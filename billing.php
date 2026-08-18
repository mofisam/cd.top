<?php
session_start();
require_once 'lib/Auth.php';
require_once 'config/database.php';

$auth = new Auth();

// ── Auth guard ─────────────────────────────────────────────
if (!isset($_COOKIE['session_token'])) { header('Location: login.php'); exit(); }
$session = $auth->verifySession($_COOKIE['session_token']);
if (!$session) {
    setcookie('session_token', '', time() - 3600, '/');
    header('Location: login.php');
    exit();
}

// ── URL helper ─────────────────────────────────────────────
$appBasePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (in_array($appBasePath, ['/', '.', '\\'])) { $appBasePath = ''; }
$assetUrl = fn(string $p): string => ($appBasePath ?: '') . '/' . ltrim($p, '/');

// ── Paystack public key (set in your config or .env) ───────
$paystackPublicKey = $_ENV['PAYSTACK_PUBLIC_KEY'];

if (!$paystackPublicKey) {
    throw new Exception("Paystack public key not set in environment");
}

// ── Fetch user ─────────────────────────────────────────────
$conn = getDBConnection();
$stmt = $conn->prepare("
    SELECT id, email, full_name, plan, credits, billing_email, billing_name, billing_phone,
           paystack_customer_code, created_at
    FROM users WHERE id = ?
");
$stmt->bind_param("i", $session['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) { header('Location: logout.php'); exit(); }

$currencySettings = getBillingCurrencySettings($conn);
$billingCurrencyMode = $currencySettings['mode'];
$usdNgnRate = $currencySettings['usd_ngn_rate'];
$defaultCurrency = ($billingCurrencyMode === 'naira' || shouldDefaultToNaira()) ? 'NGN' : 'USD';

// ── Fetch plans from DB (fallback to hardcoded if table not yet created) ──
$plans = [];
$planStmt = $conn->prepare("SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order ASC");
if ($planStmt) {
    $planStmt->execute();
    $planResult = $planStmt->get_result();
    while ($row = $planResult->fetch_assoc()) { $plans[] = $row; }
    $planStmt->close();
}
if (empty($plans)) {
    // Fallback hardcoded plans (USD cents)
    $plans = [
        ['slug'=>'free',  'name'=>'Free',  'price_monthly_kobo'=>0,       'price_yearly_kobo'=>0,        'credits_monthly'=>10,  'feature_whois'=>0,'feature_backorder'=>0,'feature_alerts'=>0,'feature_dead_sites'=>0,'feature_broker'=>0,'feature_bulk_lookup'=>0,'watchlist_limit'=>5],
        ['slug'=>'pro',   'name'=>'Pro',   'price_monthly_kobo'=>900,     'price_yearly_kobo'=>8900,     'credits_monthly'=>100, 'feature_whois'=>1,'feature_backorder'=>1,'feature_alerts'=>1,'feature_dead_sites'=>1,'feature_broker'=>0,'feature_bulk_lookup'=>0,'watchlist_limit'=>0],
        ['slug'=>'elite', 'name'=>'Elite', 'price_monthly_kobo'=>2900,    'price_yearly_kobo'=>27900,    'credits_monthly'=>500, 'feature_whois'=>1,'feature_backorder'=>1,'feature_alerts'=>1,'feature_dead_sites'=>1,'feature_broker'=>1,'feature_bulk_lookup'=>1,'watchlist_limit'=>0],
    ];
}

// ── Fetch credit packages ──────────────────────────────────
$packages = [];
$pkgStmt = $conn->prepare("SELECT * FROM credit_packages WHERE is_active = 1 ORDER BY sort_order ASC");
if ($pkgStmt) {
    $pkgStmt->execute();
    $pkgResult = $pkgStmt->get_result();
    while ($row = $pkgResult->fetch_assoc()) { $packages[] = $row; }
    $pkgStmt->close();
}
if (empty($packages)) {
    $packages = [
        ['id'=>1,'name'=>'Starter', 'credits'=>25,  'price_kobo'=>250,     'bonus_credits'=>0,  'is_popular'=>0],
        ['id'=>2,'name'=>'Standard','credits'=>60,  'price_kobo'=>500,     'bonus_credits'=>5,  'is_popular'=>1],
        ['id'=>3,'name'=>'Power',   'credits'=>150, 'price_kobo'=>1000,    'bonus_credits'=>20, 'is_popular'=>0],
        ['id'=>4,'name'=>'Bulk',    'credits'=>400, 'price_kobo'=>2500,    'bonus_credits'=>75, 'is_popular'=>0],
    ];
}

foreach ($plans as &$plan) {
    $plan['price_monthly_kobo'] = usdMinorAmount((int)$plan['price_monthly_kobo']);
    $plan['price_yearly_kobo']  = usdMinorAmount((int)$plan['price_yearly_kobo']);
}
unset($plan);

foreach ($packages as &$pkg) {
    $pkg['price_kobo'] = usdMinorAmount((int)$pkg['price_kobo']);
}
unset($pkg);

// ── Fetch payment history ──────────────────────────────────
$payments = [];
$payStmt = $conn->prepare("
    SELECT p.id, p.type, p.amount_charged_kobo, p.currency, p.status,
           p.gateway_response, p.channel, p.paystack_reference, p.created_at, p.paid_at,
           pl.name as plan_name
    FROM payments p
    LEFT JOIN plans pl ON (p.description LIKE CONCAT('%', pl.slug, '%'))
    WHERE p.user_id = ?
    ORDER BY p.created_at DESC
    LIMIT 12
");
if ($payStmt) {
    $payStmt->bind_param("i", $session['user_id']);
    $payStmt->execute();
    $payResult = $payStmt->get_result();
    while ($row = $payResult->fetch_assoc()) { $payments[] = $row; }
    $payStmt->close();
}

// ── Fetch active subscription ──────────────────────────────
$subscription = null;
$subStmt = $conn->prepare("
    SELECT s.*, p.name as plan_name, p.price_monthly_kobo, p.price_yearly_kobo
    FROM subscriptions s
    JOIN plans p ON s.plan_id = p.id
    WHERE s.user_id = ? AND s.status IN ('active','trialing','past_due','non_renewing')
    LIMIT 1
");
if ($subStmt) {
    $subStmt->bind_param("i", $session['user_id']);
    $subStmt->execute();
    $subscription = $subStmt->get_result()->fetch_assoc();
    $subStmt->close();
}

$conn->close();

// ── User meta ──────────────────────────────────────────────
$userName    = trim($user['full_name'] ?? '') ?: explode('@', $user['email'])[0];
$firstName   = explode(' ', $userName)[0];
$initials    = strtoupper(substr($userName,0,1).(strpos($userName,' ')!==false?substr($userName,strpos($userName,' ')+1,1):''));
$userPlan    = $user['plan']    ?? 'free';
$credits     = $user['credits'] ?? 10;
$billingEmail= $user['billing_email'] ?: $user['email'];

$watchlistCount = 0;
$alertCount     = 0;

// Plan intent from URL (?plan=pro&topup=1&cancel=1)
$planIntent  = in_array($_GET['plan'] ?? '', ['pro','elite']) ? $_GET['plan'] : null;
$showTopup   = isset($_GET['topup']);
$showCancel  = isset($_GET['cancel']);
$activePage  = 'billing';

// Helpers
$formatMoney = fn($amount, $currency = 'USD') => formatCurrencyMinor((int)$amount, $currency);
$formatUsdBase = fn($usdCents) => $formatMoney(usdCentsToCurrencyMinor((int)$usdCents, $defaultCurrency, $usdNgnRate), $defaultCurrency);
$planColors  = ['free'=>'--text3','pro'=>'--green2','elite'=>'--purple'];
$planIcons   = ['free'=>'fa-user','pro'=>'fa-bolt','elite'=>'fa-crown'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Billing — CheckDomain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;700;800&family=DM+Mono:wght@400;500&family=Instrument+Serif:ital@1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://js.paystack.co/v1/inline.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0A0B0E;--bg2:#111318;--bg3:#181C24;--bg4:#1E2230;
  --border:rgba(255,255,255,0.06);--border2:rgba(255,255,255,0.11);
  --text:#E9E7DF;--text2:#8A8880;--text3:#454340;
  --green:#1D9E75;--green2:#14C48A;--green-bg:rgba(29,158,117,0.1);
  --amber:#EF9F27;--amber-bg:rgba(239,159,39,0.1);
  --coral:#E8593C;--coral-bg:rgba(232,89,60,0.1);
  --purple:#7F77DD;--purple-bg:rgba(127,119,221,0.1);
  --blue:#4A90D9;--blue-bg:rgba(74,144,217,0.1);
  --mono:'DM Mono',monospace;--display:'Syne',sans-serif;
  --serif:'Instrument Serif',serif;--sb-width:224px;
}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--text);font-family:var(--display);min-height:100vh;display:flex;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;
  background-image:linear-gradient(rgba(29,158,117,0.02) 1px,transparent 1px),linear-gradient(90deg,rgba(29,158,117,0.02) 1px,transparent 1px);
  background-size:52px 52px;pointer-events:none;z-index:0}

/* ── Layout ─────────── */
.main{margin-left:var(--sb-width);flex:1;position:relative;z-index:1;min-height:100vh}

/* ── Topbar ─────────── */
.topbar{display:flex;align-items:center;justify-content:space-between;padding:15px 28px;border-bottom:1px solid var(--border);backdrop-filter:blur(12px);background:rgba(10,11,14,0.85);position:sticky;top:0;z-index:40;gap:14px}
.topbar-left{display:flex;align-items:center;gap:12px}
.topbar-right{display:flex;align-items:center;gap:10px}
.mobile-menu-btn{display:none;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;background:var(--bg2);border:1px solid var(--border);color:var(--text2);font-size:16px;cursor:pointer}
.breadcrumb{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--text3)}
.breadcrumb a{color:var(--text2);text-decoration:none;transition:color .15s}
.breadcrumb a:hover{color:var(--text)}
.topbar-btn{display:flex;align-items:center;justify-content:center;width:33px;height:33px;border-radius:8px;background:var(--bg2);border:1px solid var(--border);color:var(--text2);font-size:14px;cursor:pointer;text-decoration:none;transition:border-color .15s,color .15s}
.topbar-btn:hover{border-color:var(--border2);color:var(--text)}

/* ── Content ─────────── */
.content{padding:28px 28px 60px}
.page-title{font-family:var(--serif);font-style:italic;font-size:26px;color:var(--text);margin-bottom:4px}
.page-sub{font-size:13px;color:var(--text2);margin-bottom:28px}

/* ── Section title ─────── */
.section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.14em;color:var(--text2)}
.section-sub{font-size:12px;color:var(--text3)}

/* ── Billing toggle ─────── */
.billing-toggle{display:inline-flex;align-items:center;background:var(--bg3);border:1px solid var(--border);border-radius:9px;padding:3px;gap:2px}
.btog-btn{padding:6px 16px;border-radius:6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;cursor:pointer;background:none;border:none;color:var(--text3);font-family:var(--display);transition:all .15s}
.btog-btn.active{background:var(--bg2);color:var(--text);box-shadow:0 1px 4px rgba(0,0,0,0.3)}
.save-badge{background:var(--green-bg);color:var(--green2);font-size:9px;font-weight:700;padding:1px 5px;border-radius:3px;margin-left:4px;letter-spacing:0.08em}

/* ── Plan cards ─────────── */
.plans-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:32px}
.plan-card{background:var(--bg2);border:1px solid var(--border);border-radius:16px;padding:22px 20px 20px;display:flex;flex-direction:column;gap:16px;position:relative;overflow:hidden;transition:border-color .2s,transform .15s;cursor:default}
.plan-card:hover{transform:translateY(-2px)}
.plan-card.current{border-color:rgba(29,158,117,0.3);background:linear-gradient(160deg,rgba(29,158,117,0.05),var(--bg2) 55%)}
.plan-card.highlighted{border-color:rgba(29,158,117,0.35);background:linear-gradient(160deg,rgba(29,158,117,0.07),var(--bg2) 60%)}
.plan-card-ribbon{position:absolute;top:14px;right:14px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;padding:2px 8px;border-radius:4px}
.ribbon-popular{background:var(--green-bg);color:var(--green2);border:1px solid rgba(29,158,117,0.25)}
.ribbon-current{background:var(--bg4);color:var(--text3);border:1px solid var(--border)}

