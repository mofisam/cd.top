<?php
require_once 'auth_check.php';
$user = checkAdminAuth();
require_once '../config/database.php';

$conn = getDBConnection();
$message = '';
$messageType = '';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Password strength validation
    $passwordErrors = [];
    if (strlen($newPassword) < 8) {
        $passwordErrors[] = "Password must be at least 8 characters long";
    }
    if (!preg_match('/[A-Z]/', $newPassword)) {
        $passwordErrors[] = "Password must contain at least one uppercase letter";
    }
    if (!preg_match('/[a-z]/', $newPassword)) {
        $passwordErrors[] = "Password must contain at least one lowercase letter";
    }
    if (!preg_match('/[0-9]/', $newPassword)) {
        $passwordErrors[] = "Password must contain at least one number";
    }
    
    if (!empty($passwordErrors)) {
        $message = '<div class="bg-red-500/20 border border-red-500/50 rounded-lg p-3 mb-4">
            <p class="font-semibold mb-1">Password requirements not met:</p>
            <ul class="list-disc list-inside text-sm">';
        foreach ($passwordErrors as $error) {
            $message .= '<li>' . htmlspecialchars($error) . '</li>';
        }
        $message .= '</ul></div>';
        $messageType = 'error';
    } else {
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
                    $message = '<div class="bg-green-500/20 border border-green-500/50 rounded-lg p-3 mb-4">
                        <i class="fas fa-check-circle mr-2"></i> Password changed successfully!
                    </div>';
                    $messageType = 'success';
                    logAdminActivity($user['id'], 'CHANGE_PASSWORD', 'Admin changed password');
                }
                $updateStmt->close();
            } else {
                $message = '<div class="bg-red-500/20 border border-red-500/50 rounded-lg p-3 mb-4">
                    <i class="fas fa-exclamation-triangle mr-2"></i> New passwords do not match!
                </div>';
                $messageType = 'error';
            }
        } else {
            $message = '<div class="bg-red-500/20 border border-red-500/50 rounded-lg p-3 mb-4">
                <i class="fas fa-exclamation-triangle mr-2"></i> Current password is incorrect!
            </div>';
            $messageType = 'error';
        }
        $stmt->close();
    }
}

