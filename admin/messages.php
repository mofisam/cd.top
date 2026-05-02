<?php
require_once 'auth_check.php';
$user = checkAdminAuth();
require_once '../config/database.php';

$conn = getDBConnection();

// Mark as read
if (isset($_GET['mark_read']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("UPDATE contact_messages SET status = 'read', read_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    logAdminActivity($user['id'], 'MARK_READ', "Marked message ID: $id as read");
    header('Location: messages.php');
    exit();
}

// Mark as replied
if (isset($_GET['mark_replied']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("UPDATE contact_messages SET status = 'replied', replied_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    logAdminActivity($user['id'], 'MARK_REPLIED', "Marked message ID: $id as replied");
    header('Location: messages.php');
    exit();
}

// Delete message
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM contact_messages WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    logAdminActivity($user['id'], 'DELETE_MESSAGE', "Deleted contact message ID: $id");
    header('Location: messages.php');
    exit();
}

// Get filter parameter
$filter = $_GET['filter'] ?? 'all';
$whereClause = "";
if ($filter === 'unread') {
    $whereClause = "WHERE status = 'unread'";
} elseif ($filter === 'replied') {
    $whereClause = "WHERE status = 'replied'";
} elseif ($filter === 'read') {
    $whereClause = "WHERE status = 'read'";
}

// Get all messages with filter
$messages = $conn->query("SELECT * FROM contact_messages $whereClause ORDER BY 
    CASE status 
        WHEN 'unread' THEN 1 
        WHEN 'read' THEN 2 
        ELSE 3 
    END, created_at DESC");

$unreadCount = $conn->query("SELECT COUNT(*) as count FROM contact_messages WHERE status = 'unread'")->fetch_assoc()['count'];
$totalCount = $conn->query("SELECT COUNT(*) as count FROM contact_messages")->fetch_assoc()['count'];
$repliedCount = $conn->query("SELECT COUNT(*) as count FROM contact_messages WHERE status = 'replied'")->fetch_assoc()['count'];
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Contact Messages - checkdomain.top Admin</title>
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
        
        .main-content {
            transition: margin-left 0.3s ease;
        }
        
        /* Message card styles */
        .message-card {
            transition: all 0.2s ease;
        }
        
        .message-card:hover {
            transform: translateX(4px);
        }
        
        /* Responsive design */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0 !important;
            }
            
            .p-8 {
                padding: 1rem;
            }
            
            .message-card {
                padding: 1rem !important;
            }
            
            .message-header {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 0.75rem;
            }
            
            .message-actions {
                width: 100%;
                justify-content: flex-start !important;
                flex-wrap: wrap;
            }
            
            .message-meta {
                font-size: 0.7rem;
                flex-wrap: wrap;
            }
            
            .filter-buttons {
                flex-wrap: wrap;
                width: 100%;
            }
            
            .stats-cards {
                grid-template-columns: repeat(2, 1fr) !important;
            }
        }
        
        @media (max-width: 480px) {
            .stats-cards {
                grid-template-columns: 1fr !important;
            }
            
            .message-actions a {
                font-size: 0.7rem;
                padding: 0.25rem 0.5rem;
            }
            
            h1 {
                font-size: 1.5rem;
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
        
        /* Status badges */
        .badge-unread {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }
        
        .badge-read {
            background: rgba(107, 114, 128, 0.2);
            color: #9ca3af;
        }
        
        .badge-replied {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }
        
        /* Filter button active state */
        .filter-active {
            background: rgba(59, 130, 246, 0.2);
            border-color: #3B82F6;
            color: #3B82F6;
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
                    <h1 class="text-2xl md:text-3xl font-bold">Contact Messages</h1>
                    <p class="text-gray-400 text-sm mt-1">Manage and respond to user inquiries</p>
                </div>
                <div class="flex gap-3">
                    <button onclick="window.location.reload()" class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg transition text-sm w-full sm:w-auto">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="stats-cards grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-slate-800/50 rounded-xl p-4 border border-blue-500/20">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-gray-400 text-xs">Total Messages</p>
                            <p class="text-2xl font-bold mt-1"><?php echo $totalCount; ?></p>
                        </div>
                        <i class="fas fa-envelope text-blue-400 text-xl"></i>
                    </div>
                </div>
                <div class="bg-slate-800/50 rounded-xl p-4 border border-red-500/20">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-gray-400 text-xs">Unread</p>
                            <p class="text-2xl font-bold mt-1 text-red-400"><?php echo $unreadCount; ?></p>
                        </div>
                        <i class="fas fa-circle text-red-400 text-xl"></i>
                    </div>
                </div>
                <div class="bg-slate-800/50 rounded-xl p-4 border border-green-500/20">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-gray-400 text-xs">Replied</p>
                            <p class="text-2xl font-bold mt-1 text-green-400"><?php echo $repliedCount; ?></p>
                        </div>
                        <i class="fas fa-check-double text-green-400 text-xl"></i>
                    </div>
                </div>
                <div class="bg-slate-800/50 rounded-xl p-4 border border-gray-500/20">
                    <div class="flex justify-between items-center">
                        <div>
                            <p class="text-gray-400 text-xs">Read</p>
                            <p class="text-2xl font-bold mt-1 text-gray-400"><?php echo $totalCount - $unreadCount - $repliedCount; ?></p>
                        </div>
                        <i class="fas fa-check text-gray-400 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <!-- Filter Buttons -->
            <div class="filter-buttons flex gap-2 mb-6 overflow-x-auto pb-2">
                <a href="?filter=all" class="px-4 py-2 rounded-lg text-sm transition <?php echo $filter == 'all' ? 'filter-active bg-blue-500/20 border border-blue-500' : 'bg-slate-800/50 hover:bg-slate-700/50'; ?>">
                    <i class="fas fa-list mr-2"></i> All Messages
                </a>
                <a href="?filter=unread" class="px-4 py-2 rounded-lg text-sm transition <?php echo $filter == 'unread' ? 'filter-active bg-red-500/20 border border-red-500' : 'bg-slate-800/50 hover:bg-slate-700/50'; ?>">
                    <i class="fas fa-circle mr-2 text-red-400"></i> Unread
                    <?php if($unreadCount > 0): ?>
                    <span class="ml-1 text-red-400">(<?php echo $unreadCount; ?>)</span>
                    <?php endif; ?>
                </a>
                <a href="?filter=replied" class="px-4 py-2 rounded-lg text-sm transition <?php echo $filter == 'replied' ? 'filter-active bg-green-500/20 border border-green-500' : 'bg-slate-800/50 hover:bg-slate-700/50'; ?>">
                    <i class="fas fa-check-double mr-2 text-green-400"></i> Replied
                </a>
                <a href="?filter=read" class="px-4 py-2 rounded-lg text-sm transition <?php echo $filter == 'read' ? 'filter-active bg-gray-500/20 border border-gray-500' : 'bg-slate-800/50 hover:bg-slate-700/50'; ?>">
                    <i class="fas fa-check mr-2 text-gray-400"></i> Read
                </a>
            </div>
            
            <!-- Messages List -->
            <div class="space-y-4">
                <?php if($messages->num_rows > 0): ?>
                    <?php while($msg = $messages->fetch_assoc()): ?>
                    <div class="message-card bg-slate-800/50 rounded-xl p-4 md:p-6 border border-blue-500/20 hover:border-blue-500/40 transition <?php echo $msg['status'] == 'unread' ? 'bg-blue-900/20 border-l-4 border-l-blue-500' : ''; ?>">
                        <div class="flex flex-col md:flex-row justify-between items-start gap-4">
                            <div class="flex-1 w-full">
                                <!-- Message Header -->
                                <div class="message-header flex flex-wrap justify-between items-start gap-2 mb-3">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <h3 class="font-semibold text-base md:text-lg"><?php echo htmlspecialchars($msg['subject']); ?></h3>
                                        <?php if($msg['status'] == 'unread'): ?>
                                        <span class="badge-unread px-2 py-0.5 rounded-full text-xs font-medium">New</span>
                                        <?php elseif($msg['status'] == 'replied'): ?>
                                        <span class="badge-replied px-2 py-0.5 rounded-full text-xs font-medium">Replied</span>
                                        <?php else: ?>
                                        <span class="badge-read px-2 py-0.5 rounded-full text-xs font-medium">Read</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="message-meta text-gray-400 text-xs flex items-center gap-3">
                                        <span><i class="far fa-calendar-alt mr-1"></i><?php echo date('M d, Y H:i', strtotime($msg['created_at'])); ?></span>
                                        <span class="hidden sm:inline"><i class="fas fa-globe mr-1"></i><?php echo $msg['ip_address']; ?></span>
                                    </div>
                                </div>
                                
                                <!-- Sender Info -->
                                <div class="message-meta flex flex-wrap gap-4 mb-3 text-sm text-gray-400">
                                    <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($msg['name']); ?></span>
                                    <span><i class="fas fa-envelope mr-1"></i>
                                        <a href="mailto:<?php echo $msg['email']; ?>" class="hover:text-blue-400 transition"><?php echo htmlspecialchars($msg['email']); ?></a>
                                    </span>
                                </div>
                                
                                <!-- Message Content -->
                                <div class="bg-slate-900/50 rounded-lg p-4 mt-2">
                                    <p class="text-gray-300 whitespace-pre-wrap text-sm leading-relaxed"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p>
                                </div>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="message-actions flex flex-wrap gap-2">
                                <a href="mailto:<?php echo $msg['email']; ?>?subject=Re: <?php echo urlencode($msg['subject']); ?>" 
                                   class="px-3 py-1.5 bg-green-600 hover:bg-green-700 rounded-lg text-xs transition flex items-center gap-1 whitespace-nowrap">
                                    <i class="fas fa-reply"></i> Reply
                                </a>
                                <?php if($msg['status'] == 'unread'): ?>
                                <a href="?mark_read=1&id=<?php echo $msg['id']; ?>&filter=<?php echo $filter; ?>" 
                                   class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 rounded-lg text-xs transition whitespace-nowrap">
                                    <i class="fas fa-check"></i> Mark Read
                                </a>
                                <?php endif; ?>
                                <?php if($msg['status'] == 'read'): ?>
                                <a href="?mark_replied=1&id=<?php echo $msg['id']; ?>&filter=<?php echo $filter; ?>" 
                                   class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 rounded-lg text-xs transition whitespace-nowrap">
                                    <i class="fas fa-check-double"></i> Mark Replied
                                </a>
                                <?php endif; ?>
                                <a href="?delete=1&id=<?php echo $msg['id']; ?>&filter=<?php echo $filter; ?>" 
                                   onclick="return confirm('Delete this message? This action cannot be undone.')"
                                   class="px-3 py-1.5 bg-red-600 hover:bg-red-700 rounded-lg text-xs transition whitespace-nowrap">
                                    <i class="fas fa-trash"></i> Delete
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <!-- Empty State -->
                    <div class="bg-slate-800/50 rounded-xl p-12 text-center">
                        <i class="fas fa-inbox text-6xl text-gray-600 mb-4"></i>
                        <h3 class="text-xl font-semibold mb-2">No Messages Found</h3>
                        <p class="text-gray-400">
                            <?php if($filter != 'all'): ?>
                                No <?php echo $filter; ?> messages in your inbox.
                                <a href="?filter=all" class="text-blue-400 hover:underline">View all messages</a>
                            <?php else: ?>
                                Your inbox is empty. When users contact you, messages will appear here.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-refresh unread count every 30 seconds (optional)
        let autoRefresh = true;
        
        if (autoRefresh) {
            setInterval(function() {
                fetch('messages.php?check_unread=1')
                    .then(response => response.json())
                    .then(data => {
                        if (data.unread_count > 0) {
                            // Update badge without page refresh
                            const badge = document.querySelector('.nav-item.active .ml-auto');
                            if (badge) {
                                badge.textContent = data.unread_count;
                            }
                        }
                    })
                    .catch(console.error);
            }, 30000);
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + R to refresh
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                window.location.reload();
            }
        });
    </script>
</body>
</html>
