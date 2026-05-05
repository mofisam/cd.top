<?php
require_once '../lib/Auth.php';

$auth = new Auth();
$token = $_GET['token'] ?? '';
$error = '';
$success = '';

if (empty($token)) {
    die('Invalid reset token');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if ($password !== $confirmPassword) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters';
    } else {
        $result = $auth->resetPassword($token, $password);
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
    <title>Reset Password - checkdomain.top</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white font-['Inter']">
    <div class="min-h-screen flex items-center justify-center px-4">
        <div class="max-w-md w-full bg-slate-800/50 rounded-xl p-8">
            <div class="text-center mb-6">
                <i class="fas fa-lock text-blue-400 text-4xl mb-3"></i>
                <h2 class="text-2xl font-bold">Reset Password</h2>
                <p class="text-gray-400 text-sm mt-1">Enter your new password below</p>
            </div>
            
            <?php if ($success): ?>
                <div class="bg-green-500/20 border border-green-500/50 rounded-lg p-4 mb-4">
                    <p class="text-green-300 text-sm"><?php echo htmlspecialchars($success); ?></p>
                </div>
                <a href="../login.php" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg transition block text-center">
                    Go to Login
                </a>
            <?php elseif ($error): ?>
                <div class="bg-red-500/20 border border-red-500/50 rounded-lg p-4 mb-4">
                    <p class="text-red-300 text-sm"><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (!$success): ?>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2">New Password</label>
                    <input type="password" name="password" required 
                        class="w-full bg-slate-700 border border-gray-600 rounded-lg py-3 px-4 text-white focus:outline-none focus:border-blue-500">
                    <p class="text-xs text-gray-400 mt-1">Minimum 6 characters</p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">Confirm New Password</label>
                    <input type="password" name="confirm_password" required 
                        class="w-full bg-slate-700 border border-gray-600 rounded-lg py-3 px-4 text-white focus:outline-none focus:border-blue-500">
                </div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg transition">
                    Reset Password
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>