// Handle site settings save to database
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $siteName = $conn->real_escape_string($_POST['site_name']);
    $siteDescription = $conn->real_escape_string($_POST['site_description']);
    $contactEmail = $conn->real_escape_string($_POST['contact_email']);
    $maintenanceMode = isset($_POST['maintenance_mode']) ? 1 : 0;
    
    // Create settings table if not exists
    $conn->query("CREATE TABLE IF NOT EXISTS site_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    
    // Save settings
    $settings = [
        'site_name' => $siteName,
        'site_description' => $siteDescription,
        'contact_email' => $contactEmail,
        'maintenance_mode' => $maintenanceMode
    ];
    
    foreach ($settings as $key => $value) {
        $conn->query("INSERT INTO site_settings (setting_key, setting_value) VALUES ('$key', '$value') 
                      ON DUPLICATE KEY UPDATE setting_value = '$value'");
    }
    
    $message = '<div class="bg-green-500/20 border border-green-500/50 rounded-lg p-3 mb-4">
        <i class="fas fa-check-circle mr-2"></i> Settings saved successfully!
    </div>';
    $messageType = 'success';
    logAdminActivity($user['id'], 'UPDATE_SETTINGS', 'Updated site settings');
}

// Load current settings
$settings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM site_settings");
while ($row = $result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get admin info
$adminStmt = $conn->prepare("SELECT username, email, created_at, last_login FROM admin_users WHERE id = ?");
$adminStmt->bind_param("i", $user['id']);
$adminStmt->execute();
$adminInfo = $adminStmt->get_result()->fetch_assoc();
$adminStmt->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Settings - checkdomain.top Admin</title>
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
        
        .settings-card {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 1rem;
            transition: all 0.3s ease;
        }
        
        .settings-card:hover {
            border-color: rgba(59, 130, 246, 0.4);
        }
        
        .main-content {
            transition: margin-left 0.3s ease;
        }
        
        .input-field {
            transition: all 0.2s ease;
        }
        
        .input-field:focus {
            transform: translateY(-1px);
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
        
        /* Responsive */
        @media (max-width: 768px) {
            .settings-container {
                padding: 1rem !important;
            }
            
            .settings-card {
                padding: 1rem !important;
            }
            
            .settings-card h2 {
                font-size: 1.25rem;
            }
            
            .info-grid {
                grid-template-columns: 1fr !important;
                gap: 0.75rem !important;
            }
        }
        
        @media (max-width: 640px) {
            .flex.justify-between {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch !important;
            }
            
            button {
                width: 100%;
            }
            
            input, textarea, select {
                font-size: 16px !important; /* Prevents zoom on mobile */
            }
        }
        
        /* Password strength indicator */
        .strength-bar {
            height: 4px;
            transition: all 0.3s ease;
        }
        
        /* Toggle switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #4B5563;
            transition: 0.3s;
            border-radius: 24px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: 0.3s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: #10B981;
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }
    </style>
</head>
<body class="text-white">
    <!-- Include Sidebar -->
    <?php include_once 'includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content" style="margin-left: 16rem;">
        <div class="settings-container p-4 md:p-8">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                <div>
                    <h1 class="text-2xl md:text-3xl font-bold">Admin Settings</h1>
                    <p class="text-gray-400 text-sm mt-1">Manage your account and site preferences</p>
                </div>
                <div class="flex gap-3 w-full sm:w-auto">
                    <button onclick="window.location.reload()" class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg transition w-full sm:w-auto">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>
            
            <!-- Message Display -->
            <?php echo $message; ?>
            
            <!-- Two Column Layout -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Left Column -->
                <div class="space-y-6">
                    <!-- Admin Profile Card -->
                    <div class="settings-card rounded-xl p-6">
                        <h2 class="text-xl font-semibold mb-4 flex items-center gap-2">
                            <i class="fas fa-user-shield text-blue-400"></i>
                            Admin Profile
                        </h2>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center py-2 border-b border-gray-700">
                                <span class="text-gray-400">Username</span>
                                <span class="font-semibold"><?php echo htmlspecialchars($adminInfo['username']); ?></span>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-gray-700">
                                <span class="text-gray-400">Email</span>
                                <span><?php echo htmlspecialchars($adminInfo['email']); ?></span>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-gray-700">
                                <span class="text-gray-400">Account Created</span>
                                <span><?php echo date('M d, Y', strtotime($adminInfo['created_at'])); ?></span>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-gray-700">
                                <span class="text-gray-400">Last Login</span>
                                <span><?php echo $adminInfo['last_login'] ? date('M d, Y H:i', strtotime($adminInfo['last_login'])) : 'First login'; ?></span>
                            </div>
                            <div class="flex justify-between items-center py-2">
                                <span class="text-gray-400">Role</span>
                                <span class="px-2 py-1 bg-blue-500/20 rounded-full text-xs"><?php echo ucfirst($user['role']); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Change Password Card -->
                    <div class="settings-card rounded-xl p-6">
                        <h2 class="text-xl font-semibold mb-4 flex items-center gap-2">
                            <i class="fas fa-key text-green-400"></i>
                            Change Password
                        </h2>
                        <form method="POST" class="space-y-4">
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">Current Password</label>
                                <input type="password" name="current_password" required 
                                    class="input-field w-full bg-slate-700 border border-gray-600 rounded-lg py-2.5 px-3 text-white focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">New Password</label>
                                <input type="password" id="new_password" name="new_password" required 
                                    class="input-field w-full bg-slate-700 border border-gray-600 rounded-lg py-2.5 px-3 text-white focus:outline-none focus:border-blue-500 transition">
                                <div class="mt-2">
                                    <div class="strength-bar rounded-full" id="passwordStrength"></div>
                                    <p class="text-xs text-gray-500 mt-1" id="passwordHint"></p>
                                </div>
                            </div>
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">Confirm New Password</label>
                                <input type="password" name="confirm_password" required 
                                    class="input-field w-full bg-slate-700 border border-gray-600 rounded-lg py-2.5 px-3 text-white focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <button type="submit" name="change_password" class="bg-blue-600 hover:bg-blue-700 px-6 py-2 rounded-lg transition w-full md:w-auto">
                                <i class="fas fa-save mr-2"></i> Update Password
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div class="space-y-6">
                    <!-- Site Settings Card -->
                    <div class="settings-card rounded-xl p-6">
                        <h2 class="text-xl font-semibold mb-4 flex items-center gap-2">
                            <i class="fas fa-globe text-purple-400"></i>
                            Site Settings
                        </h2>
                        <form method="POST" class="space-y-4">
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">Site Name</label>
                                <input type="text" name="site_name" value="<?php echo htmlspecialchars($settings['site_name'] ?? 'checkdomain.top'); ?>" 
                                    class="input-field w-full bg-slate-700 border border-gray-600 rounded-lg py-2.5 px-3 text-white focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">Site Description</label>
                                <textarea name="site_description" rows="3" 
                                    class="input-field w-full bg-slate-700 border border-gray-600 rounded-lg py-2.5 px-3 text-white focus:outline-none focus:border-blue-500 transition"><?php echo htmlspecialchars($settings['site_description'] ?? 'Check domain availability instantly. Never miss your perfect domain again.'); ?></textarea>
                            </div>
                            <div>
                                <label class="block text-gray-300 text-sm mb-2">Contact Email</label>
                                <input type="email" name="contact_email" value="<?php echo htmlspecialchars($settings['contact_email'] ?? 'hello@checkdomain.top'); ?>" 
                                    class="input-field w-full bg-slate-700 border border-gray-600 rounded-lg py-2.5 px-3 text-white focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div class="flex items-center justify-between py-2">
                                <div>
                                    <label class="block text-gray-300 text-sm mb-1">Maintenance Mode</label>
                                    <p class="text-xs text-gray-500">Disable site access for non-admins</p>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="maintenance_mode" <?php echo ($settings['maintenance_mode'] ?? 0) ? 'checked' : ''; ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <button type="submit" name="save_settings" class="bg-green-600 hover:bg-green-700 px-6 py-2 rounded-lg transition w-full md:w-auto">
                                <i class="fas fa-save mr-2"></i> Save Settings
                            </button>
                        </form>
                    </div>
                    
                    <!-- System Info Card -->
                    <div class="settings-card rounded-xl p-6">
                        <h2 class="text-xl font-semibold mb-4 flex items-center gap-2">
                            <i class="fas fa-info-circle text-yellow-400"></i>
                            System Information
                        </h2>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center py-2 border-b border-gray-700">
                                <span class="text-gray-400">PHP Version</span>
                                <span class="font-mono text-sm"><?php echo phpversion(); ?></span>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-gray-700">
                                <span class="text-gray-400">MySQL Version</span>
                                <span class="font-mono text-sm">
                                    <?php 
                                    $dbConn = getDBConnection();
                                    $version = $dbConn->server_info;
                                    $dbConn->close();
                                    echo $version;
                                    ?>
                                </span>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-gray-700">
                                <span class="text-gray-400">Server Time</span>
                                <span class="font-mono text-sm"><?php echo date('Y-m-d H:i:s'); ?></span>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-gray-700">
                                <span class="text-gray-400">Timezone</span>
                                <span class="font-mono text-sm"><?php echo date_default_timezone_get(); ?></span>
                            </div>
                            <div class="flex justify-between items-center py-2">
                                <span class="text-gray-400">Upload Max Size</span>
                                <span class="font-mono text-sm"><?php echo ini_get('upload_max_filesize'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Danger Zone -->
            <div class="settings-card rounded-xl p-6 border-red-500/30">
                <h2 class="text-xl font-semibold mb-4 flex items-center gap-2 text-red-400">
                    <i class="fas fa-exclamation-triangle"></i>
                    Danger Zone
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="p-4 bg-red-500/10 rounded-lg border border-red-500/30">
                        <h3 class="font-semibold mb-2">Clear Cache</h3>
                        <p class="text-sm text-gray-400 mb-3">Clear all system cache and temporary files</p>
                        <button onclick="clearCache()" class="bg-red-600/50 hover:bg-red-600 px-4 py-2 rounded-lg text-sm transition">
                            <i class="fas fa-trash mr-2"></i> Clear Cache
                        </button>
                    </div>
                    <div class="p-4 bg-yellow-500/10 rounded-lg border border-yellow-500/30">
                        <h3 class="font-semibold mb-2">Backup Database</h3>
                        <p class="text-sm text-gray-400 mb-3">Download a backup of your database</p>
                        <button onclick="backupDatabase()" class="bg-yellow-600/50 hover:bg-yellow-600 px-4 py-2 rounded-lg text-sm transition">
                            <i class="fas fa-download mr-2"></i> Download Backup
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Password strength checker
        const newPassword = document.getElementById('new_password');
        const strengthBar = document.getElementById('passwordStrength');
        const passwordHint = document.getElementById('passwordHint');
        
        newPassword.addEventListener('input', function() {
            const password = this.value;
            let strength = 0;
            let hint = '';
            
            if (password.length >= 8) strength++;
            if (password.match(/[A-Z]/)) strength++;
            if (password.match(/[a-z]/)) strength++;
            if (password.match(/[0-9]/)) strength++;
            if (password.match(/[^a-zA-Z0-9]/)) strength++;
            
            const colors = ['#EF4444', '#F59E0B', '#FBBF24', '#34D399', '#10B981'];
            const widths = ['20%', '40%', '60%', '80%', '100%'];
            const texts = ['Very Weak', 'Weak', 'Medium', 'Strong', 'Very Strong'];
            
            strengthBar.style.width = widths[strength - 1] || '0%';
            strengthBar.style.backgroundColor = colors[strength - 1] || '#EF4444';
            passwordHint.textContent = texts[strength - 1] || 'Enter a password';
            passwordHint.style.color = colors[strength - 1] || '#EF4444';
            
            if (password.length === 0) {
                strengthBar.style.width = '0%';
                passwordHint.textContent = '';
            }
        });
        
        // Clear cache function
        function clearCache() {
            if (confirm('Are you sure you want to clear the system cache? This action cannot be undone.')) {
                fetch('/api/clear-cache.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Cache cleared successfully!');
                        location.reload();
                    } else {
                        alert('Failed to clear cache: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error clearing cache: ' + error);
                });
            }
        }
        
        // Backup database function
        function backupDatabase() {
            if (confirm('Download database backup? This may take a few moments.')) {
                window.location.href = 'backup-database.php';
            }
        }
        
        // Toggle switch animation
        document.querySelectorAll('.toggle-switch input').forEach(toggle => {
            toggle.addEventListener('change', function() {
                console.log(this.checked ? 'Enabled' : 'Disabled');
            });
        });
    </script>
</body>
</html>