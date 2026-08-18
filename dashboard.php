<?php
session_start();
require_once 'lib/Auth.php';
require_once 'config/database.php';

$auth = new Auth();
if (!isset($_COOKIE['session_token'])) { header('Location: login.php'); exit(); }
$session = $auth->verifySession($_COOKIE['session_token']);
if (!$session) {
    setcookie('session_token', '', time() - 3600, '/');
    header('Location: login.php'); exit();
}

$user = $auth->getUserById($session['user_id']);
if (!$user) {
    // Account row missing/deleted but session still valid — bail out safely.
    setcookie('session_token', '', time() - 3600, '/');
    header('Location: login.php'); exit();
}

$conn = getDBConnection();
if (function_exists('ensurePinnedDomainTables')) {
    ensurePinnedDomainTables($conn);
}

$uid = (int)$session['user_id'];

// Safe query helper — never returns false, always an array.
$q = function (string $sql) use ($conn): array {
    $r = @$conn->query($sql);
    if ($r === false) return [];
    $row = $r->fetch_assoc();
    return $row ?? [];
};

// ── User meta ──────────────────────────────────────────────────────────────
$userPlan   = $user['plan'] ?? 'free';
$credits    = (int)($user['credits'] ?? 0);
$planMax    = ['free' => 10, 'pro' => 100, 'elite' => 500][$userPlan] ?? 10;
$creditsPct = $planMax > 0 ? min(100, (int) round($credits / $planMax * 100)) : 0;

$userName  = trim($user['full_name'] ?? '') ?: explode('@', $user['email'] ?? 'user')[0];
$firstName = explode(' ', $userName)[0] ?: 'there';

// ── Watchlist ──────────────────────────────────────────────────────────────
$watchlist = [];
if ($st = $conn->prepare("SELECT domain_name, pinned_at FROM pinned_domains WHERE user_id=? AND status='active' ORDER BY pinned_at DESC LIMIT 6")) {
    $st->bind_param("i", $uid);
    $st->execute();
    $watchlist = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}
$watchlistTotal = (int)($q("SELECT COUNT(*) as c FROM pinned_domains WHERE user_id=$uid AND status='active'")['c'] ?? 0);

// ── Recent searches ────────────────────────────────────────────────────────
$recentSearches = [];
if ($st = $conn->prepare("SELECT domain_name, tld, result_status, searched_at FROM domain_searches WHERE user_id=? ORDER BY searched_at DESC LIMIT 6")) {
    $st->bind_param("i", $uid);
    $st->execute();
    $recentSearches = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}
$totalSearches = (int)($q("SELECT COUNT(*) as c FROM domain_searches WHERE user_id=$uid")['c'] ?? 0);
$weekSearches  = (int)($q("SELECT COUNT(*) as c FROM domain_searches WHERE user_id=$uid AND searched_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")['c'] ?? 0);

// ── Backorders ─────────────────────────────────────────────────────────────
$backorders = [];
if ($st = $conn->prepare("SELECT domain_name, tld, status, priority, estimated_drop_date, created_at FROM backorders WHERE user_id=? ORDER BY created_at DESC LIMIT 5")) {
    $st->bind_param("i", $uid);
    $st->execute();
    $backorders = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}
$activeBackorders = (int)($q("SELECT COUNT(*) as c FROM backorders WHERE user_id=$uid AND status IN ('pending','watching','processing')")['c'] ?? 0);

