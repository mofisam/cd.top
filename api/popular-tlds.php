<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

require_once ('../config/database.php');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tlds = getPopularTLDs(20);
    echo json_encode(['success' => true, 'tlds' => $tlds]);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>