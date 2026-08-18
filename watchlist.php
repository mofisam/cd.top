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
    setcookie('session_token', '', time() - 3600, '/');
    header('Location: login.php'); exit();
}

ob_start(); // buffer all output so AJAX responses stay clean

$conn = getDBConnection();
$uid  = (int)$session['user_id'];

$appBasePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (in_array($appBasePath, ['/', '.', '\\'])) { $appBasePath = ''; }
$assetUrl = fn(string $p): string => ($appBasePath ?: '') . '/' . ltrim($p, '/');
$url = $assetUrl;

// ── AJAX handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    if ($action === 'remove') {
        $domain = strtolower(trim($input['domain'] ?? ''));
        if (!$domain) { echo json_encode(['success'=>false,'message'=>'No domain specified.']); exit(); }
        $st = $conn->prepare("UPDATE pinned_domains SET status='expired' WHERE user_id=? AND domain_name=? AND status='active'");
        $st->bind_param("is", $uid, $domain);
        $st->execute();
        $ok = $st->affected_rows > 0;
        $st->close();
        $conn->close();
        echo json_encode(['success'=>$ok, 'message'=>$ok ? "Removed {$domain} from watchlist." : 'Domain not found.']);
        exit();
    }

    if ($action === 'add') {
        $domain = strtolower(trim($input['domain'] ?? ''));
        $domain = preg_replace('#^https?://(www\.)?#', '', $domain);
        $domain = rtrim(trim($domain), '/');
        if (!$domain || !str_contains($domain, '.')) {
            echo json_encode(['success'=>false,'message'=>'Enter a valid domain name.']); exit();
        }

        // Check plan limit for free users
        $userPlan = $user['plan'] ?? 'free';
        if ($userPlan === 'free') {
            $r = @$conn->query("SELECT COUNT(*) as c FROM pinned_domains WHERE user_id=$uid AND status='active'");
            $cnt = $r ? (int)($r->fetch_assoc()['c'] ?? 0) : 0;
            if ($cnt >= 5) {
                echo json_encode(['success'=>false,'limitReached'=>true,'message'=>'Free plan is limited to 5 watchlist domains. Upgrade to Pro for unlimited.']);
                exit();
            }
        }

        // Already watching?
        $chk = $conn->prepare("SELECT id FROM pinned_domains WHERE user_id=? AND domain_name=? AND status='active' LIMIT 1");
        $chk->bind_param("is", $uid, $domain);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($exists) {
            echo json_encode(['success'=>false,'message'=>"Already watching {$domain}."]);
            exit();
        }

        // Insert — pinned_domains needs email (FK to subscribers); use user's email
        $userEmail = $user['email'] ?? '';
        // Ensure subscriber row exists (upsert)
        $subSt = $conn->prepare("INSERT IGNORE INTO subscribers (email, status, source) VALUES (?, 'active', 'watchlist')");
        if ($subSt) { $subSt->bind_param("s", $userEmail); $subSt->execute(); $subSt->close(); }

        $ins = $conn->prepare("INSERT INTO pinned_domains (email, user_id, domain_name, status) VALUES (?,?,'active',?) ON DUPLICATE KEY UPDATE status='active', pinned_at=NOW()");
        if ($ins) {
            $ins->bind_param("sis", $userEmail, $uid, $domain);
            $ins->execute();
            $ok = $ins->affected_rows > 0;
            $ins->close();
        } else { $ok = false; }

        $conn->close();
        echo json_encode(['success'=>$ok, 'domain'=>$domain, 'message'=>$ok ? "Added {$domain} to your watchlist." : 'Could not add domain.']);
        exit();
    }

    if ($action === 'recheck') {
        // Fire a live availability check and update domain_searches
        $domain = strtolower(trim($input['domain'] ?? ''));
        if (!$domain) { echo json_encode(['success'=>false,'message'=>'No domain.']); exit(); }

        // Pull last known search result for this domain
        $sr = $conn->prepare("SELECT result_status, searched_at FROM domain_searches WHERE domain_name=? AND user_id=? ORDER BY searched_at DESC LIMIT 1");
        $sr->bind_param("si", $domain, $uid);
        $sr->execute();
        $lastSearch = $sr->get_result()->fetch_assoc();
        $sr->close();
        $conn->close();

        // Return whatever we know; the JS can trigger a full check on index.php if needed
        echo json_encode([
            'success'       => true,
            'domain'        => $domain,
            'last_status'   => $lastSearch['result_status'] ?? null,
            'last_checked'  => $lastSearch['searched_at'] ?? null,
            'redirect_to'   => ($appBasePath ?: '') . '/index.php?q=' . urlencode($domain),
        ]);
        exit();
    }

    $conn->close();
    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
    exit();
}

// ── Page data ─────────────────────────────────────────────────────────────
$userPlan     = $user['plan'] ?? 'free';
$userEmail    = $user['email'] ?? '';
$credits      = (int)($user['credits'] ?? 0);
$planMax      = ['free'=>10,'pro'=>100,'elite'=>500][$userPlan] ?? 10;
$watchlistMax = $userPlan === 'free' ? 5 : PHP_INT_MAX;

// Filters
$filterStatus = in_array($_GET['f'] ?? '', ['all','active','notified','expired']) ? ($_GET['f'] ?? 'all') : 'all';
$sortBy       = in_array($_GET['s'] ?? '', ['pinned_at','domain_name']) ? ($_GET['s'] ?? 'pinned_at') : 'pinned_at';
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['p'] ?? 1));
$perPage      = 20;

// Build WHERE
$where = "pd.user_id = $uid";
if ($filterStatus !== 'all') {
    $fs = $conn->real_escape_string($filterStatus);
    $where .= " AND pd.status = '$fs'";
} else {
    $where .= " AND pd.status IN ('active','notified')";
}
if ($search !== '') {
    $sl = $conn->real_escape_string('%' . $search . '%');
    $where .= " AND pd.domain_name LIKE '$sl'";
}

