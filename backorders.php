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

// ── DB setup ───────────────────────────────────────────────
$conn = getDBConnection();

// Create backorders table if it doesn't exist yet
$conn->query("
    CREATE TABLE IF NOT EXISTS backorders (
        id                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        user_id             INT NOT NULL,
        domain_name         VARCHAR(253)     NOT NULL,
        tld                 VARCHAR(63)      NOT NULL,
        status              ENUM(
                                'pending',      -- placed, awaiting drop
                                'watching',     -- monitoring the drop date
                                'processing',   -- drop detected, attempting capture
                                'won',          -- successfully captured
                                'lost',         -- drop missed or outbid
                                'canceled',     -- user canceled before drop
                                'expired'       -- monitoring window lapsed
                            ) NOT NULL DEFAULT 'pending',
        priority            ENUM('standard','express') NOT NULL DEFAULT 'standard',
        credits_spent       TINYINT UNSIGNED NOT NULL DEFAULT 5,
        payment_id          INT UNSIGNED     NULL,
        estimated_drop_date DATE             NULL   COMMENT 'From WHOIS expiry data',
        actual_drop_date    DATE             NULL,
        drop_detected_at    TIMESTAMP        NULL,
        won_at              TIMESTAMP        NULL,
        registrar           VARCHAR(255)     NULL   COMMENT 'Current registrar from WHOIS',
        registrar_url       VARCHAR(512)     NULL,
        whois_expiry_date   DATE             NULL,
        notes               TEXT             NULL,
        notify_email        TINYINT(1)       NOT NULL DEFAULT 1,
        created_at          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_backorder_user_domain (user_id, domain_name),
        INDEX idx_bo_user   (user_id),
        INDEX idx_bo_status (status),
        INDEX idx_bo_domain (domain_name),
        INDEX idx_bo_drop   (estimated_drop_date),
        CONSTRAINT fk_bo_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Fetch user ─────────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, email, full_name, plan, credits, credits_reserved FROM users WHERE id = ?");
$stmt->bind_param("i", $session['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { header('Location: logout.php'); exit(); }

$userPlan = $user['plan']             ?? 'free';
$credits  = (int)($user['credits']    ?? 0);
$reserved = (int)($user['credits_reserved'] ?? 0);
$canBackorder = ($userPlan !== 'free');
$creditCost   = 5;

// ── Handle AJAX ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    ob_start();
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    // ── Place backorder ─────────────────────────────────────
    if ($action === 'place') {
        if (!$canBackorder) {
            ob_end_clean(); echo json_encode(['success'=>false,'requiresUpgrade'=>true,'message'=>'Backorders require a Pro or Elite plan.']); exit();
        }
        $raw    = strtolower(trim($input['domain'] ?? ''));
        $raw    = preg_replace('#^https?://(www\.)?#', '', $raw);
        $domain = rtrim($raw, '/');

        if (!$domain || !str_contains($domain, '.')) {
            ob_end_clean(); echo json_encode(['success'=>false,'message'=>'Enter a valid domain name.']); exit();
        }
        if (strlen($domain) > 253) {
            ob_end_clean(); echo json_encode(['success'=>false,'message'=>'Domain name too long.']); exit();
        }
        $priority = ($input['priority'] ?? 'standard') === 'express' ? 'express' : 'standard';
        $cost     = $priority === 'express' ? 10 : 5;

        if (($credits - $reserved) < $cost) {
            ob_end_clean(); echo json_encode(['success'=>false,'insufficientCredits'=>true,'message'=>"Not enough credits. This costs {$cost} credits. You have " . ($credits - $reserved) . " available."]); exit();
        }

        // Check duplicate
        $dupStmt = $conn->prepare("SELECT id, status FROM backorders WHERE user_id=? AND domain_name=?");
        $dupStmt->bind_param("is", $session['user_id'], $domain);
        $dupStmt->execute();
        $existing = $dupStmt->get_result()->fetch_assoc();
        $dupStmt->close();
        if ($existing && in_array($existing['status'], ['pending','watching','processing'])) {
            ob_end_clean(); echo json_encode(['success'=>false,'message'=>"You already have an active backorder for {$domain}."]); exit();
        }

        $tld = implode('.', array_slice(explode('.', $domain), 1));

        // Reserve credits
        $resStmt = $conn->prepare("UPDATE users SET credits_reserved = credits_reserved + ? WHERE id = ? AND (credits - credits_reserved) >= ?");
        $resStmt->bind_param("iii", $cost, $session['user_id'], $cost);
        $resStmt->execute();
        if ($resStmt->affected_rows === 0) {
            $resStmt->close();
            ob_end_clean(); echo json_encode(['success'=>false,'message'=>'Credit reservation failed. Please refresh and try again.']); exit();
        }
        $resStmt->close();

        // Insert backorder
        $insStmt = $conn->prepare("
            INSERT INTO backorders (user_id, domain_name, tld, status, priority, credits_spent)
            VALUES (?, ?, ?, 'pending', ?, ?)
            ON DUPLICATE KEY UPDATE status='pending', priority=VALUES(priority), credits_spent=VALUES(credits_spent), updated_at=NOW()
        ");
        $insStmt->bind_param("isssi", $session['user_id'], $domain, $tld, $priority, $cost);
        $insStmt->execute();
        $newId = $conn->insert_id;
        $insStmt->close();

        // Ledger note (will deduct fully when won; for now just reserved)
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'id'      => $newId,
            'domain'  => $domain,
            'message' => "Backorder placed for {$domain}. {$cost} credits reserved.",
        ]);
        exit();
    }

    // ── Cancel backorder ────────────────────────────────────
    if ($action === 'cancel') {
        $id = (int)($input['id'] ?? 0);
        $boStmt = $conn->prepare("SELECT id, credits_spent, status FROM backorders WHERE id=? AND user_id=?");
        $boStmt->bind_param("ii", $id, $session['user_id']);
        $boStmt->execute();
        $bo = $boStmt->get_result()->fetch_assoc();
        $boStmt->close();

        if (!$bo || !in_array($bo['status'], ['pending','watching'])) {
            ob_end_clean(); echo json_encode(['success'=>false,'message'=>'Backorder cannot be canceled at this stage.']); exit();
        }

        $cost = (int)$bo['credits_spent'];

        // Cancel & release reserved credits
        $conn->prepare("UPDATE backorders SET status='canceled', updated_at=NOW() WHERE id=?")->bind_param("i",$id)->execute();
        $upd = $conn->prepare("UPDATE backorders SET status='canceled', updated_at=NOW() WHERE id=?");
        $upd->bind_param("i", $id); $upd->execute(); $upd->close();

        $rel = $conn->prepare("UPDATE users SET credits_reserved = GREATEST(0, credits_reserved - ?) WHERE id=?");
        $rel->bind_param("ii", $cost, $session['user_id']); $rel->execute(); $rel->close();

        ob_end_clean();
        echo json_encode(['success'=>true,'message'=>"Backorder canceled. {$cost} credits released."]);
        exit();
    }

    ob_end_clean();
    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
    exit();
}

// ── Fetch backorders ────────────────────────────────────────
$filter = in_array($_GET['filter'] ?? '', ['active','won','lost','canceled','all']) ? $_GET['filter'] : 'all';

$whereMap = [
    'active'   => "status IN ('pending','watching','processing')",
    'won'      => "status = 'won'",
    'lost'     => "status IN ('lost','expired')",
    'canceled' => "status = 'canceled'",
    'all'      => "1=1",
];
$whereSQL = $whereMap[$filter];

$boStmt = $conn->prepare("
    SELECT * FROM backorders
    WHERE user_id = ? AND {$whereSQL}
    ORDER BY
        CASE status
            WHEN 'processing' THEN 0
            WHEN 'watching'   THEN 1
            WHEN 'pending'    THEN 2
            WHEN 'won'        THEN 3
            WHEN 'lost'       THEN 4
            WHEN 'canceled'   THEN 5
            ELSE 6 END,
        created_at DESC
    LIMIT 50
");
$boStmt->bind_param("i", $session['user_id']);
$boStmt->execute();
$boResult = $boStmt->get_result();
$backorders = [];
while ($row = $boResult->fetch_assoc()) { $backorders[] = $row; }
$boStmt->close();

// ── Stats ───────────────────────────────────────────────────
$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) as total,
        SUM(status IN ('pending','watching','processing')) as active_count,
        SUM(status = 'won')    as won_count,
        SUM(status IN ('lost','expired')) as lost_count,
        SUM(status = 'canceled') as canceled_count,
        SUM(credits_spent) as total_credits_spent
    FROM backorders WHERE user_id = ?
");
$statsStmt->bind_param("i", $session['user_id']);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

// ── Watchlist + alert count for sidebar ────────────────────
$watchStmt = $conn->prepare("SELECT COUNT(*) as c FROM pinned_domains WHERE user_id=? AND status='active'");
$watchStmt->bind_param("i", $session['user_id']);
$watchStmt->execute();
$watchlistCount = (int)$watchStmt->get_result()->fetch_assoc()['c'];
$watchStmt->close();

$alertStmt = null;
$alertCount = 0;
$alertCheck = $conn->prepare("SELECT COUNT(*) as c FROM domain_alerts WHERE user_id=? AND status='unread'");
if ($alertCheck) {
    $alertCheck->bind_param("i", $session['user_id']);
    $alertCheck->execute();
    $alertCount = (int)$alertCheck->get_result()->fetch_assoc()['c'];
    $alertCheck->close();
}

$conn->close();

// ── Display meta ───────────────────────────────────────────
$userName  = trim($user['full_name'] ?? '') ?: explode('@', $user['email'])[0];
$firstName = explode(' ', $userName)[0];
$initials  = strtoupper(substr($userName,0,1).(strpos($userName,' ')!==false?substr($userName,strpos($userName,' ')+1,1):''));
$availableCredits = $credits - $reserved;

$activePage = 'backorders';

// ── Status display meta ────────────────────────────────────
$statusMeta = [
    'pending'    => ['icon'=>'fa-clock',         'color'=>'--amber',   'bg'=>'--amber-bg',   'label'=>'Pending',    'desc'=>'Waiting for drop date'],
    'watching'   => ['icon'=>'fa-eye',            'color'=>'--blue',    'bg'=>'--blue-bg',    'label'=>'Watching',   'desc'=>'Monitoring the expiry'],
    'processing' => ['icon'=>'fa-spinner',        'color'=>'--purple',  'bg'=>'--purple-bg',  'label'=>'Processing', 'desc'=>'Drop detected — capturing'],
    'won'        => ['icon'=>'fa-trophy',         'color'=>'--green2',  'bg'=>'--green-bg',   'label'=>'Won',        'desc'=>'Domain successfully captured'],
    'lost'       => ['icon'=>'fa-times-circle',   'color'=>'--coral',   'bg'=>'--coral-bg',   'label'=>'Lost',       'desc'=>'Domain not captured'],
    'canceled'   => ['icon'=>'fa-ban',            'color'=>'--text3',   'bg'=>'--bg4',        'label'=>'Canceled',   'desc'=>'Canceled by you'],
    'expired'    => ['icon'=>'fa-hourglass-end',  'color'=>'--text3',   'bg'=>'--bg4',        'label'=>'Expired',    'desc'=>'Monitoring window lapsed'],
];

function timeAgo($ts) {
    $diff = time() - $ts;
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return round($diff/60).'m ago';
    if ($diff < 86400)  return round($diff/3600).'h ago';
    if ($diff < 604800) return round($diff/86400).'d ago';
    return date('M j, Y', $ts);
}
function daysUntil($dateStr) {
    if (!$dateStr) return null;
    $diff = (strtotime($dateStr) - time()) / 86400;
    return (int)ceil($diff);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Backorders — CheckDomain</title>
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

/* ── Layout ─── */
.main{margin-left:var(--sb-width);flex:1;position:relative;z-index:1;min-height:100vh}

/* ── Topbar ─── */
.topbar{display:flex;align-items:center;justify-content:space-between;padding:15px 28px;border-bottom:1px solid var(--border);backdrop-filter:blur(12px);background:rgba(10,11,14,0.85);position:sticky;top:0;z-index:40;gap:14px}
.topbar-left{display:flex;align-items:center;gap:12px}
.topbar-right{display:flex;align-items:center;gap:10px}
.mobile-menu-btn{display:none;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;background:var(--bg2);border:1px solid var(--border);color:var(--text2);font-size:16px;cursor:pointer}
.breadcrumb{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--text3)}
.breadcrumb a{color:var(--text2);text-decoration:none;transition:color .15s}
.breadcrumb a:hover{color:var(--text)}
.topbar-btn{display:flex;align-items:center;justify-content:center;width:33px;height:33px;border-radius:8px;background:var(--bg2);border:1px solid var(--border);color:var(--text2);font-size:14px;cursor:pointer;text-decoration:none;transition:border-color .15s,color .15s}
.topbar-btn:hover{border-color:var(--border2);color:var(--text)}
.credits-pill{display:flex;align-items:center;gap:6px;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:6px 12px;font-family:var(--mono);font-size:12px;color:var(--text2);white-space:nowrap}
.credits-pill b{color:var(--amber);font-weight:700}

/* ── Content ─── */
.content{padding:28px 28px 60px}

/* ── Page header ─── */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:24px;flex-wrap:wrap}
.page-eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:.16em;color:var(--text3);margin-bottom:5px}
.page-title{font-family:var(--serif);font-style:italic;font-size:28px;color:var(--text);margin-bottom:4px}
.page-sub{font-size:13px;color:var(--text2)}
.page-sub em{color:var(--green);font-family:var(--mono);font-style:normal}
.page-sub .warn{color:var(--amber);font-family:var(--mono)}