// ── Alerts ─────────────────────────────────────────────────────────────────
$alertCount = (int)($q("SELECT COUNT(*) as c FROM domain_alerts WHERE user_id=$uid AND status='unread'")['c'] ?? 0);
$alerts = [];
if ($st = $conn->prepare("SELECT id, domain_name, alert_type, status, priority, title, message, expires_in_days, action_url, action_label, created_at FROM domain_alerts WHERE user_id=? ORDER BY created_at DESC LIMIT 5")) {
    $st->bind_param("i", $uid);
    $st->execute();
    $alerts = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

// ── Dead site scans ────────────────────────────────────────────────────────
$deadScans = [];
if ($st = $conn->prepare("SELECT domain_name, site_status, is_dead, dead_score, is_parked, is_for_sale, scanned_at FROM dead_site_scans WHERE user_id=? ORDER BY scanned_at DESC LIMIT 4")) {
    $st->bind_param("i", $uid);
    $st->execute();
    $deadScans = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}
$totalDeadScans = (int)($q("SELECT COUNT(*) as c FROM dead_site_scans WHERE user_id=$uid")['c'] ?? 0);
$foundDead      = (int)($q("SELECT COUNT(*) as c FROM dead_site_scans WHERE user_id=$uid AND is_dead=1")['c'] ?? 0);

// ── WHOIS lookups ──────────────────────────────────────────────────────────
$whoisLookups = [];
if ($st = $conn->prepare("SELECT domain_name, registrar, expiry_date, is_available, looked_up_at FROM whois_lookups WHERE user_id=? ORDER BY looked_up_at DESC LIMIT 4")) {
    $st->bind_param("i", $uid);
    $st->execute();
    $whoisLookups = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}
$totalWhois = (int)($q("SELECT COUNT(*) as c FROM whois_lookups WHERE user_id=$uid")['c'] ?? 0);

// ── Credit ledger ──────────────────────────────────────────────────────────
$ledger = [];
if ($st = $conn->prepare("SELECT delta, balance_after, type, domain_name, created_at FROM credit_ledger WHERE user_id=? ORDER BY created_at DESC LIMIT 6")) {
    $st->bind_param("i", $uid);
    $st->execute();
    $ledger = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

// ── Subscription ───────────────────────────────────────────────────────────
$activeSub = null;
if ($st = $conn->prepare("SELECT s.status, s.billing_cycle, s.current_period_end, s.cancel_at_period_end, p.name as plan_name, p.price_monthly_kobo, p.credits_monthly FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.user_id=? AND s.status IN ('active','trialing','past_due') LIMIT 1")) {
    $st->bind_param("i", $uid);
    $st->execute();
    $activeSub = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
}

// ── 14-day search activity for chart ───────────────────────────────────────
$chartMap = [];
$chartRes = @$conn->query("SELECT DATE(searched_at) as d, COUNT(*) as c FROM domain_searches WHERE user_id=$uid AND searched_at >= DATE_SUB(NOW(),INTERVAL 14 DAY) GROUP BY DATE(searched_at)");
if ($chartRes) {
    while ($r = $chartRes->fetch_assoc()) $chartMap[$r['d']] = (int)$r['c'];
}
$chartData = [];
for ($i = 13; $i >= 0; $i--) {
    $chartData[] = $chartMap[date('Y-m-d', strtotime("-$i days"))] ?? 0;
}

$conn->close();

// ── Helpers ────────────────────────────────────────────────────────────────
$appBasePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (in_array($appBasePath, ['/', '.', '\\'])) { $appBasePath = ''; }
$assetUrl = fn(string $p): string => ($appBasePath ?: '') . '/' . ltrim($p, '/');
$url = $assetUrl; // alias used throughout this file

function ago(string $ts): string {
    if (!$ts) return '—';
    $d = time() - strtotime($ts);
    if ($d < 60)     return 'just now';
    if ($d < 3600)   return round($d / 60) . 'm ago';
    if ($d < 86400)  return round($d / 3600) . 'h ago';
    if ($d < 604800) return round($d / 86400) . 'd ago';
    return date('M j', strtotime($ts));
}

// Map a search result_status to a status-pill CSS class.
function searchPillClass(string $status): string {
    if ($status === 'available') return 'p-av';
    if (str_contains($status, 'dead') || str_contains($status, 'parked')) return 'p-dead';
    if ($status === 'taken' || $status === 'registered') return 'p-tak';
    return 'p-unk';
}

// Map a search result_status to an [label, inline-style] action pair.
function searchAction(string $status): array {
    if ($status === 'available') {
        return ['Register', 'background:var(--gb);color:var(--g2)'];
    }
    if (str_contains($status, 'dead') || str_contains($status, 'parked')) {
        return ['Backorder', 'background:var(--ab);color:var(--a)'];
    }
    if ($status === 'taken' || $status === 'registered') {
        return ['Watch', 'background:var(--blb);color:var(--bl)'];
    }
    return ['Re-check', 'background:var(--bg3);color:var(--t3)'];
}

// Map a domain_alerts.alert_type to [icon style, icon class].
function alertIconMeta(string $type): array {
    switch ($type) {
        case 'expiring_soon':
        case 'just_expired':
            return ['background:var(--ab);color:var(--a)', 'fa-clock'];
        case 'available':
            return ['background:var(--gb);color:var(--g2)', 'fa-check-circle'];
        case 'backorder_won':
            return ['background:var(--pb);color:var(--p)', 'fa-trophy'];
        case 'backorder_lost':
            return ['background:var(--cb);color:var(--c)', 'fa-times-circle'];
        case 'dead_site':
            return ['background:var(--cb);color:var(--c)', 'fa-skull'];
        case 'whois_updated':
            return ['background:var(--blb);color:var(--bl)', 'fa-file-alt'];
        default:
            return ['background:var(--bg3);color:var(--t3)', 'fa-bell'];
    }
}

function alertCta(string $type, string $domain, callable $url): array {
    switch ($type) {
        case 'available':
            return [$url('index.php') . '?q=' . urlencode($domain), 'Register', 'background:var(--gb);color:var(--g2)'];
        case 'dead_site':
            return [$url('backorders.php') . '?domain=' . urlencode($domain), 'Backorder', 'background:var(--cb);color:var(--c)'];
        case 'expiring_soon':
        case 'just_expired':
            return [$url('whois.php') . '?q=' . urlencode($domain), 'View WHOIS', 'background:var(--ab);color:var(--a)'];
        case 'backorder_won':
            return [$url('backorders.php'), 'Complete transfer', 'background:var(--gb);color:var(--g2)'];
        case 'backorder_lost':
            return [$url('alerts.php'), 'View', 'background:var(--cb);color:var(--c)'];
        default:
            return [$url('alerts.php'), 'View', 'background:var(--ab);color:var(--a)'];
    }
}

function backorderStatusClass(string $status): string {
    $map = [
        'watching'   => 'bs-watching',
        'pending'    => 'bs-pending',
        'processing' => 'bs-processing',
        'won'        => 'bs-won',
        'lost'       => 'bs-lost',
        'canceled'   => 'bs-canceled',
        'expired'    => 'bs-expired',
    ];
    return $map[$status] ?? 'bs-pending';
}

function deadScorePillClass(int $score): string {
    if ($score >= 70) return 'score-hi';
    if ($score >= 40) return 'score-md';
    return 'score-lo';
}

$ledgerLabels = [
    'signup_bonus'       => 'Signup bonus',
    'plan_renewal'       => 'Plan renewal',
    'manual_grant'       => 'Manual grant',
    'topup_purchase'     => 'Top-up',
    'promo_redeem'       => 'Promo code',
    'domain_check'       => 'Domain check',
    'whois_lookup'       => 'WHOIS lookup',
    'backorder_place'    => 'Backorder',
    'alert_subscription' => 'Alert sub',
    'dead_site_scan'     => 'Dead site scan',
    'broker_request'     => 'Broker request',
    'refund'             => 'Refund',
    'expiry'             => 'Credit expiry',
    'adjustment'         => 'Adjustment',
];

$usdMinorAmount = fn(int $amount): int => $amount >= 100000 ? (int)round($amount / 1000) : $amount;
$dollars = fn(int $kobo): string => '$' . number_format($usdMinorAmount($kobo) / 100, 0, '.', ',');
$hour  = (int) date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$activePage = 'dashboard';
$watchlistCount = $watchlistTotal;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — CheckDomain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root{
  --bg:    #08090C;
  --bg2:   #0F1117;
  --bg3:   #161B25;
  --bg4:   #1C2233;
  --b1: rgba(255,255,255,0.05);
  --b2: rgba(255,255,255,0.09);
  --b3: rgba(255,255,255,0.14);
  --t1: #ECEAE2;
  --t2: #8C8A82;
  --t3: #44423C;
  --g:  #1A9A70;
  --g2: #12BF86;
  --gb: rgba(26,154,112,0.10);
  --gb2:rgba(26,154,112,0.18);
  --a:  #E89820;
  --ab: rgba(232,152,32,0.10);
  --c:  #E05038;
  --cb: rgba(224,80,56,0.10);
  --p:  #7B72D8;
  --pb: rgba(123,114,216,0.10);
  --bl: #4888D4;
  --blb:rgba(72,136,212,0.10);
  --sans:'Syne',sans-serif;
  --mono:'DM Mono',monospace;
  --sb: 220px;
}

html { scroll-behavior:smooth }
body { background:var(--bg); color:var(--t1); font-family:var(--sans);
       min-height:100vh; display:flex; overflow-x:hidden; line-height:1 }

body::before { content:''; position:fixed; inset:0; pointer-events:none; z-index:0;
  background-image: radial-gradient(circle, rgba(26,154,112,.06) 1px, transparent 1px);
  background-size: 28px 28px; }

.main { margin-left:var(--sb); flex:1; position:relative; z-index:1; min-height:100vh; display:flex; flex-direction:column }

.topbar { display:flex; align-items:center; justify-content:space-between; gap:12px;
  padding:0 28px; height:56px; border-bottom:1px solid var(--b1);
  background:rgba(8,9,12,.92); backdrop-filter:blur(16px);
  position:sticky; top:0; z-index:40; flex-shrink:0 }
.tb-left { display:flex; align-items:center; gap:10px }
.tb-right{ display:flex; align-items:center; gap:6px }
.breadcrumb{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--t3)}
.breadcrumb a{color:var(--t2);text-decoration:none;transition:color .15s}
.breadcrumb a:hover{color:var(--t1)}
.breadcrumb i{font-size:9px}

.mob-menu { display:none; width:32px; height:32px; border-radius:7px; background:var(--bg2);
  border:1px solid var(--b1); color:var(--t2); font-size:14px; cursor:pointer;
  align-items:center; justify-content:center }

.tb-search { display:flex; align-items:center; gap:7px; background:var(--bg2);
  border:1px solid var(--b2); border-radius:8px; padding:7px 12px;
  width:250px; transition:border-color .2s, width .25s }
.tb-search:focus-within { border-color:var(--g); width:310px }
.tb-search input { background:none; border:none; outline:none; font-family:var(--mono);
  font-size:12px; color:var(--t1); caret-color:var(--g2); flex:1; min-width:0 }
.tb-search input::placeholder { color:var(--t3) }
.tb-search i { color:var(--t3); font-size:11px; flex-shrink:0 }

.tb-icon { width:32px; height:32px; border-radius:7px; background:var(--bg2);
  border:1px solid var(--b1); color:var(--t2); font-size:12px; cursor:pointer;
  display:flex; align-items:center; justify-content:center;
  transition:border-color .15s,color .15s; text-decoration:none; position:relative }
.tb-icon:hover { border-color:var(--b2); color:var(--t1) }
.tb-dot { position:absolute; top:5px; right:5px; width:5px; height:5px;
  border-radius:50%; background:var(--a); border:1.5px solid var(--bg) }

.tb-credits { display:flex; align-items:center; gap:5px; background:var(--bg2);
  border:1px solid var(--b1); border-radius:7px; padding:5px 10px;
  font-family:var(--mono); font-size:11px; color:var(--t2) }
.tb-credits b { color:var(--a) }

.tb-up { display:flex; align-items:center; gap:5px; background:var(--g);
  color:#fff; border:none; border-radius:7px; padding:6px 13px;
  font-family:var(--sans); font-size:11px; font-weight:700; letter-spacing:.06em;
  text-transform:uppercase; cursor:pointer; text-decoration:none;
  transition:background .2s; white-space:nowrap }
.tb-up:hover { background:var(--g2) }

.content { padding:28px 28px 56px; flex:1 }

.pg-date { font-size:10px; font-weight:600; text-transform:uppercase;
  letter-spacing:.12em; color:var(--t3); margin-bottom:5px }
.pg-title { font-size:22px; font-weight:800; color:var(--t1); letter-spacing:-.02em }
.pg-sub { font-size:12px; color:var(--t2); margin-top:4px; line-height:1.5 }
.pg-sub em { color:var(--g2); font-family:var(--mono); font-style:normal }
.pg-sub .warn { color:var(--a); font-family:var(--mono) }

.free-banner { display:flex; align-items:center; gap:14px; margin-bottom:20px;
  background:linear-gradient(135deg,rgba(232,152,32,.06),rgba(26,154,112,.05));
  border:1px solid rgba(232,152,32,.18); border-radius:12px; padding:13px 18px }
.fb-text { flex:1 }
.fb-title { font-size:12px; font-weight:700; color:var(--t1); margin-bottom:2px }
.fb-sub { font-size:11px; color:var(--t2); line-height:1.5 }
.fb-cta { background:var(--a); color:#000; border:none; border-radius:6px;
  padding:6px 14px; font-family:var(--sans); font-size:10px; font-weight:800;
  text-transform:uppercase; letter-spacing:.06em; cursor:pointer;
  text-decoration:none; white-space:nowrap; transition:opacity .2s }
.fb-cta:hover { opacity:.85 }

.stat-row { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:20px }
.sc { background:var(--bg2); border:1px solid var(--b1); border-radius:12px;
  padding:14px 16px; position:relative; overflow:hidden;
  transition:border-color .2s, transform .15s; cursor:default }
.sc:hover { border-color:var(--b2); transform:translateY(-1px) }
.sc-bar { position:absolute; top:0; left:0; right:0; height:2px; border-radius:12px 12px 0 0 }
.sc-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px }
.sc-lbl { font-size:9px; font-weight:700; text-transform:uppercase;
  letter-spacing:.12em; color:var(--t3) }
