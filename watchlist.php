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

$user      = $auth->getUserById($session['user_id']);
$conn      = getDBConnection();
ensurePinnedDomainTables($conn);

// ── Pagination ────────────────────────────────────────────
$perPage    = 12;
$page       = max(1, (int)($_GET['page'] ?? 1));
$offset     = ($page - 1) * $perPage;
$filterTld  = trim($_GET['tld']  ?? '');
$sortBy     = in_array($_GET['sort'] ?? '', ['az','za','newest','oldest']) ? $_GET['sort'] : 'newest';

// ── Count total ───────────────────────────────────────────
$countBase  = "SELECT COUNT(*) as total FROM pinned_domains WHERE user_id = ? AND status = 'active'";
$countParams = [$session['user_id']];
$countTypes  = 'i';
if ($filterTld) {
    $countBase  .= " AND domain_name LIKE ?";
    $countParams[] = '%.' . $conn->real_escape_string($filterTld);
    $countTypes  .= 's';
}
$cStmt = $conn->prepare($countBase);
$cStmt->bind_param($countTypes, ...$countParams);
$cStmt->execute();
$totalCount = $cStmt->get_result()->fetch_assoc()['total'];
$cStmt->close();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page = min($page, $totalPages);

// ── Fetch domains ─────────────────────────────────────────
$orderClause = match($sortBy) {
    'az'     => 'ORDER BY domain_name ASC',
    'za'     => 'ORDER BY domain_name DESC',
    'oldest' => 'ORDER BY pinned_at ASC',
    default  => 'ORDER BY pinned_at DESC',
};
$fetchBase = "SELECT domain_name, pinned_at, status FROM pinned_domains WHERE user_id = ? AND status = 'active'";
if ($filterTld) {
    $fetchBase .= " AND domain_name LIKE ?";
}
$fetchBase .= " $orderClause LIMIT ? OFFSET ?";

$fetchParams = array_merge($countParams, [$perPage, $offset]);
$fetchTypes  = $countTypes . 'ii';
$fStmt = $conn->prepare($fetchBase);
$fStmt->bind_param($fetchTypes, ...$fetchParams);
$fStmt->execute();
$fetchResult = $fStmt->get_result();
$domains = [];
while ($row = $fetchResult->fetch_assoc()) {
    $domains[] = $row;
}
$fStmt->close();

// ── Collect unique TLDs for filter bar ────────────────────
$tldStmt = $conn->prepare("SELECT DISTINCT domain_name FROM pinned_domains WHERE user_id = ? AND status = 'active'");
$tldStmt->bind_param('i', $session['user_id']);
$tldStmt->execute();
$tldResult = $tldStmt->get_result();
$allTlds = [];
while ($r = $tldResult->fetch_assoc()) {
    $parts = explode('.', $r['domain_name']);
    $tld   = end($parts);
    if ($tld) $allTlds[$tld] = ($allTlds[$tld] ?? 0) + 1;
}
$tldStmt->close();
arsort($allTlds);

$conn->close();

// ── User meta ─────────────────────────────────────────────
$userName    = $user['full_name'] ?: explode('@', $user['email'])[0];
$firstName   = explode(' ', $userName)[0];
$userPlan    = $user['plan']    ?? 'free';
$credits     = $user['credits'] ?? 10;
$planLabel   = ['free' => 'Free plan', 'pro' => 'Pro plan', 'elite' => 'Elite plan'][$userPlan] ?? 'Free plan';
$planLimit   = ['free' => 5, 'pro' => 0, 'elite' => 0][$userPlan] ?? 5; // 0 = unlimited
$limitReached = $planLimit > 0 && $totalCount >= $planLimit;

// ── URL helpers ───────────────────────────────────────────
$appBasePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (in_array($appBasePath, ['/', '.', '\\'])) $appBasePath = '';
$assetUrl = fn($p) => ($appBasePath ?: '') . '/' . ltrim($p, '/');

// Build pagination URL helper
function pageUrl(int $p, string $sort, string $tld): string {
    $q = http_build_query(array_filter(['page' => $p > 1 ? $p : null, 'sort' => $sort !== 'newest' ? $sort : null, 'tld' => $tld]));
    return 'watchlist.php' . ($q ? "?$q" : '');
}

// Active page for sidebar
$activePage      = 'watchlist';
$watchlistCount  = $totalCount;
$alertCount      = 0; // wire up when alerts table exists
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Watchlist — CheckDomain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;700;800&family=DM+Mono:wght@400;500&family=Instrument+Serif:ital@1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ─── Reset ──────────────────────────────────────────────── */
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }

