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

// Get all messages
$messages = $conn->query("SELECT * FROM contact_messages ORDER BY 
    CASE status 
        WHEN 'unread' THEN 1 
        WHEN 'read' THEN 2 
        ELSE 3 
    END, created_at DESC");

$unreadCount = $conn->query("SELECT COUNT(*) as count FROM contact_messages WHERE status = 'unread'")->fetch_assoc()['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Messages - checkdomain.top Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
                    <a href="messages.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition bg-blue-500/20">
                        <i class="fas fa-envelope w-5"></i>
                        <span>Messages</span>
                        <?php if($unreadCount > 0): ?>
                        <span class="ml-auto bg-red-500 text-white text-xs px-2 py-0.5 rounded-full"><?php echo $unreadCount; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="search-analytics.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                        <i class="fas fa-chart-line w-5"></i>
                        <span>Search Analytics</span>
                    </a>
                    <a href="page-views.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
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
                <div class="flex justify-between items-center mb-8">
                    <div>
                        <h1 class="text-3xl font-bold">Contact Messages</h1>
                        <p class="text-gray-400 mt-1">Manage and respond to user inquiries</p>
                    </div>
                </div>
                
                <div class="bg-slate-800/50 rounded-xl overflow-hidden">
                    <?php if($messages->num_rows > 0): ?>
                    <div class="divide-y divide-gray-700">
                        <?php while($msg = $messages->fetch_assoc()): ?>
                        <div class="p-6 hover:bg-slate-700/30 transition <?php echo $msg['status'] == 'unread' ? 'bg-blue-900/20' : ''; ?>">
                            <div class="flex justify-between items-start mb-3">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 flex-wrap mb-2">
                                        <h3 class="font-semibold text-lg"><?php echo htmlspecialchars($msg['subject']); ?></h3>
                                        <?php if($msg['status'] == 'unread'): ?>
                                        <span class="px-2 py-1 bg-red-500/20 text-red-400 text-xs rounded-full">New</span>
                                        <?php elseif($msg['status'] == 'replied'): ?>
                                        <span class="px-2 py-1 bg-green-500/20 text-green-400 text-xs rounded-full">Replied</span>
                                        <?php else: ?>
                                        <span class="px-2 py-1 bg-gray-500/20 text-gray-400 text-xs rounded-full">Read</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-gray-400 text-sm mb-2">
                                        <i class="fas fa-user mr-2"></i><?php echo htmlspecialchars($msg['name']); ?> 
                                        <i class="fas fa-envelope ml-3 mr-2"></i><?php echo htmlspecialchars($msg['email']); ?>
                                        <i class="fas fa-calendar ml-3 mr-2"></i><?php echo date('M d, Y H:i', strtotime($msg['created_at'])); ?>
                                        <i class="fas fa-globe ml-3 mr-2"></i><?php echo $msg['ip_address']; ?>
                                    </p>
                                    <div class="bg-slate-900/50 rounded-lg p-4 mt-3">
                                        <p class="text-gray-300 whitespace-pre-wrap"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p>
                                    </div>
                                </div>
                                <div class="flex gap-2 ml-4">
                                    <a href="mailto:<?php echo $msg['email']; ?>?subject=Re: <?php echo urlencode($msg['subject']); ?>" 
                                       class="px-3 py-1 bg-green-600 hover:bg-green-700 rounded-lg text-sm transition flex items-center gap-1">
                                        <i class="fas fa-reply"></i> Reply
                                    </a>
                                    <?php if($msg['status'] == 'unread'): ?>
                                    <a href="?mark_read=1&id=<?php echo $msg['id']; ?>" 
                                       class="px-3 py-1 bg-blue-600 hover:bg-blue-700 rounded-lg text-sm transition">
                                        <i class="fas fa-check"></i> Mark Read
                                    </a>
                                    <?php endif; ?>
                                    <?php if($msg['status'] == 'read'): ?>
                                    <a href="?mark_replied=1&id=<?php echo $msg['id']; ?>" 
                                       class="px-3 py-1 bg-purple-600 hover:bg-purple-700 rounded-lg text-sm transition">
                                        <i class="fas fa-check-double"></i> Mark Replied
                                    </a>
                                    <?php endif; ?>
                                    <a href="?delete=1&id=<?php echo $msg['id']; ?>" 
                                       onclick="return confirm('Delete this message?')"
                                       class="px-3 py-1 bg-red-600 hover:bg-red-700 rounded-lg text-sm transition">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-inbox text-5xl text-gray-600 mb-4"></i>
                        <p class="text-gray-400">No messages yet</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

<?php $conn->close(); ?>