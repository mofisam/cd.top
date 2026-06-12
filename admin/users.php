<?php
require_once 'auth_check.php';
$adminUser = checkAdminAuth();
require_once '../config/database.php';

$conn = getDBConnection();
$activePage = 'users';

// ── Handle POST actions ─────────────────────────────────────
$flash = null;

// Suspend / Activate user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['user_id'])) {
    $uid    = (int)$_POST['user_id'];
    $action = $_POST['action'];

    switch ($action) {

        case 'suspend':
            $stmt = $conn->prepare("UPDATE users SET status='suspended' WHERE id=?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $stmt->close();
            logAdminActivity($adminUser['id'], 'SUSPEND_USER', "Suspended user ID: $uid");
            $flash = ['type'=>'warn','msg'=>"User #$uid suspended."];
            break;

        case 'activate':
            $stmt = $conn->prepare("UPDATE users SET status='active' WHERE id=?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $stmt->close();
            logAdminActivity($adminUser['id'], 'ACTIVATE_USER', "Activated user ID: $uid");
            $flash = ['type'=>'ok','msg'=>"User #$uid reactivated."];
            break;

        case 'verify_email':
            $stmt = $conn->prepare("UPDATE users SET email_verified=1 WHERE id=?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $stmt->close();
            logAdminActivity($adminUser['id'], 'VERIFY_EMAIL', "Manually verified email for user ID: $uid");
            $flash = ['type'=>'ok','msg'=>"Email verified for user #$uid."];
            break;

        case 'change_plan':
            $newPlan = in_array($_POST['plan'] ?? '', ['free','pro','elite']) ? $_POST['plan'] : null;
            if ($newPlan) {
                $credits = match($newPlan) { 'pro' => 100, 'elite' => 500, default => 10 };
                $stmt = $conn->prepare("UPDATE users SET plan=?, credits=? WHERE id=?");
                $stmt->bind_param("sii", $newPlan, $credits, $uid);
                $stmt->execute();
                $stmt->close();
                logAdminActivity($adminUser['id'], 'CHANGE_PLAN', "Changed plan to {$newPlan} for user ID: $uid");
                $flash = ['type'=>'ok','msg'=>"Plan changed to ".ucfirst($newPlan)." for user #$uid."];
            }
            break;

        case 'add_credits':
            $amount = max(1, min(10000, (int)($_POST['credits'] ?? 0)));
            $note   = substr(strip_tags($_POST['note'] ?? 'Admin grant'), 0, 255);
            $stmt = $conn->prepare("UPDATE users SET credits = credits + ? WHERE id=?");
            $stmt->bind_param("ii", $amount, $uid);
            $stmt->execute();
            $stmt->close();
            // Ledger entry
            $balStmt = $conn->prepare("SELECT credits FROM users WHERE id=?");
            $balStmt->bind_param("i", $uid); $balStmt->execute();
            $bal = (int)($balStmt->get_result()->fetch_assoc()['credits'] ?? 0);
            $balStmt->close();
            $ledger = $conn->prepare("INSERT INTO credit_ledger (user_id, delta, balance_after, type, admin_user_id, note) VALUES (?,?,?,'manual_grant',?,?)");
            if ($ledger) {
                $ledger->bind_param("iiiis", $uid, $amount, $bal, $adminUser['id'], $note);
                $ledger->execute(); $ledger->close();
            }
            logAdminActivity($adminUser['id'], 'ADD_CREDITS', "Added {$amount} credits to user ID: $uid. Note: $note");
            $flash = ['type'=>'ok','msg'=>"{$amount} credits added to user #$uid."];
            break;

        case 'reset_password':
            $newPw = bin2hex(random_bytes(8)); // 16-char temp password
            $hash  = password_hash($newPw, PASSWORD_DEFAULT);
            $stmt  = $conn->prepare("UPDATE users SET password_hash=?, reset_token=NULL, reset_token_expires=NULL WHERE id=?");
            $stmt->bind_param("si", $hash, $uid);
            $stmt->execute();
            $stmt->close();
            logAdminActivity($adminUser['id'], 'RESET_PASSWORD', "Reset password for user ID: $uid");
            $flash = ['type'=>'ok','msg'=>"Temp password for user #$uid: <strong class='font-mono'>{$newPw}</strong> — share securely."];
            break;

        case 'delete':
            $confirmEmail = trim($_POST['confirm_email'] ?? '');
            $emailStmt = $conn->prepare("SELECT email FROM users WHERE id=?");
            $emailStmt->bind_param("i", $uid); $emailStmt->execute();
            $realEmail = $emailStmt->get_result()->fetch_assoc()['email'] ?? '';
            $emailStmt->close();
            if ($confirmEmail === $realEmail) {
                $anon = 'deleted_'.$uid.'_'.time().'@deleted.invalid';
                $del  = $conn->prepare("UPDATE users SET email=?, full_name='Deleted User', password_hash='', status='deleted', email_verified=0 WHERE id=?");
                $del->bind_param("si", $anon, $uid);
                $del->execute(); $del->close();
                logAdminActivity($adminUser['id'], 'DELETE_USER', "Deleted user ID: $uid ($realEmail)");
                $flash = ['type'=>'ok','msg'=>"User #$uid deleted."];
            } else {
                $flash = ['type'=>'err','msg'=>"Email confirmation did not match. User not deleted."];
            }
            break;

        case 'bulk_suspend':
            $ids = array_map('intval', (array)($_POST['selected_ids'] ?? []));
            if ($ids) {
                $ph = implode(',', $ids);
                $conn->query("UPDATE users SET status='suspended' WHERE id IN ($ph)");
                logAdminActivity($adminUser['id'], 'BULK_SUSPEND', "Bulk suspended ".count($ids)." users");
                $flash = ['type'=>'warn','msg'=>count($ids)." user(s) suspended."];
            }
            break;

        case 'bulk_activate':
            $ids = array_map('intval', (array)($_POST['selected_ids'] ?? []));
            if ($ids) {
                $ph = implode(',', $ids);
                $conn->query("UPDATE users SET status='active' WHERE id IN ($ph)");
                logAdminActivity($adminUser['id'], 'BULK_ACTIVATE', "Bulk activated ".count($ids)." users");
                $flash = ['type'=>'ok','msg'=>count($ids)." user(s) activated."];
            }
            break;
    }
}

// ── CSV export ──────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="users_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Email','Full Name','Plan','Credits','Status','Email Verified','Provider','Joined','Last Login','Login Count','Last IP']);
    $rows = $conn->query("SELECT id,email,full_name,plan,credits,status,email_verified,provider,created_at,last_login,login_count,last_ip FROM users WHERE status != 'deleted' ORDER BY created_at DESC");
    while ($r = $rows->fetch_assoc()) fputcsv($out, $r);
    fclose($out);
    $conn->close();
    exit();
}

// ── Filters ─────────────────────────────────────────────────
$search     = trim($_GET['search'] ?? '');
$planFilter = in_array($_GET['plan'] ?? '', ['free','pro','elite','']) ? ($_GET['plan'] ?? '') : '';
$statusFilter = in_array($_GET['status'] ?? '', ['active','suspended','deleted','']) ? ($_GET['status'] ?? '') : '';
$sortCol    = in_array($_GET['sort'] ?? '', ['created_at','last_login','credits','login_count']) ? $_GET['sort'] : 'created_at';
$sortDir    = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$page       = max(1, (int)($_GET['page'] ?? 1));
$limit      = 25;
$offset     = ($page - 1) * $limit;

$where = ["status != 'deleted'"];
$binds = []; $types = '';

if ($search) {
    $like = "%{$search}%";
    $where[] = "(email LIKE ? OR full_name LIKE ?)";
    $binds[] = $like; $binds[] = $like; $types .= 'ss';
}
if ($planFilter)   { $where[] = "plan = ?";   $binds[] = $planFilter;   $types .= 's'; }
if ($statusFilter) { $where[] = "status = ?"; $binds[] = $statusFilter; $types .= 's'; }

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// Total count
$countStmt = $conn->prepare("SELECT COUNT(*) as c FROM users $whereSQL");
if ($types) { $countStmt->bind_param($types, ...$binds); }
$countStmt->execute();
$totalRows  = (int)$countStmt->get_result()->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($totalRows / $limit));
$countStmt->close();

