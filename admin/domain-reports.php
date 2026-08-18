<?php
require_once 'auth_check.php';
$adminUser = checkAdminAuth();
require_once '../config/database.php';

$conn       = getDBConnection();
$activePage = 'domain-reports';
$flash      = null;

// ── Ensure table exists (same definition as user-facing page) ───
$conn->query("
    CREATE TABLE IF NOT EXISTS domain_reports (
        id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        user_id         INT              NOT NULL,
        domain_name     VARCHAR(253)     NOT NULL,
        report_type     ENUM('basic','full','competitor') NOT NULL DEFAULT 'basic',
        delivery_email  VARCHAR(320)     NOT NULL,
        delivery_note   VARCHAR(255)     NULL,
        status          ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
        credits_spent   TINYINT UNSIGNED NOT NULL DEFAULT 5,
        report_data     MEDIUMTEXT       NULL,
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

$reportTypeMeta = [
    'basic'      => ['label'=>'Basic',      'pill'=>'p-basic'],
    'full'       => ['label'=>'Full WHOIS', 'pill'=>'p-full'],
    'competitor' => ['label'=>'Competitor', 'pill'=>'p-comp'],
];
$statusMeta = [
    'pending'    => ['label'=>'Pending',    'pill'=>'p-pending', 'icon'=>'fa-clock'],
    'processing' => ['label'=>'Processing', 'pill'=>'p-proc',    'icon'=>'fa-spinner'],
    'sent'       => ['label'=>'Sent',       'pill'=>'p-sent',    'icon'=>'fa-check'],
    'failed'     => ['label'=>'Failed',     'pill'=>'p-failed',  'icon'=>'fa-xmark'],
];

// ── POST: actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action   = $_POST['action'];
    $reportId = (int)($_POST['report_id'] ?? 0);

    if ($action === 'mark_sent' || $action === 'mark_failed' || $action === 'mark_processing') {
        $newStatus = $action === 'mark_sent' ? 'sent' : ($action === 'mark_failed' ? 'failed' : 'processing');
        $sentAtSql = $newStatus === 'sent' ? ", sent_at = NOW()" : "";
        $stmt = $conn->prepare("UPDATE domain_reports SET status=? {$sentAtSql} WHERE id=?");
        $stmt->bind_param("si", $newStatus, $reportId);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();

        if ($ok) {
            logAdminActivity($adminUser['id'], 'DOMAIN_REPORT_STATUS_UPDATE', "Report #{$reportId} marked as {$newStatus}");
            $flash = ['type'=>'ok', 'msg'=>"Report #{$reportId} marked as <strong>{$newStatus}</strong>."];
        } else {
            $flash = ['type'=>'err', 'msg'=>'Report not found or already in that state.'];
        }
    }

    elseif ($action === 'resend_email') {
        $rStmt = $conn->prepare("SELECT dr.*, u.full_name, u.email as account_email FROM domain_reports dr JOIN users u ON u.id=dr.user_id WHERE dr.id=? LIMIT 1");
        $rStmt->bind_param("i", $reportId);
        $rStmt->execute();
        $report = $rStmt->get_result()->fetch_assoc();
        $rStmt->close();

        if (!$report) {
            $flash = ['type'=>'err', 'msg'=>'Report not found.'];
        } else {
            $firstName = trim(explode(' ', $report['full_name'] ?? '')[0]) ?: 'there';
            $typeLabel = $reportTypeMeta[$report['report_type']]['label'] ?? 'Domain';
            $domain    = $report['domain_name'];
            $email     = $report['delivery_email'];

            $emailSent = false;
            if (file_exists('../includes/email.php')) {
                require_once '../includes/email.php';
                if (function_exists('sendEmail')) {
                    $subject  = "Your CheckDomain report for {$domain}";
                    $htmlBody = "
                    <div style='font-family:Inter,sans-serif;max-width:580px;margin:0 auto;background:#0F172A;color:#E2E8F0;padding:32px;border-radius:12px;'>
                      <h2 style='font-size:20px;margin:0 0 8px;color:#fff;'>Domain report — {$domain}</h2>
                      <p style='color:#94A3B8;margin:0 0 24px;font-size:14px;'>Hi {$firstName}, here is your <strong style='color:#10B981;'>{$typeLabel}</strong> report for <strong style='color:#38BDF8;font-family:monospace;'>{$domain}</strong>.</p>
                      <div style='background:#1E293B;border-radius:8px;padding:18px 20px;'>
                        <pre style='color:#CBD5E1;font-size:12px;white-space:pre-wrap;word-break:break-word;margin:0;font-family:monospace;'>" . htmlspecialchars(json_encode(json_decode($report['report_data'] ?? '{}'), JSON_PRETTY_PRINT)) . "</pre>
                      </div>
                      <p style='color:#475569;font-size:12px;margin:24px 0 0;'>CheckDomain · Reference #" . str_pad($reportId,6,'0',STR_PAD_LEFT) . "</p>
                    </div>";
                    $result = sendEmail($email, $subject, $htmlBody, "Domain report for {$domain} — reference #".str_pad($reportId,6,'0',STR_PAD_LEFT));
                    $emailSent = $result['success'] ?? false;
                }
            }

            if ($emailSent) {
                $uStmt = $conn->prepare("UPDATE domain_reports SET status='sent', sent_at=NOW() WHERE id=?");
                $uStmt->bind_param("i", $reportId);
                $uStmt->execute();
                $uStmt->close();
                logAdminActivity($adminUser['id'], 'DOMAIN_REPORT_RESEND', "Resent report #{$reportId} ({$domain}) to {$email}");
                $flash = ['type'=>'ok', 'msg'=>"Report resent to <strong>" . htmlspecialchars($email) . "</strong>."];
            } else {
                $flash = ['type'=>'err', 'msg'=>'Email could not be sent. Check your email configuration in includes/email.php.'];
            }
        }
    }

    elseif ($action === 'delete_report') {
        $stmt = $conn->prepare("DELETE FROM domain_reports WHERE id=?");
        $stmt->bind_param("i", $reportId);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        if ($ok) {
            logAdminActivity($adminUser['id'], 'DOMAIN_REPORT_DELETE', "Deleted report #{$reportId}");
            $flash = ['type'=>'ok', 'msg'=>"Report #{$reportId} deleted."];
        }
    }
}

// ── Filters ────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? '';
$typeFilter   = $_GET['type']   ?? '';
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['p'] ?? 1));
$perPage      = 25;

