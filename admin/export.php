<?php
require_once 'auth_check.php';
$user = checkAdminAuth();

require_once '../config/database.php';

$conn = getDBConnection();
$result = $conn->query("SELECT email, name, subscribed_at, ip_address, source FROM subscribers WHERE status = 'active' ORDER BY subscribed_at DESC");

// Log export action
logAdminActivity($user['id'], 'EXPORT_SUBSCRIBERS', 'Exported subscriber list to CSV');

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="subscribers_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Email', 'Name', 'Subscribed Date', 'IP Address', 'Source']);

while ($row = $result->fetch_assoc()) {
    fputcsv($output, $row);
}

fclose($output);
$conn->close();
?>