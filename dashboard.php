<?php
session_start();
require_once 'lib/Auth.php';
require_once 'config/database.php';

$auth = new Auth();

if (!isset($_COOKIE['session_token'])) {
    header('Location: login.php');
    exit();
}

$session = $auth->verifySession($_COOKIE['session_token']);
if (!$session) {
    setcookie('session_token', '', time() - 3600, '/');
    header('Location: login.php');
    exit();
}

$user = $auth->getUserById($session['user_id']);

$conn = getDBConnection();
ensurePinnedDomainTables($conn);

// Watchlist domains
$watchlistStmt = $conn->prepare("SELECT domain_name, pinned_at FROM pinned_domains WHERE user_id = ? AND status = 'active' ORDER BY pinned_at DESC LIMIT 6");
$watchlistStmt->bind_param("i", $session['user_id']);
$watchlistStmt->execute();
$watchlistResult = $watchlistStmt->get_result();
$watchlistDomains = [];
while ($row = $watchlistResult->fetch_assoc()) {
    $watchlistDomains[] = $row;
}
$watchlistStmt->close();
$watchlistCount = count($watchlistDomains);

// Recent searches
$searchStmt = $conn->prepare("SELECT domain_name, result_status, searched_at FROM domain_searches WHERE user_id = ? ORDER BY searched_at DESC LIMIT 6");
$recentSearches = [];
if ($searchStmt) {
    $searchStmt->bind_param("i", $session['user_id']);
    $searchStmt->execute();
    $searchResult = $searchStmt->get_result();
    while ($row = $searchResult->fetch_assoc()) {
        $recentSearches[] = $row;
    }
    $searchStmt->close();
}

// Total searches
$totalStmt = $conn->prepare("SELECT COUNT(*) as total FROM domain_searches WHERE user_id = ?");
$totalSearches = 0;
if ($totalStmt) {
    $totalStmt->bind_param("i", $session['user_id']);
    $totalStmt->execute();
    $totalResult = $totalStmt->get_result()->fetch_assoc();
    $totalSearches = $totalResult['total'] ?? 0;
    $totalStmt->close();
}

$conn->close();

// User meta
$userName = $user['full_name'] ?: explode('@', $user['email'])[0];
$firstName = explode(' ', $userName)[0];
$initials  = strtoupper(substr($userName, 0, 1) . (strpos($userName, ' ') !== false ? substr($userName, strpos($userName, ' ') + 1, 1) : ''));
$userPlan  = $user['plan'] ?? 'free'; // free | pro | elite
$credits   = $user['credits'] ?? 10;

$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$planLabel  = ['free' => 'Free plan', 'pro' => 'Pro plan', 'elite' => 'Elite plan'][$userPlan] ?? 'Free plan';
$planCredits= ['free' => 10, 'pro' => 100, 'elite' => 500][$userPlan] ?? 10;

$appBasePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (in_array($appBasePath, ['/', '.', '\\'])) $appBasePath = '';
$assetUrl = fn($p) => ($appBasePath ?: '') . '/' . ltrim($p, '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — CheckDomain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;700;800&family=DM+Mono:wght@400;500&family=Instrument+Serif:ital@1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root {
  --bg:        #0A0B0E;
  --bg2:       #111318;
  --bg3:       #181C24;
  --bg4:       #1E2230;
  --border:    rgba(255,255,255,0.06);
  --border2:   rgba(255,255,255,0.11);
  --text:      #E9E7DF;
  --text2:     #8A8880;
  --text3:     #454340;
  --green:     #1D9E75;
  --green2:    #14C48A;
  --green-bg:  rgba(29,158,117,0.1);
  --amber:     #EF9F27;
  --amber-bg:  rgba(239,159,39,0.1);
  --coral:     #E8593C;
  --coral-bg:  rgba(232,89,60,0.1);
  --purple:    #7F77DD;
  --purple-bg: rgba(127,119,221,0.1);
  --blue:      #4A90D9;
  --blue-bg:   rgba(74,144,217,0.1);
  --mono:      'DM Mono', monospace;
  --display:   'Syne', sans-serif;
  --serif:     'Instrument Serif', serif;
  --sb-width:  224px;
}

html { scroll-behavior: smooth; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--display);
  min-height: 100vh;
  display: flex;
  overflow-x: hidden;
}

/* Grid bg */
body::before {
  content:'';
  position: fixed; inset: 0;
  background-image:
    linear-gradient(rgba(29,158,117,0.02) 1px, transparent 1px),
    linear-gradient(90deg, rgba(29,158,117,0.02) 1px, transparent 1px);
  background-size: 52px 52px;
  pointer-events: none; z-index: 0;
}

/* ─── SIDEBAR ─────────────────────────────── */
.sidebar {
  width: var(--sb-width);
  flex-shrink: 0;
  background: var(--bg2);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  position: fixed;
  top: 0; left: 0; bottom: 0;
  z-index: 50;
  padding: 22px 0 20px;
  transition: transform 0.25s ease;
}

