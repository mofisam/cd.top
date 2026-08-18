<?php
session_start();
require_once 'lib/Auth.php';
require_once 'config/database.php';

$auth = new Auth();
if (!isset($_COOKIE['session_token'])) { header('Location: login.php'); exit(); }
$session = $auth->verifySession($_COOKIE['session_token']);
if (!$session) { setcookie('session_token','',time()-3600,'/'); header('Location: login.php'); exit(); }

$appBasePath = rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'')),'/' );
if (in_array($appBasePath,['/','.','\\'])) $appBasePath='';
$assetUrl = fn(string $p):string => ($appBasePath?:'').'/'.ltrim($p,'/');

$conn = getDBConnection();

// ── Ensure domain_reports table exists ──────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS domain_reports (
        id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        user_id         INT              NOT NULL,
        domain_name     VARCHAR(253)     NOT NULL,
        report_type     ENUM('basic','full','competitor') NOT NULL DEFAULT 'basic',
        delivery_email  VARCHAR(320)     NOT NULL,
        delivery_note   VARCHAR(255)     NULL COMMENT 'Optional message from user',
        status          ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
        credits_spent   TINYINT UNSIGNED NOT NULL DEFAULT 5,
        report_data     MEDIUMTEXT       NULL COMMENT 'JSON snapshot of data used in report',
        sent_at         TIMESTAMP        NULL,
        created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_dr_user   (user_id),
        INDEX idx_dr_domain (domain_name),
        INDEX idx_dr_status (status),
        CONSTRAINT fk_dr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Fetch user ───────────────────────────────────────────────────
$st = $conn->prepare("SELECT id, email, full_name, plan, credits FROM users WHERE id=?");
$st->bind_param("i",$session['user_id']); $st->execute();
$user = $st->get_result()->fetch_assoc(); $st->close();
if (!$user) { header('Location: logout.php'); exit(); }

$uid       = (int)$user['id'];
$userPlan  = $user['plan'] ?? 'free';
$credits   = (int)($user['credits'] ?? 0);
$userEmail = $user['email'];
$userName  = trim($user['full_name'] ?? '') ?: explode('@',$userEmail)[0];
$firstName = explode(' ',$userName)[0];

// ── Credit costs per report type ─────────────────────────────────
$reportTypes = [
    'basic'      => ['label'=>'Basic Report',      'credits'=>3, 'icon'=>'fa-file-lines',   'color'=>'var(--blue)',   'bg'=>'var(--blue-bg)',
                     'desc'=>'Availability, registrar, expiry date, and nameservers.',
                     'includes'=>['Domain availability','Registrar details','Creation & expiry dates','Nameserver list','DNSSEC status']],
    'full'       => ['label'=>'Full WHOIS Report',  'credits'=>6, 'icon'=>'fa-file-shield',  'color'=>'var(--green2)', 'bg'=>'var(--green-bg)',
                     'desc'=>'Everything in Basic plus registrant data, EPP status codes, and a dead-site scan.',
                     'includes'=>['Everything in Basic','Registrant name & org','Country & contact info','EPP status codes','Dead-site score & signals']],
    'competitor' => ['label'=>'Competitor Report',  'credits'=>10,'icon'=>'fa-chart-bar',    'color'=>'var(--purple)', 'bg'=>'var(--purple-bg)',
                     'desc'=>'Full WHOIS plus age score, backorder opportunity assessment, and acquisition recommendations.',
                     'includes'=>['Everything in Full','Domain age score','Backorder opportunity rating','Acquisition strategy notes','Alternative domain suggestions']],
];

// Can request reports?
$canReport = ($userPlan !== 'free');

// ── Sidebar counts ───────────────────────────────────────────────
$wlSt = $conn->prepare("SELECT COUNT(*) as c FROM pinned_domains WHERE user_id=? AND status='active'");
$wlSt->bind_param("i",$uid); $wlSt->execute();
$watchlistCount = (int)$wlSt->get_result()->fetch_assoc()['c']; $wlSt->close();

$alSt = $conn->prepare("SELECT COUNT(*) as c FROM domain_alerts WHERE user_id=? AND status='unread'");
$alSt->bind_param("i",$uid); $alSt->execute();
$alertCount = (int)$alSt->get_result()->fetch_assoc()['c']; $alSt->close();

