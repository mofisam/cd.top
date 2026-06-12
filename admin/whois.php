<?php
require_once 'auth_check.php';
$adminUser = checkAdminAuth();
require_once '../config/database.php';

$conn       = getDBConnection();
$activePage = 'whois';

// ── Auto-create table if missing ─────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS whois_lookups (
        id                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        user_id             INT UNSIGNED     NOT NULL,
        domain_name         VARCHAR(253)     NOT NULL,
        tld                 VARCHAR(63)      NOT NULL,
        credits_spent       TINYINT UNSIGNED NOT NULL DEFAULT 3,
        registrar           VARCHAR(255)     NULL,
        registrar_url       VARCHAR(512)     NULL,
        registrant_name     VARCHAR(255)     NULL,
        registrant_org      VARCHAR(255)     NULL,
        registrant_country  VARCHAR(10)      NULL,
        registrant_email    VARCHAR(320)     NULL,
        created_date        DATE             NULL,
        updated_date        DATE             NULL,
        expiry_date         DATE             NULL,
        status              VARCHAR(512)     NULL,
        nameservers         TEXT             NULL,
        dnssec              VARCHAR(64)      NULL,
        is_available        TINYINT(1)       NOT NULL DEFAULT 0,
        raw_response        MEDIUMTEXT       NULL,
        source              ENUM('api','socket','cache') NOT NULL DEFAULT 'api',
        looked_up_at        TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_wl_user   (user_id),
        INDEX idx_wl_domain (domain_name),
        INDEX idx_wl_date   (looked_up_at),
        CONSTRAINT fk_wl_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Helpers ──────────────────────────────────────────────────
$flash = null;

// ── POST actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'delete') {
        $id = (int)($_POST['lookup_id'] ?? 0);
        if ($id) {
            $conn->query("DELETE FROM whois_lookups WHERE id = $id");
            logAdminActivity($adminUser['id'], 'DELETE_WHOIS', "Deleted WHOIS lookup ID: $id");
            $flash = ['type'=>'ok', 'msg'=>"WHOIS lookup #$id deleted."];
        }
    }

    elseif ($action === 'bulk_delete') {
        $ids = array_map('intval', (array)($_POST['selected_ids'] ?? []));
        if ($ids) {
            $ph = implode(',', $ids);
            $conn->query("DELETE FROM whois_lookups WHERE id IN ($ph)");
            logAdminActivity($adminUser['id'], 'BULK_DELETE_WHOIS', "Deleted " . count($ids) . " WHOIS lookups");
            $flash = ['type'=>'ok', 'msg'=>count($ids) . " lookup(s) deleted."];
        }
    }

    elseif ($action === 'purge_cache') {
        // Delete all cache-sourced lookups older than 24h
        $conn->query("DELETE FROM whois_lookups WHERE source='cache' AND looked_up_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $affected = $conn->affected_rows;
        logAdminActivity($adminUser['id'], 'PURGE_WHOIS_CACHE', "Purged $affected stale cached WHOIS records");
        $flash = ['type'=>'ok', 'msg'=>"Purged $affected stale cached record(s)."];
    }
}

// ── CSV export ────────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="whois_lookups_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','User ID','Email','Domain','TLD','Registrar','Registrant','Country','Created','Updated','Expiry','Available','Source','Credits','Looked Up At']);
    $rs = $conn->query("
        SELECT w.id, w.user_id, u.email, w.domain_name, w.tld, w.registrar,
               w.registrant_name, w.registrant_country,
               w.created_date, w.updated_date, w.expiry_date,
               w.is_available, w.source, w.credits_spent, w.looked_up_at
        FROM whois_lookups w
        JOIN users u ON u.id = w.user_id
        ORDER BY w.looked_up_at DESC
    ");
    while ($r = $rs->fetch_assoc()) fputcsv($out, $r);
    fclose($out);
    $conn->close();
    exit();
}

// ── Filters ──────────────────────────────────────────────────
$search        = trim($_GET['search']   ?? '');
$tldFilter     = trim($_GET['tld']      ?? '');
$sourceFilter  = in_array($_GET['source'] ?? '', ['api','socket','cache','']) ? ($_GET['source'] ?? '') : '';
$availFilter   = $_GET['available'] ?? '';
$dateFrom      = trim($_GET['date_from'] ?? '');
$dateTo        = trim($_GET['date_to']   ?? '');
$userFilter    = (int)($_GET['user_id']  ?? 0);
$sortCol       = in_array($_GET['sort'] ?? '', ['looked_up_at','domain_name','expiry_date','credits_spent'])
                 ? $_GET['sort'] : 'looked_up_at';
$sortDir       = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 25;
$offset        = ($page - 1) * $perPage;

$where  = ['1=1']; $binds = []; $types = '';