.sb-logo {
  display: flex; align-items: center; gap: 10px;
  padding: 0 20px 20px;
  border-bottom: 1px solid var(--border);
  margin-bottom: 18px;
  text-decoration: none;
}
.sb-logo-mark {
  width: 28px; height: 28px; border-radius: 7px;
  background: var(--green-bg);
  border: 1px solid rgba(29,158,117,0.25);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.sb-logo-mark svg { width: 14px; height: 14px; color: var(--green2); }
.sb-logo-text { font-size: 13px; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; color: var(--text); }

.sb-section {
  font-size: 10px; font-weight: 500; letter-spacing: 0.15em; text-transform: uppercase;
  color: var(--text3); padding: 0 20px; margin-bottom: 5px;
}

.sb-nav { display: flex; flex-direction: column; gap: 1px; padding: 0 10px; margin-bottom: 18px; }

.sb-link {
  display: flex; align-items: center; gap: 9px;
  padding: 8px 11px; border-radius: 7px;
  font-size: 13px; color: var(--text2);
  text-decoration: none; cursor: pointer;
  transition: background 0.13s, color 0.13s;
  border: none; background: none; width: 100%; text-align: left;
  font-family: var(--display);
  position: relative;
}
.sb-link:hover { background: var(--bg3); color: var(--text); }
.sb-link.active { background: var(--green-bg); color: var(--green2); }
.sb-link.active::before {
  content: '';
  position: absolute; left: 0; top: 20%; bottom: 20%;
  width: 2px; border-radius: 0 2px 2px 0;
  background: var(--green2);
}
.sb-icon { font-size: 14px; flex-shrink: 0; width: 16px; text-align: center; }
.sb-badge {
  margin-left: auto; font-size: 10px; font-weight: 700;
  background: var(--amber-bg); color: var(--amber);
  border-radius: 4px; padding: 1px 6px; font-family: var(--mono);
}
.sb-badge.green { background: var(--green-bg); color: var(--green2); }

.sb-divider { height: 1px; background: var(--border); margin: 4px 20px 14px; }

.sb-bottom { margin-top: auto; padding: 0 10px; display: flex; flex-direction: column; gap: 8px; }

/* Plan upgrade strip */
.sb-upgrade {
  border-radius: 9px;
  background: linear-gradient(135deg, rgba(29,158,117,0.12), rgba(127,119,221,0.08));
  border: 1px solid rgba(29,158,117,0.2);
  padding: 12px 14px;
  text-decoration: none;
  display: block;
  transition: border-color 0.2s;
}
.sb-upgrade:hover { border-color: rgba(29,158,117,0.4); }
.sb-upgrade-label { font-size: 10px; color: var(--text3); text-transform: uppercase; letter-spacing: 0.12em; margin-bottom: 4px; }
.sb-upgrade-title { font-size: 12px; font-weight: 700; color: var(--green2); margin-bottom: 2px; }
.sb-upgrade-sub { font-size: 11px; color: var(--text2); }

.sb-user {
  display: flex; align-items: center; gap: 9px;
  padding: 9px 11px; border-radius: 8px;
  background: var(--bg3); border: 1px solid var(--border);
  cursor: pointer; transition: border-color 0.15s;
}
.sb-user:hover { border-color: var(--border2); }
.sb-avatar {
  width: 30px; height: 30px; border-radius: 50%;
  background: linear-gradient(135deg, var(--green), var(--purple));
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700; color: #fff; flex-shrink: 0;
}
.sb-user-info { flex: 1; min-width: 0; }
.sb-user-name { font-size: 12px; font-weight: 500; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sb-user-plan { font-size: 10px; color: var(--green); font-family: var(--mono); }
.sb-user-caret { font-size: 11px; color: var(--text3); }

/* ─── MAIN ────────────────────────────────── */
.main { margin-left: var(--sb-width); flex: 1; position: relative; z-index: 1; min-height: 100vh; }

/* ─── TOPBAR ──────────────────────────────── */
.topbar {
  display: flex; align-items: center; justify-content: space-between;
  padding: 15px 28px; border-bottom: 1px solid var(--border);
  backdrop-filter: blur(12px);
  background: rgba(10,11,14,0.85);
  position: sticky; top: 0; z-index: 40;
  gap: 14px;
}

.topbar-left { display: flex; align-items: center; gap: 12px; }

.mobile-menu-btn {
  display: none; align-items: center; justify-content: center;
  width: 34px; height: 34px; border-radius: 8px;
  background: var(--bg2); border: 1px solid var(--border);
  color: var(--text2); font-size: 16px; cursor: pointer;
}

.topbar-search {
  display: flex; align-items: center; gap: 8px;
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 8px; padding: 7px 13px;
  font-family: var(--mono); font-size: 13px; color: var(--text2);
  cursor: text; width: 280px;
  transition: border-color 0.2s, width 0.3s;
}
.topbar-search:focus-within { border-color: var(--green); width: 340px; }
.topbar-search input {
  background: none; border: none; outline: none;
  font-family: var(--mono); font-size: 13px; color: var(--text);
  caret-color: var(--green); flex: 1; min-width: 0;
}
.topbar-search input::placeholder { color: var(--text3); }

.topbar-right { display: flex; align-items: center; gap: 10px; }

.topbar-btn {
  display: flex; align-items: center; justify-content: center;
  width: 33px; height: 33px; border-radius: 8px;
  background: var(--bg2); border: 1px solid var(--border);
  color: var(--text2); font-size: 14px; cursor: pointer;
  position: relative; transition: border-color 0.15s, color 0.15s;
  text-decoration: none;
}
.topbar-btn:hover { border-color: var(--border2); color: var(--text); }
.notif-dot {
  position: absolute; top: 5px; right: 5px;
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--amber); border: 1.5px solid var(--bg);
}

.upgrade-btn {
  display: flex; align-items: center; gap: 6px;
  background: var(--green); color: #fff; border: none;
  border-radius: 8px; padding: 7px 15px;
  font-family: var(--display); font-size: 11px; font-weight: 700;
  letter-spacing: 0.06em; cursor: pointer; text-transform: uppercase;
  transition: background 0.2s; text-decoration: none; white-space: nowrap;
}
.upgrade-btn:hover { background: var(--green2); }

/* Credits pill in topbar */
.credits-pill {
  display: flex; align-items: center; gap: 6px;
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: 8px; padding: 6px 12px;
  font-family: var(--mono); font-size: 12px; color: var(--text2);
}
.credits-pill span { color: var(--amber); font-weight: 700; }

/* ─── CONTENT ─────────────────────────────── */
.content { padding: 28px 28px 40px; }

/* ─── PAGE HEADER ─────────────────────────── */
.page-header { margin-bottom: 24px; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
.page-greeting { font-family: var(--serif); font-style: italic; font-size: 26px; color: var(--text); margin-bottom: 4px; }
.page-sub { font-size: 13px; color: var(--text2); }
.page-sub em { color: var(--green); font-family: var(--mono); font-style: normal; }
.page-sub .warn { color: var(--amber); font-family: var(--mono); }

/* ─── PLAN BANNER (shows for free users) ─── */
.plan-banner {
  background: linear-gradient(135deg, rgba(239,159,39,0.08), rgba(29,158,117,0.06));
  border: 1px solid rgba(239,159,39,0.2);
  border-radius: 12px; padding: 14px 20px;
  display: flex; align-items: center; gap: 16px;
  margin-bottom: 24px;
}
.plan-banner-icon { font-size: 20px; flex-shrink: 0; }
.plan-banner-text { flex: 1; }
.plan-banner-title { font-size: 13px; font-weight: 700; color: var(--text); margin-bottom: 2px; }
.plan-banner-sub { font-size: 12px; color: var(--text2); }
.plan-banner-cta {
  background: var(--amber); color: #000; border: none;
  border-radius: 7px; padding: 7px 16px;
  font-family: var(--display); font-size: 11px; font-weight: 800;
  text-transform: uppercase; letter-spacing: 0.06em;
  cursor: pointer; white-space: nowrap; text-decoration: none;
  transition: opacity 0.2s;
}
.plan-banner-cta:hover { opacity: 0.85; }

/* ─── STAT GRID ───────────────────────────── */
.stat-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; margin-bottom: 24px; }

.stat-card {
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: 12px; padding: 16px 18px;
  position: relative; overflow: hidden;
  transition: border-color 0.2s, transform 0.15s;
  cursor: default;
}
.stat-card:hover { border-color: var(--border2); transform: translateY(-1px); }
.stat-card::before {
  content:''; position:absolute; top:0; left:0; right:0; height:2px;
  border-radius:12px 12px 0 0;
}
.stat-card.c-green::before  { background: var(--green); }
.stat-card.c-amber::before  { background: var(--amber); }
.stat-card.c-purple::before { background: var(--purple); }
.stat-card.c-coral::before  { background: var(--coral); }
.stat-label  { font-size: 10px; color: var(--text3); letter-spacing: 0.11em; text-transform: uppercase; margin-bottom: 9px; }
.stat-number { font-size: 26px; font-weight: 800; color: var(--text); margin-bottom: 4px; font-family: var(--mono); line-height: 1; }
.stat-change { font-size: 11px; display: flex; align-items: center; gap: 4px; }
.stat-change.up      { color: var(--green2); }
.stat-change.down    { color: var(--coral); }
.stat-change.neutral { color: var(--text3); }

/* Credits bar in stat card */
.credits-bar-wrap { margin-top: 8px; height: 3px; background: var(--border); border-radius: 2px; overflow: hidden; }
.credits-bar-fill  { height: 100%; border-radius: 2px; transition: width 0.6s ease; }

/* ─── TWO COLUMN ──────────────────────────── */
.two-col { display: grid; grid-template-columns: 1fr 340px; gap: 18px; margin-bottom: 24px; }

/* ─── CARD BASE ───────────────────────────── */
.card {
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: 14px; overflow: hidden;
  transition: border-color 0.2s;
}
.card:hover { border-color: var(--border2); }
.card-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 18px; border-bottom: 1px solid var(--border);
}
.card-title { font-size: 12px; font-weight: 700; letter-spacing: 0.06em; color: var(--text); }
.card-action {
  font-size: 11px; color: var(--green); cursor: pointer;
  text-decoration: none; background: none; border: none;
  font-family: var(--display); font-weight: 500; white-space: nowrap;
}
.card-action:hover { color: var(--green2); }
.card-body { padding: 14px 18px; }

