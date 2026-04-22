<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root'); // Change this to your database username
define('DB_PASS', '1234'); // Change this to your database password
define('DB_NAME', 'checkdomain_db');

// Create connection function
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // Check connection
    if ($conn->connect_error) {
        die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
    }
    
    // Set charset to UTF-8
    $conn->set_charset("utf8mb4");
    
    return $conn;
}

// Function to get/set session ID for tracking
function getSessionId() {
    if (!isset($_COOKIE['tracking_session'])) {
        $sessionId = bin2hex(random_bytes(16));
        setcookie('tracking_session', $sessionId, time() + (86400 * 30), "/");
        return $sessionId;
    }
    return $_COOKIE['tracking_session'];
}

// Function to get client IP address
function getClientIP() {
    $ipHeaders = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($ipHeaders as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            return trim($ips[0]);
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}


// Function to update daily stats
function updateDailyStats() {
    try {
        $conn = getDBConnection();
        $today = date('Y-m-d');
        
        // Check if stats for today exist
        $checkStmt = $conn->prepare("SELECT id FROM daily_stats WHERE stat_date = ?");
        $checkStmt->bind_param("s", $today);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows == 0) {
            // Get today's stats
            $viewsStmt = $conn->prepare("SELECT COUNT(*) as total, COUNT(DISTINCT session_id) as unique_visitors FROM page_views WHERE DATE(view_date) = ?");
            $viewsStmt->bind_param("s", $today);
            $viewsStmt->execute();
            $viewsResult = $viewsStmt->get_result();
            $viewsData = $viewsResult->fetch_assoc();
            
            $subscribersStmt = $conn->prepare("SELECT COUNT(*) as new FROM subscribers WHERE DATE(subscribed_at) = ?");
            $subscribersStmt->bind_param("s", $today);
            $subscribersStmt->execute();
            $subscribersResult = $subscribersStmt->get_result();
            $subscribersData = $subscribersResult->fetch_assoc();
            
            // Insert daily stats
            $insertStmt = $conn->prepare("INSERT INTO daily_stats (stat_date, total_views, unique_visitors, new_subscribers) VALUES (?, ?, ?, ?)");
            $insertStmt->bind_param("siii", $today, $viewsData['total'], $viewsData['unique_visitors'], $subscribersData['new']);
            $insertStmt->execute();
            $insertStmt->close();
            $viewsStmt->close();
            $subscribersStmt->close();
        }
        
        $checkStmt->close();
        $conn->close();
    } catch (Exception $e) {
        error_log("Daily stats update failed: " . $e->getMessage());
    }
}

// Function to log admin activity
function logAdminActivity($userId, $action, $details = null) {
    try {
        $conn = getDBConnection();
        $ipAddress = getClientIP();
        
        // If userId is 0 or null, set to NULL in database
        $userId = ($userId && $userId > 0) ? $userId : null;
        
        $stmt = $conn->prepare("INSERT INTO admin_activity_log (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $userId, $action, $details, $ipAddress);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    } catch (Exception $e) {
        error_log("Admin activity log failed: " . $e->getMessage());
    }
}

// Function to create admin user
function createAdminUser($username, $password, $email) {
    try {
        $conn = getDBConnection();
        
        // Check if admin already exists
        $checkStmt = $conn->prepare("SELECT id FROM admin_users WHERE username = ?");
        $checkStmt->bind_param("s", $username);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows > 0) {
            $checkStmt->close();
            $conn->close();
            return false;
        }
        $checkStmt->close();
        
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO admin_users (username, password_hash, email) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $username, $passwordHash, $email);
        
        $success = $stmt->execute();
        $stmt->close();
        $conn->close();
        
        return $success;
    } catch (Exception $e) {
        error_log("Create admin user failed: " . $e->getMessage());
        return false;
    }
}