// ── Report history ───────────────────────────────────────────────
$histSt = $conn->prepare("
    SELECT id, domain_name, report_type, delivery_email, status, credits_spent, created_at, sent_at
    FROM domain_reports WHERE user_id=? ORDER BY created_at DESC LIMIT 20
");
$histSt->bind_param("i",$uid); $histSt->execute();
$history = $histSt->get_result()->fetch_all(MYSQLI_ASSOC); $histSt->close();

// ── AJAX: submit report request ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    ob_start();

    $input      = json_decode(file_get_contents('php://input'),true) ?? [];
    $action     = $input['action'] ?? '';

    if ($action === 'request_report') {

        if (!$canReport) {
            ob_end_clean();
            echo json_encode(['success'=>false,'requiresUpgrade'=>true,'message'=>'Domain reports require a Pro plan or above.']);
            exit();
        }

        $raw    = strtolower(trim($input['domain'] ?? ''));
        $raw    = preg_replace('#^https?://(www\.)?#','',$raw);
        $domain = rtrim(trim($raw),'/');

        if (!$domain || !str_contains($domain,'.') ||
            !preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',$domain)) {
            ob_end_clean();
            echo json_encode(['success'=>false,'message'=>'Enter a valid domain name.']);
            exit();
        }

        $type = $input['report_type'] ?? 'basic';
        if (!isset($reportTypes[$type])) $type = 'basic';
        $cost = $reportTypes[$type]['credits'];

        $deliveryEmail = trim($input['delivery_email'] ?? '') ?: $userEmail;
        if (!filter_var($deliveryEmail, FILTER_VALIDATE_EMAIL)) {
            ob_end_clean();
            echo json_encode(['success'=>false,'message'=>'Enter a valid delivery email address.']);
            exit();
        }

        $note = substr(trim($input['note'] ?? ''), 0, 255);

        // Check credits
        if ($credits < $cost) {
            ob_end_clean();
            echo json_encode(['success'=>false,'insufficientCredits'=>true,
                'message'=>"Not enough credits. A {$reportTypes[$type]['label']} costs {$cost} credits. You have {$credits}."]);
            exit();
        }

        // Deduct credits atomically
        $dSt = $conn->prepare("UPDATE users SET credits=credits-? WHERE id=? AND credits>=?");
        $dSt->bind_param("iii",$cost,$uid,$cost); $dSt->execute();
        if ($dSt->affected_rows === 0) {
            $dSt->close(); ob_end_clean();
            echo json_encode(['success'=>false,'message'=>'Credit deduction failed. Please try again.']);
            exit();
        }
        $dSt->close();
        $creditsAfter = $credits - $cost;

        // Grab WHOIS cache if available (data snapshot for report)
        $whoisSt = $conn->prepare("
            SELECT * FROM whois_lookups
            WHERE domain_name=? AND looked_up_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY looked_up_at DESC LIMIT 1
        ");
        $whoisSt->bind_param("s",$domain); $whoisSt->execute();
        $whoisData = $whoisSt->get_result()->fetch_assoc(); $whoisSt->close();

        // Grab dead-site scan cache if available
        $deadSt = $conn->prepare("
            SELECT * FROM dead_site_scans
            WHERE domain_name=? AND scanned_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY scanned_at DESC LIMIT 1
        ");
        $deadSt->bind_param("s",$domain); $deadSt->execute();
        $deadData = $deadSt->get_result()->fetch_assoc(); $deadSt->close();

        $reportSnapshot = json_encode([
            'domain'     => $domain,
            'type'       => $type,
            'requested'  => date('Y-m-d H:i:s'),
            'whois'      => $whoisData ? array_intersect_key($whoisData, array_flip([
                'registrar','registrant_name','registrant_org','registrant_country',
                'created_date','updated_date','expiry_date','status','nameservers','dnssec','is_available'
            ])) : null,
            'dead_scan'  => $deadData ? array_intersect_key($deadData, array_flip([
                'site_status','is_dead','dead_score','is_parked','is_for_sale',
                'http_status','response_time_ms','has_content'
            ])) : null,
        ]);

        // Insert report request
        $insSt = $conn->prepare("
            INSERT INTO domain_reports
              (user_id, domain_name, report_type, delivery_email, delivery_note, credits_spent, report_data, status)
            VALUES (?,?,?,?,?,?,'pending',?)
        ");
        $insSt->bind_param("issssis",$uid,$domain,$type,$deliveryEmail,$note,$cost,$reportSnapshot);
        $insSt->execute();
        $reportId = $conn->insert_id;
        $insSt->close();

        // Credit ledger
        $balSt = $conn->prepare("SELECT credits FROM users WHERE id=?");
        $balSt->bind_param("i",$uid); $balSt->execute();
        $balAfter = (int)($balSt->get_result()->fetch_assoc()['credits'] ?? $creditsAfter);
        $balSt->close();

        $lgSt = $conn->prepare("INSERT INTO credit_ledger (user_id,delta,balance_after,type,domain_name,note) VALUES (?,?,?,'domain_check',?,?)");
        if ($lgSt) {
            $delta = -$cost;
            $note2 = "Domain report ({$reportTypes[$type]['label']}): {$domain}";
            $lgSt->bind_param("iiiss",$uid,$delta,$balAfter,$domain,$note2);
            $lgSt->execute(); $lgSt->close();
        }

        // Send email notification (uses existing sendEmail if available)
        $emailSent = false;
        if (file_exists('includes/email.php')) {
            require_once 'includes/email.php';
            if (function_exists('sendEmail')) {
                $typeLabel = $reportTypes[$type]['label'];
                $subject   = "Your CheckDomain report for {$domain} is being prepared";
                $htmlBody  = "
                <div style='font-family:Inter,sans-serif;max-width:580px;margin:0 auto;background:#0F172A;color:#E2E8F0;padding:32px;border-radius:12px;'>
                  <img src='https://checkdomain.ng/images/logo.png' alt='CheckDomain' style='height:40px;margin-bottom:24px;'>
                  <h2 style='font-size:20px;margin:0 0 8px;color:#fff;'>Report request received</h2>
                  <p style='color:#94A3B8;margin:0 0 24px;font-size:14px;'>Hi {$firstName}, we have received your <strong style='color:#10B981;'>{$typeLabel}</strong> request for <strong style='color:#38BDF8;font-family:monospace;'>{$domain}</strong>.</p>
                  <div style='background:#1E293B;border-radius:8px;padding:18px 20px;margin-bottom:24px;'>
                    <table style='width:100%;font-size:13px;border-collapse:collapse;'>
                      <tr><td style='color:#64748B;padding:4px 0;'>Domain</td><td style='font-family:monospace;color:#fff;'>{$domain}</td></tr>
                      <tr><td style='color:#64748B;padding:4px 0;'>Report type</td><td style='color:#fff;'>{$typeLabel}</td></tr>
                      <tr><td style='color:#64748B;padding:4px 0;'>Credits used</td><td style='color:#F59E0B;font-family:monospace;'>{$cost}</td></tr>
                      <tr><td style='color:#64748B;padding:4px 0;'>Delivery to</td><td style='color:#fff;'>{$deliveryEmail}</td></tr>
                      <tr><td style='color:#64748B;padding:4px 0;'>Reference</td><td style='color:#fff;font-family:monospace;'>#" . str_pad($reportId,6,'0',STR_PAD_LEFT) . "</td></tr>
                    </table>
                  </div>
                  <p style='color:#94A3B8;font-size:13px;margin:0 0 6px;'>Your report will be compiled and sent to <strong>{$deliveryEmail}</strong> shortly. We aim to deliver within a few minutes.</p>
                  <p style='color:#475569;font-size:12px;margin:24px 0 0;'>CheckDomain · <a href='https://checkdomain.ng' style='color:#38BDF8;'>checkdomain.ng</a></p>
                </div>";
                $result = sendEmail($deliveryEmail, $subject, $htmlBody, "Hi {$firstName}, your {$typeLabel} for {$domain} is being prepared. Reference: #".str_pad($reportId,6,'0',STR_PAD_LEFT));
                $emailSent = $result['success'] ?? false;
            }
        }

        // Mark sent if email delivered, else leave as pending for manual/cron pick-up
        if ($emailSent) {
            $upSt = $conn->prepare("UPDATE domain_reports SET status='sent', sent_at=NOW() WHERE id=?");
            $upSt->bind_param("i",$reportId); $upSt->execute(); $upSt->close();
        }

        ob_end_clean();
        echo json_encode([
            'success'          => true,
            'report_id'        => $reportId,
            'credits_remaining'=> $balAfter,
            'email_sent'       => $emailSent,
            'message'          => $emailSent
                ? "Report requested! Confirmation sent to {$deliveryEmail}."
                : "Report request saved (#{$reportId}). You'll receive the report at {$deliveryEmail} shortly.",
        ]);
        exit();
    }

    ob_end_clean();
    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
    exit();
}

$conn->close();

$prefill = htmlspecialchars(preg_replace('#^https?://(www\.)?#','',trim($_GET['domain']??'')),ENT_QUOTES,'UTF-8');
$activePage = 'domain-report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Domain Report — CheckDomain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;700;800&family=DM+Mono:wght@400;500&family=Instrument+Serif:ital@1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0A0B0E;--bg2:#111318;--bg3:#181C24;--bg4:#1E2230;
  --border:rgba(255,255,255,.06);--border2:rgba(255,255,255,.11);
  --t1:#E9E7DF;--t2:#8A8880;--t3:#454340;
  --green:#1D9E75;--green2:#14C48A;--green-bg:rgba(29,158,117,.1);
  --amber:#EF9F27;--amber-bg:rgba(239,159,39,.1);
  --coral:#E8593C;--coral-bg:rgba(232,89,60,.1);
  --purple:#7F77DD;--purple-bg:rgba(127,119,221,.1);
  --blue:#4A90D9;--blue-bg:rgba(74,144,217,.1);
  --mono:'DM Mono',monospace;--display:'Syne',sans-serif;--serif:'Instrument Serif',serif;
  --sb:224px;
}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--t1);font-family:var(--display);min-height:100vh;display:flex;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;
  background-image:linear-gradient(rgba(29,158,117,.02)1px,transparent 1px),linear-gradient(90deg,rgba(29,158,117,.02)1px,transparent 1px);
  background-size:52px 52px;pointer-events:none;z-index:0}

