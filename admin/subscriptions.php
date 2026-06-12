<?php
require_once 'auth_check.php';
$adminUser = checkAdminAuth();
require_once '../config/database.php';

$conn       = getDBConnection();
$activePage = 'subscriptions';

// ── Helpers ────────────────────────────────────────────────
$kobo2Naira = fn(int $k): string => '₦' . number_format($k / 100, 0, '.', ',');

// ── Handle POST actions ─────────────────────────────────────
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $subId  = (int)($_POST['sub_id'] ?? 0);
    $action = $_POST['action'];

    switch ($action) {

        case 'cancel':
            $stmt = $conn->prepare("
                UPDATE subscriptions
                SET status='canceled', canceled_at=NOW(),
                    cancellation_reason=?, cancel_at_period_end=0, updated_at=NOW()
                WHERE id=?
            ");
            $reason = substr(strip_tags($_POST['reason'] ?? 'Admin canceled'), 0, 255);
            $stmt->bind_param("si", $reason, $subId);
            $stmt->execute();
            $stmt->close();
            // Revert user plan to free
            $updUser = $conn->prepare("
                UPDATE users u
                JOIN subscriptions s ON s.user_id = u.id
                SET u.plan='free', u.credits=10
                WHERE s.id=?
            ");
            $updUser->bind_param("i", $subId);
            $updUser->execute();
            $updUser->close();
            logAdminActivity($adminUser['id'], 'CANCEL_SUBSCRIPTION', "Canceled subscription ID: $subId");
            $flash = ['type'=>'warn','msg'=>"Subscription #$subId canceled and user reverted to Free plan."];
            break;

        case 'reactivate':
            $stmt = $conn->prepare("
                UPDATE subscriptions
                SET status='active', canceled_at=NULL, cancel_at_period_end=0,
                    cancellation_reason=NULL, updated_at=NOW()
                WHERE id=?
            ");
            $stmt->bind_param("i", $subId);
            $stmt->execute();
            $stmt->close();
            // Restore plan on user
            $planStmt = $conn->prepare("
                UPDATE users u
                JOIN subscriptions s ON s.user_id = u.id
                JOIN plans p ON p.id = s.plan_id
                SET u.plan=p.slug
                WHERE s.id=?
            ");
            $planStmt->bind_param("i", $subId);
            $planStmt->execute();
            $planStmt->close();
            logAdminActivity($adminUser['id'], 'REACTIVATE_SUBSCRIPTION', "Reactivated subscription ID: $subId");
            $flash = ['type'=>'ok','msg'=>"Subscription #$subId reactivated."];
            break;

        case 'change_plan':
            $newPlanSlug = in_array($_POST['new_plan'] ?? '', ['free','pro','elite']) ? $_POST['new_plan'] : null;
            if ($newPlanSlug) {
                $planRow = $conn->query("SELECT id, credits_monthly FROM plans WHERE slug='$newPlanSlug' LIMIT 1")->fetch_assoc();
                if ($planRow) {
                    $stmt = $conn->prepare("UPDATE subscriptions SET plan_id=?, updated_at=NOW() WHERE id=?");
                    $stmt->bind_param("ii", $planRow['id'], $subId);
                    $stmt->execute(); $stmt->close();
                    $updUser = $conn->prepare("
                        UPDATE users u JOIN subscriptions s ON s.user_id=u.id
                        SET u.plan=?, u.credits=? WHERE s.id=?
                    ");
                    $updUser->bind_param("sii", $newPlanSlug, $planRow['credits_monthly'], $subId);
                    $updUser->execute(); $updUser->close();
                    logAdminActivity($adminUser['id'], 'CHANGE_SUB_PLAN', "Changed subscription #$subId plan to $newPlanSlug");
                    $flash = ['type'=>'ok','msg'=>"Subscription #$subId plan changed to ".ucfirst($newPlanSlug)."."];
                }
            }
            break;

        case 'extend':
            $days = max(1, min(365, (int)($_POST['days'] ?? 30)));
            $stmt = $conn->prepare("
                UPDATE subscriptions
                SET current_period_end = DATE_ADD(COALESCE(current_period_end, NOW()), INTERVAL ? DAY),
                    next_billing_at    = DATE_ADD(COALESCE(next_billing_at, NOW()),    INTERVAL ? DAY),
                    updated_at = NOW()
                WHERE id=?
            ");
            $stmt->bind_param("iii", $days, $days, $subId);
            $stmt->execute(); $stmt->close();
            logAdminActivity($adminUser['id'], 'EXTEND_SUBSCRIPTION', "Extended subscription #$subId by $days days");
            $flash = ['type'=>'ok','msg'=>"Subscription #$subId extended by $days days."];
            break;

        case 'create_manual':
            $uid       = (int)($_POST['user_id'] ?? 0);
            $planSlug  = in_array($_POST['plan'] ?? '', ['pro','elite']) ? $_POST['plan'] : 'pro';
            $cycle     = in_array($_POST['billing_cycle'] ?? '', ['monthly','yearly']) ? $_POST['billing_cycle'] : 'monthly';
            $months    = $cycle === 'yearly' ? 12 : 1;

            $planRow = $conn->query("SELECT id, credits_monthly FROM plans WHERE slug='$planSlug' LIMIT 1")->fetch_assoc();
            if ($planRow && $uid > 0) {
                // Cancel existing active sub
                $conn->query("UPDATE subscriptions SET status='canceled', canceled_at=NOW() WHERE user_id=$uid AND status IN ('active','trialing','past_due')");

                $stmt = $conn->prepare("
                    INSERT INTO subscriptions
                      (user_id, plan_id, status, billing_cycle, current_period_start, current_period_end, next_billing_at)
                    VALUES (?,?,'active',?,NOW(),DATE_ADD(NOW(),INTERVAL ? MONTH),DATE_ADD(NOW(),INTERVAL ? MONTH))
                ");
                $stmt->bind_param("iisii", $uid, $planRow['id'], $cycle, $months, $months);
                $stmt->execute(); $stmt->close();

                $updUser = $conn->prepare("UPDATE users SET plan=?, credits=credits+? WHERE id=?");
                $updUser->bind_param("sii", $planSlug, $planRow['credits_monthly'], $uid);
                $updUser->execute(); $updUser->close();

                logAdminActivity($adminUser['id'], 'CREATE_MANUAL_SUB', "Manually created {$planSlug} subscription for user ID: $uid");
                $flash = ['type'=>'ok','msg'=>"Manual {$planSlug} subscription created for user #$uid."];
            } else {
                $flash = ['type'=>'err','msg'=>"Invalid user ID or plan."];
            }
            break;
    }
}

// ── CSV export ─────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="subscriptions_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','User ID','Email','Plan','Billing','Status','Period Start','Period End','Next Billing','Sub Code','Created','Canceled']);
    $rows = $conn->query("
        SELECT s.id, s.user_id, u.email, p.slug as plan, s.billing_cycle, s.status,
               s.current_period_start, s.current_period_end, s.next_billing_at,
               s.paystack_subscription_code, s.created_at, s.canceled_at
        FROM subscriptions s
        JOIN users u ON u.id=s.user_id
        JOIN plans p ON p.id=s.plan_id
        ORDER BY s.created_at DESC
    ");
    while ($r = $rows->fetch_assoc()) fputcsv($out, $r);
    fclose($out);
    $conn->close();
    exit();
}

// ── Filters ────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$statusFilter = in_array($_GET['status'] ?? '', ['active','trialing','past_due','canceled','paused','non_renewing','incomplete','']) ? ($_GET['status'] ?? '') : '';
$planFilter   = in_array($_GET['plan'] ?? '', ['pro','elite','']) ? ($_GET['plan'] ?? '') : '';
$cycleFilter  = in_array($_GET['cycle'] ?? '', ['monthly','yearly','']) ? ($_GET['cycle'] ?? '') : '';
$sortCol      = in_array($_GET['sort'] ?? '', ['created_at','next_billing_at','status']) ? $_GET['sort'] : 'created_at';
$sortDir      = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

$where = ['1=1'];
$binds = []; $types = '';

if ($search) {
    $like = "%$search%";
    $where[] = "(u.email LIKE ? OR u.full_name LIKE ? OR s.paystack_subscription_code LIKE ?)";
    $binds[] = $like; $binds[] = $like; $binds[] = $like; $types .= 'sss';
}
if ($statusFilter) { $where[] = "s.status=?";        $binds[] = $statusFilter; $types .= 's'; }
if ($planFilter)   { $where[] = "p.slug=?";           $binds[] = $planFilter;   $types .= 's'; }
if ($cycleFilter)  { $where[] = "s.billing_cycle=?";  $binds[] = $cycleFilter;  $types .= 's'; }

$whereSQL = implode(' AND ', $where);
$sortMap  = ['created_at'=>'s.created_at','next_billing_at'=>'s.next_billing_at','status'=>'s.status'];
$orderSQL = ($sortMap[$sortCol] ?? 's.created_at') . ' ' . $sortDir;

// Count
$cStmt = $conn->prepare("
    SELECT COUNT(*) as c
    FROM subscriptions s
    JOIN users u ON u.id=s.user_id
    JOIN plans p ON p.id=s.plan_id
    WHERE $whereSQL
");
if ($types) $cStmt->bind_param($types, ...$binds);
$cStmt->execute();
$totalRows  = (int)$cStmt->get_result()->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$cStmt->close();

// Data
$dStmt = $conn->prepare("
    SELECT s.*,
           u.email, u.full_name, u.avatar,
           p.slug as plan_slug, p.name as plan_name,
           p.price_monthly_kobo, p.price_yearly_kobo
    FROM subscriptions s
    JOIN users u ON u.id=s.user_id
    JOIN plans p ON p.id=s.plan_id
    WHERE $whereSQL
    ORDER BY $orderSQL
    LIMIT ? OFFSET ?
");
$allBinds = array_merge($binds, [$perPage, $offset]);
$allTypes = $types . 'ii';
$dStmt->bind_param($allTypes, ...$allBinds);
$dStmt->execute();
$rows = $dStmt->get_result();
$subs = [];
while ($r = $rows->fetch_assoc()) $subs[] = $r;
$dStmt->close();

// ── Summary stats ───────────────────────────────────────────
$safe = fn($q): int => (int)($conn->query($q)?->fetch_assoc()['c'] ?? 0);
$safeVal = fn($q): mixed => ($conn->query($q)?->fetch_assoc()['v'] ?? null);

$statTotal     = $safe("SELECT COUNT(*) as c FROM subscriptions");
$statActive    = $safe("SELECT COUNT(*) as c FROM subscriptions WHERE status='active'");
$statTrialing  = $safe("SELECT COUNT(*) as c FROM subscriptions WHERE status='trialing'");
$statPastDue   = $safe("SELECT COUNT(*) as c FROM subscriptions WHERE status='past_due'");
$statCanceled  = $safe("SELECT COUNT(*) as c FROM subscriptions WHERE status='canceled'");
$statMRR       = (int)($conn->query("
    SELECT COALESCE(SUM(CASE WHEN s.billing_cycle='monthly' THEN p.price_monthly_kobo
                              WHEN s.billing_cycle='yearly'  THEN ROUND(p.price_yearly_kobo/12)
                              ELSE 0 END),0) as v
    FROM subscriptions s JOIN plans p ON p.id=s.plan_id
    WHERE s.status IN ('active','trialing')
")?->fetch_assoc()['v'] ?? 0);
$statRenewing  = $safe("SELECT COUNT(*) as c FROM subscriptions WHERE status='active' AND next_billing_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)");

// All users list for manual subscription form
$usersList = [];
$usersQ = $conn->query("SELECT id, email, full_name FROM users WHERE status='active' ORDER BY email LIMIT 200");
while ($r = $usersQ->fetch_assoc()) $usersList[] = $r;

$conn->close();

// ── URL helpers ─────────────────────────────────────────────
function subPageUrl(int $p, array $extra = []): string {
    $params = array_merge($_GET, $extra, ['page' => $p]);
    unset($params['export']);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== ''));
}
function subSortUrl(string $col): string {
    $dir = ($_GET['sort'] ?? '') === $col && ($_GET['dir'] ?? 'desc') === 'asc' ? 'desc' : 'asc';
    return subPageUrl(1, ['sort' => $col, 'dir' => $dir]);
}
function subSortIcon(string $col): string {
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
<title>Subscriptions — CheckDomain Admin</title>
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

/* Table rows */
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

/* Status badges */
.badge{display:inline-flex;align-items:center;gap:4px;font-size:.7rem;font-weight:600;padding:2px 8px;border-radius:9999px;white-space:nowrap}
.b-active      {background:rgba(16,185,129,.15);color:#34D399}
.b-trialing    {background:rgba(59,130,246,.15); color:#93C5FD}
.b-past_due    {background:rgba(245,158,11,.15); color:#FCD34D}
.b-canceled    {background:rgba(239,68,68,.12);  color:#FCA5A5}
.b-paused      {background:rgba(107,114,128,.2); color:#9CA3AF}
.b-non_renewing{background:rgba(245,158,11,.1);  color:#FCD34D}
.b-incomplete  {background:rgba(239,68,68,.1);   color:#FCA5A5}
.b-pro         {background:rgba(16,185,129,.15); color:#34D399}
.b-elite       {background:rgba(245,200,66,.12); color:#FCD34D}
.b-monthly     {background:rgba(59,130,246,.15); color:#93C5FD}
.b-yearly      {background:rgba(168,85,247,.15); color:#C4B5FD}

/* Dot pulse */
.pulse-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.dot-green{background:#10B981}
.dot-amber{background:#F59E0B}
.dot-red  {background:#EF4444}
.dot-gray {background:#6B7280}

/* Timeline bar */
.period-bar{height:4px;border-radius:2px;background:rgba(255,255,255,.08);overflow:hidden;margin-top:4px}
.period-fill{height:100%;border-radius:2px;transition:width .5s}

/* Input / select */
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

/* Paystack link chip */
.paystack-chip{display:inline-flex;align-items:center;gap:5px;font-family:monospace;font-size:.7rem;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);border-radius:5px;padding:2px 7px;color:#93C5FD;cursor:pointer;transition:background .13s}
.paystack-chip:hover{background:rgba(59,130,246,.2)}

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
    <i class="fas <?= $flash['type']==='ok' ? 'fa-check-circle' : ($flash['type']==='warn' ? 'fa-exclamation-triangle' : 'fa-times-circle') ?> mt-0.5 flex-shrink-0"></i>
    <span><?= $flash['msg'] ?></span>
  </div>
  <?php endif; ?>

  <!-- Page header -->
  <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
    <div>
      <h1 class="text-2xl md:text-3xl font-bold">Subscriptions</h1>
      <p class="text-gray-400 text-sm mt-1">
        <?= number_format($totalRows) ?> subscription<?= $totalRows !== 1 ? 's' : '' ?>
        <?php if ($search || $statusFilter || $planFilter || $cycleFilter): ?>
        <span class="text-blue-400">(filtered)</span>
        <?php endif; ?>
      </p>
    </div>
    <div class="flex flex-wrap gap-3">
      <a href="?export=1" class="btn-secondary flex items-center gap-2 text-sm">
        <i class="fas fa-download text-xs"></i> Export CSV
      </a>
      <button onclick="openModal('createModal')" class="btn-primary flex items-center gap-2 text-sm">
        <i class="fas fa-plus text-xs"></i> Manual subscription
      </button>
    </div>
  </div>

  <!-- Stat cards -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-7 gap-4 mb-8">
    <?php
    $cards = [
      ['val'=>$statTotal,    'lbl'=>'Total',       'icon'=>'fa-layer-group',     'color'=>'blue'],
      ['val'=>$statActive,   'lbl'=>'Active',       'icon'=>'fa-check-circle',    'color'=>'green'],
      ['val'=>$statTrialing, 'lbl'=>'Trialing',     'icon'=>'fa-hourglass-half',  'color'=>'blue'],
      ['val'=>$statPastDue,  'lbl'=>'Past due',     'icon'=>'fa-exclamation-triangle','color'=>'amber'],
      ['val'=>$statCanceled, 'lbl'=>'Canceled',     'icon'=>'fa-times-circle',    'color'=>'red'],
      ['val'=>$statRenewing, 'lbl'=>'Due in 7d',    'icon'=>'fa-calendar-alt',    'color'=>'purple'],
      ['val'=>$kobo2Naira($statMRR), 'lbl'=>'Est. MRR', 'icon'=>'fa-naira-sign', 'color'=>'green', 'raw'=>true],
    ];
    $cmap = ['blue'=>['bg'=>'bg-blue-500/20','txt'=>'text-blue-400'],'green'=>['bg'=>'bg-green-500/20','txt'=>'text-green-400'],'amber'=>['bg'=>'bg-amber-500/20','txt'=>'text-amber-400'],'red'=>['bg'=>'bg-red-500/20','txt'=>'text-red-400'],'purple'=>['bg'=>'bg-purple-500/20','txt'=>'text-purple-400']];
    foreach ($cards as $c):
      $cl = $cmap[$c['color']] ?? $cmap['blue'];
    ?>
    <div class="stat-card rounded-xl p-3 md:p-4">
      <div class="flex justify-between items-start">
        <div>
          <p class="text-gray-400 text-xs"><?= $c['lbl'] ?></p>
          <p class="text-lg md:text-xl font-bold mt-1 <?= $cl['txt'] ?>">
            <?= isset($c['raw']) ? $c['val'] : number_format($c['val']) ?>
          </p>
        </div>
        <div class="w-8 h-8 <?= $cl['bg'] ?> rounded-full flex items-center justify-center flex-shrink-0">
          <i class="fas <?= $c['icon'] ?> <?= $cl['txt'] ?> text-xs"></i>
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
                 placeholder="Email, name or SUB_xxx…" autocomplete="off">
        </div>
      </div>
      <div class="w-32">
        <label class="text-xs text-gray-400 mb-1 block">Status</label>
        <select class="inp" name="status">
          <option value="">All</option>
          <?php foreach (['active','trialing','past_due','canceled','paused','non_renewing','incomplete'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="w-28">
        <label class="text-xs text-gray-400 mb-1 block">Plan</label>
        <select class="inp" name="plan">
          <option value="">All</option>
          <option value="pro"   <?= $planFilter==='pro'?'selected':'' ?>>Pro</option>
          <option value="elite" <?= $planFilter==='elite'?'selected':'' ?>>Elite</option>
        </select>
      </div>
      <div class="w-32">
        <label class="text-xs text-gray-400 mb-1 block">Billing cycle</label>
        <select class="inp" name="cycle">
          <option value="">Both</option>
          <option value="monthly" <?= $cycleFilter==='monthly'?'selected':'' ?>>Monthly</option>
          <option value="yearly"  <?= $cycleFilter==='yearly'?'selected':'' ?>>Yearly</option>
        </select>
      </div>
      <div class="w-36">
        <label class="text-xs text-gray-400 mb-1 block">Sort</label>
        <select class="inp" name="sort" onchange="this.form.submit()">
          <option value="created_at"    <?= $sortCol==='created_at'?'selected':'' ?>>Created</option>
          <option value="next_billing_at" <?= $sortCol==='next_billing_at'?'selected':'' ?>>Next billing</option>
          <option value="status"        <?= $sortCol==='status'?'selected':'' ?>>Status</option>
        </select>
      </div>
      <input type="hidden" name="dir" value="<?= $sortDir==='ASC'?'asc':'desc' ?>">
      <button type="submit" class="btn-primary btn-sm flex items-center gap-2">
        <i class="fas fa-filter text-xs"></i> Filter
      </button>
      <?php if ($search || $statusFilter || $planFilter || $cycleFilter): ?>
      <a href="subscriptions.php" class="btn-secondary btn-sm flex items-center gap-2">
        <i class="fas fa-times text-xs"></i> Clear
      </a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Table -->
  <div class="bg-slate-800/50 rounded-xl overflow-hidden">
    <?php if (!empty($subs)): ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-700/50 border-b border-gray-700 text-xs uppercase tracking-wide text-gray-400">
          <tr>
            <th class="p-4 text-left w-10">#</th>
            <th class="p-4 text-left">User</th>
            <th class="p-4 text-left">Plan</th>
            <th class="p-4 text-left hide-mobile">
              <a href="<?= subSortUrl('status') ?>" class="hover:text-white flex items-center">
                Status <?= subSortIcon('status') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-mobile">Billing</th>
            <th class="p-4 text-left hide-mobile">
              <a href="<?= subSortUrl('next_billing_at') ?>" class="hover:text-white flex items-center">
                Period / Next billing <?= subSortIcon('next_billing_at') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-sm">Paystack code</th>
            <th class="p-4 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-700/50">
          <?php foreach ($subs as $sub):
            $isActive   = in_array($sub['status'], ['active','trialing','non_renewing']);
            $initials   = strtoupper(substr($sub['full_name'] ?: $sub['email'], 0, 1));
            $dotClass   = match($sub['status']) {
                'active'        => 'dot-green',
                'trialing'      => 'dot-green',
                'past_due'      => 'dot-amber',
                'non_renewing'  => 'dot-amber',
                default         => 'dot-gray',
            };
            $priceKobo  = $sub['billing_cycle'] === 'yearly' ? $sub['price_yearly_kobo'] : $sub['price_monthly_kobo'];

            // Period bar
            $periodPct  = 0;
            $daysLeft   = null;
            if ($sub['current_period_start'] && $sub['current_period_end']) {
                $start = strtotime($sub['current_period_start']);
                $end   = strtotime($sub['current_period_end']);
                $now   = time();
                $total = max(1, $end - $start);
                $periodPct = min(100, round((($now - $start) / $total) * 100));
                $daysLeft  = max(0, (int)ceil(($end - $now) / 86400));
            }
            $barColor = $periodPct >= 90 ? '#EF4444' : ($periodPct >= 70 ? '#F59E0B' : '#10B981');
          ?>
          <tr class="tbl-row">

            <!-- ID -->
            <td class="p-4 text-gray-500 font-mono text-xs"><?= (int)$sub['id'] ?></td>

            <!-- User -->
            <td class="p-4">
              <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center font-bold text-xs"
                     style="background:linear-gradient(135deg,#2563EB,#06B6D4)">
                  <?php if ($sub['avatar']): ?>
                  <img src="<?= htmlspecialchars($sub['avatar']) ?>" class="w-8 h-8 rounded-full object-cover" alt="">
                  <?php else: ?>
                  <?= htmlspecialchars($initials) ?>
                  <?php endif; ?>
                </div>
                <div class="min-w-0">
                  <div class="font-medium text-white text-xs truncate max-w-40"><?= htmlspecialchars($sub['full_name'] ?: '—') ?></div>
                  <div class="text-gray-400 text-xs truncate max-w-40"><?= htmlspecialchars($sub['email']) ?></div>
                  <div class="text-gray-600 text-xs">
                    <a href="users.php?search=<?= urlencode($sub['email']) ?>" class="hover:text-blue-400 transition">
                      #<?= (int)$sub['user_id'] ?>
                    </a>
                  </div>
                </div>
              </div>
            </td>

            <!-- Plan -->
            <td class="p-4">
              <span class="badge b-<?= $sub['plan_slug'] ?>">
                <i class="fas <?= $sub['plan_slug']==='elite'?'fa-crown':'fa-bolt' ?> text-xs"></i>
                <?= htmlspecialchars($sub['plan_name']) ?>
              </span>
              <?php if ($priceKobo > 0): ?>
              <div class="text-gray-500 text-xs mt-1"><?= $kobo2Naira($priceKobo) ?>/<?= $sub['billing_cycle']==='yearly'?'yr':'mo' ?></div>
              <?php endif; ?>
            </td>

            <!-- Status -->
            <td class="p-4 hide-mobile">
              <div class="flex items-center gap-2">
                <?php if ($isActive): ?>
                <span class="pulse-dot <?= $dotClass ?>"></span>
                <?php endif; ?>
                <span class="badge b-<?= $sub['status'] ?>">
                  <?= ucfirst(str_replace('_', ' ', $sub['status'])) ?>
                </span>
              </div>
              <?php if ($sub['cancel_at_period_end']): ?>
              <div class="text-amber-400 text-xs mt-1"><i class="fas fa-clock text-xs"></i> Cancels at period end</div>
              <?php endif; ?>
              <?php if ($sub['retry_count'] > 0): ?>
              <div class="text-red-400 text-xs mt-1"><i class="fas fa-redo text-xs"></i> <?= (int)$sub['retry_count'] ?> retries</div>
              <?php endif; ?>
            </td>

            <!-- Billing cycle -->
            <td class="p-4 hide-mobile">
              <span class="badge b-<?= $sub['billing_cycle'] ?>">
                <?= ucfirst($sub['billing_cycle']) ?>
              </span>
              <?php if ($sub['trial_ends_at'] && strtotime($sub['trial_ends_at']) > time()): ?>
              <div class="text-blue-400 text-xs mt-1">
                Trial ends <?= date('M j', strtotime($sub['trial_ends_at'])) ?>
              </div>
              <?php endif; ?>
            </td>

            <!-- Period -->
            <td class="p-4 hide-mobile" style="min-width:160px;">
              <?php if ($sub['next_billing_at']): ?>
              <div class="text-xs text-white font-medium">
                Next: <?= date('M j, Y', strtotime($sub['next_billing_at'])) ?>
                <?php if ($daysLeft !== null && $daysLeft <= 7): ?>
                <span class="text-amber-400">(<?= $daysLeft ?>d)</span>
                <?php endif; ?>
              </div>
              <?php endif; ?>
              <?php if ($sub['current_period_start'] && $sub['current_period_end']): ?>
              <div class="text-xs text-gray-500 mt-0.5">
                <?= date('M j', strtotime($sub['current_period_start'])) ?> — <?= date('M j, Y', strtotime($sub['current_period_end'])) ?>
              </div>
              <?php if ($daysLeft !== null): ?>
              <div class="period-bar mt-1">
                <div class="period-fill" style="width:<?= $periodPct ?>%;background:<?= $barColor ?>"></div>
              </div>
              <div class="text-xs text-gray-600 mt-0.5"><?= $daysLeft ?>d remaining</div>
              <?php endif; ?>
              <?php endif; ?>
            </td>

            <!-- Paystack code -->
            <td class="p-4 hide-sm">
              <?php if ($sub['paystack_subscription_code']): ?>
              <button class="paystack-chip"
                      onclick="copyText('<?= htmlspecialchars($sub['paystack_subscription_code'], ENT_QUOTES) ?>')"
                      title="Click to copy">
                <i class="fas fa-copy text-xs"></i>
                <?= htmlspecialchars(substr($sub['paystack_subscription_code'], 0, 18)) ?>…
              </button>
              <?php else: ?>
              <span class="text-gray-600 text-xs">Manual / not set</span>
              <?php endif; ?>
            </td>

            <!-- Actions -->
            <td class="p-4 text-right">
              <div class="flex items-center justify-end gap-1.5 flex-wrap">
                <!-- View details -->
                <button onclick="openDetailModal(<?= htmlspecialchars(json_encode($sub), ENT_QUOTES) ?>)"
                        class="w-8 h-8 bg-blue-500/20 hover:bg-blue-500/30 rounded-lg flex items-center justify-center text-blue-400 transition"
                        title="View details">
                  <i class="fas fa-eye text-xs"></i>
                </button>
                <!-- Extend -->
                <?php if ($isActive): ?>
                <button onclick="openExtendModal(<?= (int)$sub['id'] ?>, '<?= htmlspecialchars($sub['email'], ENT_QUOTES) ?>')"
                        class="w-8 h-8 bg-purple-500/20 hover:bg-purple-500/30 rounded-lg flex items-center justify-center text-purple-400 transition"
                        title="Extend period">
                  <i class="fas fa-calendar-plus text-xs"></i>
                </button>
                <?php endif; ?>
                <!-- Change plan -->
                <button onclick="openChangePlanModal(<?= (int)$sub['id'] ?>, '<?= htmlspecialchars($sub['email'], ENT_QUOTES) ?>', '<?= $sub['plan_slug'] ?>')"
                        class="w-8 h-8 bg-amber-500/20 hover:bg-amber-500/30 rounded-lg flex items-center justify-center text-amber-400 transition"
                        title="Change plan">
                  <i class="fas fa-exchange-alt text-xs"></i>
                </button>
                <!-- Cancel / Reactivate -->
                <?php if (in_array($sub['status'], ['active','trialing','past_due','non_renewing','paused'])): ?>
                <button onclick="openCancelModal(<?= (int)$sub['id'] ?>, '<?= htmlspecialchars($sub['email'], ENT_QUOTES) ?>')"
                        class="w-8 h-8 bg-red-500/20 hover:bg-red-500/30 rounded-lg flex items-center justify-center text-red-400 transition"
                        title="Cancel subscription">
                  <i class="fas fa-ban text-xs"></i>
                </button>
                <?php elseif ($sub['status'] === 'canceled'): ?>
                <button onclick="openReactivateModal(<?= (int)$sub['id'] ?>, '<?= htmlspecialchars($sub['email'], ENT_QUOTES) ?>')"
                        class="w-8 h-8 bg-green-500/20 hover:bg-green-500/30 rounded-lg flex items-center justify-center text-green-400 transition"
                        title="Reactivate">
                  <i class="fas fa-undo text-xs"></i>
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
        <a href="<?= subPageUrl($page-1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php
        $s = max(1,$page-2); $e = min($totalPages,$page+2);
        if ($s>1) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>';
        for ($i=$s;$i<=$e;$i++):
        ?>
        <a href="<?= subPageUrl($i) ?>" class="px-3 py-1.5 rounded text-xs transition <?= $i===$page?'bg-blue-600':'bg-slate-700 hover:bg-slate-600' ?>"><?= $i ?></a>
        <?php endfor;
        if ($e<$totalPages) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>'; ?>
        <?php if ($page < $totalPages): ?>
        <a href="<?= subPageUrl($page+1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="text-center py-16">
      <i class="fas fa-credit-card text-5xl text-gray-700 mb-4"></i>
      <p class="text-gray-400">No subscriptions found</p>
      <?php if ($search || $statusFilter || $planFilter || $cycleFilter): ?>
      <a href="subscriptions.php" class="inline-block mt-4 text-blue-400 hover:text-blue-300 text-sm transition">Clear filters</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div><!-- /table wrap -->

</div>
</div>

<!-- ═══════════════════════════════════
     MODALS
═══════════════════════════════════ -->

<!-- Detail modal -->
<div class="modal-backdrop" id="detailModal">
  <div class="modal-box" style="max-width:540px;">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold">Subscription details</h2>
      <button onclick="closeModal('detailModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <div class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm mb-5" id="detailGrid"></div>
    <div class="border-t border-gray-700 pt-4 text-xs text-gray-500" id="detailMeta"></div>
  </div>
</div>

<!-- Extend modal -->
<div class="modal-backdrop" id="extendModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold">Extend subscription</h2>
      <button onclick="closeModal('extendModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <p class="text-gray-400 text-sm mb-4">Extending period for <span id="ext-email" class="text-white font-mono"></span></p>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="extend">
      <input type="hidden" name="sub_id" id="ext-id">
      <div>
        <label class="text-xs text-gray-400 mb-1 block">Days to add <span class="text-red-400">*</span></label>
        <input class="inp" type="number" name="days" min="1" max="365" value="30" required>
        <p class="text-xs text-gray-500 mt-1">Both <code>current_period_end</code> and <code>next_billing_at</code> will be pushed forward.</p>
      </div>
      <div class="flex gap-3 justify-end pt-2">
        <button type="button" onclick="closeModal('extendModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-primary flex items-center gap-2">
          <i class="fas fa-calendar-plus text-xs"></i> Extend
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Change plan modal -->
<div class="modal-backdrop" id="changePlanModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold">Change plan</h2>
      <button onclick="closeModal('changePlanModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <p class="text-gray-400 text-sm mb-4">Changing plan for <span id="cp-email" class="text-white font-mono"></span></p>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="change_plan">
      <input type="hidden" name="sub_id" id="cp-id">
      <div>
        <label class="text-xs text-gray-400 mb-1 block">New plan</label>
        <select class="inp" name="new_plan" id="cp-plan">
          <option value="pro">Pro — ₦9,000/mo</option>
          <option value="elite">Elite — ₦29,000/mo</option>
        </select>
      </div>
      <div class="bg-amber-500/10 border border-amber-500/30 rounded-lg px-3 py-2 text-amber-300 text-xs">
        <i class="fas fa-exclamation-triangle mr-1"></i>
        This changes the DB record immediately. Credits will be reset to the new plan's monthly allocation.
      </div>
      <div class="flex gap-3 justify-end pt-2">
        <button type="button" onclick="closeModal('changePlanModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-amber flex items-center gap-2">
          <i class="fas fa-exchange-alt text-xs"></i> Change plan
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Cancel modal -->
<div class="modal-backdrop" id="cancelModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold text-red-400"><i class="fas fa-ban mr-2"></i>Cancel subscription</h2>
      <button onclick="closeModal('cancelModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <p class="text-gray-300 text-sm mb-2">Cancel subscription for <span id="cncl-email" class="text-white font-mono"></span>.</p>
    <p class="text-gray-500 text-xs mb-4">The user will be reverted to the Free plan immediately and their credits reset to 10.</p>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="cancel">
      <input type="hidden" name="sub_id" id="cncl-id">
      <div>
        <label class="text-xs text-gray-400 mb-1 block">Cancellation reason (optional)</label>
        <input class="inp" type="text" name="reason" placeholder="Non-payment, requested by user, etc." maxlength="255">
      </div>
      <div class="flex gap-3 justify-end pt-2">
        <button type="button" onclick="closeModal('cancelModal')" class="btn-secondary">Keep active</button>
        <button type="submit" class="btn-danger flex items-center gap-2">
          <i class="fas fa-ban text-xs"></i> Cancel subscription
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Reactivate modal -->
<div class="modal-backdrop" id="reactivateModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold text-green-400"><i class="fas fa-undo mr-2"></i>Reactivate subscription</h2>
      <button onclick="closeModal('reactivateModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <p class="text-gray-300 text-sm mb-4">
      Reactivate subscription for <span id="react-email" class="text-white font-mono"></span>?
      The user's plan will be restored from the subscription record.
    </p>
    <form method="POST" class="flex gap-3 justify-end">
      <input type="hidden" name="action" value="reactivate">
      <input type="hidden" name="sub_id" id="react-id">
      <button type="button" onclick="closeModal('reactivateModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-primary flex items-center gap-2">
        <i class="fas fa-undo text-xs"></i> Reactivate
      </button>
    </form>
  </div>
</div>

<!-- Create manual subscription modal -->
<div class="modal-backdrop" id="createModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold">Create manual subscription</h2>
      <button onclick="closeModal('createModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <p class="text-gray-400 text-sm mb-4">Grant a user a Pro or Elite subscription without a Paystack payment — useful for comps, partners, or corrections.</p>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="create_manual">
      <div>
        <label class="text-xs text-gray-400 mb-1 block">User <span class="text-red-400">*</span></label>
        <select class="inp" name="user_id" required>
          <option value="">— Select user —</option>
          <?php foreach ($usersList as $u): ?>
          <option value="<?= (int)$u['id'] ?>">
            #<?= (int)$u['id'] ?> · <?= htmlspecialchars($u['email']) ?><?= $u['full_name'] ? ' (' . htmlspecialchars($u['full_name']) . ')' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
        <p class="text-xs text-gray-500 mt-1">Shows up to 200 active users. Use the <a href="users.php" class="text-blue-400">Users page</a> to find a specific ID.</p>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="text-xs text-gray-400 mb-1 block">Plan <span class="text-red-400">*</span></label>
          <select class="inp" name="plan">
            <option value="pro">Pro</option>
            <option value="elite">Elite</option>
          </select>
        </div>
        <div>
          <label class="text-xs text-gray-400 mb-1 block">Billing cycle</label>
          <select class="inp" name="billing_cycle">
            <option value="monthly">Monthly</option>
            <option value="yearly">Yearly</option>
          </select>
        </div>
      </div>
      <div class="bg-blue-500/10 border border-blue-500/20 rounded-lg px-3 py-2 text-blue-300 text-xs">
        <i class="fas fa-info-circle mr-1"></i>
        A subscription record will be created with status=<strong>active</strong>. Any existing active subscription for this user will be canceled first. No payment is recorded.
      </div>
      <div class="flex gap-3 justify-end pt-2">
        <button type="button" onclick="closeModal('createModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-primary flex items-center gap-2">
          <i class="fas fa-plus text-xs"></i> Create subscription
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
function openDetailModal(sub) {
  const fmtDate = d => d ? new Date(d).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
  const fields = [
    {l:'Subscription ID', v:'#'+sub.id},
    {l:'User',            v:'#'+sub.user_id+' · '+esc(sub.email)},
    {l:'Plan',            v:esc(sub.plan_name||sub.plan_slug)},
    {l:'Billing cycle',   v:sub.billing_cycle||'—'},
    {l:'Status',          v:sub.status},
    {l:'Cancel at end',   v:sub.cancel_at_period_end?'Yes':'No'},
    {l:'Period start',    v:fmtDate(sub.current_period_start)},
    {l:'Period end',      v:fmtDate(sub.current_period_end)},
    {l:'Next billing',    v:fmtDate(sub.next_billing_at)},
    {l:'Trial ends',      v:fmtDate(sub.trial_ends_at)},
    {l:'Retry count',     v:sub.retry_count||'0'},
    {l:'Canceled at',     v:fmtDate(sub.canceled_at)},
  ];
  const grid = document.getElementById('detailGrid');
  grid.innerHTML = fields.map(f => `
    <div>
      <div class="text-gray-500 text-xs uppercase tracking-wide mb-0.5">${f.l}</div>
      <div class="font-mono text-sm text-gray-200 break-all">${f.v}</div>
    </div>`).join('');

  const meta = document.getElementById('detailMeta');
  meta.innerHTML = `
    Paystack sub code: <strong class="font-mono text-blue-300">${esc(sub.paystack_subscription_code||'—')}</strong><br>
    Paystack plan code: <strong class="font-mono text-blue-300">${esc(sub.paystack_plan_code||'—')}</strong><br>
    Reason: <strong>${esc(sub.cancellation_reason||'—')}</strong><br>
    Created: ${fmtDate(sub.created_at)} · Updated: ${fmtDate(sub.updated_at)}
  `;
  openModal('detailModal');
}

// ── Extend modal ──────────────────────────────────────────
function openExtendModal(id, email) {
  document.getElementById('ext-id').value    = id;
  document.getElementById('ext-email').textContent = email;
  openModal('extendModal');
}

// ── Change plan modal ─────────────────────────────────────
function openChangePlanModal(id, email, currentPlan) {
  document.getElementById('cp-id').value        = id;
  document.getElementById('cp-email').textContent = email;
  document.getElementById('cp-plan').value      = currentPlan === 'elite' ? 'pro' : 'elite';
  openModal('changePlanModal');
}

// ── Cancel modal ──────────────────────────────────────────
function openCancelModal(id, email) {
  document.getElementById('cncl-id').value        = id;
  document.getElementById('cncl-email').textContent = email;
  openModal('cancelModal');
}

// ── Reactivate modal ──────────────────────────────────────
function openReactivateModal(id, email) {
  document.getElementById('react-id').value        = id;
  document.getElementById('react-email').textContent = email;
  openModal('reactivateModal');
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
  const colors = { ok:'#10B981', warn:'#F59E0B', err:'#EF4444' };
  const icons  = { ok:'fa-check-circle', warn:'fa-exclamation-triangle', err:'fa-times-circle' };
  icon.className   = 'fas ' + (icons[type] || 'fa-info-circle');
  icon.style.color = colors[type] || '#10B981';
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