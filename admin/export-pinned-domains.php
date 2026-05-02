<?php
require_once 'auth_check.php';
$user = checkAdminAuth();
require_once '../config/database.php';

$conn = getDBConnection();

// Get filters
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$format = $_GET['format'] ?? 'csv'; // csv, excel

// Build WHERE clause
$whereConditions = [];
$params = [];
$types = "";

if (!empty($search)) {
    $whereConditions[] = "(pd.domain_name LIKE ? OR s.email LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ss";
}

if (!empty($status)) {
    $whereConditions[] = "pd.status = ?";
    $params[] = $status;
    $types .= "s";
}

if (!empty($date_from)) {
    $whereConditions[] = "DATE(pd.pinned_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $whereConditions[] = "DATE(pd.pinned_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Fetch data
$query = "
    SELECT 
        pd.id,
        pd.domain_name,
        pd.email,
        s.name as subscriber_name,
        pd.pinned_at,
        pd.status,
        s.subscribed_at,
        s.source,
        s.ip_address,
        s.user_agent
    FROM pinned_domains pd 
    LEFT JOIN subscribers s ON pd.email = s.email 
    $whereClause
    ORDER BY pd.pinned_at DESC
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Get summary statistics
$totalRecords = $result->num_rows;
$statusSummary = [];
$domainSummary = [];

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
    
    // Count statuses
    $statusSummary[$row['status']] = ($statusSummary[$row['status']] ?? 0) + 1;
    
    // Extract TLD for summary
    $parts = explode('.', $row['domain_name']);
    $tld = end($parts);
    $domainSummary[$tld] = ($domainSummary[$tld] ?? 0) + 1;
}

// Reset result pointer for CSV generation
if ($format === 'excel') {
    // For Excel, we'll use HTML table format
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="pinned_domains_export_' . date('Y-m-d') . '.xls"');
    
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<title>Pinned Domains Export</title>';
    echo '<style>';
    echo 'th { background-color: #3B82F6; color: white; padding: 8px; }';
    echo 'td { padding: 6px; border-bottom: 1px solid #ccc; }';
    echo 'table { border-collapse: collapse; width: 100%; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // Add summary section
    echo '<h2>Pinned Domains Export Report</h2>';
    echo '<p>Generated on: ' . date('Y-m-d H:i:s') . '</p>';
    echo '<p>Total Records: ' . $totalRecords . '</p>';
    
    if (!empty($search)) echo '<p>Search Filter: ' . htmlspecialchars($search) . '</p>';
    if (!empty($status)) echo '<p>Status Filter: ' . htmlspecialchars($status) . '</p>';
    if (!empty($date_from)) echo '<p>Date From: ' . htmlspecialchars($date_from) . '</p>';
    if (!empty($date_to)) echo '<p>Date To: ' . htmlspecialchars($date_to) . '</p>';
    
    // Add summary charts
    if (!empty($statusSummary)) {
        echo '<h3>Status Summary</h3>';
        echo '<table border="1">';
        echo '<tr><th>Status</th><th>Count</th></tr>';
        foreach ($statusSummary as $stat => $count) {
            echo '<tr><td>' . ucfirst($stat) . '</td><td>' . $count . '</td></tr>';
        }
        echo '</table><br>';
    }
    
    if (!empty($domainSummary)) {
        echo '<h3>Top TLDs Summary</h3>';
        echo '<table border="1">';
        echo '<tr><th>TLD</th><th>Count</th></tr>';
        arsort($domainSummary);
        $topTlds = array_slice($domainSummary, 0, 10);
        foreach ($topTlds as $tld => $count) {
            echo '<tr><td>.' . $tld . '</td><td>' . $count . '</td></tr>';
        }
        echo '</table><br>';
    }
    
    // Main data table
    echo '<h3>Detailed Data</h3>';
    echo '<table border="1">';
    echo '<tr>';
    echo '<th>ID</th>';
    echo '<th>Domain Name</th>';
    echo '<th>Subscriber Email</th>';
    echo '<th>Subscriber Name</th>';
    echo '<th>Pinned Date</th>';
    echo '<th>Status</th>';
    echo '<th>Subscriber Since</th>';
    echo '<th>Source</th>';
    echo '<th>IP Address</th>';
    echo '</tr>';
    
    foreach ($data as $row) {
        echo '<tr>';
        echo '<td>' . $row['id'] . '</td>';
        echo '<td>' . htmlspecialchars($row['domain_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['email']) . '</td>';
        echo '<td>' . htmlspecialchars($row['subscriber_name'] ?? 'N/A') . '</td>';
        echo '<td>' . date('Y-m-d H:i:s', strtotime($row['pinned_at'])) . '</td>';
        echo '<td>' . ucfirst($row['status']) . '</td>';
        echo '<td>' . ($row['subscribed_at'] ? date('Y-m-d H:i:s', strtotime($row['subscribed_at'])) : 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($row['source'] ?? 'N/A') . '</td>';
        echo '<td>' . ($row['ip_address'] ?? 'N/A') . '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    echo '</body></html>';
    
} else {
    // CSV format (default)
    $filename = "pinned_domains_export_" . date('Y-m-d_H-i-s') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add headers
    fputcsv($output, [
        'ID',
        'Domain Name',
        'TLD',
        'Subscriber Email',
        'Subscriber Name',
        'Pinned Date',
        'Pinned Time',
        'Status',
        'Subscriber Since',
        'Subscriber Source',
        'Subscriber IP',
        'User Agent'
    ]);
    
    // Add data rows
    foreach ($data as $row) {
        $parts = explode('.', $row['domain_name']);
        $tld = end($parts);
        
        fputcsv($output, [
            $row['id'],
            $row['domain_name'],
            $tld,
            $row['email'],
            $row['subscriber_name'] ?? 'N/A',
            date('Y-m-d', strtotime($row['pinned_at'])),
            date('H:i:s', strtotime($row['pinned_at'])),
            ucfirst($row['status']),
            $row['subscribed_at'] ? date('Y-m-d H:i:s', strtotime($row['subscribed_at'])) : 'N/A',
            $row['source'] ?? 'N/A',
            $row['ip_address'] ?? 'N/A',
            substr($row['user_agent'] ?? 'N/A', 0, 100) // Truncate long user agents
        ]);
    }
    
    fclose($output);
}

// Log the export action
logAdminActivity($user['id'], 'EXPORT_PINNED_DOMAINS', 
    "Exported $totalRecords pinned domains to " . strtoupper($format) . 
    ($search ? " with search: $search" : "") .
    ($status ? " with status: $status" : "")
);

$conn->close();
exit();
?>