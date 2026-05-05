<?php
session_start();
require_once 'lib/Auth.php';

$auth = new Auth();
$error = '';
$success = '';

// Check if already logged in
if (isset($_COOKIE['session_token'])) {
    $session = $auth->verifySession($_COKEN['session_token']);
    if ($session) {
        header('Location: dashboard.php');
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';
    
    if ($action === 'login') {
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'];
        $userAgent = $_SERVER['HTTP_USER_AGENT'];
        
        $result = $auth->login($email, $password, $ip, $userAgent);
        
        if ($result['success']) {
            setcookie('session_token', $result['session_token'], time() + (86400 * 7), '/', '', true, true);
            $_SESSION['user'] = $result['user'];
            header('Location: dashboard.php');
            exit();
        } else {
            $error = $result['message'];
        }
    } elseif ($action === 'register') {
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $fullName = $_POST['full_name'] ?? '';
        
        if ($password !== $confirmPassword) {
            $error = 'Passwords do not match';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters';
        } else {
            $result = $auth->register($email, $password, $fullName);
            if ($result['success']) {
                $success = $result['message'];
            } else {
                $error = $result['message'];
            }
        }
    } elseif ($action === 'forgot') {
        $email = $_POST['email'] ?? '';
        $result = $auth->forgotPassword($email);
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Login / Sign Up - checkdomain.top</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0F172A 0%, #1E293B 50%, #0B1120 100%);
            font-family: 'Inter', sans-serif;
        }
        .glass-card {
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(59, 130, 246, 0.35);
            border-radius: 2rem;
            transition: all 0.3s ease;
        }
        .glass-card:hover {
            border-color: rgba(16, 185, 129, 0.5);
        }
        .input-glow:focus {
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.5);
            border-color: #3B82F6;
            outline: none;
        }
        .social-btn {
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .social-btn:hover {
            transform: translateY(-1px);
        }
        .tab-active {
            border-bottom: 2px solid #3B82F6;
            color: #3B82F6;
        }
        .bg-noise {
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='1' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.025'/%3E%3C/svg%3E");
            pointer-events: none;
        }
    </style>
</head>
<body class="relative text-white">
    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div class="absolute top-[10%] left-[0%] w-96 h-96 bg-blue-600/15 rounded-full blur-3xl animate-float"></div>
        <div class="absolute bottom-[20%] right-[5%] w-80 h-80 bg-green-500/10 rounded-full blur-3xl animate-float" style="animation-delay: 1.8s;"></div>
        <div class="bg-noise absolute inset-0"></div>
    </div>

    <div class="relative z-10 min-h-screen flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-md">
            <!-- Logo -->
            <div class="text-center mb-8">
                <a href="index.php" class="inline-flex items-center gap-3">
                    <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-green-500 rounded-full flex items-center justify-center">
                    <img src="images/logo.png" alt="checkdomain.top" class="custom-logo" onerror="this.src='https://via.placeholder.com/60x60?text=CD'">
                    </div>
                    <h1 class="text-2xl font-bold bg-gradient-to-r from-white via-blue-300 to-green-300 bg-clip-text text-transparent">checkdomain<span class="text-green-400">.</span>top</h1>
                </a>
            </div>

            <!-- Main Card -->
            <div class="glass-card p-6 md:p-8">
                <!-- Tabs -->
                <div class="flex border-b border-gray-700 mb-6">
                    <button class="tab-button flex-1 py-3 text-center font-semibold transition" data-tab="login">
                        Sign In
                    </button>
                    <button class="tab-button flex-1 py-3 text-center font-semibold text-gray-400 transition" data-tab="register">
                        Create Account
                    </button>
                </div>

                <!-- Error/Success Messages -->
                <?php if ($error): ?>
                    <div class="bg-red-500/20 border border-red-500/50 rounded-lg p-3 mb-4">
                        <p class="text-red-300 text-sm"><?php echo htmlspecialchars($error); ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="bg-green-500/20 border border-green-500/50 rounded-lg p-3 mb-4">
                        <p class="text-green-300 text-sm"><?php echo htmlspecialchars($success); ?></p>
                    </div>
                <?php endif; ?>

                <!-- Login Form -->
                <div id="loginForm" class="tab-content">
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="login">
                        <div>
                            <label class="block text-sm font-medium mb-2">Email Address</label>
                            <input type="email" name="email" required 
                                class="w-full bg-slate-800/70 border border-blue-500/40 rounded-xl py-3 px-4 text-white placeholder:text-gray-400 focus:outline-none input-glow">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2">Password</label>
                            <input type="password" name="password" required 
                                class="w-full bg-slate-800/70 border border-blue-500/40 rounded-xl py-3 px-4 text-white placeholder:text-gray-400 focus:outline-none input-glow">
                        </div>
                        <div class="flex justify-end">
                            <button type="button" id="forgotPasswordBtn" class="text-sm text-blue-400 hover:text-blue-300 transition">
                                Forgot Password?
                            </button>
                        </div>
                        <button type="submit" class="w-full bg-gradient-to-r from-blue-600 to-blue-400 hover:from-blue-500 hover:to-blue-300 text-white font-semibold py-3 rounded-xl transition">
                            Sign In
                        </button>
                    </form>
                </div>

                <!-- Register Form -->
                <div id="registerForm" class="tab-content hidden">
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="register">
                        <div>
                            <label class="block text-sm font-medium mb-2">Full Name (Optional)</label>
                            <input type="text" name="full_name" 
                                class="w-full bg-slate-800/70 border border-blue-500/40 rounded-xl py-3 px-4 text-white placeholder:text-gray-400 focus:outline-none input-glow">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2">Email Address *</label>
                            <input type="email" name="email" required 
                                class="w-full bg-slate-800/70 border border-blue-500/40 rounded-xl py-3 px-4 text-white placeholder:text-gray-400 focus:outline-none input-glow">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2">Password *</label>
                            <input type="password" name="password" required 
                                class="w-full bg-slate-800/70 border border-blue-500/40 rounded-xl py-3 px-4 text-white placeholder:text-gray-400 focus:outline-none input-glow">
                            <p class="text-xs text-gray-400 mt-1">Minimum 6 characters</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2">Confirm Password *</label>
                            <input type="password" name="confirm_password" required 
                                class="w-full bg-slate-800/70 border border-blue-500/40 rounded-xl py-3 px-4 text-white placeholder:text-gray-400 focus:outline-none input-glow">
                        </div>
                        <button type="submit" class="w-full bg-gradient-to-r from-green-600 to-emerald-500 hover:from-green-500 hover:to-emerald-400 text-white font-semibold py-3 rounded-xl transition">
                            Create Account
                        </button>
                    </form>
                </div>

                <!-- Forgot Password Form -->
                <div id="forgotForm" class="tab-content hidden">
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="forgot">
                        <p class="text-gray-300 text-sm mb-4">Enter your email address and we'll send you a link to reset your password.</p>
                        <div>
                            <label class="block text-sm font-medium mb-2">Email Address</label>
                            <input type="email" name="email" required 
                                class="w-full bg-slate-800/70 border border-blue-500/40 rounded-xl py-3 px-4 text-white placeholder:text-gray-400 focus:outline-none input-glow">
                        </div>
                        <button type="submit" class="w-full bg-gradient-to-r from-purple-600 to-pink-500 hover:from-purple-500 hover:to-pink-400 text-white font-semibold py-3 rounded-xl transition">
                            Send Reset Link
                        </button>
                        <button type="button" id="backToLogin" class="w-full text-gray-400 hover:text-white text-sm transition">
                            ← Back to Sign In
                        </button>
                    </form>
                </div>

                <!-- Divider -->
                <div class="relative my-6">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-gray-700"></div>
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="px-4 bg-transparent text-gray-400">Or continue with</span>
                    </div>
                </div>

                <!-- Social Login Buttons -->
                <div class="space-y-3">
                    <a href="auth/google.php" class="social-btn w-full bg-white/10 hover:bg-white/20 border border-gray-700 rounded-xl py-3 px-4 flex items-center justify-center gap-3 transition">
                        <i class="fab fa-google text-red-500 text-xl"></i>
                        <span class="font-medium">Continue with Google</span>
                    </a>
                    <a href="auth/facebook.php" class="social-btn w-full bg-white/10 hover:bg-white/20 border border-gray-700 rounded-xl py-3 px-4 flex items-center justify-center gap-3 transition">
                        <i class="fab fa-facebook text-blue-600 text-xl"></i>
                        <span class="font-medium">Continue with Facebook</span>
                    </a>
                    <a href="auth/github.php" class="social-btn w-full bg-white/10 hover:bg-white/20 border border-gray-700 rounded-xl py-3 px-4 flex items-center justify-center gap-3 transition">
                        <i class="fab fa-github text-white text-xl"></i>
                        <span class="font-medium">Continue with GitHub</span>
                    </a>
                </div>
            </div>

            <p class="text-center text-gray-500 text-xs mt-6">
                By continuing, you agree to our Terms of Service and Privacy Policy.
            </p>
        </div>
    </div>

    <style>
        .animate-float {
            animation: float 6s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-12px); }
        }
    </style>

    <script>
        // Tab switching
        const tabs = document.querySelectorAll('.tab-button');
        const contents = {
            login: document.getElementById('loginForm'),
            register: document.getElementById('registerForm'),
            forgot: document.getElementById('forgotForm')
        };

        function switchTab(tab) {
            Object.keys(contents).forEach(key => {
                contents[key].classList.add('hidden');
            });
            contents[tab].classList.remove('hidden');
            
            tabs.forEach(btn => {
                btn.classList.remove('tab-active', 'text-blue-400');
                btn.classList.add('text-gray-400');
            });
            
            if (tab !== 'forgot') {
                const activeBtn = document.querySelector(`[data-tab="${tab}"]`);
                activeBtn.classList.add('tab-active', 'text-blue-400');
                activeBtn.classList.remove('text-gray-400');
            }
        }

        tabs.forEach(btn => {
            btn.addEventListener('click', () => {
                switchTab(btn.dataset.tab);
            });
        });

        document.getElementById('forgotPasswordBtn')?.addEventListener('click', () => {
            switchTab('forgot');
        });

        document.getElementById('backToLogin')?.addEventListener('click', () => {
            switchTab('login');
        });
    </script>
</body>
</html>