/* ─── CHART ───────────────────────────────── */
.chart-wrap  { padding: 14px 18px 6px; }
.chart-bars  { display: flex; align-items: flex-end; gap: 3px; height: 52px; }
.bar {
  flex: 1; border-radius: 3px 3px 0 0;
  background: var(--bg4);
  transition: background 0.2s; cursor: pointer; min-width: 0;
}
.bar:hover  { background: rgba(29,158,117,0.5); }
.bar.active { background: var(--green); }
.chart-labels { display: flex; justify-content: space-between; padding: 0 18px 12px; }
.chart-lbl    { font-size: 10px; color: var(--text3); font-family: var(--mono); }

/* ─── RECENT SEARCHES ─────────────────────── */
.search-item {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 0; border-bottom: 1px solid var(--border);
}
.search-item:last-child { border-bottom: none; }
.search-domain { font-family: var(--mono); font-size: 12px; color: var(--text); flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.search-time   { font-size: 10px; color: var(--text3); white-space: nowrap; }
.status-pill {
  font-size: 9px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;
  padding: 2px 7px; border-radius: 4px; white-space: nowrap; flex-shrink: 0;
}
.sp-available { background: var(--green-bg);  color: var(--green2); }
.sp-taken     { background: var(--amber-bg);  color: var(--amber); }
.sp-dead      { background: var(--coral-bg);  color: var(--coral); }
.sp-unknown   { background: var(--bg4);       color: var(--text3); }
.search-action {
  font-size: 11px; color: var(--text3); cursor: pointer;
  background: none; border: none; font-family: var(--display);
  padding: 3px 8px; border-radius: 5px;
  transition: background 0.13s, color 0.13s;
}
.search-action:hover { background: var(--bg3); color: var(--text); }

.quick-search-row {
  display: flex; gap: 8px; padding: 0 18px 14px;
}
.quick-search-row input {
  flex: 1; background: var(--bg3); border: 1px solid var(--border2);
  border-radius: 7px; padding: 7px 11px;
  font-family: var(--mono); font-size: 12px; color: var(--text);
  outline: none; transition: border-color 0.2s;
}
.quick-search-row input::placeholder { color: var(--text3); }
.quick-search-row input:focus { border-color: var(--green); }
.quick-search-row button {
  background: var(--green); color: #fff; border: none;
  border-radius: 7px; padding: 7px 13px;
  font-family: var(--display); font-size: 11px; font-weight: 700;
  cursor: pointer; transition: background 0.2s;
}
.quick-search-row button:hover { background: var(--green2); }

/* ─── WATCHLIST ───────────────────────────── */
.watch-item {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 0; border-bottom: 1px solid var(--border);
}
.watch-item:last-child { border-bottom: none; }
.watch-icon {
  width: 28px; height: 28px; border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; flex-shrink: 0;
}
.wi-green  { background: var(--green-bg);  color: var(--green2); }
.wi-amber  { background: var(--amber-bg);  color: var(--amber); }
.wi-purple { background: var(--purple-bg); color: var(--purple); }
.watch-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; animation: pulse 2s infinite; }
.wd-green { background: var(--green); }
.wd-amber { background: var(--amber); }
.wd-coral { background: var(--coral); }
@keyframes pulse { 0%,100%{opacity:1}50%{opacity:0.35} }
.watch-domain { font-family: var(--mono); font-size: 12px; color: var(--text); }
.watch-meta   { font-size: 10px; color: var(--text2); margin-top: 1px; }
.watch-time   { font-size: 10px; color: var(--text3); margin-left: auto; white-space: nowrap; font-family: var(--mono); }