.main{margin-left:var(--sb);flex:1;position:relative;z-index:1;min-height:100vh}
.topbar{display:flex;align-items:center;justify-content:space-between;padding:14px 28px;border-bottom:1px solid var(--border);backdrop-filter:blur(12px);background:rgba(10,11,14,.88);position:sticky;top:0;z-index:40;gap:12px}
.mob-menu{display:none;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;background:var(--bg2);border:1px solid var(--border);color:var(--t2);font-size:15px;cursor:pointer}
.breadcrumb{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--t3)}
.breadcrumb a{color:var(--t2);text-decoration:none;transition:color .15s}
.breadcrumb a:hover{color:var(--t1)}
.tb-right{display:flex;align-items:center;gap:7px}
.credits-pill{display:flex;align-items:center;gap:5px;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:6px 12px;font-family:var(--mono);font-size:12px;color:var(--t2)}
.credits-pill b{color:var(--amber)}
.tb-btn{display:flex;align-items:center;justify-content:center;width:33px;height:33px;border-radius:8px;background:var(--bg2);border:1px solid var(--border);color:var(--t2);font-size:12px;cursor:pointer;text-decoration:none;transition:all .15s}
.tb-btn:hover{border-color:var(--border2);color:var(--t1)}

.content{padding:28px 28px 60px}

