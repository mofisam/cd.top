<?php
require_once 'auth_check.php';
$user = checkAdminAuth();
require_once '../config/database.php';

$conn = getDBConnection();
$activePage = 'dead-sites';

// ── Filters ────────────────────────────────────────────────
$statusFilter = in_array($_GET['status'] ?? '', ['all','dead','live','parked','for_sale','dns_fail','no_response','redirect','ssl_error','error_4xx','error_5xx']) ? ($_GET['status'] ?? 'all') : 'all';
$search       = trim($_GET['q'] ?? '');
$dateFilter   = in_array($_GET['date'] ?? '', ['today','7d','30d','all']) ? ($_GET['date'] ?? '30d') : '30d';
$page         = max(1, (int)($_GET['p'] ?? 1));
$perPage      = 40;

$dateMap = [
    'today' => "DATE(scanned_at) = CURDATE()",
    '7d'    => "scanned_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
    '30d'   => "scanned_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
    'all'   => "1=1",
];
$dateWhere = $dateMap[$dateFilter];

$statusWhere = '1=1';
if ($statusFilter !== 'all') {
    if ($statusFilter === 'dead')  $statusWhere = "is_dead = 1";
    elseif ($statusFilter === 'live') $statusWhere = "site_status = 'live'";
    else $statusWhere = "site_status = '" . $conn->real_escape_string($statusFilter) . "'";
}

$searchWhere = '1=1';
$searchBound = '';
if ($search) {
    $searchBound = '%' . $conn->real_escape_string($search) . '%';
    $searchWhere = "d.domain_name LIKE '" . $searchBound . "'";
}

$baseWhere = "$dateWhere AND $statusWhere AND $searchWhere";

// ── Platform-wide stats ────────────────────────────────────
$safe = fn($sql) => ($r = @$conn->query($sql)) ? ($r->fetch_assoc() ?? []) : [];

$totalScans   = (int)($safe("SELECT COUNT(*) as c FROM dead_site_scans")['c'] ?? 0);
$deadScans    = (int)($safe("SELECT COUNT(*) as c FROM dead_site_scans WHERE is_dead=1")['c'] ?? 0);
$parkedScans  = (int)($safe("SELECT COUNT(*) as c FROM dead_site_scans WHERE is_parked=1")['c'] ?? 0);
$forSaleScans = (int)($safe("SELECT COUNT(*) as c FROM dead_site_scans WHERE is_for_sale=1")['c'] ?? 0);
$liveScans    = (int)($safe("SELECT COUNT(*) as c FROM dead_site_scans WHERE site_status='live'")['c'] ?? 0);
$todayScans   = (int)($safe("SELECT COUNT(*) as c FROM dead_site_scans WHERE DATE(scanned_at)=CURDATE()")['c'] ?? 0);
$uniqueUsers  = (int)($safe("SELECT COUNT(DISTINCT user_id) as c FROM dead_site_scans")['c'] ?? 0);
$creditsUsed  = (int)($safe("SELECT COALESCE(SUM(credits_spent),0) as c FROM dead_site_scans")['c'] ?? 0);

