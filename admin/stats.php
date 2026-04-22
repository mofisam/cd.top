<?php
require_once 'auth_check.php';
$user = checkAdminAuth();
require_once '../config/database.php';

$conn = getDBConnection();

// Time period filter
$period = $_GET['period'] ?? '30';
$days = intval($period);

// Get date ranges
$endDate = date('Y-m-d');
$startDate = date('Y-m-d', strtotime("-$days days"));

// ==================== MAIN STATS ====================
$totalSubscribers = $conn->query("SELECT COUNT(*) as total FROM subscribers WHERE status = 'active'")->fetch_assoc()['total'];
$totalPageViews = $conn->query("SELECT COUNT(*) as total FROM page_views")->fetch_assoc()['total'];
$totalUniqueVisitors = $conn->query("SELECT COUNT(DISTINCT session_id) as total FROM page_views")->fetch_assoc()['total'];
$totalPinnedDomains = $conn->query("SELECT COUNT(*) as total FROM pinned_domains WHERE status = 'active'")->fetch_assoc()['total'];

// ==================== PERIOD STATS ====================
$periodStats = $conn->query("
    SELECT 
        COUNT(DISTINCT CASE WHEN DATE(subscribed_at) BETWEEN '$startDate' AND '$endDate' THEN s.id END) as new_subscribers,
        COUNT(DISTINCT CASE WHEN DATE(view_date) BETWEEN '$startDate' AND '$endDate' THEN pv.session_id END) as period_visitors,
        COUNT(CASE WHEN DATE(view_date) BETWEEN '$startDate' AND '$endDate' THEN 1 END) as period_views,
        COUNT(DISTINCT CASE WHEN DATE(pinned_at) BETWEEN '$startDate' AND '$endDate' THEN pd.id END) as period_pins
    FROM subscribers s
    CROSS JOIN page_views pv
    LEFT JOIN pinned_domains pd ON 1=1
")->fetch_assoc();

// ==================== SUBSCRIBER GROWTH ====================
$growthData = $conn->query("
    SELECT 
        DATE(subscribed_at) as date,
        COUNT(*) as count
    FROM subscribers 
    WHERE subscribed_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
    GROUP BY DATE(subscribed_at)
    ORDER BY date ASC
");

$growthLabels = [];
$growthCounts = [];
while ($row = $growthData->fetch_assoc()) {
    $growthLabels[] = $row['date'];
    $growthCounts[] = $row['count'];
}

// ==================== PAGE VIEWS TREND ====================
$viewsData = $conn->query("
    SELECT 
        DATE(view_date) as date,
        COUNT(*) as views,
        COUNT(DISTINCT session_id) as unique_visitors
    FROM page_views 
    WHERE view_date >= DATE_SUB(NOW(), INTERVAL $days DAY)
    GROUP BY DATE(view_date)
    ORDER BY date ASC
");

$viewsLabels = [];
$viewsCounts = [];
$uniqueCounts = [];
while ($row = $viewsData->fetch_assoc()) {
    $viewsLabels[] = $row['date'];
    $viewsCounts[] = $row['views'];
    $uniqueCounts[] = $row['unique_visitors'];
}

// ==================== TOP DOMAINS PINNED ====================
$topDomains = $conn->query("
    SELECT 
        domain_name,
        COUNT(*) as pin_count
    FROM pinned_domains
    WHERE status = 'active'
    GROUP BY domain_name
    ORDER BY pin_count DESC
    LIMIT 10
");

// ==================== SUBSCRIBER SOURCES ====================
$sources = $conn->query("
    SELECT 
        source,
        COUNT(*) as count
    FROM subscribers
    GROUP BY source
    ORDER BY count DESC
");

// ==================== RECENT ACTIVITY ====================
$recentSubscribers = $conn->query("
    SELECT email, name, subscribed_at, ip_address, source 
    FROM subscribers 
    ORDER BY subscribed_at DESC 
    LIMIT 15
");

$recentActivity = $conn->query("
    SELECT a.*, u.username 
    FROM admin_activity_log a 
    LEFT JOIN admin_users u ON a.user_id = u.id 
    ORDER BY a.created_at DESC 
    LIMIT 20
");

// ==================== HOURLY STATS (Last 24h) ====================
$hourlyStats = $conn->query("
    SELECT 
        HOUR(view_date) as hour,
        COUNT(*) as views
    FROM page_views 
    WHERE view_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY HOUR(view_date)
    ORDER BY hour ASC
");

$hours = [];
$hourlyViews = [];
for ($i = 0; $i < 24; $i++) {
    $hours[] = $i;
    $hourlyViews[$i] = 0;
}
while ($row = $hourlyStats->fetch_assoc()) {
    $hourlyViews[$row['hour']] = $row['views'];
}

// ==================== DEVICE STATS ====================
$devices = $conn->query("
    SELECT 
        CASE 
            WHEN user_agent LIKE '%Mobile%' OR user_agent LIKE '%Android%' OR user_agent LIKE '%iPhone%' THEN 'Mobile'
            WHEN user_agent LIKE '%Tablet%' OR user_agent LIKE '%iPad%' THEN 'Tablet'
            ELSE 'Desktop'
        END as device_type,
        COUNT(*) as count
    FROM page_views
    GROUP BY device_type
");

// ==================== BROWSER STATS ====================
$browsers = $conn->query("
    SELECT 
        CASE 
            WHEN user_agent LIKE '%Chrome%' AND user_agent NOT LIKE '%Edg%' THEN 'Chrome'
            WHEN user_agent LIKE '%Firefox%' THEN 'Firefox'
            WHEN user_agent LIKE '%Safari%' AND user_agent NOT LIKE '%Chrome%' THEN 'Safari'
            WHEN user_agent LIKE '%Edg%' THEN 'Edge'
            WHEN user_agent LIKE '%Opera%' THEN 'Opera'
            ELSE 'Other'
        END as browser,
        COUNT(*) as count
    FROM page_views
    GROUP BY browser
    ORDER BY count DESC
");

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - checkdomain.top</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            background: #0F172A;
            font-family: 'Inter', sans-serif;
        }
        .stat-card {
            background: linear-gradient(135deg, rgba(30, 58, 138, 0.3), rgba(16, 185, 129, 0.1));
            backdrop-filter: blur(10px);
            border: 1px solid rgba(59, 130, 246, 0.3);
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            border-color: rgba(16, 185, 129, 0.5);
        }
        .sidebar {
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(10px);
            border-right: 1px solid rgba(59, 130, 246, 0.3);
        }
        .nav-item {
            transition: all 0.2s ease;
        }
        .nav-item:hover, .nav-item.active {
            background: rgba(59, 130, 246, 0.2);
            border-left: 3px solid #3B82F6;
            padding-left: 1.5rem;
        }
        .chart-container {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 1rem;
            padding: 1.5rem;
        }
    </style>
</head>
<body class="text-white">
    <div class="flex h-screen">
        <!-- Sidebar -->
        <div class="sidebar w-64 fixed h-full overflow-y-auto">
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
                    <a href="stats.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                        <i class="fas fa-tachometer-alt w-5"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="subscribers.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                        <i class="fas fa-users w-5"></i>
                        <span>Subscribers</span>
                    </a>
                    <a href="domains.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
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
                <!-- Header -->
                <div class="flex justify-between items-center mb-8">
                    <div>
                        <h1 class="text-3xl font-bold">Analytics Dashboard</h1>
                        <p class="text-gray-400 mt-1">Welcome back, <?php echo htmlspecialchars($user['username']); ?>!</p>
                    </div>
                    <div class="flex gap-3">
                        <select id="periodSelect" class="bg-slate-800 border border-blue-500/30 rounded-lg px-4 py-2 text-sm">
                            <option value="7" <?php echo $period == 7 ? 'selected' : ''; ?>>Last 7 days</option>
                            <option value="30" <?php echo $period == 30 ? 'selected' : ''; ?>>Last 30 days</option>
                            <option value="90" <?php echo $period == 90 ? 'selected' : ''; ?>>Last 90 days</option>
                            <option value="365" <?php echo $period == 365 ? 'selected' : ''; ?>>Last year</option>
                        </select>
                        <button onclick="window.location.reload()" class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg transition">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                </div>
                
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-gray-400 text-sm">Total Subscribers</p>
                                <p class="text-3xl font-bold mt-2"><?php echo number_format($totalSubscribers); ?></p>
                                <p class="text-green-400 text-xs mt-2">
                                    <i class="fas fa-arrow-up"></i> +<?php echo number_format($periodStats['new_subscribers']); ?> this period
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-blue-500/20 rounded-full flex items-center justify-center">
                                <i class="fas fa-users text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-gray-400 text-sm">Total Page Views</p>
                                <p class="text-3xl font-bold mt-2"><?php echo number_format($totalPageViews); ?></p>
                                <p class="text-blue-400 text-xs mt-2">
                                    <i class="fas fa-chart-line"></i> <?php echo number_format($periodStats['period_views']); ?> this period
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-green-500/20 rounded-full flex items-center justify-center">
                                <i class="fas fa-eye text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-gray-400 text-sm">Unique Visitors</p>
                                <p class="text-3xl font-bold mt-2"><?php echo number_format($totalUniqueVisitors); ?></p>
                                <p class="text-purple-400 text-xs mt-2">
                                    <i class="fas fa-users"></i> <?php echo number_format($periodStats['period_visitors']); ?> unique this period
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-purple-500/20 rounded-full flex items-center justify-center">
                                <i class="fas fa-user-friends text-purple-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-gray-400 text-sm">Pinned Domains</p>
                                <p class="text-3xl font-bold mt-2"><?php echo number_format($totalPinnedDomains); ?></p>
                                <p class="text-orange-400 text-xs mt-2">
                                    <i class="fas fa-thumbtack"></i> <?php echo number_format($periodStats['period_pins']); ?> new pins
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-orange-500/20 rounded-full flex items-center justify-center">
                                <i class="fas fa-thumbtack text-orange-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Charts Row 1 -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="chart-container">
                        <h3 class="text-lg font-semibold mb-4">Subscriber Growth</h3>
                        <canvas id="growthChart"></canvas>
                    </div>
                    
                    <div class="chart-container">
                        <h3 class="text-lg font-semibold mb-4">Page Views vs Unique Visitors</h3>
                        <canvas id="viewsChart"></canvas>
                    </div>
                </div>
                
                <!-- Charts Row 2 -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="chart-container">
                        <h3 class="text-lg font-semibold mb-4">24-Hour Traffic Pattern</h3>
                        <canvas id="hourlyChart"></canvas>
                    </div>
                    
                    <div class="chart-container">
                        <h3 class="text-lg font-semibold mb-4">Top Pinned Domains</h3>
                        <canvas id="topDomainsChart"></canvas>
                    </div>
                </div>
                
                <!-- Charts Row 3 -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="chart-container">
                        <h3 class="text-lg font-semibold mb-4">Device Distribution</h3>
                        <canvas id="deviceChart"></canvas>
                    </div>
                    
                    <div class="chart-container">
                        <h3 class="text-lg font-semibold mb-4">Browser Distribution</h3>
                        <canvas id="browserChart"></canvas>
                    </div>
                </div>
                
                <!-- Recent Subscribers -->
                <div class="chart-container mb-8">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold">Recent Subscribers</h3>
                        <a href="subscribers.php" class="text-blue-400 text-sm hover:underline">View All →</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-gray-700">
                                <tr>
                                    <th class="text-left py-3">Email</th>
                                    <th class="text-left py-3">Name</th>
                                    <th class="text-left py-3">Subscribed At</th>
                                    <th class="text-left py-3">Source</th>
                                    <th class="text-left py-3">IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $recentSubscribers->fetch_assoc()): ?>
                                <tr class="border-b border-gray-800">
                                    <td class="py-2"><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td class="py-2"><?php echo htmlspecialchars($row['name'] ?: '—'); ?></td>
                                    <td class="py-2"><?php echo date('M d, Y H:i', strtotime($row['subscribed_at'])); ?></td>
                                    <td class="py-2"><?php echo htmlspecialchars($row['source']); ?></td>
                                    <td class="py-2 font-mono text-xs"><?php echo $row['ip_address']; ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Recent Admin Activity -->
                <div class="chart-container">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold">Recent Admin Activity</h3>
                        <a href="activity.php" class="text-blue-400 text-sm hover:underline">View All →</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-gray-700">
                                <tr>
                                    <th class="text-left py-3">Admin</th>
                                    <th class="text-left py-3">Action</th>
                                    <th class="text-left py-3">Details</th>
                                    <th class="text-left py-3">Time</th>
                                    <th class="text-left py-3">IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $recentActivity->fetch_assoc()): ?>
                                <tr class="border-b border-gray-800">
                                    <td class="py-2"><?php echo htmlspecialchars($row['username'] ?? 'System'); ?></td>
                                    <td class="py-2">
                                        <span class="px-2 py-1 bg-blue-500/20 rounded-full text-xs">
                                            <?php echo htmlspecialchars($row['action']); ?>
                                        </span>
                                    </td>
                                    <td class="py-2"><?php echo htmlspecialchars($row['details'] ?? '—'); ?></td>
                                    <td class="py-2"><?php echo date('M d, Y H:i', strtotime($row['created_at'])); ?></td>
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
    
    <script>
        // Period selector
        document.getElementById('periodSelect').addEventListener('change', function() {
            window.location.href = '?period=' + this.value;
        });
        
        // Subscriber Growth Chart
        const growthCtx = document.getElementById('growthChart').getContext('2d');
        new Chart(growthCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($growthLabels); ?>,
                datasets: [{
                    label: 'New Subscribers',
                    data: <?php echo json_encode($growthCounts); ?>,
                    borderColor: '#10B981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#10B981',
                    pointBorderColor: '#fff',
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        labels: { color: '#fff' }
                    }
                },
                scales: {
                    y: {
                        ticks: { color: '#fff', stepSize: 1 },
                        grid: { color: '#374151' }
                    },
                    x: {
                        ticks: { color: '#fff', maxRotation: 45 },
                        grid: { color: '#374151' }
                    }
                }
            }
        });
        
        // Page Views Chart
        const viewsCtx = document.getElementById('viewsChart').getContext('2d');
        new Chart(viewsCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($viewsLabels); ?>,
                datasets: [
                    {
                        label: 'Page Views',
                        data: <?php echo json_encode($viewsCounts); ?>,
                        borderColor: '#3B82F6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#3B82F6'
                    },
                    {
                        label: 'Unique Visitors',
                        data: <?php echo json_encode($uniqueCounts); ?>,
                        borderColor: '#8B5CF6',
                        backgroundColor: 'rgba(139, 92, 246, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#8B5CF6'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        labels: { color: '#fff' }
                    }
                },
                scales: {
                    y: {
                        ticks: { color: '#fff', stepSize: 1 },
                        grid: { color: '#374151' }
                    },
                    x: {
                        ticks: { color: '#fff', maxRotation: 45 },
                        grid: { color: '#374151' }
                    }
                }
            }
        });
        
        // Hourly Traffic Chart
        const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
        new Chart(hourlyCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($hours); ?>,
                datasets: [{
                    label: 'Page Views',
                    data: <?php echo json_encode(array_values($hourlyViews)); ?>,
                    backgroundColor: '#10B981',
                    borderRadius: 8,
                    barPercentage: 0.8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        labels: { color: '#fff' }
                    },
                    tooltip: {
                        callbacks: {
                            title: function(context) {
                                return `${context[0].label}:00 - ${parseInt(context[0].label)+1}:00`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: { color: '#fff', stepSize: 1 },
                        grid: { color: '#374151' },
                        title: {
                            display: true,
                            text: 'Number of Views',
                            color: '#9CA3AF'
                        }
                    },
                    x: {
                        ticks: { color: '#fff' },
                        grid: { color: '#374151' },
                        title: {
                            display: true,
                            text: 'Hour of Day (24h format)',
                            color: '#9CA3AF'
                        }
                    }
                }
            }
        });
        
        // Top Domains Chart
        const topDomainsData = <?php 
            $domains = [];
            $counts = [];
            while($row = $topDomains->fetch_assoc()) {
                $domains[] = $row['domain_name'];
                $counts[] = $row['pin_count'];
            }
            echo json_encode(['labels' => $domains, 'data' => $counts]);
        ?>;
        
        const domainsCtx = document.getElementById('topDomainsChart').getContext('2d');
        new Chart(domainsCtx, {
            type: 'bar',
            data: {
                labels: topDomainsData.labels,
                datasets: [{
                    label: 'Times Pinned',
                    data: topDomainsData.data,
                    backgroundColor: '#F59E0B',
                    borderRadius: 8,
                    barPercentage: 0.8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        labels: { color: '#fff' }
                    }
                },
                scales: {
                    y: {
                        ticks: { color: '#fff' },
                        grid: { color: '#374151' }
                    },
                    x: {
                        ticks: { color: '#fff', stepSize: 1 },
                        grid: { color: '#374151' }
                    }
                }
            }
        });
        
        // Device Distribution Chart
        const deviceData = <?php 
            $devicesList = [];
            $deviceCounts = [];
            while($row = $devices->fetch_assoc()) {
                $devicesList[] = $row['device_type'];
                $deviceCounts[] = $row['count'];
            }
            echo json_encode(['labels' => $devicesList, 'data' => $deviceCounts]);
        ?>;
        
        const deviceCtx = document.getElementById('deviceChart').getContext('2d');
        new Chart(deviceCtx, {
            type: 'doughnut',
            data: {
                labels: deviceData.labels,
                datasets: [{
                    data: deviceData.data,
                    backgroundColor: ['#3B82F6', '#10B981', '#8B5CF6'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        labels: { color: '#fff', position: 'bottom' }
                    }
                }
            }
        });
        
        // Browser Distribution Chart
        const browserData = <?php 
            $browsersList = [];
            $browserCounts = [];
            while($row = $browsers->fetch_assoc()) {
                $browsersList[] = $row['browser'];
                $browserCounts[] = $row['count'];
            }
            echo json_encode(['labels' => $browsersList, 'data' => $browserCounts]);
        ?>;
        
        const browserCtx = document.getElementById('browserChart').getContext('2d');
        new Chart(browserCtx, {
            type: 'pie',
            data: {
                labels: browserData.labels,
                datasets: [{
                    data: browserData.data,
                    backgroundColor: ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#6B7280'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        labels: { color: '#fff', position: 'bottom' }
                    }
                }
            }
        });
    </script>
</body>
</html>