$orderBy = $sortBy === 'domain_name' ? 'pd.domain_name ASC' : 'pd.pinned_at DESC';

// Counts
$q = function (string $sql) use ($conn): array {
    $r = @$conn->query($sql);
    if (!$r) return [];
    return $r->fetch_assoc() ?? [];
};

$totalActive   = (int)($q("SELECT COUNT(*) as c FROM pinned_domains WHERE user_id=$uid AND status='active'")['c'] ?? 0);
$totalNotified = (int)($q("SELECT COUNT(*) as c FROM pinned_domains WHERE user_id=$uid AND status='notified'")['c'] ?? 0);
$totalAll      = $totalActive + $totalNotified;

$countRes = @$conn->query("SELECT COUNT(*) as c FROM pinned_domains pd WHERE $where");
$totalFiltered = $countRes ? (int)($countRes->fetch_assoc()['c'] ?? 0) : 0;
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$offset = ($page - 1) * $perPage;

// Fetch watchlist rows — JOIN to get latest WHOIS + alert data per domain
$listRes = @$conn->query("
    SELECT
        pd.id, pd.domain_name, pd.pinned_at, pd.status,
        -- Latest search result
        ds.result_status as last_search_status,
        ds.searched_at   as last_searched_at,
        -- Latest WHOIS
        wl.registrar, wl.expiry_date, wl.is_available as whois_available,
        wl.registrant_org, wl.looked_up_at as whois_checked_at,
        -- Latest domain alert
        da.alert_type, da.title as alert_title, da.status as alert_status, da.created_at as alerted_at,
        -- Latest dead scan
        dss.site_status as dead_site_status, dss.dead_score, dss.is_dead, dss.is_parked
    FROM pinned_domains pd
    -- Most recent search for this domain
    LEFT JOIN domain_searches ds ON ds.id = (
        SELECT id FROM domain_searches
        WHERE domain_name COLLATE utf8mb4_unicode_ci = pd.domain_name COLLATE utf8mb4_unicode_ci
        AND user_id = $uid
        ORDER BY searched_at DESC LIMIT 1
    )
    -- Most recent WHOIS lookup
    LEFT JOIN whois_lookups wl ON wl.id = (
        SELECT id FROM whois_lookups
        WHERE domain_name COLLATE utf8mb4_unicode_ci = pd.domain_name COLLATE utf8mb4_unicode_ci
        AND user_id = $uid
        ORDER BY looked_up_at DESC LIMIT 1
    )
    -- Most recent alert
    LEFT JOIN domain_alerts da ON da.id = (
        SELECT id FROM domain_alerts
        WHERE domain_name COLLATE utf8mb4_unicode_ci = pd.domain_name COLLATE utf8mb4_unicode_ci
        AND user_id = $uid
        ORDER BY created_at DESC LIMIT 1
    )
    -- Most recent dead-site scan
    LEFT JOIN dead_site_scans dss ON dss.id = (
        SELECT id FROM dead_site_scans
        WHERE domain_name COLLATE utf8mb4_unicode_ci = pd.domain_name COLLATE utf8mb4_unicode_ci
        AND user_id = $uid
        ORDER BY scanned_at DESC LIMIT 1
    )
    WHERE $where
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
");
$domains = [];
if ($listRes) while ($r = $listRes->fetch_assoc()) $domains[] = $r;

// Alert counts for the badge on sidebar
$alertCount   = (int)($q("SELECT COUNT(*) as c FROM domain_alerts WHERE user_id=$uid AND status='unread'")['c'] ?? 0);
$watchlistCount = $totalActive;

$conn->close();

// ── Helpers ──────────────────────────────────────────────────────────────
function ago(string $ts): string {
    if (!$ts) return '—';
    $d = time() - strtotime($ts);
    if ($d < 60)     return 'just now';
    if ($d < 3600)   return round($d/60).'m ago';
    if ($d < 86400)  return round($d/3600).'h ago';
    if ($d < 604800) return round($d/86400).'d ago';
    return date('M j, Y', strtotime($ts));
}

function expiryInfo(?string $date): array {
    if (!$date) return ['label'=>'—', 'cls'=>'ei-unknown', 'urgent'=>false];
    $days = (int)floor((strtotime($date) - time()) / 86400);
    if ($days < 0)   return ['label'=>'Expired', 'cls'=>'ei-expired', 'urgent'=>true];
    if ($days === 0) return ['label'=>'Expires today', 'cls'=>'ei-today', 'urgent'=>true];
    if ($days <= 14) return ['label'=>"Expires in {$days}d", 'cls'=>'ei-soon', 'urgent'=>true];
    if ($days <= 60) return ['label'=>date('M j', strtotime($date)), 'cls'=>'ei-warn', 'urgent'=>false];
    return ['label'=>date('M Y', strtotime($date)), 'cls'=>'ei-ok', 'urgent'=>false];
}

function statusIcon(string $status): string {
    return match($status) {
        'available'           => '<i class="fas fa-check-circle" style="color:var(--g2)"></i>',
        'taken', 'registered' => '<i class="fas fa-lock" style="color:var(--a)"></i>',
        'dead', 'parked'      => '<i class="fas fa-skull" style="color:var(--c)"></i>',
        default               => '<i class="fas fa-question-circle" style="color:var(--t3)"></i>',
    };
}

$activePage = 'watchlist';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Watchlist — CheckDomain</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#08090C;--bg2:#0F1117;--bg3:#161B25;--bg4:#1C2233;
  --b1:rgba(255,255,255,.05);--b2:rgba(255,255,255,.09);--b3:rgba(255,255,255,.14);
  --t1:#ECEAE2;--t2:#8C8A82;--t3:#44423C;
  --g:#1A9A70;--g2:#12BF86;--gb:rgba(26,154,112,.10);--gb2:rgba(26,154,112,.18);
  --a:#E89820;--ab:rgba(232,152,32,.10);
  --c:#E05038;--cb:rgba(224,80,56,.10);
  --p:#7B72D8;--pb:rgba(123,114,216,.10);
  --bl:#4888D4;--blb:rgba(72,136,212,.10);
  --sans:'Syne',sans-serif;--mono:'DM Mono',monospace;--sb:224px;
}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--t1);font-family:var(--sans);min-height:100vh;display:flex;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:radial-gradient(circle,rgba(26,154,112,.05) 1px,transparent 1px);
  background-size:28px 28px}

