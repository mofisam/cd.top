<?php
define('DOMAIN_RATE_LIMIT_WINDOW', 600);
define('DOMAIN_RATE_LIMIT_CAPTCHA_AFTER', 8);
define('DOMAIN_RATE_LIMIT_MAX_REQUESTS', 40);
define('DOMAIN_CAPTCHA_VERIFIED_SECONDS', 600);
define('DOMAIN_RATE_LIMIT_FILE', __DIR__ . '/../logs/domain_rate_limits.json');

function ensureCaptchaChallenge() {
    if (
        !isset($_SESSION['domain_captcha_question']) ||
        !isset($_SESSION['domain_captcha_answer']) ||
        !isset($_SESSION['domain_captcha_created_at']) ||
        ($_SESSION['domain_captcha_created_at'] + DOMAIN_RATE_LIMIT_WINDOW) < time()
    ) {
        $left = random_int(2, 12);
        $right = random_int(2, 12);
        $_SESSION['domain_captcha_question'] = "What is {$left} + {$right}?";
        $_SESSION['domain_captcha_answer'] = (string) ($left + $right);
        $_SESSION['domain_captcha_created_at'] = time();
    }

    return [
        'question' => $_SESSION['domain_captcha_question']
    ];
}

function verifyCaptchaAnswer($answer) {
    if (!isset($_SESSION['domain_captcha_answer'])) {
        ensureCaptchaChallenge();
        return false;
    }

    $answer = trim((string) $answer);
    if ($answer === '' || !hash_equals($_SESSION['domain_captcha_answer'], $answer)) {
        return false;
    }

    unset($_SESSION['domain_captcha_question'], $_SESSION['domain_captcha_answer'], $_SESSION['domain_captcha_created_at']);
    $_SESSION['domain_captcha_verified_until'] = time() + DOMAIN_CAPTCHA_VERIFIED_SECONDS;

    return true;
}

function isCaptchaVerified() {
    return isset($_SESSION['domain_captcha_verified_until']) && $_SESSION['domain_captcha_verified_until'] >= time();
}

function checkDomainRateLimit($ipAddress, $captchaAnswer = '') {
    $now = time();
    $file = DOMAIN_RATE_LIMIT_FILE;
    $directory = dirname($file);

    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    $handle = fopen($file, 'c+');
    if (!$handle) {
        error_log('Rate limit file could not be opened: ' . $file);
        return ['allowed' => true];
    }

    flock($handle, LOCK_EX);
    rewind($handle);
    $raw = stream_get_contents($handle);
    $store = json_decode($raw ?: '{}', true);
    if (!is_array($store)) {
        $store = [];
    }

    $key = hash('sha256', (string) $ipAddress);
    $record = $store[$key] ?? ['requests' => []];
    $requests = array_values(array_filter($record['requests'] ?? [], function ($timestamp) use ($now) {
        return is_numeric($timestamp) && (int) $timestamp >= ($now - DOMAIN_RATE_LIMIT_WINDOW);
    }));

    foreach ($store as $storeKey => $storeRecord) {
        $recent = array_filter($storeRecord['requests'] ?? [], function ($timestamp) use ($now) {
            return is_numeric($timestamp) && (int) $timestamp >= ($now - (DOMAIN_RATE_LIMIT_WINDOW * 2));
        });

        if (empty($recent)) {
            unset($store[$storeKey]);
        }
    }

    $requestCount = count($requests);
    $result = ['allowed' => true, 'remaining' => max(0, DOMAIN_RATE_LIMIT_MAX_REQUESTS - $requestCount)];

    if ($requestCount >= DOMAIN_RATE_LIMIT_MAX_REQUESTS) {
        $oldest = min($requests);
        $result = [
            'allowed' => false,
            'reason' => 'rate_limited',
            'retryAfter' => max(1, ($oldest + DOMAIN_RATE_LIMIT_WINDOW) - $now),
            'message' => 'Too many domain checks from this IP. Please wait a few minutes and try again.'
        ];
    } elseif ($requestCount >= DOMAIN_RATE_LIMIT_CAPTCHA_AFTER && !isCaptchaVerified()) {
        if (verifyCaptchaAnswer($captchaAnswer)) {
            $requests[] = $now;
            $result = ['allowed' => true, 'remaining' => max(0, DOMAIN_RATE_LIMIT_MAX_REQUESTS - count($requests))];
        } else {
            $result = [
                'allowed' => false,
                'reason' => 'captcha_required',
                'captcha' => ensureCaptchaChallenge(),
                'message' => 'Please complete the quick verification to continue checking domains.'
            ];
        }
    } else {
        $requests[] = $now;
        $result = ['allowed' => true, 'remaining' => max(0, DOMAIN_RATE_LIMIT_MAX_REQUESTS - count($requests))];
    }

    $store[$key] = ['requests' => $requests, 'updated_at' => $now];

    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($store));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $result;
}
?>
