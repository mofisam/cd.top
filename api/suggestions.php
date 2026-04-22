<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $query = $_GET['q'] ?? '';
    $type = $_GET['type'] ?? 'domains'; // domains, tlds, popular
    
    $suggestions = [];
    
    if ($type === 'popular') {
        // Get popular searches
        $popular = getPopularSearches(10);
        foreach ($popular as $search) {
            $suggestions[] = [
                'text' => $search['search_term'],
                'count' => $search['search_count'],
                'type' => 'popular'
            ];
        }
    } elseif ($type === 'tlds') {
        // Get popular TLDs
        $tlds = getPopularTLDs(20);
        foreach ($tlds as $tld) {
            $suggestions[] = [
                'text' => $tld['tld'],
                'name' => $tld['name'],
                'type' => 'tld'
            ];
        }
    } elseif (!empty($query)) {
        // Get domain suggestions based on query
        $conn = getDBConnection();
        
        // Search in popular searches
        $stmt = $conn->prepare("SELECT search_term, search_count FROM popular_searches WHERE search_term LIKE CONCAT('%', ?, '%') ORDER BY search_count DESC LIMIT 5");
        $stmt->bind_param("s", $query);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $suggestions[] = [
                'text' => $row['search_term'],
                'type' => 'popular',
                'count' => $row['search_count']
            ];
        }
        $stmt->close();
        $conn->close();
        
        // Add common domain completions
        $commonDomains = ['google', 'facebook', 'amazon', 'microsoft', 'apple', 'netflix', 'spotify'];
        foreach ($commonDomains as $domain) {
            if (stripos($domain, $query) === 0 && count($suggestions) < 8) {
                $suggestions[] = [
                    'text' => $domain . '.com',
                    'type' => 'suggestion'
                ];
            }
        }
    }
    
    echo json_encode(['success' => true, 'suggestions' => $suggestions]);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>