<?php
require_once 'auth_check.php';
$user = checkAdminAuth();
require_once '../config/database.php';

$conn = getDBConnection();
$activePage = 'stats';

// Time period filter
$period = $_GET['period'] ?? '30';
$days   = intval($period);
$endDate   = date('Y-m-d');
$startDate = date('Y-m-d', strtotime("-$days days"));
$prevStart = date('Y-m-d', strtotime("-" . ($days * 2) . " days"));

$safe = fn($sql) => ($r = @$conn->query($sql)) ? ($r->fetch_assoc() ?? []) : [];

// ── Revenue (Paystack in kobo) ───────────────────────────────
$revTotal  = (int)($safe("SELECT COALESCE(SUM(amount_charged_kobo),0) as v FROM payments WHERE status='success'")['v'] ?? 0);
$revPeriod = (int)($safe("SELECT COALESCE(SUM(amount_charged_kobo),0) as v FROM payments WHERE status='success' AND DATE(created_at) BETWEEN '$startDate' AND '$endDate'")['v'] ?? 0);
$revPrev   = (int)($safe("SELECT COALESCE(SUM(amount_charged_kobo),0) as v FROM payments WHERE status='success' AND DATE(created_at) BETWEEN '$prevStart' AND '$startDate'")['v'] ?? 0);
$revChange = $revPrev > 0 ? round((($revPeriod - $revPrev) / $revPrev) * 100, 1) : 0;

// ── Users ────────────────────────────────────────────────────
$totalUsers  = (int)($safe("SELECT COUNT(*) as c FROM users WHERE status != 'deleted'")['c'] ?? 0);
$newUsers    = (int)($safe("SELECT COUNT(*) as c FROM users WHERE DATE(created_at) BETWEEN '$startDate' AND '$endDate'")['c'] ?? 0);
$prevUsers   = (int)($safe("SELECT COUNT(*) as c FROM users WHERE DATE(created_at) BETWEEN '$prevStart' AND '$startDate'")['c'] ?? 0);
$usersChange = $prevUsers > 0 ? round((($newUsers - $prevUsers) / $prevUsers) * 100, 1) : 0;

// Plan breakdown
$planBreakdown = [];
$pbRes = @$conn->query("SELECT plan, COUNT(*) as c FROM users WHERE status='active' GROUP BY plan ORDER BY FIELD(plan,'elite','pro','free')");
if ($pbRes) while ($r = $pbRes->fetch_assoc()) $planBreakdown[] = $r;

// ── Active subscriptions ──────────────────────────────────────
$activeSubs  = (int)($safe("SELECT COUNT(*) as c FROM subscriptions WHERE status='active'")['c'] ?? 0);
$proSubs     = (int)($safe("SELECT COUNT(*) as c FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.status='active' AND p.slug='pro'")['c'] ?? 0);
$eliteSubs   = (int)($safe("SELECT COUNT(*) as c FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.status='active' AND p.slug='elite'")['c'] ?? 0);

// MRR estimate (sum of plan prices for active subs)
$mrrKobo = (int)($safe("SELECT COALESCE(SUM(p.price_monthly_kobo),0) as v FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.status='active'")['v'] ?? 0);

// ── Page views ───────────────────────────────────────────────
$totalPageViews      = (int)($safe("SELECT COUNT(*) as c FROM page_views")['c'] ?? 0);
$totalUniqueVisitors = (int)($safe("SELECT COUNT(DISTINCT session_id) as c FROM page_views")['c'] ?? 0);
$periodViews         = (int)($safe("SELECT COUNT(*) as c FROM page_views WHERE DATE(view_date) BETWEEN '$startDate' AND '$endDate'")['c'] ?? 0);
$periodUnique        = (int)($safe("SELECT COUNT(DISTINCT session_id) as c FROM page_views WHERE DATE(view_date) BETWEEN '$startDate' AND '$endDate'")['c'] ?? 0);
$prevViews           = (int)($safe("SELECT COUNT(*) as c FROM page_views WHERE DATE(view_date) BETWEEN '$prevStart' AND '$startDate'")['c'] ?? 0);
$viewsChange         = $prevViews > 0 ? round((($periodViews - $prevViews) / $prevViews) * 100, 1) : 0;

// ── Pending actions ──────────────────────────────────────────
$pendingBackorders = (int)($safe("SELECT COUNT(*) as c FROM backorders WHERE status IN ('pending','watching')")['c'] ?? 0);
$pendingBroker     = (int)($safe("SELECT COUNT(*) as c FROM broker_requests WHERE status IN ('submitted','researching','outreach','negotiating')")['c'] ?? 0);
$pendingRefunds    = (int)($safe("SELECT COUNT(*) as c FROM refunds WHERE status='pending'")['c'] ?? 0);
$unreadMessages    = (int)($safe("SELECT COUNT(*) as c FROM contact_messages WHERE status='unread'")['c'] ?? 0);

