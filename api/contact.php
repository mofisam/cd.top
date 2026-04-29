<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../includes/email.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $subject = $_POST['subject'] ?? '';
        $message = $_POST['message'] ?? '';
    } else {
        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $subject = trim($input['subject'] ?? '');
        $message = trim($input['message'] ?? '');
    }
    
    // Validation
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Name is required";
    } elseif (strlen($name) < 2) {
        $errors[] = "Name must be at least 2 characters";
    }
    
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address";
    }
    
    if (empty($subject)) {
        $errors[] = "Subject is required";
    } elseif (strlen($subject) < 3) {
        $errors[] = "Subject must be at least 3 characters";
    }
    
    if (empty($message)) {
        $errors[] = "Message is required";
    } elseif (strlen($message) < 10) {
        $errors[] = "Message must be at least 10 characters";
    }
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit();
    }
    
    try {
        $conn = getDBConnection();
        $ipAddress = getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $stmt = $conn->prepare("INSERT INTO contact_messages (name, email, subject, message, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $name, $email, $subject, $message, $ipAddress, $userAgent);
        
        if ($stmt->execute()) {
            $messageId = $stmt->insert_id;
            
            // Send email notification to admin via SMTP
            $adminEmailResult = sendContactNotification($name, $email, $subject, $message);
            
            // Send auto-reply to user
            $autoReplyResult = sendAutoReply($name, $email, $subject);
            
            $emailStatus = [];
            if ($adminEmailResult['success']) {
                $emailStatus[] = "Admin notified";
            } else {
                error_log("Admin email failed: " . ($adminEmailResult['error'] ?? 'Unknown error'));
            }
            
            if ($autoReplyResult['success']) {
                $emailStatus[] = "Auto-reply sent";
            } else {
                error_log("Auto-reply failed: " . ($autoReplyResult['error'] ?? 'Unknown error'));
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Thank you for your message! We\'ll get back to you within 24 hours.',
                'message_id' => $messageId,
                'email_status' => implode(', ', $emailStatus)
            ]);
        } else {
            echo json_encode(['success' => false, 'errors' => ['Failed to send message. Please try again.']]);
        }
        
        $stmt->close();
        $conn->close();
    } catch (Exception $e) {
        error_log("Contact form error: " . $e->getMessage());
        echo json_encode(['success' => false, 'errors' => ['Server error. Please try again later.']]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'errors' => ['Method not allowed']]);
}
?>