$where  = "1=1";
$params = [];
$types  = "";

if ($statusFilter && isset($statusMeta[$statusFilter])) {
    $where .= " AND dr.status=?"; $params[] = $statusFilter; $types .= "s";
}
if ($typeFilter && isset($reportTypeMeta[$typeFilter])) {
    $where .= " AND dr.report_type=?"; $params[] = $typeFilter; $types .= "s";
}
if ($search !== '') {
    $where .= " AND (dr.domain_name LIKE ? OR dr.delivery_email LIKE ? OR u.email LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like; $params[] = $like; $params[] = $like; $types .= "sss";
}

// ── Counts ───────────────────────────────────────────────────────
$q = fn($sql) => ($r = @$conn->query($sql)) ? ($r->fetch_assoc() ?? []) : [];
$countPending    = (int)($q("SELECT COUNT(*) as c FROM domain_reports WHERE status='pending'")['c'] ?? 0);
$countProcessing = (int)($q("SELECT COUNT(*) as c FROM domain_reports WHERE status='processing'")['c'] ?? 0);
$countSent       = (int)($q("SELECT COUNT(*) as c FROM domain_reports WHERE status='sent'")['c'] ?? 0);
$countFailed     = (int)($q("SELECT COUNT(*) as c FROM domain_reports WHERE status='failed'")['c'] ?? 0);
$creditsEarned   = (int)($q("SELECT COALESCE(SUM(credits_spent),0) as c FROM domain_reports")['c'] ?? 0);
$countTotal      = $countPending + $countProcessing + $countSent + $countFailed;