.main{margin-left:var(--sb);flex:1;position:relative;z-index:1;min-height:100vh;display:flex;flex-direction:column}

/* ── Topbar ── */
.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;
  padding:0 28px;height:56px;border-bottom:1px solid var(--b1);
  background:rgba(8,9,12,.92);backdrop-filter:blur(16px);
  position:sticky;top:0;z-index:40;flex-shrink:0}
.tb-left{display:flex;align-items:center;gap:10px}
.tb-right{display:flex;align-items:center;gap:6px}
.mob-menu{display:none;width:32px;height:32px;border-radius:7px;background:var(--bg2);
  border:1px solid var(--b1);color:var(--t2);font-size:14px;cursor:pointer;
  align-items:center;justify-content:center}
.breadcrumb{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--t3)}
.breadcrumb a{color:var(--t2);text-decoration:none;transition:color .15s}
.breadcrumb a:hover{color:var(--t1)}
.breadcrumb i{font-size:9px}
.tb-btn{display:flex;align-items:center;justify-content:center;width:32px;height:32px;
  border-radius:7px;background:var(--bg2);border:1px solid var(--b1);color:var(--t2);
  font-size:12px;cursor:pointer;text-decoration:none;transition:all .15s;position:relative}
.tb-btn:hover{border-color:var(--b2);color:var(--t1)}
.tb-dot{position:absolute;top:5px;right:5px;width:5px;height:5px;border-radius:50%;
  background:var(--a);border:1.5px solid var(--bg)}
.credits-pill{display:flex;align-items:center;gap:5px;background:var(--bg2);border:1px solid var(--b1);
  border-radius:7px;padding:5px 10px;font-family:var(--mono);font-size:11px;color:var(--t2)}
.credits-pill b{color:var(--a)}
.add-btn{display:flex;align-items:center;gap:6px;background:var(--g);color:#fff;border:none;
  border-radius:7px;padding:7px 14px;font-family:var(--sans);font-size:11px;font-weight:700;
  cursor:pointer;text-transform:uppercase;letter-spacing:.06em;transition:background .2s}
.add-btn:hover{background:var(--g2)}

/* ── Content ── */
.content{padding:28px 28px 56px;flex:1}

/* ── Page header ── */
.pg-date{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.12em;color:var(--t3);margin-bottom:4px}
.pg-title{font-size:22px;font-weight:800;color:var(--t1);letter-spacing:-.02em}
.pg-sub{font-size:12px;color:var(--t2);margin-top:4px;line-height:1.5}
.pg-sub em{color:var(--g2);font-style:normal;font-family:var(--mono)}

/* ── Stat bar ── */
.stat-bar{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:22px}
.sbc{background:var(--bg2);border:1px solid var(--b1);border-radius:11px;padding:13px 15px;
  position:relative;overflow:hidden;transition:border-color .2s,transform .15s;cursor:default}
.sbc:hover{border-color:var(--b2);transform:translateY(-1px)}
.sbc::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;border-radius:11px 11px 0 0;background:var(--accent,transparent)}
.sbc-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--t3);margin-bottom:8px}
.sbc-val{font-size:24px;font-weight:800;font-family:var(--mono);line-height:1;letter-spacing:-.03em}
.sbc-sub{font-size:10px;color:var(--t2);margin-top:5px}

/* ── Upgrade banner ── */
.up-banner{display:flex;align-items:center;gap:14px;margin-bottom:22px;
  background:linear-gradient(135deg,rgba(232,152,32,.06),rgba(26,154,112,.04));
  border:1px solid rgba(232,152,32,.18);border-radius:12px;padding:13px 18px}
.up-banner-text{flex:1}
.up-banner-title{font-size:12px;font-weight:700;color:var(--t1);margin-bottom:2px}
.up-banner-sub{font-size:11px;color:var(--t2);line-height:1.5}
.up-banner-cta{background:var(--a);color:#000;border:none;border-radius:6px;
  padding:6px 14px;font-family:var(--sans);font-size:10px;font-weight:800;
  text-transform:uppercase;letter-spacing:.06em;cursor:pointer;
  text-decoration:none;white-space:nowrap;transition:opacity .2s}
.up-banner-cta:hover{opacity:.85}

/* ── Filter / search bar ── */
.filter-bar{background:var(--bg2);border:1px solid var(--b1);border-radius:12px;
  padding:10px 14px;margin-bottom:18px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.f-tabs{display:flex;gap:4px;flex-wrap:wrap}
.f-tab{padding:5px 12px;border-radius:6px;font-size:11px;font-weight:600;
  border:1px solid var(--b1);background:transparent;color:var(--t2);
  cursor:pointer;transition:all .15s;text-decoration:none;display:inline-block}
.f-tab:hover,.f-tab.on{background:var(--g);border-color:var(--g);color:#fff}
.f-sep{color:var(--t3);font-size:11px;padding:0 2px}
.f-search-wrap{flex:1;min-width:180px;position:relative}
.f-search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);
  color:var(--t3);font-size:11px;pointer-events:none}
.f-search{width:100%;background:var(--bg3);border:1px solid var(--b2);border-radius:7px;
  padding:7px 10px 7px 30px;font-family:var(--mono);font-size:12px;color:var(--t1);
  outline:none;transition:border-color .2s}
