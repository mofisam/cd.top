<?php
require_once 'auth_check.php';
$user = checkAdminAuth();
require_once '../config/database.php';

$conn = getDBConnection();

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$country = $_GET['country'] ?? '';
$device = $_GET['device'] ?? '';
$browser = $_GET['browser'] ?? '';

// Build WHERE clause
$whereConditions = ["DATE(view_date) BETWEEN '$dateFrom' AND '$dateTo'"];
if ($country) $whereConditions[] = "country = '$country'";
if ($device) $whereConditions[] = "device_type = '$device'";
if ($browser) $whereConditions[] = "browser_name = '$browser'";
$whereClause = implode(" AND ", $whereConditions);

// ==================== MAIN STATS ====================
// Total page views
$totalViews = $conn->query("SELECT COUNT(*) as total FROM page_views WHERE $whereClause")->fetch_assoc()['total'];

// Unique visitors
$uniqueVisitors = $conn->query("SELECT COUNT(DISTINCT session_id) as total FROM page_views WHERE $whereClause")->fetch_assoc()['total'];

// Average views per visitor
$avgViewsPerVisitor = $uniqueVisitors > 0 ? round($totalViews / $uniqueVisitors, 2) : 0;

// Bounce rate (single page visits)
$bounceCount = $conn->query("SELECT COUNT(*) as total FROM (SELECT session_id, COUNT(*) as views FROM page_views WHERE $whereClause GROUP BY session_id HAVING views = 1) as singles")->fetch_assoc()['total'];
$bounceRate = $uniqueVisitors > 0 ? round(($bounceCount / $uniqueVisitors) * 100, 1) : 0;

