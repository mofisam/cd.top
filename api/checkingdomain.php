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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/rate_limiter.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ============================================
// WhoisXML API key — get a free key at:
// https://www.whoisxmlapi.com/signup.php
// ============================================
define('WHOIS_API_KEY', 'at_Tum0rTrVQkxRo3NjRDgRxeWiJwXTN');
// ============================================

/**
 * Primary lookup via WhoisXML API (HTTPS — works on all hosts including Truehost).
 * Port 43 raw WHOIS was removed because shared hosts block outbound port 43.
 */
function checkWithAPI($domain) {
    $url = "https://www.whoisxmlapi.com/whoisserver/WhoisService";
    $params = [
        'apiKey'       => WHOIS_API_KEY,
        'domainName'   => $domain,
        'outputFormat' => 'JSON',
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT,      'checkdomain.top/1.0');

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("WhoisXML cURL error for $domain: $curlError");
        return null;
    }

    if ($httpCode !== 200) {
        error_log("WhoisXML HTTP $httpCode for $domain");
        return null;
    }

    $data = json_decode($response, true);

    if (!$data || !isset($data['WhoisRecord'])) {
        error_log("WhoisXML unexpected response for $domain: " . substr($response, 0, 200));
        return null;
    }

    $record = $data['WhoisRecord'];

    // Domain not found = available
    if (
        (isset($record['dataError']) && $record['dataError'] === 'Not found') ||
        (isset($record['registryData']['dataError']) && $record['registryData']['dataError'] === 'Not found')
    ) {
        return ['available' => true, 'domain' => $domain];
    }

    // Has a registrar name = taken
    $registrar    = $record['registrarName']           ?? ($record['registryData']['registrarName'] ?? 'Unknown');
    $creationDate = $record['createdDateNormalized']   ?? ($record['registryData']['createdDateNormalized'] ?? 'Unknown');
    $expiryDate   = $record['expiresDateNormalized']   ?? ($record['registryData']['expiresDateNormalized'] ?? 'Unknown');

    // Extra fallback field names some TLDs return
    if ($creationDate === 'Unknown' && isset($record['registryData']['createdDate'])) {
        $creationDate = $record['registryData']['createdDate'];
    }
    if ($expiryDate === 'Unknown' && isset($record['registryData']['expiresDate'])) {
        $expiryDate = $record['registryData']['expiresDate'];
    }

    return [
        'available'    => false,
        'domain'       => $domain,
        'registrar'    => $registrar    ?: 'Unknown',
        'creationDate' => $creationDate ?: 'Unknown',
        'expiryDate'   => $expiryDate   ?: 'Unknown',
    ];
}

/**
 * Hardcoded fallback for very well-known domains.
 * Only used when the API call fails entirely (e.g. network issue or quota exceeded).
 * Returns null for anything not in the list so the caller can surface a proper error.
 */
function hardcodedFallback($domain) {
    $domain = strtolower(trim($domain));

    $takenDomains = [
        'google.com', 'facebook.com', 'amazon.com', 'microsoft.com', 'apple.com',
        'netflix.com', 'openai.com', 'github.com', 'twitter.com', 'instagram.com',
        'youtube.com', 'linkedin.com', 'spotify.com', 'tiktok.com', 'whatsapp.com',
        'checkdomain.top', 'yahoo.com', 'bing.com', 'duckduckgo.com', 'reddit.com',
    ];

    if (in_array($domain, $takenDomains)) {
        return [
            'available'    => false,
            'domain'       => $domain,
            'registrar'    => 'MarkMonitor Inc.',
            'creationDate' => 'Unknown',
            'expiryDate'   => 'Unknown',
        ];
    }

    return null; // Unknown — don't guess
}

// ---------------------------------------------------------------------------
// Main handler
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $domain       = trim($input['domain']  ?? '');
    $captchaAnswer = trim($input['captcha'] ?? '');

    // Validate domain format
    $validation = validateDomain($domain);
    if (!$validation['valid']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $validation['error']]);
        exit();
    }

    $domain = $validation['domain'];

    // Rate limiting / CAPTCHA
    $rateLimit = checkDomainRateLimit(getClientIP(), $captchaAnswer);

    if (!$rateLimit['allowed']) {
        http_response_code(429);

        if (($rateLimit['reason'] ?? '') === 'captcha_required') {
            echo json_encode([
                'success'        => false,
                'requiresCaptcha' => true,
                'captcha'        => $rateLimit['captcha'],
                'message'        => $rateLimit['message'],
            ]);
            exit();
        }

        header('Retry-After: ' . (int) ($rateLimit['retryAfter'] ?? 60));
        echo json_encode([
            'success'    => false,
            'error'      => $rateLimit['message'] ?? 'Too many requests. Please try again later.',
            'retryAfter' => $rateLimit['retryAfter'] ?? null,
        ]);
        exit();
    }

    // 1. Try WhoisXML API (HTTPS — works on shared hosting)
    $result = checkWithAPI($domain);

    // 2. Fallback: hardcoded well-known domains
    if ($result === null) {
        $result = hardcodedFallback($domain);
    }

    // 3. Both failed — surface a real error instead of a wrong answer
    if ($result === null) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'error'   => 'Domain lookup is temporarily unavailable. Please try again in a moment.',
        ]);
        exit();
    }

    $result['success'] = true;
    echo json_encode($result);

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>