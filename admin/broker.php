<?php
require_once 'auth_check.php';
$adminUser = checkAdminAuth();
require_once '../config/database.php';

$conn       = getDBConnection();
$activePage = 'broker';

// ── Auto-create table if missing ─────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS broker_requests (
        id                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        user_id             INT UNSIGNED     NOT NULL,
        domain_name         VARCHAR(253)     NOT NULL,
        tld                 VARCHAR(63)      NOT NULL,
        budget_kobo         INT UNSIGNED     NOT NULL DEFAULT 0,
        budget_flexible     TINYINT(1)       NOT NULL DEFAULT 0,
        purpose             VARCHAR(64)      NULL,
        urgency             ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
        notes               TEXT             NULL,
        status              ENUM(
                                'submitted','researching','outreach','negotiating',
                                'offer_made','accepted','transfer','completed',
                                'declined','canceled','on_hold'
                            ) NOT NULL DEFAULT 'submitted',
        broker_fee_kobo     INT UNSIGNED     NULL,
        agreed_price_kobo   INT UNSIGNED     NULL,
        final_price_kobo    INT UNSIGNED     NULL,
        commission_pct      DECIMAL(5,2)     NULL,
        payment_id          INT UNSIGNED     NULL,
        contacted_owner_at  TIMESTAMP        NULL,
        offer_made_at       TIMESTAMP        NULL,
        accepted_at         TIMESTAMP        NULL,
        completed_at        TIMESTAMP        NULL,
        admin_notes         TEXT             NULL,
        assigned_to         VARCHAR(100)     NULL,
        latest_update       TEXT             NULL,
        latest_update_at    TIMESTAMP        NULL,
        created_at          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_br_user   (user_id),
        INDEX idx_br_domain (domain_name),
        INDEX idx_br_status (status),
        CONSTRAINT fk_br_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Helpers ──────────────────────────────────────────────────
$usdMinorAmount = fn(int $amount): int => $amount >= 100000 ? (int)round($amount / 1000) : $amount;
$kobo  = fn(int $k): string  => '$' . number_format($usdMinorAmount($k) / 100, 0, '.', ',');
$flash = null;

$allStatuses = ['submitted','researching','outreach','negotiating','offer_made','accepted','transfer','completed','declined','canceled','on_hold'];

$statusMeta = [
    'submitted'   => ['color'=>'blue',   'label'=>'Submitted',    'icon'=>'fa-paper-plane'],
    'researching' => ['color'=>'purple', 'label'=>'Researching',  'icon'=>'fa-search'],
    'outreach'    => ['color'=>'amber',  'label'=>'Outreach',     'icon'=>'fa-envelope'],
    'negotiating' => ['color'=>'amber',  'label'=>'Negotiating',  'icon'=>'fa-comments-dollar'],
    'offer_made'  => ['color'=>'green',  'label'=>'Offer Made',   'icon'=>'fa-handshake'],
    'accepted'    => ['color'=>'green',  'label'=>'Accepted',     'icon'=>'fa-check-circle'],
    'transfer'    => ['color'=>'green',  'label'=>'Transferring', 'icon'=>'fa-exchange-alt'],
    'completed'   => ['color'=>'green',  'label'=>'Completed',    'icon'=>'fa-trophy'],
    'declined'    => ['color'=>'red',    'label'=>'Declined',     'icon'=>'fa-times-circle'],
    'canceled'    => ['color'=>'gray',   'label'=>'Canceled',     'icon'=>'fa-ban'],
    'on_hold'     => ['color'=>'amber',  'label'=>'On Hold',      'icon'=>'fa-pause-circle'],
];