/* ── Page header ── */
.pg-title{font-family:var(--serif);font-style:italic;font-size:26px;color:var(--t1);margin-bottom:4px}
.pg-sub{font-size:13px;color:var(--t2);line-height:1.6;max-width:600px}
.pg-sub em{color:var(--green);font-style:normal;font-family:var(--mono)}

/* ── Upgrade gate ── */
.gate{background:linear-gradient(135deg,rgba(239,159,39,.06),rgba(29,158,117,.04));border:1px solid rgba(239,159,39,.2);border-radius:14px;padding:28px 24px;text-align:center;margin-bottom:28px}
.gate-icon{font-size:30px;margin-bottom:10px}
.gate-title{font-size:17px;font-weight:800;color:var(--t1);margin-bottom:6px}
.gate-sub{font-size:13px;color:var(--t2);max-width:420px;margin:0 auto 18px;line-height:1.6}
.gate-cta{display:inline-flex;align-items:center;gap:7px;background:var(--green);color:#fff;border:none;border-radius:9px;padding:10px 24px;font-family:var(--display);font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s;text-transform:uppercase;letter-spacing:.06em}
.gate-cta:hover{background:var(--green2)}

/* ── Report type picker ── */
.type-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:22px}
.type-card{background:var(--bg2);border:2px solid var(--border);border-radius:14px;padding:18px 16px;cursor:pointer;transition:all .18s;text-align:left;position:relative;overflow:hidden}
.type-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:14px 14px 0 0;background:var(--accent,transparent);transition:background .18s}
.type-card:hover{border-color:var(--border2);transform:translateY(-1px)}
.type-card.selected{border-color:var(--accent,var(--green));background:rgba(29,158,117,.06)}
.type-card.selected::before{background:var(--accent,var(--green))}
.type-card-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;margin-bottom:10px;flex-shrink:0}
.type-card-name{font-size:13px;font-weight:700;color:var(--t1);margin-bottom:4px}
.type-card-desc{font-size:11px;color:var(--t2);line-height:1.5;margin-bottom:10px}
.type-card-cost{display:inline-flex;align-items:center;gap:4px;font-family:var(--mono);font-size:11px;font-weight:700;padding:3px 8px;border-radius:5px;background:var(--amber-bg);color:var(--amber)}
.type-card-includes{margin-top:10px;padding-top:10px;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:4px}
.type-incl-item{display:flex;align-items:center;gap:5px;font-size:10px;color:var(--t2)}
.type-incl-item i{font-size:9px;color:var(--green2);flex-shrink:0}
.type-check{position:absolute;top:12px;right:12px;width:20px;height:20px;border-radius:50%;border:2px solid var(--border2);display:flex;align-items:center;justify-content:center;font-size:10px;transition:all .15s}
.type-card.selected .type-check{background:var(--green);border-color:var(--green);color:#fff}

/* ── Form card ── */
.form-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:22px}
.form-card-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.form-card-title{font-size:12px;font-weight:700;color:var(--t1);letter-spacing:.04em}
.form-card-body{padding:18px 20px}
.field{margin-bottom:16px}
.field:last-child{margin-bottom:0}
.field-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--t3);margin-bottom:6px;display:flex;align-items:center;justify-content:space-between}
.field-label span{color:var(--t2);font-size:10px;text-transform:none;letter-spacing:0;font-weight:400}
.field-input{width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:9px;padding:11px 14px 11px 38px;font-family:var(--mono);font-size:13px;color:var(--t1);outline:none;transition:border-color .2s}
.field-input::placeholder{color:var(--t3)}
.field-input:focus{border-color:var(--green)}
.field-input.no-icon{padding-left:14px}
.field-wrap{position:relative}
.field-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--t3);font-size:12px;pointer-events:none}
.field-hint{font-size:11px;color:var(--t3);margin-top:5px;line-height:1.5}