/* ── Upgrade gate ─── */
.upgrade-gate{background:linear-gradient(135deg,rgba(29,158,117,.07),rgba(127,119,221,.05));border:1px solid rgba(29,158,117,.2);border-radius:14px;padding:28px 24px;text-align:center;margin-bottom:24px}
.gate-icon{font-size:28px;margin-bottom:12px}
.gate-title{font-size:16px;font-weight:800;color:var(--text);margin-bottom:6px}
.gate-sub{font-size:13px;color:var(--text2);max-width:400px;margin:0 auto 18px;line-height:1.6}
.gate-cta{display:inline-flex;align-items:center;gap:7px;background:var(--green);color:#fff;border:none;border-radius:9px;padding:10px 24px;font-family:var(--display);font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s;text-transform:uppercase;letter-spacing:.06em}
.gate-cta:hover{background:var(--green2)}

/* ── Stats row ─── */
.stats-row{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:24px}
.stat-chip{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:12px 14px;display:flex;align-items:center;gap:10px;cursor:pointer;transition:border-color .15s,transform .12s;text-decoration:none}
.stat-chip:hover{border-color:var(--border2);transform:translateY(-1px)}
.stat-chip.active{border-color:rgba(29,158,117,.35);background:var(--green-bg)}
.stat-chip-icon{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.sci-all{background:var(--bg3);color:var(--text2)}
.sci-amber{background:var(--amber-bg);color:var(--amber)}
.sci-green{background:var(--green-bg);color:var(--green2)}
.sci-coral{background:var(--coral-bg);color:var(--coral)}
.sci-grey{background:var(--bg4);color:var(--text3)}
.stat-chip-num{font-size:18px;font-weight:800;font-family:var(--mono);color:var(--text);line-height:1}
.stat-chip-lbl{font-size:10px;color:var(--text2);margin-top:1px}

/* ── Place backorder card ─── */
.place-card{background:var(--bg2);border:1px solid var(--border2);border-radius:14px;padding:20px 22px;margin-bottom:24px}
.place-card-title{font-size:12px;font-weight:700;color:var(--text);margin-bottom:4px;display:flex;align-items:center;gap:7px}
.place-card-sub{font-size:12px;color:var(--text2);margin-bottom:16px}

.place-form{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.place-input-wrap{flex:1;min-width:200px;position:relative}
.place-input{width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:9px;padding:10px 14px;font-family:var(--mono);font-size:13px;color:var(--text);outline:none;transition:border-color .2s}
.place-input::placeholder{color:var(--text3)}
.place-input:focus{border-color:var(--green)}
.place-input:disabled{opacity:.45;cursor:not-allowed}

.priority-toggle{display:flex;gap:2px;background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:3px;flex-shrink:0}
.ptog{padding:6px 13px;border-radius:5px;font-size:11px;font-weight:700;cursor:pointer;background:none;border:none;font-family:var(--display);color:var(--text3);transition:all .13s;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}
.ptog:hover{color:var(--text)}
.ptog.active{background:var(--bg2);color:var(--text);box-shadow:0 1px 4px rgba(0,0,0,.3)}
.ptog:disabled{opacity:.4;cursor:not-allowed}

.place-btn{display:flex;align-items:center;gap:7px;background:var(--green);color:#fff;border:none;border-radius:9px;padding:10px 20px;font-family:var(--display);font-size:12px;font-weight:700;cursor:pointer;transition:background .2s;white-space:nowrap;flex-shrink:0}
.place-btn:hover{background:var(--green2)}
.place-btn:disabled{opacity:.5;cursor:not-allowed}

.place-hint{display:flex;align-items:center;gap:12px;margin-top:12px;font-size:11px;color:var(--text3);flex-wrap:wrap}
.place-hint i{font-size:10px}
.cost-badge{background:var(--amber-bg);color:var(--amber);font-family:var(--mono);font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px}
.cost-badge.express{background:var(--purple-bg);color:var(--purple)}

/* ── Controls ─── */
.controls-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.controls-left{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.controls-right{display:flex;align-items:center;gap:8px}
.filter-tabs{display:flex;gap:2px;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:3px}
.ftab{padding:5px 13px;border-radius:5px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;cursor:pointer;color:var(--text3);background:none;border:none;font-family:var(--display);transition:all .12s;text-decoration:none;display:block}
.ftab:hover{color:var(--text);background:var(--bg3)}
.ftab.active{background:var(--bg3);color:var(--text)}
.count-label{font-size:12px;color:var(--text3);font-family:var(--mono);white-space:nowrap}
.count-label em{color:var(--green2);font-style:normal;font-weight:700}

/* ── Backorder list ─── */
.bo-list{display:flex;flex-direction:column;gap:10px}

/* ── Backorder card ─── */
.bo-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden;transition:border-color .15s}
.bo-card:hover{border-color:var(--border2)}
.bo-card.status-won{border-color:rgba(29,158,117,.25)}
.bo-card.status-lost,.bo-card.status-expired{opacity:.7}
.bo-card.status-processing{border-color:rgba(127,119,221,.3);animation:processing-glow 3s ease-in-out infinite}
@keyframes processing-glow{0%,100%{border-color:rgba(127,119,221,.3)}50%{border-color:rgba(127,119,221,.6)}}

.bo-card-main{display:grid;grid-template-columns:auto 1fr auto auto;align-items:center;gap:14px;padding:16px 18px}

/* Status icon */
.bo-status-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.bo-status-icon .fa-spinner{animation:spin 1s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* Domain info */
.bo-domain-name{font-family:var(--mono);font-size:14px;font-weight:700;color:var(--text)}
.bo-domain-name span{color:var(--text3);font-weight:400}
.bo-meta-row{display:flex;align-items:center;gap:8px;margin-top:4px;flex-wrap:wrap}

/* Status pill */
.status-pill{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;padding:2px 8px;border-radius:4px;display:inline-flex;align-items:center;gap:4px}
.sp-dot{width:5px;height:5px;border-radius:50%;background:currentColor;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}

.priority-chip{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;padding:2px 6px;border-radius:3px}
.pc-standard{background:var(--bg4);color:var(--text3)}
.pc-express{background:var(--purple-bg);color:var(--purple)}

.bo-date-info{font-size:11px;color:var(--text3);font-family:var(--mono)}
.bo-date-info b{color:var(--amber)}
.bo-date-info.urgent b{color:var(--coral)}

/* Credits col */
.bo-credits{text-align:right;flex-shrink:0}
.bo-credits-num{font-family:var(--mono);font-size:13px;font-weight:700;color:var(--text)}
.bo-credits-lbl{font-size:10px;color:var(--text3);margin-top:1px}
.credits-reserved-tag{font-size:9px;font-family:var(--mono);color:var(--amber);background:var(--amber-bg);padding:1px 5px;border-radius:3px;margin-top:3px;display:inline-block}

/* Actions col */
.bo-actions{display:flex;align-items:center;gap:6px;flex-shrink:0}
.bo-action-btn{display:flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;background:none;border:1px solid var(--border);color:var(--text3);font-size:12px;cursor:pointer;transition:all .13s;text-decoration:none}
.bo-action-btn:hover{background:var(--bg3);border-color:var(--border2);color:var(--text)}
.bo-action-btn.cancel:hover{background:var(--coral-bg);border-color:rgba(232,89,60,.25);color:var(--coral)}
.bo-action-btn.check:hover{background:var(--green-bg);border-color:rgba(29,158,117,.25);color:var(--green2)}

/* Expanded details */
.bo-card-details{border-top:1px solid var(--border);padding:14px 18px;background:var(--bg3);display:none}
.bo-card-details.open{display:block}
.bo-details-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.bd-item-label{font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:var(--text3);margin-bottom:4px}
.bd-item-value{font-size:12px;font-family:var(--mono);color:var(--text)}
.bd-item-value.na{color:var(--text3)}

/* Progress timeline */
.bo-timeline{display:flex;align-items:center;gap:0;margin-top:14px}
.tl-step{display:flex;flex-direction:column;align-items:center;flex:1;position:relative}
.tl-step::after{content:'';position:absolute;top:10px;left:50%;right:-50%;height:2px;background:var(--border)}
.tl-step:last-child::after{display:none}
.tl-step.done::after{background:var(--green)}
.tl-dot{width:20px;height:20px;border-radius:50%;border:2px solid var(--border);background:var(--bg);display:flex;align-items:center;justify-content:center;font-size:9px;z-index:1;margin-bottom:5px}
.tl-step.done .tl-dot{border-color:var(--green);background:black;color:var(--green2)}
.tl-step.active .tl-dot{border-color:var(--amber);background:var(--amber-bg);color:var(--amber);animation:pulse 2s infinite}
.tl-step.failed .tl-dot{border-color:var(--coral);background:var(--coral-bg);color:var(--coral)}
.tl-label{font-size:9px;color:var(--text3);text-transform:uppercase;letter-spacing:.08em;text-align:center}
.tl-step.done .tl-label{color:var(--green2)}
.tl-step.active .tl-label{color:var(--amber)}

/* ── Empty state ─── */
.empty-state{display:flex;flex-direction:column;align-items:center;gap:12px;text-align:center;padding:64px 24px;background:var(--bg2);border:1px solid var(--border);border-radius:14px}
.empty-icon-wrap{width:60px;height:60px;border-radius:16px;background:var(--bg3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:24px;color:var(--text3);margin-bottom:4px}
.empty-title{font-size:15px;font-weight:700;color:var(--text)}
.empty-sub{font-size:13px;color:var(--text2);max-width:300px;line-height:1.6}

/* ── How it works ─── */
.how-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-top:28px}
.how-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.how-title{font-size:12px;font-weight:700;color:var(--text)}
.how-body{padding:20px;display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
.how-step{display:flex;flex-direction:column;gap:8px}
.how-step-num{width:28px;height:28px;border-radius:7px;background:var(--green-bg);border:1px solid rgba(29,158,117,.2);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;font-family:var(--mono);color:var(--green2)}
.how-step-title{font-size:12px;font-weight:700;color:var(--text)}
.how-step-desc{font-size:11px;color:var(--text2);line-height:1.6}

/* ── Confirm modal ─── */
.modal-overlay{position:fixed;inset:0;z-index:200;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--border2);border-radius:16px;padding:28px;max-width:380px;width:90%;transform:scale(.95);transition:transform .2s}
.modal-overlay.open .modal{transform:scale(1)}
.modal-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:14px;background:var(--amber-bg);color:var(--amber)}
.modal-title{font-size:16px;font-weight:700;color:var(--text);margin-bottom:8px}
.modal-body{font-size:13px;color:var(--text2);line-height:1.6;margin-bottom:20px}
.modal-domain{font-family:var(--mono);color:var(--text);font-weight:700}
.modal-actions{display:flex;gap:10px;justify-content:flex-end}
.modal-cancel-btn{background:none;border:1px solid var(--border2);border-radius:8px;padding:9px 18px;font-family:var(--display);font-size:12px;color:var(--text2);cursor:pointer;transition:all .15s}
.modal-cancel-btn:hover{background:var(--bg3);color:var(--text)}
.modal-confirm-btn{background:var(--coral);color:#fff;border:none;border-radius:8px;padding:9px 18px;font-family:var(--display);font-size:12px;font-weight:700;cursor:pointer;transition:opacity .15s}
.modal-confirm-btn:hover{opacity:.85}

/* ── Toast ─── */
.toast{position:fixed;bottom:28px;right:28px;z-index:999;background:var(--bg3);border:1px solid var(--border2);border-radius:10px;padding:12px 18px;font-size:13px;color:var(--text);box-shadow:0 8px 32px rgba(0,0,0,.4);transform:translateY(20px);opacity:0;transition:all .3s ease;max-width:340px;display:flex;align-items:center;gap:9px}
.toast.show{transform:translateY(0);opacity:1}
.toast.success{border-color:rgba(29,158,117,.3)}
.toast.error{border-color:rgba(232,89,60,.3)}

/* ── Responsive ─── */
@media(max-width:1100px){.how-body{grid-template-columns:repeat(2,1fr)}.bo-details-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:960px){.stats-row{grid-template-columns:repeat(3,1fr)}}
@media(max-width:768px){
  .main{margin-left:0}.mobile-menu-btn{display:flex}
  .content{padding:20px 16px 50px}
  .stats-row{grid-template-columns:repeat(2,1fr)}
  .stats-row .stat-chip:last-child{grid-column:1/-1}
  .bo-card-main{grid-template-columns:auto 1fr auto;gap:10px}
  .bo-credits{display:none}
  .how-body{grid-template-columns:1fr 1fr}
  .controls-bar{flex-direction:column;align-items:flex-start}
  .credits-pill{display:none}
}
@media(max-width:480px){
  .stats-row{grid-template-columns:1fr 1fr}
  .place-form{flex-direction:column;align-items:stretch}
  .priority-toggle{align-self:flex-start}
  .how-body{grid-template-columns:1fr}
  .bo-details-grid{grid-template-columns:1fr 1fr}
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
        <span style="color:var(--text);">Backorders</span>
      </div>
    </div>
    <div class="topbar-right">
      <div class="credits-pill">
        <i class="fas fa-bolt" style="color:var(--amber);font-size:11px;"></i>
        <b><?= $availableCredits ?></b> credits available
        <?php if ($reserved > 0): ?>
        <span style="color:var(--text3);">· <?= $reserved ?> reserved</span>
        <?php endif; ?>
      </div>
      <a href="<?= htmlspecialchars($assetUrl('alerts.php')) ?>" class="topbar-btn" title="Alerts">
        <i class="fas fa-bell"></i>
      </a>
      <a href="<?= htmlspecialchars($assetUrl('billing.php?topup=1')) ?>" class="topbar-btn" title="Top up credits">
        <i class="fas fa-plus"></i>
      </a>
    </div>
  </div>

  <div class="content">

    <!-- Page header -->
    <div class="page-header">
      <div>
        <div class="page-eyebrow">Domain acquisition</div>
        <div class="page-title">Backorders.</div>
        <div class="page-sub">
          <?php if ((int)$stats['active_count'] > 0): ?>
            <em><?= $stats['active_count'] ?> active</em> backorder<?= $stats['active_count'] != 1 ? 's' : '' ?> in queue
            <?php if ((int)$stats['won_count'] > 0): ?> · <em><?= $stats['won_count'] ?> won</em> so far<?php endif; ?>.
          <?php else: ?>
            Queue domains you want to catch the moment they expire.
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Upgrade gate -->
    <?php if (!$canBackorder): ?>
    <div class="upgrade-gate">
      <div class="gate-icon">⏰</div>
      <div class="gate-title">Backorders require a Pro plan</div>
      <div class="gate-sub">
        Upgrade to Pro to place backorders on expiring domains. We monitor the drop window and attempt to capture the domain the instant it's released — before anyone else.
      </div>
      <a href="<?= htmlspecialchars($assetUrl('billing.php?plan=pro')) ?>" class="gate-cta">
        <i class="fas fa-bolt" style="font-size:10px;"></i> Upgrade to Pro — $9/mo
      </a>
    </div>
    <?php endif; ?>

    <!-- Stats chips -->
    <div class="stats-row">
      <?php
      $chips = [
        ['label'=>'All',      'count'=>(int)$stats['total'],          'icon'=>'fa-clock',       'cls'=>'sci-all',   'f'=>'all'],
        ['label'=>'Active',   'count'=>(int)$stats['active_count'],   'icon'=>'fa-eye',          'cls'=>'sci-amber', 'f'=>'active'],
        ['label'=>'Won',      'count'=>(int)$stats['won_count'],      'icon'=>'fa-trophy',       'cls'=>'sci-green', 'f'=>'won'],
        ['label'=>'Lost',     'count'=>(int)$stats['lost_count'],     'icon'=>'fa-times-circle', 'cls'=>'sci-coral', 'f'=>'lost'],
        ['label'=>'Canceled', 'count'=>(int)$stats['canceled_count'], 'icon'=>'fa-ban',          'cls'=>'sci-grey',  'f'=>'canceled'],
      ];
      foreach ($chips as $chip):
      ?>
      <a href="?filter=<?= $chip['f'] ?>" class="stat-chip <?= $filter === $chip['f'] ? 'active' : '' ?>">
        <div class="stat-chip-icon <?= $chip['cls'] ?>"><i class="fas <?= $chip['icon'] ?>"></i></div>
        <div>
          <div class="stat-chip-num"><?= $chip['count'] ?></div>
          <div class="stat-chip-lbl"><?= $chip['label'] ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Place backorder -->
    <div class="place-card">
      <div class="place-card-title">
        <i class="fas fa-plus-circle" style="color:var(--green2);font-size:13px;"></i>
        Place a backorder
      </div>
      <div class="place-card-sub">Enter a domain you want to catch when it expires. We'll monitor the drop and attempt capture automatically.</div>

      <div class="place-form">
        <div class="place-input-wrap">
          <input class="place-input" type="text" id="boInput"
                 placeholder="<?= $canBackorder ? 'domain.com or domain.ng' : 'Requires Pro plan' ?>"
                 <?= !$canBackorder ? 'disabled' : '' ?>
                 autocomplete="off" maxlength="253">
        </div>

        <div class="priority-toggle" id="priorityToggle">
          <button class="ptog active" id="btnStandard" onclick="setPriority('standard')" <?= !$canBackorder ? 'disabled' : '' ?>>
            Standard · <span class="cost-badge" id="standardCost">5 cr</span>
          </button>
          <button class="ptog" id="btnExpress" onclick="setPriority('express')" <?= !$canBackorder ? 'disabled' : '' ?>>
            Express · <span class="cost-badge express">10 cr</span>
          </button>
        </div>

        <button class="place-btn" id="placeBtn" onclick="placeBackorder()" <?= !$canBackorder ? 'disabled' : '' ?>>
          <i class="fas fa-clock" style="font-size:11px;"></i> Place backorder
        </button>
      </div>

      <div class="place-hint">
        <span><i class="fas fa-info-circle"></i> Standard: best-effort capture at drop time.</span>
        <span><i class="fas fa-bolt" style="color:var(--purple);"></i> Express: higher priority queue, faster attempt.</span>
        <span><i class="fas fa-coins" style="color:var(--amber);"></i> Credits reserved on placement, deducted only if won.</span>
        <?php if ($availableCredits < 5 && $canBackorder): ?>
        <span style="color:var(--coral);"><i class="fas fa-exclamation-triangle"></i>
          Low credits — <a href="<?= htmlspecialchars($assetUrl('billing.php?topup=1')) ?>" style="color:var(--amber);text-decoration:none;">top up</a> to place backorders.
        </span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Controls -->
    <div class="controls-bar">
      <div class="controls-left">
        <div class="filter-tabs">
          <?php foreach (['all'=>'All','active'=>'Active','won'=>'Won','lost'=>'Lost','canceled'=>'Canceled'] as $f=>$lbl): ?>
          <a href="?filter=<?= $f ?>" class="ftab <?= $filter===$f?'active':'' ?>"><?= $lbl ?></a>
          <?php endforeach; ?>
        </div>
        <span class="count-label"><em id="visibleCount"><?= count($backorders) ?></em> backorder<?= count($backorders)!==1?'s':'' ?></span>
      </div>
    </div>

    <!-- Backorder list -->
    <div class="bo-list" id="boList">

      <?php if (!empty($backorders)): ?>
        <?php foreach ($backorders as $bo):
          $sm       = $statusMeta[$bo['status']] ?? $statusMeta['pending'];
          $isActive = in_array($bo['status'], ['pending','watching','processing']);
          $daysLeft = $bo['estimated_drop_date'] ? daysUntil($bo['estimated_drop_date']) : null;
          $parts    = explode('.', $bo['domain_name']);
          $sld      = $parts[0];
          $tldPart  = '.' . implode('.', array_slice($parts, 1));

          // Timeline steps
          $tlSteps = [
            'pending'    => ['Placed','Watching','Processing','Outcome'],
            'watching'   => ['Placed','Watching','Processing','Outcome'],
            'processing' => ['Placed','Watching','Processing','Outcome'],
            'won'        => ['Placed','Watched','Processed','Won ✓'],
            'lost'       => ['Placed','Watched','Processed','Lost'],
            'canceled'   => ['Placed','—','—','Canceled'],
            'expired'    => ['Placed','Watched','—','Expired'],
          ];
          $tlCurrent = array_search($bo['status'], ['pending','watching','processing','won','lost','canceled','expired']);

          $tlStateMap = [
            'pending'    => [1, 0, 0, 0],
            'watching'   => [2, 2, 0, 0],
            'processing' => [2, 2, 2, 0],
            'won'        => [2, 2, 2, 2],
            'lost'       => [2, 2, 2, 3],
            'canceled'   => [2, 3, 3, 3],
            'expired'    => [2, 2, 3, 3],
          ];
          // 0=todo, 1=active, 2=done, 3=failed
          $tlStates = $tlStateMap[$bo['status']] ?? [0,0,0,0];
          $tlLabels = ['Placed','Watching','Processing','Outcome'];
          $tlIcons  = ['fa-check','fa-eye','fa-bolt','fa-trophy'];
        ?>
        <div class="bo-card status-<?= $bo['status'] ?>" id="bo-<?= (int)$bo['id'] ?>">

          <!-- Main row -->
          <div class="bo-card-main">

            <!-- Status icon -->
            <div class="bo-status-icon" style="background:var(<?= $sm['bg'] ?>);color:var(<?= $sm['color'] ?>);">
              <i class="fas <?= $sm['icon'] ?>"></i>
            </div>

            <!-- Domain + meta -->
            <div>
              <div class="bo-domain-name">
                <?= htmlspecialchars($sld) ?><span><?= htmlspecialchars($tldPart) ?></span>
              </div>
              <div class="bo-meta-row">
                <span class="status-pill" style="background:var(<?= $sm['bg'] ?>);color:var(<?= $sm['color'] ?>);">
                  <?php if (in_array($bo['status'], ['pending','watching','processing'])): ?><span class="sp-dot"></span><?php endif; ?>
                  <?= $sm['label'] ?>
                </span>
                <span class="priority-chip pc-<?= $bo['priority'] ?>">
                  <?= $bo['priority'] === 'express' ? '⚡ Express' : 'Standard' ?>
                </span>
                <?php if ($daysLeft !== null && $isActive): ?>
                <span class="bo-date-info <?= $daysLeft <= 10 ? 'urgent' : '' ?>">
                  Drop est. <b><?= $daysLeft > 0 ? "in {$daysLeft}d" : 'imminent' ?></b>
                  · <?= date('M j, Y', strtotime($bo['estimated_drop_date'])) ?>
                </span>
                <?php elseif ($bo['won_at']): ?>
                <span style="font-size:11px;font-family:var(--mono);color:var(--green2);">
                  Won <?= date('M j, Y', strtotime($bo['won_at'])) ?>
                </span>
                <?php else: ?>
                <span style="font-size:11px;color:var(--text3);font-family:var(--mono);">
                  Placed <?= timeAgo(strtotime($bo['created_at'])) ?>
                </span>
                <?php endif; ?>
              </div>
            </div>

            <!-- Credits -->
            <div class="bo-credits">
              <div class="bo-credits-num"><?= (int)$bo['credits_spent'] ?></div>
              <div class="bo-credits-lbl">credits</div>
              <?php if ($isActive): ?>
              <span class="credits-reserved-tag">reserved</span>
              <?php elseif ($bo['status'] === 'won'): ?>
              <span style="font-size:9px;font-family:var(--mono);color:var(--green2);background:var(--green-bg);padding:1px 5px;border-radius:3px;display:inline-block;margin-top:3px;">deducted</span>
              <?php elseif ($bo['status'] === 'canceled'): ?>
              <span style="font-size:9px;font-family:var(--mono);color:var(--text3);background:var(--bg4);padding:1px 5px;border-radius:3px;display:inline-block;margin-top:3px;">released</span>
              <?php endif; ?>
            </div>

            <!-- Actions -->
            <div class="bo-actions">
              <button class="bo-action-btn" onclick="toggleDetails(<?= (int)$bo['id'] ?>)" title="View details">
                <i class="fas fa-chevron-down" id="chevron-<?= (int)$bo['id'] ?>"></i>
              </button>
              <a href="<?= htmlspecialchars($assetUrl('index.php')) ?>?q=<?= urlencode($bo['domain_name']) ?>"
                 class="bo-action-btn check" title="Check domain">
                <i class="fas fa-search"></i>
              </a>
              <?php if ($isActive): ?>
              <button class="bo-action-btn cancel"
                      onclick="confirmCancel(<?= (int)$bo['id'] ?>, '<?= htmlspecialchars($bo['domain_name'], ENT_QUOTES) ?>')"
                      title="Cancel backorder">
                <i class="fas fa-times"></i>
              </button>
              <?php endif; ?>
            </div>

          </div><!-- /.bo-card-main -->

          <!-- Expandable details -->
          <div class="bo-card-details" id="details-<?= (int)$bo['id'] ?>">

            <!-- Progress timeline -->
            <div class="bo-timeline" style="margin-bottom:16px;">
              <?php foreach ($tlLabels as $idx => $tlLabel):
                $state = $tlStates[$idx] ?? 0;
                $cls   = match($state) { 2 => 'done', 1 => 'active', 3 => 'failed', default => '' };
                $icon  = match($state) { 2 => 'fa-check', 3 => 'fa-times', 1 => 'fa-circle', default => '' };
              ?>
              <div class="tl-step <?= $cls ?>">
                <div class="tl-dot">
                  <?php if ($icon): ?><i class="fas <?= $icon ?>"></i><?php endif; ?>
                </div>
                <div class="tl-label"><?= $tlLabel ?></div>
              </div>
              <?php endforeach; ?>
            </div>

            <!-- Details grid -->
            <div class="bo-details-grid">
              <div>
                <div class="bd-item-label">Placed</div>
                <div class="bd-item-value"><?= date('M j, Y · H:i', strtotime($bo['created_at'])) ?></div>
              </div>
              <div>
                <div class="bd-item-label">TLD</div>
                <div class="bd-item-value">.<?= htmlspecialchars($bo['tld']) ?></div>
              </div>
              <div>
                <div class="bd-item-label">WHOIS expiry</div>
                <div class="bd-item-value <?= $bo['whois_expiry_date'] ? '' : 'na' ?>">
                  <?= $bo['whois_expiry_date'] ? date('M j, Y', strtotime($bo['whois_expiry_date'])) : 'Not fetched yet' ?>
                </div>
              </div>
              <div>
                <div class="bd-item-label">Estimated drop</div>
                <div class="bd-item-value <?= $bo['estimated_drop_date'] ? '' : 'na' ?>">
                  <?= $bo['estimated_drop_date'] ? date('M j, Y', strtotime($bo['estimated_drop_date'])) : 'Pending WHOIS' ?>
                </div>
              </div>
              <div>
                <div class="bd-item-label">Registrar</div>
                <div class="bd-item-value <?= $bo['registrar'] ? '' : 'na' ?>">
                  <?= $bo['registrar'] ? htmlspecialchars($bo['registrar']) : 'Not fetched yet' ?>
                </div>
              </div>
              <div>
                <div class="bd-item-label">Priority</div>
                <div class="bd-item-value"><?= ucfirst($bo['priority']) ?></div>
              </div>
              <div>
                <div class="bd-item-label">Credits</div>
                <div class="bd-item-value"><?= (int)$bo['credits_spent'] ?> (<?= $isActive ? 'reserved' : ($bo['status']==='won' ? 'deducted' : 'released') ?>)</div>
              </div>
              <div>
                <div class="bd-item-label">Email alert</div>
                <div class="bd-item-value"><?= $bo['notify_email'] ? 'Enabled' : 'Disabled' ?></div>
              </div>
              <?php if (!empty($bo['notes'])): ?>
              <div style="grid-column:1/-1;">
                <div class="bd-item-label">Notes</div>
                <div class="bd-item-value"><?= htmlspecialchars($bo['notes']) ?></div>
              </div>
              <?php endif; ?>
            </div>

          </div><!-- /.bo-card-details -->
        </div><!-- /.bo-card -->
        <?php endforeach; ?>

      <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon-wrap"><i class="fas fa-clock"></i></div>
          <div class="empty-title">
            <?= $filter === 'won' ? 'No won backorders yet'
              : ($filter === 'active' ? 'No active backorders'
              : 'No backorders placed') ?>
          </div>
          <div class="empty-sub">
            <?php if (!$canBackorder): ?>
              Upgrade to Pro to start placing backorders on expiring domains.
            <?php elseif ($filter === 'won'): ?>
              Backorders you win will appear here.
            <?php else: ?>
              Enter a domain above to queue it for capture when it expires.
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

    </div><!-- /.bo-list -->

    <!-- How it works -->
    <div class="how-card">
      <div class="how-header">
        <i class="fas fa-question-circle" style="color:var(--green2);font-size:13px;"></i>
        <span class="how-title">How backorders work</span>
      </div>
      <div class="how-body">
        <div class="how-step">
          <div class="how-step-num">1</div>
          <div class="how-step-title">Place the order</div>
          <div class="how-step-desc">Enter any domain currently registered. 5 credits are reserved (10 for Express). Credits are only deducted if you win.</div>
        </div>
        <div class="how-step">
          <div class="how-step-num">2</div>
          <div class="how-step-title">We monitor</div>
          <div class="how-step-desc">We check WHOIS daily to determine the exact expiry and grace period end date for the domain.</div>
        </div>
        <div class="how-step">
          <div class="how-step-num">3</div>
          <div class="how-step-title">Drop attempt</div>
          <div class="how-step-desc">At the drop window, our system attempts to register the domain the moment it's released by the registry.</div>
        </div>
        <div class="how-step">
          <div class="how-step-num">4</div>
          <div class="how-step-title">You're notified</div>
          <div class="how-step-desc">Win or lose, you'll get an email alert. On a win, the domain is registered to your account details. Credits deducted only on win.</div>
        </div>
      </div>
    </div>

  </div><!-- /.content -->
</main>

<!-- Cancel confirm modal -->
<div class="modal-overlay" id="cancelModal">
  <div class="modal">
    <div class="modal-icon"><i class="fas fa-exclamation-triangle"></i></div>
    <div class="modal-title">Cancel this backorder?</div>
    <div class="modal-body">
      Cancel the backorder for <span class="modal-domain" id="cancelDomainName"></span>?
      Your reserved credits will be returned to your balance immediately.
    </div>
    <div class="modal-actions">
      <button class="modal-cancel-btn" onclick="closeModal()">Keep it</button>
      <button class="modal-confirm-btn" onclick="executeCancel()">Yes, cancel</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast">
  <i class="fas fa-check-circle" id="toastIcon" style="font-size:14px;flex-shrink:0;color:var(--green2);"></i>
  <span id="toastText"></span>
</div>

<script>
const API_URL = window.location.pathname;
let priority  = 'standard';
let pendingCancelId = null;

// ── Priority toggle ───────────────────────────────────────
function setPriority(p) {
  priority = p;
  document.getElementById('btnStandard').classList.toggle('active', p === 'standard');
  document.getElementById('btnExpress').classList.toggle('active',  p === 'express');
}

// ── Place backorder ───────────────────────────────────────
async function placeBackorder() {
  const input = document.getElementById('boInput');
  const btn   = document.getElementById('placeBtn');
  let   val   = input.value.trim().toLowerCase().replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '');
  if (!val) { input.focus(); return; }
  if (!val.includes('.')) val += '.com';

  btn.disabled  = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size:11px;"></i> Placing…';

  try {
    const res  = await post({ action: 'place', domain: val, priority });
    const data = await res.json();

    if (data.success) {
      input.value = '';
      showToast(data.message, 'success');
      setTimeout(() => location.reload(), 1200);
    } else if (data.requiresUpgrade) {
      showToast('Backorders require a Pro plan.', 'error');
      setTimeout(() => window.location.href = '<?= htmlspecialchars($assetUrl("billing.php?plan=pro")) ?>', 1500);
    } else if (data.insufficientCredits) {
      showToast(data.message, 'error');
      setTimeout(() => window.location.href = '<?= htmlspecialchars($assetUrl("billing.php?topup=1")) ?>', 2000);
    } else {
      showToast(data.message || 'Failed to place backorder.', 'error');
    }
  } catch {
    showToast('Network error. Please try again.', 'error');
  } finally {
    btn.disabled  = false;
    btn.innerHTML = '<i class="fas fa-clock" style="font-size:11px;"></i> Place backorder';
  }
}

// ── Toggle details ────────────────────────────────────────
function toggleDetails(id) {
  const panel   = document.getElementById('details-' + id);
  const chevron = document.getElementById('chevron-' + id);
  const isOpen  = panel.classList.contains('open');
  panel.classList.toggle('open', !isOpen);
  chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
  chevron.style.transition = 'transform .2s';
}

// ── Cancel modal ──────────────────────────────────────────
function confirmCancel(id, domain) {
  pendingCancelId = id;
  document.getElementById('cancelDomainName').textContent = domain;
  document.getElementById('cancelModal').classList.add('open');
}
function closeModal() {
  document.getElementById('cancelModal').classList.remove('open');
  pendingCancelId = null;
}
document.getElementById('cancelModal').addEventListener('click', e => {
  if (e.target === e.currentTarget) closeModal();
});
async function executeCancel() {
  if (!pendingCancelId) return;
  closeModal();
  const res  = await post({ action: 'cancel', id: pendingCancelId });
  const data = await res.json();
  if (data.success) {
    const card = document.getElementById('bo-' + pendingCancelId);
    if (card) {
      card.style.transition = 'opacity .3s, transform .3s';
      card.style.opacity    = '0';
      card.style.transform  = 'translateX(20px)';
      setTimeout(() => { card.remove(); updateCount(); }, 320);
    }
    showToast(data.message, 'success');
  } else {
    showToast(data.message || 'Could not cancel.', 'error');
  }
}

// ── Helpers ───────────────────────────────────────────────
function updateCount() {
  const el = document.getElementById('visibleCount');
  if (el) el.textContent = document.querySelectorAll('.bo-card').length;
}
function post(body) {
  return fetch(API_URL, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body:    JSON.stringify(body),
  });
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
  t._timer = setTimeout(() => t.classList.remove('show'), 3800);
}

// ── Keyboard ──────────────────────────────────────────────
document.getElementById('boInput')?.addEventListener('keydown', e => {
  if (e.key === 'Enter') placeBackorder();
});

// ── Mobile sidebar ────────────────────────────────────────
function openSidebar()  { document.getElementById('cdSidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('show'); }
function closeSidebar() { document.getElementById('cdSidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('show'); }
</script>

</body>
</html>