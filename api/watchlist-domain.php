<?php
ob_start(); // Buffer output to prevent any PHP warnings/notices from breaking JSON

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../lib/Auth.php';

function currentWatchlistUser() {
    if (empty($_COOKIE['session_token'])) {
        return false;
    }

    try {
        $auth = new Auth();
        return $auth->verifySession($_COOKIE['session_token']);
    } catch (Exception $e) {
        return false;
    }
}

try {
    $user = currentWatchlistUser();
    if (!$user) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'requiresLogin' => true,
            'message' => 'Please login to add domains to your watchlist.',
        ]);
        exit();
    }

    $conn = getDBConnection();
    ensurePinnedDomainTables($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $conn->prepare("SELECT domain_name, pinned_at FROM pinned_domains WHERE user_id = ? AND status = 'active' ORDER BY pinned_at DESC");
        $stmt->bind_param("i", $user['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();

        $domains = [];
        while ($row = $result->fetch_assoc()) {
            $domains[] = [
                'domain' => $row['domain_name'],
                'watchlistDate' => $row['pinned_at'],
            ];
        }

        $stmt->close();
        $conn->close();

        ob_end_clean();
        echo json_encode(['success' => true, 'domains' => $domains]);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $conn->close();
        ob_end_clean();
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $validation = validateDomain($input['domain'] ?? '');
    if (!$validation['valid']) {
        $conn->close();
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $validation['error']]);
        exit();
    }

    $domain = $validation['domain'];
    $ipAddress = getClientIP();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $stmt = $conn->prepare("
        INSERT INTO pinned_domains (email, user_id, domain_name, status, ip_address, user_agent)
        VALUES (?, ?, ?, 'active', ?, ?)
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            status = 'active',
            pinned_at = CURRENT_TIMESTAMP,
            ip_address = VALUES(ip_address),
            user_agent = VALUES(user_agent)
    ");
    $stmt->bind_param("sisss", $user['email'], $user['user_id'], $domain, $ipAddress, $userAgent);

    if ($stmt->execute()) {
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'domain' => $domain,
            'message' => 'Domain added to your watchlist. You\'ll be notified when it becomes available.',
        ]);
    } else {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to add domain to your watchlist']);
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>