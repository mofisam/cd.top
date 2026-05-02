<?php
require_once 'auth_check.php';
$user = checkAdminAuth();
require_once '../config/database.php';

$conn = getDBConnection();

// Filter parameters
$action_filter = $_GET['action'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Pagination
$page = $_GET['page'] ?? 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$whereConditions = [];
$params = [];
$types = "";

if ($action_filter) {
    $whereConditions[] = "a.action LIKE ?";
    $params[] = "%$action_filter%";
    $types .= "s";
}
if ($date_from) {
    $whereConditions[] = "DATE(a.created_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}
if ($date_to) {
    $whereConditions[] = "DATE(a.created_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}
if ($search) {
    $whereConditions[] = "(a.details LIKE ? OR u.username LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get total count with filters
$countSql = "SELECT COUNT(*) as count FROM admin_activity_log a LEFT JOIN admin_users u ON a.user_id = u.id $whereClause";
$stmt = $conn->prepare($countSql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['count'];
$stmt->close();
$totalPages = ceil($total / $limit);

// Get activities with filters
$sql = "SELECT a.*, u.username 
        FROM admin_activity_log a 
        LEFT JOIN admin_users u ON a.user_id = u.id 
        $whereClause
        ORDER BY a.created_at DESC 
        LIMIT $offset, $limit";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$activities = $stmt->get_result();
$stmt->close();

// Get unique actions for filter dropdown
$actions = $conn->query("SELECT DISTINCT action FROM admin_activity_log ORDER BY action");

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Activity Log - checkdomain.top Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
        
        .main-content {
            transition: margin-left 0.3s ease;
        }
        
        /* Filter bar styles */
        .filter-section {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 1rem;
            padding: 1rem;
        }
        
        /* Badge styles */
        .badge-login { background: rgba(16, 185, 129, 0.2); color: #10B981; }
        .badge-logout { background: rgba(245, 158, 11, 0.2); color: #F59E0B; }
        .badge-delete { background: rgba(239, 68, 68, 0.2); color: #EF4444; }
        .badge-create { background: rgba(59, 130, 246, 0.2); color: #3B82F6; }
        .badge-update { background: rgba(139, 92, 246, 0.2); color: #8B5CF6; }
        .badge-default { background: rgba(107, 114, 128, 0.2); color: #9CA3AF; }
        
        /* Responsive tables */
        @media (max-width: 768px) {
            .filter-section .flex {
                flex-direction: column;
                gap: 0.75rem;
            }
            
            .filter-section select,
            .filter-section input,
            .filter-section button {
                width: 100%;
            }
            
            .chart-container {
                padding: 1rem;
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
            
            .action-badge {
                font-size: 0.6rem;
                padding: 0.2rem 0.4rem;
            }
        }
        
        @media (max-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 0.75rem;
            }
            
            .flex.justify-between {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch !important;
            }
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
        
        /* Loading animation */
        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(59, 130, 246, 0.3);
            border-radius: 50%;
            border-top-color: #3B82F6;
            animation: spin 0.6s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Hover effects */
        .table-row-hover:hover {
            background: rgba(59, 130, 246, 0.1);
        }
        
        /* Expandable row for mobile */
        .expandable-row {
            cursor: pointer;
        }
        
        .expanded-details {
            display: none;
            background: rgba(15, 23, 42, 0.8);
            padding: 1rem;
            border-radius: 0.5rem;
            margin-top: 0.5rem;
        }
        
        .expanded-details.show {
            display: block;
        }
        
        @media (max-width: 768px) {
            .hide-mobile {
                display: none;
            }
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
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                <div>
                    <h1 class="text-2xl md:text-3xl font-bold">Admin Activity Log</h1>
                    <p class="text-gray-400 text-sm mt-1">Complete history of admin actions and system events</p>
                </div>
                <button onclick="window.location.reload()" class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg transition w-full sm:w-auto">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
            
            <!-- Stats Summary Cards -->
            <div class="stats-grid grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4 mb-6">
                <div class="stat-card rounded-xl p-3 md:p-4">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs">Total Actions</p>
                            <p class="text-xl md:text-2xl font-bold mt-1"><?php echo number_format($total); ?></p>
                        </div>
                        <div class="w-8 h-8 md:w-10 md:h-10 bg-blue-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-list text-blue-400 text-sm md:text-base"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card rounded-xl p-3 md:p-4">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs">Total Pages</p>
                            <p class="text-xl md:text-2xl font-bold mt-1"><?php echo number_format($totalPages); ?></p>
                        </div>
                        <div class="w-8 h-8 md:w-10 md:h-10 bg-green-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-file-alt text-green-400 text-sm md:text-base"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card rounded-xl p-3 md:p-4">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs">Items/Page</p>
                            <p class="text-xl md:text-2xl font-bold mt-1"><?php echo $limit; ?></p>
                        </div>
                        <div class="w-8 h-8 md:w-10 md:h-10 bg-purple-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-table text-purple-400 text-sm md:text-base"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card rounded-xl p-3 md:p-4">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs">Current Page</p>
                            <p class="text-xl md:text-2xl font-bold mt-1"><?php echo $page; ?></p>
                        </div>
                        <div class="w-8 h-8 md:w-10 md:h-10 bg-orange-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-arrow-right text-orange-400 text-sm md:text-base"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-section mb-6">
                <form method="GET" action="" class="flex flex-col gap-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Action Type</label>
                            <select name="action" class="w-full bg-slate-800 border border-blue-500/30 rounded-lg px-3 py-2 text-sm">
                                <option value="">All Actions</option>
                                <?php while($action = $actions->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($action['action']); ?>" <?php echo $action_filter == $action['action'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($action['action']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Date From</label>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" 
                                class="w-full bg-slate-800 border border-blue-500/30 rounded-lg px-3 py-2 text-sm">
                        </div>
                        
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Date To</label>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" 
                                class="w-full bg-slate-800 border border-blue-500/30 rounded-lg px-3 py-2 text-sm">
                        </div>
                        
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Search</label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                placeholder="Search by details or admin..." 
                                class="w-full bg-slate-800 border border-blue-500/30 rounded-lg px-3 py-2 text-sm">
                        </div>
                    </div>
                    
                    <div class="flex gap-3">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg transition text-sm">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="activity.php" class="bg-gray-600 hover:bg-gray-700 px-4 py-2 rounded-lg transition text-sm">
                            <i class="fas fa-undo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Activity Table -->
            <div class="chart-container p-0 md:p-6 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-slate-700/50 border-b border-gray-700">
                            <tr>
                                <th class="text-left p-3 md:p-4 text-xs md:text-sm">Admin</th>
                                <th class="text-left p-3 md:p-4 text-xs md:text-sm">Action</th>
                                <th class="text-left p-3 md:p-4 text-xs md:text-sm hide-mobile">Details</th>
                                <th class="text-left p-3 md:p-4 text-xs md:text-sm">Time</th>
                                <th class="text-left p-3 md:p-4 text-xs md:text-sm hide-mobile">IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($activities->num_rows > 0): ?>
                                <?php while($row = $activities->fetch_assoc()): ?>
                                <tr class="border-b border-gray-800 hover:bg-slate-700/30 transition table-row-hover">
                                    <td class="p-3 md:p-4">
                                        <div class="flex items-center gap-2">
                                            <div class="w-6 h-6 md:w-8 md:h-8 bg-gradient-to-br from-blue-500 to-green-500 rounded-full flex items-center justify-center">
                                                <span class="text-xs font-bold"><?php echo strtoupper(substr($row['username'] ?? 'S', 0, 1)); ?></span>
                                            </div>
                                            <span class="font-semibold text-sm"><?php echo htmlspecialchars($row['username'] ?? 'System'); ?></span>
                                        </div>
                                    </td>
                                    <td class="p-3 md:p-4">
                                        <?php
                                        $badgeClass = 'badge-default';
                                        if (strpos($row['action'], 'LOGIN') !== false) $badgeClass = 'badge-login';
                                        elseif (strpos($row['action'], 'LOGOUT') !== false) $badgeClass = 'badge-logout';
                                        elseif (strpos($row['action'], 'DELETE') !== false) $badgeClass = 'badge-delete';
                                        elseif (strpos($row['action'], 'CREATE') !== false) $badgeClass = 'badge-create';
                                        elseif (strpos($row['action'], 'UPDATE') !== false || strpos($row['action'], 'EDIT') !== false) $badgeClass = 'badge-update';
                                        ?>
                                        <span class="px-2 py-1 rounded-full text-xs whitespace-nowrap action-badge <?php echo $badgeClass; ?>">
                                            <i class="fas fa-<?php 
                                                echo strpos($row['action'], 'LOGIN') !== false ? 'sign-in-alt' : 
                                                    (strpos($row['action'], 'LOGOUT') !== false ? 'sign-out-alt' :
                                                    (strpos($row['action'], 'DELETE') !== false ? 'trash' :
                                                    (strpos($row['action'], 'CREATE') !== false ? 'plus' :
                                                    (strpos($row['action'], 'UPDATE') !== false ? 'edit' : 'circle')))); 
                                            ?> text-xs mr-1"></i>
                                            <?php echo htmlspecialchars($row['action']); ?>
                                        </span>
                                    </td>
                                    <td class="p-3 md:p-4 hide-mobile">
                                        <span class="text-sm text-gray-300"><?php echo htmlspecialchars(substr($row['details'] ?? '—', 0, 50)); ?></span>
                                    </td>
                                    <td class="p-3 md:p-4 whitespace-nowrap">
                                        <div class="text-xs">
                                            <div><?php echo date('M d, Y', strtotime($row['created_at'])); ?></div>
                                            <div class="text-gray-400 text-xs"><?php echo date('H:i:s', strtotime($row['created_at'])); ?></div>
                                        </div>
                                    </td>
                                    <td class="p-3 md:p-4 hide-mobile">
                                        <code class="text-xs bg-black/30 px-2 py-1 rounded"><?php echo $row['ip_address']; ?></code>
                                    </td>
                                </tr>
                                <!-- Mobile expanded details row -->
                                <tr class="md:hidden border-b border-gray-800">
                                    <td colspan="4" class="p-2">
                                        <div class="text-xs space-y-1 text-gray-400">
                                            <div><strong>Details:</strong> <?php echo htmlspecialchars(substr($row['details'] ?? '—', 0, 100)); ?></div>
                                            <div><strong>IP:</strong> <?php echo $row['ip_address']; ?></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-8">
                                        <div class="flex flex-col items-center gap-2">
                                            <i class="fas fa-inbox text-4xl text-gray-600"></i>
                                            <p class="text-gray-400">No activity logs found</p>
                                            <p class="text-xs text-gray-500">Try adjusting your filters</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if($totalPages > 1): ?>
                <div class="flex flex-col sm:flex-row justify-between items-center gap-4 p-4 border-t border-gray-700 mt-4">
                    <div class="text-xs text-gray-400">
                        Showing <?php echo min($offset + 1, $total); ?> to <?php echo min($offset + $limit, $total); ?> of <?php echo number_format($total); ?> entries
                    </div>
                    <div class="flex flex-wrap justify-center gap-2">
                        <?php if($page > 1): ?>
                        <a href="?page=<?php echo $page-1; ?><?php echo $action_filter ? '&action='.urlencode($action_filter) : ''; ?><?php echo $date_from ? '&date_from='.urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to='.urlencode($date_to) : ''; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" 
                           class="px-3 py-1 rounded bg-slate-700 hover:bg-slate-600 transition text-sm">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                        <?php endif; ?>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        if($startPage > 1): ?>
                            <a href="?page=1<?php echo $action_filter ? '&action='.urlencode($action_filter) : ''; ?><?php echo $date_from ? '&date_from='.urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to='.urlencode($date_to) : ''; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" 
                               class="px-3 py-1 rounded bg-slate-700 hover:bg-slate-600 transition text-sm">1</a>
                            <?php if($startPage > 2): ?><span class="px-2">...</span><?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for($i = $startPage; $i <= $endPage; $i++): ?>
                        <a href="?page=<?php echo $i; ?><?php echo $action_filter ? '&action='.urlencode($action_filter) : ''; ?><?php echo $date_from ? '&date_from='.urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to='.urlencode($date_to) : ''; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" 
                           class="px-3 py-1 rounded transition text-sm <?php echo $i == $page ? 'bg-blue-600' : 'bg-slate-700 hover:bg-slate-600'; ?>">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>
                        
                        <?php if($endPage < $totalPages): ?>
                            <?php if($endPage < $totalPages - 1): ?><span class="px-2">...</span><?php endif; ?>
                            <a href="?page=<?php echo $totalPages; ?><?php echo $action_filter ? '&action='.urlencode($action_filter) : ''; ?><?php echo $date_from ? '&date_from='.urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to='.urlencode($date_to) : ''; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" 
                               class="px-3 py-1 rounded bg-slate-700 hover:bg-slate-600 transition text-sm"><?php echo $totalPages; ?></a>
                        <?php endif; ?>
                        
                        <?php if($page < $totalPages): ?>
                        <a href="?page=<?php echo $page+1; ?><?php echo $action_filter ? '&action='.urlencode($action_filter) : ''; ?><?php echo $date_from ? '&date_from='.urlencode($date_from) : ''; ?><?php echo $date_to ? '&date_to='.urlencode($date_to) : ''; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" 
                           class="px-3 py-1 rounded bg-slate-700 hover:bg-slate-600 transition text-sm">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Export Section -->
            <div class="mt-6 flex justify-end">
                <button onclick="exportToCSV()" class="bg-green-600 hover:bg-green-700 px-4 py-2 rounded-lg transition text-sm flex items-center gap-2">
                    <i class="fas fa-download"></i> Export to CSV
                </button>
            </div>
        </div>
    </div>
    
    <script>
        // Export to CSV function
        function exportToCSV() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            window.location.href = 'export-activity.php?' + params.toString();
        }
        
        // Auto-refresh option (optional)
        let autoRefresh = false;
        let refreshInterval;
        
        function toggleAutoRefresh() {
            autoRefresh = !autoRefresh;
            if (autoRefresh) {
                refreshInterval = setInterval(() => {
                    window.location.reload();
                }, 30000); // Refresh every 30 seconds
                showToast('Auto-refresh enabled (30s interval)', false);
            } else {
                clearInterval(refreshInterval);
                showToast('Auto-refresh disabled', false);
            }
        }
        
        function showToast(message, isError) {
            // Create toast element if not exists
            let toast = document.getElementById('toastMsg');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'toastMsg';
                toast.className = 'fixed bottom-6 left-1/2 transform -translate-x-1/2 z-50 bg-slate-900/95 backdrop-blur-lg border border-blue-500 rounded-full px-5 py-2.5 text-sm font-medium text-white transition-all duration-300 opacity-0 pointer-events-none flex items-center gap-2 shadow-xl';
                document.body.appendChild(toast);
                toast.innerHTML = '<i class="fas fa-circle-info text-green-400"></i> <span id="toastText">Message</span>';
            }
            
            const toastSpan = document.getElementById('toastText');
            toastSpan.innerText = message;
            toast.classList.remove('opacity-0');
            toast.classList.add('opacity-100', 'pointer-events-auto');
            setTimeout(() => {
                toast.classList.remove('opacity-100', 'pointer-events-auto');
                toast.classList.add('opacity-0');
            }, 3000);
        }
        
        // Add keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl + R to refresh
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                window.location.reload();
            }
            // Ctrl + F to focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]')?.focus();
            }
        });
    </script>
</body>
</html>