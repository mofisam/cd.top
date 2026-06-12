<?php
require_once 'auth_check.php';
$adminUser = checkAdminAuth();
require_once '../config/database.php';

$conn       = getDBConnection();
$activePage = 'alerts';

// ── Auto-create table if missing ─────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS domain_alerts (
        id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        user_id         INT UNSIGNED     NOT NULL,
        domain_name     VARCHAR(253)     NOT NULL,
        alert_type      ENUM(
                            'available','expiring_soon','just_expired',
                            'dead_site','backorder_won','backorder_lost',
                            'price_drop','whois_updated'
                        ) NOT NULL,
        status          ENUM('unread','read','dismissed','actioned') NOT NULL DEFAULT 'unread',
        priority        ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
        title           VARCHAR(255)     NOT NULL,
        message         TEXT             NULL,
        expires_in_days SMALLINT         NULL,
        action_url      VARCHAR(512)     NULL,
        action_label    VARCHAR(64)      NULL,
        read_at         TIMESTAMP        NULL,
        created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_alert_user   (user_id, status),
        INDEX idx_alert_domain (domain_name),
        INDEX idx_alert_type   (alert_type),
        INDEX idx_alert_created(created_at),
        PRIMARY KEY (id),
        CONSTRAINT fk_alert_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Helpers ──────────────────────────────────────────────────
$flash = null;

$alertTypeMeta = [
    'available'      => ['icon'=>'fa-check-circle',   'color'=>'green',  'label'=>'Available'],
    'expiring_soon'  => ['icon'=>'fa-clock',           'color'=>'amber',  'label'=>'Expiring soon'],
    'just_expired'   => ['icon'=>'fa-hourglass-end',   'color'=>'red',    'label'=>'Just expired'],
    'dead_site'      => ['icon'=>'fa-skull',           'color'=>'red',    'label'=>'Dead site'],
    'backorder_won'  => ['icon'=>'fa-trophy',          'color'=>'amber',  'label'=>'Backorder won'],
    'backorder_lost' => ['icon'=>'fa-times-circle',    'color'=>'gray',   'label'=>'Backorder lost'],
    'price_drop'     => ['icon'=>'fa-tag',             'color'=>'purple', 'label'=>'Price drop'],
    'whois_updated'  => ['icon'=>'fa-info-circle',     'color'=>'blue',   'label'=>'WHOIS update'],
];

