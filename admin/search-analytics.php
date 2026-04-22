<?php
require_once 'auth_check.php';
$user = checkAdminAuth();
require_once '../config/database.php';

$conn = getDBConnection();
$stats = getDomainSearchStats(30);

// Get recent searches
$recentSearches = $conn->query("SELECT domain_name, tld, is_available, searched_at, ip_address FROM domain_searches ORDER BY searched_at DESC LIMIT 50");

// Get top TLDs
$topTLDs = $conn->query("SELECT tld, COUNT(*) as count FROM domain_searches GROUP BY tld ORDER BY count DESC LIMIT 20");

// Get hourly search activity
$hourlyActivity = $conn->query("SELECT HOUR(searched_at) as hour, COUNT(*) as count FROM domain_searches WHERE searched_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY HOUR(searched_at) ORDER BY hour ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Analytics - checkdomain.top Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white font-['Inter']">
    <div class="flex h-screen">
        <!-- Sidebar -->
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
                    <a href="search-analytics.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition bg-blue-500/20">
                        <i class="fas fa-chart-line w-5"></i>
                        <span>Search Analytics</span>
                    </a>
                    <a href="domains.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                        <i class="fas fa-thumbtack w-5"></i>
                        <span>Pinned Domains</span>
                    </a>
                    <a href="activity.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                        <i class="fas fa-history w-5"></i>
                        <span>Activity Log</span>
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
                    <h1 class="text-3xl font-bold">Search Analytics</h1>
                    <p class="text-gray-400 mt-1">Track domain searches, popular TLDs, and user behavior</p>
                </div>
                
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                    <div class="bg-slate-800/50 rounded-xl p-6">
                        <p class="text-gray-400 text-sm">Total Searches (30d)</p>
                        <p class="text-2xl font-bold mt-2"><?php echo number_format($stats['total_searches'] ?? 0); ?></p>
                    </div>
                    <div class="bg-slate-800/50 rounded-xl p-6">
                        <p class="text-gray-400 text-sm">Available Domains Found</p>
                        <p class="text-2xl font-bold mt-2 text-green-400"><?php echo number_format($stats['available'] ?? 0); ?></p>
                    </div>
                    <div class="bg-slate-800/50 rounded-xl p-6">
                        <p class="text-gray-400 text-sm">Taken Domains</p>
                        <p class="text-2xl font-bold mt-2 text-red-400"><?php echo number_format($stats['taken'] ?? 0); ?></p>
                    </div>
                    <div class="bg-slate-800/50 rounded-xl p-6">
                        <p class="text-gray-400 text-sm">Availability Rate</p>
                        <p class="text-2xl font-bold mt-2 text-blue-400">
                            <?php 
                            $total = ($stats['available'] ?? 0) + ($stats['taken'] ?? 0);
                            $rate = $total > 0 ? round(($stats['available'] ?? 0) / $total * 100) : 0;
                            echo $rate . '%';
                            ?>
                        </p>
                    </div>
                </div>
                
                <!-- Top TLDs Chart -->
                <div class="bg-slate-800/50 rounded-xl p-6 mb-8">
                    <h2 class="text-lg font-semibold mb-4">Most Searched TLDs</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="border-b border-gray-700">
                                <tr><th class="text-left py-2">TLD</th><th class="text-left py-2">Search Count</th><th class="text-left py-2">Percentage</th></tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total = 0;
                                $tldsData = [];
                                while($row = $topTLDs->fetch_assoc()) {
                                    $tldsData[] = $row;
                                    $total += $row['count'];
                                }
                                foreach($tldsData as $row): 
                                $percent = $total > 0 ? round($row['count'] / $total * 100, 1) : 0;
                                ?>
                                <tr class="border-b border-gray-800">
                                    <td class="py-2 font-mono">.<?php echo $row['tld']; ?></td>
                                    <td class="py-2"><?php echo number_format($row['count']); ?></td>
                                    <td class="py-2">
                                        <div class="flex items-center gap-2">
                                            <div class="flex-1 h-2 bg-gray-700 rounded-full overflow-hidden">
                                                <div class="h-full bg-blue-500 rounded-full" style="width: <?php echo $percent; ?>%"></div>
                                            </div>
                                            <span class="text-xs"><?php echo $percent; ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Recent Searches -->
                <div class="bg-slate-800/50 rounded-xl p-6">
                    <h2 class="text-lg font-semibold mb-4">Recent Domain Searches</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-gray-700">
                                <tr><th class="text-left py-2">Domain</th><th class="text-left py-2">TLD</th><th class="text-left py-2">Status</th><th class="text-left py-2">Time</th><th class="text-left py-2">IP</th></tr>
                            </thead>
                            <tbody>
                                <?php while($row = $recentSearches->fetch_assoc()): ?>
                                <tr class="border-b border-gray-800">
                                    <td class="py-2 font-mono"><?php echo $row['domain_name']; ?></td>
                                    <td class="py-2">.<?php echo $row['tld']; ?></td>
                                    <td class="py-2">
                                        <span class="px-2 py-1 rounded-full text-xs <?php echo $row['is_available'] ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'; ?>">
                                            <?php echo $row['is_available'] ? 'Available' : 'Taken'; ?>
                                        </span>
                                    </td>
                                    <td class="py-2"><?php echo date('M d, H:i', strtotime($row['searched_at'])); ?></td>
                                    <td class="py-2 font-mono text-xs"><?php echo $row['ip_address']; ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

<?php $conn->close(); ?>