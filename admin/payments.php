<?php
require_once 'auth_check.php';
$adminUser = checkAdminAuth();
require_once '../config/database.php';

$conn       = getDBConnection();
$activePage = 'payments';

// ── Helpers ────────────────────────────────────────────────
$kobo = fn(int $k): string => '₦' . number_format($k / 100, 0, '.', ',');

// ── Handle POST actions ─────────────────────────────────────
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $payId  = (int)($_POST['pay_id'] ?? 0);
    $action = $_POST['action'];

    switch ($action) {

        // ── Issue refund ────────────────────────────────────
        case 'refund':
            // Validate payment exists and is refundable
            $pStmt = $conn->prepare("
                SELECT p.*, u.email
                FROM payments p JOIN users u ON u.id=p.user_id
                WHERE p.id=? AND p.status IN ('success','refunded')
                LIMIT 1
            ");
            $pStmt->bind_param("i", $payId);
            $pStmt->execute();
            $pay = $pStmt->get_result()->fetch_assoc();
            $pStmt->close();

            if (!$pay) { $flash = ['type'=>'err','msg'=>'Payment not found or not refundable.']; break; }

            $refundKobo = min(
                (int)round((float)($_POST['refund_amount'] ?? 0) * 100),
                $pay['amount_charged_kobo'] - $pay['amount_refunded_kobo']
            );

            if ($refundKobo <= 0) { $flash = ['type'=>'err','msg'=>'Refund amount must be greater than 0.']; break; }

            $reason   = in_array($_POST['reason'] ?? '', ['duplicate','fraudulent','customer_request','other'])
                        ? $_POST['reason'] : 'customer_request';
            $notes    = substr(strip_tags($_POST['notes'] ?? ''), 0, 255);
            $credits  = max(0, (int)($_POST['credits_reversed'] ?? 0));

            // ── Call Paystack refund API ──────────────────────
            $psKey   = defined('PAYSTACK_SECRET_KEY') ? PAYSTACK_SECRET_KEY : '';
            $psRefId = null;
            $psStatus = 'pending';

            if ($psKey && $pay['paystack_transaction_id']) {
                $payload = json_encode([
                    'transaction'   => (int)$pay['paystack_transaction_id'],
                    'amount'        => $refundKobo,
                    'currency'      => $pay['currency'],
                    'customer_note' => $notes ?: 'Admin-issued refund',
                    'merchant_note' => "Admin: {$adminUser['username']}",
                ]);
                $ch = curl_init('https://api.paystack.co/refund');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_HTTPHEADER     => [
                        "Authorization: Bearer $psKey",
                        "Content-Type: application/json",
                        "Cache-Control: no-cache",
                    ],
                    CURLOPT_TIMEOUT => 15,
                ]);
                $psBody = curl_exec($ch);
                $psCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $psData = json_decode($psBody, true);
                if ($psCode === 200 && $psData['status']) {
                    $psRefId  = $psData['data']['id'] ?? null;
                    $psStatus = 'processed';
                }
            }

            // ── Insert refund row ───────────────────────────
            $rStmt = $conn->prepare("
                INSERT INTO refunds
                  (payment_id, user_id, amount_kobo, currency, reason, notes,
                   status, paystack_refund_id, merchant_note, credits_reversed, processed_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ");
            $currency = $pay['currency'];
            $mn       = "Admin: {$adminUser['username']}";
            $rStmt->bind_param("iiissssisis",
                $payId, $pay['user_id'], $refundKobo, $currency,
                $reason, $notes, $psStatus, $psRefId, $mn,
                $credits, $adminUser['id']
            );
            $rStmt->execute();
            $rStmt->close();

            // ── Update payment's refunded amount & status ───
            $newRefunded = $pay['amount_refunded_kobo'] + $refundKobo;
            $newStatus   = $newRefunded >= $pay['amount_charged_kobo'] ? 'refunded' : 'success';
            $upStmt = $conn->prepare("UPDATE payments SET amount_refunded_kobo=?, status=?, updated_at=NOW() WHERE id=?");
            $upStmt->bind_param("isi", $newRefunded, $newStatus, $payId);
            $upStmt->execute();
            $upStmt->close();

            // ── Claw back credits if credit_topup ──────────
            if ($credits > 0) {
                $crStmt = $conn->prepare("UPDATE users SET credits=GREATEST(0,credits-?) WHERE id=?");
                $crStmt->bind_param("ii", $credits, $pay['user_id']);
                $crStmt->execute();
                $crStmt->close();
                // Ledger
                $balStmt = $conn->prepare("SELECT credits FROM users WHERE id=?");
                $balStmt->bind_param("i", $pay['user_id']); $balStmt->execute();
                $bal = (int)($balStmt->get_result()->fetch_assoc()['credits'] ?? 0);
                $balStmt->close();
                $neg = -$credits;
                $lStmt = $conn->prepare("INSERT INTO credit_ledger (user_id,delta,balance_after,type,payment_id,note) VALUES (?,?,?,'refund',?,?)");
                if ($lStmt) {
                    $lNote = "Refund clawback — payment #$payId";
                    $lStmt->bind_param("iiisi", $pay['user_id'], $neg, $bal, $payId, $lNote);
                    $lStmt->execute(); $lStmt->close();
                }
            }

            logAdminActivity($adminUser['id'], 'ISSUE_REFUND', "Refund {$kobo($refundKobo)} for payment #$payId ({$pay['email']})");
            $flash = ['type'=>'ok','msg'=>"Refund of {$kobo($refundKobo)} processed for payment #{$payId}. Status: {$psStatus}."];
            break;

        // ── Mark payment status manually ────────────────────
        case 'mark_status':
            $newStatus = in_array($_POST['new_status'] ?? '', ['success','failed','abandoned','reversed'])
                         ? $_POST['new_status'] : null;
            if ($newStatus) {
                $mStmt = $conn->prepare("UPDATE payments SET status=?, updated_at=NOW() WHERE id=?");
                $mStmt->bind_param("si", $newStatus, $payId);
                $mStmt->execute(); $mStmt->close();
                logAdminActivity($adminUser['id'], 'MARK_PAYMENT_STATUS', "Marked payment #$payId as $newStatus");
                $flash = ['type'=>'ok','msg'=>"Payment #$payId marked as $newStatus."];
            }
            break;
    }
}

