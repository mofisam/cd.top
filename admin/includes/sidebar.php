<?php
// Get unread messages count for badge
$conn = getDBConnection();
$unreadCount = 0;
if (isset($conn) && $conn) {
    $result = $conn->query("SELECT COUNT(*) as count FROM contact_messages WHERE status = 'unread'");
    if ($result) {
        $unreadCount = $result->fetch_assoc()['count'];
    }
}

// Get current page to highlight active nav item
$currentPage = basename($_SERVER['PHP_SELF']);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <style>
        /* Mobile menu button */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 60;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 0.75rem;
            padding: 0.75rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .mobile-menu-btn:hover {
            background: rgba(59, 130, 246, 0.2);
            border-color: rgba(59, 130, 246, 0.6);
        }
        
        /* Sidebar responsive */
        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }
            
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                z-index: 55;
                width: 280px;
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0 !important;
            }
            
            .overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 50;
            }
            
            .overlay.active {
                display: block;
            }
        }
        
        @media (min-width: 769px) {
            .sidebar {
                transform: translateX(0) !important;
            }
        }
        
        .sidebar {
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(10px);
            border-right: 1px solid rgba(59, 130, 246, 0.3);
            transition: transform 0.3s ease;
        }
        
        .nav-item {
            transition: all 0.2s ease;
            position: relative;
            border-left: 3px solid transparent;
        }
        
        .nav-item:hover, .nav-item.active {
            background: rgba(59, 130, 246, 0.15);
            border-left-color: #3B82F6;
        }
        
        .nav-item.active {
            background: rgba(59, 130, 246, 0.2);
        }
        
        /* Scrollbar styling */
        .sidebar::-webkit-scrollbar {
            width: 5px;
        }
        
        .sidebar::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.5);
        }
        
        .sidebar::-webkit-scrollbar-thumb {
            background: #3B82F6;
            border-radius: 10px;
        }
    </style>
</head>
<body>
<!-- Mobile Menu Button -->
<div class="mobile-menu-btn" id="mobileMenuBtn">
    <i class="fas fa-bars text-xl"></i>
</div>

<!-- Overlay for mobile -->
<div class="overlay" id="mobileOverlay"></div>

<!-- Sidebar -->
<div class="sidebar w-64 fixed h-full  z-50" id="sidebar">
    <div class="p-6 border-b border-blue-500/30">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-green-500 rounded-lg flex items-center justify-center shadow-lg">
                <img src="../images/logo.png" alt="checkdomain.top" class="custom-logo"> 
            </div>
            <div>
                <h2 class="font-bold text-lg">checkdomain</h2>
                <p class="text-xs text-gray-400">Admin Panel</p>
            </div>
        </div>
    </div>
    
    <nav class="p-4 fixed h-full overflow-y-auto w-64" >
        <div class="space-y-1">
            <a href="stats.php" class="nav-item <?php echo $currentPage == 'stats.php' ? 'active' : ''; ?> flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                <i class="fas fa-tachometer-alt w-5"></i>
                <span>Dashboard</span>
            </a>
            
            <a href="subscribers.php" class="nav-item <?php echo $currentPage == 'subscribers.php' ? 'active' : ''; ?> flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                <i class="fas fa-users w-5"></i>
                <span>Subscribers</span>
            </a>
            
            <a href="search-analytics.php" class="nav-item <?php echo $currentPage == 'search-analytics.php' ? 'active' : ''; ?> flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                <i class="fas fa-chart-line w-5"></i>
                <span>Search Analytics</span>
            </a>
            
            <a href="page-views.php" class="nav-item <?php echo $currentPage == 'page-views.php' ? 'active' : ''; ?> flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                <i class="fas fa-eye w-5"></i>
                <span>Page Views</span>
            </a>
            
            <a href="messages.php" class="nav-item <?php echo $currentPage == 'messages.php' ? 'active' : ''; ?> flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                <i class="fas fa-envelope w-5"></i>
                <span>Messages</span>
                <?php if($unreadCount > 0): ?>
                <span class="ml-auto bg-red-500 text-white text-xs px-2 py-0.5 rounded-full animate-pulse"><?php echo $unreadCount; ?></span>
                <?php endif; ?>
            </a>
            
            <a href="domains.php" class="nav-item <?php echo $currentPage == 'domains.php' ? 'active' : ''; ?> flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                <i class="fas fa-thumbtack w-5"></i>
                <span>Pinned Domains</span>
            </a>
            
            <a href="activity.php" class="nav-item <?php echo $currentPage == 'activity.php' ? 'active' : ''; ?> flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                <i class="fas fa-history w-5"></i>
                <span>Activity Log</span>
            </a>
            
            <a href="test-smtp.php" class="nav-item <?php echo $currentPage == 'test-smtp.php' ? 'active' : ''; ?> flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                <i class="fas fa-envelope w-5"></i>
                <span>SMTP Test</span>
            </a>
            
            <a href="settings.php" class="nav-item <?php echo $currentPage == 'settings.php' ? 'active' : ''; ?> flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                <i class="fas fa-cog w-5"></i>
                <span>Settings</span>
            </a>
        </div>
        
        <div class="mt-8 pt-8 border-t border-gray-700">
            <div class="px-4 py-2 mb-2">
                <p class="text-xs text-gray-500 uppercase tracking-wider">Account</p>
            </div>
            <a href="logout.php" class="flex items-center gap-3 px-4 py-3 rounded-lg text-red-400 hover:bg-red-500/10 transition">
                <i class="fas fa-sign-out-alt w-5"></i>
                <span>Logout</span>
            </a>
        </div>
    </nav>
</div>

<script>
// Mobile menu toggle
document.getElementById('mobileMenuBtn')?.addEventListener('click', function() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('mobileOverlay').classList.toggle('active');
});

document.getElementById('mobileOverlay')?.addEventListener('click', function() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('mobileOverlay').classList.remove('active');
});

// Close sidebar on window resize if open
window.addEventListener('resize', function() {
    if (window.innerWidth > 768) {
        document.getElementById('sidebar')?.classList.remove('open');
        document.getElementById('mobileOverlay')?.classList.remove('active');
    }
});
</script>
</body>
</html>