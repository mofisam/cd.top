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
$tokenUrl = 'https://graph.facebook.com/v18.0/oauth/access_token';
$tokenData = [
    'client_id' => FACEBOOK_APP_ID,
    'client_secret' => FACEBOOK_APP_SECRET,
    'code' => $_GET['code'],
    'redirect_uri' => FACEBOOK_REDIRECT_URI
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $tokenUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    die('Failed to get access token');
}

// Get user info
$userInfoUrl = 'https://graph.facebook.com/v18.0/me?fields=id,name,email,picture&access_token=' . $tokenData['access_token'];
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $userInfoUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$userInfo = curl_exec($ch);
curl_close($ch);

$user = json_decode($userInfo, true);

if (!isset($user['id'])) {
    die('Failed to get user info');
}

$avatar = isset($user['picture']['data']['url']) ? $user['picture']['data']['url'] : null;

// Login or register user
$auth = new Auth();
$ip = $_SERVER['REMOTE_ADDR'];
$userAgent = $_SERVER['HTTP_USER_AGENT'];

$result = $auth->socialLogin(
    'facebook',
    $user['id'],
    $user['email'] ?? $user['id'] . '@facebook.com',
    $user['name'],
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