// ── Total for pagination ──────────────────────────────────────────
$countSql = "SELECT COUNT(*) as c FROM domain_reports dr JOIN users u ON u.id=dr.user_id WHERE {$where}";
$cStmt = $conn->prepare($countSql);
if ($params) $cStmt->bind_param($types, ...$params);
$cStmt->execute();
$totalMatched = (int)$cStmt->get_result()->fetch_assoc()['c'];
$cStmt->close();
$totalPages = max(1, ceil($totalMatched / $perPage));
$offset = ($page - 1) * $perPage;

// ── Fetch rows ─────────────────────────────────────────────────────
$listSql = "
    SELECT dr.*, u.email as account_email, u.full_name, u.plan
    FROM domain_reports dr
    JOIN users u ON u.id = dr.user_id
    WHERE {$where}
    ORDER BY
        CASE dr.status WHEN 'pending' THEN 0 WHEN 'processing' THEN 1 WHEN 'failed' THEN 2 ELSE 3 END,
        dr.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$lStmt = $conn->prepare($listSql);
if ($params) $lStmt->bind_param($types, ...$params);
$lStmt->execute();
$reports = $lStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$lStmt->close();

// ── Recent activity for sidebar widget ────────────────────────────
$recentReports = $conn->query("
    SELECT dr.domain_name, dr.report_type, dr.status, dr.created_at, u.email
    FROM domain_reports dr JOIN users u ON u.id=dr.user_id
    ORDER BY dr.created_at DESC LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>Domain Reports — CheckDomain Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0F172A;font-family:'Inter',sans-serif;overflow-x:hidden;color:#fff}
.stat-card{background:linear-gradient(135deg,rgba(30,58,138,.3),rgba(16,185,129,.1));backdrop-filter:blur(10px);border:1px solid rgba(59,130,246,.3);transition:all .3s}
.stat-card:hover{transform:translateY(-2px);border-color:rgba(16,185,129,.5)}
.main-content{transition:margin-left .3s}

.flash-ok {background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#34D399}
.flash-err{background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.3); color:#FCA5A5}

.panel{background:#1E293B;border:1px solid rgba(71,85,105,.6);border-radius:14px}

.f-tab{padding:6px 14px;border-radius:7px;font-size:.78rem;font-weight:600;border:1px solid rgba(71,85,105,.6);background:transparent;color:#94A3B8;cursor:pointer;transition:all .15s;text-decoration:none;display:inline-block}
.f-tab:hover,.f-tab.active{background:#3B82F6;border-color:#3B82F6;color:#fff}
.f-tab.active.pending{background:#F59E0B;border-color:#F59E0B;color:#000}
.f-tab.active.sent{background:#10B981;border-color:#10B981}
.f-tab.active.failed{background:#EF4444;border-color:#EF4444}

.search-input{background:rgba(51,65,85,.8);border:1px solid rgba(71,85,105,1);border-radius:8px;padding:8px 14px 8px 36px;color:#fff;font-size:.85rem;outline:none;transition:border-color .2s}
.search-input:focus{border-color:#3B82F6}
.search-input::placeholder{color:#64748B}

.pill{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:3px 9px;border-radius:5px;white-space:nowrap;display:inline-flex;align-items:center;gap:4px}
.p-basic  {background:rgba(74,144,217,.15); color:#60A5FA}
.p-full   {background:rgba(16,185,129,.15); color:#34D399}
.p-comp   {background:rgba(168,85,247,.15); color:#C4B5FD}
.p-pending{background:rgba(245,158,11,.15); color:#FCD34D}
.p-proc   {background:rgba(59,130,246,.15); color:#93C5FD}
.p-sent   {background:rgba(16,185,129,.15); color:#34D399}
.p-failed {background:rgba(239,68,68,.15);  color:#FCA5A5}

.plan-pill{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:1px 6px;border-radius:4px}
.plan-free {background:rgba(100,116,139,.15);color:#94A3B8}
.plan-pro  {background:rgba(59,130,246,.15); color:#60A5FA}
.plan-elite{background:rgba(168,85,247,.15); color:#C4B5FD}

.data-table{width:100%;font-size:.8rem;border-collapse:collapse}
.data-table th{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#64748B;padding:.65rem .8rem;text-align:left;border-bottom:1px solid rgba(71,85,105,.5);white-space:nowrap}
.data-table td{padding:.65rem .8rem;border-bottom:1px solid rgba(71,85,105,.25);color:#CBD5E1;vertical-align:middle}
.data-table tr:hover td{background:rgba(59,130,246,.04)}

.act-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:6px;font-size:.68rem;font-weight:700;cursor:pointer;border:none;transition:all .15s;text-transform:uppercase;letter-spacing:.04em}
.ab-green{background:rgba(16,185,129,.15);color:#34D399}.ab-green:hover{background:rgba(16,185,129,.28)}
.ab-coral{background:rgba(239,68,68,.15); color:#FCA5A5}.ab-coral:hover{background:rgba(239,68,68,.28)}
.ab-blue {background:rgba(59,130,246,.15);color:#93C5FD}.ab-blue:hover{background:rgba(59,130,246,.28)}
.ab-amber{background:rgba(245,158,11,.15);color:#FCD34D}.ab-amber:hover{background:rgba(245,158,11,.28)}

.page-btn{padding:5px 11px;border-radius:6px;font-size:.75rem;font-weight:600;border:1px solid rgba(71,85,105,.6);background:transparent;color:#94A3B8;text-decoration:none;display:inline-block;transition:all .15s}
.page-btn:hover,.page-btn.active{background:#3B82F6;border-color:#3B82F6;color:#fff}

/* Drawer/modal */
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(4px);z-index:100;align-items:center;justify-content:center;padding:1rem}
.modal-backdrop.open{display:flex}
.modal{background:#1E293B;border:1px solid rgba(59,130,246,.3);border-radius:1rem;padding:1.5rem;max-width:600px;width:100%;max-height:88vh;overflow-y:auto}
.mc-row{display:grid;grid-template-columns:130px 1fr;gap:8px;padding:7px 0;border-bottom:1px solid rgba(71,85,105,.3);font-size:.8rem}
.mc-row:last-child{border-bottom:none}
.mc-label{color:#64748B;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em}
.mc-val{color:#E2E8F0;font-family:'DM Mono',monospace;word-break:break-all}

::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:rgba(15,23,42,.5);border-radius:10px}
::-webkit-scrollbar-thumb{background:#3B82F6;border-radius:10px}
@media(max-width:768px){.main-content{margin-left:0!important}.p-8{padding:1rem}}
</style>
</head>
<body class="text-white">

<?php include_once 'includes/sidebar.php'; ?>

<div class="main-content" style="margin-left:16rem;">
<div class="p-4 md:p-8 max-w-7xl mx-auto">

  <!-- Flash -->
  <?php if ($flash): ?>
  <div class="flash-<?= $flash['type'] ?> rounded-xl px-4 py-3 mb-6 flex items-start gap-3 text-sm">
    <i class="fas <?= $flash['type']==='ok'?'fa-check-circle':'fa-times-circle' ?> mt-0.5 flex-shrink-0"></i>
    <span><?= $flash['msg'] ?></span>
  </div>
  <?php endif; ?>

  <!-- Header -->
  <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-7">
    <div>
      <h1 class="text-2xl md:text-3xl font-bold">Domain Reports</h1>
      <p class="text-gray-400 text-sm mt-1">Manage user-requested domain reports — review, resend, and track delivery.</p>
    </div>
  </div>

  <!-- Quick stats -->
  <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
    <div class="stat-card rounded-xl p-4">
      <p class="text-gray-400 text-xs">Pending</p>
      <p class="text-2xl font-bold mt-1 text-amber-400"><?= number_format($countPending) ?></p>
    </div>
    <div class="stat-card rounded-xl p-4">
      <p class="text-gray-400 text-xs">Processing</p>
      <p class="text-2xl font-bold mt-1 text-blue-400"><?= number_format($countProcessing) ?></p>
    </div>
    <div class="stat-card rounded-xl p-4">
      <p class="text-gray-400 text-xs">Sent</p>
      <p class="text-2xl font-bold mt-1 text-green-400"><?= number_format($countSent) ?></p>
    </div>
    <div class="stat-card rounded-xl p-4">
      <p class="text-gray-400 text-xs">Failed</p>
      <p class="text-2xl font-bold mt-1 text-red-400"><?= number_format($countFailed) ?></p>
    </div>
    <div class="stat-card rounded-xl p-4">
      <p class="text-gray-400 text-xs">Credits earned</p>
      <p class="text-2xl font-bold mt-1 text-purple-400"><?= number_format($creditsEarned) ?></p>
    </div>
  </div>

  <!-- Filter + search -->
  <div class="panel p-4 mb-5">
    <div class="flex flex-wrap items-center gap-2 mb-3">
      <a href="?" class="f-tab <?= $statusFilter===''?'active':'' ?>">All (<?=$countTotal?>)</a>
      <a href="?status=pending" class="f-tab pending <?= $statusFilter==='pending'?'active':'' ?>">Pending (<?=$countPending?>)</a>
      <a href="?status=processing" class="f-tab <?= $statusFilter==='processing'?'active':'' ?>">Processing (<?=$countProcessing?>)</a>
      <a href="?status=sent" class="f-tab sent <?= $statusFilter==='sent'?'active':'' ?>">Sent (<?=$countSent?>)</a>
      <a href="?status=failed" class="f-tab failed <?= $statusFilter==='failed'?'active':'' ?>">Failed (<?=$countFailed?>)</a>
      <span class="text-gray-600 mx-1">|</span>
      <a href="?type=basic" class="f-tab <?= $typeFilter==='basic'?'active':'' ?>">Basic</a>
      <a href="?type=full" class="f-tab <?= $typeFilter==='full'?'active':'' ?>">Full</a>
      <a href="?type=competitor" class="f-tab <?= $typeFilter==='competitor'?'active':'' ?>">Competitor</a>
    </div>
    <form method="GET" class="relative max-w-sm">
      <?php if($statusFilter): ?><input type="hidden" name="status" value="<?=htmlspecialchars($statusFilter)?>"><?php endif; ?>
      <?php if($typeFilter): ?><input type="hidden" name="type" value="<?=htmlspecialchars($typeFilter)?>"><?php endif; ?>
      <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
      <input type="text" name="q" value="<?=htmlspecialchars($search)?>" placeholder="Search domain, email…" class="search-input w-full">
    </form>
  </div>

  <!-- Table -->
  <div class="panel" style="overflow:hidden">
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead><tr>
          <th>Domain</th>
          <th>Requested by</th>
          <th>Type</th>
          <th>Delivery</th>
          <th>Status</th>
          <th>Credits</th>
          <th>Requested</th>
          <th>Actions</th>
        </tr></thead>
        <tbody>
        <?php if (empty($reports)): ?>
        <tr><td colspan="8" class="text-center py-10 text-gray-500">
          <i class="fas fa-inbox text-2xl mb-2 block opacity-40"></i>
          No domain reports match these filters.
        </td></tr>
        <?php else: foreach ($reports as $r):
          $tm = $reportTypeMeta[$r['report_type']] ?? $reportTypeMeta['basic'];
          $sm = $statusMeta[$r['status']] ?? $statusMeta['pending'];
        ?>
        <tr>
          <td>
            <div class="font-mono font-bold text-white"><?= htmlspecialchars($r['domain_name']) ?></div>
            <div class="text-gray-500 text-xs font-mono">#<?= str_pad($r['id'],6,'0',STR_PAD_LEFT) ?></div>
          </td>
          <td>
            <div class="text-white text-sm"><?= htmlspecialchars(trim(explode(' ',$r['full_name']??'')[0]) ?: explode('@',$r['account_email'])[0]) ?></div>
            <span class="plan-pill plan-<?= htmlspecialchars($r['plan']) ?>"><?= htmlspecialchars($r['plan']) ?></span>
          </td>
          <td><span class="pill <?= $tm['pill'] ?>"><?= $tm['label'] ?></span></td>
          <td class="text-gray-300 text-xs" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= htmlspecialchars($r['delivery_email']) ?>
            <?php if ($r['delivery_email'] !== $r['account_email']): ?>
            <div class="text-gray-500" style="font-size:.65rem">(custom)</div>
            <?php endif; ?>
          </td>
          <td><span class="pill <?= $sm['pill'] ?>"><i class="fas <?= $sm['icon'] ?>" style="font-size:.6rem"></i> <?= $sm['label'] ?></span></td>
          <td class="font-mono text-amber-400">−<?= (int)$r['credits_spent'] ?></td>
          <td class="text-gray-400 text-xs font-mono whitespace-nowrap"><?= date('M j, H:i', strtotime($r['created_at'])) ?></td>
          <td>
            <div class="flex items-center gap-1.5 flex-wrap">
              <button class="act-btn ab-blue" onclick='viewReport(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'>
                <i class="fas fa-eye"></i> View
              </button>
              <?php if ($r['status'] !== 'sent'): ?>
              <form method="POST" class="inline">
                <input type="hidden" name="action" value="resend_email">
                <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="act-btn ab-green"><i class="fas fa-paper-plane"></i> Send</button>
              </form>
              <?php else: ?>
              <form method="POST" class="inline">
                <input type="hidden" name="action" value="resend_email">
                <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="act-btn ab-amber"><i class="fas fa-redo"></i> Resend</button>
              </form>
              <?php endif; ?>
              <form method="POST" class="inline" onsubmit="return confirm('Delete this report record?')">
                <input type="hidden" name="action" value="delete_report">
                <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="act-btn ab-coral"><i class="fas fa-trash"></i></button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="flex items-center justify-between px-5 py-4 border-t border-slate-700/50 flex-wrap gap-3">
      <span class="text-gray-500 text-xs">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$totalMatched) ?> of <?= number_format($totalMatched) ?></span>
      <div class="flex gap-1.5 flex-wrap">
        <?php
        $qs = fn($p) => '?' . http_build_query(array_filter(['status'=>$statusFilter,'type'=>$typeFilter,'q'=>$search,'p'=>$p]));
        if ($page > 1): ?><a href="<?=$qs($page-1)?>" class="page-btn">← Prev</a><?php endif;
        for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
          <a href="<?=$qs($i)?>" class="page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
        <?php endfor;
        if ($page < $totalPages): ?><a href="<?=$qs($page+1)?>" class="page-btn">Next →</a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Recent activity strip -->
  <?php if (!empty($recentReports)): ?>
  <div class="panel p-5 mt-6">
    <h2 class="font-bold text-sm mb-4 flex items-center gap-2"><i class="fas fa-bolt text-amber-400"></i> Just requested</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
      <?php foreach ($recentReports as $rr):
        $tm = $reportTypeMeta[$rr['report_type']] ?? $reportTypeMeta['basic'];
        $sm = $statusMeta[$rr['status']] ?? $statusMeta['pending'];
      ?>
      <div class="bg-slate-900/40 rounded-lg p-3 flex items-center justify-between gap-3">
        <div class="min-w-0">
          <div class="font-mono text-sm text-white truncate"><?= htmlspecialchars($rr['domain_name']) ?></div>
          <div class="text-gray-500 text-xs truncate"><?= htmlspecialchars($rr['email']) ?></div>
        </div>
        <span class="pill <?= $sm['pill'] ?>" style="flex-shrink:0"><?= $sm['label'] ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>
</div>

<!-- View modal -->
<div id="viewModalBackdrop" class="modal-backdrop" onclick="if(event.target===this)closeViewModal()">
  <div class="modal">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold">Report detail</h2>
      <button onclick="closeViewModal()" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <div id="viewModalContent"></div>
  </div>
</div>

<!-- Toast -->
<div id="toast" style="position:fixed;bottom:24px;right:24px;z-index:999;background:#1E293B;border:1px solid rgba(59,130,246,.3);border-radius:10px;padding:12px 18px;font-size:13px;color:#E2E8F0;box-shadow:0 8px 32px rgba(0,0,0,.5);transform:translateY(20px);opacity:0;transition:all .3s ease;display:flex;align-items:center;gap:9px;max-width:340px;">
  <i class="fas fa-check-circle" id="toastIcon" style="color:#10B981;flex-shrink:0;font-size:14px;"></i>
  <span id="toastText"></span>
</div>

<script>
const REPORT_TYPE_LABELS = <?= json_encode(array_map(fn($v)=>$v['label'], $reportTypeMeta)) ?>;
const STATUS_LABELS       = <?= json_encode(array_map(fn($v)=>$v['label'], $statusMeta)) ?>;

function viewReport(r) {
  let snapshot = {};
  try { snapshot = JSON.parse(r.report_data || '{}'); } catch(e) {}

  const rows = [
    ['Domain', r.domain_name],
    ['Reference', '#' + String(r.id).padStart(6,'0')],
    ['Requested by', r.account_email],
    ['Delivery email', r.delivery_email],
    ['Report type', REPORT_TYPE_LABELS[r.report_type] || r.report_type],
    ['Status', STATUS_LABELS[r.status] || r.status],
    ['Credits spent', r.credits_spent],
    ['Requested at', r.created_at],
    ['Sent at', r.sent_at || '—'],
  ];
  if (r.delivery_note) rows.push(['User note', r.delivery_note]);

  let html = rows.map(([l,v]) => `<div class="mc-row"><div class="mc-label">${esc(l)}</div><div class="mc-val">${esc(v)}</div></div>`).join('');

  if (snapshot.whois) {
    html += `<div class="mt-4 mb-2 text-xs font-bold text-blue-300 uppercase tracking-wider">Cached WHOIS data</div>`;
    html += `<div class="bg-slate-900/50 rounded-lg p-3 font-mono text-xs text-gray-300 whitespace-pre-wrap break-all">${esc(JSON.stringify(snapshot.whois, null, 2))}</div>`;
  }
  if (snapshot.dead_scan) {
    html += `<div class="mt-4 mb-2 text-xs font-bold text-purple-300 uppercase tracking-wider">Cached dead-site scan</div>`;
    html += `<div class="bg-slate-900/50 rounded-lg p-3 font-mono text-xs text-gray-300 whitespace-pre-wrap break-all">${esc(JSON.stringify(snapshot.dead_scan, null, 2))}</div>`;
  }
  if (!snapshot.whois && !snapshot.dead_scan) {
    html += `<div class="mt-4 text-xs text-gray-500 italic">No cached data was available at request time — report was generated from a live lookup or is pending manual compilation.</div>`;
  }

  document.getElementById('viewModalContent').innerHTML = html;
  document.getElementById('viewModalBackdrop').classList.add('open');
}
function closeViewModal() { document.getElementById('viewModalBackdrop').classList.remove('open'); }

function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

<?php if ($flash): ?>
(function(){
  const t = document.getElementById('toast'), icon = document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = <?= json_encode(strip_tags($flash['msg'])) ?>;
  const isErr = <?= json_encode($flash['type']==='err') ?>;
  icon.className = 'fas ' + (isErr ? 'fa-times-circle' : 'fa-check-circle');
  icon.style.color = isErr ? '#EF4444' : '#10B981';
  t.style.transform='translateY(0)'; t.style.opacity='1';
  setTimeout(()=>{ t.style.transform='translateY(20px)'; t.style.opacity='0'; }, 4200);
})();
<?php endif; ?>
</script>

</body>
</html>