// Function to verify admin credentials
function verifyAdminCredentials($username, $password) {
    try {
        $conn = getDBConnection();
        
        $stmt = $conn->prepare("SELECT id, username, password_hash, role, is_active FROM admin_users WHERE username = ? AND is_active = 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $stmt->close();
            $conn->close();
            
            if (password_verify($password, $user['password_hash'])) {
                return $user;
            }
        } else {
            $stmt->close();
            $conn->close();
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Verify admin credentials failed: " . $e->getMessage());
        return false;
    }
}

// Function to create admin session
function createAdminSession($userId, $sessionToken, $expiresHours = 24) {
    try {
        $conn = getDBConnection();
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresHours} hours"));
        $ipAddress = getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $stmt = $conn->prepare("INSERT INTO admin_sessions (user_id, session_token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $userId, $sessionToken, $ipAddress, $userAgent, $expiresAt);
        $success = $stmt->execute();
        $stmt->close();
        $conn->close();
        
        return $success;
    } catch (Exception $e) {
        error_log("Create admin session failed: " . $e->getMessage());
        return false;
    }
}

// Function to verify admin session
function verifyAdminSession($sessionToken) {
    try {
        $conn = getDBConnection();
        
        $stmt = $conn->prepare("SELECT s.id, s.user_id, u.username, u.role, s.expires_at 
                                FROM admin_sessions s 
                                JOIN admin_users u ON s.user_id = u.id 
                                WHERE s.session_token = ? AND s.is_active = 1 AND u.is_active = 1");
        $stmt->bind_param("s", $sessionToken);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            $conn->close();
            return false;
        }
        
        $session = $result->fetch_assoc();
        $stmt->close();
        $conn->close();
        
        // Check if session expired
        if (strtotime($session['expires_at']) < time()) {
            return false;
        }
        
        return $session;
    } catch (Exception $e) {
        error_log("Verify admin session failed: " . $e->getMessage());
        return false;
    }
}

// Function to update last login
function updateLastLogin($userId) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    } catch (Exception $e) {
        error_log("Update last login failed: " . $e->getMessage());
    }
}

// Function to invalidate admin session
function invalidateAdminSession($sessionToken) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE admin_sessions SET is_active = 0 WHERE session_token = ?");
        $stmt->bind_param("s", $sessionToken);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    } catch (Exception $e) {
        error_log("Invalidate admin session failed: " . $e->getMessage());
    }
}