.empty-state {
  text-align: center; padding: 28px 18px;
  color: var(--text3); font-size: 12px;
  display: flex; flex-direction: column; align-items: center; gap: 8px;
}
.empty-state-icon { font-size: 24px; opacity: 0.5; }

/* ─── MONETIZATION: PRICING PLANS ────────── */
.pricing-section { margin-bottom: 24px; }
.pricing-header { margin-bottom: 14px; display: flex; align-items: center; justify-content: space-between; }
.pricing-title { font-size: 12px; font-weight: 700; letter-spacing: 0.06em; color: var(--text); text-transform: uppercase; }
.pricing-sub   { font-size: 12px; color: var(--text2); }

.plans-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; }

.plan-card {
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: 14px; padding: 20px 18px;
  display: flex; flex-direction: column; gap: 14px;
  position: relative; overflow: hidden;
  transition: border-color 0.2s, transform 0.15s;
}
.plan-card:hover { transform: translateY(-2px); }
.plan-card.plan-popular {
  border-color: rgba(29,158,117,0.35);
  background: linear-gradient(160deg, rgba(29,158,117,0.06), var(--bg2) 60%);
}
.plan-popular-badge {
  position: absolute; top: 12px; right: 12px;
  font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em;
  background: var(--green-bg); color: var(--green2);
  border: 1px solid rgba(29,158,117,0.25);
  border-radius: 4px; padding: 2px 7px;
}
.plan-name  { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; color: var(--text2); }
.plan-price { font-family: var(--mono); font-size: 28px; font-weight: 700; color: var(--text); line-height: 1; }
.plan-price sup { font-size: 14px; vertical-align: top; margin-top: 4px; display: inline-block; }
.plan-price sub { font-size: 12px; color: var(--text2); }
.plan-desc  { font-size: 12px; color: var(--text2); line-height: 1.5; }

.plan-features { display: flex; flex-direction: column; gap: 7px; }
.plan-feat {
  display: flex; align-items: center; gap: 8px;
  font-size: 12px; color: var(--text2);
}
.plan-feat i { color: var(--green2); font-size: 11px; flex-shrink: 0; }
.plan-feat.disabled { color: var(--text3); }
.plan-feat.disabled i { color: var(--text3); }

.plan-cta {
  width: 100%; padding: 9px;
  border-radius: 8px; font-family: var(--display);
  font-size: 12px; font-weight: 700;
  cursor: pointer; transition: all 0.2s;
  border: 1px solid var(--border2); background: none;
  color: var(--text2); letter-spacing: 0.04em;
  text-decoration: none; display: block; text-align: center;
  margin-top: auto;
}
.plan-cta:hover { background: var(--bg3); color: var(--text); }
.plan-cta.cta-primary {
  background: var(--green); border-color: var(--green); color: #fff;
}
.plan-cta.cta-primary:hover { background: var(--green2); border-color: var(--green2); }
.plan-cta.cta-current {
  background: var(--bg3); border-color: var(--border);
  color: var(--text3); cursor: default;
}

/* ─── BOTTOM 3-COL ────────────────────────── */
.bottom-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 18px; }

/* ─── ACTIVITY FEED ───────────────────────── */
.activity-item { display: flex; gap: 10px; padding: 9px 0; position: relative; }
.activity-item::before {
  content:''; position:absolute; left: 14px; top: 32px; bottom: -9px;
  width: 1px; background: var(--border);
}
.activity-item:last-child::before { display: none; }
.act-icon {
  width: 28px; height: 28px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; flex-shrink: 0; border: 2px solid var(--bg2);
}
.ai-green  { background: var(--green-bg);  color: var(--green2); }
.ai-amber  { background: var(--amber-bg);  color: var(--amber); }
.ai-purple { background: var(--purple-bg); color: var(--purple); }
.ai-coral  { background: var(--coral-bg);  color: var(--coral); }
.act-text  { font-size: 12px; color: var(--text2); flex: 1; line-height: 1.5; }
.act-text b { color: var(--text); font-weight: 500; font-family: var(--mono); }
.act-time  { font-size: 10px; color: var(--text3); margin-top: 2px; }

