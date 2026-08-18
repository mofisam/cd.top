<?php
require_once 'auth_check.php';
$adminUser = checkAdminAuth();
require_once '../config/database.php';

$conn       = getDBConnection();
$activePage = 'subscriptions';

// ── Auto-create plans table ───────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS plans (
        id                          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug                        VARCHAR(32)      NOT NULL,
        name                        VARCHAR(64)      NOT NULL,
        description                 TEXT             NULL,
        price_monthly_kobo          INT UNSIGNED     NOT NULL DEFAULT 0,
        price_yearly_kobo           INT UNSIGNED     NOT NULL DEFAULT 0,
        currency                    CHAR(3)          NOT NULL DEFAULT 'USD',
        paystack_plan_code_monthly  VARCHAR(64)      NULL DEFAULT NULL,
        paystack_plan_code_yearly   VARCHAR(64)      NULL DEFAULT NULL,
        credits_monthly             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        credits_signup              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        feature_whois               TINYINT(1) NOT NULL DEFAULT 0,
        feature_backorder           TINYINT(1) NOT NULL DEFAULT 0,
        feature_alerts              TINYINT(1) NOT NULL DEFAULT 0,
        feature_dead_sites          TINYINT(1) NOT NULL DEFAULT 0,
        feature_broker              TINYINT(1) NOT NULL DEFAULT 0,
        feature_bulk_lookup         TINYINT(1) NOT NULL DEFAULT 0,
        watchlist_limit             SMALLINT UNSIGNED NOT NULL DEFAULT 5,
        search_history_days         SMALLINT UNSIGNED NOT NULL DEFAULT 7,
        is_active                   TINYINT(1) NOT NULL DEFAULT 1,
        sort_order                  TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_plans_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Seed default plans if empty