// Test database connection function
function testDatabaseConnection() {
    try {
        $conn = getDBConnection();
        $result = $conn->query("SELECT 1");
        $conn->close();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Function to log domain search
function logDomainSearch($domain, $tld, $searchTerm, $isAvailable) {
    try {
        $conn = getDBConnection();
        $sessionId = getSessionId();
        $ipAddress = getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $stmt = $conn->prepare("INSERT INTO domain_searches (domain_name, tld, search_term, is_available, ip_address, session_id, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssisss", $domain, $tld, $searchTerm, $isAvailable, $ipAddress, $sessionId, $userAgent);
        $stmt->execute();
        $stmt->close();
        
        // Update popular searches
        updatePopularSearch($searchTerm);
        
        $conn->close();
    } catch (Exception $e) {
        error_log("Domain search logging failed: " . $e->getMessage());
    }
}

// Function to update popular searches
function updatePopularSearch($searchTerm) {
    try {
        $conn = getDBConnection();
        
        // Check if exists
        $checkStmt = $conn->prepare("SELECT id, search_count FROM popular_searches WHERE search_term = ?");
        $checkStmt->bind_param("s", $searchTerm);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $newCount = $row['search_count'] + 1;
            $updateStmt = $conn->prepare("UPDATE popular_searches SET search_count = ?, last_searched = NOW() WHERE id = ?");
            $updateStmt->bind_param("ii", $newCount, $row['id']);
            $updateStmt->execute();
            $updateStmt->close();
        } else {
            $insertStmt = $conn->prepare("INSERT INTO popular_searches (search_term, search_count) VALUES (?, 1)");
            $insertStmt->bind_param("s", $searchTerm);
            $insertStmt->execute();
            $insertStmt->close();
        }
        
        $checkStmt->close();
        $conn->close();
    } catch (Exception $e) {
        error_log("Popular search update failed: " . $e->getMessage());
    }
}

// Function to get popular searches
function getPopularSearches($limit = 10) {
    try {
        $conn = getDBConnection();
        $result = $conn->query("SELECT search_term, search_count FROM popular_searches ORDER BY search_count DESC LIMIT $limit");
        $searches = [];
        while ($row = $result->fetch_assoc()) {
            $searches[] = $row;
        }
        $conn->close();
        return $searches;
    } catch (Exception $e) {
        return [];
    }
}

// Function to get popular TLDs
function getPopularTLDs($limit = 12) {
    try {
        $conn = getDBConnection();
        $result = $conn->query("SELECT * FROM popular_tlds WHERE is_active = 1 ORDER BY popularity_score DESC LIMIT $limit");
        $tlds = [];
        while ($row = $result->fetch_assoc()) {
            $tlds[] = $row;
        }
        $conn->close();
        return $tlds;
    } catch (Exception $e) {
        return [];
    }
}

// Function to get domain search stats
function getDomainSearchStats($days = 30) {
    try {
        $conn = getDBConnection();
        $stats = [];
        
        // Total searches
        $result = $conn->query("SELECT COUNT(*) as total FROM domain_searches WHERE searched_at >= DATE_SUB(NOW(), INTERVAL $days DAY)");
        $stats['total_searches'] = $result->fetch_assoc()['total'];
        
        // Available vs taken
        $result = $conn->query("SELECT 
            SUM(CASE WHEN is_available = 1 THEN 1 ELSE 0 END) as available,
            SUM(CASE WHEN is_available = 0 THEN 1 ELSE 0 END) as taken 
            FROM domain_searches WHERE searched_at >= DATE_SUB(NOW(), INTERVAL $days DAY)");
        $availability = $result->fetch_assoc();
        $stats['available'] = $availability['available'];
        $stats['taken'] = $availability['taken'];
        
        // Top TLDs searched
        $result = $conn->query("SELECT tld, COUNT(*) as count FROM domain_searches WHERE searched_at >= DATE_SUB(NOW(), INTERVAL $days DAY) GROUP BY tld ORDER BY count DESC LIMIT 10");
        $stats['top_tlds'] = [];
        while ($row = $result->fetch_assoc()) {
            $stats['top_tlds'][] = $row;
        }
        
        $conn->close();
        return $stats;
    } catch (Exception $e) {
        return [];
    }
}

// Function to get geolocation from IP using free API with caching
function getGeolocationFromIP($ip) {
    // Skip private IPs
    if ($ip === '127.0.0.1' || $ip === 'localhost' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
        return [
            'country' => 'Local',
            'country_code' => 'LOCAL',
            'city' => 'Localhost',
            'region' => 'Local',
            'latitude' => null,
            'longitude' => null,
            'timezone' => 'UTC',
            'isp' => 'Local Network'
        ];
    }
    
    // Check cache first
    $conn = getDBConnection();
    $cacheStmt = $conn->prepare("SELECT * FROM ip_geolocation_cache WHERE ip_address = ? AND last_updated > DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $cacheStmt->bind_param("s", $ip);
    $cacheStmt->execute();
    $cacheResult = $cacheStmt->get_result();
    
    if ($cacheResult->num_rows > 0) {
        $cached = $cacheResult->fetch_assoc();
        $cacheStmt->close();
        $conn->close();
        return [
            'country' => $cached['country'],
            'country_code' => $cached['country_code'],
            'city' => $cached['city'],
            'region' => $cached['region'],
            'latitude' => $cached['latitude'],
            'longitude' => $cached['longitude'],
            'timezone' => $cached['timezone'],
            'isp' => $cached['isp']
        ];
    }
    $cacheStmt->close();
    
    // Use ip-api.com (free, no API key required, 45 requests/minute)
    $url = "http://ip-api.com/json/{$ip}?fields=status,country,countryCode,region,city,lat,lon,timezone,isp";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        
        if ($data && $data['status'] === 'success') {
            $geoData = [
                'country' => $data['country'],
                'country_code' => $data['countryCode'],
                'city' => $data['city'],
                'region' => $data['region'],
                'latitude' => $data['lat'],
                'longitude' => $data['lon'],
                'timezone' => $data['timezone'],
                'isp' => $data['isp'] ?? 'Unknown'
            ];
            
            // Cache the result
            $insertStmt = $conn->prepare("INSERT INTO ip_geolocation_cache (ip_address, country, country_code, city, region, latitude, longitude, timezone, isp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insertStmt->bind_param("sssssddss", $ip, $geoData['country'], $geoData['country_code'], $geoData['city'], $geoData['region'], $geoData['latitude'], $geoData['longitude'], $geoData['timezone'], $geoData['isp']);
            $insertStmt->execute();
            $insertStmt->close();
            
            $conn->close();
            return $geoData;
        }
    }
    
    $conn->close();
    return null;
}

// Function to detect device type
function getDeviceType($userAgent) {
    if (empty($userAgent)) return 'Unknown';
    
    $userAgent = strtolower($userAgent);
    
    if (strpos($userAgent, 'mobile') !== false || strpos($userAgent, 'android') !== false || strpos($userAgent, 'iphone') !== false) {
        return 'Mobile';
    } elseif (strpos($userAgent, 'tablet') !== false || strpos($userAgent, 'ipad') !== false) {
        return 'Tablet';
    } elseif (strpos($userAgent, 'bot') !== false || strpos($userAgent, 'crawler') !== false) {
        return 'Bot';
    }
    
    return 'Desktop';
}

// Function to detect browser
function getBrowser($userAgent) {
    if (empty($userAgent)) return 'Unknown';
    
    $userAgent = strtolower($userAgent);
    
    if (strpos($userAgent, 'edg') !== false) return 'Edge';
    if (strpos($userAgent, 'opr') !== false || strpos($userAgent, 'opera') !== false) return 'Opera';
    if (strpos($userAgent, 'chrome') !== false) return 'Chrome';
    if (strpos($userAgent, 'firefox') !== false) return 'Firefox';
    if (strpos($userAgent, 'safari') !== false) return 'Safari';
    
    return 'Other';
}

// Function to detect OS
function getOperatingSystem($userAgent) {
    if (empty($userAgent)) return 'Unknown';
    
    $userAgent = strtolower($userAgent);
    
    if (strpos($userAgent, 'windows') !== false) return 'Windows';
    if (strpos($userAgent, 'mac') !== false) return 'macOS';
    if (strpos($userAgent, 'linux') !== false) return 'Linux';
    if (strpos($userAgent, 'android') !== false) return 'Android';
    if (strpos($userAgent, 'ios') !== false || strpos($userAgent, 'iphone') !== false) return 'iOS';
    
    return 'Other';
}

// Updated trackPageView function with geolocation
function trackPageView($pageUrl) {
    try {
        $conn = getDBConnection();
        $sessionId = getSessionId();
        $ipAddress = getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $referrer = $_SERVER['HTTP_REFERER'] ?? '';
        
        // Get geolocation
        $geoData = getGeolocationFromIP($ipAddress);
        
        // Detect device, browser, OS
        $deviceType = getDeviceType($userAgent);
        $browser = getBrowser($userAgent);
        $os = getOperatingSystem($userAgent);
        
        // Get screen resolution from cookie if set
        $screenResolution = $_COOKIE['screen_resolution'] ?? null;
        $language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
        $language = substr($language, 0, 2);
        
        $stmt = $conn->prepare("INSERT INTO page_views (page_url, ip_address, user_agent, referrer, session_id, country, city, region, latitude, longitude, timezone, isp, device_type, browser_name, os_name, screen_resolution, language) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->bind_param("ssssssssddsssssss", 
            $pageUrl, $ipAddress, $userAgent, $referrer, $sessionId,
            $geoData['country'], $geoData['city'], $geoData['region'],
            $geoData['latitude'], $geoData['longitude'], $geoData['timezone'],
            $geoData['isp'], $deviceType, $browser, $os, $screenResolution, $language
        );
        
        $stmt->execute();
        $stmt->close();
        
        // Update or create user session
        updateUserSession($sessionId, $ipAddress, $geoData, $deviceType, $browser, $os);
        
        $conn->close();
    } catch (Exception $e) {
        error_log("Page view tracking failed: " . $e->getMessage());
    }
}

// Function to update user session
function updateUserSession($sessionId, $ipAddress, $geoData, $deviceType, $browser, $os) {
    try {
        $conn = getDBConnection();
        
        $checkStmt = $conn->prepare("SELECT id, page_views FROM user_sessions WHERE session_id = ?");
        $checkStmt->bind_param("s", $sessionId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $newCount = $row['page_views'] + 1;
            $updateStmt = $conn->prepare("UPDATE user_sessions SET page_views = ?, last_activity = NOW() WHERE id = ?");
            $updateStmt->bind_param("ii", $newCount, $row['id']);
            $updateStmt->execute();
            $updateStmt->close();
        } else {
            $insertStmt = $conn->prepare("INSERT INTO user_sessions (session_id, ip_address, country, city, device_type, browser, os, page_views) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
            $insertStmt->bind_param("sssssss", $sessionId, $ipAddress, $geoData['country'], $geoData['city'], $deviceType, $browser, $os);
            $insertStmt->execute();
            $insertStmt->close();
        }
        
        $checkStmt->close();
        $conn->close();
    } catch (Exception $e) {
        error_log("User session update failed: " . $e->getMessage());
    }
}
?>