.plan-name{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.14em;color:var(--text2)}
.plan-price-wrap{line-height:1}
.plan-price{font-family:var(--mono);font-size:32px;font-weight:800;color:var(--text)}
.plan-price sup{font-size:16px;vertical-align:top;margin-top:5px;display:inline-block}
.plan-price-period{font-size:12px;color:var(--text3);margin-top:4px}
.plan-price-yearly{font-size:11px;color:var(--green);font-family:var(--mono);margin-top:2px}
.plan-desc{font-size:12px;color:var(--text2);line-height:1.6}

.plan-features{display:flex;flex-direction:column;gap:8px;flex:1}
.pf-item{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text2)}
.pf-item i{font-size:11px;flex-shrink:0}
.pf-item.on i{color:var(--green2)}
.pf-item.off{color:var(--text3)}
.pf-item.off i{color:var(--text3)}
.pf-credits{font-size:12px;font-family:var(--mono);color:var(--amber);font-weight:700;background:var(--amber-bg);padding:2px 7px;border-radius:4px;display:inline-block;margin-bottom:4px}

.plan-cta{width:100%;padding:10px;border-radius:9px;font-family:var(--display);font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;letter-spacing:0.04em;text-align:center;text-decoration:none;display:block;border:1px solid var(--border2);color:var(--text2);background:none;margin-top:auto}
.plan-cta:hover{background:var(--bg3);color:var(--text)}
.plan-cta.primary{background:var(--green);border-color:var(--green);color:#fff}
.plan-cta.primary:hover{background:var(--green2);border-color:var(--green2)}
.plan-cta.current-plan{background:var(--bg3);border-color:var(--border);color:var(--text3);cursor:default;pointer-events:none}

/* ── Current sub banner ─── */
.sub-banner{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:20px 22px;margin-bottom:28px;display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.sub-banner-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.sbi-pro{background:var(--green-bg);color:var(--green2)}
.sbi-elite{background:var(--purple-bg);color:var(--purple)}
.sbi-free{background:var(--bg3);color:var(--text3)}
.sub-banner-info{flex:1}
.sub-banner-name{font-size:16px;font-weight:800;color:var(--text);margin-bottom:3px}
.sub-banner-meta{font-size:12px;color:var(--text2);display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.sub-status-pill{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;padding:2px 8px;border-radius:4px}
.ssp-active{background:var(--green-bg);color:var(--green2)}
.ssp-trial{background:var(--blue-bg);color:var(--blue)}
.ssp-past-due{background:var(--amber-bg);color:var(--amber)}
.ssp-canceled{background:var(--coral-bg);color:var(--coral)}
.sub-banner-actions{display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap}
.sub-action-btn{padding:8px 16px;border-radius:8px;font-family:var(--display);font-size:11px;font-weight:700;cursor:pointer;text-transform:uppercase;letter-spacing:0.06em;transition:all .15s;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.sab-secondary{background:var(--bg3);color:var(--text2);border:1px solid var(--border2)!important;border-style:solid}
.sab-secondary:hover{background:var(--bg4);color:var(--text)}
.sab-danger{background:none;color:var(--coral);border:1px solid rgba(232,89,60,0.3)!important;border-style:solid}
.sab-danger:hover{background:var(--coral-bg)}

/* ── Credits section ─────── */
.credits-header-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px}
.credits-balance{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:18px 22px;margin-bottom:18px;display:flex;align-items:center;gap:18px}
.credits-bal-icon{width:44px;height:44px;border-radius:11px;background:var(--amber-bg);display:flex;align-items:center;justify-content:center;font-size:18px;color:var(--amber);flex-shrink:0}
.credits-bal-num{font-size:28px;font-weight:800;font-family:var(--mono);color:var(--text);line-height:1}
.credits-bal-label{font-size:12px;color:var(--text2);margin-top:2px}
.credits-bar-outer{flex:1;min-width:120px}
.credits-bar-label{display:flex;justify-content:space-between;font-size:11px;color:var(--text3);font-family:var(--mono);margin-bottom:5px}
.credits-bar-wrap{height:5px;background:var(--border);border-radius:3px;overflow:hidden}
.credits-bar-fill{height:100%;border-radius:3px;transition:width .6s ease}

.packages-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:32px}
.pkg-card{background:var(--bg2);border:1px solid var(--border);border-radius:13px;padding:18px 16px;display:flex;flex-direction:column;gap:12px;position:relative;overflow:hidden;transition:border-color .2s,transform .15s;cursor:pointer}
.pkg-card:hover{transform:translateY(-2px);border-color:var(--border2)}
.pkg-card.popular{border-color:rgba(239,159,39,0.3);background:linear-gradient(160deg,rgba(239,159,39,0.05),var(--bg2) 60%)}
.pkg-card.selected{border-color:var(--green);box-shadow:0 0 0 1px var(--green)}
.pkg-popular-badge{position:absolute;top:10px;right:10px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;padding:2px 7px;border-radius:4px;background:var(--amber-bg);color:var(--amber);border:1px solid rgba(239,159,39,0.25)}
.pkg-credits{font-size:24px;font-weight:800;font-family:var(--mono);color:var(--text);line-height:1}
.pkg-credits span{font-size:12px;color:var(--text3);font-weight:400}
.pkg-bonus{font-size:11px;color:var(--green2);font-family:var(--mono);font-weight:700}
.pkg-price{font-size:18px;font-weight:800;font-family:var(--mono);color:var(--text)}
.pkg-name{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.12em;color:var(--text3)}
.pkg-cta{width:100%;padding:8px;border-radius:7px;font-family:var(--display);font-size:11px;font-weight:700;cursor:pointer;border:1px solid var(--border2);color:var(--text2);background:none;transition:all .2s;margin-top:auto}
.pkg-cta:hover{background:var(--bg3);color:var(--text)}
.pkg-card.selected .pkg-cta{background:var(--green);border-color:var(--green);color:#fff}

/* ── Invoice / payment history ── */
.history-table{background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:28px}
.ht-head{display:grid;grid-template-columns:1fr 110px 110px 100px 80px;padding:10px 20px;background:var(--bg3);border-bottom:1px solid var(--border)}
.ht-th{font-size:10px;text-transform:uppercase;letter-spacing:0.12em;color:var(--text3);font-weight:600}
.ht-th.right{text-align:right}
.ht-row{display:grid;grid-template-columns:1fr 110px 110px 100px 80px;padding:13px 20px;border-bottom:1px solid var(--border);align-items:center;transition:background .12s}
.ht-row:last-child{border-bottom:none}
.ht-row:hover{background:var(--bg3)}
.ht-desc{font-size:13px;color:var(--text)}
.ht-desc-sub{font-size:10px;color:var(--text3);font-family:var(--mono);margin-top:1px}
.ht-amount{font-family:var(--mono);font-size:13px;color:var(--text);text-align:right}
.ht-date{font-family:var(--mono);font-size:11px;color:var(--text2)}
.ht-ref{font-family:var(--mono);font-size:10px;color:var(--text3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pay-status{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;padding:2px 7px;border-radius:4px;text-align:center;white-space:nowrap}
.ps-success{background:var(--green-bg);color:var(--green2)}
.ps-failed{background:var(--coral-bg);color:var(--coral)}
.ps-pending{background:var(--amber-bg);color:var(--amber)}
.ps-refunded{background:var(--bg4);color:var(--text3)}

/* ── Empty state ─────── */
.empty-state{text-align:center;padding:48px 20px;display:flex;flex-direction:column;align-items:center;gap:10px}
.empty-icon{width:52px;height:52px;border-radius:13px;background:var(--bg3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:20px;color:var(--text3);margin-bottom:4px}
.empty-title{font-size:14px;font-weight:700;color:var(--text)}
.empty-sub{font-size:12px;color:var(--text2);max-width:280px;line-height:1.6}

/* ── Promo code ─────── */
.promo-row{display:flex;gap:10px;align-items:center}
.promo-input{flex:1;background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:9px 13px;font-family:var(--mono);font-size:13px;color:var(--text);outline:none;transition:border-color .2s;min-width:0}
.promo-input::placeholder{color:var(--text3)}
.promo-input:focus{border-color:var(--green)}
.promo-btn{background:var(--bg3);border:1px solid var(--border2);border-radius:8px;padding:9px 18px;font-family:var(--display);font-size:12px;font-weight:700;color:var(--text2);cursor:pointer;transition:all .15s;white-space:nowrap}
.promo-btn:hover{background:var(--bg4);color:var(--text)}
.promo-result{font-size:12px;margin-top:6px;display:none}
.promo-result.ok{color:var(--green2)}
.promo-result.err{color:var(--coral)}

/* ── Cancel modal ─────── */
.modal-overlay{position:fixed;inset:0;z-index:200;background:rgba(0,0,0,0.65);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--border2);border-radius:16px;padding:28px;max-width:400px;width:90%;transform:scale(.95);transition:transform .2s}
.modal-overlay.open .modal{transform:scale(1)}
.modal-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:14px}
.modal-icon.warn{background:var(--amber-bg);color:var(--amber)}
.modal-title{font-size:16px;font-weight:700;color:var(--text);margin-bottom:8px}
.modal-body{font-size:13px;color:var(--text2);line-height:1.6;margin-bottom:20px}
.modal-body ul{padding-left:18px;margin-top:8px;display:flex;flex-direction:column;gap:4px}
.modal-actions{display:flex;gap:10px;justify-content:flex-end}
.modal-cancel-btn{background:none;border:1px solid var(--border2);border-radius:8px;padding:9px 18px;font-family:var(--display);font-size:12px;color:var(--text2);cursor:pointer;transition:all .15s}
.modal-cancel-btn:hover{background:var(--bg3);color:var(--text)}
.modal-confirm-btn{border:none;border-radius:8px;padding:9px 18px;font-family:var(--display);font-size:12px;font-weight:700;cursor:pointer;transition:opacity .15s}
.modal-confirm-btn.danger{background:var(--coral);color:#fff}
.modal-confirm-btn.danger:hover{opacity:.85}

/* ── Toast ─────── */
.toast{position:fixed;bottom:28px;right:28px;z-index:999;background:var(--bg3);border:1px solid var(--border2);border-radius:10px;padding:12px 18px;font-size:13px;color:var(--text);box-shadow:0 8px 32px rgba(0,0,0,.4);transform:translateY(20px);opacity:0;transition:all .3s ease;max-width:320px;display:flex;align-items:center;gap:9px}
.toast.show{transform:translateY(0);opacity:1}
.toast.success{border-color:rgba(29,158,117,.3)}
.toast.error{border-color:rgba(232,89,60,.3)}

/* ── Responsive ─────── */
@media(max-width:1100px){.packages-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:960px){
  .plans-grid{grid-template-columns:1fr 1fr}
  .ht-head,.ht-row{grid-template-columns:1fr 100px 90px 80px}
  .ht-th:nth-child(3),.ht-row>*:nth-child(3){display:none}
}
@media(max-width:768px){
  .main{margin-left:0}
  .mobile-menu-btn{display:flex}
  .content{padding:20px 16px 50px}
  .plans-grid{grid-template-columns:1fr}
  .packages-grid{grid-template-columns:1fr 1fr}
  .ht-head,.ht-row{grid-template-columns:1fr 90px 80px}
  .ht-th:nth-child(2),.ht-row>*:nth-child(2),.ht-th:nth-child(3),.ht-row>*:nth-child(3){display:none}
  .sub-banner{flex-direction:column;align-items:flex-start}
}
@media(max-width:480px){
  .packages-grid{grid-template-columns:1fr}
  .plans-grid{grid-template-columns:1fr}
  .credits-balance{flex-direction:column;align-items:flex-start}
}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:49}
.sidebar-overlay.show{display:block}
</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<?php require_once 'includes/sidebar.php'; ?>

<main class="main">

  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-left">
      <button class="mobile-menu-btn" onclick="openSidebar()"><i class="fas fa-bars"></i></button>
      <div class="breadcrumb">
        <a href="<?= htmlspecialchars($assetUrl('dashboard.php')) ?>">Dashboard</a>
        <span style="color:var(--text3);font-size:9px;"><i class="fas fa-chevron-right"></i></span>
        <span style="color:var(--text);">Billing</span>
      </div>
    </div>
    <div class="topbar-right">
      <a href="<?= htmlspecialchars($assetUrl('account-settings.php#plan')) ?>" class="topbar-btn" title="Account settings">
        <i class="fas fa-cog"></i>
      </a>
    </div>
  </div>

  <div class="content">

    <div class="page-title">Plans &amp; Billing.</div>
    <div class="page-sub">Manage your subscription, credits, and payment history.</div>

    <!-- ═══════════════════════════════════════
         CURRENT SUBSCRIPTION BANNER
    ════════════════════════════════════════ -->
    <?php
    $subStatusClass = match($subscription['status'] ?? ($userPlan === 'free' ? 'free' : 'active')) {
        'active'        => 'ssp-active',
        'trialing'      => 'ssp-trial',
        'past_due'      => 'ssp-past-due',
        'non_renewing'  => 'ssp-past-due',
        'canceled'      => 'ssp-canceled',
        default         => ''
    };
    $subStatusLabel = match($subscription['status'] ?? ($userPlan === 'free' ? 'free' : 'active')) {
        'active'        => 'Active',
        'trialing'      => 'Trial',
        'past_due'      => 'Past due',
        'non_renewing'  => 'Non-renewing',
        'canceled'      => 'Canceled',
        default         => 'Free plan'
    };
    ?>
    <div class="sub-banner">
      <div class="sub-banner-icon sbi-<?= $userPlan ?>">
        <i class="fas <?= $planIcons[$userPlan] ?? 'fa-user' ?>"></i>
      </div>
      <div class="sub-banner-info">
        <div class="sub-banner-name">
          <?= ucfirst($userPlan) ?> Plan
          <?php if ($subStatusClass): ?>
          <span class="sub-status-pill <?= $subStatusClass ?>" style="margin-left:8px;"><?= $subStatusLabel ?></span>
          <?php endif; ?>
        </div>
        <div class="sub-banner-meta">
          <?php if ($subscription): ?>
            <?php if ($subscription['next_billing_at']): ?>
            <span><i class="fas fa-calendar" style="font-size:10px;margin-right:4px;"></i>
              Next billing: <?= date('M j, Y', strtotime($subscription['next_billing_at'])) ?>
            </span>
            <?php endif; ?>
            <?php if ($subscription['billing_cycle']): ?>
            <span><i class="fas fa-sync" style="font-size:10px;margin-right:4px;"></i>
              <?= ucfirst($subscription['billing_cycle']) ?> billing
            </span>
            <?php endif; ?>
            <?php if ($subscription['cancel_at_period_end']): ?>
            <span style="color:var(--amber);">
              <i class="fas fa-exclamation-circle" style="font-size:10px;margin-right:4px;"></i>
              Cancels <?= date('M j, Y', strtotime($subscription['current_period_end'])) ?>
            </span>
            <?php endif; ?>
          <?php else: ?>
            <span>No active subscription</span>
          <?php endif; ?>
          <span style="font-family:var(--mono);">
            <i class="fas fa-bolt" style="font-size:10px;margin-right:4px;color:var(--amber);"></i>
            <?= $credits ?> credits remaining
          </span>
        </div>
      </div>
      <div class="sub-banner-actions">
        <?php if ($subscription && !$subscription['cancel_at_period_end'] && $userPlan !== 'free'): ?>
        <button class="sub-action-btn sab-secondary" onclick="openCancelModal()">
          <i class="fas fa-times" style="font-size:10px;"></i> Cancel plan
        </button>
        <?php endif; ?>
        <?php if ($userPlan === 'free'): ?>
        <button class="sub-action-btn sab-secondary" onclick="scrollToPlans()">
          <i class="fas fa-arrow-up" style="font-size:10px;"></i> Upgrade now
        </button>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($assetUrl('account-settings.php#plan')) ?>"
           class="sub-action-btn sab-secondary" style="text-decoration:none;">
          <i class="fas fa-file-alt" style="font-size:10px;"></i> View invoices
        </a>
      </div>
    </div>

    <!-- ═══════════════════════════════════════
         SUBSCRIPTION PLANS
    ════════════════════════════════════════ -->
    <div id="plans-section">
      <div class="section-head">
        <div>
          <div class="section-title">Subscription plans</div>
          <div class="section-sub">All plans include domain availability checks. Credits reset each billing cycle.</div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end;">
          <?php if ($billingCurrencyMode === 'auto'): ?>
          <div class="billing-toggle" id="currencyToggle">
            <button class="btog-btn <?= $defaultCurrency === 'USD' ? 'active' : '' ?>" id="btnCurrencyUSD" onclick="setCurrency('USD')">USD</button>
            <button class="btog-btn <?= $defaultCurrency === 'NGN' ? 'active' : '' ?>" id="btnCurrencyNGN" onclick="setCurrency('NGN')">NGN</button>
          </div>
          <?php else: ?>
          <div class="billing-toggle"><button class="btog-btn active" type="button">NGN only</button></div>
          <?php endif; ?>
          <div class="billing-toggle" id="billingToggle">
            <button class="btog-btn active" id="btnMonthly" onclick="setBilling('monthly')">Monthly</button>
            <button class="btog-btn" id="btnYearly" onclick="setBilling('yearly')">
              Yearly <span class="save-badge">Save ~18%</span>
            </button>
          </div>
        </div>
      </div>

      <div class="plans-grid">
        <?php foreach ($plans as $plan):
          $slug       = $plan['slug'];
          $isCurrent  = ($slug === $userPlan);
          $isPopular  = ($slug === 'pro');
          $monthlyMinor = usdCentsToCurrencyMinor((int)$plan['price_monthly_kobo'], $defaultCurrency, $usdNgnRate);
          $yearlyMinor  = usdCentsToCurrencyMinor((int)$plan['price_yearly_kobo'],  $defaultCurrency, $usdNgnRate);
          $monthlyEquiv = $plan['price_yearly_kobo'] > 0 ? (int)round($yearlyMinor / 12) : 0;

          $features = [
            [$plan['credits_monthly'] . ' credits / month',   true,  'credits'],
            ['Unlimited watchlist',                            $plan['watchlist_limit'] == 0],
            ['Basic availability check',                       true],
            ['WHOIS deep lookup',                              $plan['feature_whois']],
            ['Drop &amp; expiry alerts',                       $plan['feature_alerts']],
            ['Backorder placement',                            $plan['feature_backorder']],
            ['Dead-site detection',                            $plan['feature_dead_sites']],
            ['Broker service',                                 $plan['feature_broker']],
          ];
        ?>
        <div class="plan-card <?= $isCurrent ? 'current' : ($isPopular ? 'highlighted' : '') ?>">

          <?php if ($isCurrent): ?>
          <span class="plan-card-ribbon ribbon-current">Current plan</span>
          <?php elseif ($isPopular): ?>
          <span class="plan-card-ribbon ribbon-popular">Most popular</span>
          <?php endif; ?>

          <div class="plan-name"><?= htmlspecialchars($plan['name']) ?></div>

          <div class="plan-price-wrap">
            <?php if ($slug === 'free'): ?>
            <div class="plan-price"><sup><?= $defaultCurrency === 'NGN' ? '₦' : '$' ?></sup>0</div>
            <div class="plan-price-period">Free forever</div>
            <?php else: ?>
            <div class="plan-price" id="price-<?= $slug ?>">
              <sup><?= $defaultCurrency === 'NGN' ? '₦' : '$' ?></sup><?= number_format($monthlyMinor / 100, 0, '.', ',') ?>
            </div>
            <div class="plan-price-period" id="period-<?= $slug ?>">/month</div>
            <div class="plan-price-yearly" id="yearly-note-<?= $slug ?>" style="display:none;">
              <?= $formatMoney($yearlyMinor, $defaultCurrency) ?> billed annually
            </div>
            <?php endif; ?>
          </div>

          <div class="plan-features">
            <?php foreach ($features as $feat):
              $isCredits = isset($feat[2]) && $feat[2] === 'credits';
            ?>
            <?php if ($isCredits): ?>
            <div class="pf-credits"><?= $feat[0] ?></div>
            <?php else: ?>
            <div class="pf-item <?= $feat[1] ? 'on' : 'off' ?>">
              <i class="fas <?= $feat[1] ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
              <?= $feat[0] ?>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
          </div>

          <?php if ($isCurrent): ?>
          <span class="plan-cta current-plan">Current plan</span>
          <?php elseif ($slug === 'free'): ?>
          <a href="#" class="plan-cta" onclick="confirmDowngrade(event)">Downgrade to Free</a>
          <?php else: ?>
          <button class="plan-cta primary"
                  onclick="initSubscription('<?= $slug ?>')"
                  id="cta-<?= $slug ?>">
            <?= $slug === 'elite' ? 'Get Elite' : 'Upgrade to ' . $plan['name'] ?> →
          </button>
          <?php endif; ?>

        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ═══════════════════════════════════════
         CREDITS TOP-UP
    ════════════════════════════════════════ -->
    <div id="topup-section">
      <div class="credits-header-row">
        <div>
          <div class="section-title">Credit top-ups</div>
          <div class="section-sub">One-time credit bundles. Credits never expire.</div>
        </div>
      </div>

      <!-- Balance -->
      <?php
      $planCreditMax = match($userPlan) { 'pro' => 100, 'elite' => 500, default => 10 };
      $creditPct     = min(100, round(($credits / $planCreditMax) * 100));
      $creditBarColor= $creditPct > 50 ? 'var(--green)' : ($creditPct > 20 ? 'var(--amber)' : 'var(--coral)');
      ?>
      <div class="credits-balance">
        <div class="credits-bal-icon"><i class="fas fa-bolt"></i></div>
        <div>
          <div class="credits-bal-num"><?= number_format($credits) ?></div>
          <div class="credits-bal-label">Credits available · <?= ucfirst($userPlan) ?> plan</div>
        </div>
        <div class="credits-bar-outer">
          <div class="credits-bar-label">
            <span>Balance</span>
            <span><?= $credits ?> / <?= $planCreditMax ?> plan credits</span>
          </div>
          <div class="credits-bar-wrap">
            <div class="credits-bar-fill" style="width:<?= $creditPct ?>%;background:<?= $creditBarColor ?>;"></div>
          </div>
        </div>
      </div>

      <!-- Credit cost reference -->
      <div style="background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:14px 18px;margin-bottom:18px;display:grid;grid-template-columns:repeat(4,1fr);gap:8px;">
        <?php
        $costItems = [
          ['fas fa-search',    'Domain check',   '1 credit'],
          ['fas fa-file-alt',  'WHOIS lookup',   '3 credits'],
          ['fas fa-clock',     'Backorder',      '5 credits'],
          ['fas fa-skull',     'Dead-site scan', '2 credits'],
        ];
        foreach ($costItems as [$icon, $label, $cost]):
        ?>
        <div style="text-align:center;padding:10px 8px;background:var(--bg3);border-radius:8px;border:1px solid var(--border);">
          <i class="fas <?= str_replace('fas ','',$icon) ?>" style="font-size:14px;color:var(--text2);margin-bottom:6px;display:block;"></i>
          <div style="font-size:10px;color:var(--text3);text-transform:uppercase;letter-spacing:0.1em;margin-bottom:3px;"><?= $label ?></div>
          <div style="font-size:12px;font-family:var(--mono);font-weight:700;color:var(--amber);"><?= $cost ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="packages-grid" id="packagesGrid">
        <?php foreach ($packages as $pkg):
          $total = $pkg['credits'] + ($pkg['bonus_credits'] ?? 0);
        ?>
        <div class="pkg-card <?= $pkg['is_popular'] ? 'popular' : '' ?>"
             id="pkg-<?= $pkg['id'] ?>"
             onclick="selectPackage(<?= $pkg['id'] ?>, <?= $pkg['price_kobo'] ?>)">
          <?php if ($pkg['is_popular']): ?>
          <span class="pkg-popular-badge">Best value</span>
          <?php endif; ?>
          <div class="pkg-name"><?= htmlspecialchars($pkg['name']) ?></div>
          <div class="pkg-credits">
            <?= number_format($pkg['credits']) ?> <span>credits</span>
            <?php if ($pkg['bonus_credits'] > 0): ?>
            <div class="pkg-bonus">+ <?= $pkg['bonus_credits'] ?> bonus = <?= $total ?> total</div>
            <?php endif; ?>
          </div>
          <div class="pkg-price" data-usd-cents="<?= (int)$pkg['price_kobo'] ?>"><?= $formatUsdBase($pkg['price_kobo']) ?></div>
          <button class="pkg-cta">Buy now</button>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Promo code -->
      <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:16px 20px;margin-bottom:32px;">
        <div style="font-size:12px;font-weight:700;color:var(--text);margin-bottom:10px;">
          <i class="fas fa-tag" style="color:var(--green2);margin-right:6px;font-size:11px;"></i> Promo code
        </div>
        <div class="promo-row">
          <input class="promo-input" type="text" id="promoInput" placeholder="Enter promo code e.g. LAUNCH50" autocomplete="off" maxlength="32">
          <button class="promo-btn" onclick="applyPromo()">Apply</button>
        </div>
        <div class="promo-result" id="promoResult"></div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════
         PAYMENT HISTORY
    ════════════════════════════════════════ -->
    <div id="history">
      <div class="section-head" style="margin-bottom:14px;">
        <div class="section-title">Payment history</div>
      </div>

      <?php if (!empty($payments)): ?>
      <div class="history-table">
        <div class="ht-head">
          <div class="ht-th">Description</div>
          <div class="ht-th">Reference</div>
          <div class="ht-th">Date</div>
          <div class="ht-th right">Amount</div>
          <div class="ht-th right">Status</div>
        </div>
        <?php foreach ($payments as $pay):
          $typeLabel = match($pay['type']) {
            'subscription'  => 'Subscription · ' . ($pay['plan_name'] ?? ucfirst($userPlan) . ' plan'),
            'credit_topup'  => 'Credit top-up',
            'one_time'      => 'One-time charge',
            default         => 'Charge',
          };
          $statusClass = match($pay['status']) {
            'success'           => 'ps-success',
            'failed','reversed' => 'ps-failed',
            'refunded'          => 'ps-refunded',
            default             => 'ps-pending',
          };
        ?>
        <div class="ht-row">
          <div>
            <div class="ht-desc"><?= htmlspecialchars($typeLabel) ?></div>
            <div class="ht-desc-sub"><?= htmlspecialchars($pay['gateway_response'] ?? '') ?></div>
          </div>
          <div class="ht-ref"><?= htmlspecialchars($pay['paystack_reference'] ?? '—') ?></div>
          <div class="ht-date"><?= date('M j, Y', strtotime($pay['created_at'])) ?></div>
          <div class="ht-amount"><?= $formatMoney($pay['amount_charged_kobo'], $pay['currency'] ?? 'USD') ?></div>
          <div style="text-align:right;"><span class="pay-status <?= $statusClass ?>"><?= ucfirst($pay['status']) ?></span></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="history-table">
        <div class="empty-state">
          <div class="empty-icon"><i class="fas fa-receipt"></i></div>
          <div class="empty-title">No payments yet</div>
          <div class="empty-sub">Once you subscribe or purchase credits, your invoices will appear here.</div>
        </div>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /.content -->
</main>

<!-- ── Cancel plan modal ──────────────────────────── -->
<div class="modal-overlay" id="cancelModal">
  <div class="modal">
    <div class="modal-icon warn"><i class="fas fa-exclamation-triangle"></i></div>
    <div class="modal-title">Cancel your <?= ucfirst($userPlan) ?> plan?</div>
    <div class="modal-body">
      You'll keep all <?= ucfirst($userPlan) ?> features until the end of your current billing period, then your account will revert to the Free plan.
      <ul>
        <li>Unused credits will be forfeited</li>
        <li>Watchlist above 5 domains will stop monitoring</li>
        <li>Active backorders will remain in place</li>
      </ul>
    </div>
    <div class="modal-actions">
      <button class="modal-cancel-btn" onclick="closeCancelModal()">Keep my plan</button>
      <button class="modal-confirm-btn danger" onclick="submitCancel()">Yes, cancel</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast">
  <i class="fas fa-check-circle" id="toastIcon" style="font-size:14px;flex-shrink:0;color:var(--green2);"></i>
  <span id="toastText"></span>
</div>

<script>
const APP_BASE         = <?= json_encode($appBasePath ?? '') ?>;
const USER_EMAIL       = <?= json_encode($billingEmail) ?>;
const USER_NAME        = <?= json_encode($userName) ?>;
const PAYSTACK_PK      = <?= json_encode($paystackPublicKey) ?>;
const CURRENT_PLAN     = <?= json_encode($userPlan) ?>;
const API_BILLING      = `${APP_BASE}/api/billing.php`;
const CURRENCY_MODE    = <?= json_encode($billingCurrencyMode) ?>;
const USD_NGN_RATE     = <?= json_encode($usdNgnRate) ?>;

// Plan prices in USD cents
const PLAN_PRICES = <?= json_encode(array_column($plans, null, 'slug')) ?>;
let   billingCycle   = 'monthly';
let   selectedCurrency = <?= json_encode($defaultCurrency) ?>;
let   selectedPkgId  = null;
let   selectedPkgAmt = 0;
let   appliedPromo   = null;

const currencySymbol = currency => currency === 'NGN' ? '₦' : '$';
const currencyLocale = currency => currency === 'NGN' ? 'en-NG' : 'en-US';
const convertUsdCents = (usdCents, currency = selectedCurrency) => {
  const cents = parseInt(usdCents || 0, 10);
  return currency === 'NGN' ? Math.round((cents / 100) * USD_NGN_RATE * 100) : cents;
};
const formatMinor = (amountMinor, currency = selectedCurrency) =>
  currencySymbol(currency) + Number((amountMinor || 0) / 100).toLocaleString(currencyLocale(currency), { maximumFractionDigits: 0 });

function setCurrency(currency) {
  if (CURRENCY_MODE !== 'auto') currency = 'NGN';
  selectedCurrency = currency === 'NGN' ? 'NGN' : 'USD';
  document.getElementById('btnCurrencyUSD')?.classList.toggle('active', selectedCurrency === 'USD');
  document.getElementById('btnCurrencyNGN')?.classList.toggle('active', selectedCurrency === 'NGN');
  updateCurrencyPrices();
}

function updateCurrencyPrices() {
  setBilling(billingCycle);
  document.querySelectorAll('.plan-price sup').forEach(el => { el.textContent = currencySymbol(selectedCurrency); });
  document.querySelectorAll('.pkg-price[data-usd-cents]').forEach(el => {
    el.textContent = formatMinor(convertUsdCents(el.dataset.usdCents));
  });
}

// ── Billing cycle toggle ──────────────────────────────────
function setBilling(cycle) {
  billingCycle = cycle;
  document.getElementById('btnMonthly').classList.toggle('active', cycle === 'monthly');
  document.getElementById('btnYearly').classList.toggle('active',  cycle === 'yearly');

  <?php foreach ($plans as $plan): if ($plan['slug'] === 'free') continue; ?>
  (function() {
    const slug        = <?= json_encode($plan['slug']) ?>;
    const monthlyKobo = convertUsdCents(<?= (int)$plan['price_monthly_kobo'] ?>);
    const yearlyKobo  = convertUsdCents(<?= (int)$plan['price_yearly_kobo'] ?>);
    const monthlyEq   = Math.round(yearlyKobo / 12 / 100);
    const priceEl     = document.getElementById('price-' + slug);
    const periodEl    = document.getElementById('period-' + slug);
    const yearlyNote  = document.getElementById('yearly-note-' + slug);
    if (!priceEl) return;
    if (cycle === 'yearly') {
      priceEl.innerHTML   = `<sup>${currencySymbol(selectedCurrency)}</sup>${monthlyEq.toLocaleString(currencyLocale(selectedCurrency))}`;
      periodEl.textContent = '/month, billed yearly';
      if (yearlyNote) yearlyNote.style.display = 'block';
      if (yearlyNote) yearlyNote.textContent = `${formatMinor(yearlyKobo)} billed annually`;
    } else {
      priceEl.innerHTML   = `<sup>${currencySymbol(selectedCurrency)}</sup>${(monthlyKobo/100).toLocaleString(currencyLocale(selectedCurrency), { maximumFractionDigits: 0 })}`;
      periodEl.textContent = '/month';
      if (yearlyNote) yearlyNote.style.display = 'none';
    }
  })();
  <?php endforeach; ?>
}

// ── Paystack subscription ─────────────────────────────────
function initSubscription(planSlug) {
  const planData  = PLAN_PRICES[planSlug];
  if (!planData) return;
  const amountUsdCents = billingCycle === 'yearly'
    ? parseInt(planData.price_yearly_kobo)
    : parseInt(planData.price_monthly_kobo);
  const amountKobo = convertUsdCents(amountUsdCents);
  if (amountKobo === 0) return;

  const ref = 'SUB_' + Date.now() + '_' + Math.random().toString(36).substr(2,6).toUpperCase();

  const handler = PaystackPop.setup({
    key:       PAYSTACK_PK,
    email:     USER_EMAIL,
    amount:    amountKobo,
    currency:  selectedCurrency,
    ref:       ref,
    metadata: {
      custom_fields: [
        { display_name: 'Plan',          variable_name: 'plan',           value: planSlug },
        { display_name: 'Billing cycle', variable_name: 'billing_cycle',  value: billingCycle },
        { display_name: 'Currency',      variable_name: 'currency',       value: selectedCurrency },
        { display_name: 'User name',     variable_name: 'user_name',      value: USER_NAME },
        { display_name: 'Promo code',    variable_name: 'promo_code',     value: appliedPromo || '' },
      ]
    },
    channels: ['card', 'bank', 'ussd', 'bank_transfer'],
    onClose: function() {
      showToast('Payment window closed.', 'error');
    },
    callback: function(response) {
      verifyPayment(response.reference, 'subscription', planSlug);
    }
  });
  handler.openIframe();
}

// ── Paystack credit top-up ────────────────────────────────
function selectPackage(pkgId, priceKobo) {
  document.querySelectorAll('.pkg-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('pkg-' + pkgId).classList.add('selected');
  selectedPkgId  = pkgId;
  selectedPkgAmt = convertUsdCents(priceKobo);

  // Short delay then launch Paystack
  setTimeout(() => initTopup(pkgId, priceKobo), 200);
}

function initTopup(pkgId, amountKobo) {
  amountKobo = convertUsdCents(amountKobo);
  const ref = 'TOP_' + Date.now() + '_' + Math.random().toString(36).substr(2,6).toUpperCase();

  const handler = PaystackPop.setup({
    key:      PAYSTACK_PK,
    email:    USER_EMAIL,
    amount:   amountKobo,
    currency: selectedCurrency,
    ref:      ref,
    metadata: {
      custom_fields: [
        { display_name: 'Package ID',  variable_name: 'package_id',  value: pkgId },
        { display_name: 'User name',   variable_name: 'user_name',   value: USER_NAME },
        { display_name: 'Currency',    variable_name: 'currency',    value: selectedCurrency },
        { display_name: 'Promo code',  variable_name: 'promo_code',  value: appliedPromo || '' },
      ]
    },
    channels: ['card', 'bank', 'ussd', 'bank_transfer'],
    onClose: function() {
      document.querySelectorAll('.pkg-card').forEach(c => c.classList.remove('selected'));
      showToast('Payment window closed.', 'error');
    },
    callback: function(response) {
      verifyPayment(response.reference, 'credit_topup', pkgId);
    }
  });
  handler.openIframe();
}

// ── Verify payment on server ──────────────────────────────
async function verifyPayment(reference, type, meta) {
  showToast('Verifying payment…');
  try {
    const res  = await fetch(API_BILLING, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ action: 'verify', reference, type, meta, meta2: billingCycle, currency: selectedCurrency, promo: appliedPromo })
    });
    const data = await res.json();

    if (data.success) {
      showToast(data.message || 'Payment successful! Your plan has been updated.', 'success');
      setTimeout(() => location.reload(), 1800);
    } else {
      showToast(data.message || 'Payment verification failed. Contact support if charged.', 'error');
    }
  } catch {
    showToast('Verification error — please contact support with your reference: ' + reference, 'error');
  }
}

// ── Promo code ─────────────────────────────────────────────
async function applyPromo() {
  const code   = document.getElementById('promoInput').value.trim().toUpperCase();
  const result = document.getElementById('promoResult');
  if (!code) return;

  result.style.display = 'block';
  result.className     = 'promo-result';
  result.textContent   = 'Checking…';

  try {
    const res  = await fetch(API_BILLING, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ action: 'validate_promo', code, currency: selectedCurrency })
    });
    const data = await res.json();

    if (data.valid) {
      appliedPromo     = code;
      result.className = 'promo-result ok';
      result.textContent = '✓ ' + (data.description || 'Promo code applied!');
    } else {
      appliedPromo     = null;
      result.className = 'promo-result err';
      result.textContent = '✗ ' + (data.message || 'Invalid or expired promo code.');
    }
  } catch {
    result.className = 'promo-result err';
    result.textContent = 'Could not validate code. Try again.';
  }
  result.style.display = 'block';
}

