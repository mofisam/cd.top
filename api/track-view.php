<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

// Track page view
$pageUrl = $_SERVER['HTTP_REFERER'] ?? $_GET['page'] ?? 'homepage';
trackPageView($pageUrl);

// Return basic stats for display (optional)
$conn = getDBConnection();
$today = date('Y-m-d');

// Get today's stats
$statsStmt = $conn->prepare("SELECT total_views, unique_visitors, new_subscribers FROM daily_stats WHERE stat_date = ?");
$statsStmt->bind_param("s", $today);
$statsStmt->execute();
$statsResult = $statsStmt->get_result();
$stats = $statsResult->fetch_assoc();

$response = [
    'success' => true,
    'views_tracked' => true,
    'stats' => $stats ?: ['total_views' => 0, 'unique_visitors' => 0, 'new_subscribers' => 0]
];

echo json_encode($response);

$statsStmt->close();
$conn->close();
?>