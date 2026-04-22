<?php
// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database configuration
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        // Try to get from $_POST if JSON parsing failed
        $email = $_POST['email'] ?? '';
        $name = $_POST['name'] ?? '';
        $source = $_POST['source'] ?? 'website';
    } else {
        $email = trim($input['email'] ?? '');
        $name = trim($input['name'] ?? '');
        $source = trim($input['source'] ?? 'website');
    }
    
    // Validate email
    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Email address is required']);
        exit();
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address']);
        exit();
    }
    
    try {
        $conn = getDBConnection();
        $ipAddress = getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Check if email already exists
        $checkStmt = $conn->prepare("SELECT id, status FROM subscribers WHERE email = ?");
        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows > 0) {
            $subscriber = $result->fetch_assoc();
            if ($subscriber['status'] === 'active') {
                echo json_encode(['success' => false, 'message' => 'This email is already subscribed!']);
                $checkStmt->close();
                $conn->close();
                exit();
            } else {
                // Reactivate subscription
                $updateStmt = $conn->prepare("UPDATE subscribers SET status = 'active', ip_address = ?, user_agent = ? WHERE email = ?");
                $updateStmt->bind_param("sss", $ipAddress, $userAgent, $email);
                $updateStmt->execute();
                $updateStmt->close();
                
                echo json_encode(['success' => true, 'message' => 'Welcome back! You\'re resubscribed.']);
                $checkStmt->close();
                $conn->close();
                exit();
            }
        }
        $checkStmt->close();
        
        // Insert new subscriber
        $stmt = $conn->prepare("INSERT INTO subscribers (email, name, ip_address, user_agent, source) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $email, $name, $ipAddress, $userAgent, $source);
        
        if ($stmt->execute()) {
            // Update daily stats
            updateDailyStats();
            
            echo json_encode([
                'success' => true, 
                'message' => '🎉 Thanks for subscribing! We\'ll notify you when we launch.',
                'subscriber_id' => $stmt->insert_id
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
        }
        
        $stmt->close();
        $conn->close();
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>