if ((int)($conn->query("SELECT COUNT(*) as c FROM plans")?->fetch_assoc()['c'] ?? 0) === 0) {
    $conn->query("
        INSERT INTO plans (slug,name,price_monthly_kobo,price_yearly_kobo,credits_monthly,credits_signup,
            feature_whois,feature_backorder,feature_alerts,feature_dead_sites,
            feature_broker,feature_bulk_lookup,watchlist_limit,search_history_days,sort_order)
        VALUES
          ('free', 'Free',  0,       0,        10,  0,  0,0,0,0,0,0, 5,  7, 1),
          ('pro',  'Pro',   900,     8900,     100, 20, 1,1,1,1,0,0, 0, 90, 2),
          ('elite','Elite', 2900,    27900,    500, 50, 1,1,1,1,1,1, 0,365, 3)
    ");
}

// ── Helpers ──────────────────────────────────────────────────
$usdMinorAmount = fn(int $amount): int => $amount >= 100000 ? (int)round($amount / 1000) : $amount;
$kobo  = fn(int $k): string => '$' . number_format($usdMinorAmount($k) / 100, 0, '.', ',');
$flash = null;

// ── POST: Plan CRUD ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ── Save plan ─────────────────────────────────────────────
    if (in_array($action, ['save_plan', 'create_plan'])) {
        $pid   = (int)($_POST['plan_id'] ?? 0);
        $slug  = strtolower(preg_replace('/[^a-z0-9_]/', '', trim($_POST['slug'] ?? '')));
        $name  = substr(strip_tags(trim($_POST['name'] ?? '')), 0, 64);
        $desc  = substr(strip_tags(trim($_POST['description'] ?? '')), 0, 500);
        $priceMonthly = max(0, (int)round((float)($_POST['price_monthly_ngn'] ?? 0) * 100));
        $priceYearly  = max(0, (int)round((float)($_POST['price_yearly_ngn']  ?? 0) * 100));
        $credMonthly  = max(0, (int)($_POST['credits_monthly'] ?? 0));
        $credSignup   = max(0, (int)($_POST['credits_signup']  ?? 0));
        $psMonthly    = substr(trim($_POST['paystack_plan_code_monthly'] ?? ''), 0, 64) ?: null;
        $psYearly     = substr(trim($_POST['paystack_plan_code_yearly']  ?? ''), 0, 64) ?: null;
        $fWhois       = isset($_POST['feature_whois'])       ? 1 : 0;
        $fBackorder   = isset($_POST['feature_backorder'])   ? 1 : 0;
        $fAlerts      = isset($_POST['feature_alerts'])      ? 1 : 0;
        $fDead        = isset($_POST['feature_dead_sites'])  ? 1 : 0;
        $fBroker      = isset($_POST['feature_broker'])      ? 1 : 0;
        $fBulk        = isset($_POST['feature_bulk_lookup']) ? 1 : 0;
        $wLimit       = max(0, (int)($_POST['watchlist_limit']      ?? 5));
        $histDays     = max(1, (int)($_POST['search_history_days']  ?? 7));
        $sortOrd      = max(0, (int)($_POST['sort_order'] ?? 0));
        $isActive     = isset($_POST['is_active']) ? 1 : 0;

        if (!$slug || !$name) { $flash = ['type'=>'err','msg'=>'Slug and name are required.']; goto done; }

        if ($action === 'save_plan' && $pid) {
            $dup = $conn->prepare("SELECT id FROM plans WHERE slug=? AND id!=? LIMIT 1");
            $dup->bind_param("si", $slug, $pid); $dup->execute();
            if ($dup->get_result()->num_rows > 0) { $dup->close(); $flash=['type'=>'err','msg'=>"Slug '$slug' already used."]; goto done; }
            $dup->close();

            $upd = $conn->prepare("
                UPDATE plans SET slug=?,name=?,description=?,
                    price_monthly_kobo=?,price_yearly_kobo=?,
                    paystack_plan_code_monthly=?,paystack_plan_code_yearly=?,
                    credits_monthly=?,credits_signup=?,
                    feature_whois=?,feature_backorder=?,feature_alerts=?,
                    feature_dead_sites=?,feature_broker=?,feature_bulk_lookup=?,
                    watchlist_limit=?,search_history_days=?,sort_order=?,
                    is_active=?,updated_at=NOW()
                WHERE id=?
            ");
            $upd->bind_param("sssiissiiiiiiiiiiiii",
                $slug,$name,$desc,$priceMonthly,$priceYearly,
                $psMonthly,$psYearly,$credMonthly,$credSignup,
                $fWhois,$fBackorder,$fAlerts,$fDead,$fBroker,$fBulk,
                $wLimit,$histDays,$sortOrd,$isActive,$pid
            );
            $upd->close();
            // Rebuild with correct types
            $upd2 = $conn->prepare("
                UPDATE plans SET slug=?,name=?,description=?,
                    price_monthly_kobo=?,price_yearly_kobo=?,
                    paystack_plan_code_monthly=?,paystack_plan_code_yearly=?,
                    credits_monthly=?,credits_signup=?,
                    feature_whois=?,feature_backorder=?,feature_alerts=?,
                    feature_dead_sites=?,feature_broker=?,feature_bulk_lookup=?,
                    watchlist_limit=?,search_history_days=?,sort_order=?,
                    is_active=?,updated_at=NOW()
                WHERE id=?
            ");
            $upd2->bind_param("sssiissiiiiiiiiiiiii",
                $slug,$name,$desc,$priceMonthly,$priceYearly,
                $psMonthly,$psYearly,$credMonthly,$credSignup,
                $fWhois,$fBackorder,$fAlerts,$fDead,$fBroker,$fBulk,
                $wLimit,$histDays,$sortOrd,$isActive,$pid
            );
            $upd2->execute(); $upd2->close();
            logAdminActivity($adminUser['id'], 'UPDATE_PLAN', "Updated plan: $name (slug: $slug)");
            $flash = ['type'=>'ok','msg'=>"Plan <strong>$name</strong> updated."];

        } else {
            $dup = $conn->prepare("SELECT id FROM plans WHERE slug=? LIMIT 1");
            $dup->bind_param("s", $slug); $dup->execute();
            if ($dup->get_result()->num_rows > 0) { $dup->close(); $flash=['type'=>'err','msg'=>"Slug '$slug' already exists."]; goto done; }
            $dup->close();

            $ins = $conn->prepare("
                INSERT INTO plans
                  (slug,name,description,price_monthly_kobo,price_yearly_kobo,
                   paystack_plan_code_monthly,paystack_plan_code_yearly,
                   credits_monthly,credits_signup,
                   feature_whois,feature_backorder,feature_alerts,
                   feature_dead_sites,feature_broker,feature_bulk_lookup,
                   watchlist_limit,search_history_days,sort_order,is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $ins->bind_param("sssiissiiiiiiiiiiii",
                $slug,$name,$desc,$priceMonthly,$priceYearly,
                $psMonthly,$psYearly,$credMonthly,$credSignup,
                $fWhois,$fBackorder,$fAlerts,$fDead,$fBroker,$fBulk,
                $wLimit,$histDays,$sortOrd,$isActive
            );
            $ins->execute(); $ins->close();
            logAdminActivity($adminUser['id'], 'CREATE_PLAN', "Created plan: $name (slug: $slug)");
            $flash = ['type'=>'ok','msg'=>"Plan <strong>$name</strong> created."];
        }
    }

    // ── Toggle plan active ────────────────────────────────────
    elseif ($action === 'toggle_plan') {
        $pid    = (int)($_POST['plan_id'] ?? 0);
        $toggle = (int)($_POST['toggle'] ?? 0);
        $conn->prepare("UPDATE plans SET is_active=?, updated_at=NOW() WHERE id=?")->bind_param("ii",$toggle,$pid)->execute();
        $u = $conn->prepare("UPDATE plans SET is_active=?, updated_at=NOW() WHERE id=?");
        $u->bind_param("ii",$toggle,$pid); $u->execute(); $u->close();
        $flash = ['type'=>'ok','msg'=>"Plan " . ($toggle ? 'activated' : 'deactivated') . "."];
    }

    // ── Grant / change user plan manually ─────────────────────
    elseif ($action === 'grant_plan') {
        $uid      = (int)($_POST['user_id']    ?? 0);
        $planSlug = in_array($_POST['plan_slug']??'', ['free','pro','elite']) ? $_POST['plan_slug'] : null;
        $months   = max(1, min(24, (int)($_POST['months'] ?? 1)));
        $cycle    = $_POST['billing_cycle'] ?? 'monthly';

        if (!$uid || !$planSlug) { $flash = ['type'=>'err','msg'=>'User ID and plan are required.']; goto done; }

        $planRow = $conn->query("SELECT id, credits_monthly, credits_signup FROM plans WHERE slug='$planSlug' LIMIT 1")->fetch_assoc();
        if (!$planRow) { $flash = ['type'=>'err','msg'=>'Plan not found.']; goto done; }

        // Cancel existing active subscriptions
        $conn->query("UPDATE subscriptions SET status='canceled', canceled_at=NOW() WHERE user_id=$uid AND status IN ('active','trialing','past_due')");

        // Create new subscription
        $now = date('Y-m-d H:i:s');
        $end = date('Y-m-d H:i:s', strtotime("+{$months} month"));

        $ins = $conn->prepare("
            INSERT INTO subscriptions
              (user_id, plan_id, status, billing_cycle,
               current_period_start, current_period_end, next_billing_at)
            VALUES (?,?,'active',?,?,?,?)
        ");
        $ins->bind_param("iissss", $uid, $planRow['id'], $cycle, $now, $end, $end);
        $ins->execute(); $ins->close();

        // Update user
        $upd = $conn->prepare("UPDATE users SET plan=?, credits=credits+? WHERE id=?");
        $upd->bind_param("sii", $planSlug, $planRow['credits_monthly'], $uid);
        $upd->execute(); $upd->close();

        logAdminActivity($adminUser['id'], 'GRANT_PLAN', "Granted {$planSlug} plan to user #$uid for $months month(s)");
        $flash = ['type'=>'ok','msg'=>"User #$uid granted <strong>".ucfirst($planSlug)."</strong> plan for $months month(s)."];
    }

    // ── Revoke plan (revert to free) ──────────────────────────
    elseif ($action === 'revoke_plan') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if (!$uid) { $flash = ['type'=>'err','msg'=>'User ID required.']; goto done; }

        $conn->query("UPDATE subscriptions SET status='canceled', canceled_at=NOW() WHERE user_id=$uid AND status NOT IN ('canceled')");
        $conn->query("UPDATE users SET plan='free', credits=10 WHERE id=$uid");

        logAdminActivity($adminUser['id'], 'REVOKE_PLAN', "Revoked plan for user #$uid — reset to Free");
        $flash = ['type'=>'ok','msg'=>"User #$uid plan revoked and reset to Free."];
    }

    done:
}

// ── Fetch plans ───────────────────────────────────────────────
$plans = [];
$planRows = $conn->query("
    SELECT p.*,
           (SELECT COUNT(*) FROM subscriptions s
            WHERE s.plan_id=p.id AND s.status='active') AS active_subs,

           (SELECT COUNT(*) FROM subscriptions s
            WHERE s.plan_id=p.id) AS total_subs,

           (SELECT COUNT(*) FROM users u
            WHERE u.plan COLLATE utf8mb4_unicode_ci =
                  p.slug COLLATE utf8mb4_unicode_ci
            AND u.status='active') AS user_count

    FROM plans p
    ORDER BY p.sort_order ASC, p.id ASC
");
while ($r = $planRows->fetch_assoc()) {
    $r['price_monthly_kobo'] = $usdMinorAmount((int)$r['price_monthly_kobo']);
    $r['price_yearly_kobo']  = $usdMinorAmount((int)$r['price_yearly_kobo']);
    $plans[] = $r;
}

// ── Active subscriptions with user info ───────────────────────
$subSearch  = trim($_GET['search'] ?? '');
$subStatus  = in_array($_GET['sub_status']??'', ['active','trialing','past_due','canceled','non_renewing','']) ? ($_GET['sub_status']??'') : '';
$subPlan    = trim($_GET['sub_plan'] ?? '');
$subPage    = max(1, (int)($_GET['page'] ?? 1));
$subPerPage = 20;
$subOffset  = ($subPage - 1) * $subPerPage;

$where  = ['1=1']; $binds = []; $types = '';
if ($subSearch) {
    $like    = "%$subSearch%";
    $where[] = "(u.email LIKE ? OR u.full_name LIKE ? OR s.paystack_subscription_code LIKE ?)";
    $binds   = [$like,$like,$like]; $types = 'sss';
}
if ($subStatus) { $where[] = "s.status=?";    $binds[] = $subStatus; $types .= 's'; }
if ($subPlan)   { $where[] = "p.slug=?";      $binds[] = $subPlan;   $types .= 's'; }

$whereSQL = implode(' AND ', $where);

// Count
$cStmt = $conn->prepare("SELECT COUNT(*) as c FROM subscriptions s JOIN users u ON u.id=s.user_id JOIN plans p ON p.id=s.plan_id WHERE $whereSQL");
if ($types) $cStmt->bind_param($types, ...$binds);
$cStmt->execute();
$subTotal   = (int)$cStmt->get_result()->fetch_assoc()['c'];
$subPages   = max(1, (int)ceil($subTotal / $subPerPage));
$cStmt->close();

// Rows
$dStmt = $conn->prepare("
    SELECT s.id, s.status, s.billing_cycle, s.cancel_at_period_end,
           s.current_period_end, s.next_billing_at, s.created_at,
           s.paystack_subscription_code, s.retry_count,
           u.id as user_id, u.email, u.full_name, u.avatar, u.plan as user_plan,
           p.slug as plan_slug, p.name as plan_name,
           p.price_monthly_kobo, p.price_yearly_kobo
    FROM subscriptions s
    JOIN users u ON u.id=s.user_id
    JOIN plans p ON p.id=s.plan_id
    WHERE $whereSQL
    ORDER BY
        FIELD(s.status,'active','trialing','past_due','non_renewing','incomplete','paused','canceled') ASC,
        s.created_at DESC
    LIMIT ? OFFSET ?
");
$allBinds = array_merge($binds, [$subPerPage, $subOffset]);
$allTypes = $types . 'ii';
$dStmt->bind_param($allTypes, ...$allBinds);
$dStmt->execute();
$subs = [];
$results = $dStmt->get_result();
while ($r = $results->fetch_assoc()) {
    $r['price_monthly_kobo'] = $usdMinorAmount((int)$r['price_monthly_kobo']);
    $r['price_yearly_kobo']  = $usdMinorAmount((int)$r['price_yearly_kobo']);
    $subs[] = $r;
}
$dStmt->close();

// ── Summary stats ─────────────────────────────────────────────
$safe  = fn($q): int => (int)($conn->query($q)?->fetch_assoc()['c'] ?? 0);
$safeV = fn($q): int => (int)($conn->query($q)?->fetch_assoc()['v'] ?? 0);

$statActiveSubs   = $safe("SELECT COUNT(*) as c FROM subscriptions WHERE status='active'");
$statMRRKobo      = $safeV("SELECT COALESCE(SUM(CASE WHEN s.billing_cycle='monthly' THEN p.price_monthly_kobo WHEN s.billing_cycle='yearly' THEN ROUND(p.price_yearly_kobo/12) ELSE 0 END),0) as v FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.status='active'");
$statFreeUsers    = $safe("SELECT COUNT(*) as c FROM users WHERE plan='free' AND status='active'");
$statProUsers     = $safe("SELECT COUNT(*) as c FROM users WHERE plan='pro'  AND status='active'");
$statEliteUsers   = $safe("SELECT COUNT(*) as c FROM users WHERE plan='elite' AND status='active'");
$statPastDue      = $safe("SELECT COUNT(*) as c FROM subscriptions WHERE status='past_due'");

// Users for grant form
$usersList = [];
$uQ = $conn->query("SELECT id, email, full_name, plan FROM users WHERE status='active' ORDER BY email LIMIT 300");
while ($r = $uQ->fetch_assoc()) $usersList[] = $r;

$conn->close();

// ── URL helpers ───────────────────────────────────────────────
function plPageUrl(int $p, array $extra = []): string {
    $params = array_merge($_GET, $extra, ['page'=>$p]);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>Plans & Subscriptions — CheckDomain Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0F172A;font-family:'Inter',sans-serif;overflow-x:hidden;color:#fff}

.stat-card{background:linear-gradient(135deg,rgba(30,58,138,.3),rgba(16,185,129,.1));backdrop-filter:blur(10px);border:1px solid rgba(59,130,246,.3);transition:all .3s}
.stat-card:hover{transform:translateY(-2px);border-color:rgba(16,185,129,.5)}
.main-content{transition:margin-left .3s}
.tbl-row{transition:background .15s}
.tbl-row:hover{background:rgba(59,130,246,.06)!important}

/* Plan cards */
.plan-card{background:#1E293B;border:2px solid rgba(71,85,105,.5);border-radius:16px;transition:border-color .2s,transform .15s;overflow:hidden}
.plan-card:hover{transform:translateY(-2px)}
.plan-card.active-plan{border-color:rgba(59,130,246,.5)}
.plan-card.plan-free {border-color:rgba(107,114,128,.4)}
.plan-card.plan-pro  {border-color:rgba(16,185,129,.4)}
.plan-card.plan-elite{border-color:rgba(245,200,66,.3);background:linear-gradient(160deg,rgba(245,200,66,.04),#1E293B 60%)}

/* Modals */
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);z-index:100;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s}
.modal-backdrop.open{opacity:1;pointer-events:all}
.modal-box{background:#1E293B;border:1px solid rgba(59,130,246,.3);border-radius:1rem;padding:1.75rem;max-width:580px;width:90%;transform:scale(.96);transition:transform .2s;max-height:92vh;overflow-y:auto}
.modal-backdrop.open .modal-box{transform:scale(1)}

/* Flash */
.flash-ok  {background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#34D399}
.flash-warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:#FCD34D}
.flash-err {background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.3); color:#FCA5A5}

/* Badges */
.badge{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:600;padding:2px 7px;border-radius:9999px;white-space:nowrap}
.b-active      {background:rgba(16,185,129,.15);color:#34D399}
.b-trialing    {background:rgba(59,130,246,.15); color:#93C5FD}
.b-past_due    {background:rgba(245,158,11,.15); color:#FCD34D}
.b-canceled    {background:rgba(239,68,68,.12);  color:#FCA5A5}
.b-non_renewing{background:rgba(245,158,11,.1);  color:#FCD34D}
.b-paused      {background:rgba(107,114,128,.2); color:#9CA3AF}
.b-incomplete  {background:rgba(239,68,68,.1);   color:#FCA5A5}
.b-free        {background:rgba(107,114,128,.2); color:#9CA3AF}
.b-pro         {background:rgba(16,185,129,.15); color:#34D399}
.b-elite       {background:rgba(245,200,66,.12); color:#FCD34D}
.b-monthly     {background:rgba(59,130,246,.12); color:#93C5FD}
.b-yearly      {background:rgba(168,85,247,.12); color:#C4B5FD}

/* Feature tick */
.ft-on {color:#10B981}
.ft-off{color:#374151}

/* Toggle */
.toggle-wrap{position:relative;width:40px;height:22px;flex-shrink:0}
.toggle-wrap input{opacity:0;width:0;height:0}
.toggle-track{position:absolute;inset:0;background:#334155;border-radius:22px;cursor:pointer;transition:background .2s;border:1px solid rgba(71,85,105,.8)}
.toggle-wrap input:checked+.toggle-track{background:#10B981;border-color:#10B981}
.toggle-track::before{content:'';position:absolute;width:16px;height:16px;border-radius:50%;background:#fff;top:2px;left:2px;transition:transform .2s}
.toggle-wrap input:checked+.toggle-track::before{transform:translateX(18px)}

/* Period bar */
.period-bar{height:3px;border-radius:2px;background:rgba(255,255,255,.07);overflow:hidden;margin-top:3px}
.period-fill{height:100%;border-radius:2px;transition:width .5s}

/* Inputs */
.inp{background:rgba(51,65,85,.8);border:1px solid rgba(71,85,105,1);border-radius:.5rem;padding:.5rem .75rem;color:#fff;width:100%;font-size:.875rem;transition:border-color .2s;outline:none}
.inp:focus{border-color:#3B82F6}
.inp::placeholder{color:#64748B}
.form-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#64748B;margin-bottom:.25rem;display:block}
.form-hint{font-size:.68rem;color:#475569;margin-top:.2rem}
.chk-feat{width:15px;height:15px;accent-color:#10B981;cursor:pointer}

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
      <h1 class="text-2xl md:text-3xl font-bold">Plans & Subscriptions</h1>
      <p class="text-gray-400 text-sm mt-1">Manage pricing tiers, features, and user subscriptions.</p>
    </div>
    <div class="flex flex-wrap gap-3">
      <button onclick="openModal('grantModal')" class="btn-amber flex items-center gap-2 text-sm">
        <i class="fas fa-bolt text-xs"></i> Grant plan to user
      </button>
      <button onclick="openCreatePlanModal()" class="btn-primary flex items-center gap-2 text-sm">
        <i class="fas fa-plus text-xs"></i> New plan
      </button>
    </div>
  </div>

  <!-- Stats -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-8">
    <?php
    $cards = [
      ['lbl'=>'Active subs',  'val'=>number_format($statActiveSubs), 'icon'=>'fa-check-circle',  'c'=>'green'],
      ['lbl'=>'Est. MRR',     'val'=>$kobo($statMRRKobo),            'icon'=>'fa-chart-line',    'c'=>'blue',  'raw'=>true],
      ['lbl'=>'Free users',   'val'=>number_format($statFreeUsers),  'icon'=>'fa-user',           'c'=>'gray'],
      ['lbl'=>'Pro users',    'val'=>number_format($statProUsers),   'icon'=>'fa-bolt',           'c'=>'green'],
      ['lbl'=>'Elite users',  'val'=>number_format($statEliteUsers), 'icon'=>'fa-crown',          'c'=>'yellow'],
      ['lbl'=>'Past due',     'val'=>number_format($statPastDue),    'icon'=>'fa-exclamation',    'c'=>'red'],
    ];
    $cmap=['green'=>['bg'=>'bg-green-500/20','t'=>'text-green-400'],'blue'=>['bg'=>'bg-blue-500/20','t'=>'text-blue-400'],'gray'=>['bg'=>'bg-slate-500/20','t'=>'text-slate-400'],'yellow'=>['bg'=>'bg-yellow-500/20','t'=>'text-yellow-400'],'red'=>['bg'=>'bg-red-500/20','t'=>'text-red-400'],'purple'=>['bg'=>'bg-purple-500/20','t'=>'text-purple-400']];
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

  <!-- ══════════════════════════
       PLAN CARDS
  ══════════════════════════ -->
  <div class="mb-3 flex items-center justify-between">
    <h2 class="text-base font-bold text-gray-200 uppercase tracking-wide text-xs">
      <i class="fas fa-layer-group text-blue-400 mr-2"></i>Pricing Plans
    </h2>
  </div>
  <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-10">
    <?php foreach ($plans as $plan):
      $planColorClass = match($plan['slug']) { 'elite'=>'plan-elite', 'pro'=>'plan-pro', default=>'plan-free' };
      $planIcon       = match($plan['slug']) { 'elite'=>'fa-crown', 'pro'=>'fa-bolt', default=>'fa-user' };
      $planColor      = match($plan['slug']) { 'elite'=>'text-yellow-400', 'pro'=>'text-green-400', default=>'text-slate-400' };
      $features = [
        ['feature_whois',       'WHOIS lookup'],
        ['feature_backorder',   'Backorders'],
        ['feature_alerts',      'Drop alerts'],
        ['feature_dead_sites',  'Dead site scan'],
        ['feature_broker',      'Broker service'],
        ['feature_bulk_lookup', 'Bulk lookup'],
      ];
    ?>
    <div class="plan-card <?= $planColorClass ?> <?= $plan['is_active'] ? 'active-plan' : 'opacity-60' ?>">
      <!-- Plan header -->
      <div class="p-5 border-b border-gray-700/50">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="flex items-center gap-2 mb-1">
              <i class="fas <?= $planIcon ?> <?= $planColor ?> text-lg"></i>
              <span class="font-bold text-lg text-white"><?= htmlspecialchars($plan['name']) ?></span>
              <?php if (!$plan['is_active']): ?>
              <span class="badge b-free">Inactive</span>
              <?php endif; ?>
            </div>
            <div class="font-mono text-2xl font-black text-white">
              <?= $plan['price_monthly_kobo'] > 0 ? $kobo((int)$plan['price_monthly_kobo']) : '$0' ?>
              <span class="text-gray-500 text-sm font-normal">/mo</span>
            </div>
            <?php if ($plan['price_yearly_kobo'] > 0): ?>
            <div class="text-gray-400 text-xs mt-0.5"><?= $kobo((int)$plan['price_yearly_kobo']) ?>/yr</div>
            <?php endif; ?>
          </div>
          <!-- Active toggle -->
          <form method="POST" class="mt-1">
            <input type="hidden" name="action" value="toggle_plan">
            <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
            <input type="hidden" name="toggle" value="<?= $plan['is_active'] ? '0' : '1' ?>">
            <label class="toggle-wrap cursor-pointer">
              <input type="checkbox" <?= $plan['is_active']?'checked':'' ?> onchange="this.form.submit()">
              <span class="toggle-track"></span>
            </label>
          </form>
        </div>
      </div>

      <!-- Credits + limits -->
      <div class="px-5 py-3 bg-slate-900/30 border-b border-gray-700/30 grid grid-cols-2 gap-3 text-xs">
        <div>
          <div class="text-gray-500 uppercase tracking-wide mb-0.5">Monthly credits</div>
          <div class="font-mono font-bold text-amber-400 text-base"><?= number_format($plan['credits_monthly']) ?></div>
        </div>
        <div>
          <div class="text-gray-500 uppercase tracking-wide mb-0.5">Signup bonus</div>
          <div class="font-mono font-bold text-amber-300"><?= number_format($plan['credits_signup']) ?></div>
        </div>
        <div>
          <div class="text-gray-500 uppercase tracking-wide mb-0.5">Watchlist limit</div>
          <div class="font-mono font-bold text-blue-300"><?= $plan['watchlist_limit'] === 0 ? '∞ Unlimited' : $plan['watchlist_limit'] ?></div>
        </div>
        <div>
          <div class="text-gray-500 uppercase tracking-wide mb-0.5">History days</div>
          <div class="font-mono font-bold text-blue-300"><?= $plan['search_history_days'] ?></div>
        </div>
      </div>

      <!-- Features -->
      <div class="px-5 py-3 border-b border-gray-700/30">
        <div class="grid grid-cols-2 gap-1.5">
          <?php foreach ($features as [$key, $label]): ?>
          <div class="flex items-center gap-2 text-xs <?= $plan[$key] ? 'text-gray-200' : 'text-gray-600' ?>">
            <i class="fas <?= $plan[$key] ? 'fa-check-circle ft-on' : 'fa-times-circle ft-off' ?> text-xs"></i>
            <?= $label ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Paystack codes -->
      <?php if ($plan['paystack_plan_code_monthly'] || $plan['paystack_plan_code_yearly']): ?>
      <div class="px-5 py-2 bg-blue-500/5 border-b border-gray-700/30 text-xs">
        <?php if ($plan['paystack_plan_code_monthly']): ?>
        <div class="text-gray-500 flex items-center gap-2">
          <span>Monthly:</span>
          <button onclick="copyText('<?= htmlspecialchars($plan['paystack_plan_code_monthly'],ENT_QUOTES) ?>')"
                  class="font-mono text-blue-300 hover:text-blue-200 transition">
            <?= htmlspecialchars($plan['paystack_plan_code_monthly']) ?>
          </button>
        </div>
        <?php endif; ?>
        <?php if ($plan['paystack_plan_code_yearly']): ?>
        <div class="text-gray-500 flex items-center gap-2 mt-0.5">
          <span>Yearly:</span>
          <button onclick="copyText('<?= htmlspecialchars($plan['paystack_plan_code_yearly'],ENT_QUOTES) ?>')"
                  class="font-mono text-blue-300 hover:text-blue-200 transition">
            <?= htmlspecialchars($plan['paystack_plan_code_yearly']) ?>
          </button>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Stats + actions -->
      <div class="px-5 py-4 flex items-center justify-between">
        <div class="text-xs text-gray-500">
          <span class="text-white font-semibold"><?= number_format($plan['user_count']) ?></span> users ·
          <span class="text-green-400 font-semibold"><?= number_format($plan['active_subs']) ?></span> active subs
        </div>
        <button onclick="openEditPlanModal(<?= htmlspecialchars(json_encode($plan),ENT_QUOTES) ?>)"
                class="btn-secondary btn-sm flex items-center gap-1.5">
          <i class="fas fa-edit text-xs"></i> Edit
        </button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ══════════════════════════
       SUBSCRIPTION TABLE
  ══════════════════════════ -->
  <div class="mb-3 flex items-center justify-between flex-wrap gap-3">
    <h2 class="text-xs font-bold text-gray-200 uppercase tracking-wide">
      <i class="fas fa-credit-card text-blue-400 mr-2"></i>Subscriptions
    </h2>
  </div>

  <!-- Sub filters -->
  <div class="bg-slate-800/50 rounded-xl p-4 mb-5">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
      <div class="flex-1 min-w-44">
        <label class="form-label">Search</label>
        <div class="relative">
          <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
          <input class="inp pl-8" type="text" name="search"
                 value="<?= htmlspecialchars($subSearch) ?>"
                 placeholder="Email or name…" autocomplete="off">
        </div>
      </div>
      <div class="w-32">
        <label class="form-label">Status</label>
        <select class="inp" name="sub_status">
          <option value="">All</option>
          <?php foreach (['active','trialing','past_due','canceled','non_renewing','paused'] as $s): ?>
          <option value="<?= $s ?>" <?= $subStatus===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="w-28">
        <label class="form-label">Plan</label>
        <select class="inp" name="sub_plan">
          <option value="">All</option>
          <?php foreach ($plans as $p): ?>
          <option value="<?= $p['slug'] ?>" <?= $subPlan===$p['slug']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn-primary btn-sm flex items-center gap-2">
        <i class="fas fa-filter text-xs"></i> Filter
      </button>
      <?php if ($subSearch||$subStatus||$subPlan): ?>
      <a href="plans.php" class="btn-secondary btn-sm flex items-center gap-2">
        <i class="fas fa-times text-xs"></i> Clear
      </a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Subscriptions table -->
  <div class="bg-slate-800/50 rounded-xl overflow-hidden">
    <?php if (!empty($subs)): ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-700/50 border-b border-gray-700 text-xs uppercase tracking-wide text-gray-400">
          <tr>
            <th class="p-4 text-left">User</th>
            <th class="p-4 text-left">Plan</th>
            <th class="p-4 text-left hide-mobile">Status</th>
            <th class="p-4 text-left hide-mobile">Billing</th>
            <th class="p-4 text-left hide-mobile">Period end</th>
            <th class="p-4 text-left hide-sm">Paystack code</th>
            <th class="p-4 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-700/50">
          <?php foreach ($subs as $sub):
            $initials = strtoupper(substr($sub['full_name'] ?: $sub['email'], 0, 1));
            $isActive = in_array($sub['status'], ['active','trialing','non_renewing']);
            $daysLeft = null;
            if ($sub['current_period_end']) {
                $daysLeft = max(0, (int)ceil((strtotime($sub['current_period_end']) - time()) / 86400));
            }
            $barPct = 0;
            if ($sub['current_period_end'] && $sub['created_at']) {
                $start = strtotime($sub['created_at']);
                $end   = strtotime($sub['current_period_end']);
                $barPct = min(100, round(((time()-$start)/max(1,$end-$start))*100));
            }
            $barColor = $barPct >= 90 ? '#EF4444' : ($barPct >= 70 ? '#F59E0B' : '#10B981');
            $priceKobo = $sub['billing_cycle']==='yearly' ? $sub['price_yearly_kobo'] : $sub['price_monthly_kobo'];
          ?>
          <tr class="tbl-row">
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
                  <div class="text-white text-xs font-medium truncate max-w-32"><?= htmlspecialchars($sub['full_name'] ?: '—') ?></div>
                  <div class="text-gray-400 text-xs truncate max-w-32"><?= htmlspecialchars($sub['email']) ?></div>
                  <a href="users.php?search=<?= urlencode($sub['email']) ?>"
                     class="text-blue-400 hover:text-blue-300 text-xs transition">#<?= (int)$sub['user_id'] ?></a>
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
              <div class="text-gray-500 text-xs mt-0.5 font-mono"><?= $kobo($priceKobo) ?>/<?= $sub['billing_cycle']==='yearly'?'yr':'mo' ?></div>
              <?php endif; ?>
            </td>
            <!-- Status -->
            <td class="p-4 hide-mobile">
              <span class="badge b-<?= $sub['status'] ?>">
                <?= ucfirst(str_replace('_',' ',$sub['status'])) ?>
              </span>
              <?php if ($sub['cancel_at_period_end']): ?>
              <div class="text-amber-400 text-xs mt-0.5"><i class="fas fa-clock text-xs"></i> Cancels at period end</div>
              <?php endif; ?>
              <?php if ($sub['retry_count'] > 0): ?>
              <div class="text-red-400 text-xs mt-0.5"><?= (int)$sub['retry_count'] ?> retries</div>
              <?php endif; ?>
            </td>
            <!-- Billing cycle -->
            <td class="p-4 hide-mobile">
              <span class="badge b-<?= $sub['billing_cycle'] ?>"><?= ucfirst($sub['billing_cycle']) ?></span>
            </td>
            <!-- Period end -->
            <td class="p-4 hide-mobile" style="min-width:130px;">
              <?php if ($sub['current_period_end']): ?>
              <div class="text-xs text-white font-medium"><?= date('M j, Y', strtotime($sub['current_period_end'])) ?></div>
              <?php if ($daysLeft !== null): ?>
              <div class="text-xs text-gray-500 mt-0.5"><?= $daysLeft ?>d remaining</div>
              <div class="period-bar mt-1">
                <div class="period-fill" style="width:<?= $barPct ?>%;background:<?= $barColor ?>"></div>
              </div>
              <?php endif; ?>
              <?php else: ?>
              <span class="text-gray-600 text-xs">—</span>
              <?php endif; ?>
            </td>
            <!-- Paystack code -->
            <td class="p-4 hide-sm">
              <?php if ($sub['paystack_subscription_code']): ?>
              <button onclick="copyText('<?= htmlspecialchars($sub['paystack_subscription_code'],ENT_QUOTES) ?>')"
                      class="font-mono text-xs text-blue-300 hover:text-blue-200 transition truncate max-w-32 block" title="Click to copy">
                <?= htmlspecialchars(substr($sub['paystack_subscription_code'],0,18)) ?>…
              </button>
              <?php else: ?>
              <span class="text-gray-600 text-xs">—</span>
              <?php endif; ?>
            </td>
            <!-- Actions -->
            <td class="p-4 text-right">
              <div class="flex items-center justify-end gap-1.5">
                <?php if ($isActive): ?>
                <button onclick="openRevokeModal(<?= (int)$sub['user_id'] ?>, '<?= htmlspecialchars($sub['email'],ENT_QUOTES) ?>')"
                        class="w-8 h-8 bg-red-500/20 hover:bg-red-500/30 rounded-lg flex items-center justify-center text-red-400 transition text-xs"
                        title="Revoke plan">
                  <i class="fas fa-ban"></i>
                </button>
                <?php endif; ?>
                <a href="subscriptions.php?search=<?= urlencode($sub['email']) ?>"
                   class="w-8 h-8 bg-blue-500/20 hover:bg-blue-500/30 rounded-lg flex items-center justify-center text-blue-400 transition text-xs"
                   title="View in subscriptions">
                  <i class="fas fa-external-link-alt"></i>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="flex flex-col sm:flex-row justify-between items-center gap-4 p-4 bg-slate-700/30 border-t border-gray-700">
      <div class="text-xs text-gray-400">
        Showing <?= number_format($subOffset+1) ?>–<?= number_format(min($subOffset+$subPerPage,$subTotal)) ?> of <?= number_format($subTotal) ?>
      </div>
      <?php if ($subPages > 1): ?>
      <div class="flex flex-wrap justify-center gap-1.5">
        <?php if ($subPage > 1): ?>
        <a href="<?= plPageUrl($subPage-1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php
        $s=max(1,$subPage-2); $e=min($subPages,$subPage+2);
        if ($s>1) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>';
        for ($i=$s;$i<=$e;$i++):
        ?>
        <a href="<?= plPageUrl($i) ?>" class="px-3 py-1.5 rounded text-xs transition <?= $i===$subPage?'bg-blue-600':'bg-slate-700 hover:bg-slate-600' ?>"><?= $i ?></a>
        <?php endfor;
        if ($e<$subPages) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>'; ?>
        <?php if ($subPage < $subPages): ?>
        <a href="<?= plPageUrl($subPage+1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="text-center py-12">
      <i class="fas fa-credit-card text-4xl text-gray-700 mb-3 block"></i>
      <p class="text-gray-400">No subscriptions found</p>
      <?php if ($subSearch||$subStatus||$subPlan): ?>
      <a href="plans.php" class="text-blue-400 text-sm mt-2 inline-block">Clear filters</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

</div>
</div>

<!-- ═══════════════════════════════
     MODALS
═══════════════════════════════ -->

<!-- Edit / Create plan modal (shared) -->
<div class="modal-backdrop" id="planModal">
  <div class="modal-box" style="max-width:640px;">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold" id="planModalTitle">Edit plan</h2>
      <button onclick="closeModal('planModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" id="pm-action" value="save_plan">
      <input type="hidden" name="plan_id" id="pm-id">

      <!-- Name + Slug -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Name <span class="text-red-400">*</span></label>
          <input class="inp" type="text" name="name" id="pm-name" maxlength="64" required placeholder="e.g. Pro">
        </div>
        <div>
          <label class="form-label">Slug <span class="text-red-400">*</span></label>
          <input class="inp font-mono" type="text" name="slug" id="pm-slug" maxlength="32" required placeholder="e.g. pro"
                 oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9_]/g,'')">
          <p class="form-hint">Used in code. Cannot change if users are on this plan.</p>
        </div>
      </div>

      <div>
        <label class="form-label">Description</label>
        <input class="inp" type="text" name="description" id="pm-desc" maxlength="500" placeholder="Short description shown on billing page">
      </div>

      <!-- Pricing -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Monthly price ($)</label>
          <input class="inp" type="number" name="price_monthly_ngn" id="pm-monthly" min="0" step="0.01" placeholder="9">
          <p class="form-hint">Enter in dollars. Stored as cents internally.</p>
        </div>
        <div>
          <label class="form-label">Yearly price ($)</label>
          <input class="inp" type="number" name="price_yearly_ngn" id="pm-yearly" min="0" step="0.01" placeholder="89">
        </div>
      </div>

      <!-- Paystack codes -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Paystack plan code (monthly)</label>
          <input class="inp font-mono" type="text" name="paystack_plan_code_monthly" id="pm-ps-monthly"
                 maxlength="64" placeholder="PLN_xxxxxxxxxxxx">
        </div>
        <div>
          <label class="form-label">Paystack plan code (yearly)</label>
          <input class="inp font-mono" type="text" name="paystack_plan_code_yearly" id="pm-ps-yearly"
                 maxlength="64" placeholder="PLN_xxxxxxxxxxxx">
        </div>
      </div>

      <!-- Credits -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Monthly credits</label>
          <input class="inp" type="number" name="credits_monthly" id="pm-cred-monthly" min="0" placeholder="100">
        </div>
        <div>
          <label class="form-label">Signup bonus credits</label>
          <input class="inp" type="number" name="credits_signup" id="pm-cred-signup" min="0" placeholder="20">
        </div>
      </div>

      <!-- Limits -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Watchlist limit (0 = unlimited)</label>
          <input class="inp" type="number" name="watchlist_limit" id="pm-wl" min="0" placeholder="5">
        </div>
        <div>
          <label class="form-label">Search history days</label>
          <input class="inp" type="number" name="search_history_days" id="pm-hist" min="1" placeholder="90">
        </div>
      </div>

      <!-- Features -->
      <div>
        <label class="form-label">Features</label>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 bg-slate-900/50 rounded-lg p-3">
          <?php
          $featureFields = [
            ['feature_whois',       'WHOIS lookup',    'pm-f-whois'],
            ['feature_backorder',   'Backorders',      'pm-f-backorder'],
            ['feature_alerts',      'Drop alerts',     'pm-f-alerts'],
            ['feature_dead_sites',  'Dead site scan',  'pm-f-dead'],
            ['feature_broker',      'Broker service',  'pm-f-broker'],
            ['feature_bulk_lookup', 'Bulk lookup',     'pm-f-bulk'],
          ];
          foreach ($featureFields as [$fname, $flabel, $fid]):
          ?>
          <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-300 select-none">
            <input type="checkbox" name="<?= $fname ?>" id="<?= $fid ?>" class="chk-feat">
            <?= $flabel ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Sort + Active -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Sort order</label>
          <input class="inp" type="number" name="sort_order" id="pm-sort" min="0" placeholder="1">
        </div>
        <div class="flex items-end pb-1">
          <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-300">
            <input type="checkbox" name="is_active" id="pm-active" class="w-4 h-4 accent-blue-500" checked>
            Active (visible on billing page)
          </label>
        </div>
      </div>

      <div class="flex gap-3 justify-end pt-3 border-t border-gray-700">
        <button type="button" onclick="closeModal('planModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-primary flex items-center gap-2">
          <i class="fas fa-save text-xs"></i> Save plan
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Grant plan modal -->
<div class="modal-backdrop" id="grantModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold text-amber-400"><i class="fas fa-bolt mr-2"></i>Grant plan to user</h2>
      <button onclick="closeModal('grantModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <p class="text-gray-400 text-sm mb-4">
      Manually assign a plan to a user without a Paystack payment — useful for team members, testing, or compensation.
    </p>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="grant_plan">
      <div>
        <label class="form-label">User <span class="text-red-400">*</span></label>
        <select class="inp" name="user_id" required>
          <option value="">— Select user —</option>
          <?php foreach ($usersList as $u): ?>
          <option value="<?= (int)$u['id'] ?>">
            #<?= $u['id'] ?> · <?= htmlspecialchars($u['email']) ?>
            <?= $u['full_name'] ? '(' . htmlspecialchars($u['full_name']) . ')' : '' ?>
            [<?= strtoupper($u['plan']??'free') ?>]
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="grid grid-cols-3 gap-3">
        <div>
          <label class="form-label">Plan <span class="text-red-400">*</span></label>
          <select class="inp" name="plan_slug">
            <?php foreach ($plans as $p): if ($p['slug'] === 'free') continue; ?>
            <option value="<?= $p['slug'] ?>"><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Billing cycle</label>
          <select class="inp" name="billing_cycle">
            <option value="monthly">Monthly</option>
            <option value="yearly">Yearly</option>
          </select>
        </div>
        <div>
          <label class="form-label">Duration (months)</label>
          <input class="inp" type="number" name="months" min="1" max="24" value="1" placeholder="1">
        </div>
      </div>
      <div class="bg-blue-500/10 border border-blue-500/20 rounded-lg px-3 py-2 text-blue-300 text-xs">
        <i class="fas fa-info-circle mr-1"></i>
        Any existing active subscription for this user will be canceled first. Monthly credits will be added immediately. No payment is recorded.
      </div>
      <div class="flex gap-3 justify-end pt-2">
        <button type="button" onclick="closeModal('grantModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-amber flex items-center gap-2">
          <i class="fas fa-bolt text-xs"></i> Grant plan
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Revoke plan modal -->
<div class="modal-backdrop" id="revokeModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold text-red-400"><i class="fas fa-ban mr-2"></i>Revoke plan</h2>
      <button onclick="closeModal('revokeModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <p class="text-gray-300 text-sm mb-4">
      Revoke the plan for <span id="rv-email" class="font-mono text-white"></span>?
      Their subscription will be canceled and they'll be downgraded to the Free plan with 10 credits.
    </p>
    <form method="POST" class="flex gap-3 justify-end">
      <input type="hidden" name="action" value="revoke_plan">
      <input type="hidden" name="user_id" id="rv-uid">
      <button type="button" onclick="closeModal('revokeModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-danger flex items-center gap-2">
        <i class="fas fa-ban text-xs"></i> Revoke plan
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
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-backdrop').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// ── Edit plan modal ───────────────────────────────────────
function openEditPlanModal(p) {
  document.getElementById('planModalTitle').textContent = 'Edit plan — ' + esc(p.name);
  document.getElementById('pm-action').value = 'save_plan';
  document.getElementById('pm-id').value     = p.id;
  document.getElementById('pm-name').value   = p.name;
  document.getElementById('pm-slug').value   = p.slug;
  document.getElementById('pm-desc').value   = p.description || '';
  document.getElementById('pm-monthly').value = Math.round((p.price_monthly_kobo||0) / 100);
  document.getElementById('pm-yearly').value  = Math.round((p.price_yearly_kobo||0) / 100);
  document.getElementById('pm-ps-monthly').value = p.paystack_plan_code_monthly || '';
  document.getElementById('pm-ps-yearly').value  = p.paystack_plan_code_yearly  || '';
  document.getElementById('pm-cred-monthly').value = p.credits_monthly || 0;
  document.getElementById('pm-cred-signup').value  = p.credits_signup  || 0;
  document.getElementById('pm-wl').value   = p.watchlist_limit      || 0;
  document.getElementById('pm-hist').value = p.search_history_days  || 7;
  document.getElementById('pm-sort').value = p.sort_order           || 0;
  document.getElementById('pm-active').checked = !!+p.is_active;

  const featureMap = {
    'pm-f-whois':     'feature_whois',
    'pm-f-backorder': 'feature_backorder',
    'pm-f-alerts':    'feature_alerts',
    'pm-f-dead':      'feature_dead_sites',
    'pm-f-broker':    'feature_broker',
    'pm-f-bulk':      'feature_bulk_lookup',
  };
  for (const [elId, key] of Object.entries(featureMap)) {
    const el = document.getElementById(elId);
    if (el) el.checked = !!+p[key];
  }
  openModal('planModal');
}

// ── Create plan modal ─────────────────────────────────────
function openCreatePlanModal() {
  document.getElementById('planModalTitle').textContent = 'New plan';
  document.getElementById('pm-action').value = 'create_plan';
  document.getElementById('pm-id').value     = '0';
  document.getElementById('pm-name').value   = '';
  document.getElementById('pm-slug').value   = '';
  document.getElementById('pm-desc').value   = '';
  document.getElementById('pm-monthly').value = '';
  document.getElementById('pm-yearly').value  = '';
  document.getElementById('pm-ps-monthly').value = '';
  document.getElementById('pm-ps-yearly').value  = '';
  document.getElementById('pm-cred-monthly').value = '0';
  document.getElementById('pm-cred-signup').value  = '0';
  document.getElementById('pm-wl').value   = '5';
  document.getElementById('pm-hist').value = '7';
  document.getElementById('pm-sort').value = '0';
  document.getElementById('pm-active').checked = true;
  ['pm-f-whois','pm-f-backorder','pm-f-alerts','pm-f-dead','pm-f-broker','pm-f-bulk']
    .forEach(id => { const el = document.getElementById(id); if (el) el.checked = false; });
  openModal('planModal');
}

// ── Revoke modal ──────────────────────────────────────────
function openRevokeModal(uid, email) {
  document.getElementById('rv-uid').value         = uid;
  document.getElementById('rv-email').textContent = email;
  openModal('revokeModal');
}

// ── Copy ──────────────────────────────────────────────────
function copyText(text) {
  navigator.clipboard.writeText(text)
    .then(() => showToast('Copied: ' + text.substring(0, 40)))
    .catch(() => showToast('Could not copy', 'err'));
}

// ── Toast ─────────────────────────────────────────────────
function showToast(msg, type = 'ok') {
  const t = document.getElementById('toast');
  const icon = document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  const c = { ok:'#10B981', warn:'#F59E0B', err:'#EF4444' };
  const i = { ok:'fa-check-circle', warn:'fa-exclamation-triangle', err:'fa-times-circle' };
  icon.className   = 'fas ' + (i[type] || 'fa-info-circle');
  icon.style.color = c[type] || '#10B981';
  t.style.transform = 'translateY(0)'; t.style.opacity = '1';
  clearTimeout(t._t);
  t._t = setTimeout(() => { t.style.transform = 'translateY(20px)'; t.style.opacity = '0'; }, 4200);
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
