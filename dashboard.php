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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard - checkdomain.top</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white font-['Inter']">
    <div class="min-h-screen">
        <nav class="bg-slate-800/50 border-b border-blue-500/30 px-6 py-4">
            <div class="max-w-7xl mx-auto flex justify-between items-center">
                <a href="index.php" class="flex items-center gap-2">
                    <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-green-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-globe text-white text-sm"></i>
                    </div>
                    <span class="font-bold text-lg">checkdomain.top</span>
                </a>
                <div class="flex items-center gap-4">
                    <span class="text-sm">Welcome, <?php echo htmlspecialchars($user['full_name'] ?: $user['email']); ?></span>
                    <a href="logout.php" class="text-red-400 hover:text-red-300 transition">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
        </nav>
        
        <div class="max-w-7xl mx-auto px-6 py-8">
            <h1 class="text-3xl font-bold mb-8">My Dashboard</h1>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-slate-800/50 rounded-xl p-6">
                    <h3 class="font-semibold mb-4">Account Info</h3>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
                    <p><strong>Member since:</strong> <?php echo date('M d, Y', strtotime($user['created_at'])); ?></p>
                    <p><strong>Last login:</strong> <?php echo date('M d, Y H:i', strtotime($user['last_login'])); ?></p>
                </div>
                
                <div class="bg-slate-800/50 rounded-xl p-6">
                    <h3 class="font-semibold mb-4">Pinned Domains</h3>
                    <p class="text-gray-400">You haven't pinned any domains yet.</p>
                    <a href="index.php" class="inline-block mt-4 text-blue-400 hover:text-blue-300">Search domains →</a>
                </div>
                
                <div class="bg-slate-800/50 rounded-xl p-6">
                    <h3 class="font-semibold mb-4">Quick Actions</h3>
                    <ul class="space-y-2">
                        <li><a href="index.php" class="text-blue-400 hover:text-blue-300">🔍 Check Domain</a></li>
                        <li><a href="#" class="text-blue-400 hover:text-blue-300">📌 View Pinned Domains</a></li>
                        <li><a href="#" class="text-blue-400 hover:text-blue-300">⚙️ Account Settings</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</body>
</html>