.sc-ico { width:26px; height:26px; border-radius:7px; display:flex;
  align-items:center; justify-content:center; font-size:11px; flex-shrink:0 }
.sc-val { font-size:26px; font-weight:800; font-family:var(--mono);
  line-height:1; letter-spacing:-.03em; margin-bottom:6px }
.sc-meta { font-size:10px; color:var(--t2) }
.sc-meta.up   { color:var(--g2) }
.sc-meta.down { color:var(--c) }
.sc-bar-wrap { height:2px; background:var(--b1); border-radius:2px;
  overflow:hidden; margin-top:8px }
.sc-bar-fill { height:100%; border-radius:2px; transition:width .6s }

.grid-main { display:grid; grid-template-columns:1fr 300px; gap:14px; margin-bottom:14px }
.grid-bot  { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; margin-bottom:14px }

.card { background:var(--bg2); border:1px solid var(--b1); border-radius:13px;
  overflow:hidden; transition:border-color .2s }
.card:hover { border-color:var(--b2) }
.card-hd { display:flex; align-items:center; justify-content:space-between;
  padding:12px 16px; border-bottom:1px solid var(--b1) }
.card-title { font-size:11px; font-weight:700; letter-spacing:.05em;
  color:var(--t1); display:flex; align-items:center; gap:6px }
.card-title i { font-size:11px }
.card-link { font-size:10px; color:var(--g); text-decoration:none;
  transition:color .15s; white-space:nowrap }