/* ─── Design tokens (mirrors dashboard) ─────────────────── */
:root {
  --bg:          #0A0B0E;
  --bg2:         #111318;
  --bg3:         #181C24;
  --bg4:         #1E2230;
  --border:      rgba(255,255,255,0.06);
  --border2:     rgba(255,255,255,0.11);
  --text:        #E9E7DF;
  --text2:       #8A8880;
  --text3:       #454340;
  --green:       #1D9E75;
  --green2:      #14C48A;
  --green-bg:    rgba(29,158,117,0.1);
  --amber:       #EF9F27;
  --amber-bg:    rgba(239,159,39,0.1);
  --coral:       #E8593C;
  --coral-bg:    rgba(232,89,60,0.1);
  --purple:      #7F77DD;
  --purple-bg:   rgba(127,119,221,0.1);
  --mono:        'DM Mono', monospace;
  --display:     'Syne', sans-serif;
  --serif:       'Instrument Serif', serif;
  --sb-width:    224px;
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

/* grid bg */
body::before {
  content: '';
  position: fixed; inset: 0;
  background-image:
    linear-gradient(rgba(29,158,117,0.02) 1px, transparent 1px),
    linear-gradient(90deg, rgba(29,158,117,0.02) 1px, transparent 1px);
  background-size: 52px 52px;
  pointer-events: none; z-index: 0;
}

/* ─── MAIN ──────────────────────────────────────────────── */
.main {
  margin-left: var(--sb-width);
  flex: 1;
  position: relative; z-index: 1;
  min-height: 100vh;
}

/* ─── TOPBAR ─────────────────────────────────────────────── */
.topbar {
  display: flex; align-items: center; justify-content: space-between;
  padding: 15px 28px;
  border-bottom: 1px solid var(--border);
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
.topbar-breadcrumb {
  display: flex; align-items: center; gap: 6px;
  font-size: 12px; color: var(--text3);
}
.topbar-breadcrumb a { color: var(--text3); text-decoration: none; transition: color 0.15s; }
.topbar-breadcrumb a:hover { color: var(--text2); }
.topbar-breadcrumb .sep { font-size: 10px; }
.topbar-breadcrumb .current { color: var(--text2); font-weight: 500; }

.topbar-right { display: flex; align-items: center; gap: 10px; }
.topbar-btn {
  display: flex; align-items: center; justify-content: center;
  width: 33px; height: 33px; border-radius: 8px;
  background: var(--bg2); border: 1px solid var(--border);
  color: var(--text2); font-size: 14px; cursor: pointer;
  text-decoration: none; transition: border-color 0.15s, color 0.15s;
  position: relative;
}
.topbar-btn:hover { border-color: var(--border2); color: var(--text); }
.notif-dot {
  position: absolute; top: 5px; right: 5px;
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--amber); border: 1.5px solid var(--bg);
}
.credits-pill {
  display: flex; align-items: center; gap: 6px;
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: 8px; padding: 6px 12px;
  font-family: var(--mono); font-size: 12px; color: var(--text2);
}
.credits-pill span { color: var(--amber); font-weight: 700; }
.add-btn {
  display: flex; align-items: center; gap: 7px;
  background: var(--green); color: #fff; border: none;
  border-radius: 8px; padding: 8px 16px;
  font-family: var(--display); font-size: 12px; font-weight: 700;
  letter-spacing: 0.05em; cursor: pointer;
  transition: background 0.2s; white-space: nowrap;
}
.add-btn:hover { background: var(--green2); }
.add-btn:disabled { opacity: 0.45; cursor: not-allowed; }

/* ─── CONTENT ────────────────────────────────────────────── */
.content { padding: 28px 28px 48px; }

/* ─── PAGE HEADER ────────────────────────────────────────── */
.page-header {
  display: flex; align-items: flex-end; justify-content: space-between;
  gap: 16px; margin-bottom: 24px; flex-wrap: wrap;
}
.page-title-group {}
.page-eyebrow {
  font-size: 10px; font-weight: 500; letter-spacing: 0.18em;
  text-transform: uppercase; color: var(--green);
  font-family: var(--mono); margin-bottom: 5px;
}
.page-title {
  font-family: var(--serif); font-style: italic;
  font-size: 28px; color: var(--text); line-height: 1.1;
}
.page-count {
  font-family: var(--mono); font-size: 12px;
  color: var(--text3); margin-top: 5px;
}
.page-count em { color: var(--text2); font-style: normal; }

/* ─── LIMIT BANNER ───────────────────────────────────────── */
.limit-banner {
  background: linear-gradient(135deg, var(--amber-bg), rgba(29,158,117,0.04));
  border: 1px solid rgba(239,159,39,0.22);
  border-radius: 12px; padding: 14px 20px;
  display: flex; align-items: center; gap: 14px;
  margin-bottom: 22px;
}
.limit-banner-icon { font-size: 18px; flex-shrink: 0; }
.limit-banner-text { flex: 1; }
.limit-banner-title { font-size: 13px; font-weight: 700; color: var(--text); margin-bottom: 2px; }
.limit-banner-sub   { font-size: 12px; color: var(--text2); }
.limit-banner-cta {
  background: var(--amber); color: #000; border: none;
  border-radius: 7px; padding: 7px 16px; font-family: var(--display);
  font-size: 11px; font-weight: 800; text-transform: uppercase;
  letter-spacing: 0.06em; cursor: pointer; white-space: nowrap;
  text-decoration: none; transition: opacity 0.2s;
}
.limit-banner-cta:hover { opacity: 0.85; }

/* ─── TOOLBAR ────────────────────────────────────────────── */
.toolbar {
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 18px; flex-wrap: wrap;
}

/* Search input */
.toolbar-search {
  display: flex; align-items: center; gap: 8px;
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 8px; padding: 8px 13px;
  font-family: var(--mono); min-width: 220px; flex: 1; max-width: 340px;
  transition: border-color 0.2s;
}
.toolbar-search:focus-within { border-color: var(--green); }
.toolbar-search i { font-size: 12px; color: var(--text3); }
.toolbar-search input {
  background: none; border: none; outline: none;
  font-family: var(--mono); font-size: 13px; color: var(--text);
  caret-color: var(--green); flex: 1; min-width: 0;
}
.toolbar-search input::placeholder { color: var(--text3); }

/* Sort select */
.toolbar-select {
  background: var(--bg2); border: 1px solid var(--border2);
  color: var(--text2); border-radius: 8px;
  padding: 8px 32px 8px 12px; font-family: var(--display);
  font-size: 12px; cursor: pointer; outline: none;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23454340'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 10px center;
  transition: border-color 0.2s;
}
.toolbar-select:focus { border-color: var(--green); color: var(--text); }

/* TLD pills */
.tld-bar {
  display: flex; align-items: center; gap: 6px;
  flex-wrap: wrap; margin-bottom: 18px;
}
.tld-pill {
  font-family: var(--mono); font-size: 11px;
  padding: 4px 10px; border-radius: 20px;
  border: 1px solid var(--border); background: var(--bg2);
  color: var(--text3); cursor: pointer; transition: all 0.15s;
  text-decoration: none;
}
.tld-pill:hover  { border-color: var(--border2); color: var(--text2); }
.tld-pill.active { background: var(--green-bg); border-color: rgba(29,158,117,0.3); color: var(--green2); }
.tld-count { opacity: 0.6; margin-left: 3px; }

/* ─── DOMAIN GRID ────────────────────────────────────────── */
.domain-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 12px;
  margin-bottom: 28px;
}

