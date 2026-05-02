<?php
require_once 'auth_check.php';
$user = checkAdminAuth();
require_once '../config/database.php';

$conn = getDBConnection();

// Handle delete request
if (isset($_POST['delete']) && isset($_POST['email'])) {
    $email = $_POST['email'];
    $stmt = $conn->prepare("UPDATE subscribers SET status = 'unsubscribed' WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    logAdminActivity($user['id'], 'DELETE_SUBSCRIBER', "Deleted subscriber: $email");
    header('Location: subscribers.php');
    exit();
}

// Handle bulk delete
if (isset($_POST['bulk_delete']) && isset($_POST['selected_emails'])) {
    $emails = $_POST['selected_emails'];
    $placeholders = implode(',', array_fill(0, count($emails), '?'));
    $types = str_repeat('s', count($emails));
    
    $stmt = $conn->prepare("UPDATE subscribers SET status = 'unsubscribed' WHERE email IN ($placeholders)");
    $stmt->bind_param($types, ...$emails);
    $stmt->execute();
    logAdminActivity($user['id'], 'BULK_DELETE_SUBSCRIBERS', "Bulk deleted " . count($emails) . " subscribers");
    header('Location: subscribers.php');
    exit();
}

// Handle export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="subscribers_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Email', 'Name', 'Subscribed Date', 'IP Address', 'Source', 'Status']);
    
    $result = $conn->query("SELECT email, name, subscribed_at, ip_address, source, status FROM subscribers ORDER BY subscribed_at DESC");
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit();
}

// Search functionality
$search = $_GET['search'] ?? '';
$searchCondition = '';
if (!empty($search)) {
    $searchCondition = "WHERE email LIKE '%$search%' OR name LIKE '%$search%'";
}

// Pagination
$page = $_GET['page'] ?? 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$total = $conn->query("SELECT COUNT(*) as count FROM subscribers $searchCondition")->fetch_assoc()['count'];
$totalPages = ceil($total / $limit);