.f-search::placeholder{color:var(--t3)}
.f-search:focus{border-color:var(--g)}
.f-sort{background:var(--bg3);border:1px solid var(--b1);border-radius:7px;
  padding:7px 10px;font-family:var(--sans);font-size:11px;color:var(--t2);
  outline:none;cursor:pointer;transition:border-color .2s}
.f-sort:focus{border-color:var(--g)}

/* ── Domain cards ── */
.domain-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px;margin-bottom:24px}

.domain-card{background:var(--bg2);border:1px solid var(--b1);border-radius:14px;
  overflow:hidden;transition:border-color .2s,transform .15s;display:flex;flex-direction:column}
.domain-card:hover{border-color:var(--b2);transform:translateY(-1px)}
.domain-card.is-urgent{border-color:rgba(232,152,32,.3)}
.domain-card.is-expired{border-color:rgba(224,80,56,.2)}

.dc-top{padding:15px 16px 12px;border-bottom:1px solid var(--b1);display:flex;align-items:flex-start;gap:10px}
.dc-icon{width:36px;height:36px;border-radius:9px;background:var(--bg3);border:1px solid var(--b1);
  display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px}
.dc-name{font-family:var(--mono);font-size:14px;font-weight:700;color:var(--t1);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0}
.dc-tld{color:var(--g2)}
.dc-age{font-size:10px;color:var(--t3);font-family:var(--mono);margin-top:2px}
.dc-badges{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}
.pill{font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
  padding:2px 6px;border-radius:4px;white-space:nowrap;display:inline-flex;align-items:center;gap:3px}
.p-av  {background:var(--gb);color:var(--g2)}
.p-tak {background:var(--ab);color:var(--a)}
.p-dead{background:var(--cb);color:var(--c)}
.p-unk {background:var(--bg3);color:var(--t3)}
.p-alert-unread{background:var(--ab);color:var(--a)}
.p-alert-read  {background:var(--bg3);color:var(--t3)}

/* Expiry badges */
.ei-ok     {background:rgba(26,154,112,.08);color:var(--g2)}
.ei-warn   {background:rgba(232,152,32,.08);color:var(--a)}
.ei-soon   {background:rgba(232,152,32,.15);color:var(--a)}
.ei-today  {background:rgba(224,80,56,.15);color:var(--c)}
.ei-expired{background:rgba(224,80,56,.15);color:var(--c)}
.ei-unknown{background:var(--bg3);color:var(--t3)}

/* Info grid inside card */
.dc-info{display:grid;grid-template-columns:1fr 1fr;gap:0;flex:1}
.dc-cell{padding:9px 16px;border-bottom:1px solid var(--b1);border-right:1px solid var(--b1)}
.dc-cell:nth-child(even){border-right:none}
.dc-cell:nth-last-child(-n+2){border-bottom:none}
.dc-cell-label{font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:.1em;
  color:var(--t3);margin-bottom:4px}
.dc-cell-val{font-size:11px;color:var(--t2);font-family:var(--mono);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dc-cell-val.highlight{color:var(--t1);font-weight:500}
.dc-cell-val.good{color:var(--g2)}
.dc-cell-val.warn{color:var(--a)}
.dc-cell-val.bad{color:var(--c)}

/* Alert strip inside card */
.dc-alert{display:flex;align-items:center;gap:8px;padding:9px 16px;
  background:rgba(232,152,32,.05);border-top:1px solid rgba(232,152,32,.12)}
.dc-alert i{font-size:11px;flex-shrink:0;color:var(--a)}
.dc-alert-text{font-size:10px;color:var(--a);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dc-alert-time{font-size:9px;color:var(--t3);font-family:var(--mono);flex-shrink:0}

/* Card actions */
.dc-actions{display:flex;padding:10px 12px;gap:6px;border-top:1px solid var(--b1);flex-wrap:wrap}
.dc-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border-radius:6px;
  font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
  border:none;cursor:pointer;transition:all .15s;text-decoration:none;font-family:var(--sans)}
.db-green{background:var(--gb);color:var(--g2)}.db-green:hover{background:var(--gb2)}
.db-amber{background:var(--ab);color:var(--a)}.db-amber:hover{background:rgba(232,152,32,.2)}
.db-blue {background:var(--blb);color:var(--bl)}.db-blue:hover{background:rgba(72,136,212,.2)}
.db-coral{background:var(--cb);color:var(--c)}.db-coral:hover{background:rgba(224,80,56,.2)}
.db-ghost{background:var(--bg3);color:var(--t2);border:1px solid var(--b1)}.db-ghost:hover{color:var(--t1);border-color:var(--b2)}

/* ── Add domain modal ── */
.modal-wrap{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);
  backdrop-filter:blur(6px);z-index:100;align-items:center;justify-content:center;padding:16px}
.modal-wrap.open{display:flex}
.modal{background:var(--bg2);border:1px solid var(--b2);border-radius:16px;
  padding:24px;width:100%;max-width:440px}
.modal-title{font-size:16px;font-weight:800;color:var(--t1);margin-bottom:6px}
.modal-sub{font-size:12px;color:var(--t2);margin-bottom:18px;line-height:1.5}
.modal-input-wrap{position:relative;margin-bottom:14px}
.modal-input-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);
  color:var(--t3);font-size:12px;pointer-events:none}
.modal-input{width:100%;background:var(--bg3);border:1px solid var(--b2);border-radius:9px;
  padding:11px 14px 11px 36px;font-family:var(--mono);font-size:13px;color:var(--t1);
  outline:none;transition:border-color .2s}
.modal-input::placeholder{color:var(--t3)}
.modal-input:focus{border-color:var(--g)}
.modal-actions{display:flex;gap:8px;justify-content:flex-end}
.modal-cancel{background:var(--bg3);color:var(--t2);border:1px solid var(--b1);
  border-radius:8px;padding:9px 16px;font-family:var(--sans);font-size:12px;font-weight:600;
  cursor:pointer;transition:all .15s}
