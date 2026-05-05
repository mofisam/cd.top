<?php
require_once '../config/oauth.php';

session_start();
$_SESSION['oauth_state'] = bin2hex(random_bytes(32));

$params = [
    'client_id' => GOOGLE_CLIENT_ID,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => 'email profile',
    'state' => $_SESSION['oauth_state']
];

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
header('Location: ' . $authUrl);
exit();
?>