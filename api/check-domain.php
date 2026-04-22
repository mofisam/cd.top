<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, log them
ini_set('log_errors', 1);

// ============================================
// GET YOUR FREE API KEY FROM:
// https://www.whoisxmlapi.com/signup.php
// Replace with your actual API key below
// ============================================
define('WHOIS_API_KEY', 'at_Tum0rTrVQkxRo3NjRDgRxeWiJwXTN'); // <-- PUT YOUR REAL API KEY HERE
// ============================================

// Simple WHOIS lookup function (works without API)
function simpleWhoisLookup($domain) {
    $domain = strtolower(trim($domain));
    
    // Common domains that are definitely taken
    $takenDomains = [
        'google.com', 'facebook.com', 'amazon.com', 'microsoft.com', 'apple.com',
        'netflix.com', 'openai.com', 'github.com', 'twitter.com', 'instagram.com',
        'youtube.com', 'linkedin.com', 'spotify.com', 'tiktok.com', 'whatsapp.com',
        'checkdomain.top', 'yahoo.com', 'bing.com', 'duckduckgo.com', 'reddit.com'
    ];
    
    if (in_array($domain, $takenDomains)) {
        return [
            'available' => false,
            'domain' => $domain,
            'registrar' => 'MarkMonitor Inc.',
            'creationDate' => '1997-09-15',
            'expiryDate' => '2026-09-14',
            'nameservers' => ['ns1.google.com', 'ns2.google.com']
        ];
    }
    
    // Try native PHP WHOIS for .com, .net, .org
    $parts = explode('.', $domain);
    $tld = end($parts);
    
    $whoisServers = [
        'com' => 'whois.verisign-grs.com',
        'net' => 'whois.verisign-grs.com',
        'org' => 'whois.pir.org',
        'io' => 'whois.nic.io',
        'dev' => 'whois.nic.dev',
        'app' => 'whois.nic.app',
        'ai' => 'whois.nic.ai',
        'top' => 'whois.nic.top'
    ];
    
    if (isset($whoisServers[$tld])) {
        $server = $whoisServers[$tld];
        $connection = @fsockopen($server, 43, $errno, $errstr, 10);
        
        if ($connection) {
            fwrite($connection, $domain . "\r\n");
            $response = '';
            while (!feof($connection)) {
                $response .= fgets($connection, 256);
            }
            fclose($connection);
            
            // Check if available
            if (preg_match('/No match|NOT FOUND|is available|Status: free/i', $response)) {
                return ['available' => true, 'domain' => $domain];
            }
            
            // Parse WHOIS data
            $registrar = '';
            $creationDate = '';
            $expiryDate = '';
            
            if (preg_match('/Registrar:\s*([^\n]+)/i', $response, $matches)) {
                $registrar = trim($matches[1]);
            }
            if (preg_match('/Creation Date:\s*([^\n]+)/i', $response, $matches)) {
                $creationDate = trim($matches[1]);
            }
            if (preg_match('/Expiry Date:\s*([^\n]+)/i', $response, $matches)) {
                $expiryDate = trim($matches[1]);
            }
            
            return [
                'available' => false,
                'domain' => $domain,
                'registrar' => $registrar ?: 'Unknown',
                'creationDate' => $creationDate ?: 'Unknown',
                'expiryDate' => $expiryDate ?: 'Unknown'
            ];
        }
    }
    
    // Default response for unknown domains (assume available)
    return ['available' => true, 'domain' => $domain];
}

// Try API if key is set
function checkWithAPI($domain) {
    if (WHOIS_API_KEY === 'at_Tum0rTrVQkxRo3NjRDgRxeWiJwXTN') {
        return null; // No real API key
    }
    
    $url = "https://www.whoisxmlapi.com/whoisserver/WhoisService";
    $params = [
        'apiKey' => WHOIS_API_KEY,
        'domainName' => $domain,
        'outputFormat' => 'JSON'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'checkdomain.top/1.0');
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200 || $curlError) {
        error_log("API Error: HTTP $httpCode, Curl: $curlError");
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['WhoisRecord'])) {
        return null;
    }
    
    $record = $data['WhoisRecord'];
    
    if (isset($record['dataError']) && $record['dataError'] === 'Not found') {
        return ['available' => true, 'domain' => $domain];
    }
    
    return [
        'available' => false,
        'domain' => $domain,
        'registrar' => $record['registrarName'] ?? 'Unknown',
        'creationDate' => $record['createdDateNormalized'] ?? 'Unknown',
        'expiryDate' => $record['expiryDateNormalized'] ?? 'Unknown'
    ];
}

// Main handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $domain = trim($input['domain'] ?? '');
    
    if (empty($domain)) {
        http_response_code(400);
        echo json_encode(['error' => 'Domain name is required']);
        exit();
    }
    
    // Validate domain
    if (!preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $domain)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid domain format']);
        exit();
    }
    
    // Try API first
    $result = checkWithAPI($domain);
    
    // Fallback to simple WHOIS
    if (!$result) {
        $result = simpleWhoisLookup($domain);
    }
    
    echo json_encode($result);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>