/* ─── CREDIT USAGE ────────────────────────── */
.credit-row {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 0; border-bottom: 1px solid var(--border);
  font-size: 12px;
}
.credit-row:last-child { border-bottom: none; }
.credit-label  { color: var(--text2); flex: 1; }
.credit-amount { font-family: var(--mono); font-size: 11px; color: var(--text); white-space: nowrap; }
.credit-bar-wrap { width: 56px; height: 3px; background: var(--border); border-radius: 2px; flex-shrink: 0; }
.credit-bar-fill { height: 100%; border-radius: 2px; }

/* ─── ALERTS ──────────────────────────────── */
.alert-item {
  display: flex; gap: 10px; padding: 10px 0;
  border-bottom: 1px solid var(--border);
}
.alert-item:last-child { border-bottom: none; }
.alert-icon {
  width: 28px; height: 28px; border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; flex-shrink: 0;
}
.alert-text { font-size: 12px; color: var(--text2); flex: 1; line-height: 1.5; }
.alert-domain { font-family: var(--mono); color: var(--text); }
.alert-time   { font-size: 10px; color: var(--text3); margin-top: 2px; }
.alert-cta {
  display: inline-block; margin-top: 5px;
  font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
  padding: 3px 9px; border-radius: 4px; border: none; cursor: pointer;
  font-family: var(--display); transition: opacity 0.15s;
}
.cta-green { background: var(--green-bg); color: var(--green2); }
.cta-amber { background: var(--amber-bg); color: var(--amber); }

/* ─── TOAST ───────────────────────────────── */
.toast {
  position: fixed; bottom: 28px; right: 28px; z-index: 999;
  background: var(--bg3); border: 1px solid var(--border2);
  border-radius: 10px; padding: 12px 18px;
  font-size: 13px; color: var(--text);
  box-shadow: 0 8px 32px rgba(0,0,0,0.4);
  transform: translateY(20px); opacity: 0;
  transition: all 0.3s ease;
  max-width: 320px;
}
.toast.show { transform: translateY(0); opacity: 1; }
.toast.success { border-color: rgba(29,158,117,0.3); }
.toast.error   { border-color: rgba(232,89,60,0.3); }

/* ─── RESPONSIVE ──────────────────────────── */
@media (max-width: 1100px) {
  .plans-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 960px) {
  .two-col { grid-template-columns: 1fr; }
  .bottom-grid { grid-template-columns: 1fr 1fr; }
  .stat-grid { grid-template-columns: repeat(2,1fr); }
}
@media (max-width: 768px) {
  .sidebar { transform: translateX(-100%); }
  .sidebar.open { transform: translateX(0); }
  .main { margin-left: 0; }
  .mobile-menu-btn { display: flex; }
  .content { padding: 20px 16px 40px; }
  .bottom-grid { grid-template-columns: 1fr; }
  .plans-grid { grid-template-columns: 1fr; }
  .topbar-search { width: 180px; }
  .topbar-search:focus-within { width: 220px; }
  .credits-pill { display: none; }
}
@media (max-width: 480px) {
  .stat-grid { grid-template-columns: 1fr 1fr; }
  .upgrade-btn { display: none; }
}

/* Mobile overlay */
.sidebar-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,0.6); z-index: 49;
}
.sidebar-overlay.show { display: block; }
</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ═══════════════════════════════════
     SIDEBAR
═══════════════════════════════════ -->
<?php
require_once 'includes/sidebar.php'; 
?>

<!-- ═══════════════════════════════════
     MAIN