/* ─── DOMAIN CARD ────────────────────────────────────────── */
.domain-card {
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: 14px; padding: 18px;
  display: flex; flex-direction: column; gap: 14px;
  position: relative; overflow: hidden;
  transition: border-color 0.2s, transform 0.15s, box-shadow 0.2s;
  animation: cardIn 0.3s ease both;
}
.domain-card:hover {
  border-color: var(--border2);
  transform: translateY(-2px);
  box-shadow: 0 8px 28px rgba(0,0,0,0.3);
}
.domain-card.removing {
  animation: cardOut 0.3s ease forwards;
  pointer-events: none;
}
@keyframes cardIn {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: translateY(0); }
}
@keyframes cardOut {
  from { opacity: 1; transform: scale(1); }
  to   { opacity: 0; transform: scale(0.95); }
}

/* Accent line top */
.domain-card::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 2px;
  border-radius: 14px 14px 0 0;
  background: linear-gradient(90deg, var(--green), var(--purple));
  opacity: 0;
  transition: opacity 0.2s;
}
.domain-card:hover::before { opacity: 1; }

/* Card top row */
.dc-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.dc-icon {
  width: 36px; height: 36px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; flex-shrink: 0;
  background: var(--green-bg);
}
/* Vary icon bg by TLD category */
.dc-icon.tld-com  { background: var(--green-bg);  color: var(--green2); }
.dc-icon.tld-io   { background: var(--purple-bg); color: var(--purple); }
.dc-icon.tld-ng   { background: var(--amber-bg);  color: var(--amber); }
.dc-icon.tld-org  { background: rgba(74,144,217,0.1); color: #4A90D9; }
.dc-icon.tld-other{ background: var(--bg4);        color: var(--text3); }

.dc-menu-btn {
  width: 28px; height: 28px; border-radius: 6px;
  background: none; border: none;
  color: var(--text3); font-size: 14px; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  opacity: 0; transition: opacity 0.15s, background 0.15s, color 0.15s;
  flex-shrink: 0;
}
.domain-card:hover .dc-menu-btn { opacity: 1; }
.dc-menu-btn:hover { background: var(--bg3); color: var(--text); }

/* Domain name */
.dc-name {
  font-family: var(--mono); font-size: 14px; font-weight: 500;
  color: var(--text); word-break: break-all; line-height: 1.35;
  flex: 1;
}
.dc-name .tld { color: var(--text3); }

/* Status badge */
.dc-status {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 10px; font-weight: 700; letter-spacing: 0.08em;
  text-transform: uppercase; padding: 3px 8px; border-radius: 4px;
  font-family: var(--mono);
}
.dc-status.watching  { background: var(--green-bg);  color: var(--green2); }
.dc-status.notified  { background: var(--amber-bg);  color: var(--amber); }
.dc-status.expired   { background: var(--bg4);       color: var(--text3); }
.dc-status i { font-size: 8px; animation: pulse 2s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.3} }

/* Card meta row */
.dc-meta {
  display: flex; align-items: center; justify-content: space-between;
  font-size: 11px; color: var(--text3);
}
.dc-date { font-family: var(--mono); }

/* Card actions */
.dc-actions { display: flex; gap: 6px; }
.dc-action {
  flex: 1; padding: 7px 8px; border-radius: 7px;
  font-family: var(--display); font-size: 11px; font-weight: 600;
  cursor: pointer; border: 1px solid var(--border); background: none;
  color: var(--text3); transition: all 0.15s; text-align: center;
  text-decoration: none; display: flex; align-items: center;
  justify-content: center; gap: 5px;
}
.dc-action:hover       { border-color: var(--border2); color: var(--text2); background: var(--bg3); }
.dc-action.primary     { background: var(--green-bg); border-color: rgba(29,158,117,0.2); color: var(--green2); }
.dc-action.primary:hover { background: rgba(29,158,117,0.18); border-color: var(--green); }
.dc-action.danger      { }
.dc-action.danger:hover{ background: var(--coral-bg); border-color: rgba(232,89,60,0.25); color: var(--coral); }

