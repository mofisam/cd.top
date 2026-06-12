<?php
require_once 'auth_check.php';
$adminUser = checkAdminAuth();
require_once '../config/database.php';

$conn       = getDBConnection();
$activePage = 'refunds';

// ── Auto-create refunds table if not present ────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS refunds (
        id                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        payment_id          INT UNSIGNED     NOT NULL,
        user_id             INT  NOT NULL,

        amount_kobo         INT UNSIGNED     NOT NULL,
        currency            CHAR(3)          NOT NULL DEFAULT 'NGN',
        reason              ENUM('duplicate','fraudulent','customer_request','other')
                            NOT NULL DEFAULT 'customer_request',
        notes               VARCHAR(255)     NULL,

        status              ENUM('pending','processed','failed')
                            NOT NULL DEFAULT 'pending',

        paystack_refund_id  BIGINT UNSIGNED  NULL DEFAULT NULL
            COMMENT 'Paystack numeric refund ID',
        merchant_note       VARCHAR(255)     NULL DEFAULT NULL,

        credits_reversed    SMALLINT UNSIGNED NOT NULL DEFAULT 0
            COMMENT 'Credits clawed back from users.credits',

        processed_by        INT UNSIGNED     NULL DEFAULT NULL
            COMMENT 'admin_users.id',
        created_at          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        PRIMARY KEY (id),
        KEY idx_ref_payment (payment_id),
        KEY idx_ref_user    (user_id),
        KEY idx_ref_status  (status),
        KEY idx_ref_created (created_at),
        CONSTRAINT fk_ref_payment FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE RESTRICT,
        CONSTRAINT fk_ref_user    FOREIGN KEY (user_id)    REFERENCES users    (id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Helpers ─────────────────────────────────────────────────
$kobo = fn(int $k): string => '₦' . number_format($k / 100, 0, '.', ',');

// ── POST actions ─────────────────────────────────────────────
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $refId  = (int)($_POST['refund_id'] ?? 0);
    $action = $_POST['action'];

    // ── Retry a pending/failed refund via Paystack ───────────
    if ($action === 'retry') {
        $rStmt = $conn->prepare("
            SELECT r.*, p.paystack_transaction_id, p.currency as pay_currency
            FROM refunds r
            JOIN payments p ON p.id = r.payment_id
            WHERE r.id = ? AND r.status IN ('pending','failed')
            LIMIT 1
        ");
        $rStmt->bind_param("i", $refId);
        $rStmt->execute();
        $ref = $rStmt->get_result()->fetch_assoc();
        $rStmt->close();

        if (!$ref) { $flash = ['type'=>'err','msg'=>'Refund not found or already processed.']; goto done; }

        $psKey = defined('PAYSTACK_SECRET_KEY') ? PAYSTACK_SECRET_KEY : '';
        $newStatus = 'failed';
        $psRefundId = $ref['paystack_refund_id'];

        if ($psKey && $ref['paystack_transaction_id']) {
            $payload = json_encode([
                'transaction'   => (int)$ref['paystack_transaction_id'],
                'amount'        => $ref['amount_kobo'],
                'currency'      => $ref['pay_currency'] ?? 'NGN',
                'customer_note' => $ref['notes'] ?: 'Admin refund retry',
                'merchant_note' => "Retry by: {$adminUser['username']}",
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
            $body   = curl_exec($ch);
            $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $ps = json_decode($body, true);
            if ($code === 200 && ($ps['status'] ?? false)) {
                $newStatus  = 'processed';
                $psRefundId = $ps['data']['id'] ?? $psRefundId;
                $flash      = ['type'=>'ok','msg'=>"Refund #{$refId} successfully sent to Paystack."];
            } else {
                $flash = ['type'=>'err','msg'=>"Paystack returned error for refund #{$refId}: " . ($ps['message'] ?? 'Unknown error')];
            }
        } else {
            // No Paystack key or no transaction ID — mark processed locally
            $newStatus = 'processed';
            $flash     = ['type'=>'warn','msg'=>"No Paystack key / transaction ID. Refund #{$refId} marked processed locally."];
        }

        $upd = $conn->prepare("
            UPDATE refunds
            SET status=?, paystack_refund_id=?, processed_by=?, updated_at=NOW()
            WHERE id=?
        ");
        $upd->bind_param("siii", $newStatus, $psRefundId, $adminUser['id'], $refId);
        $upd->execute();
        $upd->close();

        logAdminActivity($adminUser['id'], 'RETRY_REFUND', "Retried refund ID: $refId — result: $newStatus");
    }

    // ── Mark refund processed manually ──────────────────────
    elseif ($action === 'mark_processed') {
        $note = substr(strip_tags($_POST['note'] ?? ''), 0, 255);
        $upd = $conn->prepare("
            UPDATE refunds
            SET status='processed', merchant_note=?, processed_by=?, updated_at=NOW()
            WHERE id=? AND status != 'processed'
        ");
        $upd->bind_param("sii", $note, $adminUser['id'], $refId);
        $upd->execute();
        $upd->close();
        logAdminActivity($adminUser['id'], 'MARK_REFUND_PROCESSED', "Manually marked refund ID: $refId as processed");
        $flash = ['type'=>'ok','msg'=>"Refund #{$refId} marked as processed."];
    }

    // ── Mark refund failed ───────────────────────────────────
    elseif ($action === 'mark_failed') {
        $note = substr(strip_tags($_POST['note'] ?? ''), 0, 255);
        $upd = $conn->prepare("
            UPDATE refunds
            SET status='failed', merchant_note=?, processed_by=?, updated_at=NOW()
            WHERE id=? AND status = 'pending'
        ");
        $upd->bind_param("sii", $note, $adminUser['id'], $refId);
        $upd->execute();
        $upd->close();
        logAdminActivity($adminUser['id'], 'MARK_REFUND_FAILED', "Marked refund ID: $refId as failed");
        $flash = ['type'=>'warn','msg'=>"Refund #{$refId} marked as failed."];
    }

    // ── Create manual refund record ──────────────────────────
    elseif ($action === 'create_manual') {
        $payId  = (int)($_POST['payment_id'] ?? 0);
        $amtNgn = max(0, (float)($_POST['amount_ngn'] ?? 0));
        $amtKobo= (int)round($amtNgn * 100);
        $reason = in_array($_POST['reason'] ?? '', ['duplicate','fraudulent','customer_request','other'])
                  ? $_POST['reason'] : 'customer_request';
        $notes  = substr(strip_tags($_POST['notes'] ?? ''), 0, 255);
        $credits= max(0, (int)($_POST['credits_reversed'] ?? 0));

        if (!$payId || $amtKobo <= 0) {
            $flash = ['type'=>'err','msg'=>'Payment ID and amount are required.'];
            goto done;
        }

        // Validate payment
        $pStmt = $conn->prepare("SELECT id, user_id, amount_charged_kobo, amount_refunded_kobo, currency FROM payments WHERE id=? LIMIT 1");
        $pStmt->bind_param("i", $payId);
        $pStmt->execute();
        $pay = $pStmt->get_result()->fetch_assoc();
        $pStmt->close();

        if (!$pay) { $flash = ['type'=>'err','msg'=>"Payment #{$payId} not found."]; goto done; }

        $maxRefund = $pay['amount_charged_kobo'] - $pay['amount_refunded_kobo'];
        if ($amtKobo > $maxRefund) {
            $flash = ['type'=>'err','msg'=>"Refund amount exceeds refundable balance of {$kobo($maxRefund)}."];
            goto done;
        }

        $ins = $conn->prepare("
            INSERT INTO refunds
              (payment_id, user_id, amount_kobo, currency, reason, notes,
               status, credits_reversed, processed_by, merchant_note)
            VALUES (?,?,?,?,?,?,'pending',?,?,?)
        ");
        $currency = $pay['currency'];
        $mn = "Manual — admin: {$adminUser['username']}";
        $ins->bind_param("iiisssiis",
            $payId, $pay['user_id'], $amtKobo, $currency,
            $reason, $notes, $credits, $adminUser['id'], $mn
        );
        $ins->execute();
        $newId = $conn->insert_id;
        $ins->close();

        // Update payment refunded amount
        $newRefunded = $pay['amount_refunded_kobo'] + $amtKobo;
        $newStatus   = $newRefunded >= $pay['amount_charged_kobo'] ? 'refunded' : 'success';
        $conn->prepare("UPDATE payments SET amount_refunded_kobo=?, status=?, updated_at=NOW() WHERE id=?")
            ->bind_param("isi", $newRefunded, $newStatus, $payId);
        $upd2 = $conn->prepare("UPDATE payments SET amount_refunded_kobo=?, status=?, updated_at=NOW() WHERE id=?");
        $upd2->bind_param("isi", $newRefunded, $newStatus, $payId);
        $upd2->execute();
        $upd2->close();

        // Claw back credits
        if ($credits > 0) {
            $crUpd = $conn->prepare("UPDATE users SET credits=GREATEST(0, credits-?) WHERE id=?");
            $crUpd->bind_param("ii", $credits, $pay['user_id']); $crUpd->execute(); $crUpd->close();
            $balStmt = $conn->prepare("SELECT credits FROM users WHERE id=?");
            $balStmt->bind_param("i", $pay['user_id']); $balStmt->execute();
            $bal = (int)($balStmt->get_result()->fetch_assoc()['credits'] ?? 0); $balStmt->close();
            $neg = -$credits;
            $lStmt = $conn->prepare("INSERT INTO credit_ledger (user_id, delta, balance_after, type, payment_id, note) VALUES (?,?,?,'refund',?,?)");
            if ($lStmt) {
                $lNote = "Credit clawback — refund #{$newId}";
                $lStmt->bind_param("iiisi", $pay['user_id'], $neg, $bal, $payId, $lNote);
                $lStmt->execute(); $lStmt->close();
            }
        }

        logAdminActivity($adminUser['id'], 'CREATE_MANUAL_REFUND', "Created manual refund #{$newId} for payment #$payId — {$kobo($amtKobo)}");
        $flash = ['type'=>'ok','msg'=>"Manual refund #{$newId} created for payment #{$payId}. Status: pending."];
    }

    done:
}

// ── CSV export ───────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="refunds_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Payment ID','User ID','Email','Amount (₦)','Currency','Reason','Notes','Status','Paystack Refund ID','Credits Reversed','Processed By','Created','Updated']);
    $rs = $conn->query("
        SELECT r.id, r.payment_id, r.user_id, u.email,
               r.amount_kobo/100, r.currency, r.reason, r.notes,
               r.status, r.paystack_refund_id, r.credits_reversed,
               a.username as processed_by,
               r.created_at, r.updated_at
        FROM refunds r
        JOIN users u ON u.id = r.user_id
        LEFT JOIN admin_users a ON a.id = r.processed_by
        ORDER BY r.created_at DESC
    ");
    while ($row = $rs->fetch_assoc()) fputcsv($out, $row);
    fclose($out);
    $conn->close(); exit();
}

// ── Filters ──────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$statusFilter = in_array($_GET['status'] ?? '', ['pending','processed','failed','']) ? ($_GET['status'] ?? '') : '';
$reasonFilter = in_array($_GET['reason'] ?? '', ['duplicate','fraudulent','customer_request','other','']) ? ($_GET['reason'] ?? '') : '';
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to']   ?? '');
$sortCol      = in_array($_GET['sort'] ?? '', ['created_at','amount_kobo','status']) ? $_GET['sort'] : 'created_at';
$sortDir      = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

$where  = ['1=1'];
$binds  = []; $types = '';

if ($search) {
    $like    = "%$search%";
    $where[] = "(u.email LIKE ? OR u.full_name LIKE ? OR CAST(r.payment_id AS CHAR) LIKE ? OR CAST(r.id AS CHAR) LIKE ?)";
    $binds   = array_merge($binds, [$like,$like,$like,$like]); $types .= 'ssss';
}
if ($statusFilter) { $where[] = "r.status=?";  $binds[] = $statusFilter; $types .= 's'; }
if ($reasonFilter) { $where[] = "r.reason=?";  $binds[] = $reasonFilter; $types .= 's'; }
if ($dateFrom)     { $where[] = "DATE(r.created_at)>=?"; $binds[] = $dateFrom; $types .= 's'; }
if ($dateTo)       { $where[] = "DATE(r.created_at)<=?"; $binds[] = $dateTo;   $types .= 's'; }

$whereSQL = implode(' AND ', $where);
$sortMap  = ['created_at'=>'r.created_at','amount_kobo'=>'r.amount_kobo','status'=>'r.status'];
$orderSQL = ($sortMap[$sortCol] ?? 'r.created_at') . ' ' . $sortDir;

// Count
$cStmt = $conn->prepare("
    SELECT COUNT(*) as c
    FROM refunds r
    JOIN users u ON u.id = r.user_id
    WHERE $whereSQL
");
if ($types) $cStmt->bind_param($types, ...$binds);
$cStmt->execute();
$totalRows  = (int)$cStmt->get_result()->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$cStmt->close();

// Data
$dStmt = $conn->prepare("
    SELECT r.*,
           u.email, u.full_name, u.avatar,
           p.paystack_reference, p.paystack_transaction_id, p.type as pay_type,
           p.amount_charged_kobo, p.amount_refunded_kobo,
           a.username as processed_by_name
    FROM refunds r
    JOIN users u       ON u.id = r.user_id
    JOIN payments p    ON p.id = r.payment_id
    LEFT JOIN admin_users a ON a.id = r.processed_by
    WHERE $whereSQL
    ORDER BY $orderSQL
    LIMIT ? OFFSET ?
");
$allBinds = array_merge($binds, [$perPage, $offset]);
$allTypes = $types . 'ii';
$dStmt->bind_param($allTypes, ...$allBinds);
$dStmt->execute();
$refunds = [];
$result = $dStmt->get_result();
while ($r = $result->fetch_assoc()) $refunds[] = $r;
$dStmt->close();

// ── Summary stats ─────────────────────────────────────────────
$safe   = fn($q): int  => (int)($conn->query($q)?->fetch_assoc()['c'] ?? 0);
$safeV  = fn($q): int  => (int)($conn->query($q)?->fetch_assoc()['v'] ?? 0);

$statTotal       = $safe("SELECT COUNT(*) as c FROM refunds");
$statPending     = $safe("SELECT COUNT(*) as c FROM refunds WHERE status='pending'");
$statProcessed   = $safe("SELECT COUNT(*) as c FROM refunds WHERE status='processed'");
$statFailed      = $safe("SELECT COUNT(*) as c FROM refunds WHERE status='failed'");
$statTotalKobo   = $safeV("SELECT COALESCE(SUM(amount_kobo),0) as v FROM refunds WHERE status='processed'");
$statThisMonth   = $safeV("SELECT COALESCE(SUM(amount_kobo),0) as v FROM refunds WHERE status='processed' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())");

$conn->close();

// ── URL helpers ───────────────────────────────────────────────
function rfPageUrl(int $p, array $extra = []): string {
    $params = array_merge($_GET, $extra, ['page'=>$p]);
    unset($params['export']);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== ''));
}
function rfSortUrl(string $col): string {
    $dir = ($_GET['sort'] ?? '') === $col && ($_GET['dir'] ?? 'desc') === 'asc' ? 'desc' : 'asc';
    return rfPageUrl(1, ['sort'=>$col,'dir'=>$dir]);
}
function rfSortIcon(string $col): string {
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
<title>Refunds — CheckDomain Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0F172A;font-family:'Inter',sans-serif;overflow-x:hidden;color:#fff}

.stat-card{background:linear-gradient(135deg,rgba(30,58,138,.3),rgba(16,185,129,.1));backdrop-filter:blur(10px);border:1px solid rgba(59,130,246,.3);transition:all .3s ease}
.stat-card:hover{transform:translateY(-2px);border-color:rgba(16,185,129,.5)}
.main-content{transition:margin-left .3s ease}
.tbl-row{transition:background .15s}
.tbl-row:hover{background:rgba(59,130,246,.06)!important}

/* Modals */
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);z-index:100;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s}
.modal-backdrop.open{opacity:1;pointer-events:all}
.modal-box{background:#1E293B;border:1px solid rgba(59,130,246,.3);border-radius:1rem;padding:1.75rem;max-width:460px;width:90%;transform:scale(.96);transition:transform .2s;max-height:90vh;overflow-y:auto}
.modal-backdrop.open .modal-box{transform:scale(1)}

/* Flash */
.flash-ok  {background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#34D399}
.flash-warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:#FCD34D}
.flash-err {background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.3); color:#FCA5A5}

/* Badges */
.badge{display:inline-flex;align-items:center;gap:4px;font-size:.7rem;font-weight:600;padding:2px 8px;border-radius:9999px;white-space:nowrap}
.b-pending   {background:rgba(245,158,11,.15);color:#FCD34D}
.b-processed {background:rgba(16,185,129,.15);color:#34D399}
.b-failed    {background:rgba(239,68,68,.15); color:#FCA5A5}
.b-duplicate {background:rgba(168,85,247,.15);color:#C4B5FD}
.b-fraudulent{background:rgba(239,68,68,.15); color:#FCA5A5}
.b-customer_request{background:rgba(59,130,246,.15);color:#93C5FD}
.b-other     {background:rgba(107,114,128,.2);color:#9CA3AF}

/* Timeline dot */
.status-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.sd-pending  {background:#F59E0B;animation:pulse 2s infinite}
.sd-processed{background:#10B981}
.sd-failed   {background:#EF4444}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}

/* Ref chip */
.ref-chip{display:inline-flex;align-items:center;gap:4px;font-family:monospace;font-size:.68rem;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.18);border-radius:4px;padding:2px 6px;color:#93C5FD;cursor:pointer;transition:background .13s;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ref-chip:hover{background:rgba(59,130,246,.18)}

/* Amount */
.amt-main{font-family:monospace;font-size:.875rem;font-weight:700;color:#fff}
.amt-sub {font-family:monospace;font-size:.7rem;color:#6B7280}

/* Inputs */
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
.btn-green    {background:#059669;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-green:hover{background:#047857}
.btn-sm{padding:.3rem .75rem!important;font-size:.75rem!important}

::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:rgba(15,23,42,.5);border-radius:10px}
::-webkit-scrollbar-thumb{background:#3B82F6;border-radius:10px}
::-webkit-scrollbar-thumb:hover{background:#60A5FA}

@media(max-width:768px){.main-content{margin-left:0!important}.p-8{padding:1rem}.hide-mobile{display:none!important}}
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
      <h1 class="text-2xl md:text-3xl font-bold">Refunds</h1>
      <p class="text-gray-400 text-sm mt-1">
        <?= number_format($totalRows) ?> refund<?= $totalRows!==1?'s':'' ?>
        <?php if ($search||$statusFilter||$reasonFilter||$dateFrom||$dateTo): ?>
        <span class="text-blue-400">(filtered)</span>
        <?php endif; ?>
      </p>
    </div>
    <div class="flex flex-wrap gap-3">
      <a href="?export=1" class="btn-secondary flex items-center gap-2 text-sm">
        <i class="fas fa-download text-xs"></i> Export CSV
      </a>
      <button onclick="openModal('createModal')" class="btn-primary flex items-center gap-2 text-sm">
        <i class="fas fa-plus text-xs"></i> Manual refund
      </button>
    </div>
  </div>

  <!-- Stats -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-8">
    <?php
    $statCards = [
      ['lbl'=>'Total refunds',   'val'=>number_format($statTotal),              'icon'=>'fa-undo',            'c'=>'blue'],
      ['lbl'=>'Pending',         'val'=>number_format($statPending),            'icon'=>'fa-clock',           'c'=>'amber'],
      ['lbl'=>'Processed',       'val'=>number_format($statProcessed),          'icon'=>'fa-check-circle',    'c'=>'green'],
      ['lbl'=>'Failed',          'val'=>number_format($statFailed),             'icon'=>'fa-times-circle',    'c'=>'red'],
      ['lbl'=>'Total refunded',  'val'=>$kobo($statTotalKobo),                  'icon'=>'fa-money-bill-wave', 'c'=>'purple', 'raw'=>true],
      ['lbl'=>'This month',      'val'=>$kobo($statThisMonth),                  'icon'=>'fa-calendar-day',    'c'=>'green',  'raw'=>true],
    ];
    $cmap=['blue'=>['bg'=>'bg-blue-500/20','t'=>'text-blue-400'],'amber'=>['bg'=>'bg-amber-500/20','t'=>'text-amber-400'],'green'=>['bg'=>'bg-green-500/20','t'=>'text-green-400'],'red'=>['bg'=>'bg-red-500/20','t'=>'text-red-400'],'purple'=>['bg'=>'bg-purple-500/20','t'=>'text-purple-400']];
    foreach ($statCards as $sc):
      $cl = $cmap[$sc['c']] ?? $cmap['blue'];
    ?>
    <div class="stat-card rounded-xl p-4">
      <div class="flex justify-between items-start">
        <div>
          <p class="text-gray-400 text-xs"><?= $sc['lbl'] ?></p>
          <p class="text-xl font-bold mt-1 <?= $cl['t'] ?>"><?= $sc['val'] ?></p>
        </div>
        <div class="w-9 h-9 <?= $cl['bg'] ?> rounded-full flex items-center justify-center flex-shrink-0">
          <i class="fas <?= $sc['icon'] ?> <?= $cl['t'] ?> text-sm"></i>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Pending banner -->
  <?php if ($statPending > 0): ?>
  <div class="bg-amber-500/10 border border-amber-500/30 rounded-xl px-4 py-3 mb-5 flex items-center gap-3 text-sm">
    <span class="status-dot sd-pending flex-shrink-0"></span>
    <span class="text-amber-300">
      <strong><?= $statPending ?> pending refund<?= $statPending!==1?'s':'' ?></strong> awaiting processing.
      <?php if (!defined('PAYSTACK_SECRET_KEY') || !PAYSTACK_SECRET_KEY): ?>
      <span class="text-amber-500 ml-1">⚠ No Paystack secret key set — retries will mark records as processed locally only.</span>
      <?php endif; ?>
    </span>
    <a href="?status=pending" class="ml-auto text-amber-400 hover:text-amber-300 text-xs underline whitespace-nowrap">View pending →</a>
  </div>
  <?php endif; ?>

  <!-- Filters -->
  <div class="bg-slate-800/50 rounded-xl p-4 mb-5">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
      <div class="flex-1 min-w-48">
        <label class="text-xs text-gray-400 mb-1 block">Search</label>
        <div class="relative">
          <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
          <input class="inp pl-8" type="text" name="search"
                 value="<?= htmlspecialchars($search) ?>"
                 placeholder="Email, name or refund/payment ID…" autocomplete="off">
        </div>
      </div>
      <div class="w-32">
        <label class="text-xs text-gray-400 mb-1 block">Status</label>
        <select class="inp" name="status">
          <option value="">All</option>
          <?php foreach (['pending','processed','failed'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="w-40">
        <label class="text-xs text-gray-400 mb-1 block">Reason</label>
        <select class="inp" name="reason">
          <option value="">All reasons</option>
          <?php foreach (['customer_request','duplicate','fraudulent','other'] as $r): ?>
          <option value="<?= $r ?>" <?= $reasonFilter===$r?'selected':'' ?>><?= ucwords(str_replace('_',' ',$r)) ?></option>
          <?php endforeach; ?>
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
      <div class="w-36">
        <label class="text-xs text-gray-400 mb-1 block">Sort by</label>
        <select class="inp" name="sort" onchange="this.form.submit()">
          <option value="created_at"  <?= $sortCol==='created_at'?'selected':'' ?>>Created</option>
          <option value="amount_kobo" <?= $sortCol==='amount_kobo'?'selected':'' ?>>Amount</option>
          <option value="status"      <?= $sortCol==='status'?'selected':'' ?>>Status</option>
        </select>
      </div>
      <input type="hidden" name="dir" value="<?= $sortDir==='ASC'?'asc':'desc' ?>">
      <button type="submit" class="btn-primary btn-sm flex items-center gap-2">
        <i class="fas fa-filter text-xs"></i> Filter
      </button>
      <?php if ($search||$statusFilter||$reasonFilter||$dateFrom||$dateTo): ?>
      <a href="refunds.php" class="btn-secondary btn-sm flex items-center gap-2">
        <i class="fas fa-times text-xs"></i> Clear
      </a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Table -->
  <div class="bg-slate-800/50 rounded-xl overflow-hidden">
    <?php if (!empty($refunds)): ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-700/50 border-b border-gray-700 text-xs uppercase tracking-wide text-gray-400">
          <tr>
            <th class="p-4 text-left w-10 hide-sm">#</th>
            <th class="p-4 text-left">User / Payment</th>
            <th class="p-4 text-left">
              <a href="<?= rfSortUrl('amount_kobo') ?>" class="hover:text-white flex items-center">
                Amount <?= rfSortIcon('amount_kobo') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-mobile">Reason</th>
            <th class="p-4 text-left">
              <a href="<?= rfSortUrl('status') ?>" class="hover:text-white flex items-center">
                Status <?= rfSortIcon('status') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-mobile">Paystack ref</th>
            <th class="p-4 text-left hide-sm">
              <a href="<?= rfSortUrl('created_at') ?>" class="hover:text-white flex items-center">
                Date <?= rfSortIcon('created_at') ?>
              </a>
            </th>
            <th class="p-4 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-700/50">
          <?php foreach ($refunds as $ref):
            $initials   = strtoupper(substr($ref['full_name'] ?: $ref['email'], 0, 1));
            $isPending  = $ref['status'] === 'pending';
            $isFailed   = $ref['status'] === 'failed';
            $isDone     = $ref['status'] === 'processed';
            $remainKobo = max(0, $ref['amount_charged_kobo'] - $ref['amount_refunded_kobo']);
          ?>
          <tr class="tbl-row <?= $isPending ? 'border-l-2 border-amber-500/40' : ($isFailed ? 'border-l-2 border-red-500/30' : '') ?>">

            <!-- ID -->
            <td class="p-4 text-gray-500 font-mono text-xs hide-sm"><?= (int)$ref['id'] ?></td>

            <!-- User + Payment -->
            <td class="p-4">
              <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center font-bold text-xs"
                     style="background:linear-gradient(135deg,#2563EB,#06B6D4)">
                  <?php if ($ref['avatar']): ?>
                  <img src="<?= htmlspecialchars($ref['avatar']) ?>" class="w-8 h-8 rounded-full object-cover" alt="">
                  <?php else: ?>
                  <?= htmlspecialchars($initials) ?>
                  <?php endif; ?>
                </div>
                <div class="min-w-0">
                  <div class="text-white text-xs font-medium truncate max-w-36"><?= htmlspecialchars($ref['full_name'] ?: '—') ?></div>
                  <div class="text-gray-400 text-xs truncate max-w-36"><?= htmlspecialchars($ref['email']) ?></div>
                  <div class="flex items-center gap-2 mt-0.5">
                    <a href="payments.php?search=<?= urlencode($ref['paystack_reference'] ?? '') ?>"
                       class="text-blue-400 hover:text-blue-300 text-xs font-mono transition">
                      Pay #<?= (int)$ref['payment_id'] ?>
                    </a>
                    <?php if ($ref['pay_type']): ?>
                    <span class="text-gray-600 text-xs"><?= ucfirst(str_replace('_',' ',$ref['pay_type'])) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </td>

            <!-- Amount -->
            <td class="p-4">
              <div class="amt-main"><?= $kobo((int)$ref['amount_kobo']) ?></div>
              <?php if ($ref['credits_reversed'] > 0): ?>
              <div class="amt-sub"><?= (int)$ref['credits_reversed'] ?> credit<?= $ref['credits_reversed']!==1?'s':'' ?> revoked</div>
              <?php endif; ?>
            </td>

            <!-- Reason -->
            <td class="p-4 hide-mobile">
              <span class="badge b-<?= $ref['reason'] ?>">
                <?= ucwords(str_replace('_',' ',$ref['reason'])) ?>
              </span>
              <?php if ($ref['notes']): ?>
              <div class="text-gray-500 text-xs mt-1 max-w-32 truncate" title="<?= htmlspecialchars($ref['notes']) ?>">
                <?= htmlspecialchars(substr($ref['notes'],0,35)) ?>
              </div>
              <?php endif; ?>
            </td>

            <!-- Status -->
            <td class="p-4">
              <div class="flex items-center gap-2">
                <span class="status-dot sd-<?= $ref['status'] ?>"></span>
                <span class="badge b-<?= $ref['status'] ?>">
                  <?= ucfirst($ref['status']) ?>
                </span>
              </div>
              <?php if ($ref['processed_by_name']): ?>
              <div class="text-gray-600 text-xs mt-0.5">by <?= htmlspecialchars($ref['processed_by_name']) ?></div>
              <?php endif; ?>
            </td>

            <!-- Paystack ref -->
            <td class="p-4 hide-mobile">
              <?php if ($ref['paystack_refund_id']): ?>
              <button class="ref-chip"
                      onclick="copyText('<?= htmlspecialchars($ref['paystack_refund_id'],ENT_QUOTES) ?>')"
                      title="Paystack refund ID — click to copy">
                <i class="fas fa-copy text-xs"></i>
                <?= htmlspecialchars($ref['paystack_refund_id']) ?>
              </button>
              <?php else: ?>
              <span class="text-gray-600 text-xs">Not set</span>
              <?php endif; ?>
              <?php if ($ref['paystack_reference']): ?>
              <div class="mt-0.5">
                <button class="ref-chip"
                        onclick="copyText('<?= htmlspecialchars($ref['paystack_reference'],ENT_QUOTES) ?>')"
                        title="Payment reference — click to copy">
                  <?= htmlspecialchars(substr($ref['paystack_reference'],0,18)) ?>…
                </button>
              </div>
              <?php endif; ?>
            </td>

            <!-- Date -->
            <td class="p-4 hide-sm">
              <div class="text-xs text-white"><?= date('M j, Y', strtotime($ref['created_at'])) ?></div>
              <div class="text-xs text-gray-500"><?= date('H:i', strtotime($ref['created_at'])) ?></div>
            </td>

            <!-- Actions -->
            <td class="p-4 text-right">
              <div class="flex items-center justify-end gap-1.5 flex-wrap">

                <!-- View -->
                <button onclick="openDetailModal(<?= htmlspecialchars(json_encode($ref), ENT_QUOTES) ?>)"
                        class="w-8 h-8 bg-blue-500/20 hover:bg-blue-500/30 rounded-lg flex items-center justify-center text-blue-400 transition"
                        title="View details">
                  <i class="fas fa-eye text-xs"></i>
                </button>

                <!-- Retry (pending or failed) -->
                <?php if ($isPending || $isFailed): ?>
                <button onclick="openRetryModal(<?= (int)$ref['id'] ?>, '<?= htmlspecialchars($ref['email'],ENT_QUOTES) ?>', <?= (int)$ref['amount_kobo'] ?>)"
                        class="w-8 h-8 bg-green-500/20 hover:bg-green-500/30 rounded-lg flex items-center justify-center text-green-400 transition"
                        title="Retry via Paystack">
                  <i class="fas fa-redo text-xs"></i>
                </button>
                <?php endif; ?>

                <!-- Mark processed (pending) -->
                <?php if ($isPending): ?>
                <button onclick="openMarkModal(<?= (int)$ref['id'] ?>, 'processed')"
                        class="w-8 h-8 bg-green-500/10 hover:bg-green-500/20 rounded-lg flex items-center justify-center text-green-300 transition"
                        title="Mark as processed">
                  <i class="fas fa-check text-xs"></i>
                </button>
                <button onclick="openMarkModal(<?= (int)$ref['id'] ?>, 'failed')"
                        class="w-8 h-8 bg-red-500/10 hover:bg-red-500/20 rounded-lg flex items-center justify-center text-red-300 transition"
                        title="Mark as failed">
                  <i class="fas fa-times text-xs"></i>
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
        <a href="<?= rfPageUrl($page-1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php
        $s = max(1,$page-2); $e = min($totalPages,$page+2);
        if ($s>1) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>';
        for ($i=$s;$i<=$e;$i++):
        ?>
        <a href="<?= rfPageUrl($i) ?>" class="px-3 py-1.5 rounded text-xs transition <?= $i===$page?'bg-blue-600':'bg-slate-700 hover:bg-slate-600' ?>"><?= $i ?></a>
        <?php endfor;
        if ($e<$totalPages) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>'; ?>
        <?php if ($page < $totalPages): ?>
        <a href="<?= rfPageUrl($page+1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="text-center py-16">
      <i class="fas fa-undo text-5xl text-gray-700 mb-4 block"></i>
      <p class="text-gray-400 mb-1">No refunds found</p>
      <?php if ($search||$statusFilter||$reasonFilter||$dateFrom||$dateTo): ?>
      <a href="refunds.php" class="text-blue-400 hover:text-blue-300 text-sm">Clear filters</a>
      <?php else: ?>
      <p class="text-gray-600 text-sm">Refunds issued from the Payments page will appear here.</p>
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
  <div class="modal-box" style="max-width:520px;">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold">Refund details</h2>
      <button onclick="closeModal('detailModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <div class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm mb-4" id="detailGrid"></div>
    <div class="border-t border-gray-700 pt-4 flex gap-3 justify-end">
      <button onclick="closeModal('detailModal')" class="btn-secondary btn-sm">Close</button>
    </div>
  </div>
</div>

<!-- Retry modal -->
<div class="modal-backdrop" id="retryModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold text-green-400">
        <i class="fas fa-redo mr-2"></i>Retry refund
      </h2>
      <button onclick="closeModal('retryModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <div class="bg-slate-900 rounded-lg p-3 mb-4 text-sm space-y-1">
      <div class="text-gray-400 text-xs">Refund <span id="retry-id" class="font-mono text-white"></span></div>
      <div class="text-gray-400 text-xs">User: <span id="retry-email" class="text-white"></span></div>
      <div class="text-gray-400 text-xs">Amount: <span id="retry-amount" class="text-green-400 font-mono font-bold"></span></div>
    </div>
    <p class="text-gray-400 text-sm mb-4">
      This will call the <strong class="text-white">Paystack Refund API</strong> with the original transaction ID and amount.
      If the Paystack key is not configured, the refund will be marked processed locally.
    </p>
    <form method="POST" class="flex gap-3 justify-end">
      <input type="hidden" name="action" value="retry">
      <input type="hidden" name="refund_id" id="retry-rid">
      <button type="button" onclick="closeModal('retryModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-green flex items-center gap-2">
        <i class="fas fa-redo text-xs"></i> Retry now
      </button>
    </form>
  </div>
</div>

<!-- Mark processed/failed modal -->
<div class="modal-backdrop" id="markModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold" id="mark-title">Mark refund</h2>
      <button onclick="closeModal('markModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <p class="text-gray-400 text-sm mb-4" id="mark-desc"></p>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" id="mark-action">
      <input type="hidden" name="refund_id" id="mark-rid">
      <div>
        <label class="text-xs text-gray-400 mb-1 block">Admin note (optional)</label>
        <input class="inp" type="text" name="note" placeholder="Reason for manual override…" maxlength="255">
      </div>
      <div class="flex gap-3 justify-end pt-2">
        <button type="button" onclick="closeModal('markModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-primary" id="mark-btn">Confirm</button>
      </div>
    </form>
  </div>
</div>

<!-- Create manual refund modal -->
<div class="modal-backdrop" id="createModal">
  <div class="modal-box" style="max-width:480px;">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold">Create manual refund</h2>
      <button onclick="closeModal('createModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <p class="text-gray-400 text-sm mb-4">
      Record a refund that was issued outside CheckDomain (e.g. bank transfer), or create a refund to retry via Paystack later.
    </p>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="create_manual">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="text-xs text-gray-400 mb-1 block">Payment ID <span class="text-red-400">*</span></label>
          <input class="inp" type="number" name="payment_id" min="1" placeholder="e.g. 42" required>
        </div>
        <div>
          <label class="text-xs text-gray-400 mb-1 block">Refund amount (₦) <span class="text-red-400">*</span></label>
          <input class="inp" type="number" name="amount_ngn" min="0.01" step="0.01" placeholder="e.g. 9000" required>
          <p class="text-xs text-gray-500 mt-0.5">Enter in Naira, not kobo.</p>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="text-xs text-gray-400 mb-1 block">Reason</label>
          <select class="inp" name="reason">
            <option value="customer_request">Customer request</option>
            <option value="duplicate">Duplicate</option>
            <option value="fraudulent">Fraudulent</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div>
          <label class="text-xs text-gray-400 mb-1 block">Credits to claw back</label>
          <input class="inp" type="number" name="credits_reversed" min="0" value="0" placeholder="0">
        </div>
      </div>
      <div>
        <label class="text-xs text-gray-400 mb-1 block">Notes</label>
        <input class="inp" type="text" name="notes" placeholder="Internal note for audit trail" maxlength="255">
      </div>
      <div class="bg-blue-500/10 border border-blue-500/20 rounded-lg px-3 py-2 text-blue-300 text-xs">
        <i class="fas fa-info-circle mr-1"></i>
        The refund will be created with status <strong>pending</strong>. Use "Retry" on the record to push it to Paystack, or "Mark processed" if you handled it manually.
      </div>
      <div class="flex gap-3 justify-end pt-2">
        <button type="button" onclick="closeModal('createModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-primary flex items-center gap-2">
          <i class="fas fa-plus text-xs"></i> Create refund
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
            display:flex;align-items:center;gap:9px;max-width:360px;">
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
function openDetailModal(r) {
  const fmt  = d => d ? new Date(d).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
  const kNgn = k => '₦' + Number(k/100).toLocaleString('en-NG',{minimumFractionDigits:2,maximumFractionDigits:2});

  const fields = [
    {l:'Refund ID',          v:'#'+r.id},
    {l:'Payment ID',         v:'#'+r.payment_id},
    {l:'User',               v:'#'+r.user_id + ' · ' + esc(r.email)},
    {l:'Amount',             v:kNgn(r.amount_kobo||0)},
    {l:'Currency',           v:esc(r.currency||'NGN')},
    {l:'Reason',             v:esc((r.reason||'').replace(/_/g,' '))},
    {l:'Status',             v:esc(r.status)},
    {l:'Credits reversed',   v:r.credits_reversed||'0'},
    {l:'Paystack refund ID', v:esc(String(r.paystack_refund_id||'—'))},
    {l:'Payment ref',        v:esc(r.paystack_reference||'—')},
    {l:'Processed by',       v:esc(r.processed_by_name||'—')},
    {l:'Notes',              v:esc(r.notes||'—')},
    {l:'Merchant note',      v:esc(r.merchant_note||'—')},
    {l:'Created',            v:fmt(r.created_at)},
    {l:'Updated',            v:fmt(r.updated_at)},
  ];

  document.getElementById('detailGrid').innerHTML = fields.map(f => `
    <div>
      <div class="text-gray-500 text-xs uppercase tracking-wide mb-0.5">${f.l}</div>
      <div class="font-mono text-xs text-gray-200 break-all">${f.v}</div>
    </div>`).join('');

  openModal('detailModal');
}

// ── Retry modal ───────────────────────────────────────────
function openRetryModal(id, email, amtKobo) {
  document.getElementById('retry-rid').value       = id;
  document.getElementById('retry-id').textContent  = '#' + id;
  document.getElementById('retry-email').textContent = email;
  document.getElementById('retry-amount').textContent = '₦' + (amtKobo/100).toLocaleString('en-NG');
  openModal('retryModal');
}

// ── Mark modal ────────────────────────────────────────────
function openMarkModal(id, targetStatus) {
  document.getElementById('mark-rid').value    = id;
  document.getElementById('mark-action').value = 'mark_' + targetStatus;

  const isProcessed = targetStatus === 'processed';
  document.getElementById('mark-title').textContent = isProcessed ? 'Mark as processed' : 'Mark as failed';
  document.getElementById('mark-desc').textContent  = isProcessed
    ? `Mark refund #${id} as processed (handled outside Paystack)?`
    : `Mark refund #${id} as failed? It can be retried later.`;

  const btn = document.getElementById('mark-btn');
  btn.className = isProcessed ? 'btn-green' : 'btn-danger';
  btn.textContent = isProcessed ? 'Mark processed' : 'Mark failed';

  openModal('markModal');
}

// ── Copy ──────────────────────────────────────────────────
function copyText(text) {
  navigator.clipboard.writeText(text)
    .then(() => showToast('Copied: ' + String(text).substring(0,40)))
    .catch(() => showToast('Could not copy', 'err'));
}

// ── Toast ─────────────────────────────────────────────────
function showToast(msg, type = 'ok') {
  const t    = document.getElementById('toast');
  const icon = document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  const colors = {ok:'#10B981', warn:'#F59E0B', err:'#EF4444'};
  const icons  = {ok:'fa-check-circle', warn:'fa-exclamation-triangle', err:'fa-times-circle'};
  icon.className   = 'fas ' + (icons[type] || 'fa-info-circle');
  icon.style.color = colors[type] || '#10B981';
  t.style.transform = 'translateY(0)'; t.style.opacity = '1';
  clearTimeout(t._t);
  t._t = setTimeout(() => { t.style.transform = 'translateY(20px)'; t.style.opacity = '0'; }, 4500);
}

function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

<?php if ($flash): ?>
showToast(<?= json_encode(strip_tags($flash['msg'])) ?>, <?= json_encode($flash['type']) ?>);
<?php endif; ?>
</script>

</body>
</html>