// ── POST actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $reqId  = (int)($_POST['req_id'] ?? 0);
    $action = $_POST['action'];

    // ── Update status + broker workflow fields ────────────────
    if ($action === 'update') {
        $newStatus    = in_array($_POST['status']??'', $allStatuses) ? $_POST['status'] : null;
        $assignedTo   = substr(strip_tags(trim($_POST['assigned_to'] ?? '')), 0, 100);
        $adminNotes   = substr(strip_tags(trim($_POST['admin_notes'] ?? '')), 0, 5000);
        $latestUpdate = substr(strip_tags(trim($_POST['latest_update'] ?? '')), 0, 2000);
        $agreedKobo   = trim($_POST['agreed_price_ngn']??'') !== '' ? (int)round((float)$_POST['agreed_price_ngn']*100) : null;
        $feeKobo      = trim($_POST['broker_fee_ngn']??'')   !== '' ? (int)round((float)$_POST['broker_fee_ngn']*100)   : null;
        $finalKobo    = trim($_POST['final_price_ngn']??'')  !== '' ? (int)round((float)$_POST['final_price_ngn']*100)  : null;
        $commPct      = trim($_POST['commission_pct']??'')   !== '' ? (float)$_POST['commission_pct'] : null;

        if (!$reqId) { $flash = ['type'=>'err','msg'=>'Request ID missing.']; goto done; }

        // Fetch current for timeline tracking
        $cur = $conn->query("SELECT status, contacted_owner_at, offer_made_at, accepted_at, completed_at FROM broker_requests WHERE id=$reqId LIMIT 1")->fetch_assoc();

        // Timeline timestamps
        $contactedAt = $cur['contacted_owner_at'];
        $offerAt     = $cur['offer_made_at'];
        $acceptedAt  = $cur['accepted_at'];
        $completedAt = $cur['completed_at'];

        if ($newStatus === 'outreach'    && !$contactedAt) $contactedAt = date('Y-m-d H:i:s');
        if ($newStatus === 'offer_made'  && !$offerAt)     $offerAt     = date('Y-m-d H:i:s');
        if ($newStatus === 'accepted'    && !$acceptedAt)  $acceptedAt  = date('Y-m-d H:i:s');
        if ($newStatus === 'completed'   && !$completedAt) $completedAt = date('Y-m-d H:i:s');

        $updateNow = ($latestUpdate && $latestUpdate !== ($conn->query("SELECT latest_update FROM broker_requests WHERE id=$reqId LIMIT 1")->fetch_assoc()['latest_update'] ?? ''));

        $stmt = $conn->prepare("
            UPDATE broker_requests SET
                status              = ?,
                assigned_to         = ?,
                admin_notes         = ?,
                latest_update       = ?,
                latest_update_at    = " . ($updateNow ? 'NOW()' : 'latest_update_at') . ",
                agreed_price_kobo   = ?,
                broker_fee_kobo     = ?,
                final_price_kobo    = ?,
                commission_pct      = ?,
                contacted_owner_at  = ?,
                offer_made_at       = ?,
                accepted_at         = ?,
                completed_at        = ?,
                updated_at          = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("ssssiiiidsssi",
            $newStatus, $assignedTo, $adminNotes, $latestUpdate,
            $agreedKobo, $feeKobo, $finalKobo, $commPct,
            $contactedAt, $offerAt, $acceptedAt, $completedAt,
            $reqId
        );
        $stmt->execute();
        $stmt->close();

        // Notify user if status changed
        if ($newStatus !== $cur['status']) {
            // Insert alert to user
            $domainR = $conn->query("SELECT domain_name, user_id FROM broker_requests WHERE id=$reqId LIMIT 1")->fetch_assoc();
            if ($domainR) {
                $alertTitle = match($newStatus) {
                    'outreach'   => "We've contacted the owner of {$domainR['domain_name']}",
                    'negotiating'=> "Negotiating for {$domainR['domain_name']}",
                    'offer_made' => "Offer made for {$domainR['domain_name']}",
                    'accepted'   => "Offer accepted! Transferring {$domainR['domain_name']}",
                    'completed'  => "{$domainR['domain_name']} transfer complete 🎉",
                    'declined'   => "Owner declined — {$domainR['domain_name']}",
                    default      => "Update on your broker request for {$domainR['domain_name']}",
                };
                $alertMsg = $latestUpdate ?: $alertTitle;
                $alertType = in_array($newStatus, ['completed','accepted']) ? 'available' : 'whois_updated';
                $alertPrio = in_array($newStatus, ['completed','accepted','declined']) ? 'high' : 'medium';
                $insAlert = $conn->prepare("
                    INSERT INTO domain_alerts (user_id, domain_name, alert_type, status, priority, title, message)
                    VALUES (?,?,?,'unread',?,?,?)
                ");
                if ($insAlert) {
                    $insAlert->bind_param("isssss", $domainR['user_id'], $domainR['domain_name'], $alertType, $alertPrio, $alertTitle, $alertMsg);
                    $insAlert->execute(); $insAlert->close();
                }
            }
        }

        $newStatusLabel = $statusMeta[$newStatus]['label'] ?? $newStatus;
        logAdminActivity($adminUser['id'], 'UPDATE_BROKER_REQUEST', "Updated broker request #$reqId — status: $newStatusLabel");
        $flash = ['type'=>'ok','msg'=>"Broker request #$reqId updated."];
    }

    // ── Delete request ────────────────────────────────────────
    elseif ($action === 'delete') {
        $conn->query("DELETE FROM broker_requests WHERE id=$reqId");
        logAdminActivity($adminUser['id'], 'DELETE_BROKER_REQUEST', "Deleted broker request #$reqId");
        $flash = ['type'=>'ok','msg'=>"Broker request #$reqId deleted."];
    }

    // ── Bulk status update ────────────────────────────────────
    elseif ($action === 'bulk_status') {
        $ids       = array_map('intval', (array)($_POST['selected_ids']??[]));
        $newStatus = in_array($_POST['bulk_status']??'', $allStatuses) ? $_POST['bulk_status'] : null;
        if ($ids && $newStatus) {
            $ph = implode(',', $ids);
            $conn->query("UPDATE broker_requests SET status='$newStatus', updated_at=NOW() WHERE id IN ($ph)");
            logAdminActivity($adminUser['id'], 'BULK_UPDATE_BROKER', "Bulk status → $newStatus for ".count($ids)." requests");
            $flash = ['type'=>'ok','msg'=>count($ids)." request(s) updated to ".ucfirst(str_replace('_',' ',$newStatus))."."];
        }
    }

    done:
}

// ── CSV export ────────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="broker_requests_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','User ID','Email','Domain','TLD','Budget ($)','Flexible','Purpose','Urgency','Status','Agreed Price ($)','Broker Fee ($)','Commission %','Assigned To','Created','Updated']);
    $rs = $conn->query("
        SELECT r.id, r.user_id, u.email, r.domain_name, r.tld,
               r.budget_kobo/100, r.budget_flexible, r.purpose, r.urgency, r.status,
               IF(r.agreed_price_kobo IS NULL, '', r.agreed_price_kobo/100),
               IF(r.broker_fee_kobo   IS NULL, '', r.broker_fee_kobo/100),
               r.commission_pct, r.assigned_to, r.created_at, r.updated_at
        FROM broker_requests r
        JOIN users u ON u.id=r.user_id
        ORDER BY r.created_at DESC
    ");
    while ($row = $rs->fetch_assoc()) fputcsv($out, $row);
    fclose($out);
    $conn->close(); exit();
}

// ── Filters ──────────────────────────────────────────────────
$search         = trim($_GET['search'] ?? '');
$statusFilter   = in_array($_GET['status']??'', array_merge($allStatuses,[''])) ? ($_GET['status']??'') : '';
$urgencyFilter  = in_array($_GET['urgency']??'', ['low','medium','high','urgent','']) ? ($_GET['urgency']??'') : '';
$assignedFilter = trim($_GET['assigned'] ?? '');
$sortCol        = in_array($_GET['sort']??'', ['created_at','updated_at','urgency','budget_kobo']) ? $_GET['sort'] : 'created_at';
$sortDir        = ($_GET['dir']??'desc') === 'asc' ? 'ASC' : 'DESC';
$page           = max(1, (int)($_GET['page']??1));
$perPage        = 20;
$offset         = ($page-1)*$perPage;

$where = ['1=1']; $binds = []; $types = '';
if ($search) {
    $like    = "%$search%";
    $where[] = "(u.email LIKE ? OR u.full_name LIKE ? OR r.domain_name LIKE ? OR r.notes LIKE ?)";
    $binds   = array_merge($binds, [$like,$like,$like,$like]); $types .= 'ssss';
}
if ($statusFilter)   { $where[] = "r.status=?";        $binds[] = $statusFilter;   $types .= 's'; }
if ($urgencyFilter)  { $where[] = "r.urgency=?";       $binds[] = $urgencyFilter;  $types .= 's'; }
if ($assignedFilter) { $where[] = "r.assigned_to LIKE ?"; $binds[] = "%$assignedFilter%"; $types .= 's'; }

$whereSQL = implode(' AND ', $where);
$sortMap  = ['created_at'=>'r.created_at','updated_at'=>'r.updated_at','urgency'=>'FIELD(r.urgency,"urgent","high","medium","low")','budget_kobo'=>'r.budget_kobo'];
$orderSQL = ($sortMap[$sortCol] ?? 'r.created_at') . ' ' . $sortDir;

// Count
$cStmt = $conn->prepare("SELECT COUNT(*) as c FROM broker_requests r JOIN users u ON u.id=r.user_id WHERE $whereSQL");
if ($types) $cStmt->bind_param($types, ...$binds);
$cStmt->execute();
$totalRows  = (int)$cStmt->get_result()->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($totalRows/$perPage));
$cStmt->close();