/* Dropdown menu */
.dc-dropdown {
  position: absolute; top: 44px; right: 14px;
  background: var(--bg3); border: 1px solid var(--border2);
  border-radius: 9px; overflow: hidden;
  min-width: 160px; z-index: 20;
  box-shadow: 0 8px 28px rgba(0,0,0,0.4);
  display: none;
}
.dc-dropdown.show { display: block; animation: dropIn 0.15s ease; }
@keyframes dropIn {
  from { opacity: 0; transform: translateY(-6px); }
  to   { opacity: 1; transform: translateY(0); }
}
.dd-item {
  display: flex; align-items: center; gap: 9px;
  padding: 9px 14px; font-size: 12px; color: var(--text2);
  cursor: pointer; transition: background 0.12s, color 0.12s;
  border: none; background: none; width: 100%;
  font-family: var(--display); text-align: left;
  text-decoration: none;
}
.dd-item:hover { background: var(--bg4); color: var(--text); }
.dd-item.dd-danger { color: var(--coral); }
.dd-item.dd-danger:hover { background: var(--coral-bg); color: var(--coral); }
.dd-item i { width: 14px; text-align: center; font-size: 12px; }
.dd-divider { height: 1px; background: var(--border); margin: 3px 0; }

/* ─── EMPTY STATE ────────────────────────────────────────── */
.empty-state {
  grid-column: 1 / -1;
  display: flex; flex-direction: column; align-items: center;
  justify-content: center; padding: 60px 20px; text-align: center;
  gap: 12px;
}
.empty-icon {
  width: 64px; height: 64px; border-radius: 16px;
  background: var(--bg3); border: 1px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  font-size: 24px; color: var(--text3); margin-bottom: 4px;
}
.empty-title { font-size: 16px; font-weight: 700; color: var(--text); }
.empty-sub   { font-size: 13px; color: var(--text2); max-width: 320px; line-height: 1.6; }
.empty-cta {
  display: inline-flex; align-items: center; gap: 8px;
  background: var(--green); color: #fff; border: none;
  border-radius: 9px; padding: 10px 22px;
  font-family: var(--display); font-size: 13px; font-weight: 700;
  cursor: pointer; text-decoration: none; margin-top: 6px;
  transition: background 0.2s;
}
.empty-cta:hover { background: var(--green2); }

/* ─── PAGINATION ─────────────────────────────────────────── */
.pagination {
  display: flex; align-items: center; justify-content: center;
  gap: 6px; padding-top: 8px;
}
.pg-btn {
  display: flex; align-items: center; justify-content: center;
  min-width: 33px; height: 33px; border-radius: 7px;
  font-family: var(--mono); font-size: 12px;
  background: var(--bg2); border: 1px solid var(--border);
  color: var(--text3); cursor: pointer; text-decoration: none;
  transition: all 0.15s; padding: 0 8px;
}
.pg-btn:hover   { border-color: var(--border2); color: var(--text2); }
.pg-btn.active  { background: var(--green-bg); border-color: rgba(29,158,117,0.3); color: var(--green2); }
.pg-btn.disabled{ opacity: 0.3; pointer-events: none; }
.pg-ellipsis    { font-size: 12px; color: var(--text3); padding: 0 4px; }

