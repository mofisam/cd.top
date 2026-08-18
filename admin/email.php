<?php
require_once 'auth_check.php';
$adminUser = checkAdminAuth();
require_once '../config/database.php';

$conn       = getDBConnection();
$activePage = 'send-email';
$flash      = null;

// ── Sample variable values (used for previews only) ─────────────
$sampleVars = [
    '{{first_name}}'        => 'Samuel',
    '{{email}}'              => 'user@example.com',
    '{{domain_name}}'        => 'mybrand.ng',
    '{{plan_name}}'          => 'Pro plan',
    '{{amount}}'              => '$9',
    '{{billing_date}}'       => date('M j, Y'),
    '{{next_billing_date}}'  => date('M j, Y', strtotime('+1 month')),
    '{{invoice_number}}'     => 'INV-'.date('Y').'-000042',
    '{{days_left}}'           => '14',
    '{{expiry_date}}'        => date('M j, Y', strtotime('+14 days')),
    '{{status_label}}'       => 'Negotiating',
    '{{update_message}}'     => 'We have made contact with the domain owner and are awaiting a response.',
    '{{expiry_minutes}}'     => '60',
    '{{reset_url}}'           => '#',
    '{{dashboard_url}}'      => '#',
    '{{register_url}}'      => '#',
    '{{backorder_url}}'      => '#',
    '{{whois_url}}'           => '#',
    '{{billing_url}}'         => '#',
    '{{watchlist_url}}'      => '#',
    '{{broker_url}}'          => '#',
    '{{site_name}}'           => 'CheckDomain',
    '{{site_url}}'             => 'https://checkdomain.ng',
];

// ── Fetch active templates for the picker ────────────────────────
$templates = [];
$tRes = $conn->query("SELECT id, slug, name, description, subject, html_body, text_body, variables FROM email_templates WHERE is_active=1 ORDER BY is_system DESC, name ASC");
if ($tRes) while ($r = $tRes->fetch_assoc()) $templates[] = $r;

// ── Audience counts (for the picker UI) ──────────────────────────
$q = fn($sql) => ($r = @$conn->query($sql)) ? ($r->fetch_assoc() ?? []) : [];
$countAllUsers      = (int)($q("SELECT COUNT(*) as c FROM users WHERE status != 'banned'")['c'] ?? 0);
$countByPlan = [];
foreach (['free','pro','elite'] as $p) {
    $countByPlan[$p] = (int)($q("SELECT COUNT(*) as c FROM users WHERE plan='$p' AND status != 'banned'")['c'] ?? 0);
}
$countActiveUsers   = (int)($q("SELECT COUNT(*) as c FROM users WHERE status='active'")['c'] ?? 0);
$countSuspended     = (int)($q("SELECT COUNT(*) as c FROM users WHERE status='suspended'")['c'] ?? 0);
$countSubscribers   = (int)($q("SELECT COUNT(*) as c FROM subscribers WHERE status='active'")['c'] ?? 0);