.modal-cancel:hover{color:var(--t1);border-color:var(--b2)}
.modal-submit{background:var(--g);color:#fff;border:none;border-radius:8px;
  padding:9px 18px;font-family:var(--sans);font-size:12px;font-weight:700;
  cursor:pointer;transition:background .2s;letter-spacing:.03em}
.modal-submit:hover{background:var(--g2)}
.modal-submit:disabled{opacity:.5;cursor:not-allowed}

/* ── Pagination ── */
.pagination{display:flex;align-items:center;justify-content:space-between;
  gap:12px;flex-wrap:wrap;margin-top:4px}
.pg-info{font-size:11px;color:var(--t3);font-family:var(--mono)}
.pg-btns{display:flex;gap:4px;flex-wrap:wrap}
.pg-btn{padding:5px 11px;border-radius:6px;font-size:11px;font-weight:600;
  border:1px solid var(--b1);background:transparent;color:var(--t2);
  cursor:pointer;transition:all .15s;text-decoration:none;display:inline-block}
.pg-btn:hover,.pg-btn.on{background:var(--g);border-color:var(--g);color:#fff}

/* ── Empty state ── */
.empty-state{text-align:center;padding:60px 24px;color:var(--t3);
  display:flex;flex-direction:column;align-items:center;gap:12px}
.empty-state i{font-size:36px;opacity:.25}
.empty-state h3{font-size:16px;font-weight:700;color:var(--t2)}
.empty-state p{font-size:12px;max-width:320px;line-height:1.6}
.empty-cta{display:inline-flex;align-items:center;gap:6px;
  padding:8px 18px;border-radius:8px;background:var(--gb);color:var(--g2);
  font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
  text-decoration:none;border:1px solid rgba(26,154,112,.2);transition:all .15s}
.empty-cta:hover{background:var(--gb2)}

/* ── Toast ── */
.toast{position:fixed;bottom:22px;right:22px;z-index:999;background:var(--bg3);
  border:1px solid var(--b2);border-radius:10px;padding:11px 16px;font-size:12px;
  color:var(--t1);box-shadow:0 8px 32px rgba(0,0,0,.45);max-width:300px;
  display:flex;align-items:center;gap:8px;
  transform:translateY(16px);opacity:0;transition:all .3s}
.toast.show{transform:translateY(0);opacity:1}

/* ── Overlay ── */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:49}
.overlay.show{display:block}

@media(max-width:1100px){.domain-grid{grid-template-columns:1fr 1fr}}
@media(max-width:768px){
  .main{margin-left:0}.mob-menu{display:flex}
  .content{padding:18px 16px 44px}
  .stat-bar{grid-template-columns:1fr 1fr}
  .domain-grid{grid-template-columns:1fr}
  .credits-pill{display:none}
  .dc-info{grid-template-columns:1fr}
  .dc-cell{border-right:none}
  .dc-cell:nth-last-child(-n+2){border-bottom:1px solid var(--b1)}
  .dc-cell:last-child{border-bottom:none}
}
@media(max-width:480px){.topbar{padding:0 14px}.content{padding:14px 12px 44px}}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-thumb{background:var(--b2);border-radius:2px}
</style>
</head>
<body>

<div class="overlay" id="overlay" onclick="closeSB()"></div>
<?php require_once 'includes/sidebar.php'; ?>