// ── CSV export ──────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="payments_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','User ID','Email','Type','Amount (₦)','Discount (₦)','Charged (₦)','Refunded (₦)','Currency','Status','Channel','Reference','Transaction ID','Gateway Response','Promo Code','Paid At','Created']);
    $rs = $conn->query("
        SELECT p.id, p.user_id, u.email, p.type,
               p.amount_kobo/100, p.discount_kobo/100, p.amount_charged_kobo/100, p.amount_refunded_kobo/100,
               p.currency, p.status, p.channel, p.paystack_reference, p.paystack_transaction_id,
               p.gateway_response, pc.code as promo_code, p.paid_at, p.created_at
        FROM payments p
        JOIN users u ON u.id=p.user_id
        LEFT JOIN promo_codes pc ON pc.id=p.promo_code_id
        ORDER BY p.created_at DESC
    ");
    while ($r = $rs->fetch_assoc()) fputcsv($out, $r);
    fclose($out);
    $conn->close(); exit();
}

// ── Filters ────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$statusFilter = in_array($_GET['status'] ?? '', ['pending','success','failed','abandoned','reversed','refunded',''])
                ? ($_GET['status'] ?? '') : '';
$typeFilter   = in_array($_GET['type'] ?? '', ['subscription','credit_topup','one_time',''])
                ? ($_GET['type'] ?? '') : '';
$chanFilter   = in_array($_GET['channel'] ?? '', ['card','bank','ussd','qr','mobile_money','bank_transfer',''])
                ? ($_GET['channel'] ?? '') : '';
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to'] ?? '');
$sortCol      = in_array($_GET['sort'] ?? '', ['created_at','amount_charged_kobo','paid_at']) ? $_GET['sort'] : 'created_at';
$sortDir      = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

$where = ['1=1'];
$binds = []; $types = '';

if ($search) {
    $like = "%$search%";
    $where[] = "(u.email LIKE ? OR u.full_name LIKE ? OR p.paystack_reference LIKE ? OR p.description LIKE ?)";
    $binds   = array_merge($binds, [$like,$like,$like,$like]);
    $types  .= 'ssss';
}
if ($statusFilter) { $where[] = "p.status=?";         $binds[] = $statusFilter; $types .= 's'; }
if ($typeFilter)   { $where[] = "p.type=?";            $binds[] = $typeFilter;   $types .= 's'; }
if ($chanFilter)   { $where[] = "p.channel=?";         $binds[] = $chanFilter;   $types .= 's'; }
if ($dateFrom)     { $where[] = "DATE(p.created_at)>=?";$binds[] = $dateFrom;    $types .= 's'; }
if ($dateTo)       { $where[] = "DATE(p.created_at)<=?";$binds[] = $dateTo;      $types .= 's'; }

$whereSQL = implode(' AND ', $where);
$sortMap  = [
    'created_at'          => 'p.created_at',
    'amount_charged_kobo' => 'p.amount_charged_kobo',
    'paid_at'             => 'p.paid_at',
];
$orderSQL = ($sortMap[$sortCol] ?? 'p.created_at') . ' ' . $sortDir;