/* ─── ADD MODAL ──────────────────────────────────────────── */
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.65); backdrop-filter: blur(4px);
  z-index: 100; display: none; align-items: center; justify-content: center;
  padding: 20px;
}
.modal-overlay.show { display: flex; animation: fadeIn 0.2s ease; }
@keyframes fadeIn { from{opacity:0} to{opacity:1} }
.modal {
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 16px; padding: 28px; width: 100%; max-width: 440px;
  box-shadow: 0 24px 64px rgba(0,0,0,0.6);
  animation: slideUp 0.25s ease;
}
@keyframes slideUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
.modal-header { margin-bottom: 22px; }
.modal-title { font-size: 17px; font-weight: 800; color: var(--text); margin-bottom: 4px; }
.modal-sub   { font-size: 13px; color: var(--text2); }
.modal-field { margin-bottom: 16px; }
.modal-label {
  display: block; font-size: 11px; font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.1em;
  color: var(--text3); margin-bottom: 7px;
}
.modal-input {
  width: 100%;
  background: var(--bg3); border: 1px solid var(--border2);
  border-radius: 9px; padding: 11px 14px;
  font-family: var(--mono); font-size: 13px; color: var(--text);
  outline: none; transition: border-color 0.2s;
}
.modal-input::placeholder { color: var(--text3); }
.modal-input:focus { border-color: var(--green); }
.modal-input.error { border-color: var(--coral); }
.modal-error  { font-size: 12px; color: var(--coral); margin-top: 6px; display: none; }
.modal-error.show { display: block; }
.modal-actions { display: flex; gap: 10px; margin-top: 22px; }
.modal-cancel {
  flex: 1; padding: 10px; border-radius: 8px;
  background: none; border: 1px solid var(--border2);
  color: var(--text2); font-family: var(--display);
  font-size: 13px; font-weight: 600; cursor: pointer;
  transition: all 0.15s;
}
.modal-cancel:hover { background: var(--bg3); color: var(--text); }
.modal-submit {
  flex: 2; padding: 10px; border-radius: 8px;
  background: var(--green); border: none;
  color: #fff; font-family: var(--display);
  font-size: 13px; font-weight: 700; cursor: pointer;
  transition: background 0.2s;
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.modal-submit:hover    { background: var(--green2); }
.modal-submit:disabled { opacity: 0.5; cursor: not-allowed; }
.modal-spinner { display: none; }
.modal-submit.loading .modal-spinner { display: inline-block; animation: spin 0.8s linear infinite; }
.modal-submit.loading .modal-btn-text { display: none; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Confirm modal */
.confirm-modal { max-width: 380px; }
.confirm-title { font-size: 16px; font-weight: 800; color: var(--text); margin-bottom: 6px; }
.confirm-sub   { font-size: 13px; color: var(--text2); line-height: 1.6; }
.confirm-domain { font-family: var(--mono); color: var(--text); }
.confirm-actions { display: flex; gap: 10px; margin-top: 22px; }
.btn-cancel-confirm {
  flex: 1; padding: 10px; border-radius: 8px;
  background: none; border: 1px solid var(--border2);
  color: var(--text2); font-family: var(--display);
  font-size: 13px; cursor: pointer; transition: all 0.15s;
}
.btn-cancel-confirm:hover { background: var(--bg3); }
.btn-remove {
  flex: 2; padding: 10px; border-radius: 8px;
  background: var(--coral-bg); border: 1px solid rgba(232,89,60,0.25);
  color: var(--coral); font-family: var(--display);
  font-size: 13px; font-weight: 700; cursor: pointer;
  transition: all 0.2s;
}
.btn-remove:hover { background: var(--coral); color: #fff; border-color: var(--coral); }

/* ─── TOAST ──────────────────────────────────────────────── */
.toast {
  position: fixed; bottom: 28px; right: 28px; z-index: 999;
  background: var(--bg3); border: 1px solid var(--border2);
  border-radius: 10px; padding: 12px 18px;
  font-size: 13px; color: var(--text);
  box-shadow: 0 8px 32px rgba(0,0,0,0.4);
  transform: translateY(20px); opacity: 0;
  transition: all 0.3s ease; max-width: 320px;
  display: flex; align-items: center; gap: 10px;
}
.toast.show { transform: translateY(0); opacity: 1; }
.toast.success { border-color: rgba(29,158,117,0.3); }
.toast.error   { border-color: rgba(232,89,60,0.3); }
.toast i { font-size: 14px; flex-shrink: 0; }
.toast.success i { color: var(--green2); }
.toast.error   i { color: var(--coral); }

/* ─── RESPONSIVE ─────────────────────────────────────────── */
@media (max-width: 768px) {
  .main { margin-left: 0; }
  .mobile-menu-btn { display: flex; }
  .content { padding: 20px 16px 40px; }
  .domain-grid { grid-template-columns: 1fr; }
  .credits-pill { display: none; }
  .toolbar { gap: 8px; }
  .toolbar-search { min-width: 0; }
}
@media (max-width: 480px) {
  .add-btn span { display: none; }
  .page-header { flex-direction: column; align-items: flex-start; }
}

/* Sidebar mobile overlay */
.sidebar-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,0.6); z-index: 49;
}
.sidebar-overlay.show { display: block; }
</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<?php require_once 'includes/sidebar.php'; ?>

<main class="main">

  <!-- ─── TOPBAR ─────────────────────────────────────────── -->
  <div class="topbar">
    <div class="topbar-left">
      <button class="mobile-menu-btn" onclick="openSidebar()" aria-label="Open menu">
        <i class="fas fa-bars"></i>
      </button>
      <nav class="topbar-breadcrumb" aria-label="Breadcrumb">
        <a href="<?= htmlspecialchars($assetUrl('dashboard.php')) ?>">Dashboard</a>
        <span class="sep"><i class="fas fa-chevron-right"></i></span>
        <span class="current">Watchlist</span>
      </nav>
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
      <a href="<?= htmlspecialchars($assetUrl('account-settings.php')) ?>" class="topbar-btn" title="Settings">
        <i class="fas fa-cog"></i>
      </a>
      <button class="add-btn" id="openAddModal"
        <?= $limitReached ? 'disabled title="Upgrade to add more domains"' : '' ?>>
        <i class="fas fa-plus" style="font-size:11px;"></i>
        <span>Add domain</span>
      </button>
    </div>
  </div>

  <!-- ─── CONTENT ───────────────────────────────────────── -->
  <div class="content">

    <!-- Page header -->
    <div class="page-header">
      <div class="page-title-group">
        <div class="page-eyebrow"><i class="fas fa-bookmark" style="margin-right:5px;"></i> Watchlist</div>
        <div class="page-title">Your watched domains</div>
        <div class="page-count">
          <?php if ($totalCount > 0): ?>
            Monitoring <em><?= number_format($totalCount) ?> <?= $totalCount === 1 ? 'domain' : 'domains' ?></em><?php if ($planLimit > 0): ?> · <em><?= $planLimit - $totalCount ?></em> slots remaining<?php endif; ?>
          <?php else: ?>
            No domains on watch yet
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Plan limit banner -->
    <?php if ($limitReached): ?>
    <div class="limit-banner">
      <div class="limit-banner-icon">⚡</div>
      <div class="limit-banner-text">
        <div class="limit-banner-title">Free plan limit reached (<?= $planLimit ?> domains)</div>
        <div class="limit-banner-sub">Upgrade to Pro for unlimited watchlist slots, drop alerts, and WHOIS lookups.</div>
      </div>
      <a href="<?= htmlspecialchars($assetUrl('billing.php?plan=pro')) ?>" class="limit-banner-cta">Upgrade → Pro</a>
    </div>
    <?php endif; ?>

    <!-- Toolbar -->
    <?php if ($totalCount > 0): ?>
    <div class="toolbar">
      <div class="toolbar-search">
        <i class="fas fa-search"></i>
        <input type="text" id="localSearch" placeholder="Filter your watchlist…" autocomplete="off">
      </div>
      <select class="toolbar-select" id="sortSelect" onchange="applySort(this.value)">
        <option value="newest" <?= $sortBy === 'newest' ? 'selected' : '' ?>>Newest first</option>
        <option value="oldest" <?= $sortBy === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
        <option value="az"     <?= $sortBy === 'az'     ? 'selected' : '' ?>>A → Z</option>
        <option value="za"     <?= $sortBy === 'za'     ? 'selected' : '' ?>>Z → A</option>
      </select>
    </div>

    <!-- TLD filter pills -->
    <?php if (count($allTlds) > 1): ?>
    <div class="tld-bar">
      <a href="<?= htmlspecialchars(pageUrl($page, $sortBy, '')) ?>"
         class="tld-pill <?= $filterTld === '' ? 'active' : '' ?>">
        All <span class="tld-count"><?= $totalCount ?></span>
      </a>
      <?php foreach ($allTlds as $tld => $cnt): ?>
      <a href="<?= htmlspecialchars(pageUrl(1, $sortBy, $tld)) ?>"
         class="tld-pill <?= $filterTld === $tld ? 'active' : '' ?>">
        .<?= htmlspecialchars($tld) ?><span class="tld-count"><?= $cnt ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Domain grid -->
    <div class="domain-grid" id="domainGrid">

      <?php if (empty($domains)): ?>
        <div class="empty-state">
          <div class="empty-icon"><i class="fas fa-bookmark"></i></div>
          <div class="empty-title">
            <?= $filterTld ? "No .{$filterTld} domains on your watchlist" : 'Your watchlist is empty' ?>
          </div>
          <div class="empty-sub">
            <?= $filterTld
              ? "Try a different TLD filter, or add a .{$filterTld} domain to start tracking."
              : 'Search for a domain on the homepage and hit "Watch" to track it here. We\'ll notify you when it becomes available.' ?>
          </div>
          <?php if (!$filterTld): ?>
          <a href="<?= htmlspecialchars($assetUrl('index.php')) ?>" class="empty-cta">
            <i class="fas fa-search" style="font-size:12px;"></i> Search a domain
          </a>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <?php foreach ($domains as $i => $d):
          $domainParts = explode('.', $d['domain_name']);
          $tldKey      = strtolower(end($domainParts));
          $sld         = implode('.', array_slice($domainParts, 0, -1));
          $iconClass   = match(true) {
            $tldKey === 'com'              => 'tld-com',
            in_array($tldKey, ['io','app','dev','ai']) => 'tld-io',
            in_array($tldKey, ['ng','com.ng']) => 'tld-ng',
            $tldKey === 'org'              => 'tld-org',
            default                        => 'tld-other',
          };
          $ts       = strtotime($d['pinned_at']);
          $diff     = time() - $ts;
          $dateStr  = date('M j, Y', $ts);
          $timeAgo  = $diff < 60 ? 'just now'
                    : ($diff < 3600 ? round($diff/60).'m ago'
                    : ($diff < 86400 ? round($diff/3600).'h ago'
                    : ($diff < 604800 ? round($diff/86400).'d ago'
                    : $dateStr)));
        ?>
        <div class="domain-card" id="card-<?= $i ?>"
             data-domain="<?= htmlspecialchars($d['domain_name']) ?>"
             style="animation-delay: <?= min($i * 0.04, 0.4) ?>s">

          <div class="dc-top">
            <div class="dc-icon <?= $iconClass ?>">
              <i class="fas fa-globe"></i>
            </div>
            <div class="dc-name" style="margin-top:8px;">
              <?= htmlspecialchars($sld) ?><span class="tld">.<?= htmlspecialchars($tldKey) ?></span>
            </div>
            <button class="dc-menu-btn"
                    onclick="toggleMenu(event, 'menu-<?= $i ?>')"
                    aria-label="Domain options">
              <i class="fas fa-ellipsis-h"></i>
            </button>
          </div>

          <div>
            <span class="dc-status watching">
              <i class="fas fa-circle"></i> Watching
            </span>
          </div>

          <div class="dc-meta">
            <span class="dc-date" title="<?= $dateStr ?>">Added <?= $timeAgo ?></span>
          </div>

          <div class="dc-actions">
            <a href="<?= htmlspecialchars($assetUrl('index.php') . '?q=' . urlencode($d['domain_name'])) ?>"
               class="dc-action primary">
              <i class="fas fa-search" style="font-size:10px;"></i> Check
            </a>
            <button class="dc-action" onclick="copyDomain('<?= htmlspecialchars($d['domain_name']) ?>')" title="Copy domain">
              <i class="fas fa-copy" style="font-size:10px;"></i> Copy
            </button>
            <button class="dc-action danger" onclick="confirmRemove('<?= htmlspecialchars($d['domain_name']) ?>', <?= $i ?>)" title="Remove">
              <i class="fas fa-trash" style="font-size:10px;"></i>
            </button>
          </div>

          <!-- Dropdown menu -->
          <div class="dc-dropdown" id="menu-<?= $i ?>">
            <a href="<?= htmlspecialchars($assetUrl('index.php') . '?q=' . urlencode($d['domain_name'])) ?>"
               class="dd-item">
              <i class="fas fa-search"></i> Check availability
            </a>
            <button class="dd-item" onclick="copyDomain('<?= htmlspecialchars($d['domain_name']) ?>')">
              <i class="fas fa-copy"></i> Copy domain
            </button>
            <a href="https://<?= htmlspecialchars($d['domain_name']) ?>" target="_blank" rel="noopener"
               class="dd-item">
              <i class="fas fa-external-link-alt"></i> Visit site
            </a>
            <div class="dd-divider"></div>
            <button class="dd-item dd-danger"
                    onclick="confirmRemove('<?= htmlspecialchars($d['domain_name']) ?>', <?= $i ?>)">
              <i class="fas fa-trash"></i> Remove
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>

    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <a href="<?= htmlspecialchars(pageUrl($page - 1, $sortBy, $filterTld)) ?>"
         class="pg-btn <?= $page <= 1 ? 'disabled' : '' ?>" aria-label="Previous">
        <i class="fas fa-chevron-left" style="font-size:11px;"></i>
      </a>
      <?php
      $range = 2;
      $start = max(1, $page - $range);
      $end   = min($totalPages, $page + $range);
      if ($start > 1): ?>
        <a href="<?= htmlspecialchars(pageUrl(1, $sortBy, $filterTld)) ?>" class="pg-btn">1</a>
        <?php if ($start > 2): ?><span class="pg-ellipsis">…</span><?php endif; ?>
      <?php endif; ?>
      <?php for ($p = $start; $p <= $end; $p++): ?>
        <a href="<?= htmlspecialchars(pageUrl($p, $sortBy, $filterTld)) ?>"
           class="pg-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($end < $totalPages): ?>
        <?php if ($end < $totalPages - 1): ?><span class="pg-ellipsis">…</span><?php endif; ?>
        <a href="<?= htmlspecialchars(pageUrl($totalPages, $sortBy, $filterTld)) ?>" class="pg-btn"><?= $totalPages ?></a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars(pageUrl($page + 1, $sortBy, $filterTld)) ?>"
         class="pg-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" aria-label="Next">
        <i class="fas fa-chevron-right" style="font-size:11px;"></i>
      </a>
    </div>
    <?php endif; ?>

  </div><!-- /.content -->
</main>

<!-- ─── ADD DOMAIN MODAL ───────────────────────────────── -->
<div class="modal-overlay" id="addModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="modalTitle">Add to watchlist</div>
      <div class="modal-sub">We'll notify you when the domain becomes available.</div>
    </div>
    <div class="modal-field">
      <label class="modal-label" for="addDomainInput">Domain name</label>
      <input class="modal-input" type="text" id="addDomainInput"
             placeholder="e.g. mybrand.com or mybrand"
             autocomplete="off" autocapitalize="none" spellcheck="false">
      <div class="modal-error" id="addDomainError"></div>
    </div>
    <div class="modal-actions">
      <button class="modal-cancel" onclick="closeAddModal()">Cancel</button>
      <button class="modal-submit" id="addSubmitBtn" onclick="submitAdd()">
        <span class="modal-btn-text"><i class="fas fa-bookmark" style="font-size:11px;"></i> Add to watchlist</span>
        <i class="fas fa-sync-alt modal-spinner"></i>
      </button>
    </div>
  </div>
</div>

<!-- ─── CONFIRM REMOVE MODAL ──────────────────────────── -->
<div class="modal-overlay" id="confirmModal" role="dialog" aria-modal="true">
  <div class="modal confirm-modal">
    <div class="confirm-title">Remove from watchlist?</div>
    <div class="confirm-sub">
      You'll stop receiving alerts for <span class="confirm-domain" id="confirmDomainName"></span>. You can re-add it any time.
    </div>
    <div class="confirm-actions">
      <button class="btn-cancel-confirm" onclick="closeConfirmModal()">Keep it</button>
      <button class="btn-remove" id="confirmRemoveBtn">
        <i class="fas fa-trash" style="font-size:11px;margin-right:5px;"></i> Remove
      </button>
    </div>
  </div>
</div>

<!-- ─── TOAST ──────────────────────────────────────────── -->
<div class="toast" id="toast" role="status" aria-live="polite">
  <i class="fas fa-check-circle"></i>
  <span id="toastMsg"></span>
</div>

<script>
const APP_BASE = <?= json_encode($appBasePath ?? '') ?>;
const appUrl   = p => `${APP_BASE}/${String(p).replace(/^\/+/, '')}`;

// ── Toast ─────────────────────────────────────────────────
function showToast(msg, isError = false) {
  const t   = document.getElementById('toast');
  const ico = t.querySelector('i');
  document.getElementById('toastMsg').textContent = msg;
  t.className = `toast show ${isError ? 'error' : 'success'}`;
  ico.className = isError ? 'fas fa-times-circle' : 'fas fa-check-circle';
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.remove('show'), 3400);
}

