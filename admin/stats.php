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

// ==================== HOURLY STATS ====================
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Analytics Dashboard - checkdomain.top</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #0F172A;
            font-family: 'Inter', sans-serif;
            overflow-x: hidden;
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
        
        .chart-container {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 1rem;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .chart-container:hover {
            border-color: rgba(59, 130, 246, 0.4);
        }
        
        .main-content {
            transition: margin-left 0.3s ease;
        }
        
        /* Responsive tables */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 1rem !important;
            }
            
            .chart-container {
                padding: 1rem;
            }
            
            .chart-container h3 {
                font-size: 1rem;
            }
            
            .stat-card {
                padding: 1rem !important;
            }
            
            .stat-card p:first-child {
                font-size: 0.7rem;
            }
            
            .stat-card .text-3xl {
                font-size: 1.5rem;
            }
            
            .p-8 {
                padding: 1rem;
            }
            
            table {
                font-size: 0.75rem;
            }
            
            table td, table th {
                padding: 0.5rem !important;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr !important;
            }
            
            .flex.justify-between {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch !important;
            }
            
            .flex.gap-3 {
                flex-wrap: wrap;
            }
            
            select, button {
                width: 100%;
            }
        }
        
        /* Loading animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(59, 130, 246, 0.3);
            border-radius: 50%;
            border-top-color: #3B82F6;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.5);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #3B82F6;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #60A5FA;
        }
    </style>
</head>
<body class="text-white">
    <!-- Include Sidebar -->
    <?php include_once 'includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content" style="margin-left: 16rem;">
        <div class="p-4 md:p-8">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                <div>
                    <h1 class="text-2xl md:text-3xl font-bold">Analytics Dashboard</h1>
                    <p class="text-gray-400 text-sm mt-1">Welcome back, <?php echo htmlspecialchars($user['username']); ?>!</p>
                </div>
                <div class="flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
                    <select id="periodSelect" class="bg-slate-800 border border-blue-500/30 rounded-lg px-4 py-2 text-sm w-full sm:w-auto">
                        <option value="7" <?php echo $period == 7 ? 'selected' : ''; ?>>Last 7 days</option>
                        <option value="30" <?php echo $period == 30 ? 'selected' : ''; ?>>Last 30 days</option>
                        <option value="90" <?php echo $period == 90 ? 'selected' : ''; ?>>Last 90 days</option>
                        <option value="365" <?php echo $period == 365 ? 'selected' : ''; ?>>Last year</option>
                    </select>
                    <button onclick="window.location.reload()" class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg transition w-full sm:w-auto">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="stats-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6 mb-8">
                <div class="stat-card rounded-xl p-4 md:p-6">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs md:text-sm">Total Subscribers</p>
                            <p class="text-2xl md:text-3xl font-bold mt-2"><?php echo number_format($totalSubscribers); ?></p>
                            <p class="text-green-400 text-xs mt-2">
                                <i class="fas fa-arrow-up"></i> +<?php echo number_format($periodStats['new_subscribers']); ?> this period
                            </p>
                        </div>
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-blue-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-users text-blue-400 text-lg md:text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card rounded-xl p-4 md:p-6">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs md:text-sm">Total Page Views</p>
                            <p class="text-2xl md:text-3xl font-bold mt-2"><?php echo number_format($totalPageViews); ?></p>
                            <p class="text-blue-400 text-xs mt-2">
                                <i class="fas fa-chart-line"></i> <?php echo number_format($periodStats['period_views']); ?> this period
                            </p>
                        </div>
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-green-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-eye text-green-400 text-lg md:text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card rounded-xl p-4 md:p-6">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs md:text-sm">Unique Visitors</p>
                            <p class="text-2xl md:text-3xl font-bold mt-2"><?php echo number_format($totalUniqueVisitors); ?></p>
                            <p class="text-purple-400 text-xs mt-2">
                                <i class="fas fa-users"></i> <?php echo number_format($periodStats['period_visitors']); ?> unique
                            </p>
                        </div>
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-purple-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-user-friends text-purple-400 text-lg md:text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card rounded-xl p-4 md:p-6">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs md:text-sm">Pinned Domains</p>
                            <p class="text-2xl md:text-3xl font-bold mt-2"><?php echo number_format($totalPinnedDomains); ?></p>
                            <p class="text-orange-400 text-xs mt-2">
                                <i class="fas fa-thumbtack"></i> <?php echo number_format($periodStats['period_pins']); ?> new
                            </p>
                        </div>
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-orange-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-thumbtack text-orange-400 text-lg md:text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Charts Row 1 -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 md:gap-6 mb-8">
                <div class="chart-container">
                    <h3 class="text-base md:text-lg font-semibold mb-4">Subscriber Growth</h3>
                    <canvas id="growthChart" style="max-height: 300px;"></canvas>
                </div>
                
                <div class="chart-container">
                    <h3 class="text-base md:text-lg font-semibold mb-4">Page Views vs Unique Visitors</h3>
                    <canvas id="viewsChart" style="max-height: 300px;"></canvas>
                </div>
            </div>
            
            <!-- Charts Row 2 -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 md:gap-6 mb-8">
                <div class="chart-container">
                    <h3 class="text-base md:text-lg font-semibold mb-4">24-Hour Traffic Pattern</h3>
                    <canvas id="hourlyChart" style="max-height: 300px;"></canvas>
                </div>
                
                <div class="chart-container">
                    <h3 class="text-base md:text-lg font-semibold mb-4">Top Pinned Domains</h3>
                    <canvas id="topDomainsChart" style="max-height: 300px;"></canvas>
                </div>
            </div>
            
            <!-- Charts Row 3 -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 md:gap-6 mb-8">
                <div class="chart-container">
                    <h3 class="text-base md:text-lg font-semibold mb-4">Device Distribution</h3>
                    <canvas id="deviceChart" style="max-height: 250px;"></canvas>
                </div>
                
                <div class="chart-container">
                    <h3 class="text-base md:text-lg font-semibold mb-4">Browser Distribution</h3>
                    <canvas id="browserChart" style="max-height: 250px;"></canvas>
                </div>
            </div>
            
            <!-- Recent Subscribers -->
            <div class="chart-container mb-8">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
                    <h3 class="text-base md:text-lg font-semibold">Recent Subscribers</h3>
                    <a href="subscribers.php" class="text-blue-400 text-sm hover:underline">View All →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs md:text-sm">
                        <thead class="border-b border-gray-700">
                            <tr>
                                <th class="text-left py-3">Email</th>
                                <th class="text-left py-3">Name</th>
                                <th class="text-left py-3">Subscribed At</th>
                                <th class="text-left py-3 hidden md:table-cell">Source</th>
                                <th class="text-left py-3 hidden lg:table-cell">IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $recentSubscribers->fetch_assoc()): ?>
                            <tr class="border-b border-gray-800">
                                <td class="py-2"><?php echo htmlspecialchars(substr($row['email'], 0, 20) . (strlen($row['email']) > 20 ? '...' : '')); ?></td>
                                <td class="py-2"><?php echo htmlspecialchars($row['name'] ?: '—'); ?></td>
                                <td class="py-2 whitespace-nowrap"><?php echo date('M d, H:i', strtotime($row['subscribed_at'])); ?></td>
                                <td class="py-2 hidden md:table-cell"><?php echo htmlspecialchars($row['source']); ?></td>
                                <td class="py-2 hidden lg:table-cell font-mono text-xs"><?php echo $row['ip_address']; ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Recent Admin Activity -->
            <div class="chart-container">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
                    <h3 class="text-base md:text-lg font-semibold">Recent Admin Activity</h3>
                    <a href="activity.php" class="text-blue-400 text-sm hover:underline">View All →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs md:text-sm">
                        <thead class="border-b border-gray-700">
                            <tr>
                                <th class="text-left py-3">Admin</th>
                                <th class="text-left py-3">Action</th>
                                <th class="text-left py-3 hidden lg:table-cell">Details</th>
                                <th class="text-left py-3">Time</th>
                                <th class="text-left py-3 hidden xl:table-cell">IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $recentActivity->fetch_assoc()): ?>
                            <tr class="border-b border-gray-800">
                                <td class="py-2"><?php echo htmlspecialchars($row['username'] ?? 'System'); ?></td>
                                <td class="py-2">
                                    <span class="px-2 py-1 bg-blue-500/20 rounded-full text-xs whitespace-nowrap">
                                        <?php echo htmlspecialchars($row['action']); ?>
                                    </span>
                                 </td>
                                <td class="py-2 hidden lg:table-cell"><?php echo htmlspecialchars(substr($row['details'] ?? '—', 0, 30)); ?></td>
                                <td class="py-2 whitespace-nowrap"><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></td>
                                <td class="py-2 hidden xl:table-cell font-mono text-xs"><?php echo $row['ip_address']; ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
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
                    pointRadius: 3,
                    pointHoverRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        labels: { color: '#fff', font: { size: 11 } }
                    }
                },
                scales: {
                    y: {
                        ticks: { color: '#fff', stepSize: 1, font: { size: 10 } },
                        grid: { color: '#374151' }
                    },
                    x: {
                        ticks: { color: '#fff', maxRotation: 45, font: { size: 10 } },
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
                        pointBackgroundColor: '#3B82F6',
                        pointRadius: 3
                    },
                    {
                        label: 'Unique Visitors',
                        data: <?php echo json_encode($uniqueCounts); ?>,
                        borderColor: '#8B5CF6',
                        backgroundColor: 'rgba(139, 92, 246, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#8B5CF6',
                        pointRadius: 3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        labels: { color: '#fff', font: { size: 11 } }
                    }
                },
                scales: {
                    y: {
                        ticks: { color: '#fff', stepSize: 1, font: { size: 10 } },
                        grid: { color: '#374151' }
                    },
                    x: {
                        ticks: { color: '#fff', maxRotation: 45, font: { size: 10 } },
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
                    borderRadius: 6,
                    barPercentage: 0.8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        labels: { color: '#fff', font: { size: 11 } }
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
                        ticks: { color: '#fff', stepSize: 1, font: { size: 10 } },
                        grid: { color: '#374151' }
                    },
                    x: {
                        ticks: { color: '#fff', font: { size: 10 } },
                        grid: { color: '#374151' }
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
                    borderRadius: 6,
                    barPercentage: 0.8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                indexAxis: window.innerWidth < 768 ? 'x' : 'y',
                plugins: {
                    legend: {
                        labels: { color: '#fff', font: { size: 11 } }
                    }
                },
                scales: {
                    y: {
                        ticks: { color: '#fff', font: { size: 10 } },
                        grid: { color: '#374151' }
                    },
                    x: {
                        ticks: { color: '#fff', stepSize: 1, font: { size: 10 } },
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
                        labels: { color: '#fff', font: { size: 11 }, position: 'bottom' }
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
                        labels: { color: '#fff', font: { size: 11 }, position: 'bottom' }
                    }
                }
            }
        });
        
        // Adjust chart orientation on window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth < 768) {
                domainsCtx.canvas.chart.config.options.indexAxis = 'x';
            } else {
                domainsCtx.canvas.chart.config.options.indexAxis = 'y';
            }
            domainsCtx.canvas.chart.update();
        });
    </script>
</body>
</html>