// Count
$cStmt = $conn->prepare("
    SELECT COUNT(*) as c FROM payments p
    JOIN users u ON u.id=p.user_id
    WHERE $whereSQL
");
if ($types) $cStmt->bind_param($types, ...$binds);
$cStmt->execute();
$totalRows  = (int)$cStmt->get_result()->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$cStmt->close();

// Data
$dStmt = $conn->prepare("
    SELECT p.*,
           u.email, u.full_name, u.avatar,
           pc.code as promo_code
    FROM payments p
    JOIN users u ON u.id=p.user_id
    LEFT JOIN promo_codes pc ON pc.id=p.promo_code_id
    WHERE $whereSQL
    ORDER BY $orderSQL
    LIMIT ? OFFSET ?
");
$allBinds = array_merge($binds, [$perPage, $offset]);
$allTypes = $types . 'ii';
$dStmt->bind_param($allTypes, ...$allBinds);
$dStmt->execute();
$rows = $dStmt->get_result();
$payments = [];
while ($r = $rows->fetch_assoc()) $payments[] = $r;
$dStmt->close();

// ── Revenue stats ───────────────────────────────────────────
$safe = fn($q): int => (int)($conn->query($q)?->fetch_assoc()['v'] ?? 0);
$cnt  = fn($q): int => (int)($conn->query($q)?->fetch_assoc()['c'] ?? 0);

$statGross    = $safe("SELECT COALESCE(SUM(amount_charged_kobo),0) as v FROM payments WHERE status='success'");
$statRefunded = $safe("SELECT COALESCE(SUM(amount_refunded_kobo),0) as v FROM payments WHERE status IN ('success','refunded')");
$statNet      = $statGross - $statRefunded;
$statToday    = $safe("SELECT COALESCE(SUM(amount_charged_kobo),0) as v FROM payments WHERE status='success' AND DATE(paid_at)=CURDATE()");
$statFailed   = $cnt("SELECT COUNT(*) as c FROM payments WHERE status='failed'");
$statPending  = $cnt("SELECT COUNT(*) as c FROM payments WHERE status='pending'");
$statSuccess  = $cnt("SELECT COUNT(*) as c FROM payments WHERE status='success'");

$conn->close();

// ── URL helpers ──────────────────────────────────────────────
function pyPageUrl(int $p, array $extra = []): string {
    $params = array_merge($_GET, $extra, ['page'=>$p]);
    unset($params['export']);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== ''));
}
function pySortUrl(string $col): string {
    $dir = ($_GET['sort'] ?? '') === $col && ($_GET['dir'] ?? 'desc') === 'asc' ? 'desc' : 'asc';
    return pyPageUrl(1, ['sort'=>$col,'dir'=>$dir]);
}
function pySortIcon(string $col): string {
    if (($_GET['sort'] ?? '') !== $col) return '<i class="fas fa-sort text-gray-600 ml-1 text-xs"></i>';
    return ($_GET['dir'] ?? 'desc') === 'asc'
        ? '<i class="fas fa-sort-up text-blue-400 ml-1 text-xs"></i>'
        : '<i class="fas fa-sort-down text-blue-400 ml-1 text-xs"></i>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>Payments — CheckDomain Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0F172A;font-family:'Inter',sans-serif;overflow-x:hidden;color:#fff}

.stat-card{
  background:linear-gradient(135deg,rgba(30,58,138,.3),rgba(16,185,129,.1));
  backdrop-filter:blur(10px);
  border:1px solid rgba(59,130,246,.3);
  transition:all .3s ease;
}
.stat-card:hover{transform:translateY(-2px);border-color:rgba(16,185,129,.5)}

.main-content{transition:margin-left .3s ease}
.tbl-row{transition:background .15s}
.tbl-row:hover{background:rgba(59,130,246,.06)!important}

/* Modals */
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);z-index:100;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s}
.modal-backdrop.open{opacity:1;pointer-events:all}
.modal-box{background:#1E293B;border:1px solid rgba(59,130,246,.3);border-radius:1rem;padding:1.75rem;max-width:480px;width:90%;transform:scale(.96);transition:transform .2s;max-height:90vh;overflow-y:auto}
.modal-backdrop.open .modal-box{transform:scale(1)}

/* Flash */
.flash-ok  {background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#34D399}
.flash-warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:#FCD34D}
.flash-err {background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.3); color:#FCA5A5}

/* Badges */
.badge{display:inline-flex;align-items:center;gap:4px;font-size:.7rem;font-weight:600;padding:2px 8px;border-radius:9999px;white-space:nowrap}
.b-success   {background:rgba(16,185,129,.15);color:#34D399}
.b-pending   {background:rgba(245,158,11,.15);color:#FCD34D}
.b-failed    {background:rgba(239,68,68,.15); color:#FCA5A5}
.b-abandoned {background:rgba(107,114,128,.2);color:#9CA3AF}
.b-reversed  {background:rgba(107,114,128,.2);color:#9CA3AF}
.b-refunded  {background:rgba(168,85,247,.15);color:#C4B5FD}
.b-subscription{background:rgba(59,130,246,.15);color:#93C5FD}
.b-credit_topup{background:rgba(245,158,11,.15);color:#FCD34D}
.b-one_time  {background:rgba(16,185,129,.15);color:#34D399}
.b-card      {background:rgba(59,130,246,.1);color:#93C5FD}
.b-bank      {background:rgba(16,185,129,.1);color:#6EE7B7}
.b-ussd      {background:rgba(245,158,11,.1);color:#FCD34D}
.b-mobile_money{background:rgba(168,85,247,.1);color:#C4B5FD}
.b-bank_transfer{background:rgba(99,102,241,.1);color:#A5B4FC}

/* Ref chip */
.ref-chip{display:inline-flex;align-items:center;gap:5px;font-family:monospace;font-size:.7rem;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.18);border-radius:4px;padding:2px 7px;color:#93C5FD;cursor:pointer;transition:background .13s;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ref-chip:hover{background:rgba(59,130,246,.18)}

/* Amount display */
.amount-main{font-family:monospace;font-size:.875rem;font-weight:700;color:#fff}
.amount-sub {font-family:monospace;font-size:.7rem;color:#6B7280}
.amount-discount{font-size:.68rem;color:#F59E0B;font-family:monospace}
.amount-refunded{font-size:.68rem;color:#C084FC;font-family:monospace}

/* Input */
.inp{background:rgba(51,65,85,.8);border:1px solid rgba(71,85,105,1);border-radius:.5rem;padding:.5rem .75rem;color:#fff;width:100%;font-size:.875rem;transition:border-color .2s;outline:none}
.inp:focus{border-color:#3B82F6}
.inp::placeholder{color:#64748B}

.btn-primary  {background:#2563EB;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-primary:hover{background:#1D4ED8}
.btn-secondary{background:#334155;color:#CBD5E1;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-secondary:hover{background:#475569}
.btn-danger   {background:#DC2626;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-danger:hover{background:#B91C1C}
.btn-amber    {background:#D97706;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-amber:hover{background:#B45309}
.btn-sm{padding:.3rem .75rem!important;font-size:.75rem!important}

::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:rgba(15,23,42,.5);border-radius:10px}
::-webkit-scrollbar-thumb{background:#3B82F6;border-radius:10px}
::-webkit-scrollbar-thumb:hover{background:#60A5FA}

@media(max-width:768px){
  .main-content{margin-left:0!important}
  .p-8{padding:1rem}
  .hide-mobile{display:none!important}
}
@media(max-width:480px){.hide-sm{display:none!important}}
</style>
</head>
<body class="text-white">

<?php include_once 'includes/sidebar.php'; ?>

<div class="main-content" style="margin-left:16rem;">
<div class="p-4 md:p-8">

  <!-- Flash -->
  <?php if ($flash): ?>
  <div class="flash-<?= $flash['type'] ?> rounded-xl px-4 py-3 mb-6 flex items-start gap-3 text-sm">
    <i class="fas <?= $flash['type']==='ok'?'fa-check-circle':($flash['type']==='warn'?'fa-exclamation-triangle':'fa-times-circle') ?> mt-0.5 flex-shrink-0"></i>
    <span><?= $flash['msg'] ?></span>
  </div>
  <?php endif; ?>

  <!-- Page header -->
  <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
    <div>
      <h1 class="text-2xl md:text-3xl font-bold">Payments</h1>
      <p class="text-gray-400 text-sm mt-1">
        <?= number_format($totalRows) ?> transaction<?= $totalRows!==1?'s':'' ?>
        <?php if ($search||$statusFilter||$typeFilter||$chanFilter||$dateFrom||$dateTo): ?>
        <span class="text-blue-400">(filtered)</span>
        <?php endif; ?>
      </p>
    </div>
    <div class="flex flex-wrap gap-3">
      <a href="?export=1" class="btn-secondary flex items-center gap-2 text-sm">
        <i class="fas fa-download text-xs"></i> Export CSV
      </a>
      <a href="refunds.php" class="btn-amber flex items-center gap-2 text-sm">
        <i class="fas fa-undo text-xs"></i> Refunds log
      </a>
    </div>
  </div>

  <!-- Revenue stat cards -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-3 mb-8">
    <?php
    $cards = [
      ['lbl'=>'Gross revenue',  'val'=>$kobo($statGross),    'icon'=>'fa-money-bill-wave',   'c'=>'green', 'raw'=>true],
      ['lbl'=>'Net revenue',    'val'=>$kobo($statNet),      'icon'=>'fa-chart-line',         'c'=>'blue',  'raw'=>true],
      ['lbl'=>'Total refunded', 'val'=>$kobo($statRefunded), 'icon'=>'fa-undo',               'c'=>'purple','raw'=>true],
      ['lbl'=>'Today',          'val'=>$kobo($statToday),    'icon'=>'fa-calendar-day',       'c'=>'amber', 'raw'=>true],
      ['lbl'=>'Successful',     'val'=>number_format($statSuccess), 'icon'=>'fa-check-circle','c'=>'green'],
      ['lbl'=>'Failed',         'val'=>number_format($statFailed),  'icon'=>'fa-times-circle','c'=>'red'],
      ['lbl'=>'Pending',        'val'=>number_format($statPending), 'icon'=>'fa-clock',       'c'=>'amber'],
    ];
    $cmap=['green'=>['bg'=>'bg-green-500/20','t'=>'text-green-400'],'blue'=>['bg'=>'bg-blue-500/20','t'=>'text-blue-400'],'purple'=>['bg'=>'bg-purple-500/20','t'=>'text-purple-400'],'amber'=>['bg'=>'bg-amber-500/20','t'=>'text-amber-400'],'red'=>['bg'=>'bg-red-500/20','t'=>'text-red-400']];
    foreach ($cards as $c):
      $cl = $cmap[$c['c']] ?? $cmap['blue'];
    ?>
    <div class="stat-card rounded-xl p-3 md:p-4">
      <div class="flex justify-between items-start">
        <div>
          <p class="text-gray-400 text-xs"><?= $c['lbl'] ?></p>
          <p class="text-base md:text-lg font-bold mt-1 <?= $cl['t'] ?>"><?= $c['val'] ?></p>
        </div>
        <div class="w-8 h-8 <?= $cl['bg'] ?> rounded-full flex items-center justify-center flex-shrink-0">
          <i class="fas <?= $c['icon'] ?> <?= $cl['t'] ?> text-xs"></i>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Filters -->
  <div class="bg-slate-800/50 rounded-xl p-4 mb-5">
    <form method="GET" class="flex flex-wrap gap-3 items-end">

      <div class="flex-1 min-w-48">
        <label class="text-xs text-gray-400 mb-1 block">Search</label>
        <div class="relative">
          <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
          <input class="inp pl-8" type="text" name="search"
                 value="<?= htmlspecialchars($search) ?>"
                 placeholder="Email, reference or description…" autocomplete="off">
        </div>
      </div>

      <div class="w-32">
        <label class="text-xs text-gray-400 mb-1 block">Status</label>
        <select class="inp" name="status">
          <option value="">All</option>
          <?php foreach (['success','pending','failed','abandoned','reversed','refunded'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="w-36">
        <label class="text-xs text-gray-400 mb-1 block">Type</label>
        <select class="inp" name="type">
          <option value="">All types</option>
          <option value="subscription" <?= $typeFilter==='subscription'?'selected':'' ?>>Subscription</option>
          <option value="credit_topup" <?= $typeFilter==='credit_topup'?'selected':'' ?>>Credit top-up</option>
          <option value="one_time"     <?= $typeFilter==='one_time'?'selected':'' ?>>One-time</option>
        </select>
      </div>

      <div class="w-36">
        <label class="text-xs text-gray-400 mb-1 block">Channel</label>
        <select class="inp" name="channel">
          <option value="">All channels</option>
          <?php foreach (['card','bank','ussd','qr','mobile_money','bank_transfer'] as $ch): ?>
          <option value="<?= $ch ?>" <?= $chanFilter===$ch?'selected':'' ?>><?= ucwords(str_replace('_',' ',$ch)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="w-36">
        <label class="text-xs text-gray-400 mb-1 block">Sort</label>
        <select class="inp" name="sort" onchange="this.form.submit()">
          <option value="created_at"          <?= $sortCol==='created_at'?'selected':'' ?>>Created</option>
          <option value="amount_charged_kobo"  <?= $sortCol==='amount_charged_kobo'?'selected':'' ?>>Amount</option>
          <option value="paid_at"              <?= $sortCol==='paid_at'?'selected':'' ?>>Paid date</option>
        </select>
      </div>

      <div class="w-32">
        <label class="text-xs text-gray-400 mb-1 block">From</label>
        <input class="inp" type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
      </div>
      <div class="w-32">
        <label class="text-xs text-gray-400 mb-1 block">To</label>
        <input class="inp" type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
      </div>

      <input type="hidden" name="dir" value="<?= $sortDir==='ASC'?'asc':'desc' ?>">
      <button type="submit" class="btn-primary btn-sm flex items-center gap-2">
        <i class="fas fa-filter text-xs"></i> Filter
      </button>
      <?php if ($search||$statusFilter||$typeFilter||$chanFilter||$dateFrom||$dateTo): ?>
      <a href="payments.php" class="btn-secondary btn-sm flex items-center gap-2">
        <i class="fas fa-times text-xs"></i> Clear
      </a>
      <?php endif; ?>

    </form>
  </div>

  <!-- Payments table -->
  <div class="bg-slate-800/50 rounded-xl overflow-hidden">
    <?php if (!empty($payments)): ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-700/50 border-b border-gray-700 text-xs uppercase tracking-wide text-gray-400">
          <tr>
            <th class="p-4 text-left w-10">#</th>
            <th class="p-4 text-left">User</th>
            <th class="p-4 text-left hide-mobile">
              <a href="<?= pySortUrl('amount_charged_kobo') ?>" class="hover:text-white flex items-center">
                Amount <?= pySortIcon('amount_charged_kobo') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-mobile">Type / Channel</th>
            <th class="p-4 text-left">Status</th>
            <th class="p-4 text-left hide-sm">Reference</th>
            <th class="p-4 text-left hide-mobile">
              <a href="<?= pySortUrl('paid_at') ?>" class="hover:text-white flex items-center">
                Date <?= pySortIcon('paid_at') ?>
              </a>
            </th>
            <th class="p-4 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-700/50">
          <?php foreach ($payments as $pay):
            $initials = strtoupper(substr($pay['full_name'] ?: $pay['email'], 0, 1));
            $canRefund = $pay['status'] === 'success'
                         && ($pay['amount_charged_kobo'] - $pay['amount_refunded_kobo']) > 0;
            $refundable = $pay['amount_charged_kobo'] - $pay['amount_refunded_kobo'];
          ?>
          <tr class="tbl-row">

            <!-- ID -->
            <td class="p-4 text-gray-500 font-mono text-xs"><?= (int)$pay['id'] ?></td>

            <!-- User -->
            <td class="p-4">
              <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center font-bold text-xs"
                     style="background:linear-gradient(135deg,#2563EB,#06B6D4)">
                  <?php if ($pay['avatar']): ?>
                  <img src="<?= htmlspecialchars($pay['avatar']) ?>" class="w-8 h-8 rounded-full object-cover" alt="">
                  <?php else: ?>
                  <?= htmlspecialchars($initials) ?>
                  <?php endif; ?>
                </div>
                <div class="min-w-0">
                  <div class="text-white text-xs font-medium truncate max-w-36"><?= htmlspecialchars($pay['full_name'] ?: '—') ?></div>
                  <div class="text-gray-400 text-xs truncate max-w-36"><?= htmlspecialchars($pay['email']) ?></div>
                  <?php if ($pay['description']): ?>
                  <div class="text-gray-600 text-xs truncate max-w-36" title="<?= htmlspecialchars($pay['description']) ?>">
                    <?= htmlspecialchars(substr($pay['description'],0,32)) ?>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
            </td>

            <!-- Amount -->
            <td class="p-4 hide-mobile">
              <div class="amount-main"><?= $kobo((int)$pay['amount_charged_kobo']) ?></div>
              <?php if ($pay['discount_kobo'] > 0): ?>
              <div class="amount-discount">−<?= $kobo((int)$pay['discount_kobo']) ?> discount</div>
              <?php endif; ?>
              <?php if ($pay['amount_refunded_kobo'] > 0): ?>
              <div class="amount-refunded">−<?= $kobo((int)$pay['amount_refunded_kobo']) ?> refunded</div>
              <?php endif; ?>
              <?php if ($pay['fees_kobo']): ?>
              <div class="amount-sub"><?= $kobo((int)$pay['fees_kobo']) ?> fee</div>
              <?php endif; ?>
            </td>

            <!-- Type / Channel -->
            <td class="p-4 hide-mobile">
              <div class="flex flex-col gap-1">
                <span class="badge b-<?= $pay['type'] ?>">
                  <?= $pay['type']==='subscription'?'Sub':($pay['type']==='credit_topup'?'Credits':'One-off') ?>
                </span>
                <?php if ($pay['channel']): ?>
                <span class="badge b-<?= $pay['channel'] ?>">
                  <i class="fas <?= match($pay['channel']) {
                    'card'=>'fa-credit-card', 'bank'=>'fa-university', 'ussd'=>'fa-mobile-alt',
                    'mobile_money'=>'fa-money-bill', 'bank_transfer'=>'fa-exchange-alt', default=>'fa-circle'
                  } ?> text-xs"></i>
                  <?= ucwords(str_replace('_',' ',$pay['channel'])) ?>
                </span>
                <?php endif; ?>
                <?php if ($pay['promo_code']): ?>
                <span class="text-xs text-amber-400 font-mono">🏷 <?= htmlspecialchars($pay['promo_code']) ?></span>
                <?php endif; ?>
              </div>
            </td>

            <!-- Status -->
            <td class="p-4">
              <span class="badge b-<?= $pay['status'] ?>">
                <?= ucfirst($pay['status']) ?>
              </span>
              <?php if ($pay['gateway_response']): ?>
              <div class="text-gray-500 text-xs mt-1 max-w-28 truncate" title="<?= htmlspecialchars($pay['gateway_response']) ?>">
                <?= htmlspecialchars($pay['gateway_response']) ?>
              </div>
              <?php endif; ?>
              <?php if ($pay['failure_code']): ?>
              <div class="text-red-400 text-xs mt-1 font-mono"><?= htmlspecialchars($pay['failure_code']) ?></div>
              <?php endif; ?>
            </td>

            <!-- Reference -->
            <td class="p-4 hide-sm">
              <?php if ($pay['paystack_reference']): ?>
              <button class="ref-chip" onclick="copyText('<?= htmlspecialchars($pay['paystack_reference'], ENT_QUOTES) ?>')" title="Click to copy">
                <i class="fas fa-copy text-xs"></i>
                <?= htmlspecialchars($pay['paystack_reference']) ?>
              </button>
              <?php endif; ?>
              <?php if ($pay['paystack_transaction_id']): ?>
              <div class="text-gray-600 text-xs mt-0.5 font-mono"><?= (int)$pay['paystack_transaction_id'] ?></div>
              <?php endif; ?>
            </td>

            <!-- Date -->
            <td class="p-4 hide-mobile">
              <?php
              $ts = $pay['paid_at'] ? strtotime($pay['paid_at']) : strtotime($pay['created_at']);
              ?>
              <div class="text-xs text-white"><?= date('M j, Y', $ts) ?></div>
              <div class="text-xs text-gray-500"><?= date('H:i:s', $ts) ?></div>
            </td>

            <!-- Actions -->
            <td class="p-4 text-right">
              <div class="flex items-center justify-end gap-1.5">
                <!-- View detail -->
                <button onclick="openDetailModal(<?= htmlspecialchars(json_encode($pay), ENT_QUOTES) ?>)"
                        class="w-8 h-8 bg-blue-500/20 hover:bg-blue-500/30 rounded-lg flex items-center justify-center text-blue-400 transition"
                        title="View detail">
                  <i class="fas fa-eye text-xs"></i>
                </button>
                <!-- Refund -->
                <?php if ($canRefund): ?>
                <button onclick="openRefundModal(<?= (int)$pay['id'] ?>, '<?= htmlspecialchars($pay['email'],ENT_QUOTES) ?>', <?= (int)$refundable ?>, '<?= $pay['type'] ?>')"
                        class="w-8 h-8 bg-purple-500/20 hover:bg-purple-500/30 rounded-lg flex items-center justify-center text-purple-400 transition"
                        title="Issue refund">
                  <i class="fas fa-undo text-xs"></i>
                </button>
                <?php endif; ?>
                <!-- Mark status -->
                <?php if (in_array($pay['status'], ['pending','failed','abandoned'])): ?>
                <button onclick="openMarkModal(<?= (int)$pay['id'] ?>, '<?= $pay['status'] ?>')"
                        class="w-8 h-8 bg-amber-500/20 hover:bg-amber-500/30 rounded-lg flex items-center justify-center text-amber-400 transition"
                        title="Change status">
                  <i class="fas fa-edit text-xs"></i>
                </button>
                <?php endif; ?>
              </div>
            </td>

          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination footer -->
    <div class="flex flex-col sm:flex-row justify-between items-center gap-4 p-4 bg-slate-700/30 border-t border-gray-700">
      <div class="text-xs text-gray-400">
        Showing <?= number_format($offset+1) ?>–<?= number_format(min($offset+$perPage,$totalRows)) ?> of <?= number_format($totalRows) ?>
      </div>
      <?php if ($totalPages > 1): ?>
      <div class="flex flex-wrap justify-center gap-1.5">
        <?php if ($page > 1): ?>
        <a href="<?= pyPageUrl($page-1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php
        $s = max(1,$page-2); $e = min($totalPages,$page+2);
        if ($s>1) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>';
        for ($i=$s;$i<=$e;$i++):
        ?>
        <a href="<?= pyPageUrl($i) ?>" class="px-3 py-1.5 rounded text-xs transition <?= $i===$page?'bg-blue-600':'bg-slate-700 hover:bg-slate-600' ?>"><?= $i ?></a>
        <?php endfor;
        if ($e<$totalPages) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>'; ?>
        <?php if ($page < $totalPages): ?>
        <a href="<?= pyPageUrl($page+1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="text-center py-16">
      <i class="fas fa-money-bill-wave text-5xl text-gray-700 mb-4"></i>
      <p class="text-gray-400">No payments found</p>
      <?php if ($search||$statusFilter||$typeFilter||$chanFilter||$dateFrom||$dateTo): ?>
      <a href="payments.php" class="inline-block mt-4 text-blue-400 hover:text-blue-300 text-sm">Clear filters</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

</div>
</div>

<!-- ═══════════════════
     MODALS
═══════════════════ -->

<!-- Detail modal -->
<div class="modal-backdrop" id="detailModal">
  <div class="modal-box" style="max-width:560px;">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold">Payment details</h2>
      <button onclick="closeModal('detailModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <div class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm mb-4" id="detailGrid"></div>
    <div id="metadataSection" class="hidden">
      <div class="text-xs text-gray-500 uppercase tracking-wide mb-1 mt-3">Paystack Metadata</div>
      <pre id="metadataJson" class="bg-slate-900 rounded-lg p-3 text-xs text-green-300 overflow-x-auto max-h-48"></pre>
    </div>
    <div class="border-t border-gray-700 pt-4 mt-4 flex gap-3 justify-end">
      <button onclick="closeModal('detailModal')" class="btn-secondary btn-sm">Close</button>
    </div>
  </div>
</div>

<!-- Refund modal -->
<div class="modal-backdrop" id="refundModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold text-purple-400">
        <i class="fas fa-undo mr-2"></i>Issue refund
      </h2>
      <button onclick="closeModal('refundModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <div class="bg-slate-900 rounded-lg p-3 mb-4 text-sm">
      <div class="text-gray-400 text-xs mb-1">Payment</div>
      <div class="font-mono text-white" id="rf-payid"></div>
      <div class="text-gray-400 text-xs mt-1">User: <span id="rf-email" class="text-white"></span></div>
      <div class="text-gray-400 text-xs mt-0.5">Refundable: <span id="rf-max" class="text-green-400 font-mono font-bold"></span></div>
    </div>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="refund">
      <input type="hidden" name="pay_id" id="rf-id">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="text-xs text-gray-400 mb-1 block">Refund amount (₦) <span class="text-red-400">*</span></label>
          <input class="inp" type="number" name="refund_amount" id="rf-amount"
                 min="0.01" step="0.01" placeholder="e.g. 9000" required>
          <p class="text-xs text-gray-500 mt-1">Enter in Naira (₦), not kobo.</p>
        </div>
        <div>
          <label class="text-xs text-gray-400 mb-1 block">Reason</label>
          <select class="inp" name="reason">
            <option value="customer_request">Customer request</option>
            <option value="duplicate">Duplicate charge</option>
            <option value="fraudulent">Fraudulent</option>
            <option value="other">Other</option>
          </select>
        </div>
      </div>
      <div>
        <label class="text-xs text-gray-400 mb-1 block">Admin notes</label>
        <input class="inp" type="text" name="notes" placeholder="Internal note for audit log" maxlength="255">
      </div>
      <div id="creditRevRow">
        <label class="text-xs text-gray-400 mb-1 block">Credits to claw back (credit top-ups only)</label>
        <input class="inp" type="number" name="credits_reversed" min="0" max="9999" value="0" placeholder="0">
        <p class="text-xs text-gray-500 mt-1">Enter the number of credits to deduct from the user's balance.</p>
      </div>
      <div class="bg-yellow-500/10 border border-yellow-500/30 rounded-lg px-3 py-2 text-yellow-300 text-xs">
        <i class="fas fa-exclamation-triangle mr-1"></i>
        This will call the <strong>Paystack Refund API</strong> if a transaction ID exists. Otherwise the refund is recorded locally only.
        Refunds cannot be undone.
      </div>
      <div class="flex gap-3 justify-end pt-2">
        <button type="button" onclick="closeModal('refundModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-danger flex items-center gap-2"
                onclick="return confirm('Issue this refund? This cannot be undone.')">
          <i class="fas fa-undo text-xs"></i> Issue refund
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Mark status modal -->
<div class="modal-backdrop" id="markModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold text-amber-400">
        <i class="fas fa-edit mr-2"></i>Change payment status
      </h2>
      <button onclick="closeModal('markModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <p class="text-gray-400 text-sm mb-4">
      Manually override the status for payment <strong id="mk-payid" class="font-mono text-white"></strong>.
      Use with caution — this does not trigger any refund or credit actions.
    </p>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="mark_status">
      <input type="hidden" name="pay_id" id="mk-id">
      <div>
        <label class="text-xs text-gray-400 mb-1 block">New status</label>
        <select class="inp" name="new_status" id="mk-status">
          <option value="success">Success</option>
          <option value="failed">Failed</option>
          <option value="abandoned">Abandoned</option>
          <option value="reversed">Reversed</option>
        </select>
      </div>
      <div class="flex gap-3 justify-end pt-2">
        <button type="button" onclick="closeModal('markModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-amber flex items-center gap-2">
          <i class="fas fa-check text-xs"></i> Update status
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Toast -->
<div id="toast"
     style="position:fixed;bottom:24px;right:24px;z-index:999;
            background:#1E293B;border:1px solid rgba(59,130,246,.3);
            border-radius:10px;padding:12px 18px;font-size:13px;color:#E2E8F0;
            box-shadow:0 8px 32px rgba(0,0,0,.5);
            transform:translateY(20px);opacity:0;transition:all .3s ease;
            display:flex;align-items:center;gap:9px;max-width:340px;">
  <i class="fas fa-check-circle" id="toastIcon" style="color:#10B981;flex-shrink:0;font-size:14px;"></i>
  <span id="toastText"></span>
</div>

<script>
// ── Modal helpers ─────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-backdrop').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// ── Detail modal ──────────────────────────────────────────
function openDetailModal(p) {
  const fmt = d => d ? new Date(d).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
  const koboToNgn = k => '₦' + Number(k/100).toLocaleString('en-NG',{minimumFractionDigits:2,maximumFractionDigits:2});

  const fields = [
    {l:'Payment ID',       v:'#'+p.id},
    {l:'User',             v:'#'+p.user_id+' · '+esc(p.email)},
    {l:'Type',             v:esc(p.type)},
    {l:'Status',           v:esc(p.status)},
    {l:'Amount',           v:koboToNgn(p.amount_kobo||0)},
    {l:'Discount',         v:p.discount_kobo>0?koboToNgn(p.discount_kobo):'—'},
    {l:'Charged',          v:koboToNgn(p.amount_charged_kobo||0)},
    {l:'Refunded',         v:p.amount_refunded_kobo>0?koboToNgn(p.amount_refunded_kobo):'—'},
    {l:'Paystack fees',    v:p.fees_kobo>0?koboToNgn(p.fees_kobo):'—'},
    {l:'Channel',          v:esc(p.channel||'—')},
    {l:'Gateway resp',     v:esc(p.gateway_response||'—')},
    {l:'Failure code',     v:esc(p.failure_code||'—')},
    {l:'Reference',        v:esc(p.paystack_reference||'—')},
    {l:'Transaction ID',   v:esc(String(p.paystack_transaction_id||'—'))},
    {l:'IP address',       v:esc(p.ip_address||'—')},
    {l:'Paid at',          v:fmt(p.paid_at)},
    {l:'Created',          v:fmt(p.created_at)},
    {l:'Promo code',       v:esc(p.promo_code||'—')},
    {l:'Description',      v:esc(p.description||'—')},
  ];

  document.getElementById('detailGrid').innerHTML = fields.map(f => `
    <div>
      <div class="text-gray-500 text-xs uppercase tracking-wide mb-0.5">${f.l}</div>
      <div class="font-mono text-xs text-gray-200 break-all">${f.v}</div>
    </div>`).join('');

  const metaSec = document.getElementById('metadataSection');
  const metaEl  = document.getElementById('metadataJson');
  if (p.metadata) {
    try {
      metaEl.textContent = JSON.stringify(typeof p.metadata==='string' ? JSON.parse(p.metadata) : p.metadata, null, 2);
    } catch {
      metaEl.textContent = String(p.metadata);
    }
    metaSec.classList.remove('hidden');
  } else {
    metaSec.classList.add('hidden');
  }

  openModal('detailModal');
}

// ── Refund modal ──────────────────────────────────────────
function openRefundModal(id, email, refundableKobo, type) {
  document.getElementById('rf-id').value         = id;
  document.getElementById('rf-payid').textContent = '#' + id;
  document.getElementById('rf-email').textContent = email;
  document.getElementById('rf-max').textContent   = '₦' + (refundableKobo/100).toLocaleString('en-NG');
  document.getElementById('rf-amount').max        = (refundableKobo/100).toFixed(2);
  document.getElementById('rf-amount').value      = (refundableKobo/100).toFixed(2);
  // Only show credits row for credit top-ups
  document.getElementById('creditRevRow').style.display = type === 'credit_topup' ? '' : 'none';
  openModal('refundModal');
}

// ── Mark status modal ─────────────────────────────────────
function openMarkModal(id, currentStatus) {
  document.getElementById('mk-id').value          = id;
  document.getElementById('mk-payid').textContent = '#' + id;
  document.getElementById('mk-status').value      = currentStatus === 'pending' ? 'success' : 'failed';
  openModal('markModal');
}

// ── Copy ──────────────────────────────────────────────────
function copyText(text) {
  navigator.clipboard.writeText(text)
    .then(() => showToast('Copied: ' + text.substring(0,40)))
    .catch(() => showToast('Could not copy', 'err'));
}

// ── Toast ─────────────────────────────────────────────────
function showToast(msg, type = 'ok') {
  const t    = document.getElementById('toast');
  const icon = document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  const colors = {ok:'#10B981',warn:'#F59E0B',err:'#EF4444'};
  const icons  = {ok:'fa-check-circle',warn:'fa-exclamation-triangle',err:'fa-times-circle'};
  icon.className   = 'fas ' + (icons[type]||'fa-info-circle');
  icon.style.color = colors[type]||'#10B981';
  t.style.transform = 'translateY(0)'; t.style.opacity = '1';
  clearTimeout(t._t);
  t._t = setTimeout(() => { t.style.transform = 'translateY(20px)'; t.style.opacity = '0'; }, 4500);
}

function esc(s) {
  return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

<?php if ($flash): ?>
showToast(<?= json_encode(strip_tags($flash['msg'])) ?>, <?= json_encode($flash['type']) ?>);
<?php endif; ?>
</script>

</body>
</html>