// ── Recent send batches (grouped by minute+subject, approx) ──────
$recentSends = [];
$rsRes = $conn->query("
    SELECT subject, status, COUNT(*) as c, MIN(sent_at) as first_sent, MAX(sent_at) as last_sent,
           SUM(status='sent') as ok_count, SUM(status='failed') as fail_count
    FROM email_send_log
    GROUP BY subject, DATE_FORMAT(sent_at, '%Y-%m-%d %H:%i')
    ORDER BY last_sent DESC LIMIT 12
");
if ($rsRes) while ($r = $rsRes->fetch_assoc()) $recentSends[] = $r;

$totalSentAllTime = (int)($q("SELECT COUNT(*) as c FROM email_send_log WHERE status='sent'")['c'] ?? 0);
$totalFailedAllTime = (int)($q("SELECT COUNT(*) as c FROM email_send_log WHERE status='failed'")['c'] ?? 0);

// ─────────────────────────────────────────────────────────────────
// POST: resolve recipients + send
// ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_campaign') {

    $templateId   = (int)($_POST['template_id'] ?? 0);
    $audienceType = $_POST['audience_type'] ?? '';
    $planFilter   = $_POST['plan_filter'] ?? '';
    $statusFilter = $_POST['status_filter'] ?? 'active';
    $singleEmail  = trim($_POST['single_email'] ?? '');
    $customSubject= trim($_POST['custom_subject'] ?? '');

    // Load template
    $tStmt = $conn->prepare("SELECT id, name, subject, html_body, text_body FROM email_templates WHERE id=? AND is_active=1 LIMIT 1");
    $tStmt->bind_param("i", $templateId);
    $tStmt->execute();
    $tmpl = $tStmt->get_result()->fetch_assoc();
    $tStmt->close();

    if (!$tmpl) {
        $flash = ['type'=>'err','msg'=>'Please select a valid, active template.'];
        goto renderPage;
    }

    // ── Resolve recipient list ────────────────────────────────────
    $recipients = []; // [['email'=>..,'first_name'=>..], ...]

    if ($audienceType === 'single') {
        if (!filter_var($singleEmail, FILTER_VALIDATE_EMAIL)) {
            $flash = ['type'=>'err','msg'=>'Enter a valid email address.'];
            goto renderPage;
        }
        $recipients[] = ['email'=>$singleEmail, 'first_name'=>'there'];
    }

    elseif ($audienceType === 'users') {
        $sql = "SELECT email, full_name FROM users WHERE 1=1";
        if ($planFilter && in_array($planFilter, ['free','pro','elite'])) {
            $sql .= " AND plan='" . $conn->real_escape_string($planFilter) . "'";
        }
        if ($statusFilter && in_array($statusFilter, ['active','suspended','banned'])) {
            $sql .= " AND status='" . $conn->real_escape_string($statusFilter) . "'";
        }
        $res = $conn->query($sql);
        if ($res) while ($r = $res->fetch_assoc()) {
            $first = trim($r['full_name'] ?? '') ? explode(' ', trim($r['full_name']))[0] : 'there';
            $recipients[] = ['email'=>$r['email'], 'first_name'=>$first];
        }
    }

    elseif ($audienceType === 'subscribers') {
        $res = $conn->query("SELECT email, name FROM subscribers WHERE status='active'");
        if ($res) while ($r = $res->fetch_assoc()) {
            $first = trim($r['name'] ?? '') ? explode(' ', trim($r['name']))[0] : 'there';
            $recipients[] = ['email'=>$r['email'], 'first_name'=>$first];
        }
    }

    // Dedupe by email
    $seen = [];
    $recipients = array_values(array_filter($recipients, function($r) use (&$seen) {
        $e = strtolower(trim($r['email']));
        if (!$e || isset($seen[$e])) return false;
        $seen[$e] = true;
        return true;
    }));

    if (empty($recipients)) {
        $flash = ['type'=>'err','msg'=>'No recipients matched this audience. Nothing was sent.'];
        goto renderPage;
    }

    // Safety cap per click to avoid runaway sends / timeouts
    $maxPerSend = 500;
    $totalMatched = count($recipients);
    if ($totalMatched > $maxPerSend) {
        $recipients = array_slice($recipients, 0, $maxPerSend);
    }

    $emailLib = file_exists('../includes/email.php');
    if ($emailLib) require_once '../includes/email.php';

    $sentCount = 0; $failedCount = 0; $failures = [];
    $subjectTemplate = $customSubject !== '' ? $customSubject : $tmpl['subject'];

    $logStmt = $conn->prepare("INSERT INTO email_send_log (template_id, recipient, subject, status, error, sent_by) VALUES (?,?,?,?,?,?)");

    foreach ($recipients as $rcpt) {
        $vars = $sampleVars; // base defaults for any unresolved variable
        $vars['{{first_name}}'] = $rcpt['first_name'] ?: 'there';
        $vars['{{email}}']       = $rcpt['email'];

        $subjectRendered = str_replace(array_keys($vars), array_values($vars), $subjectTemplate);
        $htmlRendered    = str_replace(array_keys($vars), array_values($vars), $tmpl['html_body']);
        $textRendered    = $tmpl['text_body'] ? str_replace(array_keys($vars), array_values($vars), $tmpl['text_body']) : null;

        if ($emailLib && function_exists('sendEmail')) {
            $result = sendEmail($rcpt['email'], $subjectRendered, $htmlRendered, $textRendered);
        } else {
            $result = ['success'=>false, 'error'=>'Email library not configured (includes/email.php missing or sendEmail() undefined).'];
        }

        $status = $result['success'] ? 'sent' : 'failed';
        $error  = $result['error'] ?? null;

        if ($status === 'sent') { $sentCount++; }
        else { $failedCount++; $failures[] = $rcpt['email']; }

        $logStmt->bind_param("issssi", $templateId, $rcpt['email'], $subjectRendered, $status, $error, $adminUser['id']);
        $logStmt->execute();
    }
    $logStmt->close();

    logAdminActivity(
        $adminUser['id'],
        'SEND_EMAIL_CAMPAIGN',
        "Sent '{$tmpl['name']}' to {$totalMatched} recipient(s) [{$audienceType}] — {$sentCount} sent, {$failedCount} failed"
    );

    if ($failedCount === 0) {
        $flash = ['type'=>'ok', 'msg'=>"Sent to <strong>{$sentCount}</strong> recipient(s) successfully."];
    } elseif ($sentCount === 0) {
        $flash = ['type'=>'err', 'msg'=>"All {$failedCount} send(s) failed. Check your email configuration in <code>includes/email.php</code>."];
    } else {
        $flash = ['type'=>'warn', 'msg'=>"Sent <strong>{$sentCount}</strong>, failed <strong>{$failedCount}</strong>. See send log below for details."];
    }
    if ($totalMatched > $maxPerSend) {
        $flash['msg'] .= " Note: audience had {$totalMatched} matches; capped at {$maxPerSend} per send to avoid timeouts — run again to continue.";
    }
}