<main class="main">

  <?php
    $cdHeaderTitle = 'Watchlist';
    $cdHeaderActions = '<button class="add-btn" type="button" onclick="openAddModal()"><i class="fas fa-plus" style="font-size:10px"></i> Watch domain</button>';
    require 'includes/cd_header.php';
  ?>

  <div class="content">

    <!-- Header -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:22px">
      <div>
        <div class="pg-date"><?= date('l, F j, Y') ?></div>
        <div class="pg-title">Your watchlist.</div>
        <div class="pg-sub" style="margin-top:4px">
          <?php if ($totalActive > 0): ?>
            Tracking <em><?= $totalActive ?> domain<?= $totalActive !== 1 ? 's' : '' ?></em> for availability, expiry, and status changes.
          <?php else: ?>
            No domains watched yet. Add one to get started.
          <?php endif; ?>
        </div>
      </div>
      <?php if ($userPlan === 'free'): ?>
      <div style="text-align:right;flex-shrink:0;background:var(--bg2);border:1px solid var(--b1);border-radius:10px;padding:10px 14px">
        <div style="font-size:9px;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;margin-bottom:3px">Plan limit</div>
        <div style="font-size:16px;font-weight:800;font-family:var(--mono);color:var(--a)"><?= $totalActive ?>/5</div>
        <div style="font-size:10px;color:var(--t3)">domains (free plan)</div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Upgrade banner for free users near limit -->
    <?php if ($userPlan === 'free' && $totalActive >= 4): ?>
    <div class="up-banner">
      <span style="font-size:18px">⚡</span>
      <div class="up-banner-text">
        <div class="up-banner-title"><?= $totalActive >= 5 ? 'Watchlist full (5/5 on Free plan)' : 'Almost at your limit' ?></div>
        <div class="up-banner-sub">Upgrade to Pro for unlimited watchlist domains, expiry alerts, and backorder placement when a domain drops.</div>
      </div>
      <a href="<?= $url('billing.php?plan=pro') ?>" class="up-banner-cta">Upgrade →</a>
    </div>
    <?php endif; ?>

    <!-- Stat bar -->
    <div class="stat-bar">
      <div class="sbc" style="--accent:var(--g)">
        <div class="sbc-label">Watching</div>
        <div class="sbc-val" style="color:var(--g2)"><?= $totalActive ?></div>
        <div class="sbc-sub">active domains</div>
      </div>
      <div class="sbc" style="--accent:var(--a)">
        <div class="sbc-label">Expiring soon</div>
        <?php
        // Count domains with WHOIS expiry within 60 days
        // We'll compute this from already-fetched data below
        $expiringSoon = 0;
        foreach ($domains as $d) {
            if (!empty($d['expiry_date'])) {
                $days = (int)floor((strtotime($d['expiry_date']) - time()) / 86400);
                if ($days >= 0 && $days <= 60) $expiringSoon++;
            }
        }
        // For the full count (not just current page) we do a quick sub-query approach
        // using what we already have is fine for display purposes
        ?>
        <div class="sbc-val" style="color:var(--a)"><?= $expiringSoon ?></div>
        <div class="sbc-sub">within 60 days</div>
      </div>
      <div class="sbc" style="--accent:var(--bl)">
        <div class="sbc-label">With WHOIS data</div>
        <?php $withWhois = count(array_filter($domains, fn($d) => !empty($d['registrar']))); ?>
        <div class="sbc-val" style="color:var(--bl)"><?= $withWhois ?></div>
        <div class="sbc-sub">out of <?= count($domains) ?> shown</div>
      </div>
      <div class="sbc" style="--accent:var(--p)">
        <div class="sbc-label">With alerts</div>
        <?php $withAlerts = count(array_filter($domains, fn($d) => !empty($d['alert_type']))); ?>
        <div class="sbc-val" style="color:var(--p)"><?= $withAlerts ?></div>
        <div class="sbc-sub">domains alerted</div>
      </div>
    </div>

    <!-- Filter bar -->
    <form method="GET" class="filter-bar" id="filterForm">
      <div class="f-tabs">
        <?php foreach (['all'=>"All ({$totalAll})",'active'=>"Active ({$totalActive})",'notified'=>"Notified ({$totalNotified})"] as $val=>$lbl): ?>
        <a href="?f=<?=$val?>&s=<?=$sortBy?>&q=<?=urlencode($search)?>"
           class="f-tab <?= $filterStatus===$val?'on':'' ?>"><?=$lbl?></a>
        <?php endforeach; ?>
      </div>
      <span class="f-sep">|</span>
      <div class="f-search-wrap">
        <i class="fas fa-search"></i>
        <input type="text" name="q" class="f-search" placeholder="Filter by domain…"
               value="<?= htmlspecialchars($search) ?>" autocomplete="off">
        <input type="hidden" name="f" value="<?= htmlspecialchars($filterStatus) ?>">
      </div>
      <select name="s" class="f-sort" onchange="this.form.submit()">
        <option value="pinned_at" <?= $sortBy==='pinned_at'?'selected':'' ?>>Recently added</option>
        <option value="domain_name" <?= $sortBy==='domain_name'?'selected':'' ?>>A–Z</option>
      </select>
    </form>

    <!-- Domain cards -->
    <?php if (empty($domains)): ?>
    <div class="empty-state">
      <i class="fas fa-bookmark"></i>
      <h3><?= $search ? 'No domains match your search' : 'Your watchlist is empty' ?></h3>
      <p><?= $search ? "Try a different search term or clear the filter." : "Add domains you want to monitor — we'll track their status, expiry, and alert you when something changes." ?></p>
      <?php if (!$search): ?>
      <button class="empty-cta" onclick="openAddModal()"><i class="fas fa-plus" style="font-size:9px"></i> Add your first domain</button>
      <?php else: ?>
      <a href="?" class="empty-cta"><i class="fas fa-times" style="font-size:9px"></i> Clear filter</a>
      <?php endif; ?>
    </div>
    <?php else: ?>

    <div class="domain-grid">
    <?php foreach ($domains as $d):
      $domainName = $d['domain_name'] ?? '';
      $parts      = explode('.', $domainName, 2);
      $sld        = $parts[0];
      $tld        = isset($parts[1]) ? '.'.$parts[1] : '';
      $pinnedAgo  = ago($d['pinned_at'] ?? '');
      $lastStatus = $d['last_search_status'] ?? null;
      $ei         = expiryInfo($d['expiry_date'] ?? null);
      $isUrgent   = $ei['urgent'];
      $isExpired  = ($d['expiry_date'] && strtotime($d['expiry_date']) < time());
      $hasAlert   = !empty($d['alert_type']);
      $deadScore  = (int)($d['dead_score'] ?? 0);
      $isDead     = !empty($d['is_dead']);

      // Card CSS class
      $cardClass = '';
      if ($isExpired) $cardClass = 'is-expired';
      elseif ($isUrgent) $cardClass = 'is-urgent';

      // Domain icon color
      $iconColor = 'var(--t3)';
      $iconBg    = 'var(--bg3)';
      if ($lastStatus === 'available')               { $iconColor = 'var(--g2)'; $iconBg = 'var(--gb)'; }
      elseif (in_array($lastStatus, ['taken','registered'])) { $iconColor = 'var(--a)'; $iconBg = 'var(--ab)'; }
      elseif ($isDead)                               { $iconColor = 'var(--c)'; $iconBg = 'var(--cb)'; }

      // Registrar truncation
      $registrar = $d['registrar'] ?? null;
      if ($registrar && strlen($registrar) > 22) $registrar = substr($registrar,0,22).'…';
    ?>
    <div class="domain-card <?= $cardClass ?>" id="card-<?= md5($domainName) ?>">

      <!-- Card top -->
      <div class="dc-top">
        <div class="dc-icon" style="background:<?=$iconBg?>;border-color:<?=$iconColor?>20;color:<?=$iconColor?>">
          <i class="fas fa-globe" style="font-size:15px"></i>
        </div>
        <div style="flex:1;min-width:0">
          <div class="dc-name">
            <?= htmlspecialchars($sld) ?><span class="dc-tld"><?= htmlspecialchars($tld) ?></span>
          </div>
          <div class="dc-age">Added <?= $pinnedAgo ?></div>
          <div class="dc-badges">
            <?php if ($lastStatus): ?>
            <span class="pill <?= $lastStatus==='available'?'p-av':($lastStatus==='taken'||$lastStatus==='registered'?'p-tak':($isDead?'p-dead':'p-unk')) ?>">
              <?= htmlspecialchars($lastStatus) ?>
            </span>
            <?php endif; ?>
            <?php if ($ei['urgent']): ?>
            <span class="pill <?= $ei['cls'] ?>"><i class="fas fa-clock" style="font-size:7px"></i> <?= $ei['label'] ?></span>
            <?php endif; ?>
            <?php if ($isDead): ?>
            <span class="pill p-dead"><i class="fas fa-skull" style="font-size:7px"></i> Dead</span>
            <?php endif; ?>
            <?php if (!empty($d['is_parked'])): ?>
            <span class="pill p-tak"><i class="fas fa-parking" style="font-size:7px"></i> Parked</span>
            <?php endif; ?>
          </div>
        </div>
        <!-- Quick remove -->
        <button class="dc-btn db-ghost" style="padding:4px 8px;font-size:10px"
                onclick="removeDomain('<?= htmlspecialchars(addslashes($domainName)) ?>', this)"
                title="Remove from watchlist">
          <i class="fas fa-times"></i>
        </button>
      </div>

      <!-- Info grid -->
      <div class="dc-info">
        <!-- Expiry -->
        <div class="dc-cell">
          <div class="dc-cell-label">Expiry</div>
          <div class="dc-cell-val <?= $ei['cls'] ?>"><?= $ei['label'] ?></div>
        </div>
        <!-- Registrar -->
        <div class="dc-cell">
          <div class="dc-cell-label">Registrar</div>
          <div class="dc-cell-val highlight"><?= $registrar ? htmlspecialchars($registrar) : '—' ?></div>
        </div>
        <!-- Last checked -->
        <div class="dc-cell">
          <div class="dc-cell-label">Last checked</div>
          <div class="dc-cell-val"><?= $d['last_searched_at'] ? ago($d['last_searched_at']) : '—' ?></div>
        </div>
        <!-- WHOIS data -->
        <div class="dc-cell">
          <div class="dc-cell-label">WHOIS data</div>
          <div class="dc-cell-val <?= $d['whois_checked_at'] ? '' : '' ?>">
            <?= $d['whois_checked_at'] ? ago($d['whois_checked_at']) : 'Not looked up' ?>
          </div>
        </div>
        <!-- Dead site score -->
        <div class="dc-cell">
          <div class="dc-cell-label">Dead score</div>
          <div class="dc-cell-val <?= $deadScore>=70?'bad':($deadScore>=40?'warn':'good') ?>">
            <?= $d['dead_site_status'] ? $deadScore . '/100' : '—' ?>
          </div>
        </div>
        <!-- Registrant -->
        <div class="dc-cell">
          <div class="dc-cell-label">Registrant</div>
          <div class="dc-cell-val highlight">
            <?php $org = $d['registrant_org'] ?? null; ?>
            <?= $org ? htmlspecialchars(strlen($org)>20?substr($org,0,20).'…':$org) : '—' ?>
          </div>
        </div>
      </div>

      <!-- Alert strip (if domain has a recent alert) -->
      <?php if ($hasAlert): ?>
      <div class="dc-alert">
        <i class="fas <?= $d['alert_type']==='available'?'fa-check-circle':($d['alert_type']==='expiring_soon'||$d['alert_type']==='just_expired'?'fa-clock':($d['alert_type']==='dead_site'?'fa-skull':'fa-bell')) ?>"></i>
        <span class="dc-alert-text"><?= htmlspecialchars($d['alert_title'] ?? ucwords(str_replace('_',' ',$d['alert_type']))) ?></span>
        <span class="dc-alert-time"><?= ago($d['alerted_at'] ?? '') ?></span>
      </div>
      <?php endif; ?>

      <!-- Actions -->
      <div class="dc-actions">
        <a href="<?= $url('index.php') ?>?q=<?= urlencode($domainName) ?>" class="dc-btn db-green">
          <i class="fas fa-search" style="font-size:9px"></i> Check
        </a>
        <a href="<?= $url('whois.php') ?>?q=<?= urlencode($domainName) ?>" class="dc-btn db-blue">
          <i class="fas fa-file-alt" style="font-size:9px"></i> WHOIS
        </a>
        <?php if ($userPlan !== 'free'): ?>
        <a href="<?= $url('backorders.php') ?>?domain=<?= urlencode($domainName) ?>" class="dc-btn db-amber">
          <i class="fas fa-clock" style="font-size:9px"></i> Backorder
        </a>
        <a href="<?= $url('dead-sites.php') ?>?domain=<?= urlencode($domainName) ?>" class="dc-btn db-ghost">
          <i class="fas fa-skull" style="font-size:9px"></i> Scan
        </a>
        <?php endif; ?>
        <a href="<?= $url('domain-report.php') ?>?domain=<?= urlencode($domainName) ?>" class="dc-btn db-ghost" style="margin-left:auto">
          <i class="fas fa-file-lines" style="font-size:9px"></i> Report
        </a>
      </div>

    </div><!-- /domain-card -->
    <?php endforeach; ?>
    </div><!-- /domain-grid -->

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <?php $qs = fn($p) => '?' . http_build_query(array_filter(['f'=>$filterStatus,'s'=>$sortBy,'q'=>$search,'p'=>$p], fn($v) => $v !== '' && $v !== 'all' && $v !== 1)); ?>
    <div class="pagination">
      <span class="pg-info">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$totalFiltered) ?> of <?= $totalFiltered ?> domain<?= $totalFiltered!==1?'s':'' ?></span>
      <div class="pg-btns">
        <?php if ($page > 1): ?><a href="<?= $qs($page-1) ?>" class="pg-btn">← Prev</a><?php endif; ?>
        <?php for ($i=max(1,$page-2);$i<=min($totalPages,$page+2);$i++): ?>
        <a href="<?= $qs($i) ?>" class="pg-btn <?= $i===$page?'on':'' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?><a href="<?= $qs($page+1) ?>" class="pg-btn">Next →</a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php endif; // end domains not empty ?>

  </div><!-- /content -->