// ── Sidebar (mobile) ──────────────────────────────────────
function openSidebar() {
  document.getElementById('cdSidebar').classList.add('open');
  document.getElementById('sidebarOverlay').classList.add('show');
}
function closeSidebar() {
  document.getElementById('cdSidebar')?.classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('show');
}

// ── Dropdown menus ────────────────────────────────────────
let openMenuId = null;
function toggleMenu(e, id) {
  e.stopPropagation();
  const menu = document.getElementById(id);
  if (!menu) return;
  if (openMenuId && openMenuId !== id) {
    document.getElementById(openMenuId)?.classList.remove('show');
  }
  const isOpen = menu.classList.toggle('show');
  openMenuId = isOpen ? id : null;
}
document.addEventListener('click', () => {
  if (openMenuId) {
    document.getElementById(openMenuId)?.classList.remove('show');
    openMenuId = null;
  }
});

// ── Copy domain ───────────────────────────────────────────
function copyDomain(domain) {
  navigator.clipboard?.writeText(domain).then(() => {
    showToast(`${domain} copied to clipboard`);
  }).catch(() => {
    // fallback
    const el = document.createElement('textarea');
    el.value = domain; document.body.appendChild(el);
    el.select(); document.execCommand('copy');
    document.body.removeChild(el);
    showToast(`${domain} copied`);
  });
}