if ($search) {
    $like    = "%$search%";
    $where[] = "(w.domain_name LIKE ? OR u.email LIKE ? OR u.full_name LIKE ? OR w.registrar LIKE ? OR w.registrant_name LIKE ?)";
    $binds   = array_merge($binds, [$like,$like,$like,$like,$like]);
    $types  .= 'sssss';
}
if ($tldFilter)    { $where[] = "w.tld = ?";           $binds[] = $tldFilter;    $types .= 's'; }
if ($sourceFilter) { $where[] = "w.source = ?";        $binds[] = $sourceFilter; $types .= 's'; }
if ($availFilter === '1') { $where[] = "w.is_available = 1"; }
if ($availFilter === '0') { $where[] = "w.is_available = 0"; }
if ($dateFrom)     { $where[] = "DATE(w.looked_up_at) >= ?"; $binds[] = $dateFrom; $types .= 's'; }
if ($dateTo)       { $where[] = "DATE(w.looked_up_at) <= ?"; $binds[] = $dateTo;   $types .= 's'; }
if ($userFilter)   { $where[] = "w.user_id = ?";        $binds[] = $userFilter;  $types .= 'i'; }

$whereSQL = implode(' AND ', $where);
$sortMap  = [
    'looked_up_at'  => 'w.looked_up_at',
    'domain_name'   => 'w.domain_name',
    'expiry_date'   => 'w.expiry_date',
    'credits_spent' => 'w.credits_spent',
];
$orderSQL = ($sortMap[$sortCol] ?? 'w.looked_up_at') . ' ' . $sortDir;

// Count
$cStmt = $conn->prepare("
    SELECT COUNT(*) as c
    FROM whois_lookups w JOIN users u ON u.id = w.user_id
    WHERE $whereSQL
");
if ($types) $cStmt->bind_param($types, ...$binds);
$cStmt->execute();
$totalRows  = (int)$cStmt->get_result()->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$cStmt->close();

// Data
$dStmt = $conn->prepare("
    SELECT w.*, u.email, u.full_name, u.avatar
    FROM whois_lookups w
    JOIN users u ON u.id = w.user_id
    WHERE $whereSQL
    ORDER BY $orderSQL
    LIMIT ? OFFSET ?
");
$allBinds = array_merge($binds, [$perPage, $offset]);
$allTypes = $types . 'ii';
$dStmt->bind_param($allTypes, ...$allBinds);
$dStmt->execute();
$lookups = [];
$result = $dStmt->get_result();
while ($r = $result->fetch_assoc()) $lookups[] = $r;
$dStmt->close();

// ── Summary stats ─────────────────────────────────────────────
$safe  = fn($q): int => (int)($conn->query($q)?->fetch_assoc()['c'] ?? 0);
$safeV = fn($q): int => (int)($conn->query($q)?->fetch_assoc()['v'] ?? 0);

$statTotal       = $safe("SELECT COUNT(*) as c FROM whois_lookups");
$statToday       = $safe("SELECT COUNT(*) as c FROM whois_lookups WHERE DATE(looked_up_at) = CURDATE()");
$statApi         = $safe("SELECT COUNT(*) as c FROM whois_lookups WHERE source='api'");
$statSocket      = $safe("SELECT COUNT(*) as c FROM whois_lookups WHERE source='socket'");
$statCache       = $safe("SELECT COUNT(*) as c FROM whois_lookups WHERE source='cache'");
$statAvailable   = $safe("SELECT COUNT(*) as c FROM whois_lookups WHERE is_available=1");
$statCreditsUsed = $safeV("SELECT COALESCE(SUM(credits_spent),0) as v FROM whois_lookups");
$statExpiring30  = $safe("SELECT COUNT(*) as c FROM whois_lookups WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");

// Top TLDs
$topTlds = [];
$tRes = $conn->query("SELECT tld, COUNT(*) as c FROM whois_lookups GROUP BY tld ORDER BY c DESC LIMIT 6");
while ($t = $tRes->fetch_assoc()) $topTlds[] = $t;

// Top users by lookup count
$topUsers = [];
$uRes = $conn->query("
    SELECT u.email, u.full_name, COUNT(*) as lookups, SUM(w.credits_spent) as credits
    FROM whois_lookups w JOIN users u ON u.id=w.user_id
    GROUP BY w.user_id ORDER BY lookups DESC LIMIT 5
");
while ($u = $uRes->fetch_assoc()) $topUsers[] = $u;

$conn->close();

// ── URL helpers ────────────────────────────────────────────────
function whPageUrl(int $p, array $extra = []): string {
    $params = array_merge($_GET, $extra, ['page' => $p]);
    unset($params['export']);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== 0));
}
function whSortUrl(string $col): string {
    $dir = ($_GET['sort'] ?? '') === $col && ($_GET['dir'] ?? 'desc') === 'asc' ? 'desc' : 'asc';
    return whPageUrl(1, ['sort' => $col, 'dir' => $dir]);
}
function whSortIcon(string $col): string {
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
<title>WHOIS Lookups — CheckDomain Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
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

/* Badges */
.badge{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:600;padding:2px 8px;border-radius:9999px;white-space:nowrap}
.b-api    {background:rgba(59,130,246,.15);color:#93C5FD}
.b-socket {background:rgba(168,85,247,.15);color:#C4B5FD}
.b-cache  {background:rgba(107,114,128,.2); color:#9CA3AF}
.b-avail  {background:rgba(16,185,129,.15); color:#34D399}
.b-taken  {background:rgba(245,158,11,.15); color:#FCD34D}
.b-expiring{background:rgba(239,68,68,.15); color:#FCA5A5}

/* Domain chip */
.domain-chip{font-family:monospace;font-size:.8125rem;font-weight:700;color:#93C5FD;cursor:pointer;transition:color .15s}
.domain-chip:hover{color:#60A5FA}

/* Expiry bar */
.exp-bar{height:3px;border-radius:2px;background:rgba(255,255,255,.06);overflow:hidden;margin-top:3px}
.exp-fill{height:100%;border-radius:2px;transition:width .4s}

/* NS list */
.ns-pill{font-family:monospace;font-size:.65rem;background:rgba(51,65,85,.6);border:1px solid rgba(71,85,105,.5);border-radius:3px;padding:1px 5px;color:#94A3B8;white-space:nowrap}

/* Raw pre */
.raw-pre{background:#0A0F1A;border:1px solid rgba(59,130,246,.15);border-radius:.5rem;padding:.875rem;font-size:.7rem;font-family:monospace;color:#64748B;white-space:pre-wrap;word-break:break-word;max-height:260px;overflow-y:auto;line-height:1.65}

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
      <h1 class="text-2xl md:text-3xl font-bold">WHOIS Lookups</h1>
      <p class="text-gray-400 text-sm mt-1">
        <?= number_format($totalRows) ?> record<?= $totalRows!==1?'s':'' ?>
        <?php if ($search||$tldFilter||$sourceFilter||$availFilter||$dateFrom||$dateTo||$userFilter): ?>
        <span class="text-blue-400">(filtered)</span>
        <?php endif; ?>
      </p>
    </div>
    <div class="flex flex-wrap gap-3">
      <form method="POST" class="inline">
        <input type="hidden" name="action" value="purge_cache">
        <button type="submit"
                onclick="return confirm('Purge all stale cached WHOIS records older than 24 hours?')"
                class="btn-amber flex items-center gap-2 text-sm">
          <i class="fas fa-broom text-xs"></i> Purge stale cache
        </button>
      </form>
      <a href="?export=1" class="btn-secondary flex items-center gap-2 text-sm">
        <i class="fas fa-download text-xs"></i> Export CSV
      </a>
    </div>
  </div>

  <!-- Stats row -->
  <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3 mb-8">
    <?php
    $cards = [
      ['lbl'=>'Total',          'val'=>number_format($statTotal),       'icon'=>'fa-search',        'c'=>'blue'],
      ['lbl'=>'Today',          'val'=>number_format($statToday),       'icon'=>'fa-calendar-day',  'c'=>'purple'],
      ['lbl'=>'Via API',        'val'=>number_format($statApi),         'icon'=>'fa-plug',          'c'=>'blue'],
      ['lbl'=>'Via socket',     'val'=>number_format($statSocket),      'icon'=>'fa-network-wired', 'c'=>'purple'],
      ['lbl'=>'Cached',         'val'=>number_format($statCache),       'icon'=>'fa-memory',        'c'=>'gray'],
      ['lbl'=>'Available',      'val'=>number_format($statAvailable),   'icon'=>'fa-check-circle',  'c'=>'green'],
      ['lbl'=>'Expiring 30d',   'val'=>number_format($statExpiring30),  'icon'=>'fa-clock',         'c'=>'amber'],
      ['lbl'=>'Credits used',   'val'=>number_format($statCreditsUsed), 'icon'=>'fa-bolt',          'c'=>'amber'],
    ];
    $cmap=['blue'=>['bg'=>'bg-blue-500/20','t'=>'text-blue-400'],'purple'=>['bg'=>'bg-purple-500/20','t'=>'text-purple-400'],'gray'=>['bg'=>'bg-slate-500/20','t'=>'text-slate-400'],'green'=>['bg'=>'bg-green-500/20','t'=>'text-green-400'],'amber'=>['bg'=>'bg-amber-500/20','t'=>'text-amber-400'],'red'=>['bg'=>'bg-red-500/20','t'=>'text-red-400']];
    foreach ($cards as $c):
      $cl = $cmap[$c['c']] ?? $cmap['blue'];
    ?>
    <div class="stat-card rounded-xl p-3">
      <div class="flex justify-between items-start">
        <div>
          <p class="text-gray-500 text-xs"><?= $c['lbl'] ?></p>
          <p class="text-base font-bold mt-0.5 <?= $cl['t'] ?>"><?= $c['val'] ?></p>
        </div>
        <div class="w-7 h-7 <?= $cl['bg'] ?> rounded-full flex items-center justify-center flex-shrink-0">
          <i class="fas <?= $c['icon'] ?> <?= $cl['t'] ?>" style="font-size:.6rem;"></i>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Two column insight cards -->
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">

    <!-- Top TLDs -->
    <div class="bg-slate-800/50 rounded-xl p-4">
      <h3 class="text-xs font-700 uppercase tracking-wide text-gray-400 mb-3">
        <i class="fas fa-globe text-blue-400 mr-1.5"></i> Top TLDs looked up
      </h3>
      <div class="flex flex-col gap-2">
        <?php if (!empty($topTlds)):
          $maxTld = $topTlds[0]['c'] ?? 1;
          foreach ($topTlds as $t):
            $pct = round($t['c'] / $maxTld * 100);
        ?>
        <div class="flex items-center gap-3">
          <span class="font-mono text-xs text-blue-300 w-16 flex-shrink-0">.<?= htmlspecialchars($t['tld']) ?></span>
          <div class="flex-1 h-2 bg-slate-700 rounded-full overflow-hidden">
            <div class="h-full rounded-full bg-blue-500 transition-all" style="width:<?= $pct ?>%"></div>
          </div>
          <span class="text-xs text-gray-400 font-mono w-10 text-right"><?= number_format($t['c']) ?></span>
        </div>
        <?php endforeach; else: ?>
        <p class="text-gray-600 text-xs">No data yet.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Top users -->
    <div class="bg-slate-800/50 rounded-xl p-4">
      <h3 class="text-xs font-700 uppercase tracking-wide text-gray-400 mb-3">
        <i class="fas fa-users text-purple-400 mr-1.5"></i> Top users by lookups
      </h3>
      <div class="flex flex-col gap-2">
        <?php if (!empty($topUsers)):
          foreach ($topUsers as $idx => $tu):
        ?>
        <div class="flex items-center gap-3">
          <span class="text-gray-600 font-mono text-xs w-4 flex-shrink-0"><?= $idx+1 ?></span>
          <div class="flex-1 min-w-0">
            <div class="text-white text-xs font-medium truncate"><?= htmlspecialchars($tu['full_name'] ?: $tu['email']) ?></div>
            <div class="text-gray-500 text-xs truncate"><?= htmlspecialchars($tu['email']) ?></div>
          </div>
          <div class="text-right flex-shrink-0">
            <div class="text-blue-400 font-mono text-xs font-bold"><?= number_format($tu['lookups']) ?> lookups</div>
            <div class="text-amber-400 font-mono text-xs"><?= number_format($tu['credits']) ?> cr</div>
          </div>
        </div>
        <?php endforeach; else: ?>
        <p class="text-gray-600 text-xs">No data yet.</p>
        <?php endif; ?>
      </div>
    </div>

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
                 placeholder="Domain, email or registrar…" autocomplete="off">
        </div>
      </div>
      <div class="w-24">
        <label class="form-label">TLD</label>
        <input class="inp" type="text" name="tld"
               value="<?= htmlspecialchars($tldFilter) ?>"
               placeholder="e.g. com" maxlength="30">
      </div>
      <div class="w-28">
        <label class="form-label">Source</label>
        <select class="inp" name="source">
          <option value="">All</option>
          <?php foreach (['api','socket','cache'] as $s): ?>
          <option value="<?= $s ?>" <?= $sourceFilter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="w-32">
        <label class="form-label">Availability</label>
        <select class="inp" name="available">
          <option value="">All</option>
          <option value="0" <?= $availFilter==='0'?'selected':'' ?>>Registered</option>
          <option value="1" <?= $availFilter==='1'?'selected':'' ?>>Available</option>
        </select>
      </div>
      <div class="w-32">
        <label class="form-label">From</label>
        <input class="inp" type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
      </div>
      <div class="w-32">
        <label class="form-label">To</label>
        <input class="inp" type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
      </div>
      <div class="w-36">
        <label class="form-label">Sort</label>
        <select class="inp" name="sort" onchange="this.form.submit()">
          <option value="looked_up_at"  <?= $sortCol==='looked_up_at'?'selected':'' ?>>Newest</option>
          <option value="domain_name"   <?= $sortCol==='domain_name'?'selected':'' ?>>Domain A–Z</option>
          <option value="expiry_date"   <?= $sortCol==='expiry_date'?'selected':'' ?>>Expiry</option>
          <option value="credits_spent" <?= $sortCol==='credits_spent'?'selected':'' ?>>Credits</option>
        </select>
      </div>
      <input type="hidden" name="dir" value="<?= $sortDir==='ASC'?'asc':'desc' ?>">
      <button type="submit" class="btn-primary btn-sm flex items-center gap-2">
        <i class="fas fa-filter text-xs"></i> Filter
      </button>
      <?php if ($search||$tldFilter||$sourceFilter||$availFilter||$dateFrom||$dateTo||$userFilter): ?>
      <a href="whois.php" class="btn-secondary btn-sm flex items-center gap-2">
        <i class="fas fa-times text-xs"></i> Clear
      </a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Bulk action bar -->
  <div id="bulkBar" class="hidden bg-blue-900/30 border border-blue-500/30 rounded-xl px-4 py-3 mb-4 flex items-center gap-3 text-sm flex-wrap">
    <span id="bulkCount" class="font-mono text-blue-300">0 selected</span>
    <span class="text-gray-600">·</span>
    <button onclick="bulkDelete()" class="btn-secondary btn-sm flex items-center gap-1.5">
      <i class="fas fa-trash text-red-400 text-xs"></i> Delete selected
    </button>
    <button onclick="clearSelection()" class="text-gray-500 hover:text-gray-300 text-xs ml-auto">Deselect all</button>
  </div>

  <!-- Table -->
  <div class="bg-slate-800/50 rounded-xl overflow-hidden">
    <?php if (!empty($lookups)): ?>
    <form method="POST" id="bulkForm">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-700/50 border-b border-gray-700 text-xs uppercase tracking-wide text-gray-400">
          <tr>
            <th class="p-4 w-10"><input type="checkbox" id="selectAll" class="chk"></th>
            <th class="p-4 text-left">
              <a href="<?= whSortUrl('domain_name') ?>" class="hover:text-white flex items-center">
                Domain <?= whSortIcon('domain_name') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-mobile">User</th>
            <th class="p-4 text-left hide-mobile">Registrar</th>
            <th class="p-4 text-left hide-mobile">
              <a href="<?= whSortUrl('expiry_date') ?>" class="hover:text-white flex items-center">
                Expiry <?= whSortIcon('expiry_date') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-sm">Source</th>
            <th class="p-4 text-left hide-sm">
              <a href="<?= whSortUrl('looked_up_at') ?>" class="hover:text-white flex items-center">
                Looked up <?= whSortIcon('looked_up_at') ?>
              </a>
            </th>
            <th class="p-4 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-700/50">
          <?php foreach ($lookups as $lk):
            $initials  = strtoupper(substr($lk['full_name'] ?: $lk['email'], 0, 1));
            $expiryTs  = $lk['expiry_date'] ? strtotime($lk['expiry_date']) : null;
            $daysLeft  = $expiryTs ? (int)ceil(($expiryTs - time()) / 86400) : null;
            $isExpiring = $daysLeft !== null && $daysLeft >= 0 && $daysLeft <= 30;
            $isExpired  = $daysLeft !== null && $daysLeft < 0;

            // Expiry bar
            $createdTs  = $lk['created_date'] ? strtotime($lk['created_date']) : null;
            $barPct     = 0;
            $barColor   = '#10B981';
            if ($expiryTs && $createdTs) {
                $total  = max(1, $expiryTs - $createdTs);
                $barPct = min(100, round(((time() - $createdTs) / $total) * 100));
                $barColor = $barPct >= 90 ? '#EF4444' : ($barPct >= 70 ? '#F59E0B' : '#10B981');
            }
          ?>
          <tr class="tbl-row">

            <!-- Checkbox -->
            <td class="p-4">
              <input type="checkbox" name="selected_ids[]" value="<?= (int)$lk['id'] ?>"
                     class="chk row-chk" onchange="onCheckChange()">
            </td>

            <!-- Domain -->
            <td class="p-4">
              <button class="domain-chip block text-left"
                      onclick="openDetailModal(<?= htmlspecialchars(json_encode($lk), ENT_QUOTES) ?>)">
                <?= htmlspecialchars($lk['domain_name']) ?>
              </button>
              <div class="flex items-center gap-2 mt-1 flex-wrap">
                <?php if ($lk['is_available']): ?>
                <span class="badge b-avail"><i class="fas fa-check text-xs"></i> Available</span>
                <?php elseif ($isExpired): ?>
                <span class="badge b-expiring"><i class="fas fa-hourglass-end text-xs"></i> Expired</span>
                <?php elseif ($isExpiring): ?>
                <span class="badge b-expiring"><i class="fas fa-clock text-xs"></i> Expiring <?= $daysLeft ?>d</span>
                <?php else: ?>
                <span class="badge b-taken"><i class="fas fa-lock text-xs"></i> Registered</span>
                <?php endif; ?>
                <span class="text-gray-600 text-xs font-mono">#<?= (int)$lk['id'] ?></span>
              </div>
            </td>

            <!-- User -->
            <td class="p-4 hide-mobile">
              <div class="flex items-center gap-2">
                <div class="w-7 h-7 rounded-full flex-shrink-0 flex items-center justify-center font-bold text-xs"
                     style="background:linear-gradient(135deg,#2563EB,#06B6D4)">
                  <?php if ($lk['avatar']): ?>
                  <img src="<?= htmlspecialchars($lk['avatar']) ?>" class="w-7 h-7 rounded-full object-cover" alt="">
                  <?php else: ?>
                  <?= htmlspecialchars($initials) ?>
                  <?php endif; ?>
                </div>
                <div class="min-w-0">
                  <div class="text-white text-xs font-medium truncate max-w-28">
                    <?= htmlspecialchars($lk['full_name'] ?: '—') ?>
                  </div>
                  <a href="<?= whPageUrl(1, ['user_id'=>$lk['user_id'],'search'=>'','source'=>'','available'=>'','tld'=>'']) ?>"
                     class="text-gray-500 text-xs truncate max-w-28 block hover:text-blue-400 transition">
                    <?= htmlspecialchars($lk['email']) ?>
                  </a>
                </div>
              </div>
            </td>

            <!-- Registrar -->
            <td class="p-4 hide-mobile">
              <div class="text-white text-xs truncate max-w-36"
                   title="<?= htmlspecialchars($lk['registrar'] ?? '') ?>">
                <?= htmlspecialchars($lk['registrar'] ?: '—') ?>
              </div>
              <?php if ($lk['registrant_org'] || $lk['registrant_country']): ?>
              <div class="text-gray-500 text-xs mt-0.5 truncate max-w-36">
                <?= htmlspecialchars(implode(' · ', array_filter([$lk['registrant_org'], $lk['registrant_country']]))) ?>
              </div>
              <?php endif; ?>
            </td>

            <!-- Expiry -->
            <td class="p-4 hide-mobile" style="min-width:120px;">
              <?php if ($lk['expiry_date']): ?>
              <div class="text-xs <?= $isExpired ? 'text-red-400' : ($isExpiring ? 'text-amber-400' : 'text-white') ?>">
                <?= date('M j, Y', $expiryTs) ?>
              </div>
              <?php if ($daysLeft !== null): ?>
              <div class="text-xs text-gray-500 mt-0.5"><?= $daysLeft >= 0 ? $daysLeft.'d left' : abs($daysLeft).'d ago' ?></div>
              <?php endif; ?>
              <?php if ($barPct > 0): ?>
              <div class="exp-bar w-20 mt-1">
                <div class="exp-fill" style="width:<?= $barPct ?>%;background:<?= $barColor ?>"></div>
              </div>
              <?php endif; ?>
              <?php else: ?>
              <span class="text-gray-600 text-xs">—</span>
              <?php endif; ?>
            </td>

            <!-- Source -->
            <td class="p-4 hide-sm">
              <span class="badge b-<?= $lk['source'] ?>">
                <i class="fas <?= $lk['source']==='api'?'fa-plug':($lk['source']==='socket'?'fa-network-wired':'fa-memory') ?> text-xs"></i>
                <?= ucfirst($lk['source']) ?>
              </span>
              <div class="text-gray-600 text-xs mt-0.5 font-mono">
                <?= (int)$lk['credits_spent'] ?> cr
              </div>
            </td>

            <!-- Looked up -->
            <td class="p-4 hide-sm">
              <div class="text-xs text-white"><?= date('M j, Y', strtotime($lk['looked_up_at'])) ?></div>
              <div class="text-xs text-gray-500"><?= date('H:i:s', strtotime($lk['looked_up_at'])) ?></div>
            </td>

            <!-- Actions -->
            <td class="p-4 text-right">
              <div class="flex items-center justify-end gap-1.5">
                <button type="button" onclick="openDetailModal(<?= htmlspecialchars(json_encode($lk), ENT_QUOTES) ?>)"
                        class="w-8 h-8 bg-blue-500/20 hover:bg-blue-500/30 rounded-lg flex items-center justify-center text-blue-400 transition"
                        title="View full detail">
                  <i class="fas fa-eye text-xs"></i>
                </button>
                <button type="button" onclick="copyText('<?= htmlspecialchars($lk['domain_name'], ENT_QUOTES) ?>')"
                        class="w-8 h-8 bg-slate-500/20 hover:bg-slate-500/30 rounded-lg flex items-center justify-center text-slate-400 transition"
                        title="Copy domain">
                  <i class="fas fa-copy text-xs"></i>
                </button>
                <button type="button" onclick="confirmDelete(<?= (int)$lk['id'] ?>, '<?= htmlspecialchars($lk['domain_name'], ENT_QUOTES) ?>')"
                        class="w-8 h-8 bg-red-500/20 hover:bg-red-500/30 rounded-lg flex items-center justify-center text-red-400 transition"
                        title="Delete record">
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
        <a href="<?= whPageUrl($page-1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php
        $s = max(1,$page-2); $e = min($totalPages,$page+2);
        if ($s>1) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>';
        for ($i=$s;$i<=$e;$i++):
        ?>
        <a href="<?= whPageUrl($i) ?>" class="px-3 py-1.5 rounded text-xs transition <?= $i===$page?'bg-blue-600':'bg-slate-700 hover:bg-slate-600' ?>"><?= $i ?></a>
        <?php endfor;
        if ($e<$totalPages) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>'; ?>
        <?php if ($page < $totalPages): ?>
        <a href="<?= whPageUrl($page+1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    </form>

    <?php else: ?>
    <div class="text-center py-16">
      <i class="fas fa-search text-5xl text-gray-700 mb-4 block"></i>
      <p class="text-gray-400 mb-1">No WHOIS lookups found</p>
      <?php if ($search||$tldFilter||$sourceFilter||$availFilter||$dateFrom||$dateTo||$userFilter): ?>
      <a href="whois.php" class="text-blue-400 hover:text-blue-300 text-sm">Clear filters</a>
      <?php else: ?>
      <p class="text-gray-600 text-sm mt-1">WHOIS lookups made by Pro/Elite users will appear here.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

</div>
</div>

<!-- ═══════════════════════════
     DETAIL MODAL
═══════════════════════════ -->
<div class="modal-backdrop" id="detailModal">
  <div class="modal-box" style="max-width:660px;">
    <div class="flex items-center justify-between mb-5">
      <div>
        <h2 class="text-lg font-bold font-mono" id="dm-domain"></h2>
        <div class="flex items-center gap-2 mt-1" id="dm-badges"></div>
      </div>
      <button onclick="closeModal('detailModal')" class="text-gray-400 hover:text-white flex-shrink-0 ml-4">
        <i class="fas fa-times text-lg"></i>
      </button>
    </div>

    <!-- Info grid -->
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-5 gap-y-3 text-xs mb-5" id="dm-grid"></div>

    <!-- Nameservers -->
    <div id="dm-ns-section" class="hidden mb-4">
      <div class="text-gray-500 text-xs uppercase tracking-wide mb-2">Nameservers</div>
      <div id="dm-ns" class="flex flex-wrap gap-1.5"></div>
    </div>

    <!-- Status codes -->
    <div id="dm-status-section" class="hidden mb-4">
      <div class="text-gray-500 text-xs uppercase tracking-wide mb-2">EPP Status codes</div>
      <div id="dm-status" class="flex flex-wrap gap-1.5"></div>
    </div>

    <!-- Raw WHOIS toggle -->
    <div id="dm-raw-section" class="hidden">
      <button onclick="toggleRaw()"
              class="flex items-center gap-2 text-xs text-gray-500 hover:text-gray-300 transition mb-2"
              id="rawToggleBtn">
        <i class="fas fa-chevron-right" id="rawChevron" style="transition:transform .2s"></i>
        Raw WHOIS response
      </button>
      <pre id="dm-raw" class="raw-pre hidden"></pre>
    </div>

    <div class="border-t border-gray-700 pt-4 mt-4 flex gap-3 justify-end">
      <button onclick="copyFullWhois()" class="btn-secondary btn-sm flex items-center gap-1.5">
        <i class="fas fa-copy text-xs"></i> Copy raw
      </button>
      <button onclick="closeModal('detailModal')" class="btn-secondary btn-sm">Close</button>
    </div>
  </div>
</div>

<!-- Delete confirm modal -->
<div class="modal-backdrop" id="deleteModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold text-red-400"><i class="fas fa-trash mr-2"></i>Delete record</h2>
      <button onclick="closeModal('deleteModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <p class="text-gray-300 text-sm mb-5">
      Delete WHOIS lookup for <span class="font-mono text-white" id="del-domain"></span>?
      This only removes the cached record — the user can look it up again.
    </p>
    <form method="POST" class="flex gap-3 justify-end">
      <input type="hidden" name="action"    value="delete">
      <input type="hidden" name="lookup_id" id="del-id">
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

let currentRaw = '';

// ── Detail modal ──────────────────────────────────────────
function openDetailModal(lk) {
  const fmt  = d => d ? new Date(d).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) : '—';
  const fmtTs= d => d ? new Date(d).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';

  // Header
  document.getElementById('dm-domain').textContent = lk.domain_name;
  const badgeArea = document.getElementById('dm-badges');
  const srcColors = {api:'b-api', socket:'b-socket', cache:'b-cache'};
  const availBadge = lk.is_available
    ? '<span class="badge b-avail"><i class="fas fa-check text-xs"></i> Available</span>'
    : '<span class="badge b-taken"><i class="fas fa-lock text-xs"></i> Registered</span>';
  badgeArea.innerHTML = availBadge +
    `<span class="badge ${srcColors[lk.source]||'b-cache'}"><i class="fas fa-plug text-xs"></i> ${esc(lk.source)}</span>` +
    `<span class="text-gray-500 text-xs font-mono">#${lk.id}</span>`;

  // Info grid
  const fields = [
    {l:'Registrar',      v:esc(lk.registrar||'—')},
    {l:'Created',        v:fmt(lk.created_date)},
    {l:'Expiry',         v:fmt(lk.expiry_date)},
    {l:'Updated',        v:fmt(lk.updated_date)},
    {l:'DNSSEC',         v:esc(lk.dnssec||'—')},
    {l:'Credits used',   v:(lk.credits_spent||3)+' cr'},
    {l:'Registrant',     v:esc(lk.registrant_name||'Redacted')},
    {l:'Organisation',   v:esc(lk.registrant_org||'—')},
    {l:'Country',        v:esc(lk.registrant_country||'—')},
    {l:'User',           v:'#'+lk.user_id+' · '+esc(lk.email)},
    {l:'TLD',            v:'.'+esc(lk.tld)},
    {l:'Looked up',      v:fmtTs(lk.looked_up_at)},
  ];
  document.getElementById('dm-grid').innerHTML = fields.map(f => `
    <div>
      <div class="text-gray-500 uppercase tracking-wide mb-0.5" style="font-size:.6rem;">${f.l}</div>
      <div class="font-mono text-gray-200" style="font-size:.75rem;word-break:break-all;">${f.v}</div>
    </div>`).join('');

  // Nameservers
  const nsSection = document.getElementById('dm-ns-section');
  const nsEl      = document.getElementById('dm-ns');
  let ns = [];
  try { ns = typeof lk.nameservers === 'string' ? JSON.parse(lk.nameservers) : (lk.nameservers||[]); } catch {}
  if (ns && ns.length > 0) {
    nsEl.innerHTML = ns.map(n => `<span class="ns-pill">${esc(n)}</span>`).join('');
    nsSection.classList.remove('hidden');
  } else { nsSection.classList.add('hidden'); }

  // Status codes
  const stSection = document.getElementById('dm-status-section');
  const stEl      = document.getElementById('dm-status');
  const statuses  = lk.status ? lk.status.split(' ').filter(Boolean) : [];
  if (statuses.length > 0) {
    stEl.innerHTML = statuses.map(s => `<span class="ns-pill">${esc(s)}</span>`).join('');
    stSection.classList.remove('hidden');
  } else { stSection.classList.add('hidden'); }

  // Raw
  currentRaw = lk.raw_response || '';
  const rawSection = document.getElementById('dm-raw-section');
  const rawEl      = document.getElementById('dm-raw');
  if (currentRaw) {
    rawEl.textContent = currentRaw;
    rawSection.classList.remove('hidden');
    rawEl.classList.add('hidden'); // collapsed by default
    document.getElementById('rawChevron').style.transform = '';
  } else {
    rawSection.classList.add('hidden');
  }

  openModal('detailModal');
}

function toggleRaw() {
  const rawEl   = document.getElementById('dm-raw');
  const chevron = document.getElementById('rawChevron');
  const isOpen  = !rawEl.classList.contains('hidden');
  rawEl.classList.toggle('hidden', isOpen);
  chevron.style.transform = isOpen ? '' : 'rotate(90deg)';
}

function copyFullWhois() {
  if (!currentRaw) { showToast('No raw data available.', 'warn'); return; }
  navigator.clipboard.writeText(currentRaw)
    .then(() => showToast('Raw WHOIS copied to clipboard.'))
    .catch(()  => showToast('Could not copy.', 'err'));
}

// ── Delete confirm ────────────────────────────────────────
function confirmDelete(id, domain) {
  document.getElementById('del-id').value         = id;
  document.getElementById('del-domain').textContent = domain;
  openModal('deleteModal');
}

// ── Bulk ──────────────────────────────────────────────────
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

function bulkDelete() {
  const ids = [...document.querySelectorAll('.row-chk:checked')].map(c => c.value);
  if (!ids.length) return;
  if (!confirm(`Delete ${ids.length} WHOIS record(s)?`)) return;

  const form = document.getElementById('bulkForm');
  let ai = form.querySelector('input[name="action"]');
  if (!ai) { ai = document.createElement('input'); ai.type='hidden'; ai.name='action'; form.appendChild(ai); }
  ai.value = 'bulk_delete';
  ids.forEach(id => {
    const inp = document.createElement('input');
    inp.type='hidden'; inp.name='selected_ids[]'; inp.value=id;
    form.appendChild(inp);
  });
  form.submit();
}

// ── Copy text ─────────────────────────────────────────────
function copyText(text) {
  navigator.clipboard.writeText(text)
    .then(() => showToast('Copied: ' + text))
    .catch(() => showToast('Could not copy', 'err'));
}

// ── Toast ─────────────────────────────────────────────────
function showToast(msg, type = 'ok') {
  const t    = document.getElementById('toast');
  const icon = document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  const c = {ok:'#10B981', warn:'#F59E0B', err:'#EF4444'};
  const i = {ok:'fa-check-circle', warn:'fa-exclamation-triangle', err:'fa-times-circle'};
  icon.className   = 'fas ' + (i[type] || 'fa-info-circle');
  icon.style.color = c[type] || '#10B981';
  t.style.transform = 'translateY(0)'; t.style.opacity = '1';
  clearTimeout(t._t);
  t._t = setTimeout(() => { t.style.transform = 'translateY(20px)'; t.style.opacity = '0'; }, 4000);
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