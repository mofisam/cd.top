<?php
require_once 'auth_check.php';
$adminUser = checkAdminAuth();
require_once '../config/database.php';

$conn = getDBConnection();
$activePage = 'credit-packages';
$flash = null;

$conn->query("
    CREATE TABLE IF NOT EXISTS credit_packages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(64) NOT NULL,
        credits INT UNSIGNED NOT NULL DEFAULT 0,
        price_kobo INT UNSIGNED NOT NULL DEFAULT 0,
        bonus_credits INT UNSIGNED NOT NULL DEFAULT 0,
        is_popular TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$existingColumns = [];
$cols = $conn->query("SHOW COLUMNS FROM credit_packages");
while ($col = $cols->fetch_assoc()) {
    $existingColumns[$col['Field']] = true;
}
if (empty($existingColumns['updated_at'])) {
    $conn->query("ALTER TABLE credit_packages ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
}
if (empty($existingColumns['currency'])) {
    $conn->query("ALTER TABLE credit_packages ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'USD' AFTER price_kobo");
}

if ((int)($conn->query("SELECT COUNT(*) as c FROM credit_packages")?->fetch_assoc()['c'] ?? 0) === 0) {
    $conn->query("
        INSERT INTO credit_packages (name, credits, price_kobo, bonus_credits, is_popular, is_active, sort_order)
        VALUES
          ('Starter',  25,  250,  0, 0, 1, 1),
          ('Standard', 60,  500,  5, 1, 1, 2),
          ('Power',   150, 1000, 20, 0, 1, 3),
          ('Bulk',    400, 2500, 75, 0, 1, 4)
    ");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['create', 'save'], true)) {
        $id = (int)($_POST['id'] ?? 0);
        $name = substr(strip_tags(trim($_POST['name'] ?? '')), 0, 64);
        $credits = max(0, (int)($_POST['credits'] ?? 0));
        $bonus = max(0, (int)($_POST['bonus_credits'] ?? 0));
        $priceCents = max(0, (int)round((float)($_POST['price_usd'] ?? 0) * 100));
        $sort = max(0, (int)($_POST['sort_order'] ?? 0));
        $popular = isset($_POST['is_popular']) ? 1 : 0;
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $credits <= 0) {
            $flash = ['type' => 'err', 'msg' => 'Package name and credits are required.'];
        } elseif ($action === 'save' && $id > 0) {
            if ($popular) {
                $conn->query("UPDATE credit_packages SET is_popular=0 WHERE id <> {$id}");
            }
            $stmt = $conn->prepare("
                UPDATE credit_packages
                SET name=?, credits=?, price_kobo=?, bonus_credits=?, is_popular=?, is_active=?, sort_order=?, updated_at=NOW()
                WHERE id=?
            ");
            $stmt->bind_param("siiiiiii", $name, $credits, $priceCents, $bonus, $popular, $active, $sort, $id);
            $stmt->execute();
            $stmt->close();
            logAdminActivity($adminUser['id'], 'UPDATE_CREDIT_PACKAGE', "Updated credit package: {$name}");
            $flash = ['type' => 'ok', 'msg' => 'Credit package updated.'];
        } else {
            if ($popular) {
                $conn->query("UPDATE credit_packages SET is_popular=0");
            }
            $stmt = $conn->prepare("
                INSERT INTO credit_packages (name, credits, price_kobo, bonus_credits, is_popular, is_active, sort_order)
                VALUES (?,?,?,?,?,?,?)
            ");
            $stmt->bind_param("siiiiii", $name, $credits, $priceCents, $bonus, $popular, $active, $sort);
            $stmt->execute();
            $stmt->close();
            logAdminActivity($adminUser['id'], 'CREATE_CREDIT_PACKAGE', "Created credit package: {$name}");
            $flash = ['type' => 'ok', 'msg' => 'Credit package created.'];
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM credit_packages WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            logAdminActivity($adminUser['id'], 'DELETE_CREDIT_PACKAGE', "Deleted credit package #{$id}");
            $flash = ['type' => 'ok', 'msg' => 'Credit package deleted.'];
        }
    }
}

$packages = [];
$result = $conn->query("SELECT * FROM credit_packages ORDER BY sort_order ASC, id ASC");
while ($row = $result->fetch_assoc()) {
    $row['price_kobo'] = usdMinorAmount((int)$row['price_kobo']);
    $packages[] = $row;
}
$conn->close();

$money = fn(int $cents): string => formatCurrencyMinor($cents, 'USD');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>Credit Top-ups — CheckDomain Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body{background:#0F172A;font-family:'Inter',sans-serif;color:#E2E8F0}
.card{background:rgba(30,41,59,.55);border:1px solid rgba(59,130,246,.18);border-radius:14px}
.inp{width:100%;background:#0F172A;border:1px solid #334155;border-radius:10px;padding:10px 12px;color:#E2E8F0;outline:none}
.inp:focus{border-color:#3B82F6}
.btn{border-radius:10px;padding:9px 14px;font-size:13px;font-weight:700;transition:.15s}
.btn-primary{background:#2563EB;color:white}.btn-primary:hover{background:#1D4ED8}
.btn-secondary{background:#334155;color:#CBD5E1}.btn-secondary:hover{background:#475569}
.btn-danger{background:rgba(239,68,68,.18);color:#FCA5A5}.btn-danger:hover{background:rgba(239,68,68,.28)}
.badge{font-size:11px;border-radius:999px;padding:3px 8px;font-weight:700}
</style>
</head>
<body>
<?php include_once 'includes/sidebar.php'; ?>
<main style="margin-left:16rem;" class="min-h-screen">
  <div class="p-4 md:p-8">
    <div class="flex flex-col sm:flex-row justify-between gap-4 mb-6">
      <div>
        <h1 class="text-2xl md:text-3xl font-bold">Credit Top-ups</h1>
        <p class="text-gray-400 text-sm mt-1">Edit the credit bundles shown on the billing page.</p>
      </div>
      <button onclick="openModal()" class="btn btn-primary"><i class="fas fa-plus mr-2"></i>New package</button>
    </div>

    <?php if ($flash): ?>
    <div class="<?= $flash['type'] === 'ok' ? 'bg-green-500/15 border-green-500/30 text-green-200' : 'bg-red-500/15 border-red-500/30 text-red-200' ?> border rounded-lg p-3 mb-5">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
      <?php foreach ($packages as $pkg): ?>
      <div class="card p-5">
        <div class="flex items-start justify-between gap-3 mb-4">
          <div>
            <div class="text-lg font-black text-white"><?= htmlspecialchars($pkg['name']) ?></div>
            <div class="text-gray-500 text-xs">Sort <?= (int)$pkg['sort_order'] ?></div>
          </div>
          <div class="flex gap-1 flex-wrap justify-end">
            <?php if ($pkg['is_popular']): ?><span class="badge bg-amber-500/15 text-amber-300">Popular</span><?php endif; ?>
            <span class="badge <?= $pkg['is_active'] ? 'bg-green-500/15 text-green-300' : 'bg-gray-500/15 text-gray-400' ?>"><?= $pkg['is_active'] ? 'Active' : 'Hidden' ?></span>
          </div>
        </div>
        <div class="space-y-2 text-sm mb-5">
          <div class="flex justify-between"><span class="text-gray-400">Credits</span><span class="font-mono font-bold"><?= number_format((int)$pkg['credits']) ?></span></div>
          <div class="flex justify-between"><span class="text-gray-400">Bonus</span><span class="font-mono font-bold text-amber-300"><?= number_format((int)$pkg['bonus_credits']) ?></span></div>
          <div class="flex justify-between"><span class="text-gray-400">Price</span><span class="font-mono font-bold text-green-300"><?= $money((int)$pkg['price_kobo']) ?></span></div>
        </div>
        <div class="flex gap-2">
          <button class="btn btn-secondary flex-1" onclick='openModal(<?= htmlspecialchars(json_encode($pkg), ENT_QUOTES) ?>)'><i class="fas fa-edit mr-1"></i>Edit</button>
          <form method="POST" onsubmit="return confirm('Delete this credit package?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$pkg['id'] ?>">
            <button class="btn btn-danger" type="submit"><i class="fas fa-trash"></i></button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/60 z-[100] items-center justify-center p-4">
  <div class="card w-full max-w-xl p-6">
    <div class="flex justify-between items-center mb-5">
      <h2 id="modalTitle" class="text-xl font-bold">New package</h2>
      <button onclick="closeModal()" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" id="f-action" value="create">
      <input type="hidden" name="id" id="f-id" value="0">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div><label class="text-sm text-gray-400 block mb-1">Name</label><input class="inp" name="name" id="f-name" required></div>
        <div><label class="text-sm text-gray-400 block mb-1">Price (USD)</label><input class="inp" type="number" min="0" step="0.01" name="price_usd" id="f-price" required></div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div><label class="text-sm text-gray-400 block mb-1">Credits</label><input class="inp" type="number" min="1" step="1" name="credits" id="f-credits" required></div>
        <div><label class="text-sm text-gray-400 block mb-1">Bonus credits</label><input class="inp" type="number" min="0" step="1" name="bonus_credits" id="f-bonus" value="0"></div>
        <div><label class="text-sm text-gray-400 block mb-1">Sort order</label><input class="inp" type="number" min="0" step="1" name="sort_order" id="f-sort" value="0"></div>
      </div>
      <div class="flex flex-wrap gap-5 text-sm text-gray-300">
        <label class="flex items-center gap-2"><input type="checkbox" name="is_popular" id="f-popular" class="accent-blue-500"> Popular</label>
        <label class="flex items-center gap-2"><input type="checkbox" name="is_active" id="f-active" class="accent-blue-500" checked> Active</label>
      </div>
      <div class="flex justify-end gap-3 pt-4 border-t border-slate-700">
        <button type="button" onclick="closeModal()" class="btn btn-secondary">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-2"></i>Save</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(pkg=null){
  document.getElementById('modal').classList.remove('hidden');
  document.getElementById('modal').classList.add('flex');
  document.getElementById('modalTitle').textContent = pkg ? 'Edit package' : 'New package';
  document.getElementById('f-action').value = pkg ? 'save' : 'create';
  document.getElementById('f-id').value = pkg?.id || 0;
  document.getElementById('f-name').value = pkg?.name || '';
  document.getElementById('f-price').value = pkg ? ((pkg.price_kobo || 0) / 100) : '';
  document.getElementById('f-credits').value = pkg?.credits || '';
  document.getElementById('f-bonus').value = pkg?.bonus_credits || 0;
  document.getElementById('f-sort').value = pkg?.sort_order || 0;
  document.getElementById('f-popular').checked = !!Number(pkg?.is_popular || 0);
  document.getElementById('f-active').checked = pkg ? !!Number(pkg.is_active) : true;
}
function closeModal(){
  document.getElementById('modal').classList.add('hidden');
  document.getElementById('modal').classList.remove('flex');
}
document.getElementById('modal').addEventListener('click', e => { if (e.target === e.currentTarget) closeModal(); });
</script>
</body>
</html>