renderPage:

// ── AJAX: live preview with sample data ───────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'preview') {
    $tId = (int)($_GET['id'] ?? 0);
    $tStmt = $conn->prepare("SELECT html_body FROM email_templates WHERE id=? LIMIT 1");
    $tStmt->bind_param("i", $tId);
    $tStmt->execute();
    $html = $tStmt->get_result()->fetch_assoc()['html_body'] ?? '<p style="padding:24px;font-family:sans-serif;color:#999">Template not found.</p>';
    $tStmt->close();
    $conn->close();
    echo str_replace(array_keys($sampleVars), array_values($sampleVars), $html);
    exit();
}

// ── AJAX: live audience count ───────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'audience_count') {
    header('Content-Type: application/json');
    $audienceType = $_GET['audience_type'] ?? '';
    $planFilter   = $_GET['plan_filter'] ?? '';
    $statusFilter = $_GET['status_filter'] ?? 'active';
    $count = 0;

    if ($audienceType === 'users') {
        $sql = "SELECT COUNT(*) as c FROM users WHERE 1=1";
        if ($planFilter && in_array($planFilter, ['free','pro','elite'])) {
            $sql .= " AND plan='" . $conn->real_escape_string($planFilter) . "'";
        }
        if ($statusFilter && in_array($statusFilter, ['active','suspended','banned'])) {
            $sql .= " AND status='" . $conn->real_escape_string($statusFilter) . "'";
        }
        $count = (int)($q($sql)['c'] ?? 0);
    } elseif ($audienceType === 'subscribers') {
        $count = (int)($q("SELECT COUNT(*) as c FROM subscribers WHERE status='active'")['c'] ?? 0);
    } elseif ($audienceType === 'single') {
        $count = 1;
    }

    echo json_encode(['count'=>$count]);
    $conn->close();
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>Send Email — CheckDomain Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0F172A;font-family:'Inter',sans-serif;overflow-x:hidden;color:#fff}
.stat-card{background:linear-gradient(135deg,rgba(30,58,138,.3),rgba(16,185,129,.1));backdrop-filter:blur(10px);border:1px solid rgba(59,130,246,.3);transition:all .3s}
.stat-card:hover{transform:translateY(-2px);border-color:rgba(16,185,129,.5)}
.main-content{transition:margin-left .3s}

