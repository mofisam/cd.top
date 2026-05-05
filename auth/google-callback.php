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
$tokenUrl = 'https://oauth2.googleapis.com/token';
$tokenData = [
    'client_id' => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'code' => $_GET['code'],
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'grant_type' => 'authorization_code'
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
$userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $userInfoUrl);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $tokenData['access_token']]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$userInfo = curl_exec($ch);
curl_close($ch);

$user = json_decode($userInfo, true);

if (!isset($user['id'])) {
    die('Failed to get user info');
}

// Login or register user
$auth = new Auth();
$ip = $_SERVER['REMOTE_ADDR'];
$userAgent = $_SERVER['HTTP_USER_AGENT'];

$result = $auth->socialLogin(
    'google',
    $user['id'],
    $user['email'],
    $user['name'],
    $user['picture'],
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