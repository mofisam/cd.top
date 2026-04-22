<?php
session_start();
require_once '../config/database.php';

// Check if already logged in
if (isset($_SESSION['admin_id']) && isset($_SESSION['admin_token'])) {
    // Verify session
    $session = verifyAdminSession($_SESSION['admin_token']);
    if ($session) {
        header('Location: stats.php');
        exit();
    } else {
        // Invalid session, clear it
        session_destroy();
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        // Verify credentials
        $user = verifyAdminCredentials($username, $password);
        
        if ($user) {
            // Generate session token
            $sessionToken = bin2hex(random_bytes(32));
            
            // Create session in database
            if (createAdminSession($user['id'], $sessionToken)) {
                // Set session variables
                $_SESSION['admin_id'] = $user['id'];
                $_SESSION['admin_username'] = $user['username'];
                $_SESSION['admin_role'] = $user['role'];
                $_SESSION['admin_token'] = $sessionToken;
                
                // Update last login
                updateLastLogin($user['id']);
                
                // Log activity
                logAdminActivity($user['id'], 'LOGIN', 'User logged in successfully');
                
                header('Location: stats.php');
                exit();
            } else {
                $error = 'Session creation failed. Please try again.';
                logAdminActivity(null, 'SESSION_ERROR', 'Failed to create session for user: ' . $username);
            }
        } else {
            $error = 'Invalid username or password';
            logAdminActivity(null, 'FAILED_LOGIN', "Failed login attempt for username: $username");
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - checkdomain.top</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0F172A 0%, #1E293B 50%, #0B1120 100%);
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center">
    <div class="bg-slate-800/80 backdrop-blur-sm rounded-2xl p-8 w-full max-w-md border border-blue-500/30 shadow-2xl">
        <div class="text-center mb-8">
            <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-green-500 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-lock text-white text-2xl"></i>
            </div>
            <h2 class="text-2xl font-bold text-white">Admin Login</h2>
            <p class="text-gray-400 text-sm mt-2">checkdomain.top Administration</p>
        </div>
        
        <?php if ($error): ?>
            <div class="bg-red-500/20 border border-red-500/50 rounded-lg p-3 mb-6">
                <p class="text-red-300 text-sm flex items-center gap-2">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                </p>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="mb-4">
                <label class="block text-gray-300 text-sm mb-2">Username</label>
                <div class="relative">
                    <i class="fas fa-user absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    <input type="text" name="username" required 
                        class="w-full bg-slate-900/50 border border-blue-500/30 rounded-lg py-2 pl-10 pr-3 text-white focus:outline-none focus:border-blue-400">
                </div>
            </div>
            
            <div class="mb-6">
                <label class="block text-gray-300 text-sm mb-2">Password</label>
                <div class="relative">
                    <i class="fas fa-key absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    <input type="password" name="password" required 
                        class="w-full bg-slate-900/50 border border-blue-500/30 rounded-lg py-2 pl-10 pr-3 text-white focus:outline-none focus:border-blue-400">
                </div>
            </div>
            
            <button type="submit" class="w-full bg-gradient-to-r from-blue-600 to-blue-400 text-white font-semibold py-2 rounded-lg hover:from-blue-500 hover:to-blue-300 transition">
                <i class="fas fa-sign-in-alt mr-2"></i> Login
            </button>
        </form>
        
        <div class="mt-6 text-center text-gray-500 text-xs">
            <p>Secure admin access only</p>
        </div>
    </div>
</body>
</html>