<?php
require_once '../lib/Auth.php';

$auth = new Auth();
$token = $_GET['token'] ?? '';

if (empty($token)) {
    die('Invalid verification token');
}

$success = $auth->verifyEmail($token);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - checkdomain.top</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-gray-900 text-white font-['Inter']">
    <div class="min-h-screen flex items-center justify-center px-4">
        <div class="max-w-md w-full text-center">
            <?php if ($success): ?>
                <div class="bg-green-500/20 border border-green-500/50 rounded-xl p-8">
                    <i class="fas fa-check-circle text-green-400 text-5xl mb-4"></i>
                    <h2 class="text-2xl font-bold mb-2">Email Verified!</h2>
                    <p class="text-gray-300 mb-6">Your email has been successfully verified. You can now log in to your account.</p>
                    <a href="../login.php" class="inline-block bg-blue-600 hover:bg-blue-700 px-6 py-3 rounded-lg transition">
                        Login Now
                    </a>
                </div>
            <?php else: ?>
                <div class="bg-red-500/20 border border-red-500/50 rounded-xl p-8">
                    <i class="fas fa-exclamation-triangle text-red-400 text-5xl mb-4"></i>
                    <h2 class="text-2xl font-bold mb-2">Verification Failed</h2>
                    <p class="text-gray-300 mb-6">The verification link is invalid or has expired. Please try registering again.</p>
                    <a href="../login.php" class="inline-block bg-blue-600 hover:bg-blue-700 px-6 py-3 rounded-lg transition">
                        Back to Login
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>