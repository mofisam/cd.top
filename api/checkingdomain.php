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
 * Make a cURL GET request and return the decoded JSON body.
 * Returns null on any network or HTTP error.
 */
function apiGet($url, array $params) {
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
        error_log("cURL error [$url]: $curlError");
        return null;
    }
    if ($httpCode !== 200) {
        error_log("HTTP $httpCode [$url]: " . substr($response, 0, 300));
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        error_log("Non-JSON response [$url]: " . substr($response, 0, 300));
        return null;
    }

    return $data;
}

/**
 * STEP 1 — Check availability using the dedicated Availability API.
 * Returns 'available', 'taken', or null (on failure).
 *
 * Endpoint docs: https://domain-availability.whoisxmlapi.com/api/v1
 */
function checkAvailability($domain) {
    $data = apiGet('https://domain-availability.whoisxmlapi.com/api/v1', [
        'apiKey'       => WHOIS_API_KEY,
        'domainName'   => $domain,
        'credits'      => 'DA',          // use Domain Availability credits
    ]);

    if (!$data) return null;

    // Response contains domainAvailability: "AVAILABLE" or "UNAVAILABLE"
    $status = strtoupper($data['DomainInfo']['domainAvailability'] ?? '');

    if ($status === 'AVAILABLE')   return 'available';
    if ($status === 'UNAVAILABLE') return 'taken';

    error_log("Availability API unexpected status for $domain: " . json_encode($data));
    return null;
}

/**
 * STEP 2 — Fetch full WHOIS details (only called when domain is TAKEN).
 * Returns registrar, creationDate, expiryDate.
 */
function fetchWhoisDetails($domain) {
    $data = apiGet('https://www.whoisxmlapi.com/whoisserver/WhoisService', [
        'apiKey'       => WHOIS_API_KEY,
        'domainName'   => $domain,
        'outputFormat' => 'JSON',
    ]);

    if (!$data || !isset($data['WhoisRecord'])) {
        return [
            'registrar'    => 'Unknown',
            'creationDate' => 'Unknown',
            'expiryDate'   => 'Unknown',
        ];
    }

    $r = $data['WhoisRecord'];
    $rd = $r['registryData'] ?? [];

    $registrar    = $r['registrarName']         ?? ($rd['registrarName']         ?? '');
    $creationDate = $r['createdDateNormalized']  ?? ($rd['createdDateNormalized']  ?? '');
    $expiryDate   = $r['expiresDateNormalized']  ?? ($rd['expiresDateNormalized']  ?? '');

    // Some TLDs use slightly different field names
    if (!$creationDate) $creationDate = $r['createdDate']  ?? ($rd['createdDate']  ?? '');
    if (!$expiryDate)   $expiryDate   = $r['expiresDate']  ?? ($rd['expiresDate']  ?? '');

    // Parse nameservers if present
    $nameservers = [];
    if (!empty($r['nameServers']['hostNames'])) {
        $nameservers = array_slice($r['nameServers']['hostNames'], 0, 4);
    }

    return [
        'registrar'    => $registrar    ?: 'Unknown',
        'creationDate' => $creationDate ?: 'Unknown',
        'expiryDate'   => $expiryDate   ?: 'Unknown',
        'nameservers'  => $nameservers,
    ];
}

/**
 * Hardcoded fallback — only used if BOTH API calls fail completely.
 * Returns null for anything not in the list so the caller surfaces a real error.
 */
function hardcodedFallback($domain) {
    $takenDomains = [
        'google.com', 'facebook.com', 'amazon.com', 'microsoft.com', 'apple.com',
        'netflix.com', 'openai.com', 'github.com', 'twitter.com', 'instagram.com',
        'youtube.com', 'linkedin.com', 'spotify.com', 'tiktok.com', 'whatsapp.com',
        'checkdomain.top', 'yahoo.com', 'bing.com', 'duckduckgo.com', 'reddit.com',
    ];

    if (in_array(strtolower($domain), $takenDomains)) {
        return [
            'available'    => false,
            'domain'       => $domain,
            'registrar'    => 'Unknown',
            'creationDate' => 'Unknown',
            'expiryDate'   => 'Unknown',
        ];
    }

    return null;
}

// ---------------------------------------------------------------------------
// Main handler
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];

    $domain        = trim($input['domain']  ?? '');
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
                'success'         => false,
                'requiresCaptcha' => true,
                'captcha'         => $rateLimit['captcha'],
                'message'         => $rateLimit['message'],
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

    // ------------------------------------------------------------------
    // 1. Check availability first (dedicated API — unambiguous result)
    // ------------------------------------------------------------------
    $availability = checkAvailability($domain);

    if ($availability === 'available') {
        // Confirmed available — no need to fetch WHOIS details
        echo json_encode([
            'success'   => true,
            'available' => true,
            'domain'    => $domain,
        ]);
        exit();
    }

    if ($availability === 'taken') {
        // Confirmed taken — fetch full WHOIS details
        $details = fetchWhoisDetails($domain);
        echo json_encode(array_merge([
            'success'   => true,
            'available' => false,
            'domain'    => $domain,
        ], $details));
        exit();
    }

    // ------------------------------------------------------------------
    // 2. Availability API failed — try hardcoded list
    // ------------------------------------------------------------------
    $fallback = hardcodedFallback($domain);
    if ($fallback !== null) {
        $fallback['success'] = true;
        echo json_encode($fallback);
        exit();
    }

    // ------------------------------------------------------------------
    // 3. Everything failed — return a real error, not a wrong answer
    // ------------------------------------------------------------------
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error'   => 'Domain lookup is temporarily unavailable. Please try again in a moment.',
    ]);

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>