// ── Downgrade to Free ──────────────────────────────────────
function confirmDowngrade(e) {
  e.preventDefault();
  if (!confirm('Downgrade to the Free plan? Your subscription will be canceled at period end and credits will reset to 10.')) return;
  openCancelModal();
}

// ── Cancel plan ────────────────────────────────────────────
function openCancelModal()  { document.getElementById('cancelModal').classList.add('open'); }
function closeCancelModal() { document.getElementById('cancelModal').classList.remove('open'); }
document.getElementById('cancelModal').addEventListener('click', e => {
  if (e.target === e.currentTarget) closeCancelModal();
});

async function submitCancel() {
  closeCancelModal();
  try {
    const res  = await fetch(API_BILLING, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ action: 'cancel_subscription' })
    });
    const data = await res.json();
    showToast(data.message || (data.success ? 'Subscription canceled.' : 'Failed to cancel.'),
              data.success ? 'success' : 'error');
    if (data.success) setTimeout(() => location.reload(), 1800);
  } catch {
    showToast('Network error. Please try again.', 'error');
  }
}

// ── Scroll helpers ─────────────────────────────────────────
function scrollToPlans() {
  document.getElementById('plans-section').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Toast ──────────────────────────────────────────────────
function showToast(msg, type = 'success') {
  const t    = document.getElementById('toast');
  const icon = document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  icon.className   = `fas ${type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'}`;
  icon.style.color = type === 'error' ? 'var(--coral)' : 'var(--green2)';
  t.className = `toast show ${type}`;
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.remove('show'), 4000);
}

// ── Mobile sidebar ─────────────────────────────────────────
function openSidebar()  {
  document.getElementById('cdSidebar').classList.add('open');
  document.getElementById('sidebarOverlay').classList.add('show');
}
function closeSidebar() {
  document.getElementById('cdSidebar').classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('show');
}

// ── Auto-scroll on intent ──────────────────────────────────
window.addEventListener('DOMContentLoaded', () => {
  <?php if ($planIntent): ?>
  setTimeout(() => {
    scrollToPlans();
    const btn = document.getElementById('cta-<?= $planIntent ?>');
    if (btn) btn.style.animation = 'none';
  }, 400);
  <?php endif; ?>
  <?php if ($showTopup): ?>
  setTimeout(() => {
    document.getElementById('topup-section').scrollIntoView({ behavior: 'smooth' });
  }, 300);
  <?php endif; ?>
  <?php if ($showCancel): ?>
  setTimeout(openCancelModal, 400);
  <?php endif; ?>
});
</script>

</body>
</html>