.card-link:hover { color:var(--g2) }
.card-bd { padding:12px 16px }

.chart-wrap { padding:12px 16px 4px; height:104px; position:relative }
.chart-axis { display:flex; justify-content:space-between;
  padding:0 16px 10px; }
.chart-axis span { font-size:9px; color:var(--t3); font-family:var(--mono) }

.s-item { display:flex; align-items:center; gap:8px;
  padding:7px 0; border-bottom:1px solid var(--b1) }
.s-item:last-child { border-bottom:none }
.s-domain { font-family:var(--mono); font-size:11px; color:var(--t1);
  flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.s-time { font-size:9px; color:var(--t3); white-space:nowrap; font-family:var(--mono) }
.s-act { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
  padding:2px 8px; border-radius:4px; border:none; cursor:pointer;
  font-family:var(--sans); transition:opacity .15s; white-space:nowrap; text-decoration:none }
.s-act:hover { opacity:.75 }

.pill { font-size:8px; font-weight:700; text-transform:uppercase;
  letter-spacing:.08em; padding:2px 6px; border-radius:4px; white-space:nowrap; flex-shrink:0 }
.p-av  { background:var(--gb);  color:var(--g2) }
.p-tak { background:var(--ab);  color:var(--a) }
.p-dead{ background:var(--cb);  color:var(--c) }
.p-unk { background:var(--bg3); color:var(--t3) }

.qs-wrap { display:flex; gap:7px; padding:10px 16px 12px; border-top:1px solid var(--b1) }
.qs-wrap input { flex:1; background:var(--bg3); border:1px solid var(--b2);
  border-radius:7px; padding:7px 10px; font-family:var(--mono); font-size:11px;
  color:var(--t1); outline:none; transition:border-color .2s; min-width:0 }
.qs-wrap input::placeholder { color:var(--t3) }
.qs-wrap input:focus { border-color:var(--g) }
.qs-wrap button { background:var(--g); color:#fff; border:none; border-radius:7px;
  padding:7px 13px; font-family:var(--sans); font-size:10px; font-weight:700;
  cursor:pointer; transition:background .2s; white-space:nowrap }
.qs-wrap button:hover { background:var(--g2) }

.w-item { display:flex; align-items:center; gap:9px; padding:7px 0; border-bottom:1px solid var(--b1) }
.w-item:last-child { border-bottom:none }
.w-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; animation:blink 2.5s infinite }
@keyframes blink { 0%,100%{opacity:1} 55%{opacity:.25} }
.w-domain { font-family:var(--mono); font-size:11px; color:var(--t1);
  flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.w-when { font-size:9px; color:var(--t3); font-family:var(--mono); white-space:nowrap; margin-left:auto; flex-shrink:0 }

.al-item { display:flex; gap:9px; padding:9px 0; border-bottom:1px solid var(--b1) }
.al-item:last-child { border-bottom:none }
.al-ico { width:28px; height:28px; border-radius:7px; display:flex;
  align-items:center; justify-content:center; font-size:11px; flex-shrink:0; margin-top:1px }
.al-body { flex:1; min-width:0 }
.al-domain { font-family:var(--mono); font-size:11px; font-weight:500; color:var(--t1) }
.al-msg { font-size:10px; color:var(--t2); margin-top:2px; line-height:1.4;
  overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.al-foot { display:flex; align-items:center; gap:6px; margin-top:5px }
.al-time { font-size:9px; color:var(--t3); font-family:var(--mono) }
.al-cta { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
  padding:2px 8px; border-radius:4px; border:none; cursor:pointer; font-family:var(--sans);
  transition:opacity .15s; text-decoration:none }
.al-cta:hover { opacity:.75 }
.al-unread { width:5px; height:5px; border-radius:50%; background:var(--a);
  flex-shrink:0; margin-left:auto }

.bo-item { display:flex; align-items:center; gap:8px; padding:7px 0; border-bottom:1px solid var(--b1) }
.bo-item:last-child { border-bottom:none }
.bo-domain { font-family:var(--mono); font-size:11px; color:var(--t1); flex:1;
  overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.bo-drop { font-size:9px; color:var(--t3); font-family:var(--mono); white-space:nowrap }
.bo-st { font-size:8px; font-weight:700; text-transform:uppercase; letter-spacing:.07em;
  padding:2px 6px; border-radius:4px; flex-shrink:0 }
.bs-pending   { background:var(--ab); color:var(--a) }
.bs-watching  { background:var(--gb); color:var(--g2) }
.bs-processing{ background:var(--blb); color:var(--bl) }
.bs-won       { background:var(--pb); color:var(--p) }
.bs-lost,.bs-canceled,.bs-expired { background:var(--cb); color:var(--c) }

.ds-item { display:flex; align-items:center; gap:8px; padding:7px 0; border-bottom:1px solid var(--b1) }
.ds-item:last-child { border-bottom:none }
.ds-domain { font-family:var(--mono); font-size:11px; color:var(--t1); flex:1;
  overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.ds-score { font-family:var(--mono); font-size:10px; font-weight:700; flex-shrink:0 }
.score-hi { color:var(--c) } .score-md { color:var(--a) } .score-lo { color:var(--g2) }

.lg-row { display:flex; align-items:center; gap:7px; padding:6px 0; border-bottom:1px solid var(--b1); font-size:11px }
.lg-row:last-child { border-bottom:none }
.lg-sign { font-size:11px; font-weight:700; flex-shrink:0; width:14px; text-align:center }
.lg-label { color:var(--t2); flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.lg-delta { font-family:var(--mono); font-size:10px; font-weight:700; flex-shrink:0 }
.lg-bal   { font-family:var(--mono); font-size:9px; color:var(--t3); flex-shrink:0 }
.lg-sum { margin-top:10px; padding-top:10px; border-top:1px solid var(--b1) }
.lg-sum-row { display:flex; justify-content:space-between; align-items:center;
  font-size:10px; margin-bottom:5px }
.lg-bar { height:3px; background:var(--b1); border-radius:2px; overflow:hidden }
.lg-bar-fill { height:100%; border-radius:2px; transition:width .6s }

.wh-item { display:flex; align-items:center; gap:8px; padding:7px 0; border-bottom:1px solid var(--b1) }
.wh-item:last-child { border-bottom:none }
.wh-domain { font-family:var(--mono); font-size:11px; color:var(--t1); flex:1;
  overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.wh-exp { font-size:9px; color:var(--t3); font-family:var(--mono); flex-shrink:0 }

.plans-hd { margin-bottom:14px }
.plans-title { font-size:10px; font-weight:700; text-transform:uppercase;
  letter-spacing:.14em; color:var(--t3); margin-bottom:3px }
.plans-sub { font-size:11px; color:var(--t2) }
.plans-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px }
.plan-card { background:var(--bg2); border:1px solid var(--b1); border-radius:13px;
  padding:18px 16px; display:flex; flex-direction:column; gap:12px;
  position:relative; overflow:hidden; transition:border-color .2s, transform .15s }
.plan-card:hover { transform:translateY(-2px) }
.plan-pop { border-color:rgba(26,154,112,.28);
  background:linear-gradient(155deg,rgba(26,154,112,.06),var(--bg2) 60%) }
.pop-tag { position:absolute; top:10px; right:10px; font-size:8px; font-weight:700;
  text-transform:uppercase; letter-spacing:.1em; background:var(--gb);
  color:var(--g2); border:1px solid rgba(26,154,112,.22); border-radius:4px; padding:2px 6px }
.plan-name  { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.14em; color:var(--t2) }
.plan-price { font-family:var(--mono); font-size:28px; font-weight:700; color:var(--t1); line-height:1 }
.plan-price sup { font-size:13px; vertical-align:top; margin-top:4px; display:inline-block }
.plan-price sub { font-size:11px; color:var(--t2) }
.plan-desc  { font-size:11px; color:var(--t2); line-height:1.5 }
.plan-feats { display:flex; flex-direction:column; gap:6px }
.plan-feat  { display:flex; align-items:center; gap:7px; font-size:11px }
.plan-feat i { font-size:10px; flex-shrink:0 }
.feat-on    { color:var(--t2) }  .feat-on i { color:var(--g2) }
.feat-off   { color:var(--t3) }  .feat-off i{ color:var(--t3) }
.plan-btn { width:100%; padding:9px; border-radius:8px; font-family:var(--sans);
  font-size:11px; font-weight:700; cursor:pointer; transition:all .2s;
  border:1px solid var(--b2); background:none; color:var(--t2);
  letter-spacing:.04em; text-decoration:none; display:block; text-align:center; margin-top:auto }
.plan-btn:hover  { background:var(--bg3); color:var(--t1) }
.plan-btn.primary{ background:var(--g); border-color:var(--g); color:#fff }
.plan-btn.primary:hover { background:var(--g2) }
.plan-btn.current{ background:var(--bg3); border-color:var(--b1); color:var(--t3); cursor:default }

.empty { text-align:center; padding:22px 16px; color:var(--t3);
  display:flex; flex-direction:column; align-items:center; gap:7px }
.empty i { font-size:20px; opacity:.3 }
.empty p { font-size:11px }
.empty-cta { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.07em;
  padding:5px 12px; border-radius:6px; background:var(--gb); color:var(--g2);
  text-decoration:none; border:1px solid rgba(26,154,112,.18); transition:all .15s }
.empty-cta:hover { background:var(--gb2) }

.toast { position:fixed; bottom:22px; right:22px; z-index:999; background:var(--bg3);
  border:1px solid var(--b2); border-radius:10px; padding:11px 16px; font-size:12px;
  color:var(--t1); box-shadow:0 8px 32px rgba(0,0,0,.45); max-width:300px;
  display:flex; align-items:center; gap:8px;
  transform:translateY(16px); opacity:0; transition:all .3s }
.toast.show { transform:translateY(0); opacity:1 }

.overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:49 }
.overlay.show { display:block }

@media(max-width:1100px) {
  .grid-main { grid-template-columns:1fr }
  .grid-bot  { grid-template-columns:1fr 1fr }
}
@media(max-width:768px) {
  .main { margin-left:0 }
  .mob-menu { display:flex }
  .content { padding:18px 16px 44px }
  .stat-row { grid-template-columns:1fr 1fr }
  .grid-bot { grid-template-columns:1fr }
  .plans-grid { grid-template-columns:1fr }
  .tb-credits { display:none }
  .tb-search { width:140px }
  .tb-search:focus-within { width:180px }
}
@media(max-width:480px) {
  .tb-up span { display:none }
  .topbar { padding:0 14px }
  .content { padding:14px 12px 44px }
}

::-webkit-scrollbar { width:4px; height:4px }
::-webkit-scrollbar-thumb { background:var(--b2); border-radius:2px }
</style>
</head>
<body>

<div class="overlay" id="overlay" onclick="closeSB()"></div>
<?php require_once 'includes/sidebar.php'; ?>

<main class="main">

  <?php
    $cdHeaderTitle = 'Dashboard';
    $cdHeaderParent = 'Home';
    $cdHeaderParentHref = 'index.php';
    $cdHeaderActions = $userPlan === 'free'
        ? '<a href="' . htmlspecialchars($url('billing.php')) . '" class="tb-up"><i class="fas fa-bolt" style="font-size:9px"></i><span>Upgrade</span></a>'
        : '';
    require 'includes/cd_header.php';
  ?>

  <div class="content">

    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:20px">
      <div>
        <div class="pg-date"><?= date('l, F j, Y') ?></div>
        <div class="pg-title"><?= $greet ?>, <?= htmlspecialchars($firstName) ?> 👋</div>
        <div class="pg-sub" style="margin-top:4px">
          <?php if ($watchlistTotal > 0): ?>
            Watching <em><?= $watchlistTotal ?> domain<?= $watchlistTotal !== 1 ? 's' : '' ?></em>.
            <?php if ($credits < 5): ?> <span class="warn"><?= $credits ?> credits left</span> — top up soon.<?php endif; ?>
          <?php elseif ($totalSearches > 0): ?>
            You've run <em><?= number_format($totalSearches) ?> search<?= $totalSearches !== 1 ? 'es' : '' ?></em>. Pin domains to your watchlist.
          <?php else: ?>
            Welcome to CheckDomain — search a domain to get started.
          <?php endif; ?>
        </div>
      </div>
      <?php if ($activeSub): ?>
      <div style="text-align:right;flex-shrink:0">
        <div style="font-size:9px;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;margin-bottom:3px">Active plan</div>
        <div style="font-size:13px;font-weight:800;color:var(--g2)"><?= htmlspecialchars($activeSub['plan_name'] ?? '') ?></div>
        <div style="font-size:10px;color:var(--t3);font-family:var(--mono)">
          <?php if (!empty($activeSub['current_period_end'])): ?>
          renews <?= date('M j, Y', strtotime($activeSub['current_period_end'])) ?>
          <?php endif; ?>
          <?php if (!empty($activeSub['cancel_at_period_end'])): ?>
          <span style="color:var(--c)">· cancels</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($userPlan === 'free'): ?>
    <div class="free-banner">
      <span style="font-size:18px">⚡</span>
      <div class="fb-text">
        <div class="fb-title">You're on the Free plan · <?= $credits ?>/<?= $planMax ?> credits remaining</div>
        <div class="fb-sub">Upgrade to Pro for 100 credits/month, unlimited watchlist, WHOIS deep lookup, expiry alerts, and backorder placement.</div>
      </div>
      <a href="<?= $url('billing.php') ?>" class="fb-cta">Go to billing →</a>
    </div>
    <?php endif; ?>

    <div class="stat-row">

      <div class="sc">
        <div class="sc-bar" style="background:var(--g)"></div>
        <div class="sc-head">
          <span class="sc-lbl">Watchlist</span>
          <div class="sc-ico" style="background:var(--gb);color:var(--g2)"><i class="fas fa-bookmark"></i></div>
        </div>
        <div class="sc-val" style="color:var(--g2)"><?= $watchlistTotal ?></div>
        <div class="sc-meta <?= $watchlistTotal > 0 ? 'up' : '' ?>">
          <?= $watchlistTotal > 0 ? '↑ domains monitored' : 'no domains yet' ?>
        </div>
      </div>

      <div class="sc">
        <div class="sc-bar" style="background:var(--p)"></div>
        <div class="sc-head">
          <span class="sc-lbl">Searches</span>
          <div class="sc-ico" style="background:var(--pb);color:var(--p)"><i class="fas fa-search"></i></div>
        </div>
        <div class="sc-val" style="color:var(--p)"><?= number_format($totalSearches) ?></div>
        <div class="sc-meta <?= $weekSearches > 0 ? 'up' : '' ?>">
          <?= $weekSearches > 0 ? '↑ ' . $weekSearches . ' this week' : 'none this week' ?>
        </div>
      </div>

      <div class="sc">
        <div class="sc-bar" style="background:var(--a)"></div>
        <div class="sc-head">
          <span class="sc-lbl">Backorders</span>
          <div class="sc-ico" style="background:var(--ab);color:var(--a)"><i class="fas fa-clock"></i></div>
        </div>
        <div class="sc-val" style="color:var(--a)"><?= $activeBackorders ?></div>
        <div class="sc-meta <?= $activeBackorders > 0 ? 'up' : '' ?>">
          <?= $activeBackorders > 0 ? '↑ in progress' : 'none placed' ?>
        </div>
      </div>

      <div class="sc">
        <div class="sc-bar" style="background:var(--c)"></div>
        <div class="sc-head">
          <span class="sc-lbl">Credits left</span>
          <div class="sc-ico" style="background:var(--cb);color:var(--c)"><i class="fas fa-bolt"></i></div>
        </div>
        <div class="sc-val" style="color:<?= $creditsPct < 20 ? 'var(--c)' : ($creditsPct < 50 ? 'var(--a)' : 'var(--t1)') ?>"><?= $credits ?></div>
        <div class="sc-meta <?= $creditsPct < 20 ? 'down' : '' ?>">
          <?= $creditsPct < 20 ? '↓ running low' : 'of ' . $planMax . ' (' . $userPlan . ' plan)' ?>
        </div>
        <div class="sc-bar-wrap">
          <div class="sc-bar-fill" style="width:<?= $creditsPct ?>%;background:<?= $creditsPct < 20 ? 'var(--c)' : ($creditsPct < 50 ? 'var(--a)' : 'var(--g)') ?>"></div>
        </div>
      </div>

    </div><!-- /stat-row -->

    <div class="grid-main">

      <div style="display:flex;flex-direction:column;gap:14px">

        <div class="card">
          <div class="card-hd">
            <span class="card-title">
              <i class="fas fa-chart-column" style="color:var(--g)"></i>
              Search activity — last 14 days
            </span>
            <a href="<?= $url('index.php') ?>" class="card-link">New search →</a>
          </div>
          <div class="chart-wrap">
            <canvas id="actChart"></canvas>
          </div>
          <div class="chart-axis">
            <span>14d ago</span><span>7d ago</span><span>Today</span>
          </div>
        </div>

        <div class="card">
          <div class="card-hd">
            <span class="card-title">
              <i class="fas fa-history" style="color:var(--t3)"></i>
              Recent searches
            </span>
            <a href="<?= $url('index.php') ?>" class="card-link">Search →</a>
          </div>
          <div class="card-bd" style="padding-bottom:0">
            <?php if (!empty($recentSearches)): foreach ($recentSearches as $s):
                $rs       = $s['result_status'] ?? 'unknown';
                $pillCls  = searchPillClass($rs);
                [$actLabel, $actStyle] = searchAction($rs);
            ?>
            <div class="s-item">
              <span class="pill <?= $pillCls ?>"><?= htmlspecialchars($rs) ?></span>
              <span class="s-domain"><?= htmlspecialchars($s['domain_name'] ?? '') ?></span>
              <span class="s-time"><?= ago($s['searched_at'] ?? '') ?></span>
              <a href="<?= $url('index.php') ?>?q=<?= urlencode($s['domain_name'] ?? '') ?>"
                 class="s-act" style="<?= $actStyle ?>"><?= htmlspecialchars($actLabel) ?></a>
            </div>
            <?php endforeach; else: ?>
            <div class="empty">
              <i class="fas fa-search"></i>
              <p>No searches yet.</p>
              <a href="<?= $url('index.php') ?>" class="empty-cta">Search a domain</a>
            </div>
            <?php endif; ?>
          </div>
          <div class="qs-wrap">
            <input type="text" id="qs" placeholder="domain.com or just a keyword…" autocomplete="off">
            <button onclick="doSearch()">Check →</button>
          </div>
        </div>

      </div><!-- /left -->

      <div style="display:flex;flex-direction:column;gap:14px">

        <div class="card">
          <div class="card-hd">
            <span class="card-title">
              <i class="fas fa-bell" style="color:var(--a)"></i>
              Alerts
              <?php if ($alertCount > 0): ?>
              <span style="background:var(--ab);color:var(--a);font-size:9px;font-family:var(--mono);font-weight:700;padding:1px 5px;border-radius:3px"><?= $alertCount ?> new</span>
              <?php endif; ?>
            </span>
            <a href="<?= $url('alerts.php') ?>" class="card-link">All →</a>
          </div>
          <div class="card-bd" style="padding-top:4px;padding-bottom:4px">
            <?php if (!empty($alerts)): foreach ($alerts as $al):
                $atype = $al['alert_type'] ?? 'whois_updated';
                [$iconStyle, $iconClass] = alertIconMeta($atype);
                $domainName = $al['domain_name'] ?? '';
                if (!empty($al['action_url'])) {
                    $ctaHref  = $al['action_url'];
                    $ctaLabel = $al['action_label'] ?: 'View';
                    $ctaStyle = 'background:var(--ab);color:var(--a)';
                } else {
                    [$ctaHref, $ctaLabel, $ctaStyle] = alertCta($atype, $domainName, $url);
                }
            ?>
            <div class="al-item">
              <div class="al-ico" style="<?= $iconStyle ?>"><i class="fas <?= $iconClass ?>"></i></div>
              <div class="al-body">
                <div class="al-domain"><?= htmlspecialchars($domainName) ?></div>
                <div class="al-msg"><?= htmlspecialchars($al['title'] ?? '') ?></div>
                <div class="al-foot">
                  <span class="al-time"><?= ago($al['created_at'] ?? '') ?></span>
                  <a href="<?= htmlspecialchars($ctaHref) ?>" class="al-cta" style="<?= $ctaStyle ?>"><?= htmlspecialchars($ctaLabel) ?></a>
                  <?php if (($al['status'] ?? '') === 'unread'): ?><span class="al-unread"></span><?php endif; ?>
                </div>
              </div>
            </div>
            <?php endforeach; else: ?>
            <div class="empty">
              <i class="fas fa-bell-slash"></i>
              <p>No alerts yet. Watch domains to get notified.</p>
              <?php if ($userPlan === 'free'): ?>
              <a href="<?= $url('billing.php?plan=pro') ?>" class="empty-cta">Upgrade for alerts</a>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card">
          <div class="card-hd">
            <span class="card-title">
              <i class="fas fa-bookmark" style="color:var(--g2)"></i>
              Watchlist
              <?php if ($watchlistTotal > 0): ?>
              <span style="background:var(--gb);color:var(--g2);font-size:9px;font-family:var(--mono);font-weight:700;padding:1px 5px;border-radius:3px"><?= $watchlistTotal ?></span>
              <?php endif; ?>
            </span>
            <a href="<?= $url('watchlist.php') ?>" class="card-link">Manage →</a>
          </div>
          <div class="card-bd" style="padding-top:4px;padding-bottom:4px">
            <?php
            $dotColors = ['var(--g)', 'var(--a)', 'var(--p)', 'var(--bl)', 'var(--c)', 'var(--g2)'];
            if (!empty($watchlist)):
                foreach ($watchlist as $i => $w):
            ?>
            <div class="w-item">
              <span class="w-dot" style="background:<?= $dotColors[$i % count($dotColors)] ?>"></span>
              <span class="w-domain"><?= htmlspecialchars($w['domain_name'] ?? '') ?></span>
              <span class="w-when"><?= ago($w['pinned_at'] ?? '') ?></span>
            </div>
            <?php
                endforeach;
                if ($watchlistTotal > 6): ?>
            <div style="padding:8px 0;text-align:center;font-size:9px;color:var(--t3)">
              +<?= $watchlistTotal - 6 ?> more ·
              <a href="<?= $url('watchlist.php') ?>" style="color:var(--g);text-decoration:none">view all</a>
            </div>
            <?php endif;
            else: ?>
            <div class="empty">
              <i class="fas fa-bookmark"></i>
              <p>No domains watched yet.</p>
              <a href="<?= $url('index.php') ?>" class="empty-cta">Find domains</a>
            </div>
            <?php endif; ?>
          </div>
        </div>

      </div><!-- /right -->
    </div><!-- /grid-main -->

    <div class="grid-bot">

      <div class="card">
        <div class="card-hd">
          <span class="card-title"><i class="fas fa-clock" style="color:var(--a)"></i> Backorders</span>
          <a href="<?= $url('backorders.php') ?>" class="card-link">All →</a>
        </div>
        <div class="card-bd" style="padding-top:4px;padding-bottom:4px">
          <?php if (!empty($backorders)): foreach ($backorders as $b):
              $bst = $b['status'] ?? 'pending';
              $bsc = backorderStatusClass($bst);
          ?>
          <div class="bo-item">
            <span class="bo-st <?= $bsc ?>"><?= htmlspecialchars($bst) ?></span>
            <span class="bo-domain"><?= htmlspecialchars($b['domain_name'] ?? '') ?></span>
            <span class="bo-drop">
              <?php if (!empty($b['estimated_drop_date'])): ?>
              ~<?= date('M j', strtotime($b['estimated_drop_date'])) ?>
              <?php else: ?>
              <?= ago($b['created_at'] ?? '') ?>
              <?php endif; ?>
            </span>
          </div>
          <?php endforeach; else: ?>
          <div class="empty">
            <i class="fas fa-clock"></i>
            <p>No backorders placed.</p>
            <?php if ($userPlan === 'free'): ?>
            <a href="<?= $url('billing.php?plan=pro') ?>" class="empty-cta">Unlock backorders</a>
            <?php else: ?>
            <a href="<?= $url('backorders.php') ?>" class="empty-cta">Place a backorder</a>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-hd">
          <span class="card-title"><i class="fas fa-skull" style="color:var(--c)"></i> Dead site scans</span>
          <a href="<?= $url('dead-sites.php') ?>" class="card-link">Scan →</a>
        </div>
        <div class="card-bd" style="padding-top:4px;padding-bottom:4px">
          <?php if ($totalDeadScans > 0): ?>
          <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--t3);margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid var(--b1)">
            <span><?= $totalDeadScans ?> scanned</span>
            <span style="color:var(--c)"><?= $foundDead ?> dead found</span>
          </div>
          <?php foreach ($deadScans as $ds):
              $sc  = (int)($ds['dead_score'] ?? 0);
              $scc = deadScorePillClass($sc);
              $statusPill = !empty($ds['is_dead']) ? 'p-dead' : (!empty($ds['is_parked']) ? 'p-tak' : 'p-av');
          ?>
          <div class="ds-item">
            <span class="pill <?= $statusPill ?>"><?= htmlspecialchars($ds['site_status'] ?? 'unknown') ?></span>
            <span class="ds-domain"><?= htmlspecialchars($ds['domain_name'] ?? '') ?></span>
            <span class="ds-score <?= $scc ?>"><?= $sc ?></span>
          </div>
          <?php endforeach; else: ?>
          <div class="empty">
            <i class="fas fa-skull"></i>
            <p>No scans yet.</p>
            <?php if ($userPlan === 'free'): ?>
            <a href="<?= $url('billing.php?plan=pro') ?>" class="empty-cta">Unlock dead sites</a>
            <?php else: ?>
            <a href="<?= $url('dead-sites.php') ?>" class="empty-cta">Scan a domain</a>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-hd">
          <span class="card-title"><i class="fas fa-bolt" style="color:var(--a)"></i> Credits</span>
          <a href="<?= $url('billing.php') ?>" class="card-link">Buy more →</a>
        </div>
        <div class="card-bd" style="padding-top:4px;padding-bottom:4px">
          <?php if (!empty($ledger)): foreach ($ledger as $l):
              $delta = (int)($l['delta'] ?? 0);
              $pos   = $delta > 0;
              $typeLabel = $ledgerLabels[$l['type'] ?? ''] ?? ($l['type'] ?? 'Activity');
          ?>
          <div class="lg-row">
            <span class="lg-sign" style="color:<?= $pos ? 'var(--g2)' : 'var(--c)' ?>"><?= $pos ? '＋' : '－' ?></span>
            <span class="lg-label">
              <?= htmlspecialchars($typeLabel) ?><?= !empty($l['domain_name']) ? ' · ' . htmlspecialchars(substr($l['domain_name'], 0, 14)) : '' ?>
            </span>
            <span class="lg-delta" style="color:<?= $pos ? 'var(--g2)' : 'var(--c)' ?>"><?= abs($delta) ?></span>
            <span class="lg-bal"><?= (int)($l['balance_after'] ?? 0) ?></span>
          </div>
          <?php endforeach; else: ?>
          <div class="empty">
            <i class="fas fa-receipt"></i><p>No credit activity yet.</p>
          </div>
          <?php endif; ?>
          <div class="lg-sum">
            <div class="lg-sum-row">
              <span style="color:var(--t2)">Remaining</span>
              <span style="font-family:var(--mono);font-weight:700;color:var(--a)"><?= $credits ?> / <?= $planMax ?></span>
            </div>
            <div class="lg-bar">
              <div class="lg-bar-fill" style="width:<?= $creditsPct ?>%;background:<?= $creditsPct < 20 ? 'var(--c)' : ($creditsPct < 50 ? 'var(--a)' : 'var(--g)') ?>"></div>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /grid-bot -->

    <?php if ($totalWhois > 0): ?>
    <div class="card" style="margin-bottom:14px">
      <div class="card-hd">
        <span class="card-title"><i class="fas fa-file-alt" style="color:var(--bl)"></i> Recent WHOIS lookups</span>
        <a href="<?= $url('whois.php') ?>" class="card-link">Lookup →</a>
      </div>
      <div class="card-bd" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0;padding-top:4px;padding-bottom:4px">
        <?php foreach ($whoisLookups as $wh): ?>
        <div class="wh-item" style="padding-right:12px">
          <span class="pill <?= !empty($wh['is_available']) ? 'p-av' : 'p-tak' ?>"><?= !empty($wh['is_available']) ? 'free' : 'taken' ?></span>
          <span class="wh-domain"><?= htmlspecialchars($wh['domain_name'] ?? '') ?></span>
          <span class="wh-exp"><?= !empty($wh['expiry_date']) ? date('M Y', strtotime($wh['expiry_date'])) : '—' ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /content -->
</main>

<div class="toast" id="toast">
  <i id="t-icon" class="fas fa-check-circle" style="color:var(--g2);flex-shrink:0"></i>
  <span id="t-msg"></span>
</div>

<script>
const BASE = <?= json_encode($appBasePath) ?>;
const url  = p => `${BASE}/${p.replace(/^\/+/,'')}`;
const DATA = <?= json_encode($chartData) ?>;

(function(){
  const ctx = document.getElementById('actChart');
  if(!ctx) return;
  const labels = DATA.map((_,i)=>{
    const d = 13-i;
    if(d===0) return 'Today';
    if(d===7) return '7d';
    return '';
  });
  new Chart(ctx,{
    type:'bar',
    data:{
      labels,
      datasets:[{
        data:DATA,
        backgroundColor: DATA.map((_,i)=>i===13?'rgba(26,154,112,.75)':'rgba(255,255,255,.05)'),
        borderColor:     DATA.map((_,i)=>i===13?'rgba(26,154,112,1)':'rgba(255,255,255,.09)'),
        borderWidth:1, borderRadius:3, hoverBackgroundColor:'rgba(26,154,112,.45)'
      }]
    },
    options:{
      responsive:true, maintainAspectRatio:false,
      plugins:{
        legend:{display:false},
        tooltip:{
          backgroundColor:'rgba(15,17,23,.96)',
          borderColor:'rgba(255,255,255,.09)', borderWidth:1,
          titleColor:'#8C8A82', bodyColor:'#ECEAE2',
          titleFont:{family:'DM Mono',size:10},
          bodyFont:{family:'DM Mono',size:11},
          callbacks:{ label: c => c.parsed.y+' search'+(c.parsed.y!==1?'es':'') }
        }
      },
      scales:{
        y:{display:false, beginAtZero:true},
        x:{display:false}
      }
    }
  });
})();

function doSearch(){
  const v = (document.getElementById('qs').value || document.getElementById('topQ').value).trim();
  if(!v) return;
  const d = v.includes('.')?v:v+'.com';
  window.location.href = url('index.php')+'?q='+encodeURIComponent(d);
}
['qs','topQ'].forEach(id=>{
  document.getElementById(id)?.addEventListener('keydown',e=>{ if(e.key==='Enter') doSearch(); });
});

function openSB(){ document.getElementById('cdSidebar')?.classList.add('open'); document.getElementById('overlay')?.classList.add('show'); }
function closeSB(){ document.getElementById('cdSidebar')?.classList.remove('open'); document.getElementById('overlay')?.classList.remove('show'); }

function toast(msg,type='ok'){
  const t=document.getElementById('toast'),i=document.getElementById('t-icon');
  document.getElementById('t-msg').textContent=msg;
  i.className=`fas ${type==='err'?'fa-exclamation-circle':'fa-check-circle'}`;
  i.style.color=type==='err'?'var(--c)':'var(--g2)';
  t.classList.add('show'); clearTimeout(t._t);
  t._t=setTimeout(()=>t.classList.remove('show'),3200);
}
</script>
</body>
</html>
