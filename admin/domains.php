<?php
require_once 'auth_check.php';
$user = checkAdminAuth();
require_once '../config/database.php';

$conn = getDBConnection();

// Handle status update
if (isset($_POST['update_status']) && isset($_POST['id']) && isset($_POST['status'])) {
    $id = $_POST['id'];
    $status = $_POST['status'];
    $stmt = $conn->prepare("UPDATE pinned_domains SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $id);
    $stmt->execute();
    $stmt->close();
    logAdminActivity($user['id'], 'UPDATE_PIN_STATUS', "Updated pinned domain ID: $id to status: $status");
    header('Location: domains.php');
    exit();
}

// Handle delete
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM pinned_domains WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    logAdminActivity($user['id'], 'DELETE_PINNED_DOMAIN', "Deleted pinned domain ID: $id");
    header('Location: domains.php');
    exit();
}

// Search functionality
$search = $_GET['search'] ?? '';
$whereClause = "";
if (!empty($search)) {
    $whereClause = "WHERE pd.domain_name LIKE '%$search%' OR s.email LIKE '%$search%'";
}

// Pagination
$page = $_GET['page'] ?? 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$countQuery = "SELECT COUNT(*) as count FROM pinned_domains pd LEFT JOIN subscribers s ON pd.email = s.email $whereClause";
$total = $conn->query($countQuery)->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