// ── Sort select ───────────────────────────────────────────
function applySort(val) {
  const url = new URL(window.location.href);
  if (val === 'newest') url.searchParams.delete('sort');
  else url.searchParams.set('sort', val);
  url.searchParams.delete('page');
  window.location.href = url.toString();
}

// ── Local filter (client-side on current page) ────────────
document.getElementById('localSearch')?.addEventListener('input', function () {
  const q = this.value.toLowerCase().trim();
  document.querySelectorAll('.domain-card').forEach(card => {
    const domain = card.dataset.domain?.toLowerCase() || '';
    card.style.display = (!q || domain.includes(q)) ? '' : 'none';
  });
});

// ── Add domain modal ──────────────────────────────────────
const addModal     = document.getElementById('addModal');
const addInput     = document.getElementById('addDomainInput');
const addError     = document.getElementById('addDomainError');
const addSubmitBtn = document.getElementById('addSubmitBtn');

document.getElementById('openAddModal')?.addEventListener('click', () => {
  addModal.classList.add('show');
  setTimeout(() => addInput.focus(), 120);
});

function closeAddModal() {
  addModal.classList.remove('show');
  addInput.value = '';
  addError.textContent = '';
  addError.classList.remove('show');
  addInput.classList.remove('error');
  addSubmitBtn.classList.remove('loading');
  addSubmitBtn.disabled = false;
}