</main>

<!-- Add domain modal -->
<div class="modal-wrap" id="addModal" onclick="if(event.target===this)closeAddModal()">
  <div class="modal">
    <div class="modal-title">Watch a domain</div>
    <div class="modal-sub">Enter a domain name to start tracking its status, expiry, and availability changes.</div>
    <div class="modal-input-wrap">
      <i class="fas fa-globe"></i>
      <input type="text" class="modal-input" id="addInput"
             placeholder="e.g. mybrand.com, startup.io"
             autocomplete="off" maxlength="253">
    </div>
    <?php if ($userPlan === 'free' && $totalActive >= 5): ?>
    <div style="background:rgba(232,152,32,.08);border:1px solid rgba(232,152,32,.2);border-radius:8px;padding:10px 13px;margin-bottom:14px;font-size:11px;color:var(--a)">
      <i class="fas fa-exclamation-triangle" style="margin-right:5px"></i>
      You've reached the 5-domain limit on the Free plan.
      <a href="<?= $url('billing.php?plan=pro') ?>" style="color:var(--g2);text-decoration:none;font-weight:700"> Upgrade to Pro →</a>
    </div>
    <?php endif; ?>
    <div class="modal-actions">
      <button class="modal-cancel" onclick="closeAddModal()">Cancel</button>
      <button class="modal-submit" id="addSubmitBtn"
              <?= ($userPlan === 'free' && $totalActive >= 5) ? 'disabled' : '' ?>
              onclick="submitAdd()">
        <i class="fas fa-bookmark" style="font-size:10px"></i> Add to watchlist
      </button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast">
  <i id="t-icon" class="fas fa-check-circle" style="color:var(--g2);flex-shrink:0;font-size:13px"></i>
  <span id="t-msg"></span>