// =================== GEOGRAPHIC STATS ====================
// Top countries
$topCountries = $conn->query("
    SELECT country, COUNT(*) as views, COUNT(DISTINCT session_id) as visitors 
    FROM page_views 
    WHERE $whereClause AND country IS NOT NULL AND country != ''
    GROUP BY country 
    ORDER BY views DESC 
    LIMIT 20
");

// Top cities
$topCities = $conn->query("
    SELECT city, country, COUNT(*) as views, COUNT(DISTINCT session_id) as visitors 
    FROM page_views 
    WHERE $whereClause AND city IS NOT NULL AND city != ''
    GROUP BY city, country 
    ORDER BY views DESC 
    LIMIT 20
");

// World map data (for visualization)
$worldData = $conn->query("
    SELECT country, country_code, COUNT(*) as views, COUNT(DISTINCT session_id) as visitors 
    FROM page_views 
    WHERE $whereClause AND country IS NOT NULL AND country != ''
    GROUP BY country, country_code 
    ORDER BY views DESC
");

// ==================== DEVICE & BROWSER STATS ====================
// Device distribution
$deviceStats = $conn->query("
    SELECT device_type, COUNT(*) as views, COUNT(DISTINCT session_id) as visitors 
    FROM page_views 
    WHERE $whereClause
    GROUP BY device_type 
    ORDER BY views DESC
");

// Browser distribution
$browserStats = $conn->query("
    SELECT browser_name, COUNT(*) as views, COUNT(DISTINCT session_id) as visitors 
    FROM page_views 
    WHERE $whereClause
    GROUP BY browser_name 
    ORDER BY views DESC
");

// OS distribution
$osStats = $conn->query("
    SELECT os_name, COUNT(*) as views, COUNT(DISTINCT session_id) as visitors 
    FROM page_views 
    WHERE $whereClause
    GROUP BY os_name 
    ORDER BY views DESC
");

// Screen resolutions
$screenStats = $conn->query("
    SELECT screen_resolution, COUNT(*) as views 
    FROM page_views 
    WHERE $whereClause AND screen_resolution IS NOT NULL
    GROUP BY screen_resolution 
    ORDER BY views DESC 
    LIMIT 10
");

// ==================== ISP STATS ====================
$ispStats = $conn->query("
    SELECT isp, COUNT(*) as views, COUNT(DISTINCT session_id) as visitors 
    FROM page_views 
    WHERE $whereClause AND isp IS NOT NULL
    GROUP BY isp 
    ORDER BY views DESC 
    LIMIT 15
");

// ==================== INDIVIDUAL USER STATS ====================
$userStats = $conn->query("
    SELECT 
        session_id,
        ip_address,
        country,
        city,
        device_type,
        browser_name,
        os_name,
        COUNT(*) as page_views,
        MIN(view_date) as first_visit,
        MAX(view_date) as last_visit
    FROM page_views 
    WHERE $whereClause
    GROUP BY session_id, ip_address, country, city, device_type, browser_name, os_name
    ORDER BY page_views DESC 
    LIMIT 50
");

// ==================== TIME-BASED STATS ====================
// Hourly distribution
$hourlyStats = $conn->query("
    SELECT HOUR(view_date) as hour, COUNT(*) as views 
    FROM page_views 
    WHERE $whereClause
    GROUP BY HOUR(view_date) 
    ORDER BY hour ASC
");

// Daily views for chart
$dailyStats = $conn->query("
    SELECT DATE(view_date) as date, COUNT(*) as views, COUNT(DISTINCT session_id) as visitors 
    FROM page_views 
    WHERE $whereClause
    GROUP BY DATE(view_date) 
    ORDER BY date ASC
");

// ==================== REFERRER STATS ====================
$referrerStats = $conn->query("
    SELECT 
        CASE 
            WHEN referrer LIKE '%google%' THEN 'Google'
            WHEN referrer LIKE '%facebook%' THEN 'Facebook'
            WHEN referrer LIKE '%twitter%' THEN 'Twitter'
            WHEN referrer LIKE '%linkedin%' THEN 'LinkedIn'
            WHEN referrer LIKE '%bing%' THEN 'Bing'
            WHEN referrer LIKE '%yahoo%' THEN 'Yahoo'
            WHEN referrer = '' OR referrer IS NULL THEN 'Direct'
            ELSE 'Other'
        END as source,
        COUNT(*) as views
    FROM page_views 
    WHERE $whereClause
    GROUP BY source 
    ORDER BY views DESC
");

// ==================== GET UNIQUE FILTER VALUES ====================
$countriesList = $conn->query("SELECT DISTINCT country FROM page_views WHERE country IS NOT NULL AND country != '' ORDER BY country");
$devicesList = $conn->query("SELECT DISTINCT device_type FROM page_views WHERE device_type IS NOT NULL ORDER BY device_type");
$browsersList = $conn->query("SELECT DISTINCT browser_name FROM page_views WHERE browser_name IS NOT NULL ORDER BY browser_name");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Views Analytics - checkdomain.top Admin</title>
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
        .filter-bar {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 1rem;
            padding: 1rem;
        }
        .user-card {
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 0.75rem;
            transition: all 0.2s;
        }
        .user-card:hover {
            border-color: rgba(16, 185, 129, 0.4);
            transform: translateY(-1px);
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
                    <a href="stats.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                        <i class="fas fa-tachometer-alt w-5"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="subscribers.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                        <i class="fas fa-users w-5"></i>
                        <span>Subscribers</span>
                    </a>
                    <a href="search-analytics.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                        <i class="fas fa-chart-line w-5"></i>
                        <span>Search Analytics</span>
                    </a>
                    <a href="page-views.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition bg-blue-500/20">
                        <i class="fas fa-eye w-5"></i>
                        <span>Page Views</span>
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
                <!-- Header -->
                <div class="flex justify-between items-center mb-8">
                    <div>
                        <h1 class="text-3xl font-bold">Page Views Analytics</h1>
                        <p class="text-gray-400 mt-1">Track user behavior, geography, devices, and engagement</p>
                    </div>
                    <button onclick="window.location.reload()" class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg transition">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
                
                <!-- Filter Bar -->
                <div class="filter-bar mb-8">
                    <form method="GET" class="flex flex-wrap gap-4 items-end">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Date From</label>
                            <input type="date" name="date_from" value="<?php echo $dateFrom; ?>" class="bg-slate-800 border border-blue-500/30 rounded-lg px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Date To</label>
                            <input type="date" name="date_to" value="<?php echo $dateTo; ?>" class="bg-slate-800 border border-blue-500/30 rounded-lg px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Country</label>
                            <select name="country" class="bg-slate-800 border border-blue-500/30 rounded-lg px-3 py-2 text-sm">
                                <option value="">All Countries</option>
                                <?php while($row = $countriesList->fetch_assoc()): ?>
                                <option value="<?php echo $row['country']; ?>" <?php echo $country == $row['country'] ? 'selected' : ''; ?>>
                                    <?php echo $row['country']; ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Device</label>
                            <select name="device" class="bg-slate-800 border border-blue-500/30 rounded-lg px-3 py-2 text-sm">
                                <option value="">All Devices</option>
                                <?php while($row = $devicesList->fetch_assoc()): ?>
                                <option value="<?php echo $row['device_type']; ?>" <?php echo $device == $row['device_type'] ? 'selected' : ''; ?>>
                                    <?php echo $row['device_type']; ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Browser</label>
                            <select name="browser" class="bg-slate-800 border border-blue-500/30 rounded-lg px-3 py-2 text-sm">
                                <option value="">All Browsers</option>
                                <?php while($row = $browsersList->fetch_assoc()): ?>
                                <option value="<?php echo $row['browser_name']; ?>" <?php echo $browser == $row['browser_name'] ? 'selected' : ''; ?>>
                                    <?php echo $row['browser_name']; ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="bg-green-600 hover:bg-green-700 px-4 py-2 rounded-lg transition">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="page-views.php" class="bg-gray-600 hover:bg-gray-700 px-4 py-2 rounded-lg transition ml-2">
                                <i class="fas fa-undo"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-gray-400 text-sm">Total Page Views</p>
                                <p class="text-3xl font-bold mt-2"><?php echo number_format($totalViews); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-blue-500/20 rounded-full flex items-center justify-center">
                                <i class="fas fa-eye text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-gray-400 text-sm">Unique Visitors</p>
                                <p class="text-3xl font-bold mt-2"><?php echo number_format($uniqueVisitors); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-green-500/20 rounded-full flex items-center justify-center">
                                <i class="fas fa-users text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-gray-400 text-sm">Avg. Views/Visitor</p>
                                <p class="text-3xl font-bold mt-2"><?php echo $avgViewsPerVisitor; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-purple-500/20 rounded-full flex items-center justify-center">
                                <i class="fas fa-chart-line text-purple-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-gray-400 text-sm">Bounce Rate</p>
                                <p class="text-3xl font-bold mt-2"><?php echo $bounceRate; ?>%</p>
                            </div>
                            <div class="w-12 h-12 bg-orange-500/20 rounded-full flex items-center justify-center">
                                <i class="fas fa-sign-out-alt text-orange-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card rounded-xl p-6">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-gray-400 text-sm">Pages/Visit</p>
                                <p class="text-3xl font-bold mt-2"><?php echo $avgViewsPerVisitor; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-pink-500/20 rounded-full flex items-center justify-center">
                                <i class="fas fa-file-alt text-pink-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Charts Row 1 - Daily Trends -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="chart-container">
                        <h3 class="text-lg font-semibold mb-4">Daily Page Views Trend</h3>
                        <canvas id="dailyViewsChart"></canvas>
                    </div>
                    
                    <div class="chart-container">
                        <h3 class="text-lg font-semibold mb-4">Hourly Traffic Distribution</h3>
                        <canvas id="hourlyChart"></canvas>
                    </div>
                </div>
                
                <!-- Charts Row 2 - Geography -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="chart-container">
                        <h3 class="text-lg font-semibold mb-4">Top Countries by Visitors</h3>
                        <canvas id="countriesChart"></canvas>
                    </div>
                    
                    <div class="chart-container">
                        <h3 class="text-lg font-semibold mb-4">Top Cities by Activity</h3>
                        <div class="overflow-y-auto max-h-96">
                            <table class="w-full text-sm">
                                <thead class="border-b border-gray-700">
                                    <tr><th class="text-left py-2">City</th><th class="text-left py-2">Country</th><th class="text-right py-2">Views</th><th class="text-right py-2">Visitors</th></tr>
                                </thead>
                                <tbody>
                                    <?php while($row = $topCities->fetch_assoc()): ?>
                                    <tr class="border-b border-gray-800">
                                        <td class="py-2"><?php echo $row['city']; ?></td>
                                        <td class="py-2"><?php echo $row['country']; ?></td>
                                        <td class="py-2 text-right"><?php echo number_format($row['views']); ?></td>
                                        <td class="py-2 text-right"><?php echo number_format($row['visitors']); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Charts Row 3 - Devices & Browsers -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="chart-container">
                        <h3 class="text-lg font-semibold mb-4">Device Distribution</h3>
                        <canvas id="deviceChart"></canvas>
                    </div>
                    
                    <div class="chart-container">
                        <h3 class="text-lg font-semibold mb-4">Browser Distribution</h3>
                        <canvas id="browserChart"></canvas>
                    </div>
                    
                    <div class="chart-container">
                        <h3 class="text-lg font-semibold mb-4">Operating Systems</h3>
                        <canvas id="osChart"></canvas>
                    </div>
                </div>
                
                <!-- ISP & Referrer Stats -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="chart-container">
                        <h3 class="text-lg font-semibold mb-4">Top ISPs</h3>
                        <div class="overflow-y-auto max-h-64">
                            <table class="w-full text-sm">
                                <thead class="border-b border-gray-700">
                                    <tr><th class="text-left py-2">ISP</th><th class="text-right py-2">Views</th><th class="text-right py-2">Visitors</th></tr>
                                </thead>
                                <tbody>
                                    <?php while($row = $ispStats->fetch_assoc()): ?>
                                    <tr class="border-b border-gray-800">
                                        <td class="py-2"><?php echo $row['isp']; ?></td>
                                        <td class="py-2 text-right"><?php echo number_format($row['views']); ?></td>
                                        <td class="py-2 text-right"><?php echo number_format($row['visitors']); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="chart-container">
                        <h3 class="text-lg font-semibold mb-4">Traffic Sources</h3>
                        <canvas id="referrerChart"></canvas>
                    </div>
                </div>
                
                <!-- Individual User Stats -->
                <div class="chart-container mb-8">
                    <h3 class="text-lg font-semibold mb-4">Top Users by Page Views</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-gray-700">
                                <tr>
                                    <th class="text-left py-3">IP Address</th>
                                    <th class="text-left py-3">Location</th>
                                    <th class="text-left py-3">Device</th>
                                    <th class="text-left py-3">Browser</th>
                                    <th class="text-center py-3">Page Views</th>
                                    <th class="text-left py-3">First Visit</th>
                                    <th class="text-left py-3">Last Visit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $userStats->fetch_assoc()): ?>
                                <tr class="border-b border-gray-800 hover:bg-slate-700/30">
                                    <td class="py-3 font-mono text-xs"><?php echo $row['ip_address']; ?></td>
                                    <td class="py-3">
                                        <?php echo $row['city'] ? $row['city'] . ', ' : ''; ?><?php echo $row['country'] ?: 'Unknown'; ?>
                                    </td>
                                    <td class="py-3"><?php echo $row['device_type'] ?: 'Unknown'; ?></td>
                                    <td class="py-3"><?php echo $row['browser_name'] ?: 'Unknown'; ?></td>
                                    <td class="py-3 text-center font-bold text-blue-400"><?php echo number_format($row['page_views']); ?></td>
                                    <td class="py-3"><?php echo date('M d, H:i', strtotime($row['first_visit'])); ?></td>
                                    <td class="py-3"><?php echo date('M d, H:i', strtotime($row['last_visit'])); ?></td>
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
        // Prepare data for charts
        const dailyData = <?php 
            $dates = [];
            $views = [];
            $visitors = [];
            while($row = $dailyStats->fetch_assoc()) {
                $dates[] = $row['date'];
                $views[] = $row['views'];
                $visitors[] = $row['visitors'];
            }
            echo json_encode(['dates' => $dates, 'views' => $views, 'visitors' => $visitors]);
        ?>;
        
        const hourlyData = <?php 
            $hours = [];
            $hourlyCounts = [];
            for($i = 0; $i < 24; $i++) {
                $hours[] = $i;
                $hourlyCounts[$i] = 0;
            }
            while($row = $hourlyStats->fetch_assoc()) {
                $hourlyCounts[$row['hour']] = $row['views'];
            }
            echo json_encode(['hours' => $hours, 'counts' => array_values($hourlyCounts)]);
        ?>;
        
        const countriesData = <?php 
            $countries = [];
            $countryVisitors = [];
            while($row = $topCountries->fetch_assoc()) {
                $countries[] = $row['country'];
                $countryVisitors[] = $row['visitors'];
            }
            echo json_encode(['countries' => $countries, 'visitors' => $countryVisitors]);
        ?>;
        
        const deviceData = <?php 
            $devices = [];
            $deviceViews = [];
            while($row = $deviceStats->fetch_assoc()) {
                $devices[] = $row['device_type'];
                $deviceViews[] = $row['views'];
            }
            echo json_encode(['devices' => $devices, 'views' => $deviceViews]);
        ?>;
        
        const browserData = <?php 
            $browsers = [];
            $browserViews = [];
            while($row = $browserStats->fetch_assoc()) {
                $browsers[] = $row['browser_name'];
                $browserViews[] = $row['views'];
            }
            echo json_encode(['browsers' => $browsers, 'views' => $browserViews]);
        ?>;
        
        const osData = <?php 
            $oses = [];
            $osViews = [];
            while($row = $osStats->fetch_assoc()) {
                $oses[] = $row['os_name'];
                $osViews[] = $row['views'];
            }
            echo json_encode(['oses' => $oses, 'views' => $osViews]);
        ?>;
        
        const referrerData = <?php 
            $sources = [];
            $sourceViews = [];
            while($row = $referrerStats->fetch_assoc()) {
                $sources[] = $row['source'];
                $sourceViews[] = $row['views'];
            }
            echo json_encode(['sources' => $sources, 'views' => $sourceViews]);
        ?>;
        
        // Daily Views Chart
        new Chart(document.getElementById('dailyViewsChart'), {
            type: 'line',
            data: {
                labels: dailyData.dates,
                datasets: [
                    {
                        label: 'Page Views',
                        data: dailyData.views,
                        borderColor: '#3B82F6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Unique Visitors',
                        data: dailyData.visitors,
                        borderColor: '#10B981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { labels: { color: '#fff' } } },
                scales: {
                    y: { ticks: { color: '#fff' }, grid: { color: '#374151' } },
                    x: { ticks: { color: '#fff', maxRotation: 45 }, grid: { color: '#374151' } }
                }
            }
        });
        
        // Hourly Chart
        new Chart(document.getElementById('hourlyChart'), {
            type: 'bar',
            data: {
                labels: hourlyData.hours,
                datasets: [{
                    label: 'Page Views',
                    data: hourlyData.counts,
                    backgroundColor: '#8B5CF6',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { 
                    legend: { labels: { color: '#fff' } },
                    tooltip: { callbacks: { title: (ctx) => `${ctx[0].label}:00 - ${parseInt(ctx[0].label)+1}:00` } }
                },
                scales: {
                    y: { ticks: { color: '#fff' }, grid: { color: '#374151' } },
                    x: { ticks: { color: '#fff' }, grid: { color: '#374151' } }
                }
            }
        });
        
        // Countries Chart
        new Chart(document.getElementById('countriesChart'), {
            type: 'bar',
            data: {
                labels: countriesData.countries,
                datasets: [{
                    label: 'Unique Visitors',
                    data: countriesData.visitors,
                    backgroundColor: '#10B981',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                indexAxis: 'y',
                plugins: { legend: { labels: { color: '#fff' } } },
                scales: {
                    y: { ticks: { color: '#fff' }, grid: { color: '#374151' } },
                    x: { ticks: { color: '#fff' }, grid: { color: '#374151' } }
                }
            }
        });
        
        // Device Chart
        new Chart(document.getElementById('deviceChart'), {
            type: 'doughnut',
            data: {
                labels: deviceData.devices,
                datasets: [{
                    data: deviceData.views,
                    backgroundColor: ['#3B82F6', '#10B981', '#8B5CF6', '#F59E0B'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { labels: { color: '#fff', position: 'bottom' } } }
            }
        });
        
        // Browser Chart
        new Chart(document.getElementById('browserChart'), {
            type: 'pie',
            data: {
                labels: browserData.browsers,
                datasets: [{
                    data: browserData.views,
                    backgroundColor: ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#06B6D4'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { labels: { color: '#fff', position: 'bottom' } } }
            }
        });
        
        // OS Chart
        new Chart(document.getElementById('osChart'), {
            type: 'pie',
            data: {
                labels: osData.oses,
                datasets: [{
                    data: osData.views,
                    backgroundColor: ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { labels: { color: '#fff', position: 'bottom' } } }
            }
        });
        
        // Referrer Chart
        new Chart(document.getElementById('referrerChart'), {
            type: 'bar',
            data: {
                labels: referrerData.sources,
                datasets: [{
                    label: 'Page Views',
                    data: referrerData.views,
                    backgroundColor: '#F59E0B',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { labels: { color: '#fff' } } },
                scales: {
                    y: { ticks: { color: '#fff' }, grid: { color: '#374151' } },
                    x: { ticks: { color: '#fff' }, grid: { color: '#374151' } }
                }
            }
        });
    </script>
</body>
</html>

<?php $conn->close(); ?>