// Data
$dStmt = $conn->prepare("
    SELECT r.*, u.email, u.full_name, u.avatar, u.plan
    FROM broker_requests r
    JOIN users u ON u.id=r.user_id
    WHERE $whereSQL
    ORDER BY $orderSQL
    LIMIT ? OFFSET ?
");
$allBinds = array_merge($binds, [$perPage, $offset]);
$allTypes = $types.'ii';
$dStmt->bind_param($allTypes, ...$allBinds);
$dStmt->execute();
$requests = [];
$results = $dStmt->get_result();
while ($r = $results->fetch_assoc()) $requests[] = $r;
$dStmt->close();

// ── Summary stats ─────────────────────────────────────────────
$safe  = fn($q): int => (int)($conn->query($q)?->fetch_assoc()['c'] ?? 0);
$safeV = fn($q): int => (int)($conn->query($q)?->fetch_assoc()['v'] ?? 0);

$statTotal      = $safe("SELECT COUNT(*) as c FROM broker_requests");
$statActive     = $safe("SELECT COUNT(*) as c FROM broker_requests WHERE status IN ('submitted','researching','outreach','negotiating','offer_made','accepted','transfer')");
$statCompleted  = $safe("SELECT COUNT(*) as c FROM broker_requests WHERE status='completed'");
$statNegotiating= $safe("SELECT COUNT(*) as c FROM broker_requests WHERE status IN ('negotiating','offer_made')");
$statUrgent     = $safe("SELECT COUNT(*) as c FROM broker_requests WHERE urgency IN ('high','urgent') AND status NOT IN ('completed','declined','canceled')");
$statRevenue    = $safeV("SELECT COALESCE(SUM(broker_fee_kobo),0) as v FROM broker_requests WHERE status='completed'");

$conn->close();

// ── URL helpers ────────────────────────────────────────────────
function brPageUrl(int $p, array $extra = []): string {
    $params = array_merge($_GET, $extra, ['page'=>$p]);
    unset($params['export']);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== 0));
}
function brSortUrl(string $col): string {
    $dir = ($_GET['sort']??'') === $col && ($_GET['dir']??'desc') === 'asc' ? 'desc' : 'asc';
    return brPageUrl(1, ['sort'=>$col,'dir'=>$dir]);
}
function brSortIcon(string $col): string {
    if (($_GET['sort']??'') !== $col) return '<i class="fas fa-sort text-gray-600 ml-1 text-xs"></i>';
    return ($_GET['dir']??'desc') === 'asc'
        ? '<i class="fas fa-sort-up text-blue-400 ml-1 text-xs"></i>'
        : '<i class="fas fa-sort-down text-blue-400 ml-1 text-xs"></i>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>Broker Requests — CheckDomain Admin</title>
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
.modal-box{background:#1E293B;border:1px solid rgba(59,130,246,.3);border-radius:1rem;padding:1.75rem;max-width:620px;width:90%;transform:scale(.96);transition:transform .2s;max-height:92vh;overflow-y:auto}
.modal-backdrop.open .modal-box{transform:scale(1)}
/* Flash */
.flash-ok  {background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#34D399}
.flash-warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:#FCD34D}
.flash-err {background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.3); color:#FCA5A5}
/* Status badges */
.badge{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:600;padding:2px 8px;border-radius:9999px;white-space:nowrap}
.b-blue  {background:rgba(59,130,246,.15);color:#93C5FD}
.b-purple{background:rgba(168,85,247,.15);color:#C4B5FD}
.b-amber {background:rgba(245,158,11,.15);color:#FCD34D}
.b-green {background:rgba(16,185,129,.15);color:#34D399}
.b-red   {background:rgba(239,68,68,.15); color:#FCA5A5}
.b-gray  {background:rgba(107,114,128,.2);color:#9CA3AF}
/* Urgency dot */
.u-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.ud-urgent{background:#EF4444;animation:pulse 1.3s infinite;box-shadow:0 0 6px rgba(239,68,68,.5)}
.ud-high  {background:#F59E0B}
.ud-medium{background:#3B82F6}
.ud-low   {background:#6B7280}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
/* Pipeline steps */
.pip-step{display:flex;flex-direction:column;align-items:center;flex:1;position:relative}
.pip-step::after{content:'';position:absolute;top:9px;left:50%;right:-50%;height:2px;background:rgba(71,85,105,.5)}
.pip-step:last-child::after{display:none}
.pip-step.done::after{background:#10B981}
.pip-step.active::after{background:linear-gradient(90deg,#10B981,rgba(71,85,105,.5))}
.pip-dot{width:18px;height:18px;border-radius:50%;border:2px solid rgba(71,85,105,.7);background:#0F172A;display:flex;align-items:center;justify-content:center;font-size:7px;z-index:1;margin-bottom:3px;transition:all .3s}
.pip-step.done   .pip-dot{border-color:#10B981;background:rgba(17, 17, 17, 10);color:#10B981}
.pip-step.active .pip-dot{border-color:#F59E0B;background:rgba(25,25,25,10);color:#F59E0B;animation:pulse 1.5s infinite}
.pip-step.failed .pip-dot{border-color:#EF4444;background:rgba(239,68,68,.2);color:#EF4444}
.pip-lbl{font-size:.55rem;color:#475569;text-transform:uppercase;letter-spacing:.06em;text-align:center}
.pip-step.done   .pip-lbl{color:#10B981}
.pip-step.active .pip-lbl{color:#F59E0B}
/* Inputs */
.inp{background:rgba(51,65,85,.8);border:1px solid rgba(71,85,105,1);border-radius:.5rem;padding:.5rem .75rem;color:#fff;width:100%;font-size:.875rem;transition:border-color .2s;outline:none}
.inp:focus{border-color:#3B82F6}
.inp::placeholder{color:#64748B}
.inp-sm{padding:.4rem .7rem!important;font-size:.8rem!important}
.form-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#64748B;margin-bottom:.25rem;display:block}
.form-hint{font-size:.68rem;color:#475569;margin-top:.2rem}
.btn-primary  {background:#2563EB;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-primary:hover{background:#1D4ED8}
.btn-secondary{background:#334155;color:#CBD5E1;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-secondary:hover{background:#475569}
.btn-danger   {background:#DC2626;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-danger:hover{background:#B91C1C}
.btn-green    {background:#059669;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-green:hover{background:#047857}
.btn-sm{padding:.3rem .75rem!important;font-size:.75rem!important}
.chk{width:15px;height:15px;cursor:pointer;accent-color:#3B82F6}
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

  <!-- Header -->
  <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
    <div>
      <h1 class="text-2xl md:text-3xl font-bold">Broker Requests</h1>
      <p class="text-gray-400 text-sm mt-1">
        <?= number_format($totalRows) ?> request<?= $totalRows!==1?'s':'' ?>
        <?php if ($search||$statusFilter||$urgencyFilter||$assignedFilter): ?>
        <span class="text-blue-400">(filtered)</span>
        <?php endif; ?>
      </p>
    </div>
    <div class="flex flex-wrap gap-3">
      <a href="?export=1" class="btn-secondary flex items-center gap-2 text-sm">
        <i class="fas fa-download text-xs"></i> Export CSV
      </a>
    </div>
  </div>

  <!-- Stats -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-8">
    <?php
    $cards = [
      ['lbl'=>'Total requests', 'val'=>number_format($statTotal),      'icon'=>'fa-handshake',      'c'=>'blue'],
      ['lbl'=>'Active',         'val'=>number_format($statActive),     'icon'=>'fa-spinner',        'c'=>'amber'],
      ['lbl'=>'Negotiating',    'val'=>number_format($statNegotiating),'icon'=>'fa-comments-dollar','c'=>'purple'],
      ['lbl'=>'Completed',      'val'=>number_format($statCompleted),  'icon'=>'fa-trophy',         'c'=>'green'],
      ['lbl'=>'Urgent',         'val'=>number_format($statUrgent),     'icon'=>'fa-exclamation',    'c'=>'red'],
      ['lbl'=>'Revenue (fees)', 'val'=>$kobo($statRevenue),            'icon'=>'fa-piggy-bank',     'c'=>'green','raw'=>true],
    ];
    $cmap=['blue'=>['bg'=>'bg-blue-500/20','t'=>'text-blue-400'],'amber'=>['bg'=>'bg-amber-500/20','t'=>'text-amber-400'],'purple'=>['bg'=>'bg-purple-500/20','t'=>'text-purple-400'],'green'=>['bg'=>'bg-green-500/20','t'=>'text-green-400'],'red'=>['bg'=>'bg-red-500/20','t'=>'text-red-400']];
    foreach ($cards as $c):
      $cl = $cmap[$c['c']]??$cmap['blue'];
    ?>
    <div class="stat-card rounded-xl p-4">
      <div class="flex justify-between items-start">
        <div>
          <p class="text-gray-400 text-xs"><?= $c['lbl'] ?></p>
          <p class="text-xl font-bold mt-1 <?= $cl['t'] ?>"><?= $c['val'] ?></p>
        </div>
        <div class="w-9 h-9 <?= $cl['bg'] ?> rounded-full flex items-center justify-center flex-shrink-0">
          <i class="fas <?= $c['icon'] ?> <?= $cl['t'] ?> text-sm"></i>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Filters -->
  <div class="bg-slate-800/50 rounded-xl p-4 mb-5">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
      <div class="flex-1 min-w-44">
        <label class="form-label">Search</label>
        <div class="relative">
          <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
          <input class="inp pl-8" type="text" name="search"
                 value="<?= htmlspecialchars($search) ?>"
                 placeholder="Email, domain or notes…" autocomplete="off">
        </div>
      </div>
      <div class="w-36">
        <label class="form-label">Status</label>
        <select class="inp" name="status">
          <option value="">All statuses</option>
          <?php foreach ($statusMeta as $k=>$m): ?>
          <option value="<?= $k ?>" <?= $statusFilter===$k?'selected':'' ?>><?= $m['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="w-28">
        <label class="form-label">Urgency</label>
        <select class="inp" name="urgency">
          <option value="">All</option>
          <?php foreach (['urgent','high','medium','low'] as $u): ?>
          <option value="<?= $u ?>" <?= $urgencyFilter===$u?'selected':'' ?>><?= ucfirst($u) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="w-32">
        <label class="form-label">Assigned to</label>
        <input class="inp" type="text" name="assigned" value="<?= htmlspecialchars($assignedFilter) ?>" placeholder="Broker handle">
      </div>
      <div class="w-36">
        <label class="form-label">Sort</label>
        <select class="inp" name="sort" onchange="this.form.submit()">
          <option value="created_at"   <?= $sortCol==='created_at'?'selected':'' ?>>Newest</option>
          <option value="updated_at"   <?= $sortCol==='updated_at'?'selected':'' ?>>Last update</option>
          <option value="urgency"      <?= $sortCol==='urgency'?'selected':'' ?>>Urgency</option>
          <option value="budget_kobo"  <?= $sortCol==='budget_kobo'?'selected':'' ?>>Budget</option>
        </select>
      </div>
      <input type="hidden" name="dir" value="<?= $sortDir==='ASC'?'asc':'desc' ?>">
      <button type="submit" class="btn-primary btn-sm flex items-center gap-2">
        <i class="fas fa-filter text-xs"></i> Filter
      </button>
      <?php if ($search||$statusFilter||$urgencyFilter||$assignedFilter): ?>
      <a href="broker.php" class="btn-secondary btn-sm flex items-center gap-2">
        <i class="fas fa-times text-xs"></i> Clear
      </a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Bulk action bar -->
  <div id="bulkBar" class="hidden bg-blue-900/30 border border-blue-500/30 rounded-xl px-4 py-3 mb-4 flex items-center gap-3 text-sm flex-wrap">
    <span id="bulkCount" class="font-mono text-blue-300">0 selected</span>
    <span class="text-gray-600">·</span>
    <span class="text-gray-400 text-xs">Move to:</span>
    <?php foreach (['researching','outreach','negotiating','completed','declined','on_hold'] as $s): ?>
    <button onclick="bulkStatus('<?= $s ?>')"
            class="btn-secondary btn-sm">
      <?= $statusMeta[$s]['label'] ?>
    </button>
    <?php endforeach; ?>
    <button onclick="clearSelection()" class="text-gray-500 hover:text-gray-300 text-xs ml-auto">Deselect all</button>
  </div>

  <!-- Table -->
  <div class="bg-slate-800/50 rounded-xl overflow-hidden">
    <?php if (!empty($requests)): ?>
    <form method="POST" id="bulkForm">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-700/50 border-b border-gray-700 text-xs uppercase tracking-wide text-gray-400">
          <tr>
            <th class="p-4 w-10"><input type="checkbox" id="selectAll" class="chk"></th>
            <th class="p-4 text-left">Request</th>
            <th class="p-4 text-left hide-mobile">User</th>
            <th class="p-4 text-left hide-mobile">
              <a href="<?= brSortUrl('budget_kobo') ?>" class="hover:text-white flex items-center">
                Budget <?= brSortIcon('budget_kobo') ?>
              </a>
            </th>
            <th class="p-4 text-left">Status</th>
            <th class="p-4 text-left hide-mobile">Pipeline</th>
            <th class="p-4 text-left hide-sm">
              <a href="<?= brSortUrl('updated_at') ?>" class="hover:text-white flex items-center">
                Updated <?= brSortIcon('updated_at') ?>
              </a>
            </th>
            <th class="p-4 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-700/50">
          <?php foreach ($requests as $req):
            $sm = $statusMeta[$req['status']] ?? $statusMeta['submitted'];
            $initials = strtoupper(substr($req['full_name'] ?: $req['email'], 0, 1));
            $isActive = !in_array($req['status'], ['completed','declined','canceled']);

            // Pipeline state: 0=todo, 1=active, 2=done, 3=failed
            $pipSteps  = ['Submitted','Research','Outreach','Negotiating','Transfer','Done'];
            $stepOrder = ['submitted'=>0,'researching'=>1,'outreach'=>2,'negotiating'=>3,'offer_made'=>3,'accepted'=>4,'transfer'=>4,'completed'=>5,'declined'=>-1,'canceled'=>-1,'on_hold'=>-1];
            $curStep   = $stepOrder[$req['status']] ?? 0;
            $isDone    = $req['status'] === 'completed';
            $isFailed  = in_array($req['status'], ['declined','canceled']);
          ?>
          <tr class="tbl-row <?= $req['urgency']==='urgent'?'border-l-2 border-red-500/40':($req['urgency']==='high'?'border-l-2 border-amber-500/30':'') ?>">

            <!-- Checkbox -->
            <td class="p-4">
              <input type="checkbox" name="selected_ids[]" value="<?= (int)$req['id'] ?>"
                     class="chk row-chk" onchange="onCheckChange()">
            </td>

            <!-- Request info -->
            <td class="p-4">
              <div class="flex items-start gap-2">
                <span class="u-dot ud-<?= $req['urgency'] ?> mt-1.5 flex-shrink-0"></span>
                <div class="min-w-0">
                  <div class="font-mono font-bold text-blue-300 text-sm"><?= htmlspecialchars($req['domain_name']) ?></div>
                  <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                    <span class="text-gray-500 text-xs">#<?= (int)$req['id'] ?></span>
                    <span class="text-xs text-gray-400"><?= ucfirst($req['purpose'] ?? 'other') ?></span>
                    <?php if ($req['urgency'] !== 'medium'): ?>
                    <span class="text-xs font-semibold <?= $req['urgency']==='urgent'?'text-red-400':($req['urgency']==='high'?'text-amber-400':'text-gray-500') ?>">
                      <?= ucfirst($req['urgency']) ?>
                    </span>
                    <?php endif; ?>
                  </div>
                  <?php if ($req['assigned_to']): ?>
                  <div class="text-gray-600 text-xs mt-0.5">
                    <i class="fas fa-user-tie text-xs mr-0.5"></i><?= htmlspecialchars($req['assigned_to']) ?>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
            </td>

            <!-- User -->
            <td class="p-4 hide-mobile">
              <div class="flex items-center gap-2">
                <div class="w-7 h-7 rounded-full flex-shrink-0 flex items-center justify-center font-bold text-xs"
                     style="background:linear-gradient(135deg,#2563EB,#06B6D4)">
                  <?php if ($req['avatar']): ?>
                  <img src="<?= htmlspecialchars($req['avatar']) ?>" class="w-7 h-7 rounded-full object-cover" alt="">
                  <?php else: ?>
                  <?= htmlspecialchars($initials) ?>
                  <?php endif; ?>
                </div>
                <div class="min-w-0">
                  <div class="text-white text-xs font-medium truncate max-w-28"><?= htmlspecialchars($req['full_name'] ?: '—') ?></div>
                  <div class="text-gray-500 text-xs truncate max-w-28"><?= htmlspecialchars($req['email']) ?></div>
                  <span class="text-xs font-mono <?= $req['plan']==='elite'?'text-yellow-400':($req['plan']==='pro'?'text-green-400':'text-gray-500') ?>">
                    <?= strtoupper($req['plan']??'free') ?>
                  </span>
                </div>
              </div>
            </td>

            <!-- Budget -->
            <td class="p-4 hide-mobile">
              <div class="font-mono text-sm text-white"><?= $kobo((int)$req['budget_kobo']) ?></div>
              <?php if ($req['budget_flexible']): ?>
              <div class="text-amber-400 text-xs mt-0.5"><i class="fas fa-arrows-alt-h text-xs"></i> Flexible</div>
              <?php endif; ?>
              <?php if ($req['agreed_price_kobo']): ?>
              <div class="text-green-400 text-xs mt-0.5">Agreed: <?= $kobo((int)$req['agreed_price_kobo']) ?></div>
              <?php endif; ?>
            </td>

            <!-- Status -->
            <td class="p-4">
              <span class="badge b-<?= $sm['color'] ?>">
                <i class="fas <?= $sm['icon'] ?> text-xs"></i>
                <?= $sm['label'] ?>
              </span>
              <?php if ($req['latest_update'] && $isActive): ?>
              <div class="text-gray-500 text-xs mt-1 max-w-28 truncate" title="<?= htmlspecialchars($req['latest_update']) ?>">
                <?= htmlspecialchars(substr($req['latest_update'],0,40)) ?>
              </div>
              <?php endif; ?>
            </td>

            <!-- Pipeline mini -->
            <td class="p-4 hide-mobile" style="min-width:160px;">
              <div class="flex items-center gap-0">
                <?php foreach ($pipSteps as $si => $sLabel):
                  if ($isDone)         $cls = 'done';
                  elseif ($isFailed)   $cls = $si <= $curStep ? 'failed' : '';
                  elseif ($si < $curStep) $cls = 'done';
                  elseif ($si === $curStep) $cls = 'active';
                  else $cls = '';
                  $icon = $cls==='done' ? 'fa-check' : ($cls==='failed' ? 'fa-times' : '');
                ?>
                <div class="pip-step <?= $cls ?>">
                  <div class="pip-dot">
                    <?php if ($icon): ?><i class="fas <?= $icon ?>" style="font-size:6px;"></i><?php endif; ?>
                  </div>
                  <div class="pip-lbl" style="width:24px;"><?= substr($sLabel,0,3) ?></div>
                </div>
                <?php endforeach; ?>
              </div>
            </td>

            <!-- Updated -->
            <td class="p-4 hide-sm">
              <div class="text-xs text-white"><?= date('M j, Y', strtotime($req['updated_at'])) ?></div>
              <div class="text-xs text-gray-500"><?= date('H:i', strtotime($req['updated_at'])) ?></div>
            </td>

            <!-- Actions -->
            <td class="p-4 text-right">
              <div class="flex items-center justify-end gap-1.5">
                <button type="button" onclick="openEditModal(<?= htmlspecialchars(json_encode($req),ENT_QUOTES) ?>)"
                        class="w-8 h-8 bg-blue-500/20 hover:bg-blue-500/30 rounded-lg flex items-center justify-center text-blue-400 transition"
                        title="Open request">
                  <i class="fas fa-edit text-xs"></i>
                </button>
                <button onclick="openDeleteModal(<?= (int)$req['id'] ?>, '<?= htmlspecialchars($req['domain_name'],ENT_QUOTES) ?>')"
                        class="w-8 h-8 bg-red-500/20 hover:bg-red-500/30 rounded-lg flex items-center justify-center text-red-400 transition"
                        title="Delete">
                  <i class="fas fa-trash text-xs"></i>
                </button>
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
        <a href="<?= brPageUrl($page-1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php
        $s=max(1,$page-2); $e=min($totalPages,$page+2);
        if ($s>1) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>';
        for ($i=$s;$i<=$e;$i++):
        ?>
        <a href="<?= brPageUrl($i) ?>" class="px-3 py-1.5 rounded text-xs transition <?= $i===$page?'bg-blue-600':'bg-slate-700 hover:bg-slate-600' ?>"><?= $i ?></a>
        <?php endfor;
        if ($e<$totalPages) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>'; ?>
        <?php if ($page < $totalPages): ?>
        <a href="<?= brPageUrl($page+1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    </form>

    <?php else: ?>
    <div class="text-center py-16">
      <i class="fas fa-handshake text-5xl text-gray-700 mb-4 block"></i>
      <p class="text-gray-400 mb-1">No broker requests found</p>
      <?php if ($search||$statusFilter||$urgencyFilter||$assignedFilter): ?>
      <a href="broker.php" class="text-blue-400 hover:text-blue-300 text-sm">Clear filters</a>
      <?php else: ?>
      <p class="text-gray-600 text-sm mt-1">Requests submitted by Elite users will appear here.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

</div>
</div>

<!-- ═══════════════════
     EDIT / MANAGE MODAL
═══════════════════ -->
<div class="modal-backdrop" id="editModal">
  <div class="modal-box" style="max-width:680px;">
    <div class="flex items-center justify-between mb-5">
      <div>
        <h2 class="text-lg font-bold">Broker request</h2>
        <div class="text-gray-400 text-sm" id="edit-subtitle"></div>
      </div>
      <button onclick="closeModal('editModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>

    <!-- User + request summary -->
    <div class="bg-slate-900/60 rounded-xl p-4 mb-5 grid grid-cols-2 gap-4 text-xs">
      <div>
        <div class="text-gray-500 uppercase tracking-wide mb-0.5">User</div>
        <div id="em-user" class="text-white font-medium"></div>
        <div id="em-email" class="text-blue-300 font-mono"></div>
      </div>
      <div>
        <div class="text-gray-500 uppercase tracking-wide mb-0.5">Budget</div>
        <div id="em-budget" class="text-white font-mono font-bold"></div>
        <div id="em-purpose" class="text-gray-400"></div>
      </div>
      <div>
        <div class="text-gray-500 uppercase tracking-wide mb-0.5">Urgency</div>
        <div id="em-urgency" class="font-semibold"></div>
      </div>
      <div>
        <div class="text-gray-500 uppercase tracking-wide mb-0.5">Submitted</div>
        <div id="em-created" class="text-gray-300 font-mono"></div>
      </div>
    </div>

    <!-- User notes -->
    <div id="em-notes-wrap" class="hidden bg-slate-900/60 rounded-xl p-3 mb-5 border border-slate-700">
      <div class="text-gray-500 text-xs uppercase tracking-wide mb-1">User notes</div>
      <div id="em-notes" class="text-gray-300 text-sm leading-relaxed whitespace-pre-wrap"></div>
    </div>

    <!-- Edit form -->
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="req_id" id="em-req-id">

      <!-- Status + Assigned -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Status <span class="text-red-400">*</span></label>
          <select class="inp" name="status" id="em-status">
            <?php foreach ($statusMeta as $k=>$m): ?>
            <option value="<?= $k ?>"><?= $m['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Assigned to</label>
          <input class="inp" type="text" name="assigned_to" id="em-assigned"
                 placeholder="Broker team handle" maxlength="100">
        </div>
      </div>

      <!-- Pricing -->
      <div class="grid grid-cols-3 gap-3">
        <div>
          <label class="form-label">Agreed price ($)</label>
          <input class="inp" type="number" name="agreed_price_ngn" id="em-agreed"
                 min="0" step="0.01" placeholder="e.g. 5000">
          <p class="form-hint">In dollars, stored as cents.</p>
        </div>
        <div>
          <label class="form-label">Broker fee ($)</label>
          <input class="inp" type="number" name="broker_fee_ngn" id="em-fee"
                 min="0" step="0.01" placeholder="e.g. 750">
        </div>
        <div>
          <label class="form-label">Commission %</label>
          <input class="inp" type="number" name="commission_pct" id="em-commission"
                 min="0" max="100" step="0.01" placeholder="e.g. 15">
        </div>
      </div>

      <!-- Public update (visible to user) -->
      <div>
        <label class="form-label">
          Public update
          <span class="text-blue-400 font-normal normal-case tracking-normal ml-1">— shown to user on their dashboard</span>
        </label>
        <textarea class="inp" name="latest_update" id="em-latest-update" rows="3"
                  placeholder="e.g. We've contacted the domain owner and are awaiting a response."
                  maxlength="2000" style="resize:vertical;line-height:1.5;font-family:inherit"></textarea>
        <p class="form-hint">Changing this text will automatically update the timestamp shown to the user and trigger a dashboard alert.</p>
      </div>

      <!-- Admin notes (internal) -->
      <div>
        <label class="form-label">
          Admin notes
          <span class="text-gray-500 font-normal normal-case tracking-normal ml-1">— internal only, never shown to user</span>
        </label>
        <textarea class="inp" name="admin_notes" id="em-admin-notes" rows="3"
                  placeholder="Owner contact details, negotiation history, internal context…"
                  maxlength="5000" style="resize:vertical;line-height:1.5;font-family:inherit"></textarea>
      </div>

      <div class="flex gap-3 justify-end pt-4 border-t border-gray-700">
        <button type="button" onclick="closeModal('editModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-green flex items-center gap-2">
          <i class="fas fa-save text-xs"></i> Save changes
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Delete modal -->
<div class="modal-backdrop" id="deleteModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold text-red-400"><i class="fas fa-trash mr-2"></i>Delete request</h2>
      <button onclick="closeModal('deleteModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <p class="text-gray-300 text-sm mb-5">
      Delete broker request for <span class="font-mono text-white" id="del-domain"></span>?
      This cannot be undone.
    </p>
    <form method="POST" class="flex gap-3 justify-end">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="req_id" id="del-id">
      <button type="button" onclick="closeModal('deleteModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-danger flex items-center gap-2">
        <i class="fas fa-trash text-xs"></i> Delete
      </button>
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

// ── Edit modal ────────────────────────────────────────────
function openEditModal(r) {
  const fmt = d => d ? new Date(d).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) : '—';
  const usdMinorAmount = amount => amount >= 100000 ? Math.round(amount / 1000) : amount;
  const kUsd = k => k > 0 ? '$'+Number(usdMinorAmount(k)/100).toLocaleString('en-US') : '$0';
  const URGENCY_COLORS = {urgent:'#EF4444',high:'#F59E0B',medium:'#3B82F6',low:'#6B7280'};

  document.getElementById('edit-subtitle').textContent = '#' + r.id + ' · ' + esc(r.domain_name);
  document.getElementById('em-req-id').value   = r.id;
  document.getElementById('em-status').value   = r.status;
  document.getElementById('em-assigned').value = r.assigned_to || '';

  document.getElementById('em-agreed').value      = r.agreed_price_kobo > 0 ? (usdMinorAmount(r.agreed_price_kobo)/100) : '';
  document.getElementById('em-fee').value         = r.broker_fee_kobo   > 0 ? (usdMinorAmount(r.broker_fee_kobo)/100)   : '';
  document.getElementById('em-commission').value  = r.commission_pct    || '';

  document.getElementById('em-latest-update').value = r.latest_update  || '';
  document.getElementById('em-admin-notes').value   = r.admin_notes    || '';

  // Summary display
  document.getElementById('em-user').textContent    = r.full_name || '—';
  document.getElementById('em-email').textContent   = r.email;
  document.getElementById('em-budget').textContent  = kUsd(r.budget_kobo||0) + (r.budget_flexible ? ' (flexible)' : '');
  document.getElementById('em-purpose').textContent = r.purpose ? ucfirst(r.purpose) : '';
  document.getElementById('em-created').textContent = fmt(r.created_at);

  const urgEl = document.getElementById('em-urgency');
  urgEl.textContent  = ucfirst(r.urgency||'medium');
  urgEl.style.color  = URGENCY_COLORS[r.urgency] || '#6B7280';

  const notesWrap = document.getElementById('em-notes-wrap');
  if (r.notes) {
    document.getElementById('em-notes').textContent = r.notes;
    notesWrap.classList.remove('hidden');
  } else { notesWrap.classList.add('hidden'); }

  openModal('editModal');
}

// ── Delete modal ──────────────────────────────────────────
function openDeleteModal(id, domain) {
  document.getElementById('del-id').value        = id;
  document.getElementById('del-domain').textContent = domain;
  openModal('deleteModal');
}

// ── Bulk ──────────────────────────────────────────────────
const bulkBar = document.getElementById('bulkBar');
const bulkCount = document.getElementById('bulkCount');

function onCheckChange() {
  const checked = document.querySelectorAll('.row-chk:checked');
  bulkCount.textContent = checked.length + ' selected';
  bulkBar.classList.toggle('hidden', checked.length === 0);
  const sa = document.getElementById('selectAll');
  if (sa) sa.checked = checked.length === document.querySelectorAll('.row-chk').length;
}
document.getElementById('selectAll')?.addEventListener('change', e => {
  document.querySelectorAll('.row-chk').forEach(c => c.checked = e.target.checked);
  onCheckChange();
});
function clearSelection() {
  document.querySelectorAll('.row-chk').forEach(c => c.checked = false);
  const sa = document.getElementById('selectAll');
  if (sa) sa.checked = false;
  onCheckChange();
}
function bulkStatus(status) {
  const ids = [...document.querySelectorAll('.row-chk:checked')].map(c => c.value);
  if (!ids.length) return;
  if (!confirm(`Move ${ids.length} request(s) to "${status}"?`)) return;

  const form = document.getElementById('bulkForm');
  let ai = form.querySelector('input[name="action"]');
  if (!ai) { ai = document.createElement('input'); ai.type='hidden'; ai.name='action'; form.appendChild(ai); }
  ai.value = 'bulk_status';
  let si = form.querySelector('input[name="bulk_status"]');
  if (!si) { si = document.createElement('input'); si.type='hidden'; si.name='bulk_status'; form.appendChild(si); }
  si.value = status;
  ids.forEach(id => {
    const inp = document.createElement('input');
    inp.type='hidden'; inp.name='selected_ids[]'; inp.value=id;
    form.appendChild(inp);
  });
  form.submit();
}

// ── Utils ─────────────────────────────────────────────────
function ucfirst(s) { return String(s||'').charAt(0).toUpperCase() + String(s||'').slice(1); }
function esc(s) { return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function showToast(msg, type='ok') {
  const t=document.getElementById('toast'), icon=document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  const c={ok:'#10B981',warn:'#F59E0B',err:'#EF4444'};
  const i={ok:'fa-check-circle',warn:'fa-exclamation-triangle',err:'fa-times-circle'};
  icon.className='fas '+(i[type]||'fa-info-circle'); icon.style.color=c[type]||'#10B981';
  t.style.transform='translateY(0)'; t.style.opacity='1';
  clearTimeout(t._t);
  t._t=setTimeout(()=>{t.style.transform='translateY(20px)';t.style.opacity='0';},4200);
}

<?php if ($flash): ?>
showToast(<?= json_encode(strip_tags($flash['msg'])) ?>, <?= json_encode($flash['type']) ?>);
<?php endif; ?>
</script>

</body>
</html>