// Users
$dataStmt = $conn->prepare("
    SELECT id, email, full_name, plan, credits, status, email_verified,
           provider, created_at, last_login, login_count, last_ip, avatar
    FROM users
    $whereSQL
    ORDER BY $sortCol $sortDir
    LIMIT ? OFFSET ?
");
$allBinds = array_merge($binds, [$limit, $offset]);
$allTypes = $types . 'ii';
$dataStmt->bind_param($allTypes, ...$allBinds);
$dataStmt->execute();
$usersResult = $dataStmt->get_result();
$users = [];
while ($r = $usersResult->fetch_assoc()) $users[] = $r;
$dataStmt->close();

// ── Summary stats ───────────────────────────────────────────
$safe = fn($q) => (int)($conn->query($q)?->fetch_assoc()['c'] ?? 0);
$statTotal    = $safe("SELECT COUNT(*) as c FROM users WHERE status != 'deleted'");
$statFree     = $safe("SELECT COUNT(*) as c FROM users WHERE plan='free' AND status='active'");
$statPro      = $safe("SELECT COUNT(*) as c FROM users WHERE plan='pro'  AND status='active'");
$statElite    = $safe("SELECT COUNT(*) as c FROM users WHERE plan='elite' AND status='active'");
$statNew7     = $safe("SELECT COUNT(*) as c FROM users WHERE created_at >= NOW() - INTERVAL 7 DAY AND status != 'deleted'");
$statSuspended= $safe("SELECT COUNT(*) as c FROM users WHERE status='suspended'");

$conn->close();

// ── Build pagination URL helper ──────────────────────────────
function pageUrl(int $p, array $extra = []): string {
    $params = array_merge($_GET, $extra, ['page' => $p]);
    unset($params['export']);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== ''));
}
function sortUrl(string $col): string {
    $dir = ($_GET['sort'] ?? '') === $col && ($_GET['dir'] ?? 'desc') === 'asc' ? 'desc' : 'asc';
    return pageUrl(1, ['sort' => $col, 'dir' => $dir]);
}
function sortIcon(string $col): string {
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
<title>Users — CheckDomain Admin</title>
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

/* Table */
.tbl-row{transition:background .15s}
.tbl-row:hover{background:rgba(59,130,246,.06)!important}

/* Modals */
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:100;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s}
.modal-backdrop.open{opacity:1;pointer-events:all}
.modal-box{background:#1E293B;border:1px solid rgba(59,130,246,.3);border-radius:1rem;padding:1.75rem;max-width:420px;width:90%;transform:scale(.96);transition:transform .2s;max-height:90vh;overflow-y:auto}
.modal-backdrop.open .modal-box{transform:scale(1)}

/* Flash */
.flash-ok  {background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#34D399}
.flash-warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:#FCD34D}
.flash-err {background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3); color:#FCA5A5}

/* Checkbox */
.chk{width:16px;height:16px;cursor:pointer;accent-color:#3B82F6}

/* Badges */
.badge{display:inline-flex;align-items:center;gap:4px;font-size:.7rem;font-weight:600;padding:2px 8px;border-radius:9999px}
.badge-free   {background:rgba(100,116,139,.2);color:#94A3B8}
.badge-pro    {background:rgba(16,185,129,.15);color:#34D399}
.badge-elite  {background:rgba(245,200,66,.1); color:#FCD34D}
.badge-active {background:rgba(16,185,129,.15);color:#34D399}
.badge-suspended{background:rgba(245,158,11,.15);color:#FCD34D}
.badge-deleted{background:rgba(239,68,68,.12); color:#FCA5A5}
.badge-verified{background:rgba(59,130,246,.15);color:#93C5FD}
.badge-unverified{background:rgba(239,68,68,.12);color:#FCA5A5}

/* Provider icon */
.prov-google  {color:#EA4335}
.prov-github  {color:#E2E8F0}
.prov-facebook{color:#1877F2}
.prov-local   {color:#6B7280}

/* Credit bar */
.credit-bar{height:3px;border-radius:2px;background:rgba(255,255,255,.06);overflow:hidden}
.credit-fill{height:100%;border-radius:2px;transition:width .4s}

/* Scrollbar */
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:rgba(15,23,42,.5);border-radius:10px}
::-webkit-scrollbar-thumb{background:#3B82F6;border-radius:10px}
::-webkit-scrollbar-thumb:hover{background:#60A5FA}

/* Input focus ring */
.inp{background:rgba(51,65,85,.8);border:1px solid rgba(71,85,105,1);border-radius:.5rem;padding:.5rem .75rem;color:#fff;width:100%;font-size:.875rem;transition:border-color .2s;outline:none}
.inp:focus{border-color:#3B82F6}
.inp::placeholder{color:#64748B}

.btn-primary  {background:#2563EB;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-primary:hover{background:#1D4ED8}
.btn-secondary{background:#334155;color:#CBD5E1;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-secondary:hover{background:#475569}
.btn-danger   {background:#DC2626;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-danger:hover{background:#B91C1C}
.btn-sm{padding:.3rem .75rem!important;font-size:.75rem!important}

@media(max-width:768px){
  .main-content{margin-left:0!important}
  .hide-mobile{display:none!important}
  .p-8{padding:1rem}
}
@media(max-width:480px){
  .hide-sm{display:none!important}
}
</style>
</head>
<body class="text-white">

<?php include_once 'includes/sidebar.php'; ?>

<div class="main-content" style="margin-left:16rem;">
<div class="p-4 md:p-8">

  <!-- ── Flash message ──────────────────────────────────── -->
  <?php if ($flash): ?>
  <div class="flash-<?= $flash['type'] ?> rounded-xl px-4 py-3 mb-6 flex items-start gap-3 text-sm">
    <i class="fas <?= $flash['type']==='ok' ? 'fa-check-circle' : ($flash['type']==='warn' ? 'fa-exclamation-triangle' : 'fa-times-circle') ?> mt-0.5 flex-shrink-0"></i>
    <span><?= $flash['msg'] ?></span>
  </div>
  <?php endif; ?>

  <!-- ── Page header ────────────────────────────────────── -->
  <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
    <div>
      <h1 class="text-2xl md:text-3xl font-bold">User Management</h1>
      <p class="text-gray-400 text-sm mt-1">
        <?= number_format($totalRows) ?> user<?= $totalRows!==1?'s':'' ?>
        <?php if ($search || $planFilter || $statusFilter): ?>
        <span class="text-blue-400">(filtered)</span>
        <?php endif; ?>
      </p>
    </div>
    <div class="flex flex-wrap gap-3">
      <a href="?export=1&<?= http_build_query(array_filter(['search'=>$search,'plan'=>$planFilter,'status'=>$statusFilter])) ?>"
         class="btn-secondary flex items-center gap-2">
        <i class="fas fa-download text-xs"></i> Export CSV
      </a>
      <button onclick="openModal('addCreditsModal')" class="btn-primary flex items-center gap-2">
        <i class="fas fa-bolt text-xs"></i> Bulk add credits
      </button>
    </div>
  </div>

  <!-- ── Stat cards ──────────────────────────────────────── -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
    <?php
    $statCards = [
      ['label'=>'Total users',  'val'=>$statTotal,     'icon'=>'fa-users',         'color'=>'blue'],
      ['label'=>'Free plan',    'val'=>$statFree,      'icon'=>'fa-user',           'color'=>'gray'],
      ['label'=>'Pro plan',     'val'=>$statPro,       'icon'=>'fa-bolt',           'color'=>'green'],
      ['label'=>'Elite plan',   'val'=>$statElite,     'icon'=>'fa-crown',          'color'=>'yellow'],
      ['label'=>'New (7d)',      'val'=>$statNew7,      'icon'=>'fa-user-plus',      'color'=>'purple'],
      ['label'=>'Suspended',    'val'=>$statSuspended, 'icon'=>'fa-ban',            'color'=>'red'],
    ];
    $colorMap = [
      'blue'  =>['bg'=>'bg-blue-500/20',  'text'=>'text-blue-400'],
      'gray'  =>['bg'=>'bg-slate-500/20', 'text'=>'text-slate-400'],
      'green' =>['bg'=>'bg-green-500/20', 'text'=>'text-green-400'],
      'yellow'=>['bg'=>'bg-yellow-500/20','text'=>'text-yellow-400'],
      'purple'=>['bg'=>'bg-purple-500/20','text'=>'text-purple-400'],
      'red'   =>['bg'=>'bg-red-500/20',   'text'=>'text-red-400'],
    ];
    foreach ($statCards as $sc):
      $c = $colorMap[$sc['color']];
    ?>
    <div class="stat-card rounded-xl p-4">
      <div class="flex justify-between items-start">
        <div>
          <p class="text-gray-400 text-xs"><?= $sc['label'] ?></p>
          <p class="text-xl font-bold mt-1 <?= $c['text'] ?>"><?= number_format($sc['val']) ?></p>
        </div>
        <div class="w-9 h-9 <?= $c['bg'] ?> rounded-full flex items-center justify-center">
          <i class="fas <?= $sc['icon'] ?> <?= $c['text'] ?> text-sm"></i>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Filters ─────────────────────────────────────────── -->
  <div class="bg-slate-800/50 rounded-xl p-4 mb-5">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
      <!-- Search -->
      <div class="flex-1 min-w-48">
        <label class="text-xs text-gray-400 mb-1 block">Search</label>
        <div class="relative">
          <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
          <input class="inp pl-8" type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                 placeholder="Email or name…" autocomplete="off">
        </div>
      </div>
      <!-- Plan -->
      <div class="w-36">
        <label class="text-xs text-gray-400 mb-1 block">Plan</label>
        <select class="inp" name="plan">
          <option value="">All plans</option>
          <?php foreach (['free','pro','elite'] as $p): ?>
          <option value="<?= $p ?>" <?= $planFilter===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <!-- Status -->
      <div class="w-36">
        <label class="text-xs text-gray-400 mb-1 block">Status</label>
        <select class="inp" name="status">
          <option value="">All statuses</option>
          <?php foreach (['active','suspended','deleted'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <!-- Sort -->
      <div class="w-40">
        <label class="text-xs text-gray-400 mb-1 block">Sort by</label>
        <select class="inp" name="sort" onchange="this.form.submit()">
          <?php foreach (['created_at'=>'Joined','last_login'=>'Last login','credits'=>'Credits','login_count'=>'Logins'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= $sortCol===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <input type="hidden" name="dir" value="<?= $sortDir==='ASC'?'asc':'desc' ?>">
      <!-- Buttons -->
      <button type="submit" class="btn-primary flex items-center gap-2">
        <i class="fas fa-filter text-xs"></i> Filter
      </button>
      <?php if ($search || $planFilter || $statusFilter): ?>
      <a href="users.php" class="btn-secondary flex items-center gap-2">
        <i class="fas fa-times text-xs"></i> Clear
      </a>
      <?php endif; ?>
    </form>
  </div>

  <!-- ── Bulk action bar ─────────────────────────────────── -->
  <div id="bulkBar" class="hidden bg-blue-900/30 border border-blue-500/30 rounded-xl px-4 py-3 mb-4 flex items-center gap-3 text-sm flex-wrap">
    <span id="bulkCount" class="font-mono text-blue-300">0 selected</span>
    <span class="text-gray-600">·</span>
    <button onclick="bulkAction('bulk_activate')" class="btn-secondary btn-sm flex items-center gap-1">
      <i class="fas fa-check text-green-400 text-xs"></i> Activate
    </button>
    <button onclick="bulkAction('bulk_suspend')" class="btn-secondary btn-sm flex items-center gap-1">
      <i class="fas fa-ban text-yellow-400 text-xs"></i> Suspend
    </button>
    <button onclick="clearSelection()" class="text-gray-500 hover:text-gray-300 text-xs ml-auto">
      Deselect all
    </button>
  </div>

  <!-- ── Users table ─────────────────────────────────────── -->
  <div class="bg-slate-800/50 rounded-xl overflow-hidden">
    <?php if (!empty($users)): ?>
    <form method="POST" id="bulkForm">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-700/50 border-b border-gray-700 text-xs uppercase tracking-wide text-gray-400">
          <tr>
            <th class="p-4 w-10">
              <input type="checkbox" id="selectAll" class="chk">
            </th>
            <th class="p-4 text-left">
              <a href="<?= sortUrl('created_at') ?>" class="hover:text-white transition flex items-center">
                User <?= sortIcon('created_at') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-mobile">Plan</th>
            <th class="p-4 text-left hide-mobile">
              <a href="<?= sortUrl('credits') ?>" class="hover:text-white transition flex items-center">
                Credits <?= sortIcon('credits') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-mobile">Status</th>
            <th class="p-4 text-left hide-sm">
              <a href="<?= sortUrl('last_login') ?>" class="hover:text-white transition flex items-center">
                Last login <?= sortIcon('last_login') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-sm">
              <a href="<?= sortUrl('login_count') ?>" class="hover:text-white transition flex items-center">
                Logins <?= sortIcon('login_count') ?>
              </a>
            </th>
            <th class="p-4 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-700/50">
          <?php foreach ($users as $u):
            $initials = strtoupper(substr($u['full_name'] ?: $u['email'], 0, 1) . (strpos($u['full_name']??'', ' ') !== false ? substr($u['full_name'], strpos($u['full_name'],' ')+1, 1) : ''));
            $provider  = $u['provider'] ?: 'local';
            $provIcon  = match($provider) { 'google'=>'fab fa-google prov-google', 'github'=>'fab fa-github prov-github', 'facebook'=>'fab fa-facebook prov-facebook', default=>'fas fa-envelope prov-local' };
            $planBadge = 'badge-'.($u['plan'] ?: 'free');
            $statusBadge = 'badge-'.($u['status'] ?: 'active');
            $verBadge  = $u['email_verified'] ? 'badge-verified' : 'badge-unverified';
            $planMax   = match($u['plan'] ?? 'free') { 'pro'=>100, 'elite'=>500, default=>10 };
            $creditPct = min(100, round((($u['credits'] ?? 0) / $planMax) * 100));
            $creditColor = $creditPct > 50 ? '#10B981' : ($creditPct > 20 ? '#F59E0B' : '#EF4444');
            $joinedTs  = strtotime($u['created_at']);
            $loginTs   = $u['last_login'] ? strtotime($u['last_login']) : null;
          ?>
          <tr class="tbl-row">
            <td class="p-4">
              <input type="checkbox" name="selected_ids[]" value="<?= (int)$u['id'] ?>"
                     class="chk row-chk" onchange="onCheckChange()">
            </td>

            <!-- User cell -->
            <td class="p-4">
              <div class="flex items-center gap-3">
                <!-- Avatar -->
                <div class="w-9 h-9 rounded-full flex-shrink-0 flex items-center justify-center font-bold text-xs"
                     style="background:linear-gradient(135deg,#2563EB,#06B6D4)">
                  <?php if ($u['avatar']): ?>
                  <img src="<?= htmlspecialchars($u['avatar']) ?>" class="w-9 h-9 rounded-full object-cover" alt="">
                  <?php else: ?>
                  <?= htmlspecialchars($initials) ?>
                  <?php endif; ?>
                </div>
                <div class="min-w-0">
                  <div class="font-medium text-white truncate max-w-48" title="<?= htmlspecialchars($u['email']) ?>">
                    <?= htmlspecialchars($u['full_name'] ?: '—') ?>
                  </div>
                  <div class="text-gray-400 text-xs truncate max-w-48 flex items-center gap-1.5">
                    <i class="<?= $provIcon ?> text-xs"></i>
                    <?= htmlspecialchars($u['email']) ?>
                  </div>
                  <div class="mt-1">
                    <span class="badge <?= $verBadge ?>">
                      <i class="fas <?= $u['email_verified'] ? 'fa-check' : 'fa-times' ?> text-xs"></i>
                      <?= $u['email_verified'] ? 'Verified' : 'Unverified' ?>
                    </span>
                  </div>
                </div>
              </div>
            </td>

            <!-- Plan -->
            <td class="p-4 hide-mobile">
              <span class="badge <?= $planBadge ?>">
                <i class="fas <?= match($u['plan']??'free') { 'pro'=>'fa-bolt', 'elite'=>'fa-crown', default=>'fa-user' } ?> text-xs"></i>
                <?= ucfirst($u['plan'] ?? 'free') ?>
              </span>
            </td>

            <!-- Credits -->
            <td class="p-4 hide-mobile" style="min-width:110px;">
              <div class="flex items-center justify-between mb-1">
                <span class="font-mono text-xs text-white"><?= number_format((int)$u['credits']) ?></span>
                <span class="text-xs text-gray-500">/ <?= $planMax ?></span>
              </div>
              <div class="credit-bar">
                <div class="credit-fill" style="width:<?= $creditPct ?>%;background:<?= $creditColor ?>"></div>
              </div>
            </td>

            <!-- Status -->
            <td class="p-4 hide-mobile">
              <span class="badge <?= $statusBadge ?>">
                <?= ucfirst($u['status'] ?? 'active') ?>
              </span>
            </td>

            <!-- Last login -->
            <td class="p-4 hide-sm">
              <?php if ($loginTs): ?>
              <div class="text-xs text-gray-300"><?= date('M j, Y', $loginTs) ?></div>
              <div class="text-xs text-gray-500"><?= date('H:i', $loginTs) ?></div>
              <?php else: ?>
              <span class="text-gray-600 text-xs">Never</span>
              <?php endif; ?>
            </td>

            <!-- Login count -->
            <td class="p-4 hide-sm">
              <span class="font-mono text-sm <?= (int)$u['login_count'] > 100 ? 'text-blue-400' : 'text-gray-400' ?>">
                <?= number_format((int)$u['login_count']) ?>
              </span>
            </td>

            <!-- Actions -->
            <td class="p-4 text-right">
              <div class="flex items-center justify-end gap-1.5 flex-wrap">
                <!-- View / edit -->
                <button type="button" onclick="openUserModal(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)"
                        class="w-8 h-8 bg-blue-500/20 hover:bg-blue-500/30 rounded-lg flex items-center justify-center text-blue-400 transition"
                        title="View user">
                  <i class="fas fa-eye text-xs"></i>
                </button>
                <!-- Toggle status -->
                <?php if ($u['status'] === 'active'): ?>
                <button type="button" onclick="confirmAction('suspend', <?= (int)$u['id'] ?>, '<?= htmlspecialchars($u['email'], ENT_QUOTES) ?>')"
                        class="w-8 h-8 bg-yellow-500/20 hover:bg-yellow-500/30 rounded-lg flex items-center justify-center text-yellow-400 transition"
                        title="Suspend">
                  <i class="fas fa-ban text-xs"></i>
                </button>
                <?php elseif ($u['status'] === 'suspended'): ?>
                <button type="button" onclick="confirmAction('activate', <?= (int)$u['id'] ?>, '<?= htmlspecialchars($u['email'], ENT_QUOTES) ?>')"
                        class="w-8 h-8 bg-green-500/20 hover:bg-green-500/30 rounded-lg flex items-center justify-center text-green-400 transition"
                        title="Activate">
                  <i class="fas fa-check text-xs"></i>
                </button>
                <?php endif; ?>
                <!-- Credits -->
                <button type="button" onclick="openCreditModal(<?= (int)$u['id'] ?>, '<?= htmlspecialchars($u['email'], ENT_QUOTES) ?>')"
                        class="w-8 h-8 bg-amber-500/20 hover:bg-amber-500/30 rounded-lg flex items-center justify-center text-amber-400 transition"
                        title="Add credits">
                  <i class="fas fa-bolt text-xs"></i>
                </button>
                <!-- Delete -->
                <button onclick="openDeleteModal(<?= (int)$u['id'] ?>, '<?= htmlspecialchars($u['email'], ENT_QUOTES) ?>')"
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

    <!-- Pagination + bulk footer -->
    <div class="flex flex-col sm:flex-row justify-between items-center gap-4 p-4 bg-slate-700/30 border-t border-gray-700">
      <div class="text-xs text-gray-400">
        Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $limit, $totalRows)) ?> of <?= number_format($totalRows) ?>
      </div>
      <?php if ($totalPages > 1): ?>
      <div class="flex flex-wrap justify-center gap-1.5">
        <?php if ($page > 1): ?>
        <a href="<?= pageUrl($page-1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs">
          <i class="fas fa-chevron-left"></i>
        </a>
        <?php endif; ?>
        <?php
        $s = max(1, $page-2); $e = min($totalPages, $page+2);
        if ($s > 1) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>';
        for ($i = $s; $i <= $e; $i++):
        ?>
        <a href="<?= pageUrl($i) ?>" class="px-3 py-1.5 rounded text-xs transition <?= $i===$page ? 'bg-blue-600' : 'bg-slate-700 hover:bg-slate-600' ?>">
          <?= $i ?>
        </a>
        <?php endfor;
        if ($e < $totalPages) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>'; ?>
        <?php if ($page < $totalPages): ?>
        <a href="<?= pageUrl($page+1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs">
          <i class="fas fa-chevron-right"></i>
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    </form><!-- #bulkForm -->

    <?php else: ?>
    <div class="text-center py-16">
      <i class="fas fa-users text-5xl text-gray-700 mb-4"></i>
      <p class="text-gray-400">No users found</p>
      <?php if ($search || $planFilter || $statusFilter): ?>
      <p class="text-gray-500 text-sm mt-2">Try adjusting your filters</p>
      <a href="users.php" class="inline-block mt-4 text-blue-400 hover:text-blue-300 text-sm transition">Clear filters</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div><!-- /table wrap -->

</div><!-- /content padding -->
</div><!-- /main-content -->


<!-- ═══════════════════════════════════
     MODALS
═══════════════════════════════════ -->

<!-- ── View user modal ────────────────────────────── -->
<div class="modal-backdrop" id="userModal">
  <div class="modal-box" style="max-width:520px;">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold">User profile</h2>
      <button onclick="closeModal('userModal')" class="text-gray-400 hover:text-white transition">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <!-- Avatar + name row -->
    <div class="flex items-center gap-4 mb-5 pb-5 border-b border-gray-700">
      <div id="um-avatar" class="w-14 h-14 rounded-full flex items-center justify-center font-bold text-lg flex-shrink-0"
           style="background:linear-gradient(135deg,#2563EB,#06B6D4)"></div>
      <div>
        <div id="um-name" class="font-bold text-lg"></div>
        <div id="um-email" class="text-gray-400 text-sm font-mono"></div>
        <div class="flex gap-2 mt-1.5" id="um-badges"></div>
      </div>
    </div>

    <!-- Details grid -->
    <div class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm mb-5">
      <?php foreach ([
        ['label'=>'User ID',      'id'=>'um-id'],
        ['label'=>'Provider',     'id'=>'um-provider'],
        ['label'=>'Plan',         'id'=>'um-plan'],
        ['label'=>'Credits',      'id'=>'um-credits'],
        ['label'=>'Login count',  'id'=>'um-logins'],
        ['label'=>'Last IP',      'id'=>'um-ip'],
        ['label'=>'Joined',       'id'=>'um-joined'],
        ['label'=>'Last login',   'id'=>'um-lastlogin'],
      ] as $field): ?>
      <div>
        <div class="text-gray-500 text-xs uppercase tracking-wide mb-0.5"><?= $field['label'] ?></div>
        <div id="<?= $field['id'] ?>" class="font-mono text-sm text-gray-200">—</div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Quick actions inside modal -->
    <div class="grid grid-cols-2 gap-3 border-t border-gray-700 pt-5">
      <form method="POST" id="umPlanForm">
        <input type="hidden" name="action" value="change_plan">
        <input type="hidden" name="user_id" id="um-uid-plan">
        <label class="text-xs text-gray-400 mb-1 block">Change plan</label>
        <div class="flex gap-2">
          <select class="inp" name="plan" id="um-plan-select">
            <option value="free">Free</option>
            <option value="pro">Pro</option>
            <option value="elite">Elite</option>
          </select>
          <button type="submit" class="btn-primary btn-sm whitespace-nowrap">Apply</button>
        </div>
      </form>

      <form method="POST">
        <input type="hidden" name="action" value="verify_email">
        <input type="hidden" name="user_id" id="um-uid-verify">
        <label class="text-xs text-gray-400 mb-1 block">Email verification</label>
        <button type="submit" class="btn-secondary btn-sm w-full flex items-center justify-center gap-1.5">
          <i class="fas fa-envelope-open-text text-xs text-blue-400"></i> Mark verified
        </button>
      </form>

      <form method="POST">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="user_id" id="um-uid-reset">
        <label class="text-xs text-gray-400 mb-1 block">Password</label>
        <button type="submit" onclick="return confirm('Generate a temporary password for this user?')"
                class="btn-secondary btn-sm w-full flex items-center justify-center gap-1.5">
          <i class="fas fa-key text-xs text-yellow-400"></i> Reset password
        </button>
      </form>

      <div>
        <label class="text-xs text-gray-400 mb-1 block">Watchlist / backorders</label>
        <a id="um-watchlist-link" href="#" class="btn-secondary btn-sm w-full flex items-center justify-center gap-1.5 no-underline">
          <i class="fas fa-bookmark text-xs text-purple-400"></i> View records
        </a>
      </div>
    </div>
  </div>
</div>

<!-- ── Credits modal (single user) ──────────────── -->
<div class="modal-backdrop" id="creditModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold">Add credits</h2>
      <button onclick="closeModal('creditModal')" class="text-gray-400 hover:text-white transition">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <p class="text-gray-400 text-sm mb-4">
      Adding credits to <span id="cm-email" class="text-white font-mono"></span>
    </p>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="add_credits">
      <input type="hidden" name="user_id" id="cm-uid">
      <div>
        <label class="text-xs text-gray-400 mb-1 block">Credits to add <span class="text-red-400">*</span></label>
        <input class="inp" type="number" name="credits" min="1" max="10000" placeholder="e.g. 50" required>
      </div>
      <div>
        <label class="text-xs text-gray-400 mb-1 block">Admin note (shown in ledger)</label>
        <input class="inp" type="text" name="note" placeholder="Compensation, promo, etc." maxlength="255">
      </div>
      <div class="flex gap-3 justify-end pt-2">
        <button type="button" onclick="closeModal('creditModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-primary flex items-center gap-2">
          <i class="fas fa-bolt text-xs"></i> Add credits
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── Bulk add credits modal ────────────────────── -->
<div class="modal-backdrop" id="addCreditsModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold">Bulk add credits</h2>
      <button onclick="closeModal('addCreditsModal')" class="text-gray-400 hover:text-white transition">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <p class="text-gray-400 text-sm mb-4">
      Select users in the table first, then click Add credits here — or apply to <strong>all filtered users</strong> below.
    </p>
    <form method="POST" id="bulkCreditForm" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="add_credits">
      <input type="hidden" name="user_id" value="0"><!-- overridden by JS for bulk -->
      <div>
        <label class="text-xs text-gray-400 mb-1 block">Credits per user <span class="text-red-400">*</span></label>
        <input class="inp" type="number" name="credits" min="1" max="10000" placeholder="e.g. 10" required>
      </div>
      <div>
        <label class="text-xs text-gray-400 mb-1 block">Admin note</label>
        <input class="inp" type="text" name="note" placeholder="Promotional grant" maxlength="255">
      </div>
      <div class="bg-yellow-500/10 border border-yellow-500/30 rounded-lg px-3 py-2 text-yellow-300 text-xs">
        <i class="fas fa-exclamation-triangle mr-1"></i>
        This will apply to each selected user individually. Select users in the table first.
      </div>
      <div class="flex gap-3 justify-end pt-2">
        <button type="button" onclick="closeModal('addCreditsModal')" class="btn-secondary">Cancel</button>
        <button type="button" onclick="submitBulkCredits()" class="btn-primary flex items-center gap-2">
          <i class="fas fa-bolt text-xs"></i> Add to selected
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── Confirm action modal ──────────────────────── -->
<div class="modal-backdrop" id="confirmModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold" id="conf-title">Confirm action</h2>
      <button onclick="closeModal('confirmModal')" class="text-gray-400 hover:text-white transition">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <p class="text-gray-300 text-sm mb-5" id="conf-body"></p>
    <form method="POST" class="flex gap-3 justify-end">
      <input type="hidden" name="action" id="conf-action">
      <input type="hidden" name="user_id" id="conf-uid">
      <button type="button" onclick="closeModal('confirmModal')" class="btn-secondary">Cancel</button>
      <button type="submit" id="conf-btn" class="btn-danger">Confirm</button>
    </form>
  </div>
</div>

<!-- ── Delete user modal ─────────────────────────── -->
<div class="modal-backdrop" id="deleteModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold text-red-400">
        <i class="fas fa-exclamation-triangle mr-2"></i>Delete user
      </h2>
      <button onclick="closeModal('deleteModal')" class="text-gray-400 hover:text-white transition">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <p class="text-gray-300 text-sm mb-2">
      This will <strong>permanently anonymise</strong> user <span id="del-email" class="font-mono text-white"></span>.
      All personal data will be erased. This cannot be undone.
    </p>
    <p class="text-gray-400 text-xs mb-5">
      Type the user's email address to confirm:
    </p>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="user_id" id="del-uid">
      <input class="inp border-red-700 focus:border-red-500" type="email"
             name="confirm_email" id="del-confirm"
             placeholder="Type email to confirm" required autocomplete="off">
      <div class="flex gap-3 justify-end">
        <button type="button" onclick="closeModal('deleteModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-danger flex items-center gap-2">
          <i class="fas fa-trash text-xs"></i> Delete permanently
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── Toast ─────────────────────────────────────── -->
<div id="toast"
     style="position:fixed;bottom:24px;right:24px;z-index:999;
            background:#1E293B;border:1px solid rgba(59,130,246,.3);
            border-radius:10px;padding:12px 18px;font-size:13px;color:#E2E8F0;
            box-shadow:0 8px 32px rgba(0,0,0,.5);
            transform:translateY(20px);opacity:0;
            transition:all .3s ease;
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

// ── Open user detail modal ────────────────────────────────
function openUserModal(u) {
  const fmt = ts => ts ? new Date(ts).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) + ' ' + new Date(ts).toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'}) : '—';

  const initials = (u.full_name || u.email || '?').substring(0,1).toUpperCase() +
                   ((u.full_name||'').indexOf(' ')!==-1 ? (u.full_name||'').split(' ')[1].substring(0,1).toUpperCase() : '');

  const av = document.getElementById('um-avatar');
  if (u.avatar) {
    av.innerHTML = `<img src="${esc(u.avatar)}" class="w-14 h-14 rounded-full object-cover">`;
  } else {
    av.textContent = initials;
  }

  document.getElementById('um-name').textContent      = u.full_name || '—';
  document.getElementById('um-email').textContent     = u.email;
  document.getElementById('um-id').textContent        = '#' + u.id;
  document.getElementById('um-provider').textContent  = u.provider || 'email';
  document.getElementById('um-plan').textContent      = (u.plan || 'free').toUpperCase();
  document.getElementById('um-credits').textContent   = (u.credits || 0) + ' credits';
  document.getElementById('um-logins').textContent    = (u.login_count || 0).toLocaleString();
  document.getElementById('um-ip').textContent        = u.last_ip || '—';
  document.getElementById('um-joined').textContent    = fmt(u.created_at);
  document.getElementById('um-lastlogin').textContent = fmt(u.last_login);

  // Badges
  const badgeArea = document.getElementById('um-badges');
  badgeArea.innerHTML = `
    <span class="badge badge-${u.plan||'free'}">${(u.plan||'free').toUpperCase()}</span>
    <span class="badge badge-${u.status||'active'}">${u.status||'active'}</span>
    <span class="badge badge-${u.email_verified?'verified':'unverified'}">${u.email_verified?'Verified':'Unverified'}</span>
  `;

  // Populate hidden inputs
  document.getElementById('um-uid-plan').value   = u.id;
  document.getElementById('um-uid-verify').value = u.id;
  document.getElementById('um-uid-reset').value  = u.id;
  document.getElementById('um-plan-select').value = u.plan || 'free';
  document.getElementById('um-watchlist-link').href = 'watchlist.php?user_id=' + u.id;

  openModal('userModal');
}

// ── Credits modal ─────────────────────────────────────────
function openCreditModal(uid, email) {
  document.getElementById('cm-uid').value   = uid;
  document.getElementById('cm-email').textContent = email;
  openModal('creditModal');
}

// ── Confirm action (suspend / activate) ──────────────────
function confirmAction(action, uid, email) {
  const titles = { suspend: 'Suspend user', activate: 'Activate user' };
  const bodies = {
    suspend: `Suspend <strong class="font-mono text-white">${esc(email)}</strong>? They won't be able to log in until reactivated.`,
    activate: `Reactivate <strong class="font-mono text-white">${esc(email)}</strong>? They'll regain full account access.`,
  };
  const btnClasses = { suspend: 'btn-danger', activate: 'btn-primary' };

  document.getElementById('conf-title').textContent = titles[action] || 'Confirm';
  document.getElementById('conf-body').innerHTML    = bodies[action] || '';
  document.getElementById('conf-action').value      = action;
  document.getElementById('conf-uid').value         = uid;

  const btn = document.getElementById('conf-btn');
  btn.className = btnClasses[action] || 'btn-danger';
  btn.textContent = action === 'activate' ? 'Activate' : 'Suspend';

  openModal('confirmModal');
}

// ── Delete modal ──────────────────────────────────────────
function openDeleteModal(uid, email) {
  document.getElementById('del-uid').value      = uid;
  document.getElementById('del-email').textContent = email;
  document.getElementById('del-confirm').value  = '';
  openModal('deleteModal');
}

// ── Checkboxes / bulk bar ─────────────────────────────────
const bulkBar   = document.getElementById('bulkBar');
const bulkCount = document.getElementById('bulkCount');

function onCheckChange() {
  const checked = document.querySelectorAll('.row-chk:checked');
  const all     = document.querySelectorAll('.row-chk');
  const selectAll = document.getElementById('selectAll');

  if (selectAll) selectAll.checked = checked.length === all.length && all.length > 0;
  bulkCount.textContent = checked.length + ' selected';
  bulkBar.classList.toggle('hidden', checked.length === 0);
}

const selectAll = document.getElementById('selectAll');
if (selectAll) {
  selectAll.addEventListener('change', () => {
    document.querySelectorAll('.row-chk').forEach(c => c.checked = selectAll.checked);
    onCheckChange();
  });
}

function clearSelection() {
  document.querySelectorAll('.row-chk').forEach(c => c.checked = false);
  const sa = document.getElementById('selectAll');
  if (sa) sa.checked = false;
  onCheckChange();
}

function bulkAction(action) {
  const ids = [...document.querySelectorAll('.row-chk:checked')].map(c => c.value);
  if (!ids.length) return;
  const labels = { bulk_suspend: 'suspend', bulk_activate: 'activate' };
  if (!confirm(`${labels[action]?.charAt(0).toUpperCase()+labels[action]?.slice(1)} ${ids.length} user(s)?`)) return;

  const form   = document.getElementById('bulkForm');
  let   actIn  = form.querySelector('input[name="action"]');
  if (!actIn) { actIn = document.createElement('input'); actIn.type = 'hidden'; actIn.name = 'action'; form.appendChild(actIn); }
  actIn.value  = action;

  // Add hidden inputs for selected ids
  ids.forEach(id => {
    const inp = document.createElement('input');
    inp.type  = 'hidden'; inp.name = 'selected_ids[]'; inp.value = id;
    form.appendChild(inp);
  });
  form.submit();
}

function submitBulkCredits() {
  const ids = [...document.querySelectorAll('.row-chk:checked')].map(c => c.value);
  if (!ids.length) { showToast('Select users in the table first.', 'warn'); closeModal('addCreditsModal'); return; }
  const creditsVal = document.querySelector('#bulkCreditForm input[name="credits"]').value;
  const noteVal    = document.querySelector('#bulkCreditForm input[name="note"]').value;
  if (!creditsVal || parseInt(creditsVal) < 1) { showToast('Enter a valid credit amount.', 'err'); return; }

  // Submit one form per user (sequentially via hidden forms)
  let idx = 0;
  function next() {
    if (idx >= ids.length) { location.reload(); return; }
    const f = document.createElement('form');
    f.method = 'POST';
    f.style.display = 'none';
    f.innerHTML = `<input name="action" value="add_credits"><input name="user_id" value="${ids[idx]}"><input name="credits" value="${creditsVal}"><input name="note" value="${noteVal}">`;
    document.body.appendChild(f);
    idx++;
    if (idx < ids.length) { f.submit(); } // Last one handled by reload above
    else {
      f.addEventListener('submit', () => setTimeout(() => location.reload(), 300));
      f.submit();
    }
  }
  // Simplified: submit as one bulk call via a single form with all ids
  const bf = document.createElement('form');
  bf.method = 'POST';
  bf.style.display = 'none';
  bf.innerHTML = `<input name="action" value="add_credits_bulk">
    <input name="credits" value="${creditsVal}">
    <input name="note" value="${noteVal}">
    ${ids.map(id => `<input name="selected_ids[]" value="${id}">`).join('')}`;
  document.body.appendChild(bf);

  // Since we handle add_credits one at a time, just reload to let PHP handle each
  closeModal('addCreditsModal');
  showToast(`Applying ${creditsVal} credits to ${ids.length} users…`, 'ok');
  ids.forEach((id, i) => {
    setTimeout(() => {
      const f = document.createElement('form');
      f.method = 'POST'; f.style.display = 'none';
      f.innerHTML = `<input name="action" value="add_credits"><input name="user_id" value="${id}"><input name="credits" value="${creditsVal}"><input name="note" value="${noteVal}">`;
      document.body.appendChild(f);
      if (i === ids.length - 1) {
        f.addEventListener('submit', () => setTimeout(() => location.reload(), 800));
      }
      f.submit();
    }, i * 200);
  });
}

// ── Toast ─────────────────────────────────────────────────
function showToast(msg, type = 'ok') {
  const t    = document.getElementById('toast');
  const icon = document.getElementById('toastIcon');
  document.getElementById('toastText').innerHTML = msg;
  const colors = { ok: '#10B981', warn: '#F59E0B', err: '#EF4444' };
  const icons  = { ok: 'fa-check-circle', warn: 'fa-exclamation-triangle', err: 'fa-times-circle' };
  icon.className   = `fas ${icons[type]||'fa-info-circle'}`;
  icon.style.color = colors[type] || '#10B981';
  t.style.transform = 'translateY(0)'; t.style.opacity = '1';
  clearTimeout(t._t);
  t._t = setTimeout(() => { t.style.transform = 'translateY(20px)'; t.style.opacity = '0'; }, 4000);
}

// ── Escape helper ─────────────────────────────────────────
function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ── Auto-show flash if PHP emitted one ────────────────────
<?php if ($flash): ?>
showToast(<?= json_encode(strip_tags($flash['msg'])) ?>, <?= json_encode($flash['type']) ?>);
<?php endif; ?>
</script>

</body>
</html>