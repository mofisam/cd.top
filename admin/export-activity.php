<?php
require_once 'auth_check.php';
$user = checkAdminAuth();
require_once '../config/database.php';

// Get filters (same as activity.php)
$action_filter = $_GET['action'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Build WHERE clause (same as activity.php)
$whereConditions = [];
$params = [];
$types = "";

if ($action_filter) {
    $whereConditions[] = "a.action LIKE ?";
    $params[] = "%$action_filter%";
    $types .= "s";
}
if ($date_from) {
    $whereConditions[] = "DATE(a.created_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}
if ($date_to) {
    $whereConditions[] = "DATE(a.created_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}
if ($search) {
    $whereConditions[] = "(a.details LIKE ? OR u.username LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

$conn = getDBConnection();
$sql = "SELECT a.*, u.username 
        FROM admin_activity_log a 
        LEFT JOIN admin_users u ON a.user_id = u.id 
        $whereClause
        ORDER BY a.created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$activities = $stmt->get_result();

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="admin_activity_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['ID', 'Admin', 'Action', 'Details', 'IP Address', 'Created At']);

while ($row = $activities->fetch_assoc()) {
    fputcsv($output, [
        $row['id'],
        $row['username'] ?? 'System',
        $row['action'],
        $row['details'],
        $row['ip_address'],
        $row['created_at']
    ]);
}

fclose($output);
$stmt->close();
$conn->close();
?>