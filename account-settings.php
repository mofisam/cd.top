<?php
session_start();
require_once 'lib/Auth.php';

$auth = new Auth();

// Check if user is logged in
if (!isset($_COOKIE['session_token'])) {
    header('Location: login.php');
    exit();
}

$session = $auth->verifySession($_COOKIE['session_token']);
if (!$session) {
    setcookie('session_token', '', time() - 3600, '/');
    header('Location: login.php');
    exit();
}

$user = $auth->getUserById($session['user_id']);
$error = '';
$success = '';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if ($newPassword !== $confirmPassword) {
        $error = 'New passwords do not match';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Password must be at least 6 characters';
    } else {
        // Verify current password and update
        $result = $auth->changePassword($session['user_id'], $currentPassword, $newPassword);
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - checkdomain.top</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white font-['Inter']">
    <nav class="bg-slate-800/50 border-b border-blue-500/30 px-6 py-4">
        <div class="max-w-7xl mx-auto flex justify-between items-center">
            <a href="dashboard.php" class="flex items-center gap-2">
                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-green-500 rounded-lg flex items-center justify-center">
                    <i class="fas fa-globe text-white text-sm"></i>
                </div>
                <span class="font-bold text-lg">checkdomain.top</span>
            </a>
            <a href="dashboard.php" class="text-gray-400 hover:text-white transition">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </nav>
    
    <div class="max-w-2xl mx-auto px-6 py-12">
        <div class="bg-slate-800/50 rounded-xl p-8">
            <div class="text-center mb-8">
                <div class="w-20 h-20 bg-gradient-to-br from-blue-500 to-green-500 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-user-cog text-white text-3xl"></i>
                </div>
                <h1 class="text-2xl font-bold">Account Settings</h1>
                <p class="text-gray-400 text-sm mt-1">Manage your account preferences</p>
            </div>
            
            <?php if ($success): ?>
                <div class="bg-green-500/20 border border-green-500/50 rounded-lg p-3 mb-6">
                    <p class="text-green-300 text-sm"><?php echo htmlspecialchars($success); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="bg-red-500/20 border border-red-500/50 rounded-lg p-3 mb-6">
                    <p class="text-red-300 text-sm"><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Account Info -->
            <div class="border-b border-gray-700 pb-6 mb-6">
                <h2 class="text-lg font-semibold mb-4">Account Information</h2>
                <div class="space-y-3">
                    <div>
                        <label class="text-gray-400 text-sm">Email Address</label>
                        <p class="font-medium"><?php echo htmlspecialchars($user['email']); ?></p>
                    </div>
                    <div>
                        <label class="text-gray-400 text-sm">Full Name</label>
                        <p class="font-medium"><?php echo htmlspecialchars($user['full_name'] ?: 'Not set'); ?></p>
                    </div>
                    <div>
                        <label class="text-gray-400 text-sm">Member Since</label>
                        <p class="font-medium"><?php echo date('F d, Y', strtotime($user['created_at'])); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Change Password -->
            <?php if ($user['provider'] === 'local'): ?>
            <div>
                <h2 class="text-lg font-semibold mb-4">Change Password</h2>
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-2">Current Password</label>
                        <input type="password" name="current_password" required 
                            class="w-full bg-slate-700 border border-gray-600 rounded-lg py-2 px-3 text-white focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2">New Password</label>
                        <input type="password" name="new_password" required 
                            class="w-full bg-slate-700 border border-gray-600 rounded-lg py-2 px-3 text-white focus:outline-none focus:border-blue-500">
                        <p class="text-xs text-gray-400 mt-1">Minimum 6 characters</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2">Confirm New Password</label>
                        <input type="password" name="confirm_password" required 
                            class="w-full bg-slate-700 border border-gray-600 rounded-lg py-2 px-3 text-white focus:outline-none focus:border-blue-500">
                    </div>
                    <button type="submit" name="change_password" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2 rounded-lg transition">
                        Update Password
                    </button>
                </form>
            </div>
            <?php else: ?>
            <div class="bg-blue-500/10 border border-blue-500/30 rounded-lg p-4">
                <p class="text-sm text-blue-300">
                    <i class="fas fa-info-circle mr-2"></i>
                    You're logged in with <?php echo ucfirst($user['provider']); ?>. To change your password, please use your <?php echo ucfirst($user['provider']); ?> account settings.
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>