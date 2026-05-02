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

// Get top searched domains
$topDomains = $conn->query("SELECT domain_name, COUNT(*) as count FROM domain_searches GROUP BY domain_name ORDER BY count DESC LIMIT 10");

// Get hourly search activity
$hourlyActivity = $conn->query("SELECT HOUR(searched_at) as hour, COUNT(*) as count FROM domain_searches WHERE searched_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY HOUR(searched_at) ORDER BY hour ASC");

// Prepare data for charts
$hourlyData = [];
for ($i = 0; $i < 24; $i++) {
    $hourlyData[$i] = 0;
}
while ($row = $hourlyActivity->fetch_assoc()) {
    $hourlyData[$row['hour']] = $row['count'];
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Search Analytics - checkdomain.top Admin</title>
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
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 1rem !important;
            }
            
            .chart-container {
                padding: 1rem;
            }
            
            .chart-container h2, .chart-container h3 {
                font-size: 1rem;
            }
            
            .stat-card {
                padding: 1rem !important;
            }
            
            .stat-card p:first-child {
                font-size: 0.7rem;
            }
            
            .stat-card .text-2xl {
                font-size: 1.25rem;
            }
            
            .p-8 {
                padding: 1rem;
            }
            
            table {
                font-size: 0.7rem;
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
        }
        
        /* Badge animations */
        .status-badge {
            transition: all 0.2s ease;
        }
        
        .status-badge:hover {
            transform: scale(1.05);
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
                    <h1 class="text-2xl md:text-3xl font-bold">Search Analytics</h1>
                    <p class="text-gray-400 text-sm mt-1">Track domain searches, popular TLDs, and user behavior</p>
                </div>
                <div class="flex gap-3">
                    <select id="periodSelect" class="bg-slate-800 border border-blue-500/30 rounded-lg px-4 py-2 text-sm w-full sm:w-auto">
                        <option value="7">Last 7 days</option>
                        <option value="30" selected>Last 30 days</option>
                        <option value="90">Last 90 days</option>
                        <option value="365">Last year</option>
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
                            <p class="text-gray-400 text-xs md:text-sm">Total Searches (30d)</p>
                            <p class="text-xl md:text-2xl font-bold mt-2"><?php echo number_format($stats['total_searches'] ?? 0); ?></p>
                            <p class="text-blue-400 text-xs mt-2">
                                <i class="fas fa-search"></i> Domain queries
                            </p>
                        </div>
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-blue-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-search text-blue-400 text-lg md:text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card rounded-xl p-4 md:p-6">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs md:text-sm">Available Domains Found</p>
                            <p class="text-xl md:text-2xl font-bold mt-2 text-green-400"><?php echo number_format($stats['available'] ?? 0); ?></p>
                            <p class="text-green-400 text-xs mt-2">
                                <i class="fas fa-check-circle"></i> Ready to register
                            </p>
                        </div>
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-green-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-check-circle text-green-400 text-lg md:text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card rounded-xl p-4 md:p-6">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs md:text-sm">Taken Domains</p>
                            <p class="text-xl md:text-2xl font-bold mt-2 text-red-400"><?php echo number_format($stats['taken'] ?? 0); ?></p>
                            <p class="text-red-400 text-xs mt-2">
                                <i class="fas fa-lock"></i> Already registered
                            </p>
                        </div>
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-red-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-lock text-red-400 text-lg md:text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card rounded-xl p-4 md:p-6">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs md:text-sm">Availability Rate</p>
                            <p class="text-xl md:text-2xl font-bold mt-2 text-blue-400">
                                <?php 
                                $total = ($stats['available'] ?? 0) + ($stats['taken'] ?? 0);
                                $rate = $total > 0 ? round(($stats['available'] ?? 0) / $total * 100) : 0;
                                echo $rate . '%';
                                ?>
                            </p>
                            <p class="text-gray-400 text-xs mt-2">
                                <i class="fas fa-chart-line"></i> Of total searches
                            </p>
                        </div>
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-purple-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-chart-pie text-purple-400 text-lg md:text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Charts Row -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 md:gap-6 mb-8">
                <!-- Top TLDs Chart -->
                <div class="chart-container">
                    <h3 class="text-base md:text-lg font-semibold mb-4 flex items-center gap-2">
                        <i class="fas fa-globe text-blue-400"></i>
                        Most Searched TLDs
                    </h3>
                    <div class="overflow-x-auto">
                        <?php 
                        $total = 0;
                        $tldsData = [];
                        $topTLDs->data_seek(0);
                        while($row = $topTLDs->fetch_assoc()) {
                            $tldsData[] = $row;
                            $total += $row['count'];
                        }
                        ?>
                        <table class="w-full text-xs md:text-sm">
                            <thead class="border-b border-gray-700">
                                <tr>
                                    <th class="text-left py-2 md:py-3">TLD</th>
                                    <th class="text-left py-2 md:py-3">Search Count</th>
                                    <th class="text-left py-2 md:py-3">Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($tldsData as $row): 
                                $percent = $total > 0 ? round($row['count'] / $total * 100, 1) : 0;
                                ?>
                                <tr class="border-b border-gray-800 hover:bg-slate-700/30 transition">
                                    <td class="py-2 md:py-3 font-mono font-semibold text-blue-300">.<?php echo $row['tld']; ?></td>
                                    <td class="py-2 md:py-3"><?php echo number_format($row['count']); ?></td>
                                    <td class="py-2 md:py-3">
                                        <div class="flex items-center gap-2">
                                            <div class="flex-1 h-2 bg-gray-700 rounded-full overflow-hidden">
                                                <div class="h-full bg-gradient-to-r from-blue-500 to-green-500 rounded-full transition-all duration-500" style="width: <?php echo $percent; ?>%"></div>
                                            </div>
                                            <span class="text-xs font-mono"><?php echo $percent; ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                         </table>
                    </div>
                </div>
                
                <!-- Top Domains Chart -->
                <div class="chart-container">
                    <h3 class="text-base md:text-lg font-semibold mb-4 flex items-center gap-2">
                        <i class="fas fa-chart-bar text-green-400"></i>
                        Most Searched Domains
                    </h3>
                    <div class="overflow-x-auto">
                        <?php
                        $topDomainsList = [];
                        while($row = $topDomains->fetch_assoc()) {
                            $topDomainsList[] = $row;
                        }
                        ?>
                        <table class="w-full text-xs md:text-sm">
                            <thead class="border-b border-gray-700">
                                <tr>
                                    <th class="text-left py-2 md:py-3">Domain Name</th>
                                    <th class="text-left py-2 md:py-3">Search Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($topDomainsList as $row): ?>
                                <tr class="border-b border-gray-800 hover:bg-slate-700/30 transition">
                                    <td class="py-2 md:py-3 font-mono"><?php echo $row['domain_name']; ?></td>
                                    <td class="py-2 md:py-3">
                                        <div class="flex items-center gap-2">
                                            <span class="font-bold text-green-400"><?php echo number_format($row['count']); ?></span>
                                            <span class="text-gray-500 text-xs">searches</span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                         </table>
                    </div>
                </div>
            </div>
            
            <!-- Hourly Activity Chart -->
            <div class="chart-container mb-8">
                <h3 class="text-base md:text-lg font-semibold mb-4 flex items-center gap-2">
                    <i class="fas fa-clock text-yellow-400"></i>
                    24-Hour Search Activity
                </h3>
                <canvas id="hourlyChart" style="max-height: 300px;"></canvas>
            </div>
            
            <!-- Recent Searches -->
            <div class="chart-container">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
                    <h3 class="text-base md:text-lg font-semibold flex items-center gap-2">
                        <i class="fas fa-history text-purple-400"></i>
                        Recent Domain Searches
                    </h3>
                    <span class="text-xs text-gray-500">
                        <i class="fas fa-database"></i> Last 50 searches
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs md:text-sm">
                        <thead class="border-b border-gray-700">
                            <tr>
                                <th class="text-left py-2 md:py-3">Domain</th>
                                <th class="text-left py-2 md:py-3">TLD</th>
                                <th class="text-left py-2 md:py-3">Status</th>
                                <th class="text-left py-2 md:py-3 hidden sm:table-cell">Time</th>
                                <th class="text-left py-2 md:py-3 hidden lg:table-cell">IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $recentSearches->data_seek(0);
                            while($row = $recentSearches->fetch_assoc()): 
                            ?>
                            <tr class="border-b border-gray-800 hover:bg-slate-700/30 transition">
                                <td class="py-2 md:py-3 font-mono">
                                    <?php 
                                    $domain = $row['domain_name'];
                                    echo strlen($domain) > 30 ? substr($domain, 0, 27) . '...' : $domain;
                                    ?>
                                </td>
                                <td class="py-2 md:py-3 font-mono text-blue-300">.<?php echo $row['tld']; ?></td>
                                <td class="py-2 md:py-3">
                                    <span class="status-badge px-2 py-1 rounded-full text-xs <?php echo $row['is_available'] ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'; ?>">
                                        <i class="fas <?php echo $row['is_available'] ? 'fa-check-circle' : 'fa-times-circle'; ?> mr-1"></i>
                                        <?php echo $row['is_available'] ? 'Available' : 'Taken'; ?>
                                    </span>
                                 </td>
                                <td class="py-2 md:py-3 hidden sm:table-cell whitespace-nowrap">
                                    <?php echo date('M d, H:i', strtotime($row['searched_at'])); ?>
                                </td>
                                <td class="py-2 md:py-3 hidden lg:table-cell font-mono text-xs">
                                    <?php echo $row['ip_address']; ?>
                                </td>
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
            // This would trigger a reload with new period parameter
            window.location.href = '?period=' + this.value;
        });
        
        // Hourly Activity Chart
        const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
        const hourlyData = <?php echo json_encode(array_values($hourlyData)); ?>;
        const hours = <?php echo json_encode(array_keys($hourlyData)); ?>;
        
        new Chart(hourlyCtx, {
            type: 'line',
            data: {
                labels: hours,
                datasets: [{
                    label: 'Searches',
                    data: hourlyData,
                    borderColor: '#10B981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#10B981',
                    pointBorderColor: '#fff',
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBorderWidth: 2
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
                                return `${context[0].label}:00 - ${parseInt(context[0].label) + 1}:00`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: { color: '#fff', stepSize: 1, font: { size: 10 } },
                        grid: { color: '#374151' },
                        title: {
                            display: true,
                            text: 'Number of Searches',
                            color: '#9CA3AF',
                            font: { size: 10 }
                        }
                    },
                    x: {
                        ticks: { color: '#fff', font: { size: 10 } },
                        grid: { color: '#374151' },
                        title: {
                            display: true,
                            text: 'Hour of Day (24h format)',
                            color: '#9CA3AF',
                            font: { size: 10 }
                        }
                    }
                }
            }
        });
        
        // Animate percentage bars on load
        document.addEventListener('DOMContentLoaded', function() {
            const bars = document.querySelectorAll('.h-full');
            bars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });
        });
    </script>
</body>
</html>