═══════════════════════════════════ -->
<main class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="topbar-left">
      <button class="mobile-menu-btn" onclick="openSidebar()" aria-label="Open menu">
        <i class="fas fa-bars"></i>
      </button>
      <div class="topbar-search">
        <i class="fas fa-search" style="color:var(--text3);font-size:12px;"></i>
        <input type="text" placeholder="Search any domain..." id="topSearch" autocomplete="off">
      </div>
    </div>
    <div class="topbar-right">
      <div class="credits-pill">
        <i class="fas fa-bolt" style="color:var(--amber);font-size:11px;"></i>
        <span><?= $credits ?></span> credits
      </div>
      <a href="#" class="topbar-btn" title="Notifications">
        <i class="fas fa-bell"></i>
        <span class="notif-dot"></span>
      </a>
      <a href="account-settings.php" class="topbar-btn" title="Settings">
        <i class="fas fa-cog"></i>
      </a>
      <?php if ($userPlan === 'free'): ?>
      <a href="#pricing" class="upgrade-btn" onclick="scrollToPricing(event)">
        <i class="fas fa-arrow-up" style="font-size:10px;"></i> Upgrade
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- CONTENT -->
  <div class="content">

    <!-- PAGE HEADER -->
    <div class="page-header">
      <div>
        <div class="page-greeting"><?= $greeting ?>, <?= htmlspecialchars($firstName) ?>.</div>
        <div class="page-sub">
          <?php if ($watchlistCount > 0): ?>
            You have <em><?= $watchlistCount ?> domains</em> on your watchlist
            <?php if ($credits < 5): ?> and <span class="warn"><?= $credits ?> credits</span> remaining — running low<?php endif; ?>.
          <?php else: ?>
            Start by searching a domain — we'll track availability for you.
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- FREE PLAN BANNER -->
    <?php if ($userPlan === 'free'): ?>
    <div class="plan-banner">
      <div class="plan-banner-icon">⚡</div>
      <div class="plan-banner-text">
        <div class="plan-banner-title">You're on the Free plan</div>
        <div class="plan-banner-sub">Upgrade to Pro for 100 credits, WHOIS unlocks, backorder alerts, and dead-site detection.</div>
      </div>
      <a href="#pricing" class="plan-banner-cta" onclick="scrollToPricing(event)">See plans</a>
    </div>
    <?php endif; ?>

    <!-- STAT CARDS -->
    <?php
      $creditsPercent = min(100, round(($credits / $planCredits) * 100));
      $creditColor = $creditsPercent > 50 ? 'var(--green)' : ($creditsPercent > 20 ? 'var(--amber)' : 'var(--coral)');
    ?>
    <div class="stat-grid">
      <div class="stat-card c-green">
        <div class="stat-label">Domains watched</div>
        <div class="stat-number"><?= $watchlistCount ?></div>
        <div class="stat-change <?= $watchlistCount > 0 ? 'up' : 'neutral' ?>">
          <?= $watchlistCount > 0 ? '↑ monitoring active' : '· none yet' ?>
        </div>
      </div>
      <div class="stat-card c-amber">
        <div class="stat-label">Active backorders</div>
        <div class="stat-number">0</div>
        <div class="stat-change neutral">· none placed</div>
      </div>
      <div class="stat-card c-purple">
        <div class="stat-label">Total searches</div>
        <div class="stat-number"><?= number_format($totalSearches) ?></div>
        <div class="stat-change neutral">· all time</div>
      </div>
      <div class="stat-card c-coral">
        <div class="stat-label">Credits remaining</div>
        <div class="stat-number"><?= $credits ?></div>
        <div class="stat-change <?= $creditsPercent < 20 ? 'down' : 'neutral' ?>">
          <?= $creditsPercent < 20 ? '↓ running low' : '· of ' . $planCredits . ' (' . $planLabel . ')' ?>
        </div>
        <div class="credits-bar-wrap">
          <div class="credits-bar-fill" style="width:<?= $creditsPercent ?>%;background:<?= $creditColor ?>;"></div>
        </div>
      </div>
    </div>

    <!-- TWO COLUMN: chart + searches | watchlist + alerts -->
    <div class="two-col">

      <!-- LEFT -->
      <div style="display:flex;flex-direction:column;gap:18px;">

        <!-- Search activity chart -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">Search activity — last 14 days</span>
            <a href="#" class="card-action">Full history →</a>
          </div>
          <div class="chart-wrap">
            <div class="chart-bars" id="chartBars"></div>
          </div>
          <div class="chart-labels">
            <span class="chart-lbl">−14d</span>
            <span class="chart-lbl">−10d</span>
            <span class="chart-lbl">−7d</span>
            <span class="chart-lbl">−3d</span>
            <span class="chart-lbl">Today</span>
          </div>
        </div>

        <!-- Recent searches -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">Recent searches</span>
            <a href="index.php" class="card-action">Search new →</a>
          </div>
          <div class="card-body" style="padding-top:4px;padding-bottom:4px;">
            <?php if (!empty($recentSearches)): ?>
              <?php foreach ($recentSearches as $s):
                $status = $s['result_status'] ?? 'unknown';
                $pillClass = match($status) {
                  'available' => 'sp-available',
                  'taken'     => 'sp-taken',
                  'dead'      => 'sp-dead',
                  default     => 'sp-unknown',
                };
                $action = match($status) {
                  'available' => 'Register',
                  'taken'     => 'Watch',
                  'dead'      => 'Backorder',
                  default     => 'Check',
                };
                $ts = strtotime($s['searched_at']);
                $diff = time() - $ts;
                $timeStr = $diff < 60 ? 'just now' : ($diff < 3600 ? round($diff/60).'m ago' : ($diff < 86400 ? round($diff/3600).'h ago' : 'Yesterday'));
              ?>
              <div class="search-item">
                <div class="status-pill <?= $pillClass ?>"><?= htmlspecialchars($status) ?></div>
                <span class="search-domain"><?= htmlspecialchars($s['domain_name']) ?></span>
                <span class="search-time"><?= $timeStr ?></span>
                <button class="search-action"><?= $action ?></button>
              </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="empty-state">
                <div class="empty-state-icon"><i class="fas fa-search"></i></div>
                <div>No searches yet — try checking a domain.</div>
              </div>
            <?php endif; ?>
          </div>
          <div class="quick-search-row">
            <input type="text" id="quickInput" placeholder="mybrand.com or just mybrand" autocomplete="off">
            <button onclick="quickSearch()">Check →</button>
          </div>
        </div>

      </div>

      <!-- RIGHT -->
      <div style="display:flex;flex-direction:column;gap:18px;">

        <!-- Alerts -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">
              <i class="fas fa-bell" style="color:var(--amber);margin-right:5px;"></i> Alerts
              <span style="background:var(--amber-bg);color:var(--amber);font-size:10px;padding:1px 6px;border-radius:4px;margin-left:6px;font-family:var(--mono);">3 new</span>
            </span>
            <a href="#" class="card-action">All alerts →</a>
          </div>
          <div class="card-body" style="padding-top:4px;padding-bottom:4px;">
            <div class="alert-item">
              <div class="alert-icon ai-coral"><i class="fas fa-fire"></i></div>
              <div class="alert-text">
                <span class="alert-domain">mybrand.io</span> — site down 14 days, expires in 18d.
                <div><button class="alert-cta cta-amber">Backorder $9</button></div>
                <div class="alert-time">2 minutes ago</div>
              </div>
            </div>
            <div class="alert-item">
              <div class="alert-icon ai-amber"><i class="fas fa-clock"></i></div>
              <div class="alert-text">
                <span class="alert-domain">techlaunch.com</span> expires in <strong style="color:var(--amber);font-family:var(--mono);">18 days</strong>.
                <div class="alert-time">1 hour ago</div>
              </div>
            </div>
            <div class="alert-item">
              <div class="alert-icon ai-green"><i class="fas fa-check"></i></div>
              <div class="alert-text">
                <span class="alert-domain">growafrica.ng</span> is now available.
                <div><button class="alert-cta cta-green">Register now</button></div>
                <div class="alert-time">3 hours ago</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Watchlist -->
        <div class="card" id="watchlist">
          <div class="card-header">
            <span class="card-title">Watchlist</span>
            <a href="#" class="card-action">Manage →</a>
          </div>
          <div class="card-body" style="padding-top:4px;padding-bottom:4px;">
            <?php if (!empty($watchlistDomains)): ?>
              <?php foreach ($watchlistDomains as $i => $w):
                $colors = ['wi-green','wi-amber','wi-purple'];
                $dots   = ['wd-green','wd-amber','wd-coral'];
                $icons  = ['fas fa-globe','fas fa-clock','fas fa-bolt'];
                $ci     = $i % 3;
                $ts     = strtotime($w['pinned_at']);
                $diff   = time() - $ts;
                $timeStr= $diff < 86400 ? round($diff/3600).'h ago' : round($diff/86400).'d ago';
              ?>
              <div class="watch-item">
                <span class="watch-dot <?= $dots[$ci] ?>"></span>
                <div class="watch-icon <?= $colors[$ci] ?>"><i class="<?= $icons[$ci] ?>"></i></div>
                <div>
                  <div class="watch-domain"><?= htmlspecialchars($w['domain_name']) ?></div>
                  <div class="watch-meta">Monitoring · added <?= $timeStr ?></div>
                </div>
                <div class="watch-time">watching</div>
              </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="empty-state">
                <div class="empty-state-icon"><i class="fas fa-bookmark"></i></div>
                <div>No domains on your watchlist yet.</div>
              </div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>

    <!-- ═══════════════════════════════════
         PRICING / MONETIZATION
    ═══════════════════════════════════ -->
    <div class="pricing-section" id="pricing">
      <div class="pricing-header">
        <div>
          <div class="pricing-title">Plans &amp; Pricing</div>
          <div class="pricing-sub">Pick a plan that fits your domain hunting needs.</div>
        </div>
      </div>

      <div class="plans-grid">

        <!-- FREE -->
        <div class="plan-card">
          <div>
            <div class="plan-name">Free</div>
            <div class="plan-price"><sup>$</sup>0<sub>/mo</sub></div>
            <div class="plan-desc">Perfect for occasional checks and basic watchlisting.</div>
          </div>
          <div class="plan-features">
            <div class="plan-feat"><i class="fas fa-check-circle"></i> 10 credits / month</div>
            <div class="plan-feat"><i class="fas fa-check-circle"></i> 5 watchlist domains</div>
            <div class="plan-feat"><i class="fas fa-check-circle"></i> Basic availability check</div>
            <div class="plan-feat disabled"><i class="fas fa-times-circle"></i> WHOIS deep lookup</div>
            <div class="plan-feat disabled"><i class="fas fa-times-circle"></i> Drop / expiry alerts</div>
            <div class="plan-feat disabled"><i class="fas fa-times-circle"></i> Backorder placement</div>
            <div class="plan-feat disabled"><i class="fas fa-times-circle"></i> Dead site detection</div>
          </div>
          <?php if ($userPlan === 'free'): ?>
          <span class="plan-cta cta-current">Current plan</span>
          <?php else: ?>
          <a href="#" class="plan-cta">Downgrade</a>
          <?php endif; ?>
        </div>

        <!-- PRO (popular) -->
        <div class="plan-card plan-popular">
          <span class="plan-popular-badge">Most popular</span>
          <div>
            <div class="plan-name">Pro</div>
            <div class="plan-price"><sup>$</sup>9<sub>/mo</sub></div>
            <div class="plan-desc">For domain hunters who want every edge on expiring names.</div>
          </div>
          <div class="plan-features">
            <div class="plan-feat"><i class="fas fa-check-circle"></i> 100 credits / month</div>
            <div class="plan-feat"><i class="fas fa-check-circle"></i> Unlimited watchlist</div>
            <div class="plan-feat"><i class="fas fa-check-circle"></i> WHOIS deep lookup</div>
            <div class="plan-feat"><i class="fas fa-check-circle"></i> Drop &amp; expiry alerts</div>
            <div class="plan-feat"><i class="fas fa-check-circle"></i> Backorder placement</div>
            <div class="plan-feat"><i class="fas fa-check-circle"></i> Dead site detection</div>
            <div class="plan-feat disabled"><i class="fas fa-times-circle"></i> Broker service</div>
          </div>
          <?php if ($userPlan === 'pro'): ?>
          <span class="plan-cta cta-current">Current plan</span>
          <?php else: ?>
          <a href="billing.php?plan=pro" class="plan-cta cta-primary">Upgrade to Pro →</a>
          <?php endif; ?>
        </div>

        <!-- ELITE -->
        <div class="plan-card">
          <div>
            <div class="plan-name">Elite</div>
            <div class="plan-price"><sup>$</sup>29<sub>/mo</sub></div>
            <div class="plan-desc">Full access including broker service and bulk lookups.</div>
          </div>
          <div class="plan-features">
            <div class="plan-feat"><i class="fas fa-check-circle"></i> 500 credits / month</div>
            <div class="plan-feat"><i class="fas fa-check-circle"></i> Unlimited watchlist</div>
            <div class="plan-feat"><i class="fas fa-check-circle"></i> WHOIS deep lookup</div>
            <div class="plan-feat"><i class="fas fa-check-circle"></i> Drop &amp; expiry alerts</div>
            <div class="plan-feat"><i class="fas fa-check-circle"></i> Backorder placement</div>
            <div class="plan-feat"><i class="fas fa-check-circle"></i> Dead site detection</div>
            <div class="plan-feat"><i class="fas fa-check-circle"></i> Broker service access</div>
          </div>
          <?php if ($userPlan === 'elite'): ?>
          <span class="plan-cta cta-current">Current plan</span>
          <?php else: ?>
          <a href="billing.php?plan=elite" class="plan-cta">Get Elite →</a>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <!-- BOTTOM 3-COL: backorders | credits | activity -->
    <div class="bottom-grid">

      <!-- Active backorders -->
      <div class="card">
        <div class="card-header">
          <span class="card-title">Active backorders</span>
          <a href="#" class="card-action">View all →</a>
        </div>
        <div class="card-body" style="padding-top:4px;padding-bottom:4px;">
          <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-clock"></i></div>
            <div>No backorders yet.</div>
          </div>
        </div>
      </div>

      <!-- Credits breakdown -->
      <div class="card">
        <div class="card-header">
          <span class="card-title">Credits used this month</span>
          <a href="#pricing" class="card-action" onclick="scrollToPricing(event)">Buy more →</a>
        </div>
        <div class="card-body" style="padding-top:4px;padding-bottom:4px;">
          <div class="credit-row">
            <span class="credit-label"><i class="fas fa-search" style="margin-right:6px;font-size:11px;"></i> Domain checks</span>
            <span class="credit-amount"><?= max(0, $planCredits - $credits) ?> credits</span>
            <div class="credit-bar-wrap">
              <div class="credit-bar-fill" style="width:<?= 100 - $creditsPercent ?>%;background:var(--green);"></div>
            </div>
          </div>
          <div class="credit-row">
            <span class="credit-label"><i class="fas fa-file-alt" style="margin-right:6px;font-size:11px;"></i> WHOIS unlocks</span>
            <span class="credit-amount">0 credits</span>
            <div class="credit-bar-wrap">
              <div class="credit-bar-fill" style="width:0%;background:var(--amber);"></div>
            </div>
          </div>
          <div class="credit-row">
            <span class="credit-label"><i class="fas fa-bell" style="margin-right:6px;font-size:11px;"></i> Drop alerts</span>
            <span class="credit-amount">0 credits</span>
            <div class="credit-bar-wrap">
              <div class="credit-bar-fill" style="width:0%;background:var(--purple);"></div>
            </div>
          </div>
          <div class="credit-row">
            <span class="credit-label"><i class="fas fa-clock" style="margin-right:6px;font-size:11px;"></i> Backorders</span>
            <span class="credit-amount">0 credits</span>
            <div class="credit-bar-wrap">
              <div class="credit-bar-fill" style="width:0%;background:var(--coral);"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Activity feed -->
      <div class="card">
        <div class="card-header">
          <span class="card-title">Activity feed</span>
        </div>
        <div class="card-body" style="padding-top:4px;padding-bottom:4px;">
          <?php if (!empty($recentSearches)): ?>
            <?php foreach (array_slice($recentSearches, 0, 4) as $s):
              $status = $s['result_status'] ?? 'unknown';
              $iconClass = match($status) {
                'available' => 'ai-green',
                'taken'     => 'ai-amber',
                'dead'      => 'ai-coral',
                default     => 'ai-purple',
              };
              $iconName = match($status) {
                'available' => 'fas fa-check',
                'taken'     => 'fas fa-search',
                'dead'      => 'fas fa-skull',
                default     => 'fas fa-eye',
              };
              $ts   = strtotime($s['searched_at']);
              $diff = time() - $ts;
              $timeStr = $diff < 60 ? 'just now' : ($diff < 3600 ? round($diff/60).'m ago' : ($diff < 86400 ? round($diff/3600).'h ago' : 'Yesterday'));
            ?>
            <div class="activity-item">
              <div class="act-icon <?= $iconClass ?>"><i class="<?= $iconName ?>"></i></div>
              <div>
                <div class="act-text">Searched <b><?= htmlspecialchars($s['domain_name']) ?></b> — <?= $status ?></div>
                <div class="act-time"><?= $timeStr ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="empty-state">
              <div class="empty-state-icon"><i class="fas fa-stream"></i></div>
              <div>Your activity will appear here.</div>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>

  </div><!-- /.content -->
