<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    $domain = trim($input['domain'] ?? '');
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid email is required']);
        exit();
    }
    
    if (empty($domain)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Domain name is required']);
        exit();
    }
    
    $conn = getDBConnection();
    
    // Check if subscriber exists
    $checkStmt = $conn->prepare("SELECT id FROM subscribers WHERE email = ? AND status = 'active'");
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Please subscribe first to pin domains']);
        $checkStmt->close();
        $conn->close();
        exit();
    }
    
    // Insert or update pinned domain
    $stmt = $conn->prepare("INSERT INTO pinned_domains (email, domain_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE status = 'active', pinned_at = CURRENT_TIMESTAMP");
    $stmt->bind_param("ss", $email, $domain);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Domain pinned successfully! You\'ll be notified when it becomes available.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to pin domain']);
    }
    
    $stmt->close();
    $conn->close();
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>