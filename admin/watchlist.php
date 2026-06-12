<?php
require_once 'auth_check.php';
$adminUser = checkAdminAuth();
require_once '../config/database.php';

$conn       = getDBConnection();
$activePage = 'domains'; // keeps sidebar "Watchlist" link highlighted

ensurePinnedDomainTables($conn);

// ── POST: update status ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Single status update
    if (isset($_POST['update_status'], $_POST['id'], $_POST['status'])) {
        $id     = (int)$_POST['id'];
        $status = in_array($_POST['status'], ['active','notified','expired']) ? $_POST['status'] : 'active';
        $stmt   = $conn->prepare("UPDATE pinned_domains SET status=? WHERE id=?");
        $stmt->bind_param("si", $status, $id);
        $stmt->execute();
        $stmt->close();
        logAdminActivity($adminUser['id'], 'UPDATE_WATCHLIST_STATUS', "Updated watchlist domain ID: $id to status: $status");
        header('Location: watchlist.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit();
    }

    // Bulk action
    if (isset($_POST['bulk_action'], $_POST['selected_ids'])) {
        $ids    = array_map('intval', (array)$_POST['selected_ids']);
        $action = $_POST['bulk_action'];
        if ($ids) {
            $ph = implode(',', $ids);
            if (in_array($action, ['active','notified','expired'])) {
                $conn->query("UPDATE pinned_domains SET status='$action' WHERE id IN ($ph)");
                logAdminActivity($adminUser['id'], 'BULK_UPDATE_WATCHLIST', "Bulk set status=$action for IDs: $ph");
            } elseif ($action === 'delete') {
                $conn->query("DELETE FROM pinned_domains WHERE id IN ($ph)");
                logAdminActivity($adminUser['id'], 'BULK_DELETE_WATCHLIST', "Bulk deleted watchlist IDs: $ph");
            }
        }
        header('Location: watchlist.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit();
    }
}

// ── GET: delete single ────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id   = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM pinned_domains WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    logAdminActivity($adminUser['id'], 'DELETE_WATCHLIST_DOMAIN', "Deleted watchlist domain ID: $id");
    // Redirect without the delete param
    $params = $_GET;
    unset($params['delete']);
    header('Location: watchlist.php' . ($params ? '?' . http_build_query($params) : ''));
    exit();
}

// ── CSV export ────────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="watchlist_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Domain', 'User Email', 'Full Name', 'Status', 'IP Address', 'Watchlisted At']);
    $rs = $conn->query("
        SELECT pd.id, pd.domain_name,
               COALESCE(u.email, pd.email) as user_email,
               COALESCE(u.full_name, '') as full_name,
               pd.status, pd.ip_address, pd.pinned_at
        FROM pinned_domains pd
        LEFT JOIN users u ON u.id = pd.user_id
        ORDER BY pd.pinned_at DESC
    ");
    while ($r = $rs->fetch_assoc()) fputcsv($out, $r);
    fclose($out);
    $conn->close();
    exit();
}

// ── Filters ───────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$statusFilter = in_array($_GET['status'] ?? '', ['active','notified','expired','']) ? ($_GET['status'] ?? '') : '';
$userFilter   = (int)($_GET['user_id'] ?? 0);
$sortCol      = in_array($_GET['sort'] ?? '', ['pinned_at','domain_name','status']) ? $_GET['sort'] : 'pinned_at';
$sortDir      = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

$where  = ['1=1'];
$binds  = [];
$types  = '';

if ($search) {
    $like    = "%$search%";
    $where[] = "(pd.domain_name LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)";
    $binds   = array_merge($binds, [$like, $like, $like]);
    $types  .= 'sss';
}
if ($statusFilter) {
    $where[] = "pd.status=?";
    $binds[] = $statusFilter;
    $types  .= 's';
}
if ($userFilter) {
    $where[] = "pd.user_id=?";
    $binds[] = $userFilter;
    $types  .= 'i';
}

$whereSQL = implode(' AND ', $where);
$sortMap  = [
    'pinned_at'   => 'pd.pinned_at',
    'domain_name' => 'pd.domain_name',
    'status'      => 'pd.status',
];
$orderSQL = ($sortMap[$sortCol] ?? 'pd.pinned_at') . ' ' . $sortDir;