/* ── Delivery options ── */
.delivery-opts{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
.delivery-opt{background:var(--bg3);border:2px solid var(--border);border-radius:10px;padding:12px 14px;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:10px}
.delivery-opt:hover{border-color:var(--border2)}
.delivery-opt.selected{border-color:var(--green);background:rgba(29,158,117,.07)}
.delivery-opt-icon{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0}
.delivery-opt-label{font-size:12px;font-weight:600;color:var(--t1);margin-bottom:1px}
.delivery-opt-sub{font-size:10px;color:var(--t2)}
.do-check{width:16px;height:16px;border-radius:50%;border:2px solid var(--border2);margin-left:auto;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:8px;transition:all .15s}
.delivery-opt.selected .do-check{background:var(--green);border-color:var(--green);color:#fff}

/* ── Submit button ── */
.submit-row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:4px}
.submit-btn{display:flex;align-items:center;gap:8px;background:var(--green);color:#fff;border:none;border-radius:10px;padding:12px 26px;font-family:var(--display);font-size:13px;font-weight:700;cursor:pointer;transition:background .2s;letter-spacing:.03em}
.submit-btn:hover{background:var(--green2)}
.submit-btn:disabled{opacity:.5;cursor:not-allowed}
.submit-cost{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--t2)}
.submit-cost b{font-family:var(--mono);color:var(--amber)}

/* ── Success state ── */
.success-panel{display:none;background:linear-gradient(135deg,rgba(29,158,117,.1),rgba(20,196,138,.04));border:1px solid rgba(29,158,117,.25);border-radius:14px;padding:30px 24px;text-align:center;margin-bottom:22px}
.success-panel.show{display:block}
.success-icon{font-size:36px;color:var(--green2);margin-bottom:14px}
.success-title{font-size:18px;font-weight:800;color:var(--t1);margin-bottom:6px}
.success-sub{font-size:13px;color:var(--t2);line-height:1.6;max-width:420px;margin:0 auto 18px}
.success-ref{font-family:var(--mono);font-size:12px;color:var(--t3)}
.success-actions{display:flex;justify-content:center;gap:8px;margin-top:16px;flex-wrap:wrap}

/* ── History table ── */
.hist-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-top:28px}
.hist-head-row{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.hist-title{font-size:12px;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:7px}
.hist-th{display:grid;grid-template-columns:1fr 110px 90px 90px 70px 80px;padding:9px 20px;background:var(--bg3);border-bottom:1px solid var(--border)}
.ht{font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:var(--t3);font-weight:600}
.ht.r{text-align:right}
.hist-row{display:grid;grid-template-columns:1fr 110px 90px 90px 70px 80px;padding:12px 20px;border-bottom:1px solid var(--border);align-items:center;transition:background .12s}
.hist-row:last-child{border-bottom:none}
.hist-row:hover{background:var(--bg3)}
.hdom{font-family:var(--mono);font-size:12px;font-weight:700;color:var(--t1)}
.pill{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;padding:2px 7px;border-radius:4px;white-space:nowrap;display:inline-block}
.p-basic   {background:var(--blue-bg);color:var(--blue)}
.p-full    {background:var(--green-bg);color:var(--green2)}
.p-comp    {background:var(--purple-bg);color:var(--purple)}
.p-pending {background:var(--amber-bg);color:var(--amber)}
.p-sent    {background:var(--green-bg);color:var(--green2)}
.p-failed  {background:var(--coral-bg);color:var(--coral)}
.p-proc    {background:var(--blue-bg);color:var(--blue)}
.hcred{font-family:var(--mono);font-size:11px;color:var(--t3);text-align:right}
.hdate{font-size:11px;color:var(--t2);font-family:var(--mono)}
.hem{font-size:11px;color:var(--t2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hist-empty{text-align:center;padding:36px 20px;color:var(--t3);font-size:12px}

/* ── Toast ── */
.toast{position:fixed;bottom:28px;right:28px;z-index:999;background:var(--bg3);border:1px solid var(--border2);border-radius:10px;padding:12px 18px;font-size:13px;color:var(--t1);box-shadow:0 8px 32px rgba(0,0,0,.4);transform:translateY(20px);opacity:0;transition:all .3s;max-width:340px;display:flex;align-items:center;gap:9px}
.toast.show{transform:translateY(0);opacity:1}

/* ── Responsive ── */
@media(max-width:900px){.type-grid{grid-template-columns:1fr 1fr}.delivery-opts{grid-template-columns:1fr}}
@media(max-width:768px){
  .main{margin-left:0}.mob-menu{display:flex}
  .content{padding:18px 16px 50px}
  .type-grid{grid-template-columns:1fr}
  .credits-pill{display:none}
  .hist-th,.hist-row{grid-template-columns:1fr 80px 70px 70px}
  .hist-th>*:nth-child(3),.hist-row>*:nth-child(3),
  .hist-th>*:nth-child(6),.hist-row>*:nth-child(6){display:none}
}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:49}
.sb-overlay.show{display:block}
</style>
</head>
<body>

<div class="sb-overlay" id="sbOverlay" onclick="closeSB()"></div>
<?php require_once 'includes/sidebar.php'; ?>

<main class="main">

  <!-- Topbar -->
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:10px">
      <button class="mob-menu" onclick="openSB()"><i class="fas fa-bars"></i></button>
      <div class="breadcrumb">
        <a href="<?= htmlspecialchars($assetUrl('dashboard.php')) ?>">Dashboard</a>
        <span style="color:var(--t3);font-size:9px"><i class="fas fa-chevron-right"></i></span>
        <span style="color:var(--t1)">Domain Report</span>
      </div>
    </div>
    <div class="tb-right">
      <div class="credits-pill">
        <i class="fas fa-bolt" style="color:var(--amber);font-size:11px"></i>
        <b id="creditsDisplay"><?= $credits ?></b> credits
      </div>
      <a href="<?= htmlspecialchars($assetUrl('billing.php?topup=1')) ?>" class="tb-btn" title="Buy credits">
        <i class="fas fa-plus"></i>
      </a>
      <a href="<?= htmlspecialchars($assetUrl('alerts.php')) ?>" class="tb-btn" title="Alerts">
        <i class="fas fa-bell"></i>
      </a>
    </div>
  </div>

  <div class="content">

    <!-- Page header -->
    <div style="margin-bottom:24px">
      <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.13em;color:var(--t3);margin-bottom:4px">Domain Services</div>
      <div class="pg-title">Domain Report.</div>
      <p class="pg-sub">
        Request a detailed report for any domain — delivered straight to your email.
        <?php if($canReport):?>
        Costs <em>3–10 credits</em> depending on report depth. Includes data we already have cached where available.
        <?php endif;?>
      </p>
    </div>

    <!-- Success panel (shown after submit) -->
    <div class="success-panel" id="successPanel">
      <div class="success-icon"><i class="fas fa-circle-check"></i></div>
      <div class="success-title">Report request received!</div>
      <div class="success-sub" id="successMsg">Your report is being compiled and will be sent to your email shortly.</div>
      <div class="success-ref" id="successRef"></div>
      <div class="success-actions">
        <button onclick="resetForm()" style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:8px;background:var(--bg3);color:var(--t2);border:1px solid var(--border2);font-family:var(--display);font-size:12px;font-weight:700;cursor:pointer;transition:all .15s">
          <i class="fas fa-plus" style="font-size:10px"></i> Request another
        </button>
        <a href="<?= htmlspecialchars($assetUrl('dashboard.php')) ?>" style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:8px;background:var(--green-bg);color:var(--green2);border:none;font-family:var(--display);font-size:12px;font-weight:700;cursor:pointer;text-decoration:none">
          <i class="fas fa-gauge" style="font-size:10px"></i> Dashboard
        </a>
      </div>
    </div>

    <!-- Upgrade gate -->
    <?php if(!$canReport):?>
    <div class="gate">
      <div class="gate-icon">📋</div>
      <div class="gate-title">Domain reports require Pro or Elite</div>
      <div class="gate-sub">Upgrade to request detailed domain reports — delivered to any email address with registrar data, WHOIS records, expiry analysis, and acquisition intelligence.</div>
      <a href="<?= htmlspecialchars($assetUrl('billing.php?plan=pro')) ?>" class="gate-cta">
        <i class="fas fa-bolt" style="font-size:10px"></i> Upgrade to Pro — $9/mo
      </a>
    </div>
    <?php endif;?>

    <!-- Main form (shown if can report) -->
    <div id="mainForm" <?= !$canReport?'style="display:none"':'' ?>>

      <!-- Step 1: Report type -->
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.13em;color:var(--t3);margin-bottom:10px">
        Step 1 — Choose report type
      </div>
      <div class="type-grid">
        <?php foreach($reportTypes as $key=>$rt):?>
        <div class="type-card" id="type-<?=$key?>" data-type="<?=$key?>" data-cost="<?=$rt['credits']?>"
             style="--accent:<?=$rt['color']?>" onclick="selectType('<?=$key?>')">
          <div class="type-check" id="check-<?=$key?>"><i class="fas fa-check"></i></div>
          <div class="type-card-icon" style="background:<?=$rt['bg']?>;color:<?=$rt['color']?>">
            <i class="fas <?=$rt['icon']?>"></i>
          </div>
          <div class="type-card-name"><?=$rt['label']?></div>
          <div class="type-card-desc"><?=$rt['desc']?></div>
          <div class="type-card-cost"><i class="fas fa-bolt" style="font-size:9px"></i><?=$rt['credits']?> credits</div>
          <div class="type-card-includes">
            <?php foreach($rt['includes'] as $inc):?>
            <div class="type-incl-item"><i class="fas fa-check-circle"></i><?=htmlspecialchars($inc)?></div>
            <?php endforeach;?>
          </div>
        </div>
        <?php endforeach;?>
      </div>

      <!-- Step 2: Domain + delivery -->
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.13em;color:var(--t3);margin-bottom:10px">
        Step 2 — Domain &amp; delivery
      </div>

      <div class="form-card">
        <div class="form-card-head">
          <i class="fas fa-globe" style="color:var(--green2);font-size:12px"></i>
          <span class="form-card-title">Which domain do you want a report for?</span>
        </div>
        <div class="form-card-body">
          <div class="field">
            <div class="field-label">Domain name</div>
            <div class="field-wrap">
              <i class="fas fa-link field-icon"></i>
              <input class="field-input" type="text" id="domainInput"
                placeholder="e.g. mybrand.ng, competitor.com, startup.io"
                value="<?=$prefill?>" autocomplete="off" maxlength="253">
            </div>
            <div class="field-hint">Include the extension. We'll check our cache first — no extra credits if we already have fresh data.</div>
          </div>
        </div>
      </div>

      <div class="form-card">
        <div class="form-card-head">
          <i class="fas fa-envelope" style="color:var(--blue);font-size:12px"></i>
          <span class="form-card-title">Where should we send the report?</span>
        </div>
        <div class="form-card-body">
          <!-- Delivery type selector -->
          <div class="delivery-opts">
            <div class="delivery-opt selected" id="dopt-account" onclick="setDelivery('account')">
              <div class="delivery-opt-icon" style="background:var(--green-bg);color:var(--green2)"><i class="fas fa-user"></i></div>
              <div>
                <div class="delivery-opt-label">My account email</div>
                <div class="delivery-opt-sub" style="font-family:var(--mono)"><?=htmlspecialchars($userEmail)?></div>
              </div>
              <div class="do-check" id="dc-account"><i class="fas fa-check"></i></div>
            </div>
            <div class="delivery-opt" id="dopt-custom" onclick="setDelivery('custom')">
              <div class="delivery-opt-icon" style="background:var(--blue-bg);color:var(--blue)"><i class="fas fa-paper-plane"></i></div>
              <div>
                <div class="delivery-opt-label">Different address</div>
                <div class="delivery-opt-sub">Client, team member, or another inbox</div>
              </div>
              <div class="do-check" id="dc-custom"><i class="fas fa-check"></i></div>
            </div>
          </div>

          <!-- Custom email (hidden by default) -->
          <div class="field" id="customEmailField" style="display:none">
            <div class="field-label">Delivery email address</div>
            <div class="field-wrap">
              <i class="fas fa-envelope field-icon"></i>
              <input class="field-input" type="email" id="customEmail" placeholder="recipient@example.com">
            </div>
          </div>

          <!-- Note -->
          <div class="field" style="margin-top:14px">
            <div class="field-label">
              Note to include <span>(optional)</span>
            </div>
            <textarea class="field-input no-icon" id="noteInput" rows="2"
              placeholder="e.g. 'Please check the expiry urgency for this domain' or leave blank"
              style="resize:vertical;min-height:60px;padding-left:14px"></textarea>
          </div>
        </div>
      </div>

      <!-- Submit row -->
      <div class="submit-row">
        <div class="submit-cost">
          <i class="fas fa-bolt" style="color:var(--amber);font-size:11px"></i>
          This report will cost <b id="costDisplay">3</b> credits
          &nbsp;·&nbsp; you have <b style="color:var(--green2)"><?=$credits?></b> remaining
        </div>
        <button class="submit-btn" id="submitBtn" onclick="submitReport()" <?=$credits<3?'disabled':''?>>
          <i class="fas fa-paper-plane" style="font-size:11px"></i>
          Request report
        </button>
      </div>

    </div><!-- /mainForm -->

    <!-- History -->
    <?php if(!empty($history)):?>
    <div class="hist-card">
      <div class="hist-head-row">
        <div class="hist-title"><i class="fas fa-history" style="color:var(--green2)"></i> Report history</div>
        <span style="font-size:11px;color:var(--t3);font-family:var(--mono)">Last <?=count($history)?></span>
      </div>
      <div class="hist-th">
        <div class="ht">Domain</div>
        <div class="ht">Type</div>
        <div class="ht">Sent to</div>
        <div class="ht">Status</div>
        <div class="ht r">Credits</div>
        <div class="ht">Requested</div>
      </div>
      <?php foreach($history as $h):
        $typePill = match($h['report_type']){ 'full'=>'p-full','competitor'=>'p-comp', default=>'p-basic' };
        $stPill   = match($h['status']){'sent'=>'p-sent','failed'=>'p-failed','processing'=>'p-proc', default=>'p-pending'};
        $typeLabel= match($h['report_type']){'full'=>'Full','competitor'=>'Competitor', default=>'Basic'};
      ?>
      <div class="hist-row">
        <div>
          <div class="hdom"><?=htmlspecialchars($h['domain_name'])?></div>
          <div style="font-size:10px;color:var(--t3);font-family:var(--mono);margin-top:1px">#<?=str_pad($h['id'],6,'0',STR_PAD_LEFT)?></div>
        </div>
        <div><span class="pill <?=$typePill?>"><?=$typeLabel?></span></div>
        <div class="hem"><?=htmlspecialchars(substr($h['delivery_email'],0,24)).(strlen($h['delivery_email'])>24?'…':'')?></div>
        <div><span class="pill <?=$stPill?>"><?=htmlspecialchars($h['status'])?></span></div>
        <div class="hcred">−<?=(int)$h['credits_spent']?></div>
        <div class="hdate"><?=date('M j, H:i',strtotime($h['created_at']))?></div>
      </div>
      <?php endforeach;?>
    </div>
    <?php elseif($canReport):?>
    <div class="hist-card">
      <div class="hist-head-row"><div class="hist-title">Report history</div></div>
      <div class="hist-empty">
        <i class="fas fa-file-lines" style="font-size:22px;display:block;margin-bottom:10px;opacity:.3"></i>
        Your report requests will appear here.
      </div>
    </div>
    <?php endif;?>

  </div>
</main>

<!-- Toast -->
<div class="toast" id="toast">
  <i class="fas fa-check-circle" id="toastIcon" style="font-size:14px;flex-shrink:0;color:var(--green2)"></i>
  <span id="toastText"></span>
</div>

<script>
const APP_BASE     = <?=json_encode($appBasePath??'')?>;
const USER_EMAIL   = <?=json_encode($userEmail)?>;
const USER_CREDITS = <?=(int)$credits?>;
const CAN_REPORT   = <?=$canReport?'true':'false'?>;

let selectedType   = 'basic';
let deliveryMode   = 'account';
let currentCredits = USER_CREDITS;

// ── Type picker ───────────────────────────────────────────────
function selectType(type) {
  selectedType = type;
  document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('type-'+type).classList.add('selected');
  const cost = parseInt(document.getElementById('type-'+type).dataset.cost);
  document.getElementById('costDisplay').textContent = cost;
  document.getElementById('submitBtn').disabled = currentCredits < cost;
}

// ── Delivery mode ─────────────────────────────────────────────
function setDelivery(mode) {
  deliveryMode = mode;
  ['account','custom'].forEach(m => {
    document.getElementById('dopt-'+m).classList.toggle('selected', m===mode);
  });
  document.getElementById('customEmailField').style.display = mode==='custom'?'block':'none';
  if (mode==='custom') document.getElementById('customEmail').focus();
}

// ── Submit ────────────────────────────────────────────────────
async function submitReport() {
  const domain = document.getElementById('domainInput').value.trim().toLowerCase()
    .replace(/^https?:\/\/(www\.)?/,'').replace(/\/$/,'');

  if (!domain || !domain.includes('.')) {
    showToast('Enter a valid domain name (e.g. mybrand.com).','error'); return;
  }

  const email = deliveryMode==='custom'
    ? document.getElementById('customEmail').value.trim()
    : USER_EMAIL;

  if (!email || !email.includes('@')) {
    showToast('Enter a valid delivery email address.','error'); return;
  }

  const note = document.getElementById('noteInput').value.trim();
  const btn  = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-pulse" style="font-size:11px"></i> Submitting…';

  try {
    const res = await fetch(window.location.pathname, {
      method:'POST',
      headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
      body: JSON.stringify({
        action:'request_report',
        domain,
        report_type: selectedType,
        delivery_email: email,
        note,
      })
    });

    const data = await res.json();

    if (data.success) {
      currentCredits = data.credits_remaining ?? (currentCredits - getCost());
      document.getElementById('creditsDisplay').textContent = currentCredits;
      document.getElementById('submitBtn').disabled = true;

      document.getElementById('successMsg').textContent =
        data.message || `Your report is being compiled and will arrive at ${email} shortly.`;
      document.getElementById('successRef').textContent =
        data.report_id ? `Reference: #${String(data.report_id).padStart(6,'0')}` : '';

      document.getElementById('mainForm').style.display = 'none';
      document.getElementById('successPanel').classList.add('show');
      window.scrollTo({top:0,behavior:'smooth'});
      showToast(data.message || 'Report requested!');
    } else if (data.requiresUpgrade) {
      showToast('Domain reports require a Pro or Elite plan.','error');
    } else if (data.insufficientCredits) {
      showToast(data.message,'error');
      setTimeout(() => window.location.href = APP_BASE+'/billing.php?topup=1', 2200);
    } else {
      showToast(data.message || 'Something went wrong. Please try again.','error');
    }
  } catch(e) {
    showToast('Network error. Please try again.','error');
  } finally {
    btn.disabled  = false;
    btn.innerHTML = '<i class="fas fa-paper-plane" style="font-size:11px"></i> Request report';
  }
}

function getCost() {
  return parseInt(document.getElementById('type-'+selectedType)?.dataset.cost||3);
}

function resetForm() {
  document.getElementById('successPanel').classList.remove('show');
  document.getElementById('mainForm').style.display = 'block';
  document.getElementById('domainInput').value = '';
  document.getElementById('noteInput').value = '';
  selectType('basic');
  setDelivery('account');
  document.getElementById('submitBtn').disabled = currentCredits < 3;
}

// ── Sidebar ───────────────────────────────────────────────────
function openSB(){ document.getElementById('cdSidebar')?.classList.add('open'); document.getElementById('sbOverlay')?.classList.add('show'); }
function closeSB(){ document.getElementById('cdSidebar')?.classList.remove('open'); document.getElementById('sbOverlay')?.classList.remove('show'); }

// ── Toast ─────────────────────────────────────────────────────
function showToast(msg, type='success') {
  const t=document.getElementById('toast'), icon=document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  icon.className=`fas ${type==='error'?'fa-exclamation-circle':'fa-check-circle'}`;
  icon.style.color=type==='error'?'var(--coral)':'var(--green2)';
  t.className=`toast show`;
  clearTimeout(t._t);
  t._t=setTimeout(()=>t.classList.remove('show'),4000);
}

// ── Init ──────────────────────────────────────────────────────
selectType('basic');

// Enter key on domain input
document.getElementById('domainInput')?.addEventListener('keydown', e => {
  if (e.key==='Enter') submitReport();
});
</script>

</body>
</html>