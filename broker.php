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

$conn->query("
    CREATE TABLE IF NOT EXISTS broker_requests (
        id                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        user_id             INT  NOT NULL,
        domain_name         VARCHAR(253)     NOT NULL,
        tld                 VARCHAR(63)      NOT NULL,

        -- Acquisition details
        budget_kobo         INT UNSIGNED     NOT NULL DEFAULT 0  COMMENT 'Max budget in kobo',
        budget_flexible     TINYINT(1)       NOT NULL DEFAULT 0,
        purpose             VARCHAR(64)      NULL     COMMENT 'startup, rebrand, investment, etc.',
        urgency             ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
        notes               TEXT             NULL     COMMENT 'Additional context from user',

        -- Broker workflow
        status              ENUM(
                                'submitted',     -- just received, not yet reviewed
                                'researching',   -- team looking into current owner
                                'outreach',      -- contact attempted with owner
                                'negotiating',   -- active price negotiation
                                'offer_made',    -- formal offer sent to owner
                                'accepted',      -- owner accepted offer
                                'transfer',      -- domain transfer in progress
                                'completed',     -- transfer done, domain delivered
                                'declined',      -- owner not interested / not for sale
                                'canceled',      -- user canceled the request
                                'on_hold'        -- paused — more info needed
                            ) NOT NULL DEFAULT 'submitted',

        -- Pricing & commission
        broker_fee_kobo     INT UNSIGNED     NULL     COMMENT 'CheckDomain service fee in kobo',
        agreed_price_kobo   INT UNSIGNED     NULL     COMMENT 'Price agreed with seller',
        final_price_kobo    INT UNSIGNED     NULL     COMMENT 'Total paid including fees',
        commission_pct      DECIMAL(5,2)     NULL     COMMENT 'Commission % taken',

        -- Payment
        payment_id          INT UNSIGNED     NULL,

        -- Timeline
        contacted_owner_at  TIMESTAMP        NULL,
        offer_made_at       TIMESTAMP        NULL,
        accepted_at         TIMESTAMP        NULL,
        completed_at        TIMESTAMP        NULL,

        -- Admin notes (internal)
        admin_notes         TEXT             NULL,
        assigned_to         VARCHAR(100)     NULL     COMMENT 'Broker team member handle',

        -- Communication
        latest_update       TEXT             NULL     COMMENT 'Latest public update shown to user',
        latest_update_at    TIMESTAMP        NULL,

        created_at          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        PRIMARY KEY (id),
        INDEX idx_br_user   (user_id),
        INDEX idx_br_domain (domain_name),
        INDEX idx_br_status (status),
        CONSTRAINT fk_br_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Fetch user ─────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, email, full_name, plan, credits, billing_email, billing_name, billing_phone
    FROM users WHERE id = ?
");
$stmt->bind_param("i", $session['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { header('Location: logout.php'); exit(); }

$userPlan  = $user['plan']    ?? 'free';
$credits   = (int)($user['credits'] ?? 0);
$canBroker = ($userPlan === 'elite');

// ── Handle AJAX ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    ob_start();
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    // ── Submit new request ──────────────────────────────────
    if ($action === 'submit') {
        if (!$canBroker) {
            ob_end_clean();
            echo json_encode(['success'=>false,'requiresUpgrade'=>true,'message'=>'Broker service requires an Elite plan.']);
            exit();
        }

        $raw    = strtolower(trim($input['domain'] ?? ''));
        $raw    = preg_replace('#^https?://(www\.)?#', '', $raw);
        $domain = rtrim($raw, '/');

        if (!$domain || !str_contains($domain, '.') ||
            !preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            ob_end_clean(); echo json_encode(['success'=>false,'message'=>'Enter a valid domain name.']); exit();
        }

        $budgetNaira   = max(0, (int)($input['budget'] ?? 0));
        $budgetKobo    = $budgetNaira * 100;
        $budgetFlex    = !empty($input['budget_flexible']) ? 1 : 0;
        $purpose       = in_array($input['purpose'] ?? '', ['startup','rebrand','investment','portfolio','personal','other'])
                         ? $input['purpose'] : 'other';
        $urgency       = in_array($input['urgency'] ?? '', ['low','medium','high','urgent'])
                         ? $input['urgency'] : 'medium';
        $notes         = substr(strip_tags(trim($input['notes'] ?? '')), 0, 2000);
        $tld           = implode('.', array_slice(explode('.', $domain), 1));

        // Check for existing active request on same domain
        $dupStmt = $conn->prepare("
            SELECT id, status FROM broker_requests
            WHERE user_id=? AND domain_name=?
            AND status NOT IN ('completed','declined','canceled')
            LIMIT 1
        ");
        $dupStmt->bind_param("is", $session['user_id'], $domain);
        $dupStmt->execute();
        $existing = $dupStmt->get_result()->fetch_assoc();
        $dupStmt->close();
        if ($existing) {
            ob_end_clean();
            echo json_encode(['success'=>false,'message'=>"You already have an active broker request for {$domain}. (Request #{$existing['id']})"]);
            exit();
        }

        $insStmt = $conn->prepare("
            INSERT INTO broker_requests
              (user_id, domain_name, tld, budget_kobo, budget_flexible, purpose, urgency, notes, status)
            VALUES (?,?,?,?,?,?,?,?,'submitted')
        ");
        $insStmt->bind_param("issiisss",
            $session['user_id'], $domain, $tld,
            $budgetKobo, $budgetFlex, $purpose, $urgency, $notes
        );
        $insStmt->execute();
        $newId = $conn->insert_id;
        $insStmt->close();

        ob_end_clean();
        echo json_encode([
            'success' => true,
            'id'      => $newId,
            'domain'  => $domain,
            'message' => "Broker request submitted for {$domain}. Our team will contact you within 24 hours.",
        ]);
        exit();
    }

    // ── Cancel request ──────────────────────────────────────
    if ($action === 'cancel') {
        $id = (int)($input['id'] ?? 0);
        $brStmt = $conn->prepare("
            SELECT id, status FROM broker_requests
            WHERE id=? AND user_id=?
        ");
        $brStmt->bind_param("ii", $id, $session['user_id']);
        $brStmt->execute();
        $br = $brStmt->get_result()->fetch_assoc();
        $brStmt->close();

        if (!$br) {
            ob_end_clean(); echo json_encode(['success'=>false,'message'=>'Request not found.']); exit();
        }
        if (in_array($br['status'], ['completed','accepted','transfer'])) {
            ob_end_clean(); echo json_encode(['success'=>false,'message'=>'This request cannot be canceled at this stage.']); exit();
        }

        $upd = $conn->prepare("UPDATE broker_requests SET status='canceled', updated_at=NOW() WHERE id=?");
        $upd->bind_param("i", $id); $upd->execute(); $upd->close();

        ob_end_clean();
        echo json_encode(['success'=>true,'message'=>'Broker request canceled.']);
        exit();
    }

    ob_end_clean();
    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
    exit();
}

// ── Fetch requests ─────────────────────────────────────────
$filter = in_array($_GET['filter'] ?? '', ['active','completed','all']) ? $_GET['filter'] : 'all';
$whereMap = [
    'active'    => "status NOT IN ('completed','declined','canceled')",
    'completed' => "status IN ('completed','accepted','transfer')",
    'all'       => "1=1",
];

$brStmt = $conn->prepare("
    SELECT * FROM broker_requests
    WHERE user_id=? AND {$whereMap[$filter]}
    ORDER BY
        CASE status
            WHEN 'negotiating'  THEN 0
            WHEN 'offer_made'   THEN 1
            WHEN 'outreach'     THEN 2
            WHEN 'researching'  THEN 3
            WHEN 'submitted'    THEN 4
            WHEN 'transfer'     THEN 5
            WHEN 'accepted'     THEN 6
            WHEN 'completed'    THEN 7
            ELSE 8 END,
        created_at DESC
    LIMIT 30
");
$brStmt->bind_param("i", $session['user_id']);
$brStmt->execute();
$brResult = $brStmt->get_result();
$requests = [];
while ($row = $brResult->fetch_assoc()) { $requests[] = $row; }
$brStmt->close();

// ── Stats ───────────────────────────────────────────────────
$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) as total,
        SUM(status NOT IN ('completed','declined','canceled')) as active_count,
        SUM(status = 'completed') as completed_count,
        SUM(status IN ('negotiating','offer_made')) as negotiating_count,
        SUM(status = 'declined') as declined_count
    FROM broker_requests WHERE user_id=?
");
$statsStmt->bind_param("i", $session['user_id']);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

// ── Sidebar counts ─────────────────────────────────────────
$watchStmt = $conn->prepare("SELECT COUNT(*) as c FROM pinned_domains WHERE user_id=? AND status='active'");
$watchStmt->bind_param("i", $session['user_id']); $watchStmt->execute();
$watchlistCount = (int)$watchStmt->get_result()->fetch_assoc()['c']; $watchStmt->close();

$alertCount = 0;
$alStmt = $conn->prepare("SELECT COUNT(*) as c FROM domain_alerts WHERE user_id=? AND status='unread'");
if ($alStmt) { $alStmt->bind_param("i", $session['user_id']); $alStmt->execute(); $alertCount = (int)$alStmt->get_result()->fetch_assoc()['c']; $alStmt->close(); }

$conn->close();

// ── Display meta ───────────────────────────────────────────
$userName  = trim($user['full_name'] ?? '') ?: explode('@', $user['email'])[0];
$firstName = explode(' ', $userName)[0];
$initials  = strtoupper(substr($userName,0,1).(strpos($userName,' ')!==false?substr($userName,strpos($userName,' ')+1,1):''));

$activePage  = 'broker';
$prefill     = htmlspecialchars(preg_replace('#^https?://(www\.)?#','',trim($_GET['domain'] ?? '')), ENT_QUOTES);

// ── Status display meta ─────────────────────────────────────
$statusMeta = [
    'submitted'   => ['icon'=>'fa-paper-plane',   'color'=>'--blue',    'bg'=>'--blue-bg',    'label'=>'Submitted',    'step'=>0],
    'researching' => ['icon'=>'fa-search',         'color'=>'--purple',  'bg'=>'--purple-bg',  'label'=>'Researching',  'step'=>1],
    'outreach'    => ['icon'=>'fa-envelope',        'color'=>'--amber',   'bg'=>'--amber-bg',   'label'=>'Outreach',     'step'=>2],
    'negotiating' => ['icon'=>'fa-comments-dollar', 'color'=>'--amber',  'bg'=>'--amber-bg',   'label'=>'Negotiating',  'step'=>3],
    'offer_made'  => ['icon'=>'fa-handshake',       'color'=>'--green2', 'bg'=>'--green-bg',   'label'=>'Offer Made',   'step'=>3],
    'accepted'    => ['icon'=>'fa-check-circle',    'color'=>'--green2', 'bg'=>'--green-bg',   'label'=>'Accepted',     'step'=>4],
    'transfer'    => ['icon'=>'fa-exchange-alt',    'color'=>'--green2', 'bg'=>'--green-bg',   'label'=>'Transferring', 'step'=>4],
    'completed'   => ['icon'=>'fa-trophy',          'color'=>'--green2', 'bg'=>'--green-bg',   'label'=>'Completed',    'step'=>5],
    'declined'    => ['icon'=>'fa-times-circle',    'color'=>'--coral',  'bg'=>'--coral-bg',   'label'=>'Declined',     'step'=>-1],
    'canceled'    => ['icon'=>'fa-ban',             'color'=>'--text3',  'bg'=>'--bg4',        'label'=>'Canceled',     'step'=>-1],
    'on_hold'     => ['icon'=>'fa-pause-circle',    'color'=>'--amber',  'bg'=>'--amber-bg',   'label'=>'On Hold',      'step'=>-1],
];

$urgencyMeta = [
    'low'    => ['color'=>'--text3',  'label'=>'Low urgency'],
    'medium' => ['color'=>'--text2',  'label'=>'Medium urgency'],
    'high'   => ['color'=>'--amber',  'label'=>'High urgency'],
    'urgent' => ['color'=>'--coral',  'label'=>'Urgent'],
];

$purposeLabels = [
    'startup'   => '🚀 Startup / Launch',
    'rebrand'   => '🎨 Rebranding',
    'investment'=> '📈 Investment',
    'portfolio' => '💼 Portfolio',
    'personal'  => '👤 Personal',
    'other'     => '• Other',
];

function koboToNaira(int $k): string {
    return '₦' . number_format($k / 100, 0, '.', ',');
}
function timeAgo(string $ts): string {
    $diff = time() - strtotime($ts);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return round($diff/60).'m ago';
    if ($diff < 86400)  return round($diff/3600).'h ago';
    if ($diff < 604800) return round($diff/86400).'d ago';
    return date('M j, Y', strtotime($ts));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Broker Service — CheckDomain</title>
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
  --gold:#F5C842;--gold-bg:rgba(245,200,66,0.08);
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
.elite-badge{display:flex;align-items:center;gap:5px;background:var(--gold-bg);border:1px solid rgba(245,200,66,.2);border-radius:7px;padding:5px 11px;font-size:11px;font-weight:700;color:var(--gold);text-transform:uppercase;letter-spacing:.06em}

/* ── Content ─── */
.content{padding:28px 28px 60px}

/* ── Page header ─── */
.page-header{margin-bottom:24px}
.page-eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:.16em;color:var(--text3);margin-bottom:5px}
.page-title{font-family:var(--serif);font-style:italic;font-size:28px;color:var(--text);margin-bottom:6px}
.page-sub{font-size:13px;color:var(--text2);line-height:1.6;max-width:600px}
.page-sub em{color:var(--green);font-style:normal;font-family:var(--mono)}

/* ── Elite gate ─── */
.elite-gate{background:linear-gradient(135deg,var(--gold-bg),rgba(127,119,221,.06));border:1px solid rgba(245,200,66,.2);border-radius:16px;padding:32px 28px;text-align:center;margin-bottom:28px;position:relative;overflow:hidden}
.elite-gate::before{content:'';position:absolute;top:-60px;right:-60px;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(245,200,66,.06),transparent 70%);pointer-events:none}
.eg-icon{font-size:32px;margin-bottom:14px}
.eg-title{font-size:18px;font-weight:800;color:var(--text);margin-bottom:8px}
.eg-sub{font-size:13px;color:var(--text2);max-width:480px;margin:0 auto 22px;line-height:1.7}
.eg-features{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin-bottom:22px}
.eg-feat{display:flex;align-items:center;gap:6px;background:var(--bg3);border:1px solid var(--border);border-radius:7px;padding:6px 12px;font-size:12px;color:var(--text2)}
.eg-feat i{color:var(--gold);font-size:11px}
.eg-ctas{display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap}
.eg-cta-primary{display:inline-flex;align-items:center;gap:7px;background:var(--gold);color:#000;border:none;border-radius:9px;padding:11px 26px;font-family:var(--display);font-size:12px;font-weight:800;cursor:pointer;text-decoration:none;transition:opacity .2s;text-transform:uppercase;letter-spacing:.07em}
.eg-cta-primary:hover{opacity:.88}
.eg-cta-secondary{display:inline-flex;align-items:center;gap:7px;background:none;color:var(--green2);border:1px solid rgba(29,158,117,.3);border-radius:9px;padding:11px 22px;font-family:var(--display);font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:all .15s;text-transform:uppercase;letter-spacing:.06em}
.eg-cta-secondary:hover{background:var(--green-bg)}

/* ── Stats row ─── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:24px}
.stat-chip{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:14px 16px;display:flex;align-items:center;gap:12px}
.stat-chip-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
.sci-blue{background:var(--blue-bg);color:var(--blue)}
.sci-amber{background:var(--amber-bg);color:var(--amber)}
.sci-green{background:var(--green-bg);color:var(--green2)}
.sci-coral{background:var(--coral-bg);color:var(--coral)}
.stat-chip-num{font-size:20px;font-weight:800;font-family:var(--mono);color:var(--text);line-height:1}
.stat-chip-lbl{font-size:10px;color:var(--text2);margin-top:2px}

/* ── Two-column layout ─── */
.broker-layout{display:grid;grid-template-columns:420px 1fr;gap:22px;align-items:start}

/* ── Submit form ─── */
.submit-card{background:var(--bg2);border:1px solid var(--border);border-radius:16px;overflow:hidden;position:sticky;top:76px}
.submit-card-header{padding:18px 22px;border-bottom:1px solid var(--border);background:linear-gradient(135deg,rgba(245,200,66,.05),var(--bg2))}
.submit-card-title{font-size:13px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.submit-card-sub{font-size:12px;color:var(--text2);margin-top:3px}
.submit-card-body{padding:22px;display:flex;flex-direction:column;gap:16px}

.form-group{display:flex;flex-direction:column;gap:6px}
.form-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--text3);display:flex;align-items:center;gap:5px}
.form-label span{color:var(--coral)}
.form-input{background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:9px 13px;font-family:var(--mono);font-size:13px;color:var(--text);outline:none;transition:border-color .2s;width:100%}
.form-input::placeholder{color:var(--text3)}
.form-input:focus{border-color:var(--green)}
.form-input:disabled{opacity:.4;cursor:not-allowed}
.form-textarea{background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:10px 13px;font-family:var(--display);font-size:13px;color:var(--text);outline:none;transition:border-color .2s;width:100%;resize:vertical;min-height:80px;line-height:1.5}
.form-textarea::placeholder{color:var(--text3)}
.form-textarea:focus{border-color:var(--green)}
.form-textarea:disabled{opacity:.4;cursor:not-allowed}
.form-hint{font-size:11px;color:var(--text3)}

/* Budget row */
.budget-row{display:flex;gap:8px;align-items:center}
.budget-symbol{font-size:14px;color:var(--text3);flex-shrink:0;font-family:var(--mono)}
.budget-input{flex:1}
.budget-flex-check{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text2);cursor:pointer;flex-shrink:0}
.budget-flex-check input{accent-color:var(--green);cursor:pointer}

/* Select */
.form-select{background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:9px 13px;font-family:var(--display);font-size:13px;color:var(--text);outline:none;transition:border-color .2s;width:100%;cursor:pointer}
.form-select:focus{border-color:var(--green)}
.form-select:disabled{opacity:.4;cursor:not-allowed}

/* Urgency toggle */
.urgency-group{display:flex;gap:4px}
.urgency-btn{flex:1;padding:7px 4px;border-radius:6px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;cursor:pointer;background:var(--bg3);border:1px solid var(--border);color:var(--text3);font-family:var(--display);transition:all .13s;text-align:center}
.urgency-btn:hover{border-color:var(--border2);color:var(--text)}
.urgency-btn.active-low{background:var(--bg4);border-color:var(--border2);color:var(--text2)}
.urgency-btn.active-medium{background:var(--blue-bg);border-color:rgba(74,144,217,.3);color:var(--blue)}
.urgency-btn.active-high{background:var(--amber-bg);border-color:rgba(239,159,39,.3);color:var(--amber)}
.urgency-btn.active-urgent{background:var(--coral-bg);border-color:rgba(232,89,60,.3);color:var(--coral)}
.urgency-btn:disabled{opacity:.4;cursor:not-allowed}

.submit-btn{width:100%;display:flex;align-items:center;justify-content:center;gap:8px;background:var(--green);color:#fff;border:none;border-radius:9px;padding:12px;font-family:var(--display);font-size:13px;font-weight:700;cursor:pointer;transition:background .2s;letter-spacing:.03em}
.submit-btn:hover{background:var(--green2)}
.submit-btn:disabled{opacity:.5;cursor:not-allowed}

/* Commission note */
.commission-note{background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:11px 14px;font-size:11px;color:var(--text2);line-height:1.6}
.commission-note strong{color:var(--text)}

/* ── Request list side ─── */
.requests-side{}

.filter-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.filter-tabs{display:flex;gap:2px;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:3px}
.ftab{padding:5px 13px;border-radius:5px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;cursor:pointer;color:var(--text3);background:none;border:none;font-family:var(--display);transition:all .12s;text-decoration:none;display:block}
.ftab:hover{color:var(--text);background:var(--bg3)}
.ftab.active{background:var(--bg3);color:var(--text)}
.count-label{font-size:12px;color:var(--text3);font-family:var(--mono)}
.count-label em{color:var(--green2);font-style:normal;font-weight:700}

/* ── Request card ─── */
.request-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:12px;transition:border-color .15s}
.request-card:hover{border-color:var(--border2)}
.request-card.status-negotiating,.request-card.status-offer_made{border-color:rgba(239,159,39,.2)}
.request-card.status-completed,.request-card.status-accepted{border-color:rgba(29,158,117,.2)}
.request-card.status-declined,.request-card.status-canceled{opacity:.65}

.rc-main{display:flex;align-items:flex-start;gap:14px;padding:16px 18px}
.rc-status-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.rc-body{flex:1;min-width:0}
.rc-domain{font-family:var(--mono);font-size:15px;font-weight:700;color:var(--text)}
.rc-domain span{color:var(--text3);font-weight:400}
.rc-meta-row{display:flex;align-items:center;gap:7px;margin-top:5px;flex-wrap:wrap}

.status-pill{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;padding:3px 8px;border-radius:4px;display:inline-flex;align-items:center;gap:4px}
.sp-dot{width:5px;height:5px;border-radius:50%;background:currentColor;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.urgency-tag{font-size:9px;font-weight:700;text-transform:uppercase;padding:2px 6px;border-radius:3px}
.ut-low{background:var(--bg4);color:var(--text3)}
.ut-medium{background:var(--blue-bg);color:var(--blue)}
.ut-high{background:var(--amber-bg);color:var(--amber)}
.ut-urgent{background:var(--coral-bg);color:var(--coral)}
.purpose-tag{font-size:10px;color:var(--text3)}

.rc-update{background:var(--bg3);border-left:3px solid var(--amber);padding:9px 13px;margin:0 18px 14px;border-radius:0 7px 7px 0;font-size:12px;color:var(--text2);line-height:1.5}
.rc-update-time{font-size:10px;color:var(--text3);font-family:var(--mono);margin-top:3px}

/* Progress steps */
.rc-progress{padding:12px 18px;border-top:1px solid var(--border);background:var(--bg3)}
.progress-steps{display:flex;align-items:center;gap:0}
.ps-step{display:flex;flex-direction:column;align-items:center;flex:1;position:relative}
.ps-step::after{content:'';position:absolute;top:10px;left:50%;right:-50%;height:2px;background:var(--border)}
.ps-step:last-child::after{display:none}
.ps-step.done::after{background:var(--green)}
.ps-step.active::after{background:linear-gradient(90deg,var(--green),var(--border))}
.ps-dot{width:20px;height:20px;border-radius:50%;border:2px solid var(--border);background:var(--bg);display:flex;align-items:center;justify-content:center;font-size:8px;z-index:1;margin-bottom:4px;transition:all .3s}
.ps-step.done .ps-dot{border-color:var(--green);background:var(--green-bg);color:var(--green2)}
.ps-step.active .ps-dot{border-color:var(--amber);background:var(--amber-bg);color:var(--amber);animation:pulse 2s infinite}
.ps-step.declined .ps-dot{border-color:var(--coral);background:var(--coral-bg);color:var(--coral)}
.ps-label{font-size:9px;color:var(--text3);text-transform:uppercase;letter-spacing:.07em;text-align:center;line-height:1.3}
.ps-step.done .ps-label{color:var(--green2)}
.ps-step.active .ps-label{color:var(--amber)}

/* Pricing info */
.rc-pricing{padding:12px 18px;border-top:1px solid var(--border);display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.rcp-item-label{font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:var(--text3);margin-bottom:3px}
.rcp-item-value{font-family:var(--mono);font-size:13px;font-weight:700;color:var(--text)}
.rcp-item-value.na{color:var(--text3);font-family:var(--display);font-size:12px;font-weight:400}

/* RC actions */
.rc-actions{padding:12px 18px;border-top:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.rc-action-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:7px;font-family:var(--display);font-size:11px;font-weight:700;cursor:pointer;text-decoration:none;transition:all .15s;border:none;text-transform:uppercase;letter-spacing:.05em}
.rab-default{background:var(--bg3);color:var(--text2);border:1px solid var(--border)}
.rab-default:hover{background:var(--bg4);color:var(--text)}
.rab-coral{background:none;color:var(--coral);border:1px solid rgba(232,89,60,.25)}
.rab-coral:hover{background:var(--coral-bg)}
.rab-green{background:var(--green-bg);color:var(--green2)}
.rab-green:hover{background:rgba(29,158,117,.2)}
.rc-time{font-size:11px;color:var(--text3);font-family:var(--mono);margin-left:auto}

/* ── Empty state ─── */
.empty-state{display:flex;flex-direction:column;align-items:center;gap:12px;text-align:center;padding:56px 24px;background:var(--bg2);border:1px solid var(--border);border-radius:14px}
.empty-icon-wrap{width:56px;height:56px;border-radius:14px;background:var(--bg3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:22px;color:var(--text3)}
.empty-title{font-size:15px;font-weight:700;color:var(--text)}
.empty-sub{font-size:13px;color:var(--text2);max-width:280px;line-height:1.6}

/* ── How it works ─── */
.how-section{margin-top:28px}
.how-title-row{display:flex;align-items:center;gap:8px;margin-bottom:16px}
.how-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--text2)}
.how-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.how-card{background:var(--bg2);border:1px solid var(--border);border-radius:13px;padding:18px;display:flex;flex-direction:column;gap:10px}
.how-num{width:30px;height:30px;border-radius:8px;background:var(--gold-bg);border:1px solid rgba(245,200,66,.15);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;font-family:var(--mono);color:var(--gold)}
.how-card-title{font-size:13px;font-weight:700;color:var(--text)}
.how-card-desc{font-size:12px;color:var(--text2);line-height:1.6}

/* ── Cancel modal ─── */
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
@media(max-width:1100px){.broker-layout{grid-template-columns:360px 1fr}}
@media(max-width:900px){
  .broker-layout{grid-template-columns:1fr}
  .submit-card{position:static}
  .stats-row{grid-template-columns:repeat(2,1fr)}
  .how-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:768px){
  .main{margin-left:0}.mobile-menu-btn{display:flex}
  .content{padding:20px 16px 50px}
  .how-grid{grid-template-columns:1fr}
  .rc-pricing{grid-template-columns:1fr 1fr}
}
@media(max-width:480px){
  .stats-row{grid-template-columns:1fr 1fr}
  .elite-badge{display:none}
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
        <span style="color:var(--text);">Broker Service</span>
      </div>
    </div>
    <div class="topbar-right">
      <?php if ($userPlan === 'elite'): ?>
      <div class="elite-badge">
        <i class="fas fa-crown" style="font-size:10px;"></i> Elite
      </div>
      <?php endif; ?>
      <a href="<?= htmlspecialchars($assetUrl('billing.php')) ?>" class="topbar-btn" title="Billing">
        <i class="fas fa-credit-card"></i>
      </a>
    </div>
  </div>

  <div class="content">

    <!-- Page header -->
    <div class="page-header">
      <div class="page-eyebrow">Premium service</div>
      <div class="page-title">Domain Broker.</div>
      <div class="page-sub">
        Want a domain that's already taken? Our team negotiates directly with the current owner on your behalf —
        confidentially, professionally, and at no cost unless we succeed.
        <?php if ($canBroker): ?>
          <br><em><?= (int)$stats['active_count'] ?> active</em> request<?= $stats['active_count'] != 1 ? 's' : '' ?> · <em><?= (int)$stats['completed_count'] ?> completed</em>.
        <?php endif; ?>
      </div>
    </div>

    <!-- Elite gate -->
    <?php if (!$canBroker): ?>
    <div class="elite-gate">
      <div class="eg-icon">🤝</div>
      <div class="eg-title">Broker Service is an Elite feature</div>
      <div class="eg-sub">
        Our team of domain brokers will contact the current owner of any domain you want, negotiate the best price, and handle the full transfer — end to end. You only pay if we succeed.
      </div>
      <div class="eg-features">
        <div class="eg-feat"><i class="fas fa-user-tie"></i> Dedicated broker assigned</div>
        <div class="eg-feat"><i class="fas fa-user-secret"></i> Confidential outreach</div>
        <div class="eg-feat"><i class="fas fa-handshake"></i> No upfront fee</div>
        <div class="eg-feat"><i class="fas fa-exchange-alt"></i> Full transfer handled</div>
        <div class="eg-feat"><i class="fas fa-shield-alt"></i> Escrow protection</div>
        <div class="eg-feat"><i class="fas fa-headset"></i> 24h response time</div>
      </div>
      <div class="eg-ctas">
        <a href="<?= htmlspecialchars($assetUrl('billing.php?plan=elite')) ?>" class="eg-cta-primary">
          <i class="fas fa-crown" style="font-size:10px;"></i> Upgrade to Elite — ₦29,000/mo
        </a>
        <a href="<?= htmlspecialchars($assetUrl('billing.php?plan=pro')) ?>" class="eg-cta-secondary">
          Start with Pro →
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-chip">
        <div class="stat-chip-icon sci-blue"><i class="fas fa-paper-plane"></i></div>
        <div><div class="stat-chip-num"><?= (int)$stats['total'] ?></div><div class="stat-chip-lbl">Total requests</div></div>
      </div>
      <div class="stat-chip">
        <div class="stat-chip-icon sci-amber"><i class="fas fa-comments-dollar"></i></div>
        <div><div class="stat-chip-num"><?= (int)$stats['negotiating_count'] ?></div><div class="stat-chip-lbl">In negotiation</div></div>
      </div>
      <div class="stat-chip">
        <div class="stat-chip-icon sci-green"><i class="fas fa-trophy"></i></div>
        <div><div class="stat-chip-num"><?= (int)$stats['completed_count'] ?></div><div class="stat-chip-lbl">Completed</div></div>
      </div>
      <div class="stat-chip">
        <div class="stat-chip-icon sci-coral"><i class="fas fa-times-circle"></i></div>
        <div><div class="stat-chip-num"><?= (int)$stats['declined_count'] ?></div><div class="stat-chip-lbl">Declined</div></div>
      </div>
    </div>

    <!-- Main two-column layout -->
    <div class="broker-layout">

      <!-- ── Submit form ──────────────────────────── -->
      <div>
        <div class="submit-card">
          <div class="submit-card-header">
            <div class="submit-card-title">
              <i class="fas fa-plus-circle" style="color:var(--gold);font-size:13px;"></i>
              New broker request
            </div>
            <div class="submit-card-sub">Tell us what domain you want and your budget.</div>
          </div>
          <div class="submit-card-body">

            <div class="form-group">
              <label class="form-label" for="brokerDomain">
                Domain name <span>*</span>
              </label>
              <input class="form-input" type="text" id="brokerDomain"
                     placeholder="example.com or premiumname.io"
                     value="<?= $prefill ?>"
                     <?= !$canBroker ? 'disabled' : '' ?>
                     autocomplete="off" maxlength="253">
              <span class="form-hint">The exact domain you want us to acquire.</span>
            </div>

            <div class="form-group">
              <label class="form-label" for="brokerBudget">
                Max budget (₦) <span>*</span>
              </label>
              <div class="budget-row">
                <span class="budget-symbol">₦</span>
                <input class="form-input budget-input" type="number" id="brokerBudget"
                       placeholder="500,000" min="0" step="1000"
                       <?= !$canBroker ? 'disabled' : '' ?>>
                <label class="budget-flex-check">
                  <input type="checkbox" id="brokerBudgetFlex" <?= !$canBroker ? 'disabled' : '' ?>>
                  Flexible
                </label>
              </div>
              <span class="form-hint">Set 0 if you need a quote first. "Flexible" signals willingness to go above this.</span>
            </div>

            <div class="form-group">
              <label class="form-label" for="brokerPurpose">Purpose</label>
              <select class="form-select" id="brokerPurpose" <?= !$canBroker ? 'disabled' : '' ?>>
                <option value="startup">🚀 Startup / Launch</option>
                <option value="rebrand">🎨 Rebranding</option>
                <option value="investment">📈 Investment / Portfolio</option>
                <option value="personal">👤 Personal project</option>
                <option value="other">• Other</option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Urgency</label>
              <div class="urgency-group" id="urgencyGroup">
                <?php foreach (['low'=>'Low','medium'=>'Med','high'=>'High','urgent'=>'Urgent'] as $val=>$lbl): ?>
                <button class="urgency-btn <?= $val==='medium' ? 'active-medium' : '' ?>"
                        id="urg-<?= $val ?>"
                        onclick="setUrgency('<?= $val ?>')"
                        <?= !$canBroker ? 'disabled' : '' ?>>
                  <?= $lbl ?>
                </button>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" for="brokerNotes">Additional context</label>
              <textarea class="form-textarea" id="brokerNotes"
                        placeholder="Why you want this domain, any history with the owner, deadline, etc."
                        maxlength="2000"
                        <?= !$canBroker ? 'disabled' : '' ?>></textarea>
              <span class="form-hint">Optional but helps our brokers make a stronger case.</span>
            </div>

            <div class="commission-note">
              <strong>No upfront cost.</strong> CheckDomain charges a <strong>15% commission</strong> on the final agreed sale price, only if we successfully acquire the domain. Failed negotiations are completely free.
            </div>

            <button class="submit-btn" id="submitBtn" onclick="submitRequest()" <?= !$canBroker ? 'disabled' : '' ?>>
              <i class="fas fa-paper-plane" style="font-size:11px;"></i>
              Submit broker request
            </button>

          </div>
        </div>
      </div>

      <!-- ── Request list ──────────────────────────── -->
      <div class="requests-side">

        <div class="filter-bar">
          <div class="filter-tabs">
            <?php foreach (['all'=>'All','active'=>'Active','completed'=>'Completed'] as $f=>$lbl): ?>
            <a href="?filter=<?= $f ?>" class="ftab <?= $filter===$f?'active':'' ?>"><?= $lbl ?></a>
            <?php endforeach; ?>
          </div>
          <span class="count-label"><em><?= count($requests) ?></em> request<?= count($requests)!==1?'s':'' ?></span>
        </div>

        <?php if (!empty($requests)): ?>
          <?php foreach ($requests as $req):
            $sm      = $statusMeta[$req['status']] ?? $statusMeta['submitted'];
            $um      = $urgencyMeta[$req['urgency']] ?? $urgencyMeta['medium'];
            $parts   = explode('.', $req['domain_name']);
            $sld     = $parts[0];
            $tldPart = '.' . implode('.', array_slice($parts, 1));
            $step    = $sm['step'];
            $isActive = !in_array($req['status'], ['completed','declined','canceled']);

            // Timeline: 0=Submitted, 1=Research, 2=Outreach, 3=Negotiating, 4=Transfer, 5=Done
            $tlLabels = ['Submitted','Research','Outreach','Negotiating','Transfer','Done'];
            $tlState  = []; // 0=todo, 1=active, 2=done, 3=declined
            for ($i = 0; $i < 6; $i++) {
                if ($req['status'] === 'declined') {
                    $tlState[$i] = $i <= $step ? 3 : 0;
                } elseif ($req['status'] === 'canceled') {
                    $tlState[$i] = $i === 0 ? 2 : 0;
                } elseif ($i < $step) {
                    $tlState[$i] = 2; // done
                } elseif ($i === $step) {
                    $tlState[$i] = 1; // active
                } else {
                    $tlState[$i] = 0; // todo
                }
            }
            if ($req['status'] === 'completed') { foreach ($tlState as &$s) $s = 2; }
          ?>
          <div class="request-card status-<?= $req['status'] ?>" id="req-<?= (int)$req['id'] ?>">

            <!-- Main row -->
            <div class="rc-main">
              <div class="rc-status-icon" style="background:var(<?= $sm['bg'] ?>);color:var(<?= $sm['color'] ?>);">
                <i class="fas <?= $sm['icon'] ?>"></i>
              </div>
              <div class="rc-body">
                <div class="rc-domain">
                  <?= htmlspecialchars($sld) ?><span><?= htmlspecialchars($tldPart) ?></span>
                </div>
                <div class="rc-meta-row">
                  <span class="status-pill" style="background:var(<?= $sm['bg'] ?>);color:var(<?= $sm['color'] ?>);">
                    <?php if ($isActive && $step >= 0): ?><span class="sp-dot"></span><?php endif; ?>
                    <?= $sm['label'] ?>
                  </span>
                  <span class="urgency-tag ut-<?= $req['urgency'] ?>"><?= $um['label'] ?></span>
                  <span class="purpose-tag"><?= $purposeLabels[$req['purpose'] ?? 'other'] ?? '• Other' ?></span>
                </div>
              </div>
              <div style="text-align:right;flex-shrink:0;">
                <div style="font-size:10px;color:var(--text3);font-family:var(--mono);">#<?= (int)$req['id'] ?></div>
                <div style="font-size:10px;color:var(--text3);margin-top:3px;"><?= timeAgo($req['created_at']) ?></div>
              </div>
            </div>

            <!-- Latest update -->
            <?php if (!empty($req['latest_update'])): ?>
            <div class="rc-update">
              <?= htmlspecialchars($req['latest_update']) ?>
              <?php if ($req['latest_update_at']): ?>
              <div class="rc-update-time">Update · <?= date('M j, Y H:i', strtotime($req['latest_update_at'])) ?></div>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Progress steps -->
            <div class="rc-progress">
              <div class="progress-steps">
                <?php foreach ($tlLabels as $idx => $tlLabel):
                  $state = $tlState[$idx] ?? 0;
                  $cls   = match($state) { 2=>'done', 1=>'active', 3=>'declined', default=>'' };
                  $icon  = match($state) { 2=>'fa-check', 3=>'fa-times', 1=>'fa-circle', default=>'' };
                ?>
                <div class="ps-step <?= $cls ?>" >
                  <div class="ps-dot" style="background:#111;"><?php if ($icon): ?><i class="fas <?= $icon ?>" style="font-size:7px;"></i><?php endif; ?></div>
                  <div class="ps-label"><?= $tlLabel ?></div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Pricing (show if any price data exists) -->
            <?php if ($req['budget_kobo'] > 0 || $req['agreed_price_kobo'] || $req['broker_fee_kobo']): ?>
            <div class="rc-pricing">
              <div>
                <div class="rcp-item-label">Your budget</div>
                <div class="rcp-item-value">
                  <?= koboToNaira($req['budget_kobo']) ?>
                  <?php if ($req['budget_flexible']): ?>
                  <span style="font-size:9px;color:var(--text3);background:var(--bg4);padding:1px 5px;border-radius:3px;margin-left:4px;">Flexible</span>
                  <?php endif; ?>
                </div>
              </div>
              <div>
                <div class="rcp-item-label">Agreed price</div>
                <div class="rcp-item-value <?= $req['agreed_price_kobo'] ? '' : 'na' ?>">
                  <?= $req['agreed_price_kobo'] ? koboToNaira((int)$req['agreed_price_kobo']) : 'Pending' ?>
                </div>
              </div>
              <div>
                <div class="rcp-item-label">Broker fee (15%)</div>
                <div class="rcp-item-value <?= $req['broker_fee_kobo'] ? '' : 'na' ?>">
                  <?= $req['broker_fee_kobo'] ? koboToNaira((int)$req['broker_fee_kobo']) : 'On success' ?>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <!-- Actions -->
            <div class="rc-actions">
              <a href="<?= htmlspecialchars($assetUrl('whois.php')) ?>?domain=<?= urlencode($req['domain_name']) ?>"
                 class="rc-action-btn rab-default">
                <i class="fas fa-search" style="font-size:10px;"></i> WHOIS
              </a>
              <?php if ($req['status'] === 'completed'): ?>
              <span class="rc-action-btn rab-green" style="cursor:default;">
                <i class="fas fa-trophy" style="font-size:10px;"></i> Acquired
              </span>
              <?php elseif ($isActive && in_array($req['status'], ['submitted','researching','on_hold'])): ?>
              <button class="rc-action-btn rab-coral"
                      onclick="confirmCancel(<?= (int)$req['id'] ?>, '<?= htmlspecialchars($req['domain_name'], ENT_QUOTES) ?>')">
                <i class="fas fa-times" style="font-size:10px;"></i> Cancel
              </button>
              <?php endif; ?>
              <span class="rc-time">
                <i class="fas fa-clock" style="font-size:9px;margin-right:3px;"></i>
                <?= date('M j, Y', strtotime($req['created_at'])) ?>
              </span>
            </div>

          </div>
          <?php endforeach; ?>

        <?php else: ?>
          <div class="empty-state">
            <div class="empty-icon-wrap"><i class="fas fa-handshake"></i></div>
            <div class="empty-title">
              <?= $filter === 'completed' ? 'No completed requests yet' : ($filter==='active' ? 'No active requests' : 'No broker requests yet') ?>
            </div>
            <div class="empty-sub">
              <?php if (!$canBroker): ?>
                Upgrade to Elite to start submitting broker requests.
              <?php else: ?>
                Use the form to submit your first broker request. Our team responds within 24 hours.
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- How it works -->
        <div class="how-section">
          <div class="how-title-row">
            <i class="fas fa-question-circle" style="color:var(--gold);font-size:13px;"></i>
            <span class="how-title">How the broker service works</span>
          </div>
          <div class="how-grid">
            <div class="how-card">
              <div class="how-num">1</div>
              <div class="how-card-title">Submit your request</div>
              <div class="how-card-desc">Tell us the domain, your maximum budget, and why you want it. More context helps us negotiate a better price.</div>
            </div>
            <div class="how-card">
              <div class="how-num">2</div>
              <div class="how-card-title">We research &amp; reach out</div>
              <div class="how-card-desc">Our team looks up the owner via WHOIS, finds the best contact, and reaches out confidentially — without revealing who the buyer is.</div>
            </div>
            <div class="how-card">
              <div class="how-num">3</div>
              <div class="how-card-title">Negotiation &amp; transfer</div>
              <div class="how-card-desc">We negotiate the best price on your behalf. Once agreed, we handle the escrow payment and full domain transfer to your account.</div>
            </div>
          </div>
        </div>

      </div><!-- /.requests-side -->
    </div><!-- /.broker-layout -->
  </div><!-- /.content -->
</main>

<!-- Cancel modal -->
<div class="modal-overlay" id="cancelModal">
  <div class="modal">
    <div class="modal-icon"><i class="fas fa-exclamation-triangle"></i></div>
    <div class="modal-title">Cancel this request?</div>
    <div class="modal-body">Cancel the broker request for <span class="modal-domain" id="cancelDomainName"></span>? If our team has already made contact with the owner, canceling may affect future negotiations.</div>
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
const API_URL    = window.location.pathname;
const APP_BASE   = <?= json_encode($appBasePath ?? '') ?>;
const CAN_BROKER = <?= $canBroker ? 'true' : 'false' ?>;
let urgency = 'medium';
let pendingCancelId = null;

// ── Urgency toggle ────────────────────────────────────────
function setUrgency(val) {
  urgency = val;
  ['low','medium','high','urgent'].forEach(u => {
    const btn = document.getElementById('urg-' + u);
    if (!btn) return;
    btn.className = 'urgency-btn' + (u === val ? ` active-${u}` : '');
  });
}

// ── Submit request ────────────────────────────────────────
async function submitRequest() {
  if (!CAN_BROKER) return;
  const btn    = document.getElementById('submitBtn');
  let domain   = document.getElementById('brokerDomain').value.trim().toLowerCase()
                   .replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '');
  const budget = parseFloat(document.getElementById('brokerBudget').value) || 0;
  const flex   = document.getElementById('brokerBudgetFlex').checked;
  const purpose= document.getElementById('brokerPurpose').value;
  const notes  = document.getElementById('brokerNotes').value;

  if (!domain) { document.getElementById('brokerDomain').focus(); showToast('Enter a domain name.', 'error'); return; }
  if (!domain.includes('.')) domain += '.com';

  btn.disabled  = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size:11px;"></i> Submitting…';

  try {
    const res  = await post({ action:'submit', domain, budget, budget_flexible: flex, purpose, urgency, notes });
    const data = await res.json();

    if (data.success) {
      // Clear form
      document.getElementById('brokerDomain').value  = '';
      document.getElementById('brokerBudget').value  = '';
      document.getElementById('brokerBudgetFlex').checked = false;
      document.getElementById('brokerNotes').value   = '';
      showToast(data.message, 'success');
      setTimeout(() => location.reload(), 1500);
    } else if (data.requiresUpgrade) {
      showToast('Broker service requires an Elite plan.', 'error');
      setTimeout(() => window.location.href = APP_BASE + '/billing.php?plan=elite', 1800);
    } else {
      showToast(data.message || 'Submission failed.', 'error');
    }
  } catch {
    showToast('Network error. Please try again.', 'error');
  } finally {
    btn.disabled  = false;
    btn.innerHTML = '<i class="fas fa-paper-plane" style="font-size:11px;"></i> Submit broker request';
  }
}

// ── Cancel modal ──────────────────────────────────────────
function confirmCancel(id, domain) {
  pendingCancelId = id;
  document.getElementById('cancelDomainName').textContent = domain;
  document.getElementById('cancelModal').classList.add('open');
}
function closeModal() { document.getElementById('cancelModal').classList.remove('open'); pendingCancelId = null; }
document.getElementById('cancelModal').addEventListener('click', e => { if (e.target === e.currentTarget) closeModal(); });

async function executeCancel() {
  if (!pendingCancelId) return;
  closeModal();
  const res  = await post({ action: 'cancel', id: pendingCancelId });
  const data = await res.json();
  if (data.success) {
    const card = document.getElementById('req-' + pendingCancelId);
    if (card) { card.style.transition = 'opacity .3s'; card.style.opacity = '0'; setTimeout(() => card.remove(), 320); }
    showToast(data.message, 'success');
  } else {
    showToast(data.message || 'Could not cancel.', 'error');
  }
}

// ── Helpers ───────────────────────────────────────────────
function post(body) {
  return fetch(API_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify(body),
  });
}

function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  const icon = document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  icon.className   = `fas ${type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'}`;
  icon.style.color = type === 'error' ? 'var(--coral)' : 'var(--green2)';
  t.className = `toast show ${type}`;
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.remove('show'), 3800);
}

function openSidebar()  { document.getElementById('cdSidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('show'); }
function closeSidebar() { document.getElementById('cdSidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('show'); }

// ── Auto-fill domain from URL param ───────────────────────
window.addEventListener('DOMContentLoaded', () => {
  const input = document.getElementById('brokerDomain');
  if (input?.value.trim()) input.focus();
});

// ── Enter key on domain field ─────────────────────────────
document.getElementById('brokerDomain')?.addEventListener('keydown', e => {
  if (e.key === 'Enter') { e.preventDefault(); document.getElementById('brokerBudget')?.focus(); }
});
</script>

</body>
</html>