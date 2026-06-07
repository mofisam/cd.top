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

// ── Ensure alerts table exists ─────────────────────────────
$conn = getDBConnection();
$conn->query("
    CREATE TABLE IF NOT EXISTS domain_alerts (
        id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        user_id         INT  NOT NULL,
        domain_name     VARCHAR(253)     NOT NULL,
        alert_type      ENUM(
                            'available',
                            'expiring_soon',
                            'just_expired',
                            'dead_site',
                            'backorder_won',
                            'backorder_lost',
                            'price_drop',
                            'whois_updated'
                        ) NOT NULL,
        status          ENUM('unread','read','dismissed','actioned') NOT NULL DEFAULT 'unread',
        priority        ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
        title           VARCHAR(255)     NOT NULL,
        message         TEXT             NULL,
        expires_in_days SMALLINT         NULL COMMENT 'For expiring_soon alerts',
        action_url      VARCHAR(512)     NULL COMMENT 'Deep-link for CTA',
        action_label    VARCHAR(64)      NULL COMMENT 'CTA button text',
        read_at         TIMESTAMP        NULL,
        created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_alert_user        (user_id, status),
        INDEX idx_alert_domain      (domain_name),
        INDEX idx_alert_type        (alert_type),
        INDEX idx_alert_created     (created_at),
        PRIMARY KEY (id),
        CONSTRAINT fk_alert_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Fetch user ─────────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, email, full_name, plan, credits FROM users WHERE id = ?");
$stmt->bind_param("i", $session['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { header('Location: logout.php'); exit(); }

// ── Handle AJAX actions (mark read, dismiss, clear) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    if ($action === 'mark_read') {
        $id = (int)($input['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE domain_alerts SET status='read', read_at=NOW() WHERE id=? AND user_id=?");
        $stmt->bind_param("ii", $id, $session['user_id']);
        $stmt->execute();
        $stmt->close();
        ob_end_clean(); echo json_encode(['success'=>true]); exit();
    }
    if ($action === 'mark_all_read') {
        $stmt = $conn->prepare("UPDATE domain_alerts SET status='read', read_at=NOW() WHERE user_id=? AND status='unread'");
        $stmt->bind_param("i", $session['user_id']);
        $stmt->execute();
        $count = $stmt->affected_rows;
        $stmt->close();
        ob_end_clean(); echo json_encode(['success'=>true,'count'=>$count]); exit();
    }
    if ($action === 'dismiss') {
        $id = (int)($input['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE domain_alerts SET status='dismissed' WHERE id=? AND user_id=?");
        $stmt->bind_param("ii", $id, $session['user_id']);
        $stmt->execute();
        $stmt->close();
        ob_end_clean(); echo json_encode(['success'=>true]); exit();
    }
    if ($action === 'dismiss_all') {
        $stmt = $conn->prepare("UPDATE domain_alerts SET status='dismissed' WHERE user_id=? AND status IN ('unread','read')");
        $stmt->bind_param("i", $session['user_id']);
        $stmt->execute();
        $stmt->close();
        ob_end_clean(); echo json_encode(['success'=>true]); exit();
    }
    ob_end_clean(); echo json_encode(['success'=>false,'message'=>'Unknown action']); exit();
}

// ── Fetch alerts ───────────────────────────────────────────
$filter = in_array($_GET['filter'] ?? '', ['unread','read','dismissed','all']) ? $_GET['filter'] : 'all';
$typeFilter = $_GET['type'] ?? 'all';

$whereClause = "WHERE a.user_id = ?";
$params      = [$session['user_id']];
$types       = "i";

if ($filter === 'unread') { $whereClause .= " AND a.status = 'unread'"; }
elseif ($filter === 'read') { $whereClause .= " AND a.status = 'read'"; }
elseif ($filter === 'dismissed') { $whereClause .= " AND a.status = 'dismissed'"; }
else { $whereClause .= " AND a.status != 'dismissed'"; }

$validTypes = ['available','expiring_soon','just_expired','dead_site','backorder_won','backorder_lost','price_drop','whois_updated'];
if ($typeFilter !== 'all' && in_array($typeFilter, $validTypes)) {
    $whereClause .= " AND a.alert_type = ?";
    $params[] = $typeFilter;
    $types   .= "s";
}

$alertsStmt = $conn->prepare("
    SELECT a.*
    FROM domain_alerts a
    {$whereClause}
    ORDER BY
        CASE a.priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END,
        a.created_at DESC
    LIMIT 60
");
$alertsStmt->bind_param($types, ...$params);
$alertsStmt->execute();
$alertsResult = $alertsStmt->get_result();
$alerts = [];
while ($row = $alertsResult->fetch_assoc()) { $alerts[] = $row; }
$alertsStmt->close();

// ── Stats ──────────────────────────────────────────────────
$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) as total,
        SUM(status = 'unread') as unread,
        SUM(alert_type = 'available') as available,
        SUM(alert_type IN ('expiring_soon','just_expired')) as expiring,
        SUM(alert_type = 'dead_site') as dead,
        SUM(alert_type IN ('backorder_won','backorder_lost')) as backorders
    FROM domain_alerts
    WHERE user_id = ? AND status != 'dismissed'
");
$statsStmt->bind_param("i", $session['user_id']);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

// ── Watchlist count for sidebar ────────────────────────────
$watchStmt = $conn->prepare("SELECT COUNT(*) as c FROM pinned_domains WHERE user_id=? AND status='active'");
$watchStmt->bind_param("i", $session['user_id']);
$watchStmt->execute();
$watchlistCount = (int)$watchStmt->get_result()->fetch_assoc()['c'];
$watchStmt->close();
$conn->close();

// ── User meta ──────────────────────────────────────────────
$userName  = trim($user['full_name'] ?? '') ?: explode('@', $user['email'])[0];
$firstName = explode(' ', $userName)[0];
$initials  = strtoupper(substr($userName,0,1).(strpos($userName,' ')!==false?substr($userName,strpos($userName,' ')+1,1):''));
$userPlan  = $user['plan']    ?? 'free';
$credits   = $user['credits'] ?? 10;
$alertCount = (int)($stats['unread'] ?? 0);

$activePage = 'alerts';

// ── Alert display helpers ──────────────────────────────────
$alertMeta = [
    'available'       => ['icon'=>'fa-check-circle',  'color'=>'--green2',  'bg'=>'--green-bg',  'label'=>'Available'],
    'expiring_soon'   => ['icon'=>'fa-clock',          'color'=>'--amber',   'bg'=>'--amber-bg',  'label'=>'Expiring soon'],
    'just_expired'    => ['icon'=>'fa-hourglass-end',  'color'=>'--coral',   'bg'=>'--coral-bg',  'label'=>'Just expired'],
    'dead_site'       => ['icon'=>'fa-skull',          'color'=>'--coral',   'bg'=>'--coral-bg',  'label'=>'Dead site'],
    'backorder_won'   => ['icon'=>'fa-trophy',         'color'=>'--amber',   'bg'=>'--amber-bg',  'label'=>'Backorder won'],
    'backorder_lost'  => ['icon'=>'fa-times-circle',   'color'=>'--text3',   'bg'=>'--bg4',       'label'=>'Backorder lost'],
    'price_drop'      => ['icon'=>'fa-tag',            'color'=>'--purple',  'bg'=>'--purple-bg', 'label'=>'Price drop'],
    'whois_updated'   => ['icon'=>'fa-info-circle',    'color'=>'--blue',    'bg'=>'--blue-bg',   'label'=>'WHOIS update'],
];
$priorityMeta = [
    'high'   => ['color'=>'--coral',  'label'=>'High'],
    'medium' => ['color'=>'--amber',  'label'=>'Med'],
    'low'    => ['color'=>'--text3',  'label'=>'Low'],
];

function timeAgo($ts) {
    $diff = time() - $ts;
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return round($diff/60).'m ago';
    if ($diff < 86400)  return round($diff/3600).'h ago';
    if ($diff < 604800) return round($diff/86400).'d ago';
    return date('M j', $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Alerts — CheckDomain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;700;800&family=DM+Mono:wght@400;500&family=Instrument+Serif:ital@1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
  background-image:linear-gradient(rgba(29,158,117,.02) 1px,transparent 1px),linear-gradient(90deg,rgba(29,158,117,.02) 1px,transparent 1px);
  background-size:52px 52px;pointer-events:none;z-index:0}

/* ── Layout ─────── */
.main{margin-left:var(--sb-width);flex:1;position:relative;z-index:1;min-height:100vh}

/* ── Topbar ─────── */
.topbar{display:flex;align-items:center;justify-content:space-between;padding:15px 28px;border-bottom:1px solid var(--border);backdrop-filter:blur(12px);background:rgba(10,11,14,0.85);position:sticky;top:0;z-index:40;gap:14px}
.topbar-left{display:flex;align-items:center;gap:12px}
.topbar-right{display:flex;align-items:center;gap:10px}
.mobile-menu-btn{display:none;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;background:var(--bg2);border:1px solid var(--border);color:var(--text2);font-size:16px;cursor:pointer}
.breadcrumb{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--text3)}
.breadcrumb a{color:var(--text2);text-decoration:none;transition:color .15s}
.breadcrumb a:hover{color:var(--text)}
.topbar-btn{display:flex;align-items:center;justify-content:center;width:33px;height:33px;border-radius:8px;background:var(--bg2);border:1px solid var(--border);color:var(--text2);font-size:14px;cursor:pointer;text-decoration:none;transition:border-color .15s,color .15s}
.topbar-btn:hover{border-color:var(--border2);color:var(--text)}

/* ── Content ─────── */
.content{padding:28px 28px 60px}

/* ── Page header ─────── */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:24px;flex-wrap:wrap}
.page-eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:.16em;color:var(--text3);margin-bottom:5px}
.page-title{font-family:var(--serif);font-style:italic;font-size:28px;color:var(--text);margin-bottom:4px}
.page-sub{font-size:13px;color:var(--text2)}
.page-sub em{color:var(--green);font-family:var(--mono);font-style:normal}
.page-sub .warn{color:var(--amber);font-family:var(--mono)}

/* ── Upgrade gate ─────── */
.upgrade-gate{background:linear-gradient(135deg,rgba(29,158,117,.07),rgba(127,119,221,.05));border:1px solid rgba(29,158,117,.2);border-radius:14px;padding:28px 24px;text-align:center;margin-bottom:24px}
.gate-icon{font-size:28px;margin-bottom:12px}
.gate-title{font-size:16px;font-weight:800;color:var(--text);margin-bottom:6px}
.gate-sub{font-size:13px;color:var(--text2);max-width:380px;margin:0 auto 18px;line-height:1.6}
.gate-cta{display:inline-flex;align-items:center;gap:7px;background:var(--green);color:#fff;border:none;border-radius:9px;padding:10px 24px;font-family:var(--display);font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s;text-transform:uppercase;letter-spacing:.06em}
.gate-cta:hover{background:var(--green2)}

/* ── Stats row ─────── */
.stats-row{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:22px}
.stat-chip{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:12px 14px;display:flex;align-items:center;gap:10px;cursor:pointer;transition:border-color .15s,transform .12s;text-decoration:none}
.stat-chip:hover{border-color:var(--border2);transform:translateY(-1px)}
.stat-chip.active{border-color:rgba(29,158,117,.35);background:var(--green-bg)}
.stat-chip-icon{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.sci-all{background:var(--bg3);color:var(--text2)}
.sci-green{background:var(--green-bg);color:var(--green2)}
.sci-amber{background:var(--amber-bg);color:var(--amber)}
.sci-coral{background:var(--coral-bg);color:var(--coral)}
.sci-purple{background:var(--purple-bg);color:var(--purple)}
.stat-chip-num{font-size:18px;font-weight:800;font-family:var(--mono);color:var(--text);line-height:1}
.stat-chip-lbl{font-size:10px;color:var(--text2);margin-top:1px}

/* ── Controls bar ─────── */
.controls-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.controls-left{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.controls-right{display:flex;align-items:center;gap:8px}

.filter-tabs{display:flex;gap:2px;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:3px}
.ftab{padding:5px 13px;border-radius:5px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;cursor:pointer;color:var(--text3);background:none;border:none;font-family:var(--display);transition:all .12s;text-decoration:none;display:block}
.ftab:hover{color:var(--text);background:var(--bg3)}
.ftab.active{background:var(--bg3);color:var(--text)}

.type-select{background:var(--bg2);border:1px solid var(--border);border-radius:7px;padding:6px 10px;font-family:var(--display);font-size:11px;color:var(--text2);cursor:pointer;outline:none}

.count-label{font-size:12px;color:var(--text3);font-family:var(--mono);white-space:nowrap}
.count-label em{color:var(--green2);font-style:normal;font-weight:700}

.action-btn{display:flex;align-items:center;gap:5px;background:none;border:1px solid var(--border);border-radius:7px;padding:6px 12px;font-family:var(--display);font-size:11px;color:var(--text2);cursor:pointer;transition:all .13s;white-space:nowrap}
.action-btn:hover{background:var(--bg3);border-color:var(--border2);color:var(--text)}
.action-btn.danger:hover{background:var(--coral-bg);border-color:rgba(232,89,60,.25);color:var(--coral)}

/* ── Alert list ─────── */
.alerts-list{display:flex;flex-direction:column;gap:8px}

/* ── Alert card ─────── */
.alert-card{background:var(--bg2);border:1px solid var(--border);border-radius:13px;padding:16px 18px;display:flex;align-items:flex-start;gap:14px;position:relative;transition:border-color .15s,background .15s;cursor:default}
.alert-card:hover{border-color:var(--border2)}
.alert-card.unread{border-left:3px solid var(--green)}
.alert-card.unread.high-priority{border-left-color:var(--coral)}
.alert-card.unread.medium-priority{border-left-color:var(--amber)}
.alert-card.read{opacity:.75}
.alert-card.removing{opacity:0;transform:translateX(30px);transition:all .3s ease}

/* Unread dot */
.unread-dot{position:absolute;top:16px;right:16px;width:7px;height:7px;border-radius:50%;background:var(--green);animation:pulse 2s infinite}
.unread-dot.high{background:var(--coral)}
.unread-dot.medium{background:var(--amber)}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}

/* Alert icon */
.alert-icon-wrap{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}

/* Alert body */
.alert-body{flex:1;min-width:0}
.alert-header-row{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:4px;flex-wrap:wrap}
.alert-title{font-size:13px;font-weight:700;color:var(--text);line-height:1.4}
.alert-domain-tag{font-family:var(--mono);font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;white-space:nowrap;flex-shrink:0}
.alert-message{font-size:12px;color:var(--text2);line-height:1.6;margin-bottom:10px}

/* Meta row */
.alert-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.alert-time{font-size:10px;color:var(--text3);font-family:var(--mono)}
.type-badge{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;padding:2px 7px;border-radius:4px;flex-shrink:0}
.priority-badge{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;padding:2px 6px;border-radius:3px;background:var(--bg4);flex-shrink:0}

/* Alert CTA */
.alert-cta-row{display:flex;align-items:center;gap:8px;margin-top:10px;flex-wrap:wrap}
.alert-cta{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:7px;font-family:var(--display);font-size:11px;font-weight:700;cursor:pointer;text-decoration:none;transition:all .15s;border:none;text-transform:uppercase;letter-spacing:.05em}
.cta-green{background:var(--green-bg);color:var(--green2)}
.cta-green:hover{background:rgba(29,158,117,.2)}
.cta-amber{background:var(--amber-bg);color:var(--amber)}
.cta-amber:hover{background:rgba(239,159,39,.2)}
.cta-coral{background:var(--coral-bg);color:var(--coral)}
.cta-coral:hover{background:rgba(232,89,60,.2)}
.cta-purple{background:var(--purple-bg);color:var(--purple)}
.cta-purple:hover{background:rgba(127,119,221,.2)}

/* Alert actions */
.alert-actions{display:flex;flex-direction:column;gap:5px;flex-shrink:0}
.icon-btn{width:26px;height:26px;border-radius:6px;display:flex;align-items:center;justify-content:center;background:none;border:1px solid var(--border);color:var(--text3);font-size:11px;cursor:pointer;transition:all .13s}
.icon-btn:hover{background:var(--bg3);border-color:var(--border2);color:var(--text)}
.icon-btn.dismiss:hover{background:var(--coral-bg);border-color:rgba(232,89,60,.25);color:var(--coral)}

/* ── Empty state ─────── */
.empty-state{display:flex;flex-direction:column;align-items:center;gap:12px;text-align:center;padding:64px 24px}
.empty-icon-wrap{width:60px;height:60px;border-radius:16px;background:var(--bg3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:24px;color:var(--text3);margin-bottom:4px}
.empty-title{font-size:15px;font-weight:700;color:var(--text)}
.empty-sub{font-size:13px;color:var(--text2);max-width:300px;line-height:1.6}
.empty-cta{display:inline-flex;align-items:center;gap:7px;background:var(--green);color:#fff;border:none;border-radius:8px;padding:9px 20px;font-family:var(--display);font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s;margin-top:4px}
.empty-cta:hover{background:var(--green2)}

/* ── Alert settings panel ─────── */
.settings-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-top:28px}
.settings-card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.settings-card-title{font-size:12px;font-weight:700;color:var(--text)}
.settings-card-body{padding:20px}
.settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.setting-row{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid var(--border)}
.setting-row:last-child{border-bottom:none}
.setting-info{}
.setting-label{font-size:13px;font-weight:500;color:var(--text);margin-bottom:2px}
.setting-desc{font-size:11px;color:var(--text3);line-height:1.5}
.setting-plan-note{font-size:10px;color:var(--amber);font-family:var(--mono);margin-top:3px}
.toggle-switch{position:relative;width:38px;height:20px;flex-shrink:0}
.toggle-switch input{opacity:0;width:0;height:0}
.toggle-track{position:absolute;inset:0;background:var(--bg4);border:1px solid var(--border2);border-radius:20px;cursor:pointer;transition:background .2s}
.toggle-switch input:checked+.toggle-track{background:var(--green);border-color:var(--green)}
.toggle-track::before{content:'';position:absolute;width:14px;height:14px;border-radius:50%;background:var(--text3);top:2px;left:2px;transition:transform .2s,background .2s}
.toggle-switch input:checked+.toggle-track::before{transform:translateX(18px);background:#fff}
.toggle-switch input:disabled+.toggle-track{opacity:.4;cursor:not-allowed}

/* ── Toast ─────── */
.toast{position:fixed;bottom:28px;right:28px;z-index:999;background:var(--bg3);border:1px solid var(--border2);border-radius:10px;padding:12px 18px;font-size:13px;color:var(--text);box-shadow:0 8px 32px rgba(0,0,0,.4);transform:translateY(20px);opacity:0;transition:all .3s ease;max-width:320px;display:flex;align-items:center;gap:9px}
.toast.show{transform:translateY(0);opacity:1}
.toast.success{border-color:rgba(29,158,117,.3)}
.toast.error{border-color:rgba(232,89,60,.3)}

/* ── Responsive ─────── */
@media(max-width:1000px){.stats-row{grid-template-columns:repeat(3,1fr)}.settings-grid{grid-template-columns:1fr}}
@media(max-width:768px){
  .main{margin-left:0}.mobile-menu-btn{display:flex}
  .content{padding:20px 16px 50px}
  .stats-row{grid-template-columns:repeat(2,1fr)}
  .stats-row .stat-chip:last-child{grid-column:1/-1}
  .controls-bar{flex-direction:column;align-items:flex-start}
  .alert-card{flex-direction:column}
  .alert-actions{flex-direction:row}
}
@media(max-width:480px){.stats-row{grid-template-columns:1fr 1fr}}
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
        <span style="color:var(--text);">Alerts</span>
      </div>
    </div>
    <div class="topbar-right">
      <?php if ($alertCount > 0): ?>
      <button class="action-btn" onclick="markAllRead()" title="Mark all read">
        <i class="fas fa-check-double" style="font-size:11px;"></i> Mark all read
      </button>
      <?php endif; ?>
      <a href="<?= htmlspecialchars($assetUrl('watchlist.php')) ?>" class="topbar-btn" title="Watchlist">
        <i class="fas fa-bookmark"></i>
      </a>
    </div>
  </div>

  <div class="content">

    <!-- Page header -->
    <div class="page-header">
      <div>
        <div class="page-eyebrow">Monitoring</div>
        <div class="page-title">Your Alerts.</div>
        <div class="page-sub">
          <?php if ((int)$stats['unread'] > 0): ?>
            You have <span class="warn"><?= $stats['unread'] ?> unread</span> alert<?= $stats['unread'] != 1 ? 's' : '' ?> — domains need your attention.
          <?php elseif ((int)$stats['total'] > 0): ?>
            All caught up — <em><?= $stats['total'] ?> alert<?= $stats['total'] != 1 ? 's' : '' ?></em> in your history.
          <?php else: ?>
            No alerts yet. Add domains to your watchlist to start monitoring.
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Upgrade gate for free users -->
    <?php if ($userPlan === 'free'): ?>
    <div class="upgrade-gate">
      <div class="gate-icon">🔔</div>
      <div class="gate-title">Alerts require a Pro plan</div>
      <div class="gate-sub">
        Upgrade to Pro to receive real-time alerts when your watched domains become available, expire, or go offline. Free users can still add domains to their watchlist.
      </div>
      <a href="<?= htmlspecialchars($assetUrl('billing.php?plan=pro')) ?>" class="gate-cta">
        <i class="fas fa-bolt" style="font-size:10px;"></i> Upgrade to Pro — ₦9,000/mo
      </a>
    </div>
    <?php endif; ?>

    <!-- Stats row -->
    <div class="stats-row">
      <?php
      $statChips = [
        ['label'=>'All alerts',  'count'=>(int)$stats['total'],     'icon'=>'fa-bell',         'ico-class'=>'sci-all',    'filter'=>'all',     'type'=>'all'],
        ['label'=>'Available',   'count'=>(int)$stats['available'], 'icon'=>'fa-check-circle', 'ico-class'=>'sci-green',  'filter'=>'all',     'type'=>'available'],
        ['label'=>'Expiring',    'count'=>(int)$stats['expiring'],  'icon'=>'fa-clock',        'ico-class'=>'sci-amber',  'filter'=>'all',     'type'=>'expiring_soon'],
        ['label'=>'Dead sites',  'count'=>(int)$stats['dead'],      'icon'=>'fa-skull',        'ico-class'=>'sci-coral',  'filter'=>'all',     'type'=>'dead_site'],
        ['label'=>'Backorders',  'count'=>(int)$stats['backorders'],'icon'=>'fa-trophy',       'ico-class'=>'sci-purple', 'filter'=>'all',     'type'=>'backorder_won'],
      ];
      foreach ($statChips as $chip):
        $isActive = ($filter === $chip['filter'] && $typeFilter === $chip['type']);
      ?>
      <a href="?filter=<?= $chip['filter'] ?>&type=<?= $chip['type'] ?>"
         class="stat-chip <?= $isActive ? 'active' : '' ?>">
        <div class="stat-chip-icon <?= $chip['ico-class'] ?>">
          <i class="fas <?= $chip['icon'] ?>"></i>
        </div>
        <div>
          <div class="stat-chip-num"><?= $chip['count'] ?></div>
          <div class="stat-chip-lbl"><?= $chip['label'] ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Controls bar -->
    <div class="controls-bar">
      <div class="controls-left">
        <!-- Status filter tabs -->
        <div class="filter-tabs">
          <?php foreach (['all'=>'All','unread'=>'Unread','read'=>'Read'] as $f=>$label): ?>
          <a href="?filter=<?= $f ?>&type=<?= htmlspecialchars($typeFilter) ?>"
             class="ftab <?= $filter === $f ? 'active' : '' ?>">
            <?= $label ?>
            <?php if ($f === 'unread' && $alertCount > 0): ?>
            <span style="background:var(--coral);color:#fff;font-size:9px;padding:0 4px;border-radius:3px;margin-left:3px;"><?= $alertCount ?></span>
            <?php endif; ?>
          </a>
          <?php endforeach; ?>
        </div>

        <!-- Type filter -->
        <select class="type-select" onchange="window.location='?filter=<?= $filter ?>&type='+this.value">
          <option value="all"           <?= $typeFilter==='all'?'selected':'' ?>>All types</option>
          <option value="available"     <?= $typeFilter==='available'?'selected':'' ?>>Available</option>
          <option value="expiring_soon" <?= $typeFilter==='expiring_soon'?'selected':'' ?>>Expiring soon</option>
          <option value="just_expired"  <?= $typeFilter==='just_expired'?'selected':'' ?>>Just expired</option>
          <option value="dead_site"     <?= $typeFilter==='dead_site'?'selected':'' ?>>Dead sites</option>
          <option value="backorder_won" <?= $typeFilter==='backorder_won'?'selected':'' ?>>Backorder won</option>
          <option value="backorder_lost"<?= $typeFilter==='backorder_lost'?'selected':'' ?>>Backorder lost</option>
          <option value="whois_updated" <?= $typeFilter==='whois_updated'?'selected':'' ?>>WHOIS updates</option>
        </select>

        <span class="count-label">
          <em id="visibleCount"><?= count($alerts) ?></em> alert<?= count($alerts) !== 1 ? 's' : '' ?>
        </span>
      </div>

      <div class="controls-right">
        <?php if (count($alerts) > 0): ?>
        <button class="action-btn danger" onclick="dismissAll()">
          <i class="fas fa-trash" style="font-size:10px;"></i> Dismiss all
        </button>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Alert cards ─────────────────────────────────── -->
    <div class="alerts-list" id="alertsList">

      <?php if (!empty($alerts)): ?>
        <?php foreach ($alerts as $alert):
          $meta     = $alertMeta[$alert['alert_type']] ?? $alertMeta['whois_updated'];
          $priMeta  = $priorityMeta[$alert['priority']] ?? $priorityMeta['low'];
          $isUnread = $alert['status'] === 'unread';
          $ts       = strtotime($alert['created_at']);

          // Resolve CTA style
          $ctaClass = match($alert['alert_type']) {
            'available'              => 'cta-green',
            'expiring_soon','just_expired' => 'cta-amber',
            'dead_site','backorder_won'    => 'cta-coral',
            'backorder_lost'         => 'cta-coral',
            'price_drop'             => 'cta-purple',
            default                  => 'cta-green',
          };

          // Domain tag color
          $tagStyle = "background:var({$meta['bg']});color:var({$meta['color']});";
        ?>
        <div class="alert-card <?= $isUnread ? 'unread' : 'read' ?> <?= $alert['priority'] ?>-priority"
             id="alert-<?= (int)$alert['id'] ?>"
             data-id="<?= (int)$alert['id'] ?>"
             data-status="<?= htmlspecialchars($alert['status']) ?>">

          <!-- Unread dot -->
          <?php if ($isUnread): ?>
          <span class="unread-dot <?= $alert['priority'] ?>"></span>
          <?php endif; ?>

          <!-- Icon -->
          <div class="alert-icon-wrap" style="background:var(<?= $meta['bg'] ?>);color:var(<?= $meta['color'] ?>);">
            <i class="fas <?= $meta['icon'] ?>"></i>
          </div>

          <!-- Body -->
          <div class="alert-body">
            <div class="alert-header-row">
              <div class="alert-title"><?= htmlspecialchars($alert['title']) ?></div>
              <span class="alert-domain-tag" style="<?= $tagStyle ?>">
                <?= htmlspecialchars($alert['domain_name']) ?>
              </span>
            </div>

            <?php if (!empty($alert['message'])): ?>
            <div class="alert-message"><?= htmlspecialchars($alert['message']) ?></div>
            <?php endif; ?>

            <!-- CTA buttons -->
            <?php if (!empty($alert['action_url']) && !empty($alert['action_label'])): ?>
            <div class="alert-cta-row">
              <a href="<?= htmlspecialchars($alert['action_url']) ?>"
                 class="alert-cta <?= $ctaClass ?>"
                 onclick="handleCtaClick(<?= (int)$alert['id'] ?>)">
                <?= htmlspecialchars($alert['action_label']) ?>
              </a>
              <a href="<?= htmlspecialchars($assetUrl('index.php')) ?>?q=<?= urlencode($alert['domain_name']) ?>"
                 class="alert-cta" style="background:var(--bg3);color:var(--text2);">
                <i class="fas fa-search" style="font-size:10px;"></i> Check domain
              </a>
            </div>
            <?php else: ?>
            <div class="alert-cta-row">
              <a href="<?= htmlspecialchars($assetUrl('index.php')) ?>?q=<?= urlencode($alert['domain_name']) ?>"
                 class="alert-cta <?= $ctaClass ?>"
                 onclick="handleCtaClick(<?= (int)$alert['id'] ?>)">
                <i class="fas fa-search" style="font-size:10px;"></i> Check domain
              </a>
            </div>
            <?php endif; ?>

            <!-- Meta -->
            <div class="alert-meta" style="margin-top:8px;">
              <span class="type-badge" style="background:var(<?= $meta['bg'] ?>);color:var(<?= $meta['color'] ?>);"><?= $meta['label'] ?></span>
              <span class="priority-badge" style="color:var(<?= $priMeta['color'] ?>);">
                <?= $priMeta['label'] ?> priority
              </span>
              <span class="alert-time">
                <i class="fas fa-clock" style="font-size:9px;margin-right:3px;"></i>
                <?= timeAgo($ts) ?> · <?= date('M j, Y H:i', $ts) ?>
              </span>
            </div>
          </div>

          <!-- Quick actions -->
          <div class="alert-actions">
            <?php if ($isUnread): ?>
            <button class="icon-btn" onclick="markRead(<?= (int)$alert['id'] ?>)" title="Mark as read">
              <i class="fas fa-check"></i>
            </button>
            <?php endif; ?>
            <button class="icon-btn dismiss" onclick="dismissAlert(<?= (int)$alert['id'] ?>)" title="Dismiss">
              <i class="fas fa-times"></i>
            </button>
          </div>

        </div>
        <?php endforeach; ?>

      <?php else: ?>
        <div class="empty-state" id="emptyState">
          <div class="empty-icon-wrap">
            <i class="fas <?= $filter==='unread' ? 'fa-check-circle' : 'fa-bell' ?>"></i>
          </div>
          <div class="empty-title">
            <?php if ($filter==='unread'): ?>All caught up!
            <?php elseif ($filter==='dismissed'): ?>No dismissed alerts
            <?php else: ?>No alerts yet<?php endif; ?>
          </div>
          <div class="empty-sub">
            <?php if ($filter==='unread'): ?>
              No unread alerts. All notifications have been reviewed.
            <?php elseif ($userPlan==='free'): ?>
              Upgrade to Pro to start receiving domain alerts.
            <?php else: ?>
              Alerts appear here when your watched domains become available, expire, or go offline.
            <?php endif; ?>
          </div>
          <?php if ($filter==='all' && $userPlan !== 'free'): ?>
          <a href="<?= htmlspecialchars($assetUrl('watchlist.php')) ?>" class="empty-cta">
            <i class="fas fa-bookmark" style="font-size:11px;"></i> Manage watchlist
          </a>
          <?php elseif ($userPlan==='free'): ?>
          <a href="<?= htmlspecialchars($assetUrl('billing.php?plan=pro')) ?>" class="empty-cta">
            <i class="fas fa-bolt" style="font-size:11px;"></i> Upgrade to Pro
          </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    </div>

    <!-- ── Alert settings ──────────────────────────── -->
    <div class="settings-card">
      <div class="settings-card-header">
        <div class="settings-card-title">
          <i class="fas fa-sliders-h" style="color:var(--green2);margin-right:7px;"></i>
          Alert preferences
        </div>
        <a href="<?= htmlspecialchars($assetUrl('account-settings.php#notifications')) ?>"
           style="font-size:11px;color:var(--green);text-decoration:none;">
          All notification settings →
        </a>
      </div>
      <div class="settings-card-body">
        <?php
        $alertSettings = [
          [
            'key'     => 'alert_available',
            'label'   => 'Domain becomes available',
            'desc'    => 'Notify when a watched domain drops and is available to register.',
            'icon'    => 'fa-check-circle',
            'color'   => 'var(--green2)',
            'enabled' => true,
            'pro'     => false,
          ],
          [
            'key'     => 'alert_expiring',
            'label'   => 'Expiring soon (30 days)',
            'desc'    => 'Alert when a watched domain is within 30 days of expiry.',
            'icon'    => 'fa-clock',
            'color'   => 'var(--amber)',
            'enabled' => $userPlan !== 'free',
            'pro'     => true,
          ],
          [
            'key'     => 'alert_expired',
            'label'   => 'Just expired',
            'desc'    => 'Alert immediately when a watched domain registration expires.',
            'icon'    => 'fa-hourglass-end',
            'color'   => 'var(--coral)',
            'enabled' => $userPlan !== 'free',
            'pro'     => true,
          ],
          [
            'key'     => 'alert_dead',
            'label'   => 'Dead-site detection',
            'desc'    => 'Alert when a watched domain\'s website goes offline or returns errors.',
            'icon'    => 'fa-skull',
            'color'   => 'var(--coral)',
            'enabled' => $userPlan !== 'free',
            'pro'     => true,
          ],
          [
            'key'     => 'alert_backorder',
            'label'   => 'Backorder results',
            'desc'    => 'Notify when you win or lose a domain backorder.',
            'icon'    => 'fa-trophy',
            'color'   => 'var(--amber)',
            'enabled' => $userPlan !== 'free',
            'pro'     => true,
          ],
          [
            'key'     => 'alert_whois',
            'label'   => 'WHOIS changes',
            'desc'    => 'Alert when registrant, nameservers, or status change on a domain.',
            'icon'    => 'fa-file-alt',
            'color'   => 'var(--blue)',
            'enabled' => $userPlan === 'elite',
            'pro'     => true,
            'elite'   => true,
          ],
        ];
        ?>
        <div style="display:flex;flex-direction:column;gap:0;">
          <?php foreach ($alertSettings as $setting): ?>
          <div class="setting-row">
            <div class="setting-info">
              <div class="setting-label">
                <i class="fas <?= $setting['icon'] ?>" style="color:<?= $setting['color'] ?>;font-size:12px;margin-right:7px;"></i>
                <?= htmlspecialchars($setting['label']) ?>
                <?php if (!empty($setting['elite'])): ?>
                <span style="font-size:9px;background:var(--purple-bg);color:var(--purple);padding:1px 5px;border-radius:3px;margin-left:5px;font-weight:700;">ELITE</span>
                <?php elseif (!empty($setting['pro'])): ?>
                <span style="font-size:9px;background:var(--green-bg);color:var(--green2);padding:1px 5px;border-radius:3px;margin-left:5px;font-weight:700;">PRO</span>
                <?php endif; ?>
              </div>
              <div class="setting-desc"><?= htmlspecialchars($setting['desc']) ?></div>
              <?php if (!$setting['enabled'] && $setting['pro']): ?>
              <div class="setting-plan-note">
                <i class="fas fa-lock" style="font-size:9px;"></i>
                Requires <?= !empty($setting['elite']) ? 'Elite' : 'Pro' ?> plan
                — <a href="<?= htmlspecialchars($assetUrl('billing.php')) ?>" style="color:var(--amber);text-decoration:none;">upgrade</a>
              </div>
              <?php endif; ?>
            </div>
            <label class="toggle-switch">
              <input type="checkbox"
                     <?= $setting['enabled'] ? 'checked' : '' ?>
                     <?= !$setting['enabled'] ? 'disabled' : '' ?>
                     onchange="saveAlertPref('<?= $setting['key'] ?>', this.checked)">
              <span class="toggle-track"></span>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div><!-- /.content -->
</main>

<!-- Toast -->
<div class="toast" id="toast">
  <i class="fas fa-check-circle" id="toastIcon" style="font-size:14px;flex-shrink:0;color:var(--green2);"></i>
  <span id="toastText"></span>
</div>

<script>
const APP_BASE = <?= json_encode($appBasePath ?? '') ?>;
const ALERTS_API = window.location.pathname; // POST to self

// ── Mark single alert read ────────────────────────────────
async function markRead(id) {
  const res  = await postAction({ action: 'mark_read', id });
  if (res.success) {
    const card = document.getElementById('alert-' + id);
    if (card) {
      card.classList.remove('unread');
      card.classList.add('read');
      card.querySelector('.unread-dot')?.remove();
      card.querySelector('.icon-btn[onclick^="markRead"]')?.remove();
      card.dataset.status = 'read';
    }
    updateUnreadBadge(-1);
    showToast('Alert marked as read.');
  }
}

// ── Mark all read ─────────────────────────────────────────
async function markAllRead() {
  const res = await postAction({ action: 'mark_all_read' });
  if (res.success) {
    document.querySelectorAll('.alert-card.unread').forEach(card => {
      card.classList.remove('unread');
      card.classList.add('read');
      card.querySelector('.unread-dot')?.remove();
      card.querySelector('.icon-btn[onclick^="markRead"]')?.remove();
      card.dataset.status = 'read';
    });
    updateUnreadBadge(-999);
    showToast(`${res.count || 'All'} alerts marked as read.`);
  }
}

// ── Dismiss single alert ──────────────────────────────────
async function dismissAlert(id) {
  const res  = await postAction({ action: 'dismiss', id });
  if (res.success) {
    const card = document.getElementById('alert-' + id);
    if (card) {
      card.classList.add('removing');
      setTimeout(() => {
        card.remove();
        checkEmpty();
        updateVisibleCount();
      }, 320);
    }
    showToast('Alert dismissed.');
  }
}

// ── Dismiss all ───────────────────────────────────────────
async function dismissAll() {
  if (!confirm('Dismiss all visible alerts?')) return;
  const res = await postAction({ action: 'dismiss_all' });
  if (res.success) {
    document.querySelectorAll('.alert-card').forEach(c => {
      c.classList.add('removing');
      setTimeout(() => c.remove(), 320);
    });
    setTimeout(() => { checkEmpty(); updateVisibleCount(); }, 350);
    updateUnreadBadge(-999);
    showToast('All alerts dismissed.');
  }
}

// ── CTA click — auto mark read ────────────────────────────
function handleCtaClick(id) {
  const card = document.getElementById('alert-' + id);
  if (card && card.dataset.status === 'unread') {
    markRead(id);
  }
}

// ── Save alert preference ─────────────────────────────────
function saveAlertPref(key, value) {
  // POST to account-settings endpoint or store in localStorage as placeholder
  localStorage.setItem('alertPref_' + key, value ? '1' : '0');
  showToast('Preference saved.');
}

// ── Helpers ───────────────────────────────────────────────
async function postAction(body) {
  try {
    const res  = await fetch(ALERTS_API, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body:    JSON.stringify(body),
    });
    return await res.json();
  } catch {
    showToast('Network error.', 'error');
    return { success: false };
  }
}

function updateUnreadBadge(delta) {
  const badge = document.querySelector('.sidebar .cd-sb-badge');
  if (!badge) return;
  const curr = parseInt(badge.textContent) || 0;
  const next = Math.max(0, curr + delta);
  if (next === 0) badge.style.display = 'none';
  else { badge.textContent = next; badge.style.display = ''; }
}

function updateVisibleCount() {
  const cards = document.querySelectorAll('.alert-card');
  const el    = document.getElementById('visibleCount');
  if (el) el.textContent = cards.length;
}

function checkEmpty() {
  const list = document.getElementById('alertsList');
  if (!list) return;
  if (list.querySelectorAll('.alert-card').length === 0 && !list.querySelector('.empty-state')) {
    list.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon-wrap"><i class="fas fa-check-circle" style="color:var(--green2);"></i></div>
        <div class="empty-title">All clear!</div>
        <div class="empty-sub">No alerts here. Your watched domains are being monitored.</div>
      </div>`;
  }
}

// ── Toast ─────────────────────────────────────────────────
function showToast(msg, type = 'success') {
  const t    = document.getElementById('toast');
  const icon = document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  icon.className   = `fas ${type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'}`;
  icon.style.color = type === 'error' ? 'var(--coral)' : 'var(--green2)';
  t.className = `toast show ${type}`;
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.remove('show'), 3400);
}

// ── Mobile sidebar ────────────────────────────────────────
function openSidebar()  { document.getElementById('cdSidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('show'); }
function closeSidebar() { document.getElementById('cdSidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('show'); }

// ── Auto-mark visible alerts as read after 5s on page ────
window.addEventListener('DOMContentLoaded', () => {
  // Restore toggle prefs from localStorage
  document.querySelectorAll('.toggle-switch input:not([disabled])').forEach(toggle => {
    const key   = toggle.getAttribute('onchange')?.match(/'([^']+)'/)?.[1];
    if (!key) return;
    const saved = localStorage.getItem('alertPref_' + key);
    if (saved !== null) toggle.checked = saved === '1';
  });
});
</script>

</body>
</html>