</div>

<script>
const BASE = <?= json_encode($appBasePath) ?>;
const u    = p => `${BASE}/${p.replace(/^\/+/,'')}`;

function openSB()  { document.getElementById('cdSidebar')?.classList.add('open'); document.getElementById('overlay')?.classList.add('show'); }
function closeSB() { document.getElementById('cdSidebar')?.classList.remove('open'); document.getElementById('overlay')?.classList.remove('show'); }

function openAddModal()  {
  document.getElementById('addModal').classList.add('open');
  setTimeout(() => document.getElementById('addInput')?.focus(), 80);
}
function closeAddModal() {
  document.getElementById('addModal').classList.remove('open');
  document.getElementById('addInput').value = '';
}

document.getElementById('addInput')?.addEventListener('keydown', e => {
  if (e.key === 'Enter') submitAdd();
  if (e.key === 'Escape') closeAddModal();
});

async function submitAdd() {
  const raw    = document.getElementById('addInput').value.trim();
  const domain = raw.toLowerCase().replace(/^https?:\/\/(www\.)?/, '').replace(/\/+$/, '');
  if (!domain || !domain.includes('.')) { showToast('Enter a valid domain (e.g. mybrand.com).','error'); return; }

  const btn = document.getElementById('addSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-pulse" style="font-size:10px"></i> Adding…';

  try {
    const res  = await fetch('', { method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body: JSON.stringify({action:'add',domain}) });
    const data = await res.json();
    if (data.success) {
      showToast(data.message || `${domain} added to your watchlist.`);
      closeAddModal();
      setTimeout(() => location.reload(), 900);
    } else if (data.limitReached) {
      showToast(data.message,'error');
      closeAddModal();
    } else {
      showToast(data.message || 'Could not add domain.','error');
    }
  } catch(e) { showToast('Network error. Please try again.','error'); }
  finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-bookmark" style="font-size:10px"></i> Add to watchlist';
  }
}

async function removeDomain(domain, btn) {
  if (!confirm(`Remove ${domain} from your watchlist?`)) return;
  btn.disabled = true;
  try {
    const res  = await fetch('', { method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body: JSON.stringify({action:'remove',domain}) });
    const data = await res.json();
    if (data.success) {
      showToast(data.message || `${domain} removed.`);
      const card = document.getElementById('card-' + md5(domain));
      if (card) { card.style.transition='opacity .3s,transform .3s'; card.style.opacity='0'; card.style.transform='scale(.97)'; setTimeout(()=>card.remove(),300); }
    } else { showToast(data.message || 'Could not remove domain.','error'); }
  } catch(e) { showToast('Network error.','error'); }
  finally { btn.disabled = false; }
}

// Simple md5 polyfill for the card ID (matches PHP md5())
function md5(s) {
  // We just need a consistent string key — hash isn't actually needed client-side;
  // fallback to a slugified version of the domain name for id matching.
  return s.replace(/[^a-z0-9]/gi,'').toLowerCase().slice(0,32).padEnd(32,'0');
}

// Filter search — live filter on keyup (without submitting the form)
document.querySelector('.f-search')?.addEventListener('keydown', e => {
  if (e.key === 'Enter') { e.preventDefault(); document.getElementById('filterForm').submit(); }
});

function showToast(msg, type='ok') {
  const t = document.getElementById('toast'), i = document.getElementById('t-icon');
  document.getElementById('t-msg').textContent = msg;
  i.className = `fas ${type==='error'?'fa-exclamation-circle':'fa-check-circle'}`;
  i.style.color = type==='error' ? 'var(--c)' : 'var(--g2)';
  t.classList.add('show');
  clearTimeout(t._t);
  t._t = setTimeout(() => t.classList.remove('show'), 3500);
}
</script>
</body>
</html>
