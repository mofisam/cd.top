<?php
require_once 'auth_check.php';
$user = checkAdminAuth();
require_once '../config/database.php';

$conn = getDBConnection();
$message = '';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Verify current password
    $stmt = $conn->prepare("SELECT password_hash FROM admin_users WHERE id = ?");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $admin = $result->fetch_assoc();
    
    if (password_verify($currentPassword, $admin['password_hash'])) {
        if ($newPassword === $confirmPassword) {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateStmt = $conn->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?");
            $updateStmt->bind_param("si", $newHash, $user['id']);
            if ($updateStmt->execute()) {
                $message = '<div class="bg-green-500/20 border border-green-500/50 rounded-lg p-3 mb-4">Password changed successfully!</div>';
                logAdminActivity($user['id'], 'CHANGE_PASSWORD', 'Admin changed password');
            }
            $updateStmt->close();
        } else {
            $message = '<div class="bg-red-500/20 border border-red-500/50 rounded-lg p-3 mb-4">New passwords do not match!</div>';
        }
    } else {
        $message = '<div class="bg-red-500/20 border border-red-500/50 rounded-lg p-3 mb-4">Current password is incorrect!</div>';
    }
    $stmt->close();
}

// Handle site settings (demo)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $siteName = $_POST['site_name'];
    $siteDescription = $_POST['site_description'];
    $contactEmail = $_POST['contact_email'];
    
    // In a real app, save these to a settings table
    $message = '<div class="bg-green-500/20 border border-green-500/50 rounded-lg p-3 mb-4">Settings saved successfully!</div>';
    logAdminActivity($user['id'], 'UPDATE_SETTINGS', 'Updated site settings');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - checkdomain.top Admin</title>
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
                    <a href="domains.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                        <i class="fas fa-thumbtack w-5"></i>
                        <span>Pinned Domains</span>
                    </a>
                    <a href="activity.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                        <i class="fas fa-history w-5"></i>
                        <span>Activity Log</span>
                    </a>
                    <a href="settings.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition bg-blue-500/20">
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
                <div class="mb-8">
                    <h1 class="text-3xl font-bold">Admin Settings</h1>
                    <p class="text-gray-400 mt-1">Manage your account and site preferences</p>
                </div>
                
                <?php echo $message; ?>
                
                <!-- Change Password -->
                <div class="bg-slate-800/50 rounded-xl p-6 mb-8">
                    <h2 class="text-xl font-semibold mb-4">Change Password</h2>
                    <form method="POST" class="max-w-md">
                        <div class="mb-4">
                            <label class="block text-gray-300 text-sm mb-2">Current Password</label>
                            <input type="password" name="current_password" required 
                                class="w-full bg-slate-700 border border-gray-600 rounded-lg py-2 px-3 text-white focus:outline-none focus:border-blue-500">
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-300 text-sm mb-2">New Password</label>
                            <input type="password" name="new_password" required 
                                class="w-full bg-slate-700 border border-gray-600 rounded-lg py-2 px-3 text-white focus:outline-none focus:border-blue-500">
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-300 text-sm mb-2">Confirm New Password</label>
                            <input type="password" name="confirm_password" required 
                                class="w-full bg-slate-700 border border-gray-600 rounded-lg py-2 px-3 text-white focus:outline-none focus:border-blue-500">
                        </div>
                        <button type="submit" name="change_password" class="bg-blue-600 hover:bg-blue-700 px-6 py-2 rounded-lg transition">
                            Update Password
                        </button>
                    </form>
                </div>
                
                <!-- Site Settings -->
                <div class="bg-slate-800/50 rounded-xl p-6">
                    <h2 class="text-xl font-semibold mb-4">Site Settings</h2>
                    <form method="POST" class="max-w-md">
                        <div class="mb-4">
                            <label class="block text-gray-300 text-sm mb-2">Site Name</label>
                            <input type="text" name="site_name" value="checkdomain.top" 
                                class="w-full bg-slate-700 border border-gray-600 rounded-lg py-2 px-3 text-white focus:outline-none focus:border-blue-500">
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-300 text-sm mb-2">Site Description</label>
                            <textarea name="site_description" rows="3" 
                                class="w-full bg-slate-700 border border-gray-600 rounded-lg py-2 px-3 text-white focus:outline-none focus:border-blue-500">Check domain availability instantly. Never miss your perfect domain again.</textarea>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-300 text-sm mb-2">Contact Email</label>
                            <input type="email" name="contact_email" value="hello@checkdomain.top" 
                                class="w-full bg-slate-700 border border-gray-600 rounded-lg py-2 px-3 text-white focus:outline-none focus:border-blue-500">
                        </div>
                        <button type="submit" name="save_settings" class="bg-green-600 hover:bg-green-700 px-6 py-2 rounded-lg transition">
                            Save Settings
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

<?php $conn->close(); ?>