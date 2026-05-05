<?php
session_start();
require_once '../config/oauth.php';
require_once '../lib/Auth.php';

// Verify state
if (!isset($_GET['state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
    die('Invalid state');
}

if (isset($_GET['error'])) {
    die('Authorization failed: ' . $_GET['error']);
}

if (!isset($_GET['code'])) {
    die('No authorization code received');
}

// Exchange code for token
$tokenUrl = 'https://github.com/login/oauth/access_token';
$tokenData = [
    'client_id' => GITHUB_CLIENT_ID,
    'client_secret' => GITHUB_CLIENT_SECRET,
    'code' => $_GET['code'],
    'redirect_uri' => GITHUB_REDIRECT_URI
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $tokenUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    die('Failed to get access token');
}

// Get user info
$userInfoUrl = 'https://api.github.com/user';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $userInfoUrl);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $tokenData['access_token'],
    'User-Agent: checkdomain.top'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$userInfo = curl_exec($ch);
curl_close($ch);

$user = json_decode($userInfo, true);

if (!isset($user['id'])) {
    die('Failed to get user info');
}

// Get user email (GitHub may not return email in primary request)
$emailUrl = 'https://api.github.com/user/emails';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $emailUrl);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $tokenData['access_token'],
    'User-Agent: checkdomain.top'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$emailResponse = curl_exec($ch);
curl_close($ch);

$emails = json_decode($emailResponse, true);
$email = $user['email'] ?? $user['id'] . '@github.com';
if ($emails) {
    foreach ($emails as $e) {
        if ($e['primary'] && $e['verified']) {
            $email = $e['email'];
            break;
        } elseif ($e['verified']) {
            $email = $e['email'];
        }
    }
}

$avatar = $user['avatar_url'] ?? null;

// Login or register user
$auth = new Auth();
$ip = $_SERVER['REMOTE_ADDR'];
$userAgent = $_SERVER['HTTP_USER_AGENT'];

$result = $auth->socialLogin(
    'github',
    (string)$user['id'],
    $email,
    $user['name'] ?? $user['login'],
    $avatar,
    $ip,
    $userAgent
);

if ($result['success']) {
    setcookie('session_token', $result['session_token'], time() + (86400 * 7), '/', '', true, true);
    $_SESSION['user'] = $result['user'];
    header('Location: ../dashboard.php');
} else {
    header('Location: ../login.php?error=' . urlencode($result['message']));
}
exit();
?>