addModal.addEventListener('click', e => { if (e.target === addModal) closeAddModal(); });
addInput.addEventListener('keydown', e => { if (e.key === 'Enter') submitAdd(); });

async function submitAdd() {
  const raw    = addInput.value.trim();
  const domain = raw.includes('.') ? raw : raw + '.com';

  if (!raw) {
    showFieldError('Please enter a domain name.');
    return;
  }
  if (!/^[a-zA-Z0-9][a-zA-Z0-9\-\.]{1,251}[a-zA-Z0-9]$/.test(domain)) {
    showFieldError('Please enter a valid domain name.');
    return;
  }

  addSubmitBtn.disabled = true;
  addSubmitBtn.classList.add('loading');

  try {
    const res  = await fetch(appUrl('api/watchlist-domain.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ domain }),
    });
    const data = await res.json();

    if (data.success) {
      closeAddModal();
      showToast(`${domain} added to your watchlist`);
      setTimeout(() => window.location.reload(), 900);
    } else if (data.requiresLogin) {
      window.location.href = appUrl('login.php');
    } else {
      showFieldError(data.message || 'Could not add domain.');
    }
  } catch (err) {
    showFieldError('Network error — please try again.');
  } finally {
    addSubmitBtn.classList.remove('loading');
    addSubmitBtn.disabled = false;
  }
}

function showFieldError(msg) {
  addError.textContent = msg;
  addError.classList.add('show');
  addInput.classList.add('error');
  addInput.focus();
}

// ── Remove / confirm modal ─────────────────────────────────
const confirmModal   = document.getElementById('confirmModal');
const confirmDomName = document.getElementById('confirmDomainName');
const confirmRemBtn  = document.getElementById('confirmRemoveBtn');
let   pendingRemove  = null;

function confirmRemove(domain, cardIndex) {
  // Close any open dropdown first
  if (openMenuId) {
    document.getElementById(openMenuId)?.classList.remove('show');
    openMenuId = null;
  }
  pendingRemove = { domain, cardIndex };
  confirmDomName.textContent = domain;
  confirmModal.classList.add('show');
}

function closeConfirmModal() {
  confirmModal.classList.remove('show');
  pendingRemove = null;
}

confirmModal.addEventListener('click', e => { if (e.target === confirmModal) closeConfirmModal(); });

confirmRemBtn.addEventListener('click', async () => {
  if (!pendingRemove) return;
  const { domain, cardIndex } = pendingRemove;
  confirmRemBtn.disabled = true;
  confirmRemBtn.innerHTML = '<i class="fas fa-sync-alt" style="animation:spin 0.8s linear infinite;"></i> Removing…';

  try {
    const res  = await fetch(appUrl('api/watchlist-domain.php'), {
      method:  'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ domain }),
    });

    let data;
    try { data = await res.json(); } catch { data = {}; }

    if (res.ok && (data.success !== false)) {
      closeConfirmModal();
      const card = document.getElementById(`card-${cardIndex}`);
      if (card) {
        card.classList.add('removing');
        setTimeout(() => {
          card.remove();
          // Update count display
          const remaining = document.querySelectorAll('.domain-card:not(.removing)').length;
          if (remaining === 0) window.location.reload();
        }, 300);
      }
      showToast(`${domain} removed from watchlist`);
    } else {
      closeConfirmModal();
      showToast(data.message || 'Could not remove domain.', true);
    }
  } catch {
    closeConfirmModal();
    showToast('Network error — please try again.', true);
  } finally {
    confirmRemBtn.disabled = false;
    confirmRemBtn.innerHTML = '<i class="fas fa-trash" style="font-size:11px;margin-right:5px;"></i> Remove';
  }
});

// ── Keyboard nav ──────────────────────────────────────────
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    closeAddModal();
    closeConfirmModal();
  }
});
</script>
</body>
</html>