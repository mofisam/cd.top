<?php
require_once 'auth_check.php';
$user = checkAdminAuth();
require_once '../config/database.php';

$conn = getDBConnection();

// Pagination
$page = $_GET['page'] ?? 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$total = $conn->query("SELECT COUNT(*) as count FROM pinned_domains")->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

$pinnedDomains = $conn->query("
    SELECT pd.*, s.email, s.name 
    FROM pinned_domains pd 
    LEFT JOIN subscribers s ON pd.email = s.email 
    ORDER BY pd.pinned_at DESC 
    LIMIT $offset, $limit
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pinned Domains - checkdomain.top Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white font-['Inter']">
    <div class="flex h-screen">
        <!-- Sidebar (same structure) -->
        <div class="sidebar w-64 fixed h-full overflow-y-auto bg-slate-900/95 backdrop-blur border-r border-blue-500/30">
            <div class="p-6 border-b border-blue-500/30">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-green-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-chart-line text-white"></i>
                    </div>
                    <div>
                        <h2 class="font-bold text-lg">checkdomain</h2>
                        <p class="text-xs text-gray-400">Admin Panel</p>
                    </div>
                </div>
            </div>
            
            <nav class="p-4">
                <div class="space-y-2">
                    <a href="stats.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                        <i class="fas fa-tachometer-alt w-5"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="subscribers.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                        <i class="fas fa-users w-5"></i>
                        <span>Subscribers</span>
                    </a>
                    <a href="domains.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition bg-blue-500/20">
                        <i class="fas fa-thumbtack w-5"></i>
                        <span>Pinned Domains</span>
                    </a>
                    <a href="activity.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                        <i class="fas fa-history w-5"></i>
                        <span>Activity Log</span>
                    </a>
                    <a href="settings.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                        <i class="fas fa-cog w-5"></i>
                        <span>Settings</span>
                    </a>
                </div>
                
                <div class="mt-8 pt-8 border-t border-gray-700">
                    <a href="logout.php" class="flex items-center gap-3 px-4 py-3 rounded-lg text-red-400 hover:bg-red-500/10 transition">
                        <i class="fas fa-sign-out-alt w-5"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </nav>
        </div>
        
        <!-- Main Content -->
        <div class="flex-1 ml-64 overflow-y-auto">
            <div class="p-8">
                <div class="mb-8">
                    <h1 class="text-3xl font-bold">Pinned Domains</h1>
                    <p class="text-gray-400 mt-1">Domains that users want to be notified about</p>
                </div>
                
                <div class="bg-slate-800/50 rounded-xl overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-slate-700/50 border-b border-gray-700">
                            <tr>
                                <th class="text-left p-4">Domain</th>
                                <th class="text-left p-4">Subscriber Email</th>
                                <th class="text-left p-4">Name</th>
                                <th class="text-left p-4">Pinned Date</th>
                                <th class="text-left p-4">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $pinnedDomains->fetch_assoc()): ?>
                            <tr class="border-b border-gray-700 hover:bg-slate-700/30">
                                <td class="p-4 font-mono"><?php echo htmlspecialchars($row['domain_name']); ?></td>
                                <td class="p-4"><?php echo htmlspecialchars($row['email']); ?></td>
                                <td class="p-4"><?php echo htmlspecialchars($row['name'] ?: '—'); ?></td>
                                <td class="p-4"><?php echo date('M d, Y H:i', strtotime($row['pinned_at'])); ?></td>
                                <td class="p-4">
                                    <span class="px-2 py-1 bg-yellow-500/20 rounded-full text-xs"><?php echo $row['status']; ?></span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    
                    <?php if($totalPages > 1): ?>
                    <div class="flex justify-center gap-2 p-4">
                        <?php for($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>" class="px-3 py-1 rounded <?php echo $i == $page ? 'bg-blue-600' : 'bg-slate-700 hover:bg-slate-600'; ?>">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

<?php $conn->close(); ?>