<?php
session_start();
require_once '../config/oauth.php';
require_once '../lib/Auth.php';
require_once '../lib/session_cookie.php';

function redirectToLoginWithError(string $message): void {
    header('Location: ../login.php?error=' . urlencode($message));
    exit();
}

function fetchJsonWithCurl(string $url, array $options = []): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    foreach ($options as $option => $value) {
        curl_setopt($ch, $option, $value);
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        redirectToLoginWithError('Google login is unavailable right now. Please try again.');
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        redirectToLoginWithError('Google returned an unexpected response. Please try again.');
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = $data['error_description'] ?? $data['error'] ?? 'Google authorization failed.';
        redirectToLoginWithError($message);
    }

    return $data;
}

// Verify state
if (empty($_SESSION['oauth_state']) || !isset($_GET['state']) || !hash_equals($_SESSION['oauth_state'], (string) $_GET['state'])) {
    redirectToLoginWithError('Google login session expired. Please try again.');
}
unset($_SESSION['oauth_state']);

if (isset($_GET['error'])) {
    redirectToLoginWithError('Google authorization failed: ' . (string) $_GET['error']);
}

if (!isset($_GET['code'])) {
    redirectToLoginWithError('No Google authorization code was received.');
}

// Exchange code for token
$tokenUrl = 'https://oauth2.googleapis.com/token';
$tokenData = [
    'client_id' => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'code' => $_GET['code'],
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'grant_type' => 'authorization_code'
];

$tokenData = fetchJsonWithCurl($tokenUrl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($tokenData),
]);

if (!isset($tokenData['access_token'])) {
    redirectToLoginWithError('Failed to get a Google access token.');
}

// Get user info
$userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo';
$user = fetchJsonWithCurl($userInfoUrl, [
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tokenData['access_token']],
]);

if (!isset($user['id'], $user['email'])) {
    redirectToLoginWithError('Failed to get your Google account details.');
}

// Login or register user
$auth = new Auth();
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$result = $auth->socialLogin(
    'google',
    $user['id'],
    $user['email'],
    $user['name'] ?? $user['email'],
    $user['picture'] ?? null,
    $ip,
    $userAgent
);

if ($result['success']) {
    setAuthSessionCookie($result['session_token']);
    $_SESSION['user'] = $result['user'];
    header('Location: ../dashboard.php');
} else {
    header('Location: ../login.php?error=' . urlencode($result['message']));
}
exit();
?>