$subscribers = $conn->query("SELECT * FROM subscribers $searchCondition ORDER BY subscribed_at DESC LIMIT $offset, $limit");
$totalActive = $conn->query("SELECT COUNT(*) as count FROM subscribers WHERE status = 'active'")->fetch_assoc()['count'];
$totalUnsubscribed = $conn->query("SELECT COUNT(*) as count FROM subscribers WHERE status = 'unsubscribed'")->fetch_assoc()['count'];
$todaySubscribers = $conn->query("SELECT COUNT(*) as count FROM subscribers WHERE DATE(subscribed_at) = CURDATE()")->fetch_assoc()['count'];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Subscribers - checkdomain.top Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
        
        /* Responsive tables */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0 !important;
            }
            
            .p-8 {
                padding: 1rem;
            }
            
            .flex.justify-between {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch !important;
            }
            
            .flex.gap-3 {
                flex-wrap: wrap;
            }
            
            .export-btn, .search-input, .search-btn {
                width: 100%;
            }
            
            table {
                font-size: 0.75rem;
            }
            
            table td, table th {
                padding: 0.5rem !important;
            }
            
            .action-buttons {
                display: flex;
                gap: 0.5rem;
            }
            
            /* Hide less important columns on mobile */
            .hide-mobile {
                display: none;
            }
        }
        
        @media (max-width: 480px) {
            .hide-mobile-sm {
                display: none;
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
        
        /* Checkbox styling */
        .checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #3B82F6;
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
                    <h1 class="text-2xl md:text-3xl font-bold">Subscribers Management</h1>
                    <p class="text-gray-400 text-sm mt-1">Manage and export your subscriber list</p>
                </div>
                <div class="flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
                    <a href="?export=1" class="bg-green-600 hover:bg-green-700 px-4 py-2 rounded-lg transition flex items-center justify-center gap-2 export-btn">
                        <i class="fas fa-download"></i> Export CSV
                    </a>
                </div>
            </div>
            
            <!-- Stats Cards oooooooooooooooo-->
            <?php
           ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <div class="stat-card rounded-xl p-4">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs">Total Subscribers</p>
                            <p class="text-2xl font-bold mt-2"><?php echo number_format($total); ?></p>
                        </div>
                        <div class="w-10 h-10 bg-blue-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-users text-blue-400"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card rounded-xl p-4">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs">Active Subscribers</p>
                            <p class="text-2xl font-bold mt-2 text-green-400"><?php echo number_format($totalActive); ?></p>
                        </div>
                        <div class="w-10 h-10 bg-green-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-check-circle text-green-400"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card rounded-xl p-4">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs">Unsubscribed</p>
                            <p class="text-2xl font-bold mt-2 text-red-400"><?php echo number_format($totalUnsubscribed); ?></p>
                        </div>
                        <div class="w-10 h-10 bg-red-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-times-circle text-red-400"></i>
                        </div>
                    </div>
                </div>
                <div class="stat-card rounded-xl p-4">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs">New Today</p>
                            <p class="text-2xl font-bold mt-2 text-purple-400"><?php echo number_format($todaySubscribers); ?></p>
                        </div>
                        <div class="w-10 h-10 bg-purple-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-calendar-day text-purple-400"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Search Bar -->
            <div class="bg-slate-800/50 rounded-xl p-4 mb-6">
                <form method="GET" class="flex flex-col sm:flex-row gap-3">
                    <div class="flex-1">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search by email or name..." 
                               class="w-full bg-slate-700 border border-gray-600 rounded-lg py-2 px-4 text-white focus:outline-none focus:border-blue-500 search-input">
                    </div>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 px-6 py-2 rounded-lg transition search-btn">
                        <i class="fas fa-search mr-2"></i> Search
                    </button>
                    <?php if (!empty($search)): ?>
                    <a href="subscribers.php" class="bg-gray-600 hover:bg-gray-700 px-6 py-2 rounded-lg transition text-center">
                        <i class="fas fa-times mr-2"></i> Clear
                    </a>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- Subscribers Table -->
            <div class="bg-slate-800/50 rounded-xl overflow-hidden">
                <?php if($subscribers->num_rows > 0): ?>
                <form method="POST" id="bulkForm">
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-slate-700/50 border-b border-gray-700">
                                <tr>
                                    <th class="text-left p-4 w-12">
                                        <input type="checkbox" id="selectAll" class="checkbox">
                                    </th>
                                    <th class="text-left p-4">Email</th>
                                    <th class="text-left p-4">Name</th>
                                    <th class="text-left p-4 hide-mobile">Subscribed Date</th>
                                    <th class="text-left p-4 hide-mobile">Source</th>
                                    <th class="text-left p-4 hide-mobile-sm">IP Address</th>
                                    <th class="text-left p-4">Status</th>
                                    <th class="text-left p-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $subscribers->fetch_assoc()): ?>
                                <tr class="border-b border-gray-700 hover:bg-slate-700/30 transition">
                                    <td class="p-4">
                                        <input type="checkbox" name="selected_emails[]" value="<?php echo htmlspecialchars($row['email']); ?>" class="checkbox subscriber-checkbox">
                                    </td>
                                    <td class="p-4 break-all">
                                        <?php echo htmlspecialchars($row['email']); ?>
                                    </td>
                                    <td class="p-4">
                                        <?php echo htmlspecialchars($row['name'] ?: '—'); ?>
                                    </td>
                                    <td class="p-4 whitespace-nowrap hide-mobile">
                                        <?php echo date('M d, Y H:i', strtotime($row['subscribed_at'])); ?>
                                    </td>
                                    <td class="p-4 hide-mobile">
                                        <span class="px-2 py-1 bg-blue-500/20 rounded-full text-xs">
                                            <?php echo htmlspecialchars($row['source']); ?>
                                        </span>
                                    </td>
                                    <td class="p-4 font-mono text-xs hide-mobile-sm">
                                        <?php echo $row['ip_address']; ?>
                                    </td>
                                    <td class="p-4">
                                        <span class="px-2 py-1 rounded-full text-xs <?php echo $row['status'] == 'active' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'; ?>">
                                            <?php echo $row['status']; ?>
                                        </span>
                                    </td>
                                    <td class="p-4">
                                        <div class="action-buttons">
                                            <a href="mailto:<?php echo $row['email']; ?>" 
                                               class="text-blue-400 hover:text-blue-300 transition" title="Send Email">
                                                <i class="fas fa-envelope"></i>
                                            </a>
                                            <button type="button" onclick="deleteSubscriber('<?php echo $row['email']; ?>')" 
                                                    class="text-red-400 hover:text-red-300 transition ml-3" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Bulk Actions -->
                    <div class="flex flex-col sm:flex-row justify-between items-center gap-4 p-4 bg-slate-700/30 border-t border-gray-700">
                        <div class="flex items-center gap-2">
                            <span id="selectedCount" class="text-sm text-gray-400">0 selected</span>
                            <button type="button" id="bulkDeleteBtn" 
                                    class="bg-red-600 hover:bg-red-700 px-4 py-1.5 rounded-lg text-sm transition disabled:opacity-50 disabled:cursor-not-allowed" 
                                    disabled>
                                <i class="fas fa-trash-alt mr-1"></i> Delete Selected
                            </button>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if($totalPages > 1): ?>
                        <div class="flex flex-wrap justify-center gap-2">
                            <?php if($page > 1): ?>
                            <a href="?page=<?php echo $page-1; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>" 
                               class="px-3 py-1 rounded bg-slate-700 hover:bg-slate-600 transition">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <?php endif; ?>
                            
                            <?php 
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            for($i = $startPage; $i <= $endPage; $i++): ?>
                            <a href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>" 
                               class="px-3 py-1 rounded <?php echo $i == $page ? 'bg-blue-600' : 'bg-slate-700 hover:bg-slate-600'; ?> transition">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>
                            
                            <?php if($page < $totalPages): ?>
                            <a href="?page=<?php echo $page+1; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>" 
                               class="px-3 py-1 rounded bg-slate-700 hover:bg-slate-600 transition">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </form>
                <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-inbox text-5xl text-gray-600 mb-4"></i>
                    <p class="text-gray-400">No subscribers found</p>
                    <?php if (!empty($search)): ?>
                    <p class="text-gray-500 text-sm mt-2">Try a different search term</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    // Select all checkbox functionality
    const selectAllCheckbox = document.getElementById('selectAll');
    const subscriberCheckboxes = document.querySelectorAll('.subscriber-checkbox');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const selectedCountSpan = document.getElementById('selectedCount');
    
    function updateSelectedCount() {
        const checked = document.querySelectorAll('.subscriber-checkbox:checked');
        const count = checked.length;
        selectedCountSpan.textContent = count + ' selected';
        bulkDeleteBtn.disabled = count === 0;
    }
    
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            subscriberCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
            updateSelectedCount();
        });
    }
    
    subscriberCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const allChecked = Array.from(subscriberCheckboxes).every(cb => cb.checked);
            if (selectAllCheckbox) selectAllCheckbox.checked = allChecked;
            updateSelectedCount();
        });
    });
    
    // Bulk delete
    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', function() {
            const checked = document.querySelectorAll('.subscriber-checkbox:checked');
            if (checked.length === 0) return;
            
            if (confirm('Are you sure you want to unsubscribe ' + checked.length + ' subscriber(s)?')) {
                const form = document.getElementById('bulkForm');
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'bulk_delete';
                input.value = '1';
                form.appendChild(input);
                form.submit();
            }
        });
    }
    
    // Single delete
    function deleteSubscriber(email) {
        if(confirm('Are you sure you want to unsubscribe ' + email + '?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="delete" value="1"><input type="hidden" name="email" value="' + email + '">';
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    // Initialize selected count
    updateSelectedCount();
    </script>
</body>
</html>