// Count
$cStmt = $conn->prepare("
    SELECT COUNT(*) as c
    FROM pinned_domains pd
    LEFT JOIN users u ON u.id = pd.user_id
    WHERE $whereSQL
");
if ($types) $cStmt->bind_param($types, ...$binds);
$cStmt->execute();
$totalRows  = (int)$cStmt->get_result()->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$cStmt->close();

// Data
$dStmt = $conn->prepare("
    SELECT pd.*,
           COALESCE(u.email, pd.email) as user_email,
           u.full_name, u.avatar, u.plan
    FROM pinned_domains pd
    LEFT JOIN users u ON u.id = pd.user_id
    WHERE $whereSQL
    ORDER BY $orderSQL
    LIMIT ? OFFSET ?
");
$allBinds = array_merge($binds, [$perPage, $offset]);
$allTypes = $types . 'ii';
$dStmt->bind_param($allTypes, ...$allBinds);
$dStmt->execute();

$result = $dStmt->get_result();
$rows = [];
while ($r = $result->fetch_assoc()) $rows[] = $r;
$dStmt->close();

// ── Stats ──────────────────────────────────────────────────────
$safe = fn($q): int => (int)($conn->query($q)?->fetch_assoc()['c'] ?? 0);

$statTotal   = $safe("SELECT COUNT(*) as c FROM pinned_domains");
$statActive  = $safe("SELECT COUNT(*) as c FROM pinned_domains WHERE status='active'");
$statNotified= $safe("SELECT COUNT(*) as c FROM pinned_domains WHERE status='notified'");
$statExpired = $safe("SELECT COUNT(*) as c FROM pinned_domains WHERE status='expired'");
$statUsers   = $safe("SELECT COUNT(DISTINCT user_id) as c FROM pinned_domains WHERE status='active'");

// Most watchlisted domains (top 5)
$topDomains = [];
$topResult = $conn->query("
    SELECT domain_name, COUNT(*) as watch_count
    FROM pinned_domains
    WHERE status='active'
    GROUP BY domain_name
    ORDER BY watch_count DESC
    LIMIT 5
");
while ($r = $topResult->fetch_assoc()) $topDomains[] = $r;
$maxWatches = !empty($topDomains) ? $topDomains[0]['watch_count'] : 1;

$conn->close();

// ── URL helpers ────────────────────────────────────────────────
function wlPageUrl(int $p, array $extra = []): string {
    $params = array_merge($_GET, $extra, ['page' => $p]);
    unset($params['delete'], $params['export']);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== 0));
}
function wlSortUrl(string $col): string {
    $dir = ($_GET['sort'] ?? '') === $col && ($_GET['dir'] ?? 'desc') === 'asc' ? 'desc' : 'asc';
    return wlPageUrl(1, ['sort' => $col, 'dir' => $dir]);
}
function wlSortIcon(string $col): string {
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
<title>Watchlist — CheckDomain Admin</title>
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

/* Badges */
.badge{display:inline-flex;align-items:center;gap:4px;font-size:.7rem;font-weight:600;padding:2px 8px;border-radius:9999px;white-space:nowrap}
.b-active   {background:rgba(16,185,129,.15);color:#34D399}
.b-notified {background:rgba(59,130,246,.15); color:#93C5FD}
.b-expired  {background:rgba(239,68,68,.15);  color:#FCA5A5}
.b-pro      {background:rgba(16,185,129,.15); color:#34D399}
.b-elite    {background:rgba(245,200,66,.1);  color:#FCD34D}
.b-free     {background:rgba(107,114,128,.2); color:#9CA3AF}

/* Pulse dot */
.pulse-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.pd-active  {background:#10B981;animation:pulse 2s infinite}
.pd-notified{background:#3B82F6}
.pd-expired {background:#EF4444}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}

/* Checkbox */
.chk{width:15px;height:15px;cursor:pointer;accent-color:#3B82F6}

/* Domain chip */
.domain-chip{font-family:monospace;font-size:.8rem;color:#93C5FD;font-weight:600}

/* Top domains bar */
.top-bar-wrap{height:6px;background:rgba(59,130,246,.1);border-radius:3px;overflow:hidden}
.top-bar-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,#3B82F6,#10B981);transition:width .5s ease}

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

  <!-- Page header -->
  <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
    <div>
      <h1 class="text-2xl md:text-3xl font-bold">Watchlist</h1>
      <p class="text-gray-400 text-sm mt-1">
        Domains users are monitoring ·
        <?= number_format($totalRows) ?> record<?= $totalRows !== 1 ? 's' : '' ?>
        <?php if ($search || $statusFilter || $userFilter): ?>
        <span class="text-blue-400">(filtered)</span>
        <?php endif; ?>
      </p>
    </div>
    <div class="flex flex-wrap gap-3">
      <a href="?export=1&<?= http_build_query(array_filter(['search'=>$search,'status'=>$statusFilter])) ?>"
         class="btn-secondary flex items-center gap-2 text-sm">
        <i class="fas fa-download text-xs"></i> Export CSV
      </a>
      <button onclick="window.location.reload()"
              class="btn-primary flex items-center gap-2 text-sm">
        <i class="fas fa-sync-alt text-xs"></i> Refresh
      </button>
    </div>
  </div>

  <!-- Stats cards -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-8">
    <?php
    $cards = [
      ['lbl'=>'Total entries',  'val'=>number_format($statTotal),    'icon'=>'fa-bookmark',    'c'=>'blue'],
      ['lbl'=>'Active',         'val'=>number_format($statActive),   'icon'=>'fa-eye',         'c'=>'green'],
      ['lbl'=>'Notified',       'val'=>number_format($statNotified), 'icon'=>'fa-bell',        'c'=>'blue'],
      ['lbl'=>'Expired',        'val'=>number_format($statExpired),  'icon'=>'fa-clock',       'c'=>'red'],
      ['lbl'=>'Unique users',   'val'=>number_format($statUsers),    'icon'=>'fa-users',       'c'=>'purple'],
    ];
    $cmap = [
        'blue'  => ['bg'=>'bg-blue-500/20',   't'=>'text-blue-400'],
        'green' => ['bg'=>'bg-green-500/20',  't'=>'text-green-400'],
        'red'   => ['bg'=>'bg-red-500/20',    't'=>'text-red-400'],
        'purple'=> ['bg'=>'bg-purple-500/20', 't'=>'text-purple-400'],
    ];
    foreach ($cards as $c):
        $cl = $cmap[$c['c']] ?? $cmap['blue'];
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

  <!-- Two column: filters + top domains -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

    <!-- Filters (2 cols wide) -->
    <div class="lg:col-span-2 bg-slate-800/50 rounded-xl p-4">
      <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-44">
          <label class="text-xs text-gray-400 mb-1 block">Search</label>
          <div class="relative">
            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
            <input class="inp pl-8" type="text" name="search"
                   value="<?= htmlspecialchars($search) ?>"
                   placeholder="Domain, email or name…" autocomplete="off">
          </div>
        </div>
        <div class="w-32">
          <label class="text-xs text-gray-400 mb-1 block">Status</label>
          <select class="inp" name="status">
            <option value="">All</option>
            <option value="active"   <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
            <option value="notified" <?= $statusFilter==='notified'?'selected':'' ?>>Notified</option>
            <option value="expired"  <?= $statusFilter==='expired'?'selected':'' ?>>Expired</option>
          </select>
        </div>
        <div class="w-36">
          <label class="text-xs text-gray-400 mb-1 block">Sort</label>
          <select class="inp" name="sort" onchange="this.form.submit()">
            <option value="pinned_at"   <?= $sortCol==='pinned_at'?'selected':'' ?>>Date added</option>
            <option value="domain_name" <?= $sortCol==='domain_name'?'selected':'' ?>>Domain A–Z</option>
            <option value="status"      <?= $sortCol==='status'?'selected':'' ?>>Status</option>
          </select>
        </div>
        <input type="hidden" name="dir" value="<?= $sortDir==='ASC'?'asc':'desc' ?>">
        <button type="submit" class="btn-primary btn-sm flex items-center gap-2">
          <i class="fas fa-filter text-xs"></i> Filter
        </button>
        <?php if ($search || $statusFilter || $userFilter): ?>
        <a href="watchlist.php" class="btn-secondary btn-sm flex items-center gap-2">
          <i class="fas fa-times text-xs"></i> Clear
        </a>
        <?php endif; ?>
      </form>
    </div>

    <!-- Top watchlisted domains -->
    <?php if (!empty($topDomains)): ?>
    <div class="bg-slate-800/50 rounded-xl p-4">
      <div class="text-xs text-gray-400 uppercase tracking-wide font-semibold mb-3">
        <i class="fas fa-fire text-amber-400 mr-1"></i> Most watched
      </div>
      <div class="flex flex-col gap-2.5">
        <?php foreach ($topDomains as $td):
          $pct = round(($td['watch_count'] / max($maxWatches, 1)) * 100);
        ?>
        <div>
          <div class="flex justify-between items-center mb-1">
            <a href="?search=<?= urlencode($td['domain_name']) ?>"
               class="domain-chip hover:text-white transition truncate max-w-36"
               title="<?= htmlspecialchars($td['domain_name']) ?>">
              <?= htmlspecialchars($td['domain_name']) ?>
            </a>
            <span class="text-blue-400 text-xs font-mono ml-2 flex-shrink-0"><?= $td['watch_count'] ?>×</span>
          </div>
          <div class="top-bar-wrap">
            <div class="top-bar-fill" style="width:<?= $pct ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- Bulk action bar -->
  <div id="bulkBar" class="hidden bg-blue-900/30 border border-blue-500/30 rounded-xl px-4 py-3 mb-4 flex items-center gap-3 text-sm flex-wrap">
    <span id="bulkCount" class="font-mono text-blue-300">0 selected</span>
    <span class="text-gray-600">·</span>
    <span class="text-gray-400 text-xs">Mark as:</span>
    <?php foreach (['active'=>'Active','notified'=>'Notified','expired'=>'Expired'] as $val=>$lbl): ?>
    <button onclick="bulkAction('<?= $val ?>')"
            class="btn-secondary btn-sm"><?= $lbl ?></button>
    <?php endforeach; ?>
    <button onclick="bulkAction('delete')"
            class="btn-secondary btn-sm text-red-400 hover:text-white">
      <i class="fas fa-trash text-xs"></i> Delete
    </button>
    <button onclick="clearSelection()" class="text-gray-500 hover:text-gray-300 text-xs ml-auto">Deselect all</button>
  </div>

  <!-- Watchlist table -->
  <div class="bg-slate-800/50 rounded-xl overflow-hidden">
    <?php if (!empty($rows)): ?>
    <form method="POST" id="bulkForm">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-700/50 border-b border-gray-700 text-xs uppercase tracking-wide text-gray-400">
          <tr>
            <th class="p-4 w-10">
              <input type="checkbox" id="selectAll" class="chk">
            </th>
            <th class="p-4 text-left">
              <a href="<?= wlSortUrl('domain_name') ?>" class="hover:text-white flex items-center">
                Domain <?= wlSortIcon('domain_name') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-mobile">User</th>
            <th class="p-4 text-left hide-sm">Plan</th>
            <th class="p-4 text-left">
              <a href="<?= wlSortUrl('status') ?>" class="hover:text-white flex items-center">
                Status <?= wlSortIcon('status') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-mobile">
              <a href="<?= wlSortUrl('pinned_at') ?>" class="hover:text-white flex items-center">
                Date added <?= wlSortIcon('pinned_at') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-sm">IP</th>
            <th class="p-4 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-700/50">
          <?php foreach ($rows as $row):
            $initials = strtoupper(substr($row['full_name'] ?: ($row['user_email'] ?? 'U'), 0, 1));
            $userPlan = $row['plan'] ?? 'free';
          ?>
          <tr class="tbl-row">

            <!-- Checkbox -->
            <td class="p-4">
              <input type="checkbox" name="selected_ids[]" value="<?= (int)$row['id'] ?>"
                     class="chk row-chk" onchange="onCheckChange()">
            </td>

            <!-- Domain -->
            <td class="p-4">
              <div class="flex items-center gap-2">
                <span class="pulse-dot pd-<?= $row['status'] ?>"></span>
                <div>
                  <a href="?search=<?= urlencode($row['domain_name']) ?>"
                     class="domain-chip hover:text-white transition">
                    <?= htmlspecialchars($row['domain_name']) ?>
                  </a>
                  <div class="text-gray-600 text-xs mt-0.5 font-mono">#<?= (int)$row['id'] ?></div>
                </div>
              </div>
            </td>

            <!-- User -->
            <td class="p-4 hide-mobile">
              <div class="flex items-center gap-2">
                <div class="w-7 h-7 rounded-full flex-shrink-0 flex items-center justify-center font-bold text-xs"
                     style="background:linear-gradient(135deg,#2563EB,#06B6D4)">
                  <?php if (!empty($row['avatar'])): ?>
                  <img src="<?= htmlspecialchars($row['avatar']) ?>" class="w-7 h-7 rounded-full object-cover" alt="">
                  <?php else: ?>
                  <?= htmlspecialchars($initials) ?>
                  <?php endif; ?>
                </div>
                <div class="min-w-0">
                  <div class="text-white text-xs font-medium truncate max-w-32"><?= htmlspecialchars($row['full_name'] ?: '—') ?></div>
                  <a href="users.php?search=<?= urlencode($row['user_email'] ?? '') ?>"
                     class="text-blue-400 hover:text-blue-300 text-xs truncate max-w-32 block transition">
                    <?= htmlspecialchars($row['user_email'] ?? '—') ?>
                  </a>
                </div>
              </div>
            </td>

            <!-- Plan -->
            <td class="p-4 hide-sm">
              <span class="badge b-<?= $userPlan ?>">
                <i class="fas <?= $userPlan==='elite'?'fa-crown':($userPlan==='pro'?'fa-bolt':'fa-user') ?> text-xs"></i>
                <?= ucfirst($userPlan) ?>
              </span>
            </td>

            <!-- Status + inline change -->
            <td class="p-4">
              <form method="POST" class="flex items-center gap-2">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <input type="hidden" name="update_status" value="1">
                <select name="status"
                        onchange="if(confirm('Change status?')) this.form.submit();"
                        class="bg-slate-700 border border-gray-600 rounded text-xs px-2 py-1 text-white outline-none focus:border-blue-500 cursor-pointer">
                  <option value="active"   <?= $row['status']==='active'?'selected':'' ?>>Active</option>
                  <option value="notified" <?= $row['status']==='notified'?'selected':'' ?>>Notified</option>
                  <option value="expired"  <?= $row['status']==='expired'?'selected':'' ?>>Expired</option>
                </select>
              </form>
            </td>

            <!-- Date -->
            <td class="p-4 hide-mobile">
              <div class="text-xs text-white"><?= date('M j, Y', strtotime($row['pinned_at'])) ?></div>
              <div class="text-xs text-gray-500"><?= date('H:i', strtotime($row['pinned_at'])) ?></div>
            </td>

            <!-- IP -->
            <td class="p-4 hide-sm">
              <?php if ($row['ip_address']): ?>
              <span class="font-mono text-xs text-gray-400"><?= htmlspecialchars($row['ip_address']) ?></span>
              <?php else: ?>
              <span class="text-gray-600 text-xs">—</span>
              <?php endif; ?>
            </td>

            <!-- Actions -->
            <td class="p-4 text-right">
              <div class="flex items-center justify-end gap-1.5">
                <!-- Filter by user -->
                <?php if ($row['user_id']): ?>
                <a href="?user_id=<?= (int)$row['user_id'] ?>"
                   class="w-8 h-8 bg-purple-500/20 hover:bg-purple-500/30 rounded-lg flex items-center justify-center text-purple-400 transition"
                   title="View all domains for this user">
                  <i class="fas fa-user text-xs"></i>
                </a>
                <?php endif; ?>
                <!-- WHOIS -->
                <a href="../whois.php?domain=<?= urlencode($row['domain_name']) ?>"
                   target="_blank"
                   class="w-8 h-8 bg-blue-500/20 hover:bg-blue-500/30 rounded-lg flex items-center justify-center text-blue-400 transition"
                   title="WHOIS lookup">
                  <i class="fas fa-search text-xs"></i>
                </a>
                <!-- Delete -->
                <a href="?delete=<?= (int)$row['id'] ?>&<?= http_build_query(array_filter(['search'=>$search,'status'=>$statusFilter,'page'=>$page])) ?>"
                   onclick="return confirm('Delete this watchlist entry?')"
                   class="w-8 h-8 bg-red-500/20 hover:bg-red-500/30 rounded-lg flex items-center justify-center text-red-400 transition"
                   title="Delete">
                  <i class="fas fa-trash text-xs"></i>
                </a>
              </div>
            </td>

          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination + footer -->
    <div class="flex flex-col sm:flex-row justify-between items-center gap-4 p-4 bg-slate-700/30 border-t border-gray-700">
      <div class="text-xs text-gray-400">
        Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalRows)) ?> of <?= number_format($totalRows) ?>
        <?php if ($userFilter): ?>
        · <a href="watchlist.php" class="text-blue-400 hover:text-blue-300">Clear user filter</a>
        <?php endif; ?>
      </div>
      <?php if ($totalPages > 1): ?>
      <div class="flex flex-wrap justify-center gap-1.5">
        <?php if ($page > 1): ?>
        <a href="<?= wlPageUrl($page - 1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs">
          <i class="fas fa-chevron-left"></i>
        </a>
        <?php endif; ?>
        <?php
        $s = max(1, $page - 2);
        $e = min($totalPages, $page + 2);
        if ($s > 1) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>';
        for ($i = $s; $i <= $e; $i++):
        ?>
        <a href="<?= wlPageUrl($i) ?>"
           class="px-3 py-1.5 rounded text-xs transition <?= $i === $page ? 'bg-blue-600' : 'bg-slate-700 hover:bg-slate-600' ?>">
          <?= $i ?>
        </a>
        <?php endfor;
        if ($e < $totalPages) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>'; ?>
        <?php if ($page < $totalPages): ?>
        <a href="<?= wlPageUrl($page + 1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs">
          <i class="fas fa-chevron-right"></i>
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    </form><!-- /bulkForm -->

    <?php else: ?>
    <div class="text-center py-16">
      <i class="fas fa-bookmark text-5xl text-gray-700 mb-4 block"></i>
      <p class="text-gray-400 mb-1">No watchlist entries found</p>
      <?php if ($search || $statusFilter || $userFilter): ?>
      <a href="watchlist.php" class="text-blue-400 hover:text-blue-300 text-sm mt-2 inline-block">Clear filters</a>
      <?php else: ?>
      <p class="text-gray-600 text-sm mt-1">Users haven't added any domains to their watchlist yet.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

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
// ── Bulk checkboxes ────────────────────────────────────────
const bulkBar   = document.getElementById('bulkBar');
const bulkCount = document.getElementById('bulkCount');

function onCheckChange() {
  const checked = document.querySelectorAll('.row-chk:checked');
  const all     = document.querySelectorAll('.row-chk');
  const sa      = document.getElementById('selectAll');
  bulkCount.textContent = checked.length + ' selected';
  bulkBar.classList.toggle('hidden', checked.length === 0);
  if (sa) sa.checked = checked.length === all.length && all.length > 0;
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

  const label = action === 'delete'
    ? `Delete ${ids.length} entry(s)?`
    : `Mark ${ids.length} entry(s) as "${action}"?`;
  if (!confirm(label)) return;

  const form = document.getElementById('bulkForm');

  // Set bulk_action
  let ba = form.querySelector('input[name="bulk_action"]');
  if (!ba) { ba = document.createElement('input'); ba.type='hidden'; ba.name='bulk_action'; form.appendChild(ba); }
  ba.value = action;

  // Add selected IDs (avoid duplicates from checkboxes already named selected_ids[])
  ids.forEach(id => {
    // The checkbox inputs already have name="selected_ids[]" — form submit picks them up
  });

  form.submit();
}

// ── Toast ──────────────────────────────────────────────────
function showToast(msg, type = 'ok') {
  const t    = document.getElementById('toast');
  const icon = document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  const colors = { ok:'#10B981', warn:'#F59E0B', err:'#EF4444' };
  const icons  = { ok:'fa-check-circle', warn:'fa-exclamation-triangle', err:'fa-times-circle' };
  icon.className   = 'fas ' + (icons[type] || 'fa-info-circle');
  icon.style.color = colors[type] || '#10B981';
  t.style.transform = 'translateY(0)';
  t.style.opacity   = '1';
  clearTimeout(t._t);
  t._t = setTimeout(() => { t.style.transform = 'translateY(20px)'; t.style.opacity = '0'; }, 3500);
}

// Show success toast after redirect if action was taken
<?php if (isset($_GET['done'])): ?>
showToast('Action completed successfully.');
<?php endif; ?>
</script>

</body>
</html>