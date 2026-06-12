<?php
require_once 'auth_check.php';
$adminUser = checkAdminAuth();
require_once '../config/database.php';

$conn       = getDBConnection();
$activePage = 'backorders';

// ── Auto-create backorders table ────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS backorders (
        id                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        user_id             INT UNSIGNED     NOT NULL,
        domain_name         VARCHAR(253)     NOT NULL,
        tld                 VARCHAR(63)      NOT NULL,
        status              ENUM('pending','watching','processing','won','lost','canceled','expired')
                            NOT NULL DEFAULT 'pending',
        priority            ENUM('standard','express') NOT NULL DEFAULT 'standard',
        credits_spent       TINYINT UNSIGNED NOT NULL DEFAULT 5,
        payment_id          INT UNSIGNED     NULL,
        estimated_drop_date DATE             NULL,
        actual_drop_date    DATE             NULL,
        drop_detected_at    TIMESTAMP        NULL,
        won_at              TIMESTAMP        NULL,
        registrar           VARCHAR(255)     NULL,
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

$flash = null;

// ── POST actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $boId   = (int)($_POST['bo_id']  ?? 0);
    $action = $_POST['action'];

    switch ($action) {

        // ── Update status ────────────────────────────────────
        case 'update_status':
            $validStatuses = ['pending','watching','processing','won','lost','canceled','expired'];
            $newStatus = in_array($_POST['status'] ?? '', $validStatuses) ? $_POST['status'] : null;
            if (!$newStatus || !$boId) { $flash = ['type'=>'err','msg'=>'Invalid status or ID.']; break; }

            $extraSQL   = '';
            $extraBinds = [];
            $extraTypes = '';

            if ($newStatus === 'won') {
                $extraSQL    = ", won_at = COALESCE(won_at, NOW()), actual_drop_date = COALESCE(actual_drop_date, CURDATE())";
                // Release reserved credits and deduct from balance
                $crStmt = $conn->prepare("
                    UPDATE users u
                    JOIN backorders b ON b.user_id = u.id
                    SET u.credits          = GREATEST(0, u.credits - b.credits_spent),
                        u.credits_reserved = GREATEST(0, u.credits_reserved - b.credits_spent)
                    WHERE b.id = ?
                ");
                $crStmt->bind_param("i", $boId); $crStmt->execute(); $crStmt->close();
                // Ledger
                $boRow = $conn->query("SELECT user_id, domain_name, credits_spent FROM backorders WHERE id=$boId LIMIT 1")->fetch_assoc();
                if ($boRow) {
                    $bal = (int)($conn->query("SELECT credits FROM users WHERE id={$boRow['user_id']} LIMIT 1")->fetch_assoc()['credits'] ?? 0);
                    $neg = -(int)$boRow['credits_spent'];
                    $note = "Backorder won: {$boRow['domain_name']}";
                    $lStmt = $conn->prepare("INSERT INTO credit_ledger (user_id,delta,balance_after,type,domain_name,note) VALUES (?,?,?,'backorder_place',?,?)");
                    if ($lStmt) { $lStmt->bind_param("iiiss",$boRow['user_id'],$neg,$bal,$boRow['domain_name'],$note); $lStmt->execute(); $lStmt->close(); }
                }
            } elseif (in_array($newStatus, ['canceled','lost','expired'])) {
                // Release reserved credits back to user
                $relStmt = $conn->prepare("
                    UPDATE users u
                    JOIN backorders b ON b.user_id = u.id
                    SET u.credits_reserved = GREATEST(0, u.credits_reserved - b.credits_spent)
                    WHERE b.id = ? AND b.status IN ('pending','watching','processing')
                ");
                $relStmt->bind_param("i", $boId); $relStmt->execute(); $relStmt->close();
            } elseif ($newStatus === 'processing') {
                $extraSQL = ", drop_detected_at = COALESCE(drop_detected_at, NOW())";
            }

            $upd = $conn->prepare("UPDATE backorders SET status=?, updated_at=NOW() $extraSQL WHERE id=?");
            $upd->bind_param("si", $newStatus, $boId); $upd->execute(); $upd->close();

            // Fetch domain for log
            $domRow = $conn->query("SELECT domain_name FROM backorders WHERE id=$boId LIMIT 1")->fetch_assoc();
            logAdminActivity($adminUser['id'], 'UPDATE_BACKORDER_STATUS', "Set backorder #{$boId} ({$domRow['domain_name']}) to {$newStatus}");
            $flash = ['type'=>'ok','msg'=>"Backorder #{$boId} marked as <strong>{$newStatus}</strong>."];
            break;

        // ── Update WHOIS / drop date ─────────────────────────
        case 'update_whois':
            $estDrop   = trim($_POST['estimated_drop_date'] ?? '') ?: null;
            $whoisExp  = trim($_POST['whois_expiry_date']   ?? '') ?: null;
            $registrar = substr(strip_tags(trim($_POST['registrar'] ?? '')), 0, 255) ?: null;
            $notes     = substr(strip_tags(trim($_POST['notes']     ?? '')), 0, 2000) ?: null;

            $upd = $conn->prepare("
                UPDATE backorders
                SET estimated_drop_date=?, whois_expiry_date=?, registrar=?, notes=?, updated_at=NOW()
                WHERE id=?
            ");
            $upd->bind_param("ssssi", $estDrop, $whoisExp, $registrar, $notes, $boId);
            $upd->execute(); $upd->close();
            logAdminActivity($adminUser['id'], 'UPDATE_BACKORDER_WHOIS', "Updated WHOIS data for backorder #{$boId}");
            $flash = ['type'=>'ok','msg'=>"Backorder #{$boId} WHOIS data updated."];
            break;

        // ── Bulk status update ───────────────────────────────
        case 'bulk_status':
            $ids       = array_map('intval', (array)($_POST['selected_ids'] ?? []));
            $newStatus = in_array($_POST['bulk_status_val'] ?? '', ['watching','processing','won','lost','expired'])
                         ? $_POST['bulk_status_val'] : null;
            if ($ids && $newStatus) {
                $ph = implode(',', $ids);
                $conn->query("UPDATE backorders SET status='$newStatus', updated_at=NOW() WHERE id IN ($ph)");
                logAdminActivity($adminUser['id'], 'BULK_UPDATE_BACKORDER', "Bulk set ".count($ids)." backorders to $newStatus");
                $flash = ['type'=>'ok','msg'=>count($ids)." backorder(s) set to <strong>{$newStatus}</strong>."];
            }
            break;

        // ── Delete (admin only, for cleanup) ─────────────────
        case 'delete':
            $domRow = $conn->query("SELECT domain_name, status, user_id, credits_spent FROM backorders WHERE id=$boId LIMIT 1")->fetch_assoc();
            if ($domRow) {
                if (in_array($domRow['status'], ['pending','watching'])) {
                    // Release credits
                    $conn->query("UPDATE users SET credits_reserved=GREATEST(0,credits_reserved-{$domRow['credits_spent']}) WHERE id={$domRow['user_id']}");
                }
                $conn->query("DELETE FROM backorders WHERE id=$boId");
                logAdminActivity($adminUser['id'], 'DELETE_BACKORDER', "Deleted backorder #{$boId} ({$domRow['domain_name']})");
                $flash = ['type'=>'ok','msg'=>"Backorder #{$boId} deleted."];
            }
            break;
    }
}

// ── CSV export ───────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="backorders_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','User ID','Email','Domain','TLD','Status','Priority','Credits','Est. Drop','Actual Drop','Registrar','WHOIS Expiry','Won At','Created']);
    $rs = $conn->query("
        SELECT b.id, b.user_id, u.email, b.domain_name, b.tld, b.status, b.priority,
               b.credits_spent, b.estimated_drop_date, b.actual_drop_date,
               b.registrar, b.whois_expiry_date, b.won_at, b.created_at
        FROM backorders b JOIN users u ON u.id=b.user_id
        ORDER BY b.created_at DESC
    ");
    while ($r = $rs->fetch_assoc()) fputcsv($out, $r);
    fclose($out);
    $conn->close(); exit();
}

// ── Filters ──────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$statusFilter = in_array($_GET['status'] ?? '', ['pending','watching','processing','won','lost','canceled','expired','']) ? ($_GET['status'] ?? '') : '';
$prioFilter   = in_array($_GET['priority'] ?? '', ['standard','express','']) ? ($_GET['priority'] ?? '') : '';
$sortCol      = in_array($_GET['sort'] ?? '', ['created_at','estimated_drop_date','domain_name','status']) ? $_GET['sort'] : 'created_at';
$sortDir      = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

$where = ['1=1']; $binds = []; $types = '';
if ($search) {
    $like    = "%$search%";
    $where[] = "(b.domain_name LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)";
    $binds   = array_merge($binds, [$like,$like,$like]); $types .= 'sss';
}
if ($statusFilter) { $where[] = "b.status=?";   $binds[] = $statusFilter; $types .= 's'; }
if ($prioFilter)   { $where[] = "b.priority=?"; $binds[] = $prioFilter;   $types .= 's'; }

$whereSQL = implode(' AND ', $where);
$sortMap  = ['created_at'=>'b.created_at','estimated_drop_date'=>'b.estimated_drop_date','domain_name'=>'b.domain_name','status'=>'b.status'];
$orderSQL = ($sortMap[$sortCol] ?? 'b.created_at') . ' ' . $sortDir;

// Count
$cStmt = $conn->prepare("SELECT COUNT(*) as c FROM backorders b JOIN users u ON u.id=b.user_id WHERE $whereSQL");
if ($types) $cStmt->bind_param($types, ...$binds);
$cStmt->execute();
$totalRows  = (int)$cStmt->get_result()->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$cStmt->close();

// Data
$dStmt = $conn->prepare("
    SELECT b.*, u.email, u.full_name, u.avatar
    FROM backorders b
    JOIN users u ON u.id = b.user_id
    WHERE $whereSQL
    ORDER BY $orderSQL
    LIMIT ? OFFSET ?
");
$allBinds = array_merge($binds, [$perPage, $offset]);

$dStmt->bind_param($types . 'ii', ...$allBinds);
$dStmt->execute();

$result = $dStmt->get_result();

$backorders = [];
while ($r = $result->fetch_assoc()) {
    $backorders[] = $r;
}

$result->free(); 
$dStmt->close();

// ── Stats ─────────────────────────────────────────────────────
$safe = fn($q): int => (int)($conn->query($q)?->fetch_assoc()['c'] ?? 0);
$statTotal      = $safe("SELECT COUNT(*) as c FROM backorders");
$statActive     = $safe("SELECT COUNT(*) as c FROM backorders WHERE status IN ('pending','watching','processing')");
$statProcessing = $safe("SELECT COUNT(*) as c FROM backorders WHERE status='processing'");
$statWon        = $safe("SELECT COUNT(*) as c FROM backorders WHERE status='won'");
$statLost       = $safe("SELECT COUNT(*) as c FROM backorders WHERE status='lost'");
$statDropSoon   = $safe("SELECT COUNT(*) as c FROM backorders WHERE status IN ('pending','watching') AND estimated_drop_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");

$conn->close();

// ── Helpers ────────────────────────────────────────────────────
function boPageUrl(int $p, array $e=[]): string {
    $params = array_merge($_GET,$e,['page'=>$p]); unset($params['export']);
    return '?'.http_build_query(array_filter($params,fn($v)=>$v!==''));
}
function boSortUrl(string $col): string {
    $dir = ($_GET['sort']??'')===$col && ($_GET['dir']??'desc')==='asc' ? 'desc' : 'asc';
    return boPageUrl(1,['sort'=>$col,'dir'=>$dir]);
}
function boSortIcon(string $col): string {
    if (($_GET['sort']??'')!==$col) return '<i class="fas fa-sort text-gray-600 ml-1 text-xs"></i>';
    return ($_GET['dir']??'desc')==='asc'
        ? '<i class="fas fa-sort-up text-blue-400 ml-1 text-xs"></i>'
        : '<i class="fas fa-sort-down text-blue-400 ml-1 text-xs"></i>';
}

$statusMeta = [
    'pending'    => ['icon'=>'fa-clock',          'cls'=>'b-pending',    'label'=>'Pending'],
    'watching'   => ['icon'=>'fa-eye',             'cls'=>'b-watching',   'label'=>'Watching'],
    'processing' => ['icon'=>'fa-bolt',            'cls'=>'b-processing', 'label'=>'Processing'],
    'won'        => ['icon'=>'fa-trophy',          'cls'=>'b-won',        'label'=>'Won'],
    'lost'       => ['icon'=>'fa-times-circle',    'cls'=>'b-lost',       'label'=>'Lost'],
    'canceled'   => ['icon'=>'fa-ban',             'cls'=>'b-canceled',   'label'=>'Canceled'],
    'expired'    => ['icon'=>'fa-hourglass-end',   'cls'=>'b-expired',    'label'=>'Expired'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>Backorders — CheckDomain Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0F172A;font-family:'Inter',sans-serif;overflow-x:hidden;color:#fff}

.stat-card{background:linear-gradient(135deg,rgba(30,58,138,.3),rgba(16,185,129,.1));backdrop-filter:blur(10px);border:1px solid rgba(59,130,246,.3);transition:all .3s ease}
.stat-card:hover{transform:translateY(-2px);border-color:rgba(16,185,129,.5)}
.main-content{transition:margin-left .3s ease}
.tbl-row{transition:background .15s}
.tbl-row:hover{background:rgba(59,130,246,.06)!important}

/* Modals */
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);z-index:100;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s}
.modal-backdrop.open{opacity:1;pointer-events:all}
.modal-box{background:#1E293B;border:1px solid rgba(59,130,246,.3);border-radius:1rem;padding:1.75rem;max-width:480px;width:90%;transform:scale(.96);transition:transform .2s;max-height:90vh;overflow-y:auto}
.modal-backdrop.open .modal-box{transform:scale(1)}

/* Flash */
.flash-ok  {background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#34D399}
.flash-warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:#FCD34D}
.flash-err {background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.3); color:#FCA5A5}

/* Badges */
.badge{display:inline-flex;align-items:center;gap:4px;font-size:.7rem;font-weight:600;padding:2px 8px;border-radius:9999px;white-space:nowrap}
.b-pending    {background:rgba(245,158,11,.15);color:#FCD34D}
.b-watching   {background:rgba(59,130,246,.15); color:#93C5FD}
.b-processing {background:rgba(168,85,247,.2);  color:#C4B5FD;animation:glow 2s ease-in-out infinite}
.b-won        {background:rgba(16,185,129,.15);  color:#34D399}
.b-lost       {background:rgba(239,68,68,.15);   color:#FCA5A5}
.b-canceled   {background:rgba(107,114,128,.2);  color:#9CA3AF}
.b-expired    {background:rgba(107,114,128,.2);  color:#9CA3AF}
.b-standard   {background:rgba(71,85,105,.3);    color:#94A3B8}
.b-express    {background:rgba(168,85,247,.15);  color:#C4B5FD}
@keyframes glow{0%,100%{opacity:1}50%{opacity:.65}}

/* Pulse dot */
.pulse-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0;animation:pd 2s infinite}
@keyframes pd{0%,100%{opacity:1}50%{opacity:.25}}
.pd-amber  {background:#F59E0B}
.pd-blue   {background:#3B82F6}
.pd-purple {background:#9333EA}

/* Drop countdown chip */
.drop-chip{font-size:.7rem;font-family:monospace;font-weight:700;padding:2px 7px;border-radius:4px}
.dc-urgent{background:rgba(239,68,68,.15);color:#FCA5A5}
.dc-soon  {background:rgba(245,158,11,.15);color:#FCD34D}
.dc-ok    {background:rgba(59,130,246,.12);color:#93C5FD}

/* Inputs */
.inp{background:rgba(51,65,85,.8);border:1px solid rgba(71,85,105,1);border-radius:.5rem;padding:.5rem .75rem;color:#fff;width:100%;font-size:.875rem;transition:border-color .2s;outline:none}
.inp:focus{border-color:#3B82F6}
.inp::placeholder{color:#64748B}
.form-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#64748B;margin-bottom:.25rem;display:block}

.btn-primary  {background:#2563EB;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-primary:hover{background:#1D4ED8}
.btn-secondary{background:#334155;color:#CBD5E1;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-secondary:hover{background:#475569}
.btn-danger   {background:#DC2626;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-danger:hover{background:#B91C1C}
.btn-amber    {background:#D97706;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-amber:hover{background:#B45309}
.btn-green    {background:#059669;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-green:hover{background:#047857}
.btn-sm{padding:.3rem .75rem!important;font-size:.75rem!important}

.chk{width:15px;height:15px;cursor:pointer;accent-color:#3B82F6}

::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:rgba(15,23,42,.5);border-radius:10px}
::-webkit-scrollbar-thumb{background:#3B82F6;border-radius:10px}
::-webkit-scrollbar-thumb:hover{background:#60A5FA}

@media(max-width:768px){.main-content{margin-left:0!important}.p-8{padding:1rem}.hide-mobile{display:none!important}}
@media(max-width:480px){.hide-sm{display:none!important}}
</style>
</head>
<body class="text-white">

<?php include_once 'includes/sidebar.php'; ?>

<div class="main-content" style="margin-left:16rem;">
<div class="p-4 md:p-8">

  <!-- Flash -->
  <?php if ($flash): ?>
  <div class="flash-<?= $flash['type'] ?> rounded-xl px-4 py-3 mb-6 flex items-start gap-3 text-sm">
    <i class="fas <?= $flash['type']==='ok'?'fa-check-circle':($flash['type']==='warn'?'fa-exclamation-triangle':'fa-times-circle') ?> mt-0.5 flex-shrink-0"></i>
    <span><?= $flash['msg'] ?></span>
  </div>
  <?php endif; ?>

  <!-- Page header -->
  <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
    <div>
      <h1 class="text-2xl md:text-3xl font-bold">Backorders</h1>
      <p class="text-gray-400 text-sm mt-1">
        <?= number_format($totalRows) ?> backorder<?= $totalRows!==1?'s':'' ?>
        <?php if ($search||$statusFilter||$prioFilter): ?><span class="text-blue-400">(filtered)</span><?php endif; ?>
      </p>
    </div>
    <div class="flex flex-wrap gap-3">
      <a href="?export=1" class="btn-secondary flex items-center gap-2 text-sm">
        <i class="fas fa-download text-xs"></i> Export CSV
      </a>
    </div>
  </div>

  <!-- Stats -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-8">
    <?php
    $cards = [
      ['lbl'=>'Total',        'val'=>$statTotal,      'icon'=>'fa-layer-group',   'c'=>'blue'],
      ['lbl'=>'Active',       'val'=>$statActive,     'icon'=>'fa-eye',            'c'=>'amber'],
      ['lbl'=>'Processing',   'val'=>$statProcessing, 'icon'=>'fa-bolt',           'c'=>'purple'],
      ['lbl'=>'Won',          'val'=>$statWon,        'icon'=>'fa-trophy',         'c'=>'green'],
      ['lbl'=>'Lost',         'val'=>$statLost,       'icon'=>'fa-times-circle',   'c'=>'red'],
      ['lbl'=>'Drop in 7d',   'val'=>$statDropSoon,   'icon'=>'fa-calendar-day',   'c'=>'amber'],
    ];
    $cmap=['blue'=>['bg'=>'bg-blue-500/20','t'=>'text-blue-400'],'amber'=>['bg'=>'bg-amber-500/20','t'=>'text-amber-400'],'green'=>['bg'=>'bg-green-500/20','t'=>'text-green-400'],'red'=>['bg'=>'bg-red-500/20','t'=>'text-red-400'],'purple'=>['bg'=>'bg-purple-500/20','t'=>'text-purple-400']];
    foreach ($cards as $c):
      $cl = $cmap[$c['c']] ?? $cmap['blue'];
    ?>
    <div class="stat-card rounded-xl p-4">
      <div class="flex justify-between items-start">
        <div>
          <p class="text-gray-400 text-xs"><?= $c['lbl'] ?></p>
          <p class="text-xl font-bold mt-1 <?= $cl['t'] ?>"><?= number_format($c['val']) ?></p>
        </div>
        <div class="w-9 h-9 <?= $cl['bg'] ?> rounded-full flex items-center justify-center flex-shrink-0">
          <i class="fas <?= $c['icon'] ?> <?= $cl['t'] ?> text-sm"></i>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Drop-soon banner -->
  <?php if ($statDropSoon > 0): ?>
  <div class="bg-amber-500/10 border border-amber-500/30 rounded-xl px-4 py-3 mb-5 flex items-center gap-3 text-sm">
    <span class="pulse-dot pd-amber flex-shrink-0"></span>
    <span class="text-amber-300">
      <strong><?= $statDropSoon ?> backorder<?= $statDropSoon!==1?'s':'' ?></strong> have estimated drop dates in the next 7 days.
    </span>
    <a href="?status=watching" class="ml-auto text-amber-400 hover:text-amber-300 text-xs underline whitespace-nowrap">View all →</a>
  </div>
  <?php endif; ?>

  <!-- Processing banner -->
  <?php if ($statProcessing > 0): ?>
  <div class="bg-purple-500/10 border border-purple-500/30 rounded-xl px-4 py-3 mb-5 flex items-center gap-3 text-sm">
    <span class="pulse-dot pd-purple flex-shrink-0"></span>
    <span class="text-purple-300">
      <strong><?= $statProcessing ?> backorder<?= $statProcessing!==1?'s':'' ?></strong> currently in capture processing.
    </span>
    <a href="?status=processing" class="ml-auto text-purple-400 hover:text-purple-300 text-xs underline whitespace-nowrap">View →</a>
  </div>
  <?php endif; ?>

  <!-- Filters -->
  <div class="bg-slate-800/50 rounded-xl p-4 mb-5">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
      <div class="flex-1 min-w-44">
        <label class="form-label">Search</label>
        <div class="relative">
          <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
          <input class="inp pl-8" type="text" name="search"
                 value="<?= htmlspecialchars($search) ?>"
                 placeholder="Domain, email or name…" autocomplete="off">
        </div>
      </div>
      <div class="w-36">
        <label class="form-label">Status</label>
        <select class="inp" name="status">
          <option value="">All statuses</option>
          <?php foreach (['pending','watching','processing','won','lost','canceled','expired'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="w-32">
        <label class="form-label">Priority</label>
        <select class="inp" name="priority">
          <option value="">All</option>
          <option value="standard" <?= $prioFilter==='standard'?'selected':'' ?>>Standard</option>
          <option value="express"  <?= $prioFilter==='express'?'selected':'' ?>>Express</option>
        </select>
      </div>
      <div class="w-40">
        <label class="form-label">Sort</label>
        <select class="inp" name="sort" onchange="this.form.submit()">
          <option value="created_at"           <?= $sortCol==='created_at'?'selected':'' ?>>Created</option>
          <option value="estimated_drop_date"  <?= $sortCol==='estimated_drop_date'?'selected':'' ?>>Drop date</option>
          <option value="domain_name"          <?= $sortCol==='domain_name'?'selected':'' ?>>Domain</option>
          <option value="status"               <?= $sortCol==='status'?'selected':'' ?>>Status</option>
        </select>
      </div>
      <input type="hidden" name="dir" value="<?= $sortDir==='ASC'?'asc':'desc' ?>">
      <button type="submit" class="btn-primary btn-sm flex items-center gap-2">
        <i class="fas fa-filter text-xs"></i> Filter
      </button>
      <?php if ($search||$statusFilter||$prioFilter): ?>
      <a href="backorders.php" class="btn-secondary btn-sm flex items-center gap-2">
        <i class="fas fa-times text-xs"></i> Clear
      </a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Bulk action bar -->
  <div id="bulkBar" class="hidden bg-blue-900/30 border border-blue-500/30 rounded-xl px-4 py-3 mb-4 flex items-center gap-3 text-sm flex-wrap">
    <span id="bulkCount" class="font-mono text-blue-300">0 selected</span>
    <span class="text-gray-600">·</span>
    <span class="text-gray-400 text-xs">Set all to:</span>
    <?php foreach (['watching'=>'Watching','processing'=>'Processing','won'=>'Won','lost'=>'Lost','expired'=>'Expired'] as $v=>$l): ?>
    <button onclick="bulkSetStatus('<?= $v ?>')" class="btn-secondary btn-sm text-xs"><?= $l ?></button>
    <?php endforeach; ?>
    <button onclick="clearSelection()" class="text-gray-500 hover:text-gray-300 text-xs ml-auto">Deselect all</button>
  </div>

  <!-- Table -->
  <div class="bg-slate-800/50 rounded-xl overflow-hidden">
    <?php if (!empty($backorders)): ?>
    <form method="POST" id="bulkForm">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-700/50 border-b border-gray-700 text-xs uppercase tracking-wide text-gray-400">
          <tr>
            <th class="p-4 w-10"><input type="checkbox" id="selectAll" class="chk"></th>
            <th class="p-4 text-left">
              <a href="<?= boSortUrl('domain_name') ?>" class="hover:text-white flex items-center">
                Domain <?= boSortIcon('domain_name') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-sm">User</th>
            <th class="p-4 text-left">
              <a href="<?= boSortUrl('status') ?>" class="hover:text-white flex items-center">
                Status <?= boSortIcon('status') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-mobile">Priority</th>
            <th class="p-4 text-left hide-mobile">
              <a href="<?= boSortUrl('estimated_drop_date') ?>" class="hover:text-white flex items-center">
                Drop date <?= boSortIcon('estimated_drop_date') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-mobile">WHOIS / Registrar</th>
            <th class="p-4 text-left hide-sm">
              <a href="<?= boSortUrl('created_at') ?>" class="hover:text-white flex items-center">
                Placed <?= boSortIcon('created_at') ?>
              </a>
            </th>
            <th class="p-4 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-700/50">
          <?php foreach ($backorders as $bo):
            $sm         = $statusMeta[$bo['status']] ?? $statusMeta['pending'];
            $initials   = strtoupper(substr($bo['full_name'] ?: $bo['email'], 0, 1));
            $isActive   = in_array($bo['status'], ['pending','watching','processing']);
            $parts      = explode('.', $bo['domain_name']);
            $sld        = $parts[0];
            $tldPart    = '.' . implode('.', array_slice($parts, 1));
            $daysLeft   = null;
            $dropClass  = '';
            if ($bo['estimated_drop_date']) {
                $daysLeft  = (int)ceil((strtotime($bo['estimated_drop_date']) - time()) / 86400);
                $dropClass = $daysLeft <= 3 ? 'dc-urgent' : ($daysLeft <= 10 ? 'dc-soon' : 'dc-ok');
            }
          ?>
          <tr class="tbl-row">

            <!-- Checkbox -->
            <td class="p-4">
              <input type="checkbox" name="selected_ids[]" value="<?= (int)$bo['id'] ?>"
                     class="chk row-chk" onchange="onCheckChange()">
            </td>

            <!-- Domain -->
            <td class="p-4">
              <div class="font-mono font-bold text-sm text-white">
                <?= htmlspecialchars($sld) ?><span class="text-gray-400 font-normal"><?= htmlspecialchars($tldPart) ?></span>
              </div>
              <div class="text-gray-500 text-xs mt-0.5">#<?= (int)$bo['id'] ?> · <?= (int)$bo['credits_spent'] ?> credits</div>
            </td>

            <!-- User -->
            <td class="p-4 hide-sm">
              <div class="flex items-center gap-2">
                <div class="w-7 h-7 rounded-full flex-shrink-0 flex items-center justify-center font-bold text-xs"
                     style="background:linear-gradient(135deg,#2563EB,#06B6D4)">
                  <?php if ($bo['avatar']): ?>
                  <img src="<?= htmlspecialchars($bo['avatar']) ?>" class="w-7 h-7 rounded-full object-cover" alt="">
                  <?php else: ?>
                  <?= htmlspecialchars($initials) ?>
                  <?php endif; ?>
                </div>
                <div class="min-w-0">
                  <div class="text-white text-xs truncate max-w-32"><?= htmlspecialchars($bo['full_name'] ?: '—') ?></div>
                  <div class="text-gray-500 text-xs truncate max-w-32">
                    <a href="users.php?search=<?= urlencode($bo['email']) ?>" class="hover:text-blue-400 transition">
                      <?= htmlspecialchars($bo['email']) ?>
                    </a>
                  </div>
                </div>
              </div>
            </td>

            <!-- Status -->
            <td class="p-4">
              <div class="flex items-center gap-2">
                <?php if ($isActive): ?>
                <span class="pulse-dot <?= $bo['status']==='processing'?'pd-purple':($bo['status']==='watching'?'pd-blue':'pd-amber') ?>"></span>
                <?php endif; ?>
                <span class="badge <?= $sm['cls'] ?>">
                  <i class="fas <?= $sm['icon'] ?> text-xs"></i>
                  <?= $sm['label'] ?>
                </span>
              </div>
              <?php if ($bo['status'] === 'won' && $bo['won_at']): ?>
              <div class="text-green-400 text-xs mt-0.5">Won <?= date('M j, Y', strtotime($bo['won_at'])) ?></div>
              <?php endif; ?>
            </td>

            <!-- Priority -->
            <td class="p-4 hide-mobile">
              <span class="badge b-<?= $bo['priority'] ?>">
                <?php if ($bo['priority']==='express'): ?>
                <i class="fas fa-bolt text-xs"></i> Express
                <?php else: ?>
                Standard
                <?php endif; ?>
              </span>
            </td>

            <!-- Drop date -->
            <td class="p-4 hide-mobile">
              <?php if ($bo['estimated_drop_date']): ?>
              <div class="text-white text-xs font-medium"><?= date('M j, Y', strtotime($bo['estimated_drop_date'])) ?></div>
              <?php if ($daysLeft !== null && $isActive): ?>
              <span class="drop-chip <?= $dropClass ?> mt-1 inline-block">
                <?= $daysLeft > 0 ? "in {$daysLeft}d" : ($daysLeft === 0 ? 'Today' : 'Past') ?>
              </span>
              <?php elseif ($bo['actual_drop_date']): ?>
              <div class="text-gray-500 text-xs mt-0.5">Dropped <?= date('M j', strtotime($bo['actual_drop_date'])) ?></div>
              <?php endif; ?>
              <?php else: ?>
              <span class="text-gray-600 text-xs">Not set</span>
              <?php endif; ?>
            </td>

            <!-- WHOIS / Registrar -->
            <td class="p-4 hide-mobile">
              <?php if ($bo['registrar']): ?>
              <div class="text-white text-xs truncate max-w-36" title="<?= htmlspecialchars($bo['registrar']) ?>">
                <?= htmlspecialchars($bo['registrar']) ?>
              </div>
              <?php endif; ?>
              <?php if ($bo['whois_expiry_date']): ?>
              <div class="text-gray-500 text-xs mt-0.5">Exp <?= date('M j, Y', strtotime($bo['whois_expiry_date'])) ?></div>
              <?php else: ?>
              <span class="text-gray-600 text-xs">No WHOIS</span>
              <?php endif; ?>
            </td>

            <!-- Placed date -->
            <td class="p-4 hide-sm">
              <div class="text-xs text-white"><?= date('M j, Y', strtotime($bo['created_at'])) ?></div>
              <div class="text-xs text-gray-500"><?= date('H:i', strtotime($bo['created_at'])) ?></div>
            </td>

            <!-- Actions -->
            <td class="p-4 text-right">
              <div class="flex items-center justify-end gap-1.5 flex-wrap">
                <!-- Update status -->
                <button type="button" onclick="openStatusModal(<?= htmlspecialchars(json_encode($bo),ENT_QUOTES) ?>)"
                        class="w-8 h-8 bg-blue-500/20 hover:bg-blue-500/30 rounded-lg flex items-center justify-center text-blue-400 transition"
                        title="Update status">
                  <i class="fas fa-edit text-xs"></i>
                </button>
                <!-- Update WHOIS -->
                <button type="button" onclick="openWhoisModal(<?= htmlspecialchars(json_encode($bo),ENT_QUOTES) ?>)"
                        class="w-8 h-8 bg-amber-500/20 hover:bg-amber-500/30 rounded-lg flex items-center justify-center text-amber-400 transition"
                        title="Update WHOIS / drop date">
                  <i class="fas fa-calendar-alt text-xs"></i>
                </button>
                <!-- Delete -->
                <button type="button" onclick="openDeleteModal(<?= (int)$bo['id'] ?>, '<?= htmlspecialchars($bo['domain_name'],ENT_QUOTES) ?>')"
                        class="w-8 h-8 bg-red-500/20 hover:bg-red-500/30 rounded-lg flex items-center justify-center text-red-400 transition"
                        title="Delete">
                  <i class="fas fa-trash text-xs"></i>
                </button>
              </div>
            </td>

          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination footer -->
    <div class="flex flex-col sm:flex-row justify-between items-center gap-4 p-4 bg-slate-700/30 border-t border-gray-700">
      <div class="text-xs text-gray-400">
        Showing <?= number_format($offset+1) ?>–<?= number_format(min($offset+$perPage,$totalRows)) ?> of <?= number_format($totalRows) ?>
      </div>
      <?php if ($totalPages > 1): ?>
      <div class="flex flex-wrap justify-center gap-1.5">
        <?php if ($page>1): ?><a href="<?= boPageUrl($page-1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
        <?php
        $s=max(1,$page-2);$e=min($totalPages,$page+2);
        if($s>1) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>';
        for($i=$s;$i<=$e;$i++):
        ?><a href="<?= boPageUrl($i) ?>" class="px-3 py-1.5 rounded text-xs transition <?= $i===$page?'bg-blue-600':'bg-slate-700 hover:bg-slate-600' ?>"><?= $i ?></a><?php endfor;
        if($e<$totalPages) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>'; ?>
        <?php if ($page<$totalPages): ?><a href="<?= boPageUrl($page+1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    </form>

    <?php else: ?>
    <div class="text-center py-16">
      <i class="fas fa-clock text-5xl text-gray-700 mb-4 block"></i>
      <p class="text-gray-400 mb-1">No backorders found</p>
      <?php if ($search||$statusFilter||$prioFilter): ?>
      <a href="backorders.php" class="text-blue-400 hover:text-blue-300 text-sm">Clear filters</a>
      <?php else: ?>
      <p class="text-gray-600 text-sm">Backorders placed by users will appear here.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

</div>
</div>

<!-- ═══════════════════════════════
     MODALS
═══════════════════════════════ -->

<!-- Status update modal -->
<div class="modal-backdrop" id="statusModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold">Update status</h2>
      <button onclick="closeModal('statusModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <div class="bg-slate-900 rounded-lg p-3 mb-4 text-sm">
      <div class="font-mono text-white font-bold" id="sm-domain"></div>
      <div class="text-gray-400 text-xs mt-0.5">User: <span id="sm-email" class="text-white"></span></div>
      <div class="text-gray-400 text-xs mt-0.5">Current: <span id="sm-current" class="text-amber-300 font-mono"></span></div>
    </div>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="bo_id" id="sm-id">
      <div>
        <label class="form-label">New status <span class="text-red-400">*</span></label>
        <select class="inp" name="status" id="sm-status">
          <?php foreach (['pending','watching','processing','won','lost','canceled','expired'] as $s): ?>
          <option value="<?= $s ?>"><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div id="sm-warning" class="hidden bg-amber-500/10 border border-amber-500/30 rounded-lg px-3 py-2 text-amber-300 text-xs"></div>
      <div class="flex gap-3 justify-end pt-2">
        <button type="button" onclick="closeModal('statusModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-primary flex items-center gap-2">
          <i class="fas fa-check text-xs"></i> Update
        </button>
      </div>
    </form>
  </div>
</div>

<!-- WHOIS / drop date modal -->
<div class="modal-backdrop" id="whoisModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold">Update WHOIS &amp; drop data</h2>
      <button onclick="closeModal('whoisModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <div class="text-gray-400 text-sm mb-4">
      Backorder <strong id="wm-domain" class="font-mono text-white"></strong>
    </div>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="update_whois">
      <input type="hidden" name="bo_id" id="wm-id">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Estimated drop date</label>
          <input class="inp" type="date" name="estimated_drop_date" id="wm-drop">
          <p class="text-xs text-gray-500 mt-1">When do we expect the domain to drop?</p>
        </div>
        <div>
          <label class="form-label">WHOIS expiry date</label>
          <input class="inp" type="date" name="whois_expiry_date" id="wm-expiry">
        </div>
      </div>
      <div>
        <label class="form-label">Registrar</label>
        <input class="inp" type="text" name="registrar" id="wm-registrar"
               placeholder="e.g. GoDaddy, Namecheap" maxlength="255">
      </div>
      <div>
        <label class="form-label">Notes</label>
        <textarea class="inp" name="notes" id="wm-notes" rows="3"
                  placeholder="Internal notes about this backorder…" maxlength="2000"
                  style="resize:vertical;"></textarea>
      </div>
      <div class="flex gap-3 justify-end pt-2">
        <button type="button" onclick="closeModal('whoisModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-amber flex items-center gap-2">
          <i class="fas fa-save text-xs"></i> Save
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Delete modal -->
<div class="modal-backdrop" id="deleteModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold text-red-400"><i class="fas fa-trash mr-2"></i>Delete backorder</h2>
      <button onclick="closeModal('deleteModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <p class="text-gray-300 text-sm mb-2">
      Delete backorder for <strong id="del-domain" class="font-mono text-white"></strong>?
    </p>
    <p class="text-gray-500 text-xs mb-5">
      Reserved credits will be released back to the user. This cannot be undone.
    </p>
    <form method="POST" class="flex gap-3 justify-end">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="bo_id" id="del-id">
      <button type="button" onclick="closeModal('deleteModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-danger flex items-center gap-2">
        <i class="fas fa-trash text-xs"></i> Delete
      </button>
    </form>
  </div>
</div>

<!-- Toast -->
<div id="toast"
     style="position:fixed;bottom:24px;right:24px;z-index:999;
            background:#1E293B;border:1px solid rgba(59,130,246,.3);
            border-radius:10px;padding:12px 18px;font-size:13px;color:#E2E8F0;
            box-shadow:0 8px 32px rgba(0,0,0,.5);
            transform:translateY(20px);opacity:0;transition:all .3s ease;
            display:flex;align-items:center;gap:9px;max-width:340px;">
  <i class="fas fa-check-circle" id="toastIcon" style="color:#10B981;flex-shrink:0;font-size:14px;"></i>
  <span id="toastText"></span>
</div>

<script>
// ── Modal helpers ─────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-backdrop').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// ── Status modal ──────────────────────────────────────────
function openStatusModal(bo) {
  document.getElementById('sm-id').value         = bo.id;
  document.getElementById('sm-domain').textContent = bo.domain_name;
  document.getElementById('sm-email').textContent  = bo.email;
  document.getElementById('sm-current').textContent = bo.status;
  document.getElementById('sm-status').value       = bo.status;
  updateStatusWarning(bo.status, bo.credits_spent);
  document.getElementById('sm-status').addEventListener('change', function() {
    updateStatusWarning(this.value, bo.credits_spent);
  });
  openModal('statusModal');
}

function updateStatusWarning(newStatus, credits) {
  const w = document.getElementById('sm-warning');
  const msgs = {
    won:      `User's ${credits} reserved credits will be <strong>deducted permanently</strong>.`,
    canceled: `User's ${credits} reserved credits will be <strong>released</strong> back to their balance.`,
    lost:     `User's ${credits} reserved credits will be <strong>released</strong> back to their balance.`,
    expired:  `User's ${credits} reserved credits will be <strong>released</strong> back to their balance.`,
  };
  if (msgs[newStatus]) {
    w.innerHTML = '<i class="fas fa-exclamation-triangle mr-1"></i>' + msgs[newStatus];
    w.classList.remove('hidden');
  } else {
    w.classList.add('hidden');
  }
}

// ── WHOIS modal ───────────────────────────────────────────
function openWhoisModal(bo) {
  document.getElementById('wm-id').value         = bo.id;
  document.getElementById('wm-domain').textContent = bo.domain_name;
  document.getElementById('wm-drop').value        = bo.estimated_drop_date || '';
  document.getElementById('wm-expiry').value      = bo.whois_expiry_date   || '';
  document.getElementById('wm-registrar').value   = bo.registrar           || '';
  document.getElementById('wm-notes').value       = bo.notes               || '';
  openModal('whoisModal');
}

// ── Delete modal ──────────────────────────────────────────
function openDeleteModal(id, domain) {
  document.getElementById('del-id').value        = id;
  document.getElementById('del-domain').textContent = domain;
  openModal('deleteModal');
}

// ── Bulk checkboxes ───────────────────────────────────────
const bulkBar   = document.getElementById('bulkBar');
const bulkCount = document.getElementById('bulkCount');

function onCheckChange() {
  const checked = document.querySelectorAll('.row-chk:checked');
  bulkCount.textContent = checked.length + ' selected';
  bulkBar.classList.toggle('hidden', checked.length === 0);
  const sa = document.getElementById('selectAll');
  if (sa) sa.checked = checked.length === document.querySelectorAll('.row-chk').length && checked.length > 0;
}

document.getElementById('selectAll')?.addEventListener('change', e => {
  document.querySelectorAll('.row-chk').forEach(c => c.checked = e.target.checked);
  onCheckChange();
});

function clearSelection() {
  document.querySelectorAll('.row-chk').forEach(c => c.checked = false);
  const sa = document.getElementById('selectAll'); if (sa) sa.checked = false;
  onCheckChange();
}

function bulkSetStatus(val) {
  const ids = [...document.querySelectorAll('.row-chk:checked')].map(c => c.value);
  if (!ids.length) return;
  if (!confirm(`Set ${ids.length} backorder(s) to "${val}"? Credit adjustments apply for won/canceled.`)) return;

  const form = document.getElementById('bulkForm');
  const setHidden = (name, value) => {
    let el = form.querySelector(`input[name="${name}"]`);
    if (!el) { el = document.createElement('input'); el.type='hidden'; el.name=name; form.appendChild(el); }
    el.value = value;
  };
  setHidden('action', 'bulk_status');
  setHidden('bulk_status_val', val);
  ids.forEach(id => {
    const inp = document.createElement('input');
    inp.type='hidden'; inp.name='selected_ids[]'; inp.value=id; form.appendChild(inp);
  });
  form.submit();
}

// ── Toast ─────────────────────────────────────────────────
function showToast(msg, type='ok') {
  const t    = document.getElementById('toast');
  const icon = document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  const c = {ok:'#10B981',warn:'#F59E0B',err:'#EF4444'};
  const i = {ok:'fa-check-circle',warn:'fa-exclamation-triangle',err:'fa-times-circle'};
  icon.className = 'fas ' + (i[type]||'fa-info-circle');
  icon.style.color = c[type]||'#10B981';
  t.style.transform = 'translateY(0)'; t.style.opacity = '1';
  clearTimeout(t._t);
  t._t = setTimeout(() => { t.style.transform='translateY(20px)'; t.style.opacity='0'; }, 4000);
}

function esc(s) {
  return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

<?php if ($flash): ?>
showToast(<?= json_encode(strip_tags($flash['msg'])) ?>, <?= json_encode($flash['type']) ?>);
<?php endif; ?>
</script>

</body>
</html>