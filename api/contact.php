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

// Function to send email notification
function sendContactNotification($to, $subject, $message, $fromName, $fromEmail) {
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: " . $fromName . " <" . $fromEmail . ">" . "\r\n";
    $headers .= "Reply-To: " . $fromEmail . "\r\n";
    
    $emailBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #1E3A8A, #10B981); color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f9f9f9; }
            .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
            .message-box { background: white; padding: 15px; border-left: 4px solid #3B82F6; margin: 15px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>New Contact Message</h2>
                <p>checkdomain.top</p>
            </div>
            <div class='content'>
                <p><strong>Name:</strong> " . htmlspecialchars($fromName) . "</p>
                <p><strong>Email:</strong> " . htmlspecialchars($fromEmail) . "</p>
                <p><strong>Subject:</strong> " . htmlspecialchars($subject) . "</p>
                <div class='message-box'>
                    <p><strong>Message:</strong></p>
                    <p>" . nl2br(htmlspecialchars($message)) . "</p>
                </div>
                <p>You can reply to this message by clicking <a href='mailto:" . htmlspecialchars($fromEmail) . "'>here</a>.</p>
            </div>
            <div class='footer'>
                <p>This message was sent from the contact form on checkdomain.top</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return mail($to, $subject, $emailBody, $headers);
}

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
            // Send email notification to admin (optional)
            $adminEmail = "admin@checkdomain.top"; // Change to your admin email
            $emailSent = sendContactNotification($adminEmail, "New Contact: " . $subject, $message, $name, $email);
            
            echo json_encode([
                'success' => true,
                'message' => 'Thank you for your message! We\'ll get back to you within 24 hours.',
                'message_id' => $stmt->insert_id
            ]);
        } else {
            echo json_encode(['success' => false, 'errors' => ['Failed to send message. Please try again.']]);
        }
        
        $stmt->close();
        $conn->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'errors' => ['Server error: ' . $e->getMessage()]]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'errors' => ['Method not allowed']]);
}
?>