.flash-ok  {background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#34D399}
.flash-warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:#FCD34D}
.flash-err {background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.3); color:#FCA5A5}

.panel{background:#1E293B;border:1px solid rgba(71,85,105,.6);border-radius:14px}

.inp{background:rgba(51,65,85,.8);border:1px solid rgba(71,85,105,1);border-radius:.5rem;padding:.55rem .75rem;color:#fff;width:100%;font-size:.85rem;transition:border-color .2s;outline:none}
.inp:focus{border-color:#3B82F6}
.inp::placeholder{color:#64748B}
.form-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#64748B;margin-bottom:.3rem;display:block}
.form-hint{font-size:.68rem;color:#475569;margin-top:.25rem}

.btn-primary{background:#2563EB;color:#fff;border:none;border-radius:.5rem;padding:.65rem 1.5rem;font-size:.85rem;font-weight:700;cursor:pointer;transition:background .15s}
.btn-primary:hover{background:#1D4ED8}
.btn-primary:disabled{opacity:.5;cursor:not-allowed}
.btn-secondary{background:#334155;color:#CBD5E1;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-secondary:hover{background:#475569}

/* Template picker cards */
.tpl-pick{background:#1E293B;border:2px solid rgba(71,85,105,.5);border-radius:12px;padding:14px;cursor:pointer;transition:all .15s;text-align:left}
.tpl-pick:hover{border-color:rgba(59,130,246,.5)}
.tpl-pick.selected{border-color:#3B82F6;background:rgba(59,130,246,.08)}

/* Audience cards */
.aud-pick{background:#1E293B;border:2px solid rgba(71,85,105,.5);border-radius:12px;padding:16px;cursor:pointer;transition:all .15s;text-align:left;display:flex;align-items:flex-start;gap:12px}
.aud-pick:hover{border-color:rgba(59,130,246,.5)}
.aud-pick.selected{border-color:#10B981;background:rgba(16,185,129,.08)}
.aud-icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.95rem}

/* Steps indicator */
.step-num{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0}
.step-active{background:#3B82F6;color:#fff}
.step-done{background:#10B981;color:#fff}
.step-pending{background:#334155;color:#94A3B8}

#previewFrame{width:100%;height:520px;border:none;border-radius:8px;background:#fff}

.badge{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:600;padding:2px 8px;border-radius:9999px;white-space:nowrap}
.b-sent {background:rgba(16,185,129,.15);color:#34D399}
.b-fail {background:rgba(239,68,68,.15); color:#FCA5A5}
.b-mixed{background:rgba(245,158,11,.15);color:#FCD34D}

.data-table{width:100%;font-size:.8rem;border-collapse:collapse}
.data-table th{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748B;padding:.6rem .75rem;text-align:left;border-bottom:1px solid rgba(71,85,105,.5)}
.data-table td{padding:.6rem .75rem;border-bottom:1px solid rgba(71,85,105,.25);color:#CBD5E1}
.data-table tr:hover td{background:rgba(59,130,246,.04)}

::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:rgba(15,23,42,.5);border-radius:10px}
::-webkit-scrollbar-thumb{background:#3B82F6;border-radius:10px}
@media(max-width:768px){.main-content{margin-left:0!important}.p-8{padding:1rem}}
</style>
</head>
<body class="text-white">

<?php include_once 'includes/sidebar.php'; ?>

<div class="main-content" style="margin-left:16rem;">
<div class="p-4 md:p-8 max-w-5xl mx-auto">

  <!-- Flash -->
  <?php if ($flash): ?>
  <div class="flash-<?= $flash['type'] ?> rounded-xl px-4 py-3 mb-6 flex items-start gap-3 text-sm">
    <i class="fas <?= $flash['type']==='ok'?'fa-check-circle':($flash['type']==='warn'?'fa-exclamation-triangle':'fa-times-circle') ?> mt-0.5 flex-shrink-0"></i>
    <span><?= $flash['msg'] ?></span>
  </div>
  <?php endif; ?>

  <!-- Header -->
  <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-7">
    <div>
      <h1 class="text-2xl md:text-3xl font-bold">Send Email</h1>
      <p class="text-gray-400 text-sm mt-1">Pick a template, choose an audience, preview, and send.</p>
    </div>
    <a href="email-templates.php" class="btn-secondary flex items-center gap-2 text-sm">
      <i class="fas fa-edit text-xs"></i> Manage templates
    </a>
  </div>

  <!-- Quick stats -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
    <div class="stat-card rounded-xl p-4">
      <p class="text-gray-400 text-xs">Active templates</p>
      <p class="text-2xl font-bold mt-1 text-blue-400"><?= count($templates) ?></p>
    </div>
    <div class="stat-card rounded-xl p-4">
      <p class="text-gray-400 text-xs">Sent (all time)</p>
      <p class="text-2xl font-bold mt-1 text-green-400"><?= number_format($totalSentAllTime) ?></p>
    </div>
    <div class="stat-card rounded-xl p-4">
      <p class="text-gray-400 text-xs">Failed (all time)</p>
      <p class="text-2xl font-bold mt-1 text-red-400"><?= number_format($totalFailedAllTime) ?></p>
    </div>
    <div class="stat-card rounded-xl p-4">
      <p class="text-gray-400 text-xs">Reachable contacts</p>
      <p class="text-2xl font-bold mt-1 text-purple-400"><?= number_format($countAllUsers + $countSubscribers) ?></p>
    </div>
  </div>

  <?php if (empty($templates)): ?>
  <div class="panel p-8 text-center">
    <i class="fas fa-inbox text-3xl text-gray-500 mb-3"></i>
    <p class="text-gray-300 font-medium">No active templates yet.</p>
    <p class="text-gray-500 text-sm mt-1 mb-4">Create or activate a template first.</p>
    <a href="email-templates.php" class="btn-primary inline-flex items-center gap-2"><i class="fas fa-plus text-xs"></i> Go to templates</a>
  </div>
  <?php else: ?>

  <form method="POST" id="sendForm">
    <input type="hidden" name="action" value="send_campaign">
    <input type="hidden" name="template_id" id="f-template-id">
    <input type="hidden" name="audience_type" id="f-audience-type">

    <!-- STEP 1: Template -->
    <div class="panel p-5 mb-5">
      <div class="flex items-center gap-3 mb-4">
        <span class="step-num step-active" id="step1-num">1</span>
        <h2 class="font-bold text-sm">Choose a template</h2>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3" id="templatePicker">
        <?php foreach ($templates as $t): ?>
        <div class="tpl-pick" data-id="<?= (int)$t['id'] ?>" onclick="selectTemplate(<?= (int)$t['id'] ?>)">
          <div class="flex items-center justify-between mb-1">
            <span class="font-semibold text-sm text-white"><?= htmlspecialchars($t['name']) ?></span>
            <i class="far fa-circle text-gray-500 check-icon" id="check-<?= (int)$t['id'] ?>"></i>
          </div>
          <div class="text-gray-500 text-xs font-mono mb-1"><?= htmlspecialchars($t['slug']) ?></div>
          <?php if ($t['description']): ?>
          <div class="text-gray-400 text-xs"><?= htmlspecialchars($t['description']) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- STEP 2: Audience -->
    <div class="panel p-5 mb-5">
      <div class="flex items-center gap-3 mb-4">
        <span class="step-num step-pending" id="step2-num">2</span>
        <h2 class="font-bold text-sm">Choose audience</h2>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">

        <div class="aud-pick" data-aud="users" onclick="selectAudience('users')">
          <div class="aud-icon" style="background:rgba(59,130,246,.15);color:#60A5FA"><i class="fas fa-users"></i></div>
          <div>
            <div class="font-semibold text-sm text-white">Registered users</div>
            <div class="text-gray-400 text-xs mt-0.5">Filter by plan and account status</div>
            <div class="text-gray-500 text-xs mt-1"><?= number_format($countAllUsers) ?> total</div>
          </div>
        </div>

        <div class="aud-pick" data-aud="subscribers" onclick="selectAudience('subscribers')">
          <div class="aud-icon" style="background:rgba(168,85,247,.15);color:#C4B5FD"><i class="fas fa-envelope-open-text"></i></div>
          <div>
            <div class="font-semibold text-sm text-white">Newsletter subscribers</div>
            <div class="text-gray-400 text-xs mt-0.5">Everyone with an active subscription</div>
            <div class="text-gray-500 text-xs mt-1"><?= number_format($countSubscribers) ?> active</div>
          </div>
        </div>

        <div class="aud-pick md:col-span-2" data-aud="single" onclick="selectAudience('single')">
          <div class="aud-icon" style="background:rgba(16,185,129,.15);color:#34D399"><i class="fas fa-user"></i></div>
          <div class="flex-1">
            <div class="font-semibold text-sm text-white">Single email address</div>
            <div class="text-gray-400 text-xs mt-0.5">Send to one address — useful for tests or one-off replies</div>
          </div>
        </div>

      </div>

      <!-- Users filters -->
      <div id="usersFilters" class="hidden grid grid-cols-1 sm:grid-cols-2 gap-3 bg-slate-900/40 rounded-lg p-4 mb-3">
        <div>
          <label class="form-label">Plan</label>
          <select class="inp" id="f-plan" name="plan_filter" onchange="refreshAudienceCount()">
            <option value="">All plans</option>
            <option value="free">Free (<?= $countByPlan['free'] ?>)</option>
            <option value="pro">Pro (<?= $countByPlan['pro'] ?>)</option>
            <option value="elite">Elite (<?= $countByPlan['elite'] ?>)</option>
          </select>
        </div>
        <div>
          <label class="form-label">Account status</label>
          <select class="inp" id="f-status" name="status_filter" onchange="refreshAudienceCount()">
            <option value="active" selected>Active (<?= $countActiveUsers ?>)</option>
            <option value="suspended">Suspended (<?= $countSuspended ?>)</option>
            <option value="">Any status</option>
          </select>
        </div>
      </div>

      <!-- Single email input -->
      <div id="singleFilters" class="hidden bg-slate-900/40 rounded-lg p-4 mb-3">
        <label class="form-label">Email address</label>
        <input class="inp" type="email" name="single_email" id="f-single-email" placeholder="someone@example.com">
      </div>

      <div class="flex items-center gap-2 text-sm bg-blue-500/10 border border-blue-500/20 rounded-lg px-4 py-2.5 text-blue-300">
        <i class="fas fa-bullseye text-xs"></i>
        <span>Matched recipients: <strong id="audienceCountDisplay">0</strong></span>
      </div>
    </div>

    <!-- STEP 3: Subject + Preview -->
    <div class="panel p-5 mb-5">
      <div class="flex items-center gap-3 mb-4">
        <span class="step-num step-pending" id="step3-num">3</span>
        <h2 class="font-bold text-sm">Subject &amp; preview</h2>
      </div>

      <label class="form-label">Subject line override (optional)</label>
      <input class="inp mb-1" type="text" name="custom_subject" id="f-subject" placeholder="Leave blank to use the template's default subject">
      <p class="form-hint mb-4">Supports the same {{variables}} as the template body.</p>

      <button type="button" class="btn-secondary flex items-center gap-2 text-sm" onclick="openPreview()">
        <i class="fas fa-eye text-xs"></i> Preview with sample data
      </button>
    </div>

    <!-- Send -->
    <div class="panel p-5 flex flex-col sm:flex-row items-center justify-between gap-4">
      <div class="text-sm text-gray-400 flex items-center gap-2">
        <i class="fas fa-info-circle text-blue-400"></i>
        Sends immediately using your configured SMTP settings. Capped at 500 recipients per click.
      </div>
      <button type="submit" class="btn-primary flex items-center gap-2 whitespace-nowrap" id="sendBtn" disabled>
        <i class="fas fa-paper-plane text-xs"></i> Send campaign
      </button>
    </div>
  </form>

  <?php endif; ?>

  <!-- ── Recent sends log ─────────────────────────────────────── -->
  <?php if (!empty($recentSends)): ?>
  <div class="panel p-5 mt-8">
    <h2 class="font-bold text-sm mb-4 flex items-center gap-2"><i class="fas fa-history text-gray-400"></i> Recent send activity</h2>
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead><tr>
          <th>Subject</th>
          <th>Recipients</th>
          <th>Sent</th>
          <th>Failed</th>
          <th>When</th>
        </tr></thead>
        <tbody>
        <?php foreach ($recentSends as $rs):
          $badge = $rs['fail_count'] == 0 ? 'b-sent' : ($rs['ok_count'] == 0 ? 'b-fail' : 'b-mixed');
        ?>
        <tr>
          <td class="text-white font-medium" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($rs['subject']) ?></td>
          <td><?= number_format($rs['c']) ?></td>
          <td><span class="badge b-sent"><?= number_format($rs['ok_count']) ?></span></td>
          <td><?php if ($rs['fail_count'] > 0): ?><span class="badge b-fail"><?= number_format($rs['fail_count']) ?></span><?php else: ?>—<?php endif; ?></td>
          <td class="text-gray-500 text-xs"><?= date('M j, H:i', strtotime($rs['last_sent'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div>
</div>

<!-- Preview modal -->
<div id="previewModalBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(4px);z-index:100;align-items:center;justify-content:center;">
  <div style="background:#1E293B;border:1px solid rgba(59,130,246,.3);border-radius:1rem;padding:1.5rem;width:90%;max-width:680px;max-height:92vh;overflow-y:auto">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold">Email preview</h2>
      <button onclick="closePreview()" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <div class="bg-slate-900 rounded-xl p-2 mb-2">
      <iframe id="previewFrame" sandbox="allow-same-origin"></iframe>
    </div>
    <p class="text-gray-600 text-xs text-center">Showing sample data — actual emails use real recipient values.</p>
    <div class="flex justify-end mt-4">
      <button onclick="closePreview()" class="btn-secondary">Close</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div id="toast" style="position:fixed;bottom:24px;right:24px;z-index:999;background:#1E293B;border:1px solid rgba(59,130,246,.3);border-radius:10px;padding:12px 18px;font-size:13px;color:#E2E8F0;box-shadow:0 8px 32px rgba(0,0,0,.5);transform:translateY(20px);opacity:0;transition:all .3s ease;display:flex;align-items:center;gap:9px;max-width:340px;">
  <i class="fas fa-check-circle" id="toastIcon" style="color:#10B981;flex-shrink:0;font-size:14px;"></i>
  <span id="toastText"></span>
</div>

<script>
let selectedTemplateId = null;
let selectedAudience   = null;

function selectTemplate(id) {
  selectedTemplateId = id;
  document.getElementById('f-template-id').value = id;
  document.querySelectorAll('.tpl-pick').forEach(el => {
    const on = el.dataset.id == id;
    el.classList.toggle('selected', on);
    document.getElementById('check-'+el.dataset.id).className = on ? 'fas fa-check-circle text-blue-400 check-icon' : 'far fa-circle text-gray-500 check-icon';
  });
  document.getElementById('step1-num').className = 'step-num step-done';
  document.getElementById('step2-num').className = 'step-num step-active';
  updateSendButton();
}

function selectAudience(type) {
  selectedAudience = type;
  document.getElementById('f-audience-type').value = type;
  document.querySelectorAll('.aud-pick').forEach(el => el.classList.toggle('selected', el.dataset.aud === type));
  document.getElementById('usersFilters').classList.toggle('hidden', type !== 'users');
  document.getElementById('singleFilters').classList.toggle('hidden', type !== 'single');
  document.getElementById('step2-num').className = 'step-num step-done';
  document.getElementById('step3-num').className = 'step-num step-active';
  refreshAudienceCount();
  updateSendButton();
}

async function refreshAudienceCount() {
  const display = document.getElementById('audienceCountDisplay');
  if (!selectedAudience) { display.textContent = '0'; return; }

  if (selectedAudience === 'single') {
    display.textContent = '1 (if valid)';
    return;
  }

  const plan   = document.getElementById('f-plan')?.value || '';
  const status = document.getElementById('f-status')?.value || '';
  const params = new URLSearchParams({ ajax:'audience_count', audience_type:selectedAudience, plan_filter:plan, status_filter:status });

  try {
    const res = await fetch('?' + params.toString());
    const data = await res.json();
    display.textContent = (data.count ?? 0).toLocaleString();
    updateSendButton();
  } catch(e) { display.textContent = '—'; }
}

function updateSendButton() {
  const btn = document.getElementById('sendBtn');
  if (btn) btn.disabled = !(selectedTemplateId && selectedAudience);
}

function openPreview() {
  if (!selectedTemplateId) { showToast('Choose a template first.', 'warn'); return; }
  document.getElementById('previewFrame').src = '?ajax=preview&id=' + selectedTemplateId;
  document.getElementById('previewModalBackdrop').style.display = 'flex';
}
function closePreview() { document.getElementById('previewModalBackdrop').style.display = 'none'; }
document.getElementById('previewModalBackdrop').addEventListener('click', e => {
  if (e.target.id === 'previewModalBackdrop') closePreview();
});

document.getElementById('f-single-email')?.addEventListener('input', () => {
  if (selectedAudience === 'single') updateSendButton();
});

document.getElementById('sendForm')?.addEventListener('submit', e => {
  if (selectedAudience === 'single') {
    const email = document.getElementById('f-single-email').value.trim();
    if (!email || !email.includes('@')) {
      e.preventDefault();
      showToast('Enter a valid email address.', 'err');
      return;
    }
  }
  const btn = document.getElementById('sendBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-pulse text-xs"></i> Sending…';
});

function showToast(msg, type='ok') {
  const t = document.getElementById('toast'), icon = document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  const c = {ok:'#10B981',warn:'#F59E0B',err:'#EF4444'};
  const i = {ok:'fa-check-circle',warn:'fa-exclamation-triangle',err:'fa-times-circle'};
  icon.className = 'fas ' + (i[type]||'fa-info-circle');
  icon.style.color = c[type] || '#10B981';
  t.style.transform = 'translateY(0)'; t.style.opacity = '1';
  clearTimeout(t._t);
  t._t = setTimeout(() => { t.style.transform = 'translateY(20px)'; t.style.opacity = '0'; }, 4200);
}

<?php if ($flash): ?>
showToast(<?= json_encode(strip_tags($flash['msg'])) ?>, <?= json_encode($flash['type']) ?>);
<?php endif; ?>
</script>

</body>
</html>