// ── POST actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ── Create single alert ──────────────────────────────────
    if ($action === 'create') {
        $uid        = (int)($_POST['user_id'] ?? 0);
        $domain     = strtolower(trim(preg_replace('#^https?://(www\.)?#','',trim($_POST['domain_name']??''))));
        $type       = isset($alertTypeMeta[$_POST['alert_type']??'']) ? $_POST['alert_type'] : 'available';
        $status     = in_array($_POST['status']??'', ['unread','read','dismissed','actioned']) ? $_POST['status'] : 'unread';
        $priority   = in_array($_POST['priority']??'', ['high','medium','low']) ? $_POST['priority'] : 'medium';
        $title      = substr(strip_tags(trim($_POST['title']??'')), 0, 255);
        $message    = substr(strip_tags(trim($_POST['message']??'')), 0, 2000);
        $expireDays = trim($_POST['expires_in_days']??'') !== '' ? (int)$_POST['expires_in_days'] : null;
        $actionUrl  = substr(trim($_POST['action_url']??''), 0, 512) ?: null;
        $actionLbl  = substr(trim($_POST['action_label']??''), 0, 64) ?: null;

        if (!$uid || !$domain || !$title) {
            $flash = ['type'=>'err','msg'=>'User ID, domain name and title are required.']; goto done;
        }
        // Verify user exists
        $chkUser = $conn->prepare("SELECT id FROM users WHERE id=? LIMIT 1");
        $chkUser->bind_param("i", $uid); $chkUser->execute();
        if ($chkUser->get_result()->num_rows === 0) {
            $chkUser->close();
            $flash = ['type'=>'err','msg'=>"User #$uid not found."]; goto done;
        }
        $chkUser->close();

        $ins = $conn->prepare("
            INSERT INTO domain_alerts
              (user_id, domain_name, alert_type, status, priority, title, message,
               expires_in_days, action_url, action_label)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->bind_param("issssssiss",
            $uid, $domain, $type, $status, $priority, $title, $message,
            $expireDays, $actionUrl, $actionLbl
        );
        $ins->execute(); $ins->close();

        logAdminActivity($adminUser['id'], 'CREATE_ALERT', "Created alert for user #$uid — domain: $domain");
        $flash = ['type'=>'ok','msg'=>"Alert created for user #$uid ({$domain})."];
    }

    // ── Broadcast alert to all / plan users ─────────────────
    elseif ($action === 'broadcast') {
        $targetPlan = in_array($_POST['target_plan']??'', ['all','free','pro','elite']) ? $_POST['target_plan'] : 'all';
        $domain     = strtolower(trim($_POST['domain_name'] ?? ''));
        $type       = isset($alertTypeMeta[$_POST['alert_type']??'']) ? $_POST['alert_type'] : 'available';
        $priority   = in_array($_POST['priority']??'', ['high','medium','low']) ? $_POST['priority'] : 'medium';
        $title      = substr(strip_tags(trim($_POST['title']??'')), 0, 255);
        $message    = substr(strip_tags(trim($_POST['message']??'')), 0, 2000);
        $actionUrl  = substr(trim($_POST['action_url']??''), 0, 512) ?: null;
        $actionLbl  = substr(trim($_POST['action_label']??''), 0, 64) ?: null;

        if (!$domain || !$title) {
            $flash = ['type'=>'err','msg'=>'Domain and title are required for broadcast.']; goto done;
        }

        $planWhere = $targetPlan === 'all' ? '' : "AND plan='{$conn->real_escape_string($targetPlan)}'";
        $users = $conn->query("SELECT id FROM users WHERE status='active' $planWhere");
        $count = 0;
        while ($u = $users->fetch_assoc()) {
            $uid = (int)$u['id'];
            $ins = $conn->prepare("
                INSERT INTO domain_alerts
                  (user_id, domain_name, alert_type, status, priority, title, message, action_url, action_label)
                VALUES (?,?,?,'unread',?,?,?,?,?)
            ");
            $ins->bind_param("isssssss", $uid, $domain, $type, $priority, $title, $message, $actionUrl, $actionLbl);
            $ins->execute(); $ins->close();
            $count++;
        }

        logAdminActivity($adminUser['id'], 'BROADCAST_ALERT', "Broadcast alert to $count users — target: $targetPlan, domain: $domain");
        $flash = ['type'=>'ok','msg'=>"Alert broadcast to <strong>{$count}</strong> user(s) (target: {$targetPlan})."];
    }

    // ── Update alert status ──────────────────────────────────
    elseif ($action === 'update_status') {
        $alertId   = (int)($_POST['alert_id'] ?? 0);
        $newStatus = in_array($_POST['new_status']??'', ['unread','read','dismissed','actioned'])
                     ? $_POST['new_status'] : null;
        if ($newStatus && $alertId) {
            $readAt = ($newStatus === 'read') ? ", read_at=NOW()" : "";
            $conn->query("UPDATE domain_alerts SET status='$newStatus'$readAt WHERE id=$alertId");
            logAdminActivity($adminUser['id'], 'UPDATE_ALERT_STATUS', "Changed alert #$alertId to $newStatus");
            $flash = ['type'=>'ok','msg'=>"Alert #$alertId status updated to {$newStatus}."];
        }
    }

    // ── Delete alert ─────────────────────────────────────────
    elseif ($action === 'delete') {
        $alertId = (int)($_POST['alert_id'] ?? 0);
        if ($alertId) {
            $conn->query("DELETE FROM domain_alerts WHERE id=$alertId");
            logAdminActivity($adminUser['id'], 'DELETE_ALERT', "Deleted alert #$alertId");
            $flash = ['type'=>'ok','msg'=>"Alert #$alertId deleted."];
        }
    }

    // ── Bulk delete ──────────────────────────────────────────
    elseif ($action === 'bulk_delete') {
        $ids = array_map('intval', (array)($_POST['selected_ids']??[]));
        if ($ids) {
            $ph = implode(',', $ids);
            $conn->query("DELETE FROM domain_alerts WHERE id IN ($ph)");
            logAdminActivity($adminUser['id'], 'BULK_DELETE_ALERTS', "Deleted ".count($ids)." alerts");
            $flash = ['type'=>'ok','msg'=>count($ids)." alert(s) deleted."];
        }
    }

    // ── Bulk mark read ────────────────────────────────────────
    elseif ($action === 'bulk_read') {
        $ids = array_map('intval', (array)($_POST['selected_ids']??[]));
        if ($ids) {
            $ph = implode(',', $ids);
            $conn->query("UPDATE domain_alerts SET status='read', read_at=NOW() WHERE id IN ($ph)");
            logAdminActivity($adminUser['id'], 'BULK_READ_ALERTS', "Marked ".count($ids)." alerts as read");
            $flash = ['type'=>'ok','msg'=>count($ids)." alert(s) marked as read."];
        }
    }

    done:
}

// ── CSV export ────────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="alerts_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','User ID','Email','Domain','Type','Status','Priority','Title','Message','Read At','Created']);
    $rs = $conn->query("
        SELECT a.id, a.user_id, u.email, a.domain_name, a.alert_type,
               a.status, a.priority, a.title, a.message, a.read_at, a.created_at
        FROM domain_alerts a
        JOIN users u ON u.id=a.user_id
        ORDER BY a.created_at DESC
    ");
    while ($r = $rs->fetch_assoc()) fputcsv($out, $r);
    fclose($out);
    $conn->close(); exit();
}

// ── Filters ──────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$typeFilter   = isset($alertTypeMeta[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$statusFilter = in_array($_GET['status']??'', ['unread','read','dismissed','actioned','']) ? ($_GET['status']??'') : '';
$priorityFilter = in_array($_GET['priority']??'', ['high','medium','low','']) ? ($_GET['priority']??'') : '';
$userFilter   = (int)($_GET['user_id'] ?? 0);
$sortCol      = in_array($_GET['sort']??'', ['created_at','priority','status','alert_type']) ? $_GET['sort'] : 'created_at';
$sortDir      = ($_GET['dir']??'desc') === 'asc' ? 'ASC' : 'DESC';
$page         = max(1, (int)($_GET['page']??1));
$perPage      = 30;
$offset       = ($page-1)*$perPage;

$where  = ['1=1']; $binds = []; $types = '';
if ($search) {
    $like    = "%$search%";
    $where[] = "(u.email LIKE ? OR u.full_name LIKE ? OR a.domain_name LIKE ? OR a.title LIKE ?)";
    $binds   = array_merge($binds, [$like,$like,$like,$like]); $types .= 'ssss';
}
if ($typeFilter)     { $where[] = "a.alert_type=?"; $binds[] = $typeFilter;     $types .= 's'; }
if ($statusFilter)   { $where[] = "a.status=?";     $binds[] = $statusFilter;   $types .= 's'; }
if ($priorityFilter) { $where[] = "a.priority=?";   $binds[] = $priorityFilter; $types .= 's'; }
if ($userFilter)     { $where[] = "a.user_id=?";    $binds[] = $userFilter;     $types .= 'i'; }

$whereSQL = implode(' AND ', $where);
$sortMap  = ['created_at'=>'a.created_at','priority'=>'FIELD(a.priority,"high","medium","low")','status'=>'a.status','alert_type'=>'a.alert_type'];
$orderSQL = ($sortMap[$sortCol] ?? 'a.created_at') . ' ' . $sortDir;

// Count
$cStmt = $conn->prepare("
    SELECT COUNT(*) as c FROM domain_alerts a
    JOIN users u ON u.id=a.user_id WHERE $whereSQL
");
if ($types) $cStmt->bind_param($types, ...$binds);
$cStmt->execute();
$totalRows  = (int)$cStmt->get_result()->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($totalRows/$perPage));
$cStmt->close();

// Data
$dStmt = $conn->prepare("
    SELECT a.*, u.email, u.full_name, u.avatar
    FROM domain_alerts a
    JOIN users u ON u.id=a.user_id
    WHERE $whereSQL
    ORDER BY $orderSQL
    LIMIT ? OFFSET ?
");
$allBinds = array_merge($binds, [$perPage, $offset]);
$allTypes = $types.'ii';
$dStmt->bind_param($allTypes, ...$allBinds);
$dStmt->execute();

$result = $dStmt->get_result();

if (!$result) {
    die($conn->error);
}

$alerts = $result->fetch_all(MYSQLI_ASSOC);

$result->free();
$dStmt->close();

// ── Summary stats ─────────────────────────────────────────────
$safe = fn($q): int => (int)($conn->query($q)?->fetch_assoc()['c'] ?? 0);

$statTotal    = $safe("SELECT COUNT(*) as c FROM domain_alerts");
$statUnread   = $safe("SELECT COUNT(*) as c FROM domain_alerts WHERE status='unread'");
$statHigh     = $safe("SELECT COUNT(*) as c FROM domain_alerts WHERE priority='high' AND status='unread'");
$statToday    = $safe("SELECT COUNT(*) as c FROM domain_alerts WHERE DATE(created_at)=CURDATE()");
$statAvail    = $safe("SELECT COUNT(*) as c FROM domain_alerts WHERE alert_type='available' AND status='unread'");
$statExpiring = $safe("SELECT COUNT(*) as c FROM domain_alerts WHERE alert_type IN ('expiring_soon','just_expired') AND status='unread'");

// Users list for create form (limited)
$usersList = [];
$uQ = $conn->query("SELECT id, email, full_name FROM users WHERE status='active' ORDER BY email LIMIT 200");
while ($r = $uQ->fetch_assoc()) $usersList[] = $r;

$conn->close();

// ── URL helpers ───────────────────────────────────────────────
function alPageUrl(int $p, array $extra = []): string {
    $params = array_merge($_GET, $extra, ['page'=>$p]);
    unset($params['export']);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== 0));
}
function alSortUrl(string $col): string {
    $dir = ($_GET['sort']??'') === $col && ($_GET['dir']??'desc') === 'asc' ? 'desc' : 'asc';
    return alPageUrl(1, ['sort'=>$col,'dir'=>$dir]);
}
function alSortIcon(string $col): string {
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
<title>Alerts — CheckDomain Admin</title>
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
.modal-box{background:#1E293B;border:1px solid rgba(59,130,246,.3);border-radius:1rem;padding:1.75rem;max-width:540px;width:90%;transform:scale(.96);transition:transform .2s;max-height:92vh;overflow-y:auto}
.modal-backdrop.open .modal-box{transform:scale(1)}
/* Flash */
.flash-ok  {background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#34D399}
.flash-warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:#FCD34D}
.flash-err {background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.3); color:#FCA5A5}
/* Badges */
.badge{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:600;padding:2px 7px;border-radius:9999px;white-space:nowrap}
.b-unread   {background:rgba(245,158,11,.2); color:#FCD34D}
.b-read     {background:rgba(107,114,128,.2);color:#9CA3AF}
.b-dismissed{background:rgba(107,114,128,.15);color:#64748B}
.b-actioned {background:rgba(16,185,129,.15);color:#34D399}
.b-high     {background:rgba(239,68,68,.2);  color:#FCA5A5}
.b-medium   {background:rgba(245,158,11,.15);color:#FCD34D}
.b-low      {background:rgba(107,114,128,.2);color:#9CA3AF}
.b-available       {background:rgba(16,185,129,.15);color:#34D399}
.b-expiring_soon   {background:rgba(245,158,11,.15);color:#FCD34D}
.b-just_expired    {background:rgba(239,68,68,.15); color:#FCA5A5}
.b-dead_site       {background:rgba(239,68,68,.15); color:#FCA5A5}
.b-backorder_won   {background:rgba(245,158,11,.15);color:#FCD34D}
.b-backorder_lost  {background:rgba(107,114,128,.2);color:#9CA3AF}
.b-price_drop      {background:rgba(168,85,247,.15);color:#C4B5FD}
.b-whois_updated   {background:rgba(59,130,246,.15);color:#93C5FD}
/* Priority dot */
.p-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.pd-high  {background:#EF4444;box-shadow:0 0 6px rgba(239,68,68,.5);animation:pulse 1.5s infinite}
.pd-medium{background:#F59E0B}
.pd-low   {background:#6B7280}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
/* Inputs */
.inp{background:rgba(51,65,85,.8);border:1px solid rgba(71,85,105,1);border-radius:.5rem;padding:.5rem .75rem;color:#fff;width:100%;font-size:.875rem;transition:border-color .2s;outline:none}
.inp:focus{border-color:#3B82F6}
.inp::placeholder{color:#64748B}
.form-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#64748B;margin-bottom:.25rem;display:block}
.btn-primary  {background:#2563EB;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-primary:hover{background:#1D4ED8}
.btn-secondary{background:#334155;color:#CBD5E1;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-secondary:hover{background:#475569}
.btn-danger   {background:#DC2626;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-danger:hover{background:#B91C1C}
.btn-amber    {background:#D97706;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-amber:hover{background:#B45309}
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

  <!-- Page header -->
  <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
    <div>
      <h1 class="text-2xl md:text-3xl font-bold">Alerts</h1>
      <p class="text-gray-400 text-sm mt-1">
        <?= number_format($totalRows) ?> alert<?= $totalRows!==1?'s':'' ?>
        <?php if ($search||$typeFilter||$statusFilter||$priorityFilter||$userFilter): ?>
        <span class="text-blue-400">(filtered)</span>
        <?php endif; ?>
      </p>
    </div>
    <div class="flex flex-wrap gap-3">
      <a href="?export=1" class="btn-secondary flex items-center gap-2 text-sm">
        <i class="fas fa-download text-xs"></i> Export CSV
      </a>
      <button onclick="openModal('broadcastModal')" class="btn-amber flex items-center gap-2 text-sm">
        <i class="fas fa-broadcast-tower text-xs"></i> Broadcast
      </button>
      <button onclick="openModal('createModal')" class="btn-primary flex items-center gap-2 text-sm">
        <i class="fas fa-plus text-xs"></i> Create alert
      </button>
    </div>
  </div>

  <!-- Stats -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-8">
    <?php
    $cards = [
      ['lbl'=>'Total alerts',  'val'=>number_format($statTotal),    'icon'=>'fa-bell',        'c'=>'blue'],
      ['lbl'=>'Unread',        'val'=>number_format($statUnread),   'icon'=>'fa-envelope',    'c'=>'amber'],
      ['lbl'=>'High priority', 'val'=>number_format($statHigh),     'icon'=>'fa-exclamation', 'c'=>'red'],
      ['lbl'=>'Today',         'val'=>number_format($statToday),    'icon'=>'fa-calendar-day','c'=>'purple'],
      ['lbl'=>'Available',     'val'=>number_format($statAvail),    'icon'=>'fa-check-circle','c'=>'green'],
      ['lbl'=>'Expiring',      'val'=>number_format($statExpiring), 'icon'=>'fa-clock',       'c'=>'amber'],
    ];
    $cmap=['blue'=>['bg'=>'bg-blue-500/20','t'=>'text-blue-400'],'amber'=>['bg'=>'bg-amber-500/20','t'=>'text-amber-400'],'red'=>['bg'=>'bg-red-500/20','t'=>'text-red-400'],'green'=>['bg'=>'bg-green-500/20','t'=>'text-green-400'],'purple'=>['bg'=>'bg-purple-500/20','t'=>'text-purple-400']];
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

  <!-- Quick filter pills -->
  <div class="flex flex-wrap gap-2 mb-4">
    <?php
    $quickFilters = [
      ['label'=>'All',              'params'=>[]],
      ['label'=>'Unread',           'params'=>['status'=>'unread']],
      ['label'=>'High priority',    'params'=>['priority'=>'high']],
      ['label'=>'Available today',  'params'=>['type'=>'available','status'=>'unread']],
      ['label'=>'Expiring',         'params'=>['type'=>'expiring_soon']],
      ['label'=>'Dead sites',       'params'=>['type'=>'dead_site']],
    ];
    foreach ($quickFilters as $qf):
      $isActive = empty(array_diff_assoc($qf['params'], array_intersect_key($_GET,$qf['params'])))
                  && count($qf['params']) === count(array_filter(array_intersect_key($_GET,$qf['params'])));
    ?>
    <a href="<?= alPageUrl(1, array_merge(['search'=>'','status'=>'','type'=>'','priority'=>'','user_id'=>''], $qf['params'])) ?>"
       class="px-3 py-1.5 rounded-full text-xs font-medium transition <?= $isActive?'bg-blue-600 text-white':'bg-slate-700 text-gray-400 hover:bg-slate-600 hover:text-white' ?>">
      <?= $qf['label'] ?>
    </a>
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
                 placeholder="Email, domain or title…" autocomplete="off">
        </div>
      </div>
      <div class="w-36">
        <label class="form-label">Alert type</label>
        <select class="inp" name="type">
          <option value="">All types</option>
          <?php foreach ($alertTypeMeta as $k=>$m): ?>
          <option value="<?= $k ?>" <?= $typeFilter===$k?'selected':'' ?>><?= $m['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="w-32">
        <label class="form-label">Status</label>
        <select class="inp" name="status">
          <option value="">All</option>
          <?php foreach (['unread','read','dismissed','actioned'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="w-28">
        <label class="form-label">Priority</label>
        <select class="inp" name="priority">
          <option value="">All</option>
          <?php foreach (['high','medium','low'] as $p): ?>
          <option value="<?= $p ?>" <?= $priorityFilter===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($userFilter): ?>
      <div class="w-28">
        <label class="form-label">User ID</label>
        <input class="inp" type="number" name="user_id" value="<?= $userFilter ?>" placeholder="User ID">
      </div>
      <?php endif; ?>
      <div class="w-36">
        <label class="form-label">Sort</label>
        <select class="inp" name="sort" onchange="this.form.submit()">
          <option value="created_at" <?= $sortCol==='created_at'?'selected':'' ?>>Newest</option>
          <option value="priority"   <?= $sortCol==='priority'?'selected':'' ?>>Priority</option>
          <option value="status"     <?= $sortCol==='status'?'selected':'' ?>>Status</option>
          <option value="alert_type" <?= $sortCol==='alert_type'?'selected':'' ?>>Type</option>
        </select>
      </div>
      <input type="hidden" name="dir" value="<?= $sortDir==='ASC'?'asc':'desc' ?>">
      <button type="submit" class="btn-primary btn-sm flex items-center gap-2">
        <i class="fas fa-filter text-xs"></i> Filter
      </button>
      <?php if ($search||$typeFilter||$statusFilter||$priorityFilter||$userFilter): ?>
      <a href="alerts.php" class="btn-secondary btn-sm flex items-center gap-2">
        <i class="fas fa-times text-xs"></i> Clear
      </a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Bulk action bar -->
  <div id="bulkBar" class="hidden bg-blue-900/30 border border-blue-500/30 rounded-xl px-4 py-3 mb-4 flex items-center gap-3 text-sm flex-wrap">
    <span id="bulkCount" class="font-mono text-blue-300">0 selected</span>
    <span class="text-gray-600">·</span>
    <button onclick="bulkAction('bulk_read')" class="btn-secondary btn-sm flex items-center gap-1.5">
      <i class="fas fa-check text-green-400 text-xs"></i> Mark read
    </button>
    <button onclick="bulkAction('bulk_delete')" class="btn-secondary btn-sm flex items-center gap-1.5">
      <i class="fas fa-trash text-red-400 text-xs"></i> Delete selected
    </button>
    <button onclick="clearSelection()" class="text-gray-500 hover:text-gray-300 text-xs ml-auto">Deselect all</button>
  </div>

  <!-- Alerts table -->
  <div class="bg-slate-800/50 rounded-xl overflow-hidden">
    <?php if (!empty($alerts)): ?>
    <form method="POST" id="bulkForm">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-700/50 border-b border-gray-700 text-xs uppercase tracking-wide text-gray-400">
          <tr>
            <th class="p-4 w-10"><input type="checkbox" id="selectAll" class="chk"></th>
            <th class="p-4 text-left">
              <a href="<?= alSortUrl('priority') ?>" class="hover:text-white flex items-center">
                Alert <?= alSortIcon('priority') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-mobile">User</th>
            <th class="p-4 text-left hide-mobile">
              <a href="<?= alSortUrl('alert_type') ?>" class="hover:text-white flex items-center">
                Type <?= alSortIcon('alert_type') ?>
              </a>
            </th>
            <th class="p-4 text-left">
              <a href="<?= alSortUrl('status') ?>" class="hover:text-white flex items-center">
                Status <?= alSortIcon('status') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-sm">
              <a href="<?= alSortUrl('created_at') ?>" class="hover:text-white flex items-center">
                Date <?= alSortIcon('created_at') ?>
              </a>
            </th>
            <th class="p-4 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-700/50">
          <?php foreach ($alerts as $alert):
            $am       = $alertTypeMeta[$alert['alert_type']] ?? $alertTypeMeta['whois_updated'];
            $isUnread = $alert['status'] === 'unread';
            $initials = strtoupper(substr($alert['full_name'] ?: $alert['email'], 0, 1));
          ?>
          <tr class="tbl-row <?= $isUnread?'border-l-2 border-amber-500/30':'' ?>">

            <!-- Checkbox -->
            <td class="p-4">
              <input type="checkbox" name="selected_ids[]" value="<?= (int)$alert['id'] ?>"
                     class="chk row-chk" onchange="onCheckChange()">
            </td>

            <!-- Alert info -->
            <td class="p-4">
              <div class="flex items-start gap-3">
                <div class="p-dot pd-<?= $alert['priority'] ?> mt-1.5 flex-shrink-0"></div>
                <div class="min-w-0">
                  <div class="font-medium text-white text-sm truncate max-w-56" title="<?= htmlspecialchars($alert['title']) ?>">
                    <?= htmlspecialchars($alert['title']) ?>
                  </div>
                  <div class="flex items-center gap-1.5 mt-0.5">
                    <span class="font-mono text-xs text-blue-300"><?= htmlspecialchars($alert['domain_name']) ?></span>
                    <span class="badge b-<?= $alert['priority'] ?> hidden sm:inline-flex"><?= ucfirst($alert['priority']) ?></span>
                  </div>
                  <?php if ($alert['message']): ?>
                  <div class="text-gray-500 text-xs mt-0.5 truncate max-w-52" title="<?= htmlspecialchars($alert['message']) ?>">
                    <?= htmlspecialchars(substr($alert['message'],0,60)) ?>
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
                  <?php if ($alert['avatar']): ?>
                  <img src="<?= htmlspecialchars($alert['avatar']) ?>" class="w-7 h-7 rounded-full object-cover" alt="">
                  <?php else: ?>
                  <?= htmlspecialchars($initials) ?>
                  <?php endif; ?>
                </div>
                <div class="min-w-0">
                  <a href="<?= alPageUrl(1, ['user_id'=>$alert['user_id'],'search'=>'','status'=>'','type'=>'','priority'=>'']) ?>"
                     class="text-blue-400 hover:text-blue-300 text-xs font-medium truncate max-w-28 block transition">
                    <?= htmlspecialchars($alert['full_name'] ?: '—') ?>
                  </a>
                  <div class="text-gray-500 text-xs truncate max-w-28"><?= htmlspecialchars($alert['email']) ?></div>
                </div>
              </div>
            </td>

            <!-- Type -->
            <td class="p-4 hide-mobile">
              <span class="badge b-<?= $alert['alert_type'] ?>">
                <i class="fas <?= $am['icon'] ?> text-xs"></i>
                <?= $am['label'] ?>
              </span>
            </td>

            <!-- Status -->
            <td class="p-4">
              <span class="badge b-<?= $alert['status'] ?>">
                <?= ucfirst($alert['status']) ?>
              </span>
              <?php if ($alert['read_at']): ?>
              <div class="text-gray-600 text-xs mt-0.5"><?= date('M j H:i', strtotime($alert['read_at'])) ?></div>
              <?php endif; ?>
            </td>

            <!-- Date -->
            <td class="p-4 hide-sm">
              <div class="text-xs text-white"><?= date('M j, Y', strtotime($alert['created_at'])) ?></div>
              <div class="text-xs text-gray-500"><?= date('H:i', strtotime($alert['created_at'])) ?></div>
            </td>

            <!-- Actions -->
            <td class="p-4 text-right">
              <div class="flex items-center justify-end gap-1.5">
                <!-- View detail -->
                <button onclick="openDetailModal(<?= htmlspecialchars(json_encode($alert),ENT_QUOTES) ?>)"
                        class="w-8 h-8 bg-blue-500/20 hover:bg-blue-500/30 rounded-lg flex items-center justify-center text-blue-400 transition"
                        title="View details">
                  <i class="fas fa-eye text-xs"></i>
                </button>
                <!-- Mark read (if unread) -->
                <?php if ($isUnread): ?>
                <form method="POST" class="inline">
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="alert_id" value="<?= (int)$alert['id'] ?>">
                  <input type="hidden" name="new_status" value="read">
                  <button type="submit"
                          class="w-8 h-8 bg-green-500/20 hover:bg-green-500/30 rounded-lg flex items-center justify-center text-green-400 transition"
                          title="Mark as read">
                    <i class="fas fa-check text-xs"></i>
                  </button>
                </form>
                <?php endif; ?>
                <!-- Delete -->
                <button onclick="openDeleteModal(<?= (int)$alert['id'] ?>, '<?= htmlspecialchars($alert['title'],ENT_QUOTES) ?>')"
                        class="w-8 h-8 bg-red-500/20 hover:bg-red-500/30 rounded-lg flex items-center justify-center text-red-400 transition"
                        title="Delete alert">
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
        <a href="<?= alPageUrl($page-1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php
        $s=max(1,$page-2); $e=min($totalPages,$page+2);
        if ($s>1) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>';
        for ($i=$s;$i<=$e;$i++):
        ?>
        <a href="<?= alPageUrl($i) ?>" class="px-3 py-1.5 rounded text-xs transition <?= $i===$page?'bg-blue-600':'bg-slate-700 hover:bg-slate-600' ?>"><?= $i ?></a>
        <?php endfor;
        if ($e<$totalPages) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>'; ?>
        <?php if ($page < $totalPages): ?>
        <a href="<?= alPageUrl($page+1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    </form>

    <?php else: ?>
    <div class="text-center py-16">
      <i class="fas fa-bell text-5xl text-gray-700 mb-4 block"></i>
      <p class="text-gray-400 mb-1">No alerts found</p>
      <?php if ($search||$typeFilter||$statusFilter||$priorityFilter||$userFilter): ?>
      <a href="alerts.php" class="text-blue-400 hover:text-blue-300 text-sm">Clear filters</a>
      <?php else: ?>
      <p class="text-gray-600 text-sm mt-1">Alerts are created automatically when domains are monitored.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

</div>
</div>

<!-- ════════════════════
     MODALS
════════════════════ -->

<!-- Detail modal -->
<div class="modal-backdrop" id="detailModal">
  <div class="modal-box" style="max-width:520px;">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold">Alert details</h2>
      <button onclick="closeModal('detailModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <div class="grid grid-cols-2 gap-x-5 gap-y-3 text-sm mb-5" id="detailGrid"></div>
    <div id="detailMessage" class="hidden bg-slate-900 rounded-lg p-3 text-sm text-gray-300 leading-relaxed mb-4"></div>
    <div id="detailActions" class="hidden bg-slate-900 rounded-lg p-3 text-xs text-blue-300 mb-4"></div>
    <!-- Inline status change -->
    <div class="border-t border-gray-700 pt-4">
      <form method="POST" class="flex items-end gap-3">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="alert_id" id="detail-alert-id">
        <div class="flex-1">
          <label class="form-label">Change status</label>
          <select class="inp" name="new_status" id="detail-status-select">
            <?php foreach (['unread','read','dismissed','actioned'] as $s): ?>
            <option value="<?= $s ?>"><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn-primary btn-sm flex items-center gap-1.5">
          <i class="fas fa-save text-xs"></i> Update
        </button>
        <button type="button" onclick="closeModal('detailModal')" class="btn-secondary btn-sm">Close</button>
      </form>
    </div>
  </div>
</div>

<!-- Create alert modal -->
<div class="modal-backdrop" id="createModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold">Create alert</h2>
      <button onclick="closeModal('createModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="create">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">User <span class="text-red-400">*</span></label>
          <select class="inp" name="user_id" required>
            <option value="">— Select user —</option>
            <?php foreach ($usersList as $u): ?>
            <option value="<?= (int)$u['id'] ?>"
                    <?= $userFilter===$u['id']?'selected':'' ?>>
              #<?= $u['id'] ?> · <?= htmlspecialchars($u['email']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Domain <span class="text-red-400">*</span></label>
          <input class="inp" type="text" name="domain_name" placeholder="example.com" maxlength="253" required>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Alert type <span class="text-red-400">*</span></label>
          <select class="inp" name="alert_type">
            <?php foreach ($alertTypeMeta as $k=>$m): ?>
            <option value="<?= $k ?>"><?= $m['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Priority</label>
          <select class="inp" name="priority">
            <option value="medium">Medium</option>
            <option value="high">High</option>
            <option value="low">Low</option>
          </select>
        </div>
      </div>
      <div>
        <label class="form-label">Title <span class="text-red-400">*</span></label>
        <input class="inp" type="text" name="title" placeholder="e.g. mybrand.com is now available!" maxlength="255" required>
      </div>
      <div>
        <label class="form-label">Message</label>
        <textarea class="inp" name="message" rows="3" placeholder="Detailed alert message (optional)" maxlength="2000"
                  style="resize:vertical;line-height:1.5;font-family:inherit"></textarea>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">CTA label</label>
          <input class="inp" type="text" name="action_label" placeholder="e.g. Register now" maxlength="64">
        </div>
        <div>
          <label class="form-label">CTA URL</label>
          <input class="inp" type="url" name="action_url" placeholder="https://…" maxlength="512">
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Expires in (days)</label>
          <input class="inp" type="number" name="expires_in_days" min="1" placeholder="e.g. 30">
        </div>
        <div>
          <label class="form-label">Initial status</label>
          <select class="inp" name="status">
            <option value="unread">Unread</option>
            <option value="read">Read</option>
          </select>
        </div>
      </div>
      <div class="flex gap-3 justify-end pt-3 border-t border-gray-700">
        <button type="button" onclick="closeModal('createModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-primary flex items-center gap-2">
          <i class="fas fa-bell text-xs"></i> Create alert
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Broadcast modal -->
<div class="modal-backdrop" id="broadcastModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold text-amber-400">
        <i class="fas fa-broadcast-tower mr-2"></i>Broadcast alert
      </h2>
      <button onclick="closeModal('broadcastModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <p class="text-gray-400 text-sm mb-5">
      Send an alert to <strong class="text-white">all active users</strong> or a specific plan tier at once.
      Each user will receive an individual alert record.
    </p>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="broadcast">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Target audience</label>
          <select class="inp" name="target_plan">
            <option value="all">All active users</option>
            <option value="free">Free plan only</option>
            <option value="pro">Pro plan only</option>
            <option value="elite">Elite plan only</option>
          </select>
        </div>
        <div>
          <label class="form-label">Alert type <span class="text-red-400">*</span></label>
          <select class="inp" name="alert_type">
            <?php foreach ($alertTypeMeta as $k=>$m): ?>
            <option value="<?= $k ?>"><?= $m['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Domain <span class="text-red-400">*</span></label>
          <input class="inp" type="text" name="domain_name" placeholder="affected.com" maxlength="253" required>
        </div>
        <div>
          <label class="form-label">Priority</label>
          <select class="inp" name="priority">
            <option value="medium">Medium</option>
            <option value="high">High</option>
            <option value="low">Low</option>
          </select>
        </div>
      </div>
      <div>
        <label class="form-label">Title <span class="text-red-400">*</span></label>
        <input class="inp" type="text" name="title" placeholder="Alert title shown to all users" maxlength="255" required>
      </div>
      <div>
        <label class="form-label">Message</label>
        <textarea class="inp" name="message" rows="3" placeholder="Optional detailed message…" maxlength="2000"
                  style="resize:vertical;line-height:1.5;font-family:inherit"></textarea>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">CTA label</label>
          <input class="inp" type="text" name="action_label" placeholder="Learn more" maxlength="64">
        </div>
        <div>
          <label class="form-label">CTA URL</label>
          <input class="inp" type="url" name="action_url" placeholder="https://…" maxlength="512">
        </div>
      </div>
      <div class="bg-amber-500/10 border border-amber-500/30 rounded-lg px-3 py-2 text-amber-300 text-xs">
        <i class="fas fa-exclamation-triangle mr-1"></i>
        This inserts one alert row per matching user. Cannot be undone in bulk — use with care.
      </div>
      <div class="flex gap-3 justify-end pt-3 border-t border-gray-700">
        <button type="button" onclick="closeModal('broadcastModal')" class="btn-secondary">Cancel</button>
        <button type="submit"
                class="btn-amber flex items-center gap-2"
                onclick="return confirm('Broadcast this alert to all matching users?')">
          <i class="fas fa-broadcast-tower text-xs"></i> Broadcast
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Delete modal -->
<div class="modal-backdrop" id="deleteModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold text-red-400"><i class="fas fa-trash mr-2"></i>Delete alert</h2>
      <button onclick="closeModal('deleteModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <p class="text-gray-300 text-sm mb-5">
      Delete alert "<span id="del-title" class="text-white font-medium"></span>"?
      This cannot be undone.
    </p>
    <form method="POST" class="flex gap-3 justify-end">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="alert_id" id="del-id">
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

// ── Detail modal ──────────────────────────────────────────
function openDetailModal(a) {
  const fmt  = d => d ? new Date(d).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
  const TYPE_META = <?= json_encode($alertTypeMeta) ?>;

  const fields = [
    {l:'Alert ID',    v:'#'+a.id},
    {l:'User',        v:'#'+a.user_id+' · '+esc(a.email)},
    {l:'Domain',      v:'<span class="font-mono text-blue-300">'+esc(a.domain_name)+'</span>'},
    {l:'Type',        v:esc((TYPE_META[a.alert_type]||{}).label||a.alert_type)},
    {l:'Priority',    v:esc(a.priority)},
    {l:'Status',      v:esc(a.status)},
    {l:'Read at',     v:fmt(a.read_at)},
    {l:'Created',     v:fmt(a.created_at)},
  ];

  document.getElementById('detailGrid').innerHTML = fields.map(f => `
    <div>
      <div class="text-gray-500 text-xs uppercase tracking-wide mb-0.5">${f.l}</div>
      <div class="font-mono text-xs text-gray-200">${f.v}</div>
    </div>`).join('');

  const msgEl = document.getElementById('detailMessage');
  if (a.message) { msgEl.textContent = a.message; msgEl.classList.remove('hidden'); }
  else msgEl.classList.add('hidden');

  const actEl = document.getElementById('detailActions');
  if (a.action_label || a.action_url) {
    actEl.innerHTML = `CTA: <strong>${esc(a.action_label||'—')}</strong><br>URL: <a href="${esc(a.action_url||'')}" target="_blank" class="underline">${esc(a.action_url||'—')}</a>`;
    actEl.classList.remove('hidden');
  } else actEl.classList.add('hidden');

  document.getElementById('detail-alert-id').value     = a.id;
  document.getElementById('detail-status-select').value = a.status;

  openModal('detailModal');
}

// ── Delete modal ──────────────────────────────────────────
function openDeleteModal(id, title) {
  document.getElementById('del-id').value       = id;
  document.getElementById('del-title').textContent = title;
  openModal('deleteModal');
}

// ── Bulk checkboxes ───────────────────────────────────────
const bulkBar   = document.getElementById('bulkBar');
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

function bulkAction(action) {
  const ids = [...document.querySelectorAll('.row-chk:checked')].map(c => c.value);
  if (!ids.length) return;
  const labels = { bulk_delete:'Delete', bulk_read:'Mark as read' };
  if (!confirm(`${labels[action]} ${ids.length} alert(s)?`)) return;

  const form = document.getElementById('bulkForm');
  let ai = form.querySelector('input[name="action"]');
  if (!ai) { ai = document.createElement('input'); ai.type='hidden'; ai.name='action'; form.appendChild(ai); }
  ai.value = action;
  ids.forEach(id => {
    const inp = document.createElement('input');
    inp.type='hidden'; inp.name='selected_ids[]'; inp.value=id;
    form.appendChild(inp);
  });
  form.submit();
}

// ── Toast ─────────────────────────────────────────────────
function showToast(msg, type='ok') {
  const t    = document.getElementById('toast');
  const icon = document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  const c = {ok:'#10B981',warn:'#F59E0B',err:'#EF4444'};
  const i = {ok:'fa-check-circle',warn:'fa-exclamation-triangle',err:'fa-times-circle'};
  icon.className = 'fas '+(i[type]||'fa-info-circle');
  icon.style.color = c[type]||'#10B981';
  t.style.transform='translateY(0)'; t.style.opacity='1';
  clearTimeout(t._t);
  t._t = setTimeout(()=>{t.style.transform='translateY(20px)';t.style.opacity='0';}, 4200);
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