// ── Revenue chart (daily) ─────────────────────────────────────
$revChartRes = @$conn->query("
    SELECT DATE(created_at) as d, COALESCE(SUM(amount_charged_kobo),0) as v
    FROM payments WHERE status='success' AND DATE(created_at) >= DATE_SUB(NOW(), INTERVAL $days DAY)
    GROUP BY DATE(created_at) ORDER BY d ASC
");
$revLabels = []; $revData = [];
if ($revChartRes) while ($r = $revChartRes->fetch_assoc()) {
    $revLabels[] = $r['d'];
    $revData[]   = round($r['v'] / 100, 0);
}

// ── Views trend chart ─────────────────────────────────────────
$viewsRes = @$conn->query("
    SELECT DATE(view_date) as d, COUNT(*) as views, COUNT(DISTINCT session_id) as uniq
    FROM page_views WHERE view_date >= DATE_SUB(NOW(), INTERVAL $days DAY)
    GROUP BY DATE(view_date) ORDER BY d ASC
");
$viewsLabels = []; $viewsData = []; $uniqueData = [];
if ($viewsRes) while ($r = $viewsRes->fetch_assoc()) {
    $viewsLabels[] = $r['d'];
    $viewsData[]   = $r['views'];
    $uniqueData[]  = $r['uniq'];
}

// ── New users chart ───────────────────────────────────────────
$usersRes = @$conn->query("
    SELECT DATE(created_at) as d, COUNT(*) as c
    FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL $days DAY) AND status != 'deleted'
    GROUP BY DATE(created_at) ORDER BY d ASC
");
$usersLabels = []; $usersData = [];
if ($usersRes) while ($r = $usersRes->fetch_assoc()) {
    $usersLabels[] = $r['d'];
    $usersData[]   = $r['c'];
}

// ── Hourly traffic ────────────────────────────────────────────
$hourlyRes = @$conn->query("
    SELECT HOUR(view_date) as h, COUNT(*) as c
    FROM page_views WHERE view_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY HOUR(view_date)
");
$hourlyViews = array_fill(0, 24, 0);
if ($hourlyRes) while ($r = $hourlyRes->fetch_assoc()) $hourlyViews[$r['h']] = (int)$r['c'];

// ── Device & browser stats ────────────────────────────────────
$devRes  = @$conn->query("
    SELECT CASE WHEN user_agent LIKE '%Mobile%' OR user_agent LIKE '%Android%' OR user_agent LIKE '%iPhone%' THEN 'Mobile'
                WHEN user_agent LIKE '%Tablet%' OR user_agent LIKE '%iPad%' THEN 'Tablet' ELSE 'Desktop' END as dt,
           COUNT(*) as c FROM page_views GROUP BY dt
");
$deviceLabels = []; $deviceData = [];
if ($devRes) while ($r = $devRes->fetch_assoc()) { $deviceLabels[] = $r['dt']; $deviceData[] = $r['c']; }

$brwRes = @$conn->query("
    SELECT CASE WHEN user_agent LIKE '%Chrome%' AND user_agent NOT LIKE '%Edg%' THEN 'Chrome'
                WHEN user_agent LIKE '%Firefox%' THEN 'Firefox'
                WHEN user_agent LIKE '%Safari%' AND user_agent NOT LIKE '%Chrome%' THEN 'Safari'
                WHEN user_agent LIKE '%Edg%' THEN 'Edge'
                WHEN user_agent LIKE '%Opera%' THEN 'Opera' ELSE 'Other' END as br,
           COUNT(*) as c FROM page_views GROUP BY br ORDER BY c DESC
");
$brLabels = []; $brData = [];
if ($brwRes) while ($r = $brwRes->fetch_assoc()) { $brLabels[] = $r['br']; $brData[] = $r['c']; }

// ── Top searched domains ──────────────────────────────────────
$topSearches = @$conn->query("SELECT domain_name, COUNT(*) as c FROM domain_searches GROUP BY domain_name ORDER BY c DESC LIMIT 8");

// ── Recent users ──────────────────────────────────────────────
$recentUsers = @$conn->query("SELECT id, email, full_name, plan, status, created_at FROM users WHERE status != 'deleted' ORDER BY created_at DESC LIMIT 8");

// ── Recent payments ────────────────────────────────────────────
$recentPayments = @$conn->query("
    SELECT p.id, u.email, p.amount_charged_kobo, p.status, p.created_at, pl.name as plan_name
    FROM payments p
    JOIN users u ON u.id = p.user_id
    LEFT JOIN plans pl ON pl.id = p.description
    ORDER BY p.created_at DESC LIMIT 8
");

// ── Recent admin activity ─────────────────────────────────────
$recentActivity = @$conn->query("
    SELECT a.*, u.username FROM admin_activity_log a
    LEFT JOIN admin_users u ON a.user_id = u.id
    ORDER BY a.created_at DESC LIMIT 12
");

$conn->close();

// Helper
$fmt  = fn($n) => number_format((int)$n);
$usdMinorAmount = fn(int $amount): int => $amount >= 100000 ? (int)round($amount / 1000) : $amount;
$dollars = fn($kobo) => '$' . number_format($usdMinorAmount((int)$kobo) / 100, 0, '.', ',');
$pct  = fn($v) => ($v > 0 ? '+' : '') . $v . '%';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>Dashboard — CheckDomain Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#080B12;font-family:'Inter',sans-serif;overflow-x:hidden;color:#E2E8F0}

/* ── Design tokens ── */
:root{
  --bg0:#080B12;--bg1:#0D1117;--bg2:#131924;--bg3:#1A2333;
  --border:rgba(59,130,246,0.12);--border2:rgba(59,130,246,0.25);
  --blue:#3B82F6;--blue2:#60A5FA;--blue-bg:rgba(59,130,246,0.08);
  --green:#10B981;--green-bg:rgba(16,185,129,0.1);
  --amber:#F59E0B;--amber-bg:rgba(245,158,11,0.1);
  --coral:#EF4444;--coral-bg:rgba(239,68,68,0.1);
  --purple:#8B5CF6;--purple-bg:rgba(139,92,246,0.1);
  --cyan:#06B6D4;
  --text1:#E2E8F0;--text2:#94A3B8;--text3:#475569;
  --radius:12px;
}

/* ── Cards ── */
.card{
  background:var(--bg1);
  border:1px solid var(--border);
  border-radius:var(--radius);
  padding:1.25rem 1.5rem;
  transition:border-color .2s;
}
.card:hover{border-color:var(--border2)}

/* ── Stat cards ── */
.stat-card{
  background:var(--bg1);
  border:1px solid var(--border);
  border-radius:var(--radius);
  padding:1.25rem 1.5rem;
  transition:all .2s;
  position:relative;
  overflow:hidden;
}
.stat-card::before{
  content:'';position:absolute;inset:0;
  background:linear-gradient(135deg,var(--accent-start,rgba(59,130,246,.06)),transparent 60%);
  pointer-events:none;
}
.stat-card:hover{transform:translateY(-2px);border-color:var(--border2)}

.stat-value{font-size:1.75rem;font-weight:800;letter-spacing:-.03em;line-height:1}
.stat-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--text3)}
.stat-sub{font-size:.75rem;margin-top:.5rem;color:var(--text2)}

.badge-up{color:var(--green)}
.badge-down{color:var(--coral)}
.badge-neu{color:var(--text2)}

/* ── Icon bubbles ── */
.icon-bubble{
  width:40px;height:40px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  font-size:1rem;flex-shrink:0;
}

/* ── Alert strip ── */
.alert-strip{
  display:flex;align-items:center;gap:.5rem;
  background:var(--bg2);border:1px solid var(--border);
  border-radius:8px;padding:.6rem 1rem;
  font-size:.8rem;color:var(--text2);
  transition:all .15s;text-decoration:none;
}
.alert-strip:hover{background:var(--bg3);border-color:var(--border2);color:var(--text1)}
.alert-count{
  font-weight:700;min-width:20px;height:20px;
  border-radius:10px;padding:0 5px;font-size:.7rem;
  display:flex;align-items:center;justify-content:center;
}

/* ── Table styles ── */
.data-table{width:100%;font-size:.8rem;border-collapse:collapse}
.data-table th{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text3);padding:.75rem .5rem;text-align:left;border-bottom:1px solid var(--border)}
.data-table td{padding:.65rem .5rem;border-bottom:1px solid rgba(59,130,246,.05);color:var(--text2)}
.data-table tr:last-child td{border-bottom:none}
.data-table tr:hover td{background:rgba(59,130,246,.03);color:var(--text1)}

/* ── Plan badges ── */
.plan-pill{display:inline-flex;align-items:center;gap:.3rem;font-size:.65rem;font-weight:700;padding:.2rem .5rem;border-radius:6px;text-transform:uppercase;letter-spacing:.07em}
.plan-free{background:rgba(100,116,139,.15);color:#64748B}
.plan-pro{background:rgba(59,130,246,.15);color:var(--blue2)}
.plan-elite{background:rgba(139,92,246,.15);color:#A78BFA}

.status-active{background:rgba(16,185,129,.12);color:#34D399}
.status-suspended{background:rgba(239,68,68,.12);color:#F87171}
.status-deleted{background:rgba(100,116,139,.12);color:#64748B}

/* ── Section header ── */
.section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem}
.section-title{font-size:.9rem;font-weight:700;color:var(--text1)}
.section-link{font-size:.75rem;color:var(--blue2);text-decoration:none;transition:color .15s}
.section-link:hover{color:#fff}

/* ── Chart wrapper ── */
.chart-wrap{position:relative;min-height:200px}

/* ── Quick action buttons ── */
.q-btn{
  display:flex;align-items:center;gap:.5rem;
  background:var(--bg2);border:1px solid var(--border);
  border-radius:8px;padding:.6rem .9rem;
  font-size:.78rem;font-weight:600;color:var(--text2);
  text-decoration:none;transition:all .15s;cursor:pointer;
}
.q-btn:hover{background:var(--bg3);border-color:var(--border2);color:var(--text1)}

/* ── Scrollbar ── */
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(59,130,246,.2);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:rgba(59,130,246,.4)}

/* ── Period selector ── */
.period-btn{
  padding:.35rem .75rem;border-radius:6px;font-size:.75rem;font-weight:600;
  border:1px solid var(--border);background:transparent;color:var(--text2);
  cursor:pointer;transition:all .15s;
}
.period-btn.active,.period-btn:hover{background:var(--blue);border-color:var(--blue);color:#fff}

/* ── Responsive ── */
.main-content{margin-left:256px}
@media(max-width:768px){
  .main-content{margin-left:0!important}
  .stat-value{font-size:1.4rem}
  .hide-mobile{display:none!important}
}
@media(max-width:480px){
  .stat-grid-4{grid-template-columns:1fr 1fr!important}
  .stat-grid-4 .stat-card:nth-child(3),.stat-grid-4 .stat-card:nth-child(4){display:none}
}
</style>
</head>
<body>

<?php include_once 'includes/sidebar.php'; ?>

<div class="main-content" style="min-height:100vh">
<div class="p-4 md:p-7 max-w-screen-2xl mx-auto">

  <!-- ── Topbar ───────────────────────────────────── -->
  <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-7">
    <div>
      <div style="font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.12em;color:var(--text3);margin-bottom:.25rem">
        <?php echo date('l, F j, Y'); ?>
      </div>
      <h1 style="font-size:1.5rem;font-weight:800;color:var(--text1);letter-spacing:-.02em">
        Welcome back, <?php echo htmlspecialchars($user['username']); ?> 👋
      </h1>
      <p style="font-size:.8rem;color:var(--text2);margin-top:.2rem">Here's what's happening on CheckDomain today.</p>
    </div>
    <div class="flex flex-wrap gap-2">
      <?php foreach([7=>'7d',30=>'30d',90=>'90d',365=>'1y'] as $d=>$label): ?>
      <button onclick="window.location.href='?period=<?=$d?>'"
              class="period-btn <?php echo $period==$d?'active':'' ?>"><?=$label?></button>
      <?php endforeach; ?>
      <button onclick="window.location.reload()" class="q-btn" style="border-color:var(--border2)">
        <i class="fas fa-sync-alt" style="font-size:.7rem"></i> Refresh
      </button>
    </div>
  </div>

  <!-- ── Pending actions strip ────────────────────── -->
  <?php if($unreadMessages + $pendingRefunds + $pendingBroker + $pendingBackorders > 0): ?>
  <div class="flex flex-wrap gap-2 mb-6">
    <?php if($unreadMessages > 0): ?>
    <a href="messages.php" class="alert-strip">
      <i class="fas fa-envelope" style="color:var(--coral)"></i>
      <span class="alert-count" style="background:var(--coral-bg);color:var(--coral)"><?=$unreadMessages?></span>
      unread message<?=$unreadMessages!=1?'s':''?>
    </a>
    <?php endif; ?>
    <?php if($pendingRefunds > 0): ?>
    <a href="refunds.php" class="alert-strip">
      <i class="fas fa-undo" style="color:var(--amber)"></i>
      <span class="alert-count" style="background:var(--amber-bg);color:var(--amber)"><?=$pendingRefunds?></span>
      pending refund<?=$pendingRefunds!=1?'s':''?>
    </a>
    <?php endif; ?>
    <?php if($pendingBroker > 0): ?>
    <a href="broker.php" class="alert-strip">
      <i class="fas fa-handshake" style="color:var(--blue2)"></i>
      <span class="alert-count" style="background:var(--blue-bg);color:var(--blue2)"><?=$pendingBroker?></span>
      broker request<?=$pendingBroker!=1?'s':''?>
    </a>
    <?php endif; ?>
    <?php if($pendingBackorders > 0): ?>
    <a href="backorders.php" class="alert-strip">
      <i class="fas fa-clock" style="color:var(--amber)"></i>
      <span class="alert-count" style="background:var(--amber-bg);color:var(--amber)"><?=$pendingBackorders?></span>
      active backorder<?=$pendingBackorders!=1?'s':''?>
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ── KPI cards ─────────────────────────────────── -->
  <div class="stat-grid-4 grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

    <!-- Revenue -->
    <div class="stat-card" style="--accent-start:rgba(16,185,129,.08)">
      <div class="flex justify-between items-start mb-3">
        <span class="stat-label">Revenue (period)</span>
        <div class="icon-bubble" style="background:var(--green-bg);color:var(--green)"><i class="fas fa-dollars-sign"></i></div>
      </div>
      <div class="stat-value" style="color:var(--green)"><?=$dollars($revPeriod)?></div>
      <div class="stat-sub">
        Total: <?=$dollars($revTotal)?>
        <?php if($revChange != 0): ?>
        &nbsp;<span class="<?=$revChange>=0?'badge-up':'badge-down'?>"><?=$pct($revChange)?> vs prev</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- MRR -->
    <div class="stat-card" style="--accent-start:rgba(59,130,246,.08)">
      <div class="flex justify-between items-start mb-3">
        <span class="stat-label">Est. MRR</span>
        <div class="icon-bubble" style="background:var(--blue-bg);color:var(--blue2)"><i class="fas fa-chart-line"></i></div>
      </div>
      <div class="stat-value" style="color:var(--blue2)"><?=$dollars($mrrKobo)?></div>
      <div class="stat-sub">
        <?=$fmt($proSubs)?> Pro · <?=$fmt($eliteSubs)?> Elite · <?=$fmt($activeSubs)?> total active
      </div>
    </div>

    <!-- Users -->
    <div class="stat-card" style="--accent-start:rgba(139,92,246,.08)">
      <div class="flex justify-between items-start mb-3">
        <span class="stat-label">Total Users</span>
        <div class="icon-bubble" style="background:var(--purple-bg);color:var(--purple)"><i class="fas fa-users"></i></div>
      </div>
      <div class="stat-value" style="color:var(--purple)"><?=$fmt($totalUsers)?></div>
      <div class="stat-sub">
        +<?=$fmt($newUsers)?> this period
        <?php if($usersChange != 0): ?>
        &nbsp;<span class="<?=$usersChange>=0?'badge-up':'badge-down'?>"><?=$pct($usersChange)?> vs prev</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Page views -->
    <div class="stat-card" style="--accent-start:rgba(6,182,212,.08)">
      <div class="flex justify-between items-start mb-3">
        <span class="stat-label">Page Views</span>
        <div class="icon-bubble" style="background:rgba(6,182,212,.1);color:var(--cyan)"><i class="fas fa-eye"></i></div>
      </div>
      <div class="stat-value" style="color:var(--cyan)"><?=$fmt($periodViews)?></div>
      <div class="stat-sub">
        <?=$fmt($periodUnique)?> unique · All-time: <?=$fmt($totalPageViews)?>
        <?php if($viewsChange != 0): ?>
        &nbsp;<span class="<?=$viewsChange>=0?'badge-up':'badge-down'?>"><?=$pct($viewsChange)?></span>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- ── Charts row 1 ──────────────────────────────── -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">

    <!-- Revenue chart (2/3 width) -->
    <div class="card lg:col-span-2">
      <div class="section-head">
        <span class="section-title"><i class="fas fa-chart-area" style="color:var(--green);margin-right:.4rem"></i>Revenue trend</span>
        <span style="font-size:.7rem;color:var(--text3)">in USD, last <?=$days?> days</span>
      </div>
      <div class="chart-wrap"><canvas id="revChart"></canvas></div>
    </div>

    <!-- Plan split -->
    <div class="card">
      <div class="section-head">
        <span class="section-title"><i class="fas fa-layer-group" style="color:var(--blue2);margin-right:.4rem"></i>Plan split</span>
        <a href="users.php" class="section-link">All users →</a>
      </div>
      <div class="chart-wrap" style="min-height:180px"><canvas id="planChart"></canvas></div>
      <div style="margin-top:1rem;display:flex;flex-direction:column;gap:.4rem">
        <?php foreach($planBreakdown as $pb): 
          $color = ['elite'=>'#A78BFA','pro'=>'#60A5FA','free'=>'#64748B'][$pb['plan']] ?? '#64748B';
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;font-size:.78rem">
          <span style="display:flex;align-items:center;gap:.4rem;color:var(--text2)">
            <span style="width:8px;height:8px;border-radius:50%;background:<?=$color?>;display:inline-block"></span>
            <?=ucfirst($pb['plan'])?>
          </span>
          <span style="font-weight:700;color:var(--text1)"><?=$fmt($pb['c'])?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

  <!-- ── Charts row 2 ──────────────────────────────── -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">

    <!-- Views + Unique -->
    <div class="card lg:col-span-2">
      <div class="section-head">
        <span class="section-title"><i class="fas fa-globe" style="color:var(--cyan);margin-right:.4rem"></i>Traffic</span>
        <span style="font-size:.7rem;color:var(--text3)">page views vs unique visitors</span>
      </div>
      <div class="chart-wrap"><canvas id="viewsChart"></canvas></div>
    </div>

    <!-- Hourly heatmap-style bar -->
    <div class="card">
      <div class="section-head">
        <span class="section-title"><i class="fas fa-clock" style="color:var(--amber);margin-right:.4rem"></i>Last 24 h</span>
      </div>
      <div class="chart-wrap" style="min-height:180px"><canvas id="hourlyChart"></canvas></div>
    </div>

  </div>

  <!-- ── Charts row 3 ──────────────────────────────── -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">

    <!-- New users -->
    <div class="card">
      <div class="section-head">
        <span class="section-title"><i class="fas fa-user-plus" style="color:var(--purple);margin-right:.4rem"></i>New signups</span>
      </div>
      <div class="chart-wrap" style="min-height:160px"><canvas id="usersChart"></canvas></div>
    </div>

    <!-- Device -->
    <div class="card">
      <div class="section-head">
        <span class="section-title"><i class="fas fa-mobile-alt" style="color:var(--blue2);margin-right:.4rem"></i>Devices</span>
      </div>
      <div class="chart-wrap" style="min-height:160px"><canvas id="deviceChart"></canvas></div>
    </div>

    <!-- Browser -->
    <div class="card">
      <div class="section-head">
        <span class="section-title"><i class="fas fa-compass" style="color:var(--cyan);margin-right:.4rem"></i>Browsers</span>
      </div>
      <div class="chart-wrap" style="min-height:160px"><canvas id="browserChart"></canvas></div>
    </div>

  </div>

  <!-- ── Tables row ─────────────────────────────────── -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">

    <!-- Recent users -->
    <div class="card">
      <div class="section-head">
        <span class="section-title">Recent signups</span>
        <a href="users.php" class="section-link">View all →</a>
      </div>
      <div style="overflow-x:auto">
        <table class="data-table">
          <thead><tr>
            <th>User</th>
            <th>Plan</th>
            <th>Status</th>
            <th class="hide-mobile">Joined</th>
          </tr></thead>
          <tbody>
          <?php if($recentUsers): while($row = $recentUsers->fetch_assoc()): ?>
          <tr>
            <td>
              <div style="color:var(--text1);font-weight:500"><?=htmlspecialchars(strtok($row['full_name'] ?: $row['email'], ' '))?></div>
              <div style="font-size:.7rem;color:var(--text3)"><?=htmlspecialchars(substr($row['email'],0,24)).(strlen($row['email'])>24?'…':'')?></div>
            </td>
            <td><span class="plan-pill plan-<?=htmlspecialchars($row['plan'])?>"><?=htmlspecialchars($row['plan'])?></span></td>
            <td><span class="plan-pill status-<?=htmlspecialchars($row['status'])?>"><?=htmlspecialchars($row['status'])?></span></td>
            <td class="hide-mobile" style="white-space:nowrap"><?=date('M j, H:i', strtotime($row['created_at']))?></td>
          </tr>
          <?php endwhile; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Recent payments -->
    <div class="card">
      <div class="section-head">
        <span class="section-title">Recent payments</span>
        <a href="payments.php" class="section-link">View all →</a>
      </div>
      <div style="overflow-x:auto">
        <table class="data-table">
          <thead><tr>
            <th>User</th>
            <th>Amount</th>
            <th>Plan</th>
            <th class="hide-mobile">When</th>
          </tr></thead>
          <tbody>
          <?php if($recentPayments): while($row = $recentPayments->fetch_assoc()): 
            $ok = $row['status'] === 'success';
          ?>
          <tr>
            <td style="color:var(--text1)"><?=htmlspecialchars(substr($row['email'],0,20)).(strlen($row['email'])>20?'…':'')?></td>
            <td style="color:<?=$ok?'var(--green)':'var(--coral)'?>;font-weight:700"><?=$dollars((int)$row['amount_charged_kobo'])?></td>
            <td><?php if($row['plan_name']): ?><span class="plan-pill plan-<?=strtolower($row['plan_name'])?>"><?=htmlspecialchars($row['plan_name'])?></span><?php endif; ?></td>
            <td class="hide-mobile" style="white-space:nowrap"><?=date('M j, H:i', strtotime($row['created_at']))?></td>
          </tr>
          <?php endwhile; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <!-- ── Bottom row ─────────────────────────────────── -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">

    <!-- Top searches -->
    <div class="card">
      <div class="section-head">
        <span class="section-title"><i class="fas fa-search" style="color:var(--amber);margin-right:.4rem"></i>Top searches</span>
        <a href="search-analytics.php" class="section-link">All →</a>
      </div>
      <?php if($topSearches): $i=0; while($row = $topSearches->fetch_assoc()): $i++; ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:.45rem 0;border-bottom:1px solid var(--border);font-size:.8rem">
        <span style="color:var(--text2);font-family:monospace"><?=htmlspecialchars($row['domain_name'])?></span>
        <span style="background:var(--amber-bg);color:var(--amber);font-weight:700;font-size:.7rem;padding:.15rem .45rem;border-radius:5px"><?=$fmt($row['c'])?></span>
      </div>
      <?php endwhile; endif; ?>
    </div>

    <!-- Quick actions -->
    <div class="card">
      <div class="section-head">
        <span class="section-title">Quick actions</span>
      </div>
      <div style="display:flex;flex-direction:column;gap:.5rem">
        <a href="users.php" class="q-btn"><i class="fas fa-user-plus" style="color:var(--purple);width:16px"></i> Manage users</a>
        <a href="subscriptions.php" class="q-btn"><i class="fas fa-credit-card" style="color:var(--blue2);width:16px"></i> Subscriptions</a>
        <a href="payments.php" class="q-btn"><i class="fas fa-money-bill-wave" style="color:var(--green);width:16px"></i> Payments</a>
        <a href="promo-codes.php" class="q-btn"><i class="fas fa-tags" style="color:var(--amber);width:16px"></i> Promo codes</a>
        <a href="messages.php" class="q-btn"><i class="fas fa-envelope" style="color:var(--coral);width:16px"></i> Messages <?php if($unreadMessages): ?><span class="alert-count" style="background:var(--coral-bg);color:var(--coral)"><?=$unreadMessages?></span><?php endif; ?></a>
        <a href="backup-database.php" class="q-btn"><i class="fas fa-database" style="color:var(--cyan);width:16px"></i> Backup DB</a>
      </div>
    </div>

    <!-- Recent admin activity -->
    <div class="card">
      <div class="section-head">
        <span class="section-title">Admin activity</span>
        <a href="activity.php" class="section-link">All →</a>
      </div>
      <div style="display:flex;flex-direction:column;gap:.3rem;max-height:260px;overflow-y:auto">
        <?php if($recentActivity): while($row = $recentActivity->fetch_assoc()): ?>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:.4rem 0;border-bottom:1px solid var(--border);gap:.5rem">
          <div>
            <div style="font-size:.75rem;color:var(--blue2);font-weight:600;line-height:1.2"><?=htmlspecialchars($row['action'])?></div>
            <div style="font-size:.7rem;color:var(--text3);margin-top:.1rem"><?=htmlspecialchars($row['username'] ?? 'System')?></div>
          </div>
          <div style="font-size:.65rem;color:var(--text3);white-space:nowrap;flex-shrink:0"><?=date('M j H:i', strtotime($row['created_at']))?></div>
        </div>
        <?php endwhile; endif; ?>
      </div>
    </div>

  </div>

</div><!-- /inner -->
</div><!-- /main-content -->

<script>
const CDef = {
  color: '#E2E8F0',
  grid:  'rgba(59,130,246,0.06)',
  font:  { family: "'Inter', sans-serif", size: 10 }
};
const axisOpts = {
  ticks: { color: CDef.color, font: CDef.font },
  grid:  { color: CDef.grid }
};
const legendOpts = {
  labels: { color: CDef.color, font: CDef.font, boxWidth: 10, padding: 12 }
};

// ── Revenue chart ──────────────────────────────────
new Chart(document.getElementById('revChart'), {
  type: 'line',
  data: {
    labels: <?=json_encode($revLabels)?>,
    datasets: [{
      label: 'Revenue ($)',
      data: <?=json_encode($revData)?>,
      borderColor: '#10B981',
      backgroundColor: 'rgba(16,185,129,0.08)',
      tension: 0.4, fill: true,
      pointBackgroundColor: '#10B981',
      pointRadius: 3, pointHoverRadius: 5
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: true,
    plugins: { legend: legendOpts },
    scales: { y: { ...axisOpts, ticks: { ...axisOpts.ticks, callback: v => '$'+v.toLocaleString() } }, x: axisOpts }
  }
});

// ── Plan donut ─────────────────────────────────────
new Chart(document.getElementById('planChart'), {
  type: 'doughnut',
  data: {
    labels: <?=json_encode(array_column($planBreakdown,'plan'))?>,
    datasets: [{
      data: <?=json_encode(array_column($planBreakdown,'c'))?>,
      backgroundColor: ['#A78BFA','#60A5FA','#475569'],
      borderWidth: 0, hoverOffset: 4
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    cutout: '68%',
    plugins: { legend: { display: false } }
  }
});

// ── Traffic chart ──────────────────────────────────
new Chart(document.getElementById('viewsChart'), {
  type: 'line',
  data: {
    labels: <?=json_encode($viewsLabels)?>,
    datasets: [
      { label:'Page views', data: <?=json_encode($viewsData)?>, borderColor:'#06B6D4', backgroundColor:'rgba(6,182,212,0.08)', tension:0.4, fill:true, pointRadius:2 },
      { label:'Unique visitors', data: <?=json_encode($uniqueData)?>, borderColor:'#8B5CF6', backgroundColor:'rgba(139,92,246,0.06)', tension:0.4, fill:true, pointRadius:2 }
    ]
  },
  options: {
    responsive:true, maintainAspectRatio:true,
    plugins:{ legend: legendOpts },
    scales:{ y: axisOpts, x: axisOpts }
  }
});

// ── Hourly bar ─────────────────────────────────────
new Chart(document.getElementById('hourlyChart'), {
  type: 'bar',
  data: {
    labels: Array.from({length:24}, (_,i)=>i+'h'),
    datasets:[{ label:'Views', data:<?=json_encode(array_values($hourlyViews))?>, backgroundColor:'rgba(245,158,11,0.5)', borderColor:'#F59E0B', borderWidth:1, borderRadius:3 }]
  },
  options:{
    responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{ display:false } },
    scales:{ y:{...axisOpts, grid:{color:CDef.grid}}, x:{...axisOpts, ticks:{...axisOpts.ticks, maxRotation:0}} }
  }
});

// ── New users line ─────────────────────────────────
new Chart(document.getElementById('usersChart'), {
  type:'line',
  data:{
    labels: <?=json_encode($usersLabels)?>,
    datasets:[{ label:'New users', data:<?=json_encode($usersData)?>, borderColor:'#8B5CF6', backgroundColor:'rgba(139,92,246,0.1)', tension:0.4, fill:true, pointRadius:2 }]
  },
  options:{
    responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{display:false} },
    scales:{ y:{...axisOpts, ticks:{...axisOpts.ticks, stepSize:1}}, x:{...axisOpts, ticks:{...axisOpts.ticks, display:false}} }
  }
});

// ── Device doughnut ────────────────────────────────
new Chart(document.getElementById('deviceChart'), {
  type:'doughnut',
  data:{
    labels: <?=json_encode($deviceLabels)?>,
    datasets:[{ data:<?=json_encode($deviceData)?>, backgroundColor:['#3B82F6','#10B981','#8B5CF6'], borderWidth:0 }]
  },
  options:{
    responsive:true, maintainAspectRatio:false,
    cutout:'60%',
    plugins:{ legend:{ ...legendOpts, position:'bottom' } }
  }
});

// ── Browser pie ────────────────────────────────────
new Chart(document.getElementById('browserChart'), {
  type:'pie',
  data:{
    labels: <?=json_encode($brLabels)?>,
    datasets:[{ data:<?=json_encode($brData)?>, backgroundColor:['#3B82F6','#F59E0B','#10B981','#8B5CF6','#EF4444','#64748B'], borderWidth:0 }]
  },
  options:{
    responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{ ...legendOpts, position:'bottom' } }
  }
});
</script>
</body>
</html>