</main>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<script>
const APP_BASE = <?= json_encode($appBasePath ?? '') ?>;
const appUrl = p => `${APP_BASE}/${String(p).replace(/^\/+/,'')}`;

/* Chart sparkline */
(function() {
  const data = [2,5,3,8,4,11,7,5,13,9,6,17,14,<?= max(1, count($recentSearches)) ?>];
  const max = Math.max(...data);
  const wrap = document.getElementById('chartBars');
  if (!wrap) return;
  data.forEach((v, i) => {
    const bar = document.createElement('div');
    bar.className = 'bar' + (i === data.length-1 ? ' active' : '');
    bar.style.height = Math.round((v/max)*100) + '%';
    bar.title = v + ' searches';
    wrap.appendChild(bar);
  });
})();

/* Mobile sidebar */
function openSidebar() {
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('sidebarOverlay').classList.add('show');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('show');
}

/* Scroll to pricing */
function scrollToPricing(e) {
  e.preventDefault();
  document.getElementById('pricing').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/* Quick search */
function quickSearch() {
  const val = (document.getElementById('quickInput').value || document.getElementById('topSearch').value).trim();
  if (!val) return;
  const domain = val.includes('.') ? val : val + '.com';
  window.location.href = appUrl('index.php') + '?q=' + encodeURIComponent(domain);
}

document.getElementById('quickInput').addEventListener('keydown', e => { if (e.key === 'Enter') quickSearch(); });
document.getElementById('topSearch').addEventListener('keydown', e => { if (e.key === 'Enter') quickSearch(); });

/* Toast utility */
function showToast(msg, isError) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast show ' + (isError ? 'error' : 'success');
  clearTimeout(t._timer);
  t._timer = setTimeout(() => { t.classList.remove('show'); }, 3200);
}

/* Alert CTAs */
document.querySelectorAll('.alert-cta').forEach(btn => {
  btn.addEventListener('click', () => showToast('Feature coming soon — upgrade to Pro to place backorders.', false));
});
</script>
</body>
</html>