<?php
require_once 'auth_check.php';
$user = checkAdminAuth();
require_once '../';

$testResult = null;

if (isset($_POST['test_smtp'])) {
    $testResult = testSMTPConnection();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test SMTP - checkdomain.top Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white font-['Inter']">
    <div class="flex h-screen">
        <!-- Sidebar (same as other admin pages) -->
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
                    <a href="messages.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition">
                        <i class="fas fa-envelope w-5"></i>
                        <span>Messages</span>
                    </a>
                    <a href="test-smtp.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-lg text-gray-300 hover:text-white transition bg-blue-500/20">
                        <i class="fas fa-envelope w-5"></i>
                        <span>Test SMTP</span>
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
                <div class="max-w-2xl mx-auto">
                    <div class="mb-8">
                        <h1 class="text-3xl font-bold">SMTP Configuration Test</h1>
                        <p class="text-gray-400 mt-1">Test your email settings before going live</p>
                    </div>
                    
                    <?php if ($testResult): ?>
                        <div class="mb-6 p-4 rounded-lg <?php echo $testResult['success'] ? 'bg-green-500/20 border border-green-500/50' : 'bg-red-500/20 border border-red-500/50'; ?>">
                            <div class="flex items-center gap-3">
                                <i class="fas <?php echo $testResult['success'] ? 'fa-check-circle text-green-400' : 'fa-exclamation-triangle text-red-400'; ?> text-xl"></i>
                                <div>
                                    <p class="font-semibold <?php echo $testResult['success'] ? 'text-green-400' : 'text-red-400'; ?>">
                                        <?php echo $testResult['success'] ? 'Connection Successful!' : 'Connection Failed!'; ?>
                                    </p>
                                    <p class="text-sm text-gray-300 mt-1">
                                        <?php echo $testResult['success'] ? $testResult['message'] : $testResult['error']; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="bg-slate-800/50 rounded-xl p-6">
                        <h2 class="text-xl font-semibold mb-4">Current SMTP Settings</h2>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center p-3 bg-slate-700/50 rounded-lg">
                                <span class="text-gray-400">Host:</span>
                                <span class="font-mono text-sm"><?php echo SMTP_HOST; ?></span>
                            </div>
                            <div class="flex justify-between items-center p-3 bg-slate-700/50 rounded-lg">
                                <span class="text-gray-400">Port:</span>
                                <span class="font-mono text-sm"><?php echo SMTP_PORT; ?></span>
                            </div>
                            <div class="flex justify-between items-center p-3 bg-slate-700/50 rounded-lg">
                                <span class="text-gray-400">Username:</span>
                                <span class="font-mono text-sm"><?php echo SMTP_USER; ?></span>
                            </div>
                            <div class="flex justify-between items-center p-3 bg-slate-700/50 rounded-lg">
                                <span class="text-gray-400">Encryption:</span>
                                <span class="font-mono text-sm"><?php echo strtoupper(SMTP_SECURE); ?></span>
                            </div>
                            <div class="flex justify-between items-center p-3 bg-slate-700/50 rounded-lg">
                                <span class="text-gray-400">From Email:</span>
                                <span class="font-mono text-sm"><?php echo SMTP_FROM_EMAIL; ?></span>
                            </div>
                            <div class="flex justify-between items-center p-3 bg-slate-700/50 rounded-lg">
                                <span class="text-gray-400">Admin Email:</span>
                                <span class="font-mono text-sm"><?php echo SMTP_ADMIN_EMAIL; ?></span>
                            </div>
                        </div>
                        
                        <form method="POST" class="mt-6">
                            <button type="submit" name="test_smtp" class="btn-primary text-white font-semibold px-6 py-2 rounded-lg transition w-full">
                                <i class="fas fa-paper-plane mr-2"></i>
                                Test SMTP Connection
                            </button>
                        </form>
                        
                        <div class="mt-6 p-4 bg-yellow-500/10 border border-yellow-500/30 rounded-lg">
                            <h3 class="font-semibold text-yellow-400 mb-2">📝 Important Notes:</h3>
                            <ul class="text-xs text-gray-300 space-y-1">
                                <li>• For Gmail: Use an <strong>App Password</strong>, NOT your regular password</li>
                                <li>• Get Gmail App Password: Google Account → Security → 2-Step Verification → App Passwords</li>
                                <li>• For other providers: Use your SMTP credentials from your email hosting</li>
                                <li>• Make sure your hosting provider allows outbound SMTP connections</li>
                                <li>• Contact your host if port 587 is blocked</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <style>
        .btn-primary {
            background: linear-gradient(135deg, #2563EB 0%, #3B82F6 100%);
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #3B82F6 0%, #60A5FA 100%);
        }
    </style>
</body>
</html>