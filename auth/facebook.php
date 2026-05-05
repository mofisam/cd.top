<?php
require_once '../config/oauth.php';

session_start();
$_SESSION['oauth_state'] = bin2hex(random_bytes(32));

$params = [
    'client_id' => FACEBOOK_APP_ID,
    'redirect_uri' => FACEBOOK_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => 'email,public_profile',
    'state' => $_SESSION['oauth_state']
];

$authUrl = 'https://www.facebook.com/v18.0/dialog/oauth?' . http_build_query($params);
header('Location: ' . $authUrl);
exit();
?>