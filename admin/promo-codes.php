<?php
require_once 'auth_check.php';
$adminUser = checkAdminAuth();
require_once '../config/database.php';

$conn       = getDBConnection();
$activePage = 'promo-codes';

// ── Auto-create tables if missing ──────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS promo_codes (
        id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        code                VARCHAR(32)   NOT NULL,
        description         VARCHAR(255)  NULL,
        type                ENUM('percent_off','amount_off','free_credits','free_trial') NOT NULL,
        value               DECIMAL(10,2) NOT NULL DEFAULT 0,
        applies_to_plan     VARCHAR(32)   NULL DEFAULT NULL,
        applies_to_billing  ENUM('monthly','yearly','both') NOT NULL DEFAULT 'both',
        min_purchase_kobo   INT UNSIGNED  NOT NULL DEFAULT 0,
        new_users_only      TINYINT(1)    NOT NULL DEFAULT 0,
        one_per_user        TINYINT(1)    NOT NULL DEFAULT 1,
        max_uses            INT UNSIGNED  NULL DEFAULT NULL,
        uses_count          INT UNSIGNED  NOT NULL DEFAULT 0,
        valid_from          TIMESTAMP     NULL DEFAULT NULL,
        valid_until         TIMESTAMP     NULL DEFAULT NULL,
        is_active           TINYINT(1)    NOT NULL DEFAULT 1,
        created_by          INT UNSIGNED  NULL DEFAULT NULL,
        created_at          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_promo_code (code),
        KEY idx_promo_active (is_active, valid_from, valid_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$conn->query("
    CREATE TABLE IF NOT EXISTS promo_code_uses (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        promo_code_id   INT UNSIGNED NOT NULL,
        user_id         INT NOT NULL,
        payment_id      INT UNSIGNED NULL DEFAULT NULL,
        discount_kobo   INT UNSIGNED NOT NULL DEFAULT 0,
        used_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_pcu_promo (promo_code_id),
        KEY idx_pcu_user  (user_id),
        CONSTRAINT fk_pcu_promo FOREIGN KEY (promo_code_id) REFERENCES promo_codes (id) ON DELETE RESTRICT,
        CONSTRAINT fk_pcu_user  FOREIGN KEY (user_id)       REFERENCES users       (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Helpers ────────────────────────────────────────────────
$usdMinorAmount = fn(int $amount): int => $amount >= 100000 ? (int)round($amount / 1000) : $amount;
$kobo    = fn(int $k): string => '$' . number_format($usdMinorAmount($k) / 100, 0, '.', ',');
$flash   = null;

// ── POST actions ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ── Sanitise shared fields ────────────────────────────
    $sanitiseCode  = fn(string $c): string => strtoupper(preg_replace('/[^A-Z0-9_\-]/', '', strtoupper(trim($c))));
    $sanitiseFloat = fn($v): float         => max(0, (float)$v);

    if (in_array($action, ['create', 'edit'])) {
        $id             = (int)($_POST['promo_id'] ?? 0);
        $code           = $sanitiseCode($_POST['code'] ?? '');
        $description    = substr(strip_tags(trim($_POST['description'] ?? '')), 0, 255);
        $type           = in_array($_POST['type'] ?? '', ['percent_off','amount_off','free_credits','free_trial'])
                          ? $_POST['type'] : 'percent_off';
        $value          = $sanitiseFloat($_POST['value'] ?? 0);
        $appPlan        = in_array($_POST['applies_to_plan'] ?? '', ['','free','pro','elite'])
                          ? ($_POST['applies_to_plan'] ?: null) : null;
        $appBilling     = in_array($_POST['applies_to_billing'] ?? '', ['monthly','yearly','both'])
                          ? $_POST['applies_to_billing'] : 'both';
        if ($type === 'amount_off') {
            $value = (int)round($value * 100);
        }
        $minPurchaseNgn = max(0, (float)($_POST['min_purchase_ngn'] ?? 0));
        $minKobo        = (int)round($minPurchaseNgn * 100);
        $newOnly        = isset($_POST['new_users_only']) ? 1 : 0;
        $onePerUser     = isset($_POST['one_per_user'])   ? 1 : 0;
        $maxUses        = (trim($_POST['max_uses'] ?? '') === '') ? null : max(1, (int)$_POST['max_uses']);
        $validFrom      = trim($_POST['valid_from']  ?? '') ?: null;
        $validUntil     = trim($_POST['valid_until'] ?? '') ?: null;
        $isActive       = isset($_POST['is_active'])  ? 1 : 0;

        // Validate
        if (!$code) { $flash = ['type'=>'err','msg'=>'Promo code cannot be empty.']; goto done; }
        if (strlen($code) > 32) { $flash = ['type'=>'err','msg'=>'Code must be 32 characters or less.']; goto done; }
        if ($value <= 0) { $flash = ['type'=>'err','msg'=>'Value must be greater than 0.']; goto done; }
        if ($type === 'percent_off' && $value > 100) { $flash = ['type'=>'err','msg'=>'Percent off cannot exceed 100%.']; goto done; }

        if ($action === 'create') {
            // Check uniqueness
            $dup = $conn->prepare("SELECT id FROM promo_codes WHERE code=? LIMIT 1");
            $dup->bind_param("s", $code); $dup->execute();
            if ($dup->get_result()->num_rows > 0) {
                $dup->close();
                $flash = ['type'=>'err','msg'=>"Code <strong>{$code}</strong> already exists."];
                goto done;
            }
            $dup->close();

            $ins = $conn->prepare("
                INSERT INTO promo_codes
                  (code, description, type, value, applies_to_plan, applies_to_billing,
                   min_purchase_kobo, new_users_only, one_per_user, max_uses,
                   valid_from, valid_until, is_active, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $ins->bind_param("sssdssiiiiissi",
                $code, $description, $type, $value, $appPlan, $appBilling,
                $minKobo, $newOnly, $onePerUser, $maxUses,
                $validFrom, $validUntil, $isActive, $adminUser['id']
            );
            $ins->execute();
            $newId = $conn->insert_id;
            $ins->close();
            logAdminActivity($adminUser['id'], 'CREATE_PROMO', "Created promo code: $code (ID: $newId)");
            $flash = ['type'=>'ok','msg'=>"Promo code <strong>{$code}</strong> created successfully."];

        } elseif ($action === 'edit' && $id > 0) {
            // Check uniqueness for other records
            $dup = $conn->prepare("SELECT id FROM promo_codes WHERE code=? AND id!=? LIMIT 1");
            $dup->bind_param("si", $code, $id); $dup->execute();
            if ($dup->get_result()->num_rows > 0) {
                $dup->close();
                $flash = ['type'=>'err','msg'=>"Code <strong>{$code}</strong> is already used by another promo."];
                goto done;
            }
            $dup->close();

            $upd = $conn->prepare("
                UPDATE promo_codes
                SET code=?, description=?, type=?, value=?,
                    applies_to_plan=?, applies_to_billing=?,
                    min_purchase_kobo=?, new_users_only=?, one_per_user=?,
                    max_uses=?, valid_from=?, valid_until=?, is_active=?, updated_at=NOW()
                WHERE id=?
            ");
            $upd->bind_param("sssdssiiiiissi",
                $code, $description, $type, $value, $appPlan, $appBilling,
                $minKobo, $newOnly, $onePerUser, $maxUses,
                $validFrom, $validUntil, $isActive, $id
            );
            $upd->execute();
            $upd->close();
            logAdminActivity($adminUser['id'], 'EDIT_PROMO', "Edited promo code: $code (ID: $id)");
            $flash = ['type'=>'ok','msg'=>"Promo code <strong>{$code}</strong> updated."];
        }

    } elseif ($action === 'toggle') {
        $id      = (int)($_POST['promo_id'] ?? 0);
        $toggle  = ($_POST['toggle'] ?? '') === 'activate' ? 1 : 0;
        $label   = $toggle ? 'activated' : 'deactivated';
        $upd = $conn->prepare("UPDATE promo_codes SET is_active=?, updated_at=NOW() WHERE id=?");
        $upd->bind_param("ii", $toggle, $id); $upd->execute(); $upd->close();
        // Get code for log
        $codeR = $conn->query("SELECT code FROM promo_codes WHERE id=$id LIMIT 1")->fetch_assoc()['code'] ?? "ID $id";
        logAdminActivity($adminUser['id'], 'TOGGLE_PROMO', ucfirst($label)." promo code: $codeR");
        $flash = ['type'=>'ok','msg'=>"Promo code <strong>{$codeR}</strong> {$label}."];

    } elseif ($action === 'delete') {
        $id   = (int)($_POST['promo_id'] ?? 0);
        // Check if any uses exist
        $uses = (int)($conn->query("SELECT COUNT(*) as c FROM promo_code_uses WHERE promo_code_id=$id")->fetch_assoc()['c'] ?? 0);
        if ($uses > 0) {
            $flash = ['type'=>'err','msg'=>"Cannot delete — this code has been used {$uses} time(s). Deactivate it instead."];
        } else {
            $codeR = $conn->query("SELECT code FROM promo_codes WHERE id=$id LIMIT 1")->fetch_assoc()['code'] ?? "ID $id";
            $conn->prepare("DELETE FROM promo_codes WHERE id=?")->bind_param("i",$id)->execute();
            $del = $conn->prepare("DELETE FROM promo_codes WHERE id=?");
            $del->bind_param("i", $id); $del->execute(); $del->close();
            logAdminActivity($adminUser['id'], 'DELETE_PROMO', "Deleted promo code: $codeR");
            $flash = ['type'=>'ok','msg'=>"Promo code <strong>{$codeR}</strong> deleted."];
        }

    } elseif ($action === 'bulk_toggle') {
        $ids    = array_map('intval', (array)($_POST['selected_ids'] ?? []));
        $toggle = ($_POST['toggle_target'] ?? '') === '1' ? 1 : 0;
        if ($ids) {
            $ph = implode(',', $ids);
            $conn->query("UPDATE promo_codes SET is_active=$toggle, updated_at=NOW() WHERE id IN ($ph)");
            $label = $toggle ? 'activated' : 'deactivated';
            logAdminActivity($adminUser['id'], 'BULK_TOGGLE_PROMO', "Bulk $label ".count($ids)." promo codes");
            $flash = ['type'=>'ok','msg'=>count($ids)." promo code(s) {$label}."];
        }
    }

    done:
}

// ── CSV export ──────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="promo_codes_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Code','Description','Type','Value','Applies To Plan','Billing','Min Purchase ($)','New Users Only','One Per User','Max Uses','Uses Count','Valid From','Valid Until','Active','Created By','Created At']);
    $rs = $conn->query("
        SELECT p.id, p.code, p.description, p.type, p.value,
               p.applies_to_plan, p.applies_to_billing,
               p.min_purchase_kobo/100,
               p.new_users_only, p.one_per_user,
               p.max_uses, p.uses_count,
               p.valid_from, p.valid_until, p.is_active,
               a.username as created_by, p.created_at
        FROM promo_codes p
        LEFT JOIN admin_users a ON a.id = p.created_by
        ORDER BY p.created_at DESC
    ");
    while ($r = $rs->fetch_assoc()) fputcsv($out, $r);
    fclose($out);
    $conn->close(); exit();
}

// ── Filters ─────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$typeFilter   = in_array($_GET['type'] ?? '', ['percent_off','amount_off','free_credits','free_trial','']) ? ($_GET['type'] ?? '') : '';
$statusFilter = in_array($_GET['status'] ?? '', ['active','inactive','']) ? ($_GET['status'] ?? '') : '';
$sortCol      = in_array($_GET['sort'] ?? '', ['created_at','uses_count','value','valid_until']) ? $_GET['sort'] : 'created_at';
$sortDir      = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

$where = ['1=1']; $binds = []; $types = '';
if ($search) {
    $like    = "%$search%";
    $where[] = "(p.code LIKE ? OR p.description LIKE ?)";
    $binds[] = $like; $binds[] = $like; $types .= 'ss';
}
if ($typeFilter)                 { $where[] = "p.type=?";      $binds[] = $typeFilter;   $types .= 's'; }
if ($statusFilter === 'active')  { $where[] = "p.is_active=1"; }
if ($statusFilter === 'inactive'){ $where[] = "p.is_active=0"; }

$whereSQL = implode(' AND ', $where);
$sortMap  = ['created_at'=>'p.created_at','uses_count'=>'p.uses_count','value'=>'p.value','valid_until'=>'p.valid_until'];
$orderSQL = ($sortMap[$sortCol] ?? 'p.created_at') . ' ' . $sortDir;

// Count
$cStmt = $conn->prepare("SELECT COUNT(*) as c FROM promo_codes p WHERE $whereSQL");
if ($types) {
    $cStmt->bind_param($types, ...$binds);
}
$cStmt->execute();

$cResult = $cStmt->get_result();
$totalRows = (int)$cResult->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$cResult->free();
$cStmt->close();

$dStmt = $conn->prepare("
    SELECT p.*,
           a.username as created_by_name,
           (SELECT COALESCE(SUM(pcu.discount_kobo),0)
              FROM promo_code_uses pcu
             WHERE pcu.promo_code_id = p.id) as total_discount_kobo,
           (SELECT COUNT(DISTINCT pcu.user_id)
              FROM promo_code_uses pcu
             WHERE pcu.promo_code_id = p.id) as unique_users
    FROM promo_codes p
    LEFT JOIN admin_users a ON a.id = p.created_by
    WHERE $whereSQL
    ORDER BY $orderSQL
    LIMIT ? OFFSET ?
");

$allBinds = array_merge($binds, [$perPage, $offset]);
$allTypes = $types . 'ii';

$dStmt->bind_param($allTypes, ...$allBinds);
$dStmt->execute();

$dResult = $dStmt->get_result();

$promos = [];
while ($row = $dResult->fetch_assoc()) {
    $promos[] = $row;
}

$dResult->free();
$dStmt->close();

// ── Summary stats ─────────────────────────────────────────
$safe  = fn($q): int => (int)($conn->query($q)?->fetch_assoc()['c'] ?? 0);
$safeV = fn($q): int => (int)($conn->query($q)?->fetch_assoc()['v'] ?? 0);

$statTotal      = $safe("SELECT COUNT(*) as c FROM promo_codes");
$statActive     = $safe("SELECT COUNT(*) as c FROM promo_codes WHERE is_active=1");
$statExpired    = $safe("SELECT COUNT(*) as c FROM promo_codes WHERE valid_until IS NOT NULL AND valid_until < NOW()");
$statUsedUp     = $safe("SELECT COUNT(*) as c FROM promo_codes WHERE max_uses IS NOT NULL AND uses_count >= max_uses");
$statTotalUses  = $safe("SELECT COUNT(*) as c FROM promo_code_uses");
$statTotalSaved = $safeV("SELECT COALESCE(SUM(discount_kobo),0) as v FROM promo_code_uses");

$conn->close();

// ── URL helpers ───────────────────────────────────────────
function pcPageUrl(int $p, array $extra = []): string {
    $params = array_merge($_GET, $extra, ['page'=>$p]);
    unset($params['export']);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== ''));
}
function pcSortUrl(string $col): string {
    $dir = ($_GET['sort'] ?? '') === $col && ($_GET['dir'] ?? 'desc') === 'asc' ? 'desc' : 'asc';
    return pcPageUrl(1, ['sort'=>$col, 'dir'=>$dir]);
}
function pcSortIcon(string $col): string {
    if (($_GET['sort'] ?? '') !== $col) return '<i class="fas fa-sort text-gray-600 ml-1 text-xs"></i>';
    return ($_GET['dir'] ?? 'desc') === 'asc'
        ? '<i class="fas fa-sort-up text-blue-400 ml-1 text-xs"></i>'
        : '<i class="fas fa-sort-down text-blue-400 ml-1 text-xs"></i>';
}

// ── Display helpers ────────────────────────────────────────
function promoValueLabel(string $type, float $value, $conn = null): string {
    return match($type) {
        'percent_off'  => number_format($value, 0) . '% off',
        'amount_off'   => '$' . number_format($value / 100, 0, '.', ',') . ' off',
        'free_credits' => number_format($value, 0) . ' free credits',
        'free_trial'   => number_format($value, 0) . ' extra days',
        default        => (string)$value,
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>Promo Codes — CheckDomain Admin</title>
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
.modal-box{background:#1E293B;border:1px solid rgba(59,130,246,.3);border-radius:1rem;padding:1.75rem;max-width:520px;width:90%;transform:scale(.96);transition:transform .2s;max-height:92vh;overflow-y:auto}
.modal-backdrop.open .modal-box{transform:scale(1)}

/* Flash */
.flash-ok  {background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#34D399}
.flash-warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:#FCD34D}
.flash-err {background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.3); color:#FCA5A5}

/* Badges */
.badge{display:inline-flex;align-items:center;gap:4px;font-size:.7rem;font-weight:600;padding:2px 8px;border-radius:9999px;white-space:nowrap}
.b-percent_off {background:rgba(59,130,246,.15);color:#93C5FD}
.b-amount_off  {background:rgba(245,158,11,.15);color:#FCD34D}
.b-free_credits{background:rgba(245,200,66,.15);color:#FDE68A}
.b-free_trial  {background:rgba(168,85,247,.15);color:#C4B5FD}
.b-active      {background:rgba(16,185,129,.15);color:#34D399}
.b-inactive    {background:rgba(107,114,128,.2);color:#9CA3AF}
.b-expired     {background:rgba(239,68,68,.12); color:#FCA5A5}
.b-used-up     {background:rgba(239,68,68,.12); color:#FCA5A5}
.b-pro         {background:rgba(16,185,129,.15);color:#34D399}
.b-elite       {background:rgba(245,200,66,.1); color:#FCD34D}
.b-free        {background:rgba(107,114,128,.2);color:#9CA3AF}
.b-all         {background:rgba(59,130,246,.15);color:#93C5FD}

/* Code display */
.code-chip{font-family:monospace;font-weight:700;font-size:.875rem;background:rgba(51,65,85,.6);border:1px solid rgba(71,85,105,.7);border-radius:6px;padding:3px 10px;color:#E2E8F0;letter-spacing:.04em;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:6px}
.code-chip:hover{background:rgba(59,130,246,.15);border-color:rgba(59,130,246,.3);color:#93C5FD}

/* Usage bar */
.use-bar{height:4px;border-radius:2px;background:rgba(255,255,255,.07);overflow:hidden;margin-top:3px}
.use-fill{height:100%;border-radius:2px;transition:width .4s}

/* Toggle switch */
.toggle-wrap{position:relative;width:40px;height:22px;flex-shrink:0}
.toggle-wrap input{opacity:0;width:0;height:0}
.toggle-track{position:absolute;inset:0;background:#334155;border-radius:22px;cursor:pointer;transition:background .2s;border:1px solid rgba(71,85,105,.8)}
.toggle-wrap input:checked + .toggle-track{background:#10B981;border-color:#10B981}
.toggle-track::before{content:'';position:absolute;width:16px;height:16px;border-radius:50%;background:#fff;top:2px;left:2px;transition:transform .2s}
.toggle-wrap input:checked + .toggle-track::before{transform:translateX(18px)}

/* Inputs */
.inp{background:rgba(51,65,85,.8);border:1px solid rgba(71,85,105,1);border-radius:.5rem;padding:.5rem .75rem;color:#fff;width:100%;font-size:.875rem;transition:border-color .2s;outline:none}
.inp:focus{border-color:#3B82F6}
.inp::placeholder{color:#64748B}
.inp-sm{padding:.4rem .7rem!important;font-size:.8rem!important}
.form-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#64748B;margin-bottom:.25rem;display:block}
.form-hint{font-size:.7rem;color:#475569;margin-top:.25rem}

.btn-primary  {background:#2563EB;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-primary:hover{background:#1D4ED8}
.btn-secondary{background:#334155;color:#CBD5E1;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-secondary:hover{background:#475569}
.btn-danger   {background:#DC2626;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-danger:hover{background:#B91C1C}
.btn-amber    {background:#D97706;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-amber:hover{background:#B45309}
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
      <h1 class="text-2xl md:text-3xl font-bold">Promo Codes</h1>
      <p class="text-gray-400 text-sm mt-1">
        <?= number_format($totalRows) ?> code<?= $totalRows!==1?'s':'' ?>
        <?php if ($search||$typeFilter||$statusFilter): ?><span class="text-blue-400">(filtered)</span><?php endif; ?>
      </p>
    </div>
    <div class="flex flex-wrap gap-3">
      <a href="?export=1" class="btn-secondary flex items-center gap-2 text-sm">
        <i class="fas fa-download text-xs"></i> Export CSV
      </a>
      <button onclick="openCreateModal()" class="btn-primary flex items-center gap-2 text-sm">
        <i class="fas fa-plus text-xs"></i> New promo code
      </button>
    </div>
  </div>

  <!-- Stats -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-8">
    <?php
    $cards = [
      ['lbl'=>'Total codes',    'val'=>number_format($statTotal),         'icon'=>'fa-tags',         'c'=>'blue'],
      ['lbl'=>'Active',         'val'=>number_format($statActive),        'icon'=>'fa-check-circle', 'c'=>'green'],
      ['lbl'=>'Expired',        'val'=>number_format($statExpired),       'icon'=>'fa-clock',        'c'=>'amber'],
      ['lbl'=>'Used up',        'val'=>number_format($statUsedUp),        'icon'=>'fa-ban',          'c'=>'red'],
      ['lbl'=>'Total uses',     'val'=>number_format($statTotalUses),     'icon'=>'fa-ticket-alt',   'c'=>'purple'],
      ['lbl'=>'Total saved',    'val'=>$kobo($statTotalSaved),            'icon'=>'fa-piggy-bank',   'c'=>'green',  'raw'=>true],
    ];
    $cmap=['blue'=>['bg'=>'bg-blue-500/20','t'=>'text-blue-400'],'green'=>['bg'=>'bg-green-500/20','t'=>'text-green-400'],'amber'=>['bg'=>'bg-amber-500/20','t'=>'text-amber-400'],'red'=>['bg'=>'bg-red-500/20','t'=>'text-red-400'],'purple'=>['bg'=>'bg-purple-500/20','t'=>'text-purple-400']];
    foreach ($cards as $c):
      $cl = $cmap[$c['c']] ?? $cmap['blue'];
    ?>
    <div class="stat-card rounded-xl p-4">
      <div class="flex justify-between items-start">
        <div>
          <p class="text-gray-400 text-xs"><?= $c['lbl'] ?></p>
          <p class="text-xl font-bold mt-1 <?= $cl['t'] ?>"><?= $c['val'] ?></p>
        </div>
        <div class="w-9 h-9 <?= $cl['bg'] ?> rounded-full flex items-center justify-center flex-shrink-0">
          <i class="fas <?= $c['icon'] ?> <?= $cl['t'] ?> text-sm"></i>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Filters -->
  <div class="bg-slate-800/50 rounded-xl p-4 mb-5">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
      <div class="flex-1 min-w-44">
        <label class="form-label">Search</label>
        <div class="relative">
          <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
          <input class="inp pl-8" type="text" name="search"
                 value="<?= htmlspecialchars($search) ?>"
                 placeholder="Code or description…" autocomplete="off">
        </div>
      </div>
      <div class="w-36">
        <label class="form-label">Type</label>
        <select class="inp" name="type">
          <option value="">All types</option>
          <?php foreach (['percent_off'=>'% Off','amount_off'=>'Amount Off','free_credits'=>'Free Credits','free_trial'=>'Free Trial'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= $typeFilter===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="w-32">
        <label class="form-label">Status</label>
        <select class="inp" name="status">
          <option value="">All</option>
          <option value="active"   <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
          <option value="inactive" <?= $statusFilter==='inactive'?'selected':'' ?>>Inactive</option>
        </select>
      </div>
      <div class="w-36">
        <label class="form-label">Sort</label>
        <select class="inp" name="sort" onchange="this.form.submit()">
          <option value="created_at"  <?= $sortCol==='created_at'?'selected':'' ?>>Created</option>
          <option value="uses_count"  <?= $sortCol==='uses_count'?'selected':'' ?>>Uses</option>
          <option value="value"       <?= $sortCol==='value'?'selected':'' ?>>Value</option>
          <option value="valid_until" <?= $sortCol==='valid_until'?'selected':'' ?>>Expiry</option>
        </select>
      </div>
      <input type="hidden" name="dir" value="<?= $sortDir==='ASC'?'asc':'desc' ?>">
      <button type="submit" class="btn-primary btn-sm flex items-center gap-2">
        <i class="fas fa-filter text-xs"></i> Filter
      </button>
      <?php if ($search||$typeFilter||$statusFilter): ?>
      <a href="promo-codes.php" class="btn-secondary btn-sm flex items-center gap-2">
        <i class="fas fa-times text-xs"></i> Clear
      </a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Bulk action bar -->
  <div id="bulkBar" class="hidden bg-blue-900/30 border border-blue-500/30 rounded-xl px-4 py-3 mb-4 flex items-center gap-3 text-sm flex-wrap">
    <span id="bulkCount" class="font-mono text-blue-300">0 selected</span>
    <span class="text-gray-600">·</span>
    <button onclick="bulkToggle(1)" class="btn-secondary btn-sm flex items-center gap-1.5">
      <i class="fas fa-check text-green-400 text-xs"></i> Activate
    </button>
    <button onclick="bulkToggle(0)" class="btn-secondary btn-sm flex items-center gap-1.5">
      <i class="fas fa-ban text-red-400 text-xs"></i> Deactivate
    </button>
    <button onclick="clearSelection()" class="text-gray-500 hover:text-gray-300 text-xs ml-auto">
      Deselect all
    </button>
  </div>

  <!-- Table -->
  <div class="bg-slate-800/50 rounded-xl overflow-hidden">
    <?php if (!empty($promos)): ?>
    <form method="POST" id="bulkForm">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-slate-700/50 border-b border-gray-700 text-xs uppercase tracking-wide text-gray-400">
          <tr>
            <th class="p-4 w-10">
              <input type="checkbox" id="selectAll" class="chk">
            </th>
            <th class="p-4 text-left">Code</th>
            <th class="p-4 text-left hide-mobile">Type / Value</th>
            <th class="p-4 text-left hide-mobile">Restrictions</th>
            <th class="p-4 text-left">
              <a href="<?= pcSortUrl('uses_count') ?>" class="hover:text-white flex items-center">
                Uses <?= pcSortIcon('uses_count') ?>
              </a>
            </th>
            <th class="p-4 text-left hide-sm">
              <a href="<?= pcSortUrl('valid_until') ?>" class="hover:text-white flex items-center">
                Validity <?= pcSortIcon('valid_until') ?>
              </a>
            </th>
            <th class="p-4 text-center hide-mobile">Active</th>
            <th class="p-4 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-700/50">
          <?php foreach ($promos as $promo):
            $now        = time();
            $isExpired  = $promo['valid_until'] && strtotime($promo['valid_until']) < $now;
            $isUsedUp   = $promo['max_uses'] !== null && $promo['uses_count'] >= $promo['max_uses'];
            $isLive     = $promo['is_active'] && !$isExpired && !$isUsedUp;
            $usePct     = $promo['max_uses'] > 0 ? min(100, round($promo['uses_count'] / $promo['max_uses'] * 100)) : 0;
            $barColor   = $usePct >= 90 ? '#EF4444' : ($usePct >= 70 ? '#F59E0B' : '#10B981');

            $statusKey = !$promo['is_active'] ? 'inactive' : ($isExpired ? 'expired' : ($isUsedUp ? 'used-up' : 'active'));
          ?>
          <tr class="tbl-row">

            <!-- Checkbox -->
            <td class="p-4">
              <input type="checkbox" name="selected_ids[]" value="<?= (int)$promo['id'] ?>"
                     class="chk row-chk" onchange="onCheckChange()">
            </td>

            <!-- Code + description -->
            <td class="p-4">
              <button class="code-chip mb-1" onclick="copyCode('<?= htmlspecialchars($promo['code'], ENT_QUOTES) ?>')">
                <i class="fas fa-copy text-xs opacity-50"></i>
                <?= htmlspecialchars($promo['code']) ?>
              </button>
              <?php if ($promo['description']): ?>
              <div class="text-gray-400 text-xs max-w-40 truncate" title="<?= htmlspecialchars($promo['description']) ?>">
                <?= htmlspecialchars($promo['description']) ?>
              </div>
              <?php endif; ?>
              <div class="mt-1">
                <span class="badge b-<?= $statusKey ?>">
                  <?= ucwords(str_replace('-',' ',$statusKey)) ?>
                </span>
              </div>
            </td>

            <!-- Type + value -->
            <td class="p-4 hide-mobile">
              <span class="badge b-<?= $promo['type'] ?>">
                <?= match($promo['type']) {
                  'percent_off'  => '% Off',
                  'amount_off'   => '$ Off',
                  'free_credits' => 'Credits',
                  'free_trial'   => 'Trial',
                  default        => $promo['type'],
                } ?>
              </span>
              <div class="text-white font-mono font-bold text-sm mt-1">
                <?= promoValueLabel($promo['type'], (float)$promo['value']) ?>
              </div>
              <?php if ($promo['applies_to_billing'] !== 'both'): ?>
              <div class="text-gray-500 text-xs"><?= ucfirst($promo['applies_to_billing']) ?> only</div>
              <?php endif; ?>
            </td>

            <!-- Restrictions -->
            <td class="p-4 hide-mobile">
              <div class="flex flex-col gap-1">
                <?php if ($promo['applies_to_plan']): ?>
                <span class="badge b-<?= $promo['applies_to_plan'] ?>">
                  <?= ucfirst($promo['applies_to_plan']) ?> plan only
                </span>
                <?php else: ?>
                <span class="badge b-all">All plans</span>
                <?php endif; ?>
                <?php if ($promo['new_users_only']): ?>
                <span class="text-xs text-amber-400"><i class="fas fa-star text-xs mr-0.5"></i> New users only</span>
                <?php endif; ?>
                <?php if ($promo['one_per_user']): ?>
                <span class="text-xs text-gray-400"><i class="fas fa-user text-xs mr-0.5"></i> One per user</span>
                <?php endif; ?>
                <?php if ($promo['min_purchase_kobo'] > 0): ?>
                <span class="text-xs text-gray-500">Min. <?= $kobo((int)$promo['min_purchase_kobo']) ?></span>
                <?php endif; ?>
              </div>
            </td>

            <!-- Uses -->
            <td class="p-4">
              <div class="flex items-center gap-1.5">
                <span class="font-mono text-sm <?= $isUsedUp ? 'text-red-400' : 'text-white' ?>">
                  <?= number_format($promo['uses_count']) ?>
                </span>
                <?php if ($promo['max_uses'] !== null): ?>
                <span class="text-gray-500 text-xs">/ <?= number_format($promo['max_uses']) ?></span>
                <?php else: ?>
                <span class="text-gray-600 text-xs">/ ∞</span>
                <?php endif; ?>
              </div>
              <?php if ($promo['max_uses'] !== null): ?>
              <div class="use-bar mt-1 w-16">
                <div class="use-fill" style="width:<?= $usePct ?>%;background:<?= $barColor ?>"></div>
              </div>
              <?php endif; ?>
              <?php if ($promo['total_discount_kobo'] > 0): ?>
              <div class="text-gray-600 text-xs mt-0.5"><?= $kobo((int)$promo['total_discount_kobo']) ?> saved</div>
              <?php endif; ?>
              <?php if ($promo['unique_users'] > 0): ?>
              <div class="text-gray-600 text-xs"><?= $promo['unique_users'] ?> user<?= $promo['unique_users']!==1?'s':'' ?></div>
              <?php endif; ?>
            </td>

            <!-- Validity -->
            <td class="p-4 hide-sm">
              <?php if ($promo['valid_from'] || $promo['valid_until']): ?>
              <div class="text-xs">
                <?php if ($promo['valid_from']): ?>
                <div class="text-gray-400">From: <?= date('M j, Y', strtotime($promo['valid_from'])) ?></div>
                <?php endif; ?>
                <?php if ($promo['valid_until']): ?>
                <div class="<?= $isExpired ? 'text-red-400' : 'text-gray-300' ?>">
                  Until: <?= date('M j, Y', strtotime($promo['valid_until'])) ?>
                  <?php if (!$isExpired): ?>
                  <span class="text-gray-500">(<?= max(0,(int)ceil((strtotime($promo['valid_until'])-time())/86400)) ?>d)</span>
                  <?php endif; ?>
                </div>
                <?php endif; ?>
              </div>
              <?php else: ?>
              <span class="text-gray-600 text-xs">No expiry</span>
              <?php endif; ?>
              <?php if ($promo['created_by_name']): ?>
              <div class="text-gray-600 text-xs mt-1">by <?= htmlspecialchars($promo['created_by_name']) ?></div>
              <?php endif; ?>
            </td>

            <!-- Active toggle -->
            <td class="p-4 hide-mobile text-center">
              <form method="POST" class="inline">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="promo_id" value="<?= (int)$promo['id'] ?>">
                <input type="hidden" name="toggle" value="<?= $promo['is_active'] ? 'deactivate' : 'activate' ?>">
                <label class="toggle-wrap cursor-pointer" title="<?= $promo['is_active'] ? 'Click to deactivate' : 'Click to activate' ?>">
                  <input type="checkbox" <?= $promo['is_active'] ? 'checked' : '' ?> onchange="this.form.submit()">
                  <span class="toggle-track"></span>
                </label>
              </form>
            </td>

            <!-- Actions -->
            <td class="p-4 text-right">
              <div class="flex items-center justify-end gap-1.5">
                <!-- View uses -->
                <button type="button" onclick="openUsesModal(<?= (int)$promo['id'] ?>, '<?= htmlspecialchars($promo['code'],ENT_QUOTES) ?>')"
                        class="w-8 h-8 bg-purple-500/20 hover:bg-purple-500/30 rounded-lg flex items-center justify-center text-purple-400 transition"
                        title="View usage">
                  <i class="fas fa-list-ul text-xs"></i>
                </button>
                <!-- Edit -->
                <button type="button" onclick="openEditModal(<?= htmlspecialchars(json_encode($promo),ENT_QUOTES) ?>)"
                        class="w-8 h-8 bg-blue-500/20 hover:bg-blue-500/30 rounded-lg flex items-center justify-center text-blue-400 transition"
                        title="Edit">
                  <i class="fas fa-edit text-xs"></i>
                </button>
                <!-- Delete -->
                <button type="button" onclick="openDeleteModal(<?= (int)$promo['id'] ?>, '<?= htmlspecialchars($promo['code'],ENT_QUOTES) ?>',  <?= (int)$promo['uses_count'] ?>)"
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
        <?php if ($page > 1): ?>
        <a href="<?= pcPageUrl($page-1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php
        $s = max(1,$page-2); $e = min($totalPages,$page+2);
        if ($s>1) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>';
        for ($i=$s;$i<=$e;$i++):
        ?>
        <a href="<?= pcPageUrl($i) ?>" class="px-3 py-1.5 rounded text-xs transition <?= $i===$page?'bg-blue-600':'bg-slate-700 hover:bg-slate-600' ?>"><?= $i ?></a>
        <?php endfor;
        if ($e<$totalPages) echo '<span class="px-2 py-1.5 text-gray-600 text-xs">…</span>'; ?>
        <?php if ($page < $totalPages): ?>
        <a href="<?= pcPageUrl($page+1) ?>" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 transition text-xs"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    </form><!-- /bulkForm -->

    <?php else: ?>
    <div class="text-center py-16">
      <i class="fas fa-tags text-5xl text-gray-700 mb-4 block"></i>
      <p class="text-gray-400 mb-1">No promo codes found</p>
      <?php if ($search||$typeFilter||$statusFilter): ?>
      <a href="promo-codes.php" class="text-blue-400 hover:text-blue-300 text-sm">Clear filters</a>
      <?php else: ?>
      <p class="text-gray-600 text-sm mt-1">Create your first promo code to offer discounts.</p>
      <button onclick="openCreateModal()" class="btn-primary mt-4 btn-sm flex items-center gap-2 mx-auto">
        <i class="fas fa-plus text-xs"></i> New promo code
      </button>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

</div>
</div>

<!-- ════════════════════════════════════
     MODALS
════════════════════════════════════ -->

<!-- Create / Edit modal (shared) -->
<div class="modal-backdrop" id="codeModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold" id="codeModalTitle">New promo code</h2>
      <button onclick="closeModal('codeModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" id="codeForm" class="flex flex-col gap-4">
      <input type="hidden" name="action" id="codeAction" value="create">
      <input type="hidden" name="promo_id" id="codePromoId" value="0">

      <!-- Code + Type -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Code <span class="text-red-400">*</span></label>
          <div class="relative">
            <input class="inp uppercase" type="text" name="code" id="f-code"
                   placeholder="LAUNCH50" maxlength="32" required
                   oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9_\-]/g,'')">
            <button type="button" onclick="generateCode()"
                    class="absolute right-2 top-1/2 -translate-y-1/2 text-blue-400 hover:text-blue-300 text-xs transition"
                    title="Generate random code">
              <i class="fas fa-dice"></i>
            </button>
          </div>
          <p class="form-hint">Letters, numbers, _ and - only.</p>
        </div>
        <div>
          <label class="form-label">Type <span class="text-red-400">*</span></label>
          <select class="inp" name="type" id="f-type" onchange="updateValueLabel()">
            <option value="percent_off">% Off subscription</option>
            <option value="amount_off">$ Amount off</option>
            <option value="free_credits">Free credits</option>
            <option value="free_trial">Free trial days</option>
          </select>
        </div>
      </div>

      <!-- Value + Plan -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label" id="valueLabel">Discount % <span class="text-red-400">*</span></label>
          <input class="inp" type="number" name="value" id="f-value"
                 min="0.01" step="0.01" placeholder="e.g. 20" required>
          <p class="form-hint" id="valueHint">0–100 for percentage discounts.</p>
        </div>
        <div>
          <label class="form-label">Applies to plan</label>
          <select class="inp" name="applies_to_plan" id="f-plan">
            <option value="">All plans</option>
            <option value="pro">Pro only</option>
            <option value="elite">Elite only</option>
          </select>
        </div>
      </div>

      <!-- Billing + Min purchase -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Billing cycle</label>
          <select class="inp" name="applies_to_billing" id="f-billing">
            <option value="both">Both</option>
            <option value="monthly">Monthly only</option>
            <option value="yearly">Yearly only</option>
          </select>
        </div>
        <div>
          <label class="form-label">Min purchase ($)</label>
          <input class="inp" type="number" name="min_purchase_ngn" id="f-min"
                 min="0" step="0.01" value="0" placeholder="0 = no minimum">
          <p class="form-hint">Enter in dollars, stored as cents.</p>
        </div>
      </div>

      <!-- Max uses + Description -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Max uses</label>
          <input class="inp" type="number" name="max_uses" id="f-maxuses"
                 min="1" placeholder="Leave empty for unlimited">
          <p class="form-hint">Blank = unlimited uses.</p>
        </div>
        <div>
          <label class="form-label">Description</label>
          <input class="inp" type="text" name="description" id="f-description"
                 placeholder="Internal note or promo name" maxlength="255">
        </div>
      </div>

      <!-- Dates -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Valid from</label>
          <input class="inp" type="datetime-local" name="valid_from" id="f-from">
          <p class="form-hint">Leave blank to start immediately.</p>
        </div>
        <div>
          <label class="form-label">Valid until</label>
          <input class="inp" type="datetime-local" name="valid_until" id="f-until">
          <p class="form-hint">Leave blank for no expiry.</p>
        </div>
      </div>

      <!-- Checkboxes -->
      <div class="grid grid-cols-3 gap-3">
        <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-300 select-none">
          <input type="checkbox" name="new_users_only" id="f-newonly" class="chk">
          New users only
        </label>
        <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-300 select-none">
          <input type="checkbox" name="one_per_user" id="f-oneperuser" class="chk" checked>
          One per user
        </label>
        <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-300 select-none">
          <input type="checkbox" name="is_active" id="f-active" class="chk" checked>
          Active
        </label>
      </div>

      <div class="flex gap-3 justify-end pt-3 border-t border-gray-700">
        <button type="button" onclick="closeModal('codeModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-primary flex items-center gap-2" id="codeSubmitBtn">
          <i class="fas fa-plus text-xs"></i> Create code
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Usage modal -->
<div class="modal-backdrop" id="usesModal">
  <div class="modal-box" style="max-width:560px;">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold">
        Usage — <span id="usesCodeName" class="font-mono text-blue-300"></span>
      </h2>
      <button onclick="closeModal('usesModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <div id="usesContent">
      <div class="text-center py-10 text-gray-500">
        <i class="fas fa-spinner fa-spin text-2xl mb-3 block"></i>
        Loading usage…
      </div>
    </div>
    <div class="border-t border-gray-700 pt-4 mt-4 flex justify-end">
      <button onclick="closeModal('usesModal')" class="btn-secondary btn-sm">Close</button>
    </div>
  </div>
</div>

<!-- Delete modal -->
<div class="modal-backdrop" id="deleteModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold text-red-400"><i class="fas fa-trash mr-2"></i>Delete promo code</h2>
      <button onclick="closeModal('deleteModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <div id="deleteBody" class="text-gray-300 text-sm mb-5"></div>
    <form method="POST" class="flex gap-3 justify-end">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="promo_id" id="del-id">
      <button type="button" onclick="closeModal('deleteModal')" class="btn-secondary">Cancel</button>
      <button type="submit" id="del-btn" class="btn-danger flex items-center gap-2">
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
const APP_BASE = <?= json_encode(rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'')),'/').'/') ?>;

// ── Modal helpers ─────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-backdrop').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// ── Create modal ──────────────────────────────────────────
function openCreateModal() {
  document.getElementById('codeModalTitle').textContent = 'New promo code';
  document.getElementById('codeAction').value           = 'create';
  document.getElementById('codePromoId').value          = '0';
  document.getElementById('codeSubmitBtn').innerHTML    = '<i class="fas fa-plus text-xs"></i> Create code';
  document.getElementById('codeForm').reset();
  document.getElementById('f-oneperuser').checked = true;
  document.getElementById('f-active').checked     = true;
  updateValueLabel();
  openModal('codeModal');
}

// ── Edit modal ────────────────────────────────────────────
function openEditModal(p) {
  document.getElementById('codeModalTitle').textContent = 'Edit — ' + esc(p.code);
  document.getElementById('codeAction').value           = 'edit';
  document.getElementById('codePromoId').value          = p.id;
  document.getElementById('codeSubmitBtn').innerHTML    = '<i class="fas fa-save text-xs"></i> Save changes';

  const fmtDt = d => d ? d.replace(' ','T').substring(0,16) : '';

  document.getElementById('f-code').value        = p.code;
  document.getElementById('f-type').value        = p.type;
  document.getElementById('f-value').value       = p.type === 'amount_off' ? (Number(p.value || 0) / 100) : p.value;
  document.getElementById('f-plan').value        = p.applies_to_plan || '';
  document.getElementById('f-billing').value     = p.applies_to_billing;
  document.getElementById('f-min').value         = Number(p.min_purchase_kobo || 0) / 100;
  document.getElementById('f-maxuses').value     = p.max_uses || '';
  document.getElementById('f-description').value = p.description || '';
  document.getElementById('f-from').value        = fmtDt(p.valid_from);
  document.getElementById('f-until').value       = fmtDt(p.valid_until);
  document.getElementById('f-newonly').checked   = !!+p.new_users_only;
  document.getElementById('f-oneperuser').checked= !!+p.one_per_user;
  document.getElementById('f-active').checked    = !!+p.is_active;

  updateValueLabel();
  openModal('codeModal');
}

// ── Dynamic value label ───────────────────────────────────
function updateValueLabel() {
  const type  = document.getElementById('f-type').value;
  const label = document.getElementById('valueLabel');
  const hint  = document.getElementById('valueHint');
  const input = document.getElementById('f-value');
  const map = {
    percent_off:   { lbl:'Discount %',         hint:'0–100. e.g. 20 = 20% off.',          step:'1',  max:'100' },
    amount_off:    { lbl:'Amount off ($)',      hint:'In dollars. e.g. 9 = $9 off.', step:'0.01',max:''    },
    free_credits:  { lbl:'Credits to grant',   hint:'Number of credits added to balance.',  step:'1',  max:''    },
    free_trial:    { lbl:'Extra trial days',    hint:'Days added to free trial period.',     step:'1',  max:''    },
  };
  const m = map[type] || map.percent_off;
  label.innerHTML  = m.lbl + ' <span class="text-red-400">*</span>';
  hint.textContent = m.hint;
  input.step       = m.step;
  if (m.max) input.max = m.max; else input.removeAttribute('max');
}

// ── Generate random code ──────────────────────────────────
function generateCode() {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  let code = '';
  for (let i=0; i<8; i++) code += chars.charAt(Math.floor(Math.random()*chars.length));
  document.getElementById('f-code').value = code;
}

// ── Usage modal (AJAX) ────────────────────────────────────
async function openUsesModal(promoId, code) {
  document.getElementById('usesCodeName').textContent = code;
  document.getElementById('usesContent').innerHTML = `
    <div class="text-center py-10 text-gray-500">
      <i class="fas fa-spinner fa-spin text-2xl mb-3 block"></i>Loading usage…
    </div>`;
  openModal('usesModal');

  try {
    const res  = await fetch(`promo-codes.php?ajax=uses&id=${promoId}`);
    const html = await res.text();
    document.getElementById('usesContent').innerHTML = html;
  } catch {
    document.getElementById('usesContent').innerHTML = '<p class="text-center text-red-400 py-6">Failed to load usage data.</p>';
  }
}

// ── Delete modal ──────────────────────────────────────────
function openDeleteModal(id, code, uses) {
  document.getElementById('del-id').value = id;
  const body = document.getElementById('deleteBody');
  const btn  = document.getElementById('del-btn');
  if (uses > 0) {
    body.innerHTML = `<div class="bg-red-500/10 border border-red-500/30 rounded-lg p-3 text-red-300 text-sm">
      <i class="fas fa-exclamation-triangle mr-2"></i>
      <strong>${esc(code)}</strong> has been used <strong>${uses}</strong> time(s) and cannot be deleted.
      Deactivate it using the toggle instead.</div>`;
    btn.disabled = true; btn.className = 'btn-secondary opacity-40 cursor-not-allowed';
  } else {
    body.innerHTML = `Delete promo code <strong class="font-mono text-white">${esc(code)}</strong>? This cannot be undone.`;
    btn.disabled = false; btn.className = 'btn-danger flex items-center gap-2';
  }
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
  if (sa) sa.checked = checked.length === document.querySelectorAll('.row-chk').length;
}

document.getElementById('selectAll')?.addEventListener('change', e => {
  document.querySelectorAll('.row-chk').forEach(c => c.checked = e.target.checked);
  onCheckChange();
});

function clearSelection() {
  document.querySelectorAll('.row-chk,.chk#selectAll').forEach(c => c.checked = false);
  onCheckChange();
}

function bulkToggle(val) {
  const ids = [...document.querySelectorAll('.row-chk:checked')].map(c => c.value);
  if (!ids.length) return;
  const label = val ? 'activate' : 'deactivate';
  if (!confirm(`${label.charAt(0).toUpperCase()+label.slice(1)} ${ids.length} code(s)?`)) return;

  const form = document.getElementById('bulkForm');
  let ai = form.querySelector('input[name="action"]');
  if (!ai) { ai = document.createElement('input'); ai.type='hidden'; ai.name='action'; form.appendChild(ai); }
  ai.value = 'bulk_toggle';
  let ti = form.querySelector('input[name="toggle_target"]');
  if (!ti) { ti = document.createElement('input'); ti.type='hidden'; ti.name='toggle_target'; form.appendChild(ti); }
  ti.value = val;
  ids.forEach(id => {
    const inp = document.createElement('input');
    inp.type='hidden'; inp.name='selected_ids[]'; inp.value=id;
    form.appendChild(inp);
  });
  form.submit();
}

// ── Copy code to clipboard ────────────────────────────────
function copyCode(code) {
  navigator.clipboard.writeText(code)
    .then(() => showToast('Copied: ' + code))
    .catch(() => showToast('Could not copy', 'err'));
}

// ── Toast ─────────────────────────────────────────────────
function showToast(msg, type='ok') {
  const t    = document.getElementById('toast');
  const icon = document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  const c = {ok:'#10B981', warn:'#F59E0B', err:'#EF4444'};
  const i = {ok:'fa-check-circle', warn:'fa-exclamation-triangle', err:'fa-times-circle'};
  icon.className = 'fas ' + (i[type]||'fa-info-circle');
  icon.style.color = c[type]||'#10B981';
  t.style.transform='translateY(0)'; t.style.opacity='1';
  clearTimeout(t._t);
  t._t = setTimeout(()=>{t.style.transform='translateY(20px)';t.style.opacity='0';}, 4000);
}

function esc(s) {
  return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

<?php if ($flash): ?>
showToast(<?= json_encode(strip_tags($flash['msg'])) ?>, <?= json_encode($flash['type']) ?>);
<?php endif; ?>
</script>

<?php
// ── AJAX handler — usage detail ─────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'uses') {
    $pid   = (int)($_GET['id'] ?? 0);
    $conn2 = getDBConnection();
    $stmt  = $conn2->prepare("
        SELECT pcu.id, pcu.used_at, pcu.discount_kobo,
               u.email, u.full_name,
               p.paystack_reference
        FROM promo_code_uses pcu
        JOIN users u    ON u.id = pcu.user_id
        LEFT JOIN payments p ON p.id = pcu.payment_id
        WHERE pcu.promo_code_id = ?
        ORDER BY pcu.used_at DESC
        LIMIT 50
    ");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $uses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn2->close();

    if (empty($uses)) {
        echo '<div class="text-center py-8 text-gray-500"><i class="fas fa-inbox text-2xl mb-3 block opacity-30"></i>No uses recorded yet.</div>';
    } else {
        echo '<div class="text-xs text-gray-500 mb-3">Last ' . count($uses) . ' redemption' . (count($uses)!==1?'s':'') . '</div>';
        echo '<div class="overflow-x-auto"><table class="w-full text-xs"><thead class="text-gray-500 uppercase border-b border-gray-700"><tr><th class="pb-2 text-left">User</th><th class="pb-2 text-left">Payment ref</th><th class="pb-2 text-right">Discount</th><th class="pb-2 text-right">Date</th></tr></thead><tbody class="divide-y divide-gray-700/50">';
        foreach ($uses as $u) {
            $discount = '$' . number_format($u['discount_kobo']/100, 0, '.', ',');
            $ref      = $u['paystack_reference'] ? htmlspecialchars(substr($u['paystack_reference'],0,20)).'…' : '—';
            $name     = htmlspecialchars($u['full_name'] ?: $u['email']);
            $date     = date('M j, Y H:i', strtotime($u['used_at']));
            echo "<tr class='hover:bg-slate-700/30'><td class='py-2 pr-3'><div class='text-white font-medium truncate max-w-32'>$name</div><div class='text-gray-500 truncate max-w-32'>".htmlspecialchars($u['email'])."</div></td><td class='py-2 font-mono text-blue-300'>$ref</td><td class='py-2 text-right text-green-400 font-mono'>$discount</td><td class='py-2 text-right text-gray-400'>$date</td></tr>";
        }
        echo '</tbody></table></div>';
    }
    exit();
}
?>
</body>
</html>
