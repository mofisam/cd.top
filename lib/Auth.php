<?php
require_once __DIR__ . '/../config/database.php';

class Auth {
    private $conn;
    
    public function __construct() {
        $this->conn = getDBConnection();
    }
    
    // Register new user
    public function register($email, $password, $fullName = null) {
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email address'];
        }
        
        // Check if email exists
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => 'Email already registered'];
        }
        
        // Hash password
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $verificationToken = bin2hex(random_bytes(32));
        
        // Insert user
        $stmt = $this->conn->prepare("INSERT INTO users (email, password_hash, full_name, verification_token) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $email, $passwordHash, $fullName, $verificationToken);
        
        if ($stmt->execute()) {
            $userId = $this->conn->insert_id;
            
            // Send verification email
            $this->sendVerificationEmail($email, $verificationToken, $fullName);
            
            return ['success' => true, 'message' => 'Registration successful! Please verify your email.', 'user_id' => $userId];
        }
        
        return ['success' => false, 'message' => 'Registration failed'];
    }
    
    // Login user
    public function login($email, $password, $ip, $userAgent) {
        $stmt = $this->conn->prepare("SELECT id, email, full_name, password_hash, status, role, email_verified FROM users WHERE email = ? AND provider = 'local'");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $this->logLoginAttempt(null, $email, $ip, $userAgent, false);
            return ['success' => false, 'message' => 'Invalid credentials'];
        }
        
        $user = $result->fetch_assoc();
        
        if ($user['status'] !== 'active') {
            return ['success' => false, 'message' => 'Account is not active'];
        }
        
        if (!$user['email_verified']) {
            return ['success' => false, 'message' => 'Please verify your email first'];
        }
        
        if (!password_verify($password, $user['password_hash'])) {
            $this->logLoginAttempt($user['id'], $email, $ip, $userAgent, false);
            return ['success' => false, 'message' => 'Invalid credentials'];
        }
        
        // Update last login
        $updateStmt = $this->conn->prepare("UPDATE users SET last_login = NOW(), last_ip = ?, login_count = login_count + 1 WHERE id = ?");
        $updateStmt->bind_param("si", $ip, $user['id']);
        $updateStmt->execute();
        
        // Create session
        $sessionToken = $this->createSession($user['id'], $ip, $userAgent);
        
        $this->logLoginAttempt($user['id'], $email, $ip, $userAgent, true);
        
        return [
            'success' => true, 
            'message' => 'Login successful',
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['full_name'],
                'role' => $user['role']
            ],
            'session_token' => $sessionToken
        ];
    }
    
    // Social login/register
    public function socialLogin($provider, $providerId, $email, $name, $avatar = null, $ip, $userAgent) {
        // Check if user exists
        $stmt = $this->conn->prepare("SELECT id, email, full_name, role FROM users WHERE provider = ? AND provider_id = ?");
        $stmt->bind_param("ss", $provider, $providerId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Existing user
            $user = $result->fetch_assoc();
            $updateStmt = $this->conn->prepare("UPDATE users SET last_login = NOW(), last_ip = ?, login_count = login_count + 1 WHERE id = ?");
            $updateStmt->bind_param("si", $ip, $user['id']);
            $updateStmt->execute();
        } else {
            // Check if email exists with local account
            $emailStmt = $this->conn->prepare("SELECT id FROM users WHERE email = ? AND provider = 'local'");
            $emailStmt->bind_param("s", $email);
            $emailStmt->execute();
            if ($emailStmt->get_result()->num_rows > 0) {
                return ['success' => false, 'message' => 'Email already registered with password. Please login with password.'];
            }
            
            // Create new user
            $insertStmt = $this->conn->prepare("INSERT INTO users (email, full_name, avatar, provider, provider_id, email_verified, last_login, last_ip) VALUES (?, ?, ?, ?, ?, 1, NOW(), ?)");
            $insertStmt->bind_param("ssssss", $email, $name, $avatar, $provider, $providerId, $ip);
            $insertStmt->execute();
            $userId = $this->conn->insert_id;
            $user = ['id' => $userId, 'email' => $email, 'full_name' => $name, 'role' => 'user'];
        }
        
        // Create session
        $sessionToken = $this->createSession($user['id'], $ip, $userAgent);
        $this->logLoginAttempt($user['id'], $email, $ip, $userAgent, true);
        
        return [
            'success' => true,
            'message' => 'Login successful',
            'user' => $user,
            'session_token' => $sessionToken
        ];
    }
    
    // Create user session
    private function createSession($userId, $ip, $userAgent) {
        $sessionToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        
        // Detect device type
        $deviceType = $this->getDeviceType($userAgent);
        $browser = $this->getBrowser($userAgent);
        $os = $this->getOS($userAgent);
        
        $stmt = $this->conn->prepare("INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, device_type, browser, os, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssssss", $userId, $sessionToken, $ip, $userAgent, $deviceType, $browser, $os, $expiresAt);
        $stmt->execute();
        
        return $sessionToken;
    }
    
    // Verify session
    public function verifySession($sessionToken) {
        $stmt = $this->conn->prepare("SELECT s.*, u.id as user_id, u.email, u.full_name, u.role, u.status FROM user_sessions s JOIN users u ON s.user_id = u.id WHERE s.session_token = ? AND s.is_active = 1 AND s.expires_at > NOW() AND u.status = 'active'");
        $stmt->bind_param("s", $sessionToken);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        return false;
    }
    
    // Logout
    public function logout($sessionToken) {
        $stmt = $this->conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE session_token = ?");
        $stmt->bind_param("s", $sessionToken);
        return $stmt->execute();
    }
    
    // Verify email
    public function verifyEmail($token) {
        $stmt = $this->conn->prepare("UPDATE users SET email_verified = 1, verification_token = NULL WHERE verification_token = ?");
        $stmt->bind_param("s", $token);
        return $stmt->execute() && $stmt->affected_rows > 0;
    }
    
    // Send verification email
    private function sendVerificationEmail($email, $token, $name) {
        $verificationLink = "https://checkdomain.top/auth/verify.php?token=" . $token;
        $subject = "Verify your email - checkdomain.top";
        $message = "
        <html>
        <body>
            <h2>Welcome to checkdomain.top!</h2>
            <p>Hi " . htmlspecialchars($name ?: $email) . ",</p>
            <p>Please click the link below to verify your email address:</p>
            <p><a href='$verificationLink'>$verificationLink</a></p>
            <p>This link will expire in 24 hours.</p>
            <p>Best regards,<br>checkdomain.top Team</p>
        </body>
        </html>
        ";
        
        // Use SMTP to send email
        require_once __DIR__ . '/../config/email.php';
        sendEmail($email, $subject, $message);
    }
    
    // Forgot password
    public function forgotPassword($email) {
        $resetToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        $stmt = $this->conn->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE email = ? AND provider = 'local'");
        $stmt->bind_param("sss", $resetToken, $expiresAt, $email);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $resetLink = "https://checkdomain.top/auth/reset-password.php?token=" . $resetToken;
            $subject = "Reset your password - checkdomain.top";
            $message = "
            <html>
            <body>
                <h2>Password Reset Request</h2>
                <p>Click the link below to reset your password:</p>
                <p><a href='$resetLink'>$resetLink</a></p>
                <p>This link will expire in 1 hour.</p>
                <p>If you didn't request this, ignore this email.</p>
            </body>
            </html>
            ";
            
            require_once __DIR__ . '/../config/email.php';
            sendEmail($email, $subject, $message);
            return ['success' => true, 'message' => 'Reset link sent to your email'];
        }
        return ['success' => false, 'message' => 'Email not found'];
    }
    
    // Reset password
    public function resetPassword($token, $newPassword) {
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > NOW()");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return ['success' => false, 'message' => 'Invalid or expired token'];
        }
        
        $user = $result->fetch_assoc();
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $updateStmt = $this->conn->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?");
        $updateStmt->bind_param("si", $passwordHash, $user['id']);
        $updateStmt->execute();
        
        return ['success' => true, 'message' => 'Password reset successful'];
    }
    
    // Get user by ID
    public function getUserById($id) {
        $stmt = $this->conn->prepare("SELECT id, email, full_name, avatar, role, created_at, last_login FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    
    // Log login attempt
    private function logLoginAttempt($userId, $email, $ip, $userAgent, $success) {
        $stmt = $this->conn->prepare("INSERT INTO login_history (user_id, login_type, ip_address, user_agent, success) VALUES (?, 'email', ?, ?, ?)");
        $stmt->bind_param("isss", $userId, $ip, $userAgent, $success);
        $stmt->execute();
    }
    
    // Device detection helpers
    private function getDeviceType($userAgent) {
        if (strpos($userAgent, 'Mobile') !== false) return 'Mobile';
        if (strpos($userAgent, 'Tablet') !== false) return 'Tablet';
        return 'Desktop';
    }
    
    private function getBrowser($userAgent) {
        if (strpos($userAgent, 'Chrome') !== false) return 'Chrome';
        if (strpos($userAgent, 'Firefox') !== false) return 'Firefox';
        if (strpos($userAgent, 'Safari') !== false) return 'Safari';
        if (strpos($userAgent, 'Edge') !== false) return 'Edge';
        return 'Other';
    }
    
    private function getOS($userAgent) {
        if (strpos($userAgent, 'Windows') !== false) return 'Windows';
        if (strpos($userAgent, 'Mac') !== false) return 'macOS';
        if (strpos($userAgent, 'Linux') !== false) return 'Linux';
        if (strpos($userAgent, 'Android') !== false) return 'Android';
        if (strpos($userAgent, 'iOS') !== false) return 'iOS';
        return 'Other';
    }
}
?>