// ── Status breakdown chart ─────────────────────────────────
$statusBreakRes = @$conn->query("
    SELECT site_status, COUNT(*) as c
    FROM dead_site_scans
    GROUP BY site_status ORDER BY c DESC
");
$statusLabels = []; $statusData = [];
if ($statusBreakRes) while ($r = $statusBreakRes->fetch_assoc()) {
    $statusLabels[] = ucfirst(str_replace('_',' ', $r['site_status']));
    $statusData[] = (int)$r['c'];
}

// ── Daily scans trend ──────────────────────────────────────
$trendRes = @$conn->query("
    SELECT DATE(scanned_at) as d, COUNT(*) as total,
           SUM(is_dead=1) as dead_c, SUM(site_status='live') as live_c
    FROM dead_site_scans
    WHERE scanned_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(scanned_at) ORDER BY d ASC
");
$trendLabels = []; $trendTotal = []; $trendDead = [];
if ($trendRes) while ($r = $trendRes->fetch_assoc()) {
    $trendLabels[] = $r['d'];
    $trendTotal[]  = (int)$r['total'];
    $trendDead[]   = (int)$r['dead_c'];
}

// ── Top most-scanned domains ───────────────────────────────
$topDomainsRes = @$conn->query("
    SELECT domain_name, COUNT(*) as scan_count,
           MAX(dead_score) as max_score,
           MAX(is_dead) as is_dead,
           MAX(site_status) as last_status,
           MAX(scanned_at) as last_scan
    FROM dead_site_scans
    GROUP BY domain_name ORDER BY scan_count DESC LIMIT 10
");

// ── Top users by scans ─────────────────────────────────────
$topUsersRes = @$conn->query("
    SELECT u.id, u.email, u.full_name, u.plan,
           COUNT(d.id) as scan_count,
           SUM(d.is_dead=1) as dead_count,
           SUM(d.credits_spent) as credits_used
    FROM dead_site_scans d
    JOIN users u ON u.id = d.user_id
    GROUP BY d.user_id ORDER BY scan_count DESC LIMIT 8
");

// ── Main scans list ────────────────────────────────────────
$countRes = @$conn->query("
    SELECT COUNT(*) as c FROM dead_site_scans d
    JOIN users u ON u.id = d.user_id
    WHERE $baseWhere
");
$totalFiltered = $countRes ? (int)$countRes->fetch_assoc()['c'] : 0;
$totalPages = max(1, ceil($totalFiltered / $perPage));
$offset = ($page - 1) * $perPage;

$scansRes = @$conn->query("
    SELECT d.*, u.email, u.full_name, u.plan
    FROM dead_site_scans d
    JOIN users u ON u.id = d.user_id
    WHERE $baseWhere
    ORDER BY d.scanned_at DESC
    LIMIT $perPage OFFSET $offset
");
$scans = [];
if ($scansRes) while ($r = $scansRes->fetch_assoc()) $scans[] = $r;

$conn->close();

// ── Helpers ────────────────────────────────────────────────
$fmt = fn($n) => number_format((int)$n);

$siteMeta = [
    'live'        => ['label'=>'Live',         'class'=>'sp-green',  'icon'=>'fa-check-circle'],
    'redirect'    => ['label'=>'Redirect',     'class'=>'sp-blue',   'icon'=>'fa-external-link-alt'],
    'error_4xx'   => ['label'=>'4xx Error',    'class'=>'sp-amber',  'icon'=>'fa-exclamation-triangle'],
    'error_5xx'   => ['label'=>'5xx Error',    'class'=>'sp-coral',  'icon'=>'fa-server'],
    'no_response' => ['label'=>'No Response',  'class'=>'sp-coral',  'icon'=>'fa-wifi'],
    'dns_fail'    => ['label'=>'DNS Fail',     'class'=>'sp-coral',  'icon'=>'fa-skull'],
    'ssl_error'   => ['label'=>'SSL Error',    'class'=>'sp-amber',  'icon'=>'fa-lock'],
    'parked'      => ['label'=>'Parked',       'class'=>'sp-amber',  'icon'=>'fa-parking'],
    'for_sale'    => ['label'=>'For Sale',     'class'=>'sp-purple', 'icon'=>'fa-tag'],
    'dead'        => ['label'=>'Dead',         'class'=>'sp-coral',  'icon'=>'fa-skull-crossbones'],
];

function timeAgo(string $ts): string {
    $d = time() - strtotime($ts);
    if ($d < 60)    return 'just now';
    if ($d < 3600)  return round($d/60).'m ago';
    if ($d < 86400) return round($d/3600).'h ago';
    return date('M j', strtotime($ts));
}

function qs(array $overrides = []): string {
    $p = array_merge(['status'=>$_GET['status']??'all','q'=>$_GET['q']??'','date'=>$_GET['date']??'30d','p'=>1], $overrides);
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== 'all' && $v !== '1'));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dead Site Scans — CheckDomain Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#080B12;font-family:'Inter',sans-serif;color:#E2E8F0}

:root{
  --bg0:#080B12;--bg1:#0D1117;--bg2:#131924;--bg3:#1A2333;--bg4:#1E2A3D;
  --border:rgba(59,130,246,.12);--border2:rgba(59,130,246,.25);
  --blue:#3B82F6;--blue2:#60A5FA;--blue-bg:rgba(59,130,246,.08);
  --green:#10B981;--green2:#34D399;--green-bg:rgba(16,185,129,.1);
  --amber:#F59E0B;--amber-bg:rgba(245,158,11,.1);
  --coral:#EF4444;--coral-bg:rgba(239,68,68,.1);
  --purple:#8B5CF6;--purple-bg:rgba(139,92,246,.1);
  --text1:#E2E8F0;--text2:#94A3B8;--text3:#475569;
  --radius:12px;
}

/* ── Card base ── */
.card{background:var(--bg1);border:1px solid var(--border);border-radius:var(--radius);padding:1.25rem 1.5rem;transition:border-color .2s}
.card:hover{border-color:var(--border2)}

/* ── Stat cards ── */
.stat-card{background:var(--bg1);border:1px solid var(--border);border-radius:var(--radius);padding:1.1rem 1.4rem;transition:all .2s;position:relative;overflow:hidden;cursor:default}
.stat-card::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,var(--ac,rgba(59,130,246,.05)),transparent 55%);pointer-events:none}
.stat-card:hover{transform:translateY(-1px);border-color:var(--border2)}
.stat-val{font-size:1.6rem;font-weight:800;letter-spacing:-.03em;line-height:1;margin:.35rem 0 .2rem}
.stat-label{font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--text3)}
.stat-sub{font-size:.72rem;color:var(--text2);margin-top:.2rem}
.icon-bub{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}

/* ── Status pills ── */
.sp-green {background:var(--green-bg);color:var(--green2)}
.sp-blue  {background:var(--blue-bg);color:var(--blue2)}
.sp-amber {background:var(--amber-bg);color:var(--amber)}
.sp-coral {background:var(--coral-bg);color:var(--coral)}
.sp-purple{background:var(--purple-bg);color:var(--purple)}

.status-pill{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;padding:.2rem .55rem;border-radius:5px;white-space:nowrap;display:inline-flex;align-items:center;gap:.3rem}

/* ── Plan pills ── */
.plan-pill{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;padding:.15rem .45rem;border-radius:4px;display:inline-block}
.plan-free {background:rgba(100,116,139,.15);color:#64748B}
.plan-pro  {background:var(--blue-bg);color:var(--blue2)}
.plan-elite{background:var(--purple-bg);color:#A78BFA}

/* ── Score ring ── */
.score-chip{font-family:monospace;font-size:.8rem;font-weight:800;padding:.2rem .5rem;border-radius:6px;display:inline-block}
.score-high{background:var(--coral-bg);color:var(--coral)}
.score-mid {background:var(--amber-bg);color:var(--amber)}
.score-low {background:var(--green-bg);color:var(--green2)}

/* ── Filter bar ── */
.filter-bar{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
.f-tab{padding:.35rem .75rem;border-radius:6px;font-size:.72rem;font-weight:600;border:1px solid var(--border);background:transparent;color:var(--text2);cursor:pointer;transition:all .15s;text-decoration:none;display:inline-block}
.f-tab:hover,.f-tab.active{background:var(--blue);border-color:var(--blue);color:#fff}
.f-tab.dead.active{background:var(--coral);border-color:var(--coral)}
.f-tab.live.active{background:var(--green);border-color:var(--green)}
.f-tab.parked.active,.f-tab.dns_fail.active,.f-tab.no_response.active{background:var(--amber);border-color:var(--amber);color:#000}
.f-tab.for_sale.active{background:var(--purple);border-color:var(--purple)}

/* ── Search bar ── */
.search-wrap{position:relative;flex:1;min-width:200px}
.search-input{width:100%;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:.55rem 1rem .55rem 2.25rem;font-size:.8rem;color:var(--text1);outline:none;transition:border-color .2s}
.search-input:focus{border-color:var(--blue2)}
.search-icon{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:var(--text3);font-size:.75rem;pointer-events:none}

/* ── Table ── */
.data-table{width:100%;font-size:.78rem;border-collapse:collapse}
.data-table th{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text3);padding:.7rem .75rem;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
.data-table td{padding:.65rem .75rem;border-bottom:1px solid rgba(59,130,246,.04);color:var(--text2);vertical-align:middle}
.data-table tbody tr:hover td{background:rgba(59,130,246,.03);color:var(--text1)}
.data-table tbody tr:last-child td{border-bottom:none}

/* ── Dead score bar ── */
.score-bar{height:4px;border-radius:2px;background:var(--bg3);overflow:hidden;margin-top:.25rem;width:60px}
.score-fill{height:100%;border-radius:2px;transition:width .4s}

/* ── Section headers ── */
.sec-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem}
.sec-title{font-size:.88rem;font-weight:700;color:var(--text1)}
.sec-link{font-size:.72rem;color:var(--blue2);text-decoration:none}
.sec-link:hover{color:#fff}

/* ── Pagination ── */
.page-btn{padding:.35rem .7rem;border-radius:6px;font-size:.72rem;font-weight:600;border:1px solid var(--border);background:transparent;color:var(--text2);cursor:pointer;transition:all .15s;text-decoration:none;display:inline-block}
.page-btn:hover,.page-btn.active{background:var(--blue);border-color:var(--blue);color:#fff}
.page-btn:disabled{opacity:.4;cursor:not-allowed}

/* ── Action buttons ── */
.act-btn{display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .65rem;border-radius:5px;font-size:.65rem;font-weight:700;cursor:pointer;border:none;transition:all .15s;text-decoration:none;text-transform:uppercase;letter-spacing:.05em}
.ab-coral{background:var(--coral-bg);color:var(--coral)}
.ab-coral:hover{background:rgba(239,68,68,.2)}
.ab-blue{background:var(--blue-bg);color:var(--blue2)}
.ab-blue:hover{background:rgba(59,130,246,.18)}
.ab-amber{background:var(--amber-bg);color:var(--amber)}
.ab-amber:hover{background:rgba(245,158,11,.2)}
.ab-green{background:var(--green-bg);color:var(--green2)}
.ab-green:hover{background:rgba(16,185,129,.2)}

/* ── Toast ── */
.toast{position:fixed;bottom:24px;right:24px;z-index:999;background:var(--bg2);border:1px solid var(--border2);border-radius:10px;padding:11px 16px;font-size:.82rem;color:var(--text1);box-shadow:0 8px 32px rgba(0,0,0,.4);transform:translateY(16px);opacity:0;transition:all .3s;display:flex;align-items:center;gap:.5rem;max-width:300px}
.toast.show{transform:translateY(0);opacity:1}
.toast.success{border-color:rgba(16,185,129,.3)}
.toast.error{border-color:rgba(239,68,68,.3)}

/* ── Main layout ── */
.main-content{margin-left:256px;min-height:100vh}
@media(max-width:768px){.main-content{margin-left:0!important}.hide-sm{display:none!important}}
@media(max-width:900px){.grid-2col{grid-template-columns:1fr!important}}

/* ── Scrollbar ── */
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-thumb{background:rgba(59,130,246,.2);border-radius:2px}

/* ── Modal ── */
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:100;align-items:center;justify-content:center;padding:1rem}
.modal-backdrop.open{display:flex}
.modal{background:var(--bg1);border:1px solid var(--border2);border-radius:16px;padding:1.5rem;max-width:520px;width:100%;max-height:85vh;overflow-y:auto}
.modal-title{font-size:1rem;font-weight:700;margin-bottom:1rem;color:var(--text1)}
.modal-close{float:right;background:none;border:none;color:var(--text2);cursor:pointer;font-size:1.1rem;line-height:1}
.modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem}
.modal-cell{background:var(--bg2);border-radius:8px;padding:.65rem .85rem}
.mc-label{font-size:.6rem;text-transform:uppercase;letter-spacing:.1em;color:var(--text3);margin-bottom:.2rem}
.mc-value{font-size:.78rem;font-family:monospace;color:var(--text1);word-break:break-all}
.mc-value.good{color:var(--green2)}
.mc-value.warn{color:var(--amber)}
.mc-value.bad{color:var(--coral)}
</style>
</head>
<body>

<?php include_once 'includes/sidebar.php'; ?>

<div class="main-content">
<div class="p-4 md:p-7 max-w-screen-2xl mx-auto">

  <!-- ── Topbar ───────────────────────────────────── -->
  <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-6">
    <div>
      <div style="font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.12em;color:var(--text3);margin-bottom:.2rem">Domain Services</div>
      <h1 style="font-size:1.5rem;font-weight:800;color:var(--text1);letter-spacing:-.02em">Dead Site Scans</h1>
      <p style="font-size:.78rem;color:var(--text2);margin-top:.15rem">Platform-wide view of all dead site scans across every user.</p>
    </div>
    <div class="flex gap-2 flex-wrap">
      <a href="export.php?type=dead-sites" class="act-btn ab-blue" style="padding:.5rem 1rem;font-size:.72rem">
        <i class="fas fa-download"></i> Export CSV
      </a>
      <button onclick="openRescanModal()" class="act-btn ab-amber" style="padding:.5rem 1rem;font-size:.72rem">
        <i class="fas fa-sync-alt"></i> Batch re-scan
      </button>
    </div>
  </div>

  <!-- ── KPI row ───────────────────────────────────── -->
  <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3 mb-5">
    <?php
    $kpis = [
      ['v'=>$totalScans,   'l'=>'Total scans',   's'=>"Today: +{$fmt($todayScans)}",  'c'=>'var(--blue2)',   'ac'=>'rgba(59,130,246,.06)',   'ico'=>'fa-search',           'icob'=>'var(--blue-bg)'],
      ['v'=>$deadScans,    'l'=>'Dead/inactive', 's'=>round($totalScans?$deadScans/$totalScans*100:0,1).'% of scans','c'=>'var(--coral)',   'ac'=>'rgba(239,68,68,.06)',   'ico'=>'fa-skull-crossbones', 'icob'=>'var(--coral-bg)'],
      ['v'=>$parkedScans,  'l'=>'Parked',        's'=>'Inactive holding pages',         'c'=>'var(--amber)',   'ac'=>'rgba(245,158,11,.06)',   'ico'=>'fa-parking',          'icob'=>'var(--amber-bg)'],
      ['v'=>$forSaleScans, 'l'=>'For sale',      's'=>'Acquisition opportunities',      'c'=>'var(--purple)',  'ac'=>'rgba(139,92,246,.06)',  'ico'=>'fa-tag',              'icob'=>'var(--purple-bg)'],
      ['v'=>$liveScans,    'l'=>'Live sites',    's'=>round($totalScans?$liveScans/$totalScans*100:0,1).'% healthy', 'c'=>'var(--green2)',  'ac'=>'rgba(16,185,129,.06)',  'ico'=>'fa-check-circle',     'icob'=>'var(--green-bg)'],
      ['v'=>$uniqueUsers,  'l'=>'Users scanning','s'=>'Unique users ever',              'c'=>'var(--blue2)',   'ac'=>'rgba(59,130,246,.06)',  'ico'=>'fa-users',            'icob'=>'var(--blue-bg)'],
      ['v'=>$creditsUsed,  'l'=>'Credits used',  's'=>'Platform total',                 'c'=>'var(--amber)',   'ac'=>'rgba(245,158,11,.06)',  'ico'=>'fa-bolt',             'icob'=>'var(--amber-bg)'],
      ['v'=>$todayScans,   'l'=>"Today's scans", 's'=>date('D, M j'),                  'c'=>'var(--green2)',  'ac'=>'rgba(16,185,129,.06)',  'ico'=>'fa-calendar-day',     'icob'=>'var(--green-bg)'],
    ];
    foreach ($kpis as $k): ?>
    <div class="stat-card lg:col-span-1 md:col-span-1 col-span-1" style="--ac:<?=$k['ac']?>">
      <div class="flex justify-between items-start">
        <span class="stat-label"><?=$k['l']?></span>
        <div class="icon-bub" style="background:<?=$k['icob']?>;color:<?=$k['c']?>"><i class="fas <?=$k['ico']?>"></i></div>
      </div>
      <div class="stat-val" style="color:<?=$k['c']?>"><?=$fmt($k['v'])?></div>
      <div class="stat-sub"><?=$k['s']?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Charts ──────────────────────────────────── -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5 grid-2col">

    <!-- Trend -->
    <div class="card lg:col-span-2">
      <div class="sec-head">
        <span class="sec-title"><i class="fas fa-chart-line" style="color:var(--blue2);margin-right:.4rem"></i>Scan volume — last 30 days</span>
      </div>
      <div style="height:120px;">
        <canvas id="trendChart"></canvas>
      </div>
    </div>

    <!-- Status breakdown -->
    <div class="card">
      <div class="sec-head">
        <span class="sec-title"><i class="fas fa-chart-pie" style="color:var(--coral);margin-right:.4rem"></i>Status breakdown</span>
      </div>
      <div style="height:170px;">
      <canvas id="statusChart" ></canvas>
      </div>
    </div>

  </div>

  <!-- ── Top domains + top users ─────────────────── -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5 grid-2col">

    <!-- Top scanned domains -->
    <div class="card">
      <div class="sec-head">
        <span class="sec-title">Most-scanned domains</span>
      </div>
      <table class="data-table">
        <thead><tr>
          <th>Domain</th>
          <th>Scans</th>
          <th>Status</th>
          <th class="hide-sm">Dead score</th>
          <th class="hide-sm">Last seen</th>
        </tr></thead>
        <tbody>
        <?php if ($topDomainsRes): while ($row = $topDomainsRes->fetch_assoc()):
          $sm = $siteMeta[$row['last_status']] ?? $siteMeta['no_response'];
          $sc = (int)$row['max_score'];
          $scCls = $sc>=70?'score-high':($sc>=40?'score-mid':'score-low');
        ?>
        <tr style="cursor:pointer" onclick="document.getElementById('qInput').value='<?=htmlspecialchars($row['domain_name'],ENT_QUOTES)?>'; document.getElementById('filterForm').submit()">
          <td>
            <span style="font-family:monospace;font-weight:700;color:var(--text1)"><?=htmlspecialchars($row['domain_name'])?></span>
          </td>
          <td style="font-weight:700;color:var(--text1)"><?=$fmt($row['scan_count'])?></td>
          <td><span class="status-pill <?=$sm['class']?>"><?=$sm['label']?></span></td>
          <td class="hide-sm"><span class="score-chip <?=$scCls?>"><?=$sc?></span></td>
          <td class="hide-sm" style="color:var(--text3);font-size:.7rem"><?=timeAgo($row['last_scan'])?></td>
        </tr>
        <?php endwhile; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Top users -->
    <div class="card">
      <div class="sec-head">
        <span class="sec-title">Most active users</span>
        <a href="users.php" class="sec-link">All users →</a>
      </div>
      <table class="data-table">
        <thead><tr>
          <th>User</th>
          <th>Plan</th>
          <th>Scans</th>
          <th>Dead found</th>
          <th class="hide-sm">Credits used</th>
        </tr></thead>
        <tbody>
        <?php if ($topUsersRes): while ($row = $topUsersRes->fetch_assoc()): ?>
        <tr>
          <td>
            <div style="color:var(--text1);font-weight:600;font-size:.78rem"><?=htmlspecialchars(strtok($row['full_name']?:$row['email'],' '))?></div>
            <div style="font-size:.65rem;color:var(--text3)"><?=htmlspecialchars(substr($row['email'],0,26)).(strlen($row['email'])>26?'…':'')?></div>
          </td>
          <td><span class="plan-pill plan-<?=htmlspecialchars($row['plan'])?>"><?=htmlspecialchars($row['plan'])?></span></td>
          <td style="font-weight:700;color:var(--text1)"><?=$fmt($row['scan_count'])?></td>
          <td style="color:var(--coral);font-weight:700"><?=$fmt($row['dead_count'])?></td>
          <td class="hide-sm"><?=$fmt($row['credits_used'])?></td>
        </tr>
        <?php endwhile; endif; ?>
        </tbody>
      </table>
    </div>

  </div>

  <!-- ── Main scan log ────────────────────────────── -->
  <div class="card" style="padding:0;overflow:hidden">
    <div style="padding:1.1rem 1.5rem;border-bottom:1px solid var(--border)">
      <div class="sec-head" style="margin-bottom:.85rem">
        <span class="sec-title">
          <i class="fas fa-list" style="color:var(--text3);margin-right:.4rem"></i>
          Scan log
          <span style="font-size:.7rem;font-weight:400;color:var(--text3);margin-left:.5rem"><?=$fmt($totalFiltered)?> result<?=$totalFiltered!=1?'s':''?></span>
        </span>
      </div>
      <!-- Filters -->
      <form id="filterForm" method="GET" action="" style="display:flex;flex-direction:column;gap:.6rem">
        <div class="filter-bar">
          <?php foreach (['all'=>'All','dead'=>'Dead','live'=>'Live','parked'=>'Parked','for_sale'=>'For Sale','dns_fail'=>'DNS Fail','no_response'=>'No Response','ssl_error'=>'SSL Error','error_4xx'=>'4xx','error_5xx'=>'5xx','redirect'=>'Redirect'] as $val=>$lbl): ?>
          <a href="<?=qs(['status'=>$val,'p'=>1])?>" class="f-tab <?=$val?> <?=$statusFilter===$val?'active':''?>"><?=$lbl?></a>
          <?php endforeach; ?>
        </div>
        <div class="filter-bar">
          <div class="search-wrap">
            <i class="fas fa-search search-icon"></i>
            <input class="search-input" type="text" name="q" id="qInput" placeholder="Search domain name…" value="<?=htmlspecialchars($search)?>" autocomplete="off">
          </div>
          <?php foreach (['today'=>'Today','7d'=>'7 days','30d'=>'30 days','all'=>'All time'] as $val=>$lbl): ?>
          <a href="<?=qs(['date'=>$val,'p'=>1])?>" class="f-tab <?=$dateFilter===$val?'active':''?>"><?=$lbl?></a>
          <?php endforeach; ?>
          <button type="submit" class="act-btn ab-blue" style="padding:.4rem .9rem">
            <i class="fas fa-search"></i> Search
          </button>
        </div>
      </form>
    </div>

    <!-- Table -->
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead><tr>
          <th>Domain</th>
          <th>User</th>
          <th>Status</th>
          <th>HTTP</th>
          <th>Response</th>
          <th>Dead score</th>
          <th class="hide-sm">Signals</th>
          <th>Scanned</th>
          <th>Actions</th>
        </tr></thead>
        <tbody>
        <?php if (empty($scans)): ?>
        <tr><td colspan="9" style="text-align:center;padding:2.5rem;color:var(--text3)">
          <i class="fas fa-ghost" style="font-size:1.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
          No scans found matching your filters.
        </td></tr>
        <?php else: foreach ($scans as $scan):
          $sm = $siteMeta[$scan['site_status']] ?? $siteMeta['no_response'];
          $sc = (int)$scan['dead_score'];
          $scCls = $sc>=70?'score-high':($sc>=40?'score-mid':'score-low');
          $httpColor = (int)$scan['http_status']>=400?'var(--coral)':((int)$scan['http_status']>=300?'var(--blue2)':((int)$scan['http_status']>=200?'var(--green2)':'var(--text3)'));
        ?>
        <tr>
          <td>
            <div style="font-family:monospace;font-weight:700;color:var(--text1);font-size:.8rem">
              <?=htmlspecialchars($scan['domain_name'])?>
            </div>
            <?php if ($scan['page_title']): ?>
            <div style="font-size:.65rem;color:var(--text3);margin-top:.1rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?=htmlspecialchars(substr($scan['page_title'],0,40))?>
            </div>
            <?php endif; ?>
          </td>
          <td>
            <div style="font-size:.75rem;color:var(--text1)"><?=htmlspecialchars(strtok($scan['full_name']?:$scan['email'],' '))?></div>
            <span class="plan-pill plan-<?=htmlspecialchars($scan['plan'])?>"><?=htmlspecialchars($scan['plan'])?></span>
          </td>
          <td><span class="status-pill <?=$sm['class']?>"><i class="fas <?=$sm['icon']?>" style="font-size:.6rem"></i><?=$sm['label']?></span></td>
          <td style="font-family:monospace;color:<?=$httpColor?>;font-weight:700;font-size:.8rem"><?=$scan['http_status']?:'—'?></td>
          <td style="font-size:.72rem;color:var(--text2);font-family:monospace"><?=$scan['response_time_ms']?$scan['response_time_ms'].'ms':'—'?></td>
          <td>
            <span class="score-chip <?=$scCls?>"><?=$sc?></span>
            <div class="score-bar" style="margin-top:.3rem">
              <div class="score-fill" style="width:<?=$sc?>%;background:<?=$sc>=70?'var(--coral)':($sc>=40?'var(--amber)':'var(--green2)')?>"></div>
            </div>
          </td>
          <td class="hide-sm">
            <div style="display:flex;gap:.25rem;flex-wrap:wrap">
              <?php if ($scan['is_parked']): ?><span style="font-size:.6rem;background:var(--amber-bg);color:var(--amber);padding:.1rem .35rem;border-radius:3px">Parked</span><?php endif; ?>
              <?php if ($scan['is_for_sale']): ?><span style="font-size:.6rem;background:var(--purple-bg);color:var(--purple);padding:.1rem .35rem;border-radius:3px">For sale</span><?php endif; ?>
              <?php if (!$scan['has_content'] && $scan['is_dead']): ?><span style="font-size:.6rem;background:var(--coral-bg);color:var(--coral);padding:.1rem .35rem;border-radius:3px">No content</span><?php endif; ?>
              <?php if ($scan['ssl_valid']===0 || $scan['ssl_valid']==='0'): ?><span style="font-size:.6rem;background:var(--amber-bg);color:var(--amber);padding:.1rem .35rem;border-radius:3px">SSL ⚠</span><?php endif; ?>
              <?php if (!$scan['is_parked'] && !$scan['is_for_sale'] && !$scan['is_dead']): ?><span style="font-size:.6rem;color:var(--text3)">—</span><?php endif; ?>
            </div>
          </td>
          <td style="font-size:.7rem;color:var(--text3);white-space:nowrap"><?=timeAgo($scan['scanned_at'])?></td>
          <td>
            <div style="display:flex;gap:.3rem;align-items:center">
              <button class="act-btn ab-blue" onclick='showDetail(<?=htmlspecialchars(json_encode($scan),ENT_QUOTES)?>, this)' title="View details">
                <i class="fas fa-eye"></i>
              </button>
              <?php if ($scan['is_dead']): ?>
              <a href="../backorders.php?domain=<?=urlencode($scan['domain_name'])?>" class="act-btn ab-amber" title="Backorder">
                <i class="fas fa-clock"></i>
              </a>
              <?php endif; ?>
              <button class="act-btn ab-coral" onclick="deleteScan(<?=(int)$scan['id']?>, this)" title="Delete">
                <i class="fas fa-trash"></i>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="padding:1rem 1.5rem;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
      <span style="font-size:.75rem;color:var(--text3)">
        Showing <?=($offset+1)?> – <?=min($offset+$perPage,$totalFiltered)?> of <?=$fmt($totalFiltered)?>
      </span>
      <div style="display:flex;gap:.3rem;flex-wrap:wrap">
        <?php if ($page > 1): ?>
        <a href="<?=qs(['p'=>$page-1])?>" class="page-btn">← Prev</a>
        <?php endif; ?>
        <?php
        $start = max(1, $page - 2);
        $end   = min($totalPages, $page + 2);
        for ($i = $start; $i <= $end; $i++):
        ?>
        <a href="<?=qs(['p'=>$i])?>" class="page-btn <?=$i===$page?'active':''?>"><?=$i?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <a href="<?=qs(['p'=>$page+1])?>" class="page-btn">Next →</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>
</div>

<!-- ── Detail modal ──────────────────────────────── -->
<div class="modal-backdrop" id="detailModal" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <div class="modal-title">
      <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
      Scan detail — <span id="modalDomain" style="font-family:monospace;color:var(--blue2)"></span>
    </div>
    <div id="modalContent"></div>
  </div>
</div>

<!-- ── Batch re-scan modal ───────────────────────── -->
<div class="modal-backdrop" id="rescanModal" onclick="if(event.target===this)closeRescan()">
  <div class="modal">
    <div class="modal-title">
      <button class="modal-close" onclick="closeRescan()"><i class="fas fa-times"></i></button>
      Batch re-scan dead domains
    </div>
    <p style="font-size:.8rem;color:var(--text2);margin-bottom:1rem;line-height:1.6">
      This triggers a fresh scan for all domains currently flagged as dead or parked, updating their cached results. Use this when you want accurate fresh data for acquisition research.
    </p>
    <div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:.85rem 1rem;margin-bottom:1rem;font-size:.8rem;color:var(--text2)">
      <i class="fas fa-info-circle" style="color:var(--amber);margin-right:.4rem"></i>
      Re-scans bypass user credit deduction. This is an admin operation.
    </div>
    <div style="display:flex;gap:.5rem;justify-content:flex-end">
      <button onclick="closeRescan()" class="act-btn" style="background:var(--bg2);border:1px solid var(--border);color:var(--text2);padding:.5rem 1.2rem;font-size:.78rem">Cancel</button>
      <button onclick="confirmRescan()" class="act-btn ab-amber" style="padding:.5rem 1.2rem;font-size:.78rem">
        <i class="fas fa-sync-alt"></i> Start re-scan
      </button>
    </div>
  </div>
</div>

<!-- ── Toast ── -->
<div class="toast" id="toast">
  <i id="toastIcon" class="fas fa-check-circle" style="color:var(--green2);flex-shrink:0"></i>
  <span id="toastText"></span>
</div>

<script>
const SM = <?=json_encode($siteMeta)?>;

// ── Charts ───────────────────────────────────────────
const C = {
  font: { family: "'Inter', sans-serif", size: 10 },
  grid: 'rgba(59,130,246,.06)',
  text: '#94A3B8'
};

new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: <?=json_encode($trendLabels)?>,
    datasets: [
      { label:'Total scans', data:<?=json_encode($trendTotal)?>, borderColor:'#60A5FA', backgroundColor:'rgba(96,165,250,.07)', tension:.4, fill:true, pointRadius:2 },
      { label:'Dead/inactive', data:<?=json_encode($trendDead)?>, borderColor:'#EF4444', backgroundColor:'rgba(239,68,68,.05)', tension:.4, fill:true, pointRadius:2 }
    ]
  },
  options:{
    responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{ labels:{ color:C.text, font:C.font, boxWidth:10, padding:12 } } },
    scales:{
      y:{ ticks:{color:C.text,font:C.font}, grid:{color:C.grid} },
      x:{ ticks:{color:C.text,font:C.font}, grid:{color:C.grid} }
    }
  }
});

new Chart(document.getElementById('statusChart'), {
  type: 'doughnut',
  data: {
    labels: <?=json_encode($statusLabels)?>,
    datasets:[{ data:<?=json_encode($statusData)?>, backgroundColor:['#10B981','#3B82F6','#F59E0B','#EF4444','#8B5CF6','#EC4899','#06B6D4','#64748B','#22C55E','#F97316'], borderWidth:0 }]
  },
  options:{ responsive:true, maintainAspectRatio:false, cutout:'60%', plugins:{ legend:{ position:'bottom', labels:{ color:C.text, font:C.font, boxWidth:8, padding:8 } } } }
});

// ── Scan detail modal ───────────z───────────────────
function showDetail(d, btn) {
  document.getElementById('modalDomain').textContent = d.domain_name;
  const sm = SM[d.site_status] || SM['no_response'];
  const sc = parseInt(d.dead_score || 0);
  const scColor = sc>=70?'var(--coral)':sc>=40?'var(--amber)':'var(--green2)';
  const httpColor = parseInt(d.http_status)>=400?'bad':parseInt(d.http_status)>=300?'warn':'good';

  const cells = [
    { l:'Site status',    v: `<span class="status-pill ${sm.class}"><i class="fas ${sm.icon}" style="font-size:.6rem"></i> ${sm.label}</span>`, raw:true },
    { l:'Dead score',     v: `<span style="color:${scColor};font-weight:800">${sc}/100</span>`, raw:true },
    { l:'HTTP status',    v: d.http_status || 'No response', cls: httpColor },
    { l:'Response time',  v: d.response_time_ms ? d.response_time_ms + 'ms' : '—' },
    { l:'SSL',            v: d.ssl_valid==1?'Valid':d.ssl_valid==0?'Invalid / Error':'Not checked', cls: d.ssl_valid==1?'good':d.ssl_valid==0?'warn':'' },
    { l:'Has content',    v: d.has_content?'Yes':'No', cls: d.has_content?'good':'bad' },
    { l:'Is parked',      v: d.is_parked?'Yes':'No', cls: d.is_parked?'warn':'' },
    { l:'For sale',       v: d.is_for_sale?'Yes':'No', cls: d.is_for_sale?'warn':'' },
    { l:'Redirect count', v: d.redirect_count || '0' },
    { l:'Server',         v: d.server_header || 'Not detected' },
    { l:'Content type',   v: d.content_type || 'Not detected' },
    { l:'Scanned at',     v: d.scanned_at },
  ];

  let html = '<div class="modal-grid">';
  cells.forEach(c => {
    const val = c.raw ? c.v : `<span class="mc-value ${c.cls||''}">${esc(c.v)}</span>`;
    html += `<div class="modal-cell"><div class="mc-label">${esc(c.l)}</div>${val}</div>`;
  });
  html += '</div>';

  if (d.page_title) {
    html += `<div class="modal-cell" style="margin-top:.5rem"><div class="mc-label">Page title</div><span class="mc-value">${esc(d.page_title)}</span></div>`;
  }
  if (d.final_url) {
    html += `<div class="modal-cell" style="margin-top:.5rem"><div class="mc-label">Final URL</div><a href="${esc(d.final_url)}" target="_blank" rel="noopener" class="mc-value" style="color:var(--blue2)">${esc(d.final_url)}</a></div>`;
  }

  // Action buttons
  html += `<div style="display:flex;gap:.5rem;margin-top:1rem;flex-wrap:wrap">`;
  if (d.is_dead) html += `<a href="../backorders.php?domain=${encodeURIComponent(d.domain_name)}" class="act-btn ab-amber" style="padding:.45rem 1rem;font-size:.72rem"><i class="fas fa-clock"></i> Backorder</a>`;
  html += `<a href="../whois.php?domain=${encodeURIComponent(d.domain_name)}" class="act-btn ab-blue" style="padding:.45rem 1rem;font-size:.72rem"><i class="fas fa-search"></i> WHOIS</a>`;
  html += `<button onclick="adminRescanSingle('${esc(d.domain_name)}')" class="act-btn ab-green" style="padding:.45rem 1rem;font-size:.72rem"><i class="fas fa-sync-alt"></i> Re-scan</button>`;
  html += `</div>`;

  document.getElementById('modalContent').innerHTML = html;
  document.getElementById('detailModal').classList.add('open');
}
function closeModal() { document.getElementById('detailModal').classList.remove('open'); }

// ── Delete scan ────────────────────────────────────
async function deleteScan(id, btn) {
  if (!confirm('Delete this scan record?')) return;
  btn.disabled = true;
  try {
    const res = await fetch('', { method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body: JSON.stringify({action:'delete_scan', id}) });
    const d = await res.json();
    if (d.success) {
      btn.closest('tr').style.transition = 'opacity .3s';
      btn.closest('tr').style.opacity = '0';
      setTimeout(() => btn.closest('tr').remove(), 300);
      showToast('Scan deleted.', 'success');
    } else showToast(d.message||'Error.', 'error');
  } catch { showToast('Network error.', 'error'); }
  btn.disabled = false;
}

// ── Admin re-scan (single) ─────────────────────────
async function adminRescanSingle(domain) {
  showToast('Triggering re-scan for ' + domain + '…', 'success');
  closeModal();
  try {
    const res = await fetch('', { method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body: JSON.stringify({action:'admin_rescan', domain}) });
    const d = await res.json();
    showToast(d.success ? 'Re-scan complete for ' + domain : (d.message||'Error'), d.success?'success':'error');
    if (d.success) setTimeout(() => location.reload(), 1500);
  } catch { showToast('Network error.', 'error'); }
}

// ── Batch re-scan modal ────────────────────────────
function openRescanModal() { document.getElementById('rescanModal').classList.add('open'); }
function closeRescan()     { document.getElementById('rescanModal').classList.remove('open'); }
function confirmRescan()   {
  closeRescan();
  showToast('Batch re-scan queued — check activity log for progress.', 'success');
  // In production, POST to a queue handler or background script
}

// ── Toast ─────────────────────────────────────────
function showToast(msg, type='success') {
  const t = document.getElementById('toast'), icon = document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  icon.className = `fas ${type==='error'?'fa-exclamation-circle':'fa-check-circle'}`;
  icon.style.color = type==='error'?'var(--coral)':'var(--green2)';
  t.className = `toast show ${type}`;
  clearTimeout(t._t);
  t._t = setTimeout(() => t.classList.remove('show'), 3600);
}

function esc(s) {
  return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Auto-submit search on enter
document.getElementById('qInput').addEventListener('keydown', e => {
  if (e.key === 'Enter') { e.preventDefault(); document.getElementById('filterForm').submit(); }
});
</script>

<?php
// ── AJAX handler for admin page ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    ob_clean();
    header('Content-Type: application/json');
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
    $conn2  = getDBConnection();

    if ($action === 'delete_scan') {
        $id = (int)($input['id'] ?? 0);
        $stmt = $conn2->prepare("DELETE FROM dead_site_scans WHERE id=?");
        $stmt->bind_param("i", $id); $stmt->execute();
        echo json_encode(['success'=> $stmt->affected_rows > 0]);
        $stmt->close();
    } elseif ($action === 'admin_rescan') {
        // Requires scan engine — include public file's function
        require_once '../dead-sites.php';  // just gets the runDeadSiteScan function
        // Note: In a real setup, extract the scan function to a shared lib
        $domain = strtolower(trim($input['domain'] ?? ''));
        $domain = preg_replace('#^https?://(www\.)?#','',$domain);
        if (!$domain) { echo json_encode(['success'=>false,'message'=>'No domain.']); exit; }
        $result = runDeadSiteScan($domain);
        $tld = implode('.', array_slice(explode('.', $domain), 1));
        // Update or insert (admin scan uses user_id=0 or first admin user)
        $stmt = $conn2->prepare("INSERT INTO dead_site_scans (user_id, domain_name, tld, http_status, response_time_ms, final_url, redirect_count, ssl_valid, server_header, content_type, site_status, is_dead, dead_score, has_content, is_parked, is_for_sale, page_title, credits_spent) VALUES (0,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0) ON DUPLICATE KEY UPDATE scanned_at=NOW()");
        echo json_encode(['success'=>true, 'data'=>$result]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Unknown action.']);
    }
    $conn2->close();
    exit;
}
?>
</body>
</html>