$pinnedDomains = $conn->query("
    SELECT pd.*, s.email, s.name 
    FROM pinned_domains pd 
    LEFT JOIN subscribers s ON pd.email = s.email 
    $whereClause
    ORDER BY pd.pinned_at DESC 
    LIMIT $offset, $limit
");

// Get statistics
$stats = $conn->query("
    SELECT 
        COUNT(*) as total_pins,
        COUNT(DISTINCT email) as unique_users,
        COUNT(DISTINCT domain_name) as unique_domains
    FROM pinned_domains 
    WHERE status = 'active'
")->fetch_assoc();

$topDomains = $conn->query("
    SELECT domain_name, COUNT(*) as pin_count 
    FROM pinned_domains 
    WHERE status = 'active'
    GROUP BY domain_name 
    ORDER BY pin_count DESC 
    LIMIT 5
");
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Pinned Domains - checkdomain.top Admin</title>
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
        
        .main-content {
            transition: margin-left 0.3s ease;
        }
        
        /* Responsive styles */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 1rem !important;
            }
            
            .stat-card {
                padding: 1rem !important;
            }
            
            .stat-card .text-2xl {
                font-size: 1.25rem;
            }
            
            .p-8 {
                padding: 1rem;
            }
            
            table {
                font-size: 0.75rem;
            }
            
            table td, table th {
                padding: 0.75rem 0.5rem !important;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .action-buttons a, .action-buttons button {
                width: 100%;
                text-align: center;
            }
        }
        
        @media (max-width: 640px) {
            .stats-grid {
                grid-template-columns: 1fr !important;
            }
            
            .flex.justify-between {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch !important;
            }
            
            .search-form {
                flex-direction: column;
            }
            
            .search-form input, .search-form button {
                width: 100%;
            }
        }
        
        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .status-active {
            background: rgba(16, 185, 129, 0.2);
            color: #10B981;
        }
        
        .status-notified {
            background: rgba(59, 130, 246, 0.2);
            color: #3B82F6;
        }
        
        .status-expired {
            background: rgba(239, 68, 68, 0.2);
            color: #EF4444;
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
        
        /* Table hover effect */
        .data-table tbody tr:hover {
            background: rgba(59, 130, 246, 0.1);
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
                    <h1 class="text-2xl md:text-3xl font-bold">Pinned Domains</h1>
                    <p class="text-gray-400 text-sm mt-1">Manage domains that users want to be notified about</p>
                </div>
                <button onclick="window.location.reload()" class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg transition w-full sm:w-auto">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
            
            <!-- Stats Cards -->
            <div class="stats-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6 mb-8">
                <div class="stat-card rounded-xl p-4 md:p-6">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs md:text-sm">Total Pins</p>
                            <p class="text-2xl md:text-3xl font-bold mt-2"><?php echo number_format($stats['total_pins']); ?></p>
                        </div>
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-blue-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-thumbtack text-blue-400 text-lg md:text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card rounded-xl p-4 md:p-6">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs md:text-sm">Unique Users</p>
                            <p class="text-2xl md:text-3xl font-bold mt-2"><?php echo number_format($stats['unique_users']); ?></p>
                        </div>
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-green-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-users text-green-400 text-lg md:text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card rounded-xl p-4 md:p-6">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs md:text-sm">Unique Domains</p>
                            <p class="text-2xl md:text-3xl font-bold mt-2"><?php echo number_format($stats['unique_domains']); ?></p>
                        </div>
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-purple-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-globe text-purple-400 text-lg md:text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Search Bar -->
            <div class="mb-6">
                <form method="GET" class="search-form flex flex-col sm:flex-row gap-3">
                    <div class="flex-1">
                        <input type="text" name="search" placeholder="Search by domain or email..." 
                               value="<?php echo htmlspecialchars($search); ?>"
                               class="w-full bg-slate-800 border border-blue-500/30 rounded-lg py-2 px-4 text-white placeholder:text-gray-400 focus:outline-none focus:border-blue-500">
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg transition">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <?php if (!empty($search)): ?>
                        <a href="domains.php" class="bg-gray-600 hover:bg-gray-700 px-4 py-2 rounded-lg transition">
                            <i class="fas fa-times"></i> Clear
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <!-- Top Domains Section -->
            <?php if ($topDomains->num_rows > 0): ?>
            <div class="chart-container bg-slate-800/50 rounded-xl p-4 md:p-6 mb-8">
                <h3 class="text-base md:text-lg font-semibold mb-4">Most Pinned Domains</h3>
                <div class="space-y-3">
                    <?php while($row = $topDomains->fetch_assoc()): ?>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span class="font-mono"><?php echo htmlspecialchars($row['domain_name']); ?></span>
                            <span class="text-blue-400"><?php echo $row['pin_count']; ?> pins</span>
                        </div>
                        <div class="w-full bg-slate-700 rounded-full h-2">
                            <?php 
                            $maxPins = $topDomains->num_rows > 0 ? $row['pin_count'] : 1;
                            $percentage = ($row['pin_count'] / max($stats['total_pins'], 1)) * 100;
                            ?>
                            <div class="bg-gradient-to-r from-blue-500 to-green-500 h-2 rounded-full" style="width: <?php echo $percentage; ?>%"></div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Pinned Domains Table -->
            <div class="bg-slate-800/50 rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="data-table w-full">
                        <thead class="bg-slate-700/50 border-b border-gray-700">
                            <tr>
                                <th class="text-left p-3 md:p-4">Domain</th>
                                <th class="text-left p-3 md:p-4">Subscriber</th>
                                <th class="text-left p-3 md:p-4 hidden md:table-cell">Name</th>
                                <th class="text-left p-3 md:p-4">Pinned Date</th>
                                <th class="text-left p-3 md:p-4">Status</th>
                                <th class="text-left p-3 md:p-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($pinnedDomains->num_rows > 0): ?>
                                <?php while($row = $pinnedDomains->fetch_assoc()): ?>
                                <tr class="border-b border-gray-700 hover:bg-slate-700/30 transition">
                                    <td class="p-3 md:p-4">
                                        <span class="font-mono text-sm"><?php echo htmlspecialchars($row['domain_name']); ?></span>
                                    </td>
                                    <td class="p-3 md:p-4">
                                        <span class="text-sm"><?php echo htmlspecialchars($row['email']); ?></span>
                                    </td>
                                    <td class="p-3 md:p-4 hidden md:table-cell">
                                        <span class="text-sm"><?php echo htmlspecialchars($row['name'] ?: '—'); ?></span>
                                    </td>
                                    <td class="p-3 md:p-4 whitespace-nowrap">
                                        <span class="text-sm"><?php echo date('M d, Y', strtotime($row['pinned_at'])); ?></span>
                                        <span class="text-xs text-gray-400 block"><?php echo date('H:i', strtotime($row['pinned_at'])); ?></span>
                                    </td>
                                    <td class="p-3 md:p-4">
                                        <span class="status-badge status-<?php echo $row['status']; ?>">
                                            <i class="fas fa-<?php echo $row['status'] == 'active' ? 'clock' : ($row['status'] == 'notified' ? 'check-circle' : 'times-circle'); ?> text-xs"></i>
                                            <?php echo ucfirst($row['status']); ?>
                                        </span>
                                    </td>
                                    <td class="p-3 md:p-4">
                                        <div class="action-buttons flex flex-wrap gap-2">
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                <select name="status" onchange="this.form.submit()" class="bg-slate-700 border border-gray-600 rounded-lg text-xs px-2 py-1">
                                                    <option value="active" <?php echo $row['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                                    <option value="notified" <?php echo $row['status'] == 'notified' ? 'selected' : ''; ?>>Notified</option>
                                                    <option value="expired" <?php echo $row['status'] == 'expired' ? 'selected' : ''; ?>>Expired</option>
                                                </select>
                                                <input type="hidden" name="update_status" value="1">
                                            </form>
                                            <a href="?delete=<?php echo $row['id']; ?>" 
                                               onclick="return confirm('Delete this pinned domain?')"
                                               class="text-red-400 hover:text-red-300 transition">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </td>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-12">
                                        <i class="fas fa-inbox text-5xl text-gray-600 mb-4"></i>
                                        <p class="text-gray-400">No pinned domains found</p>
                                        <?php if (!empty($search)): ?>
                                        <p class="text-sm text-gray-500 mt-2">Try a different search term</p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if($totalPages > 1): ?>
                <div class="flex justify-center gap-2 p-4 flex-wrap">
                    <?php if($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                       class="px-3 py-1 rounded bg-slate-700 hover:bg-slate-600 transition">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <?php endif; ?>
                    
                    <?php 
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for($i = $startPage; $i <= $endPage; $i++): 
                    ?>
                    <a href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                       class="px-3 py-1 rounded <?php echo $i == $page ? 'bg-blue-600' : 'bg-slate-700 hover:bg-slate-600'; ?> transition">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                    
                    <?php if($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                       class="px-3 py-1 rounded bg-slate-700 hover:bg-slate-600 transition">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Export Section -->
            <div class="mt-6 text-center">
                <button onclick="exportToCSV()" class="bg-green-600 hover:bg-green-700 px-4 py-2 rounded-lg transition">
                    <i class="fas fa-download"></i> Export to CSV
                </button>
            </div>
        </div>
    </div>
    
    <script>
        // Export to CSV function
        function exportToCSV() {
            window.location.href = 'export-pinned-domains.php<?php echo !empty($search) ? '?search=' . urlencode($search) : ''; ?>';
        }
        
        // Status update confirmation
        document.querySelectorAll('select[name="status"]').forEach(select => {
            select.addEventListener('change', function() {
                if (confirm('Change status of this pinned domain?')) {
                    this.form.submit();
                } else {
                    this.value = this.getAttribute('data-original-value');
                }
            });
            select.setAttribute('data-original-value', select.value);
        });
    </script>
</body>
</html>

<?php  ?>