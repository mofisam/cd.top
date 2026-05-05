<?php
require_once '../config/oauth.php';

session_start();
$_SESSION['oauth_state'] = bin2hex(random_bytes(32));

$params = [
    'client_id' => GITHUB_CLIENT_ID,
    'redirect_uri' => GITHUB_REDIRECT_URI,
    'scope' => 'user:email',
    'state' => $_SESSION['oauth_state']
];

$authUrl = 'https://github.com/login/oauth/authorize?' . http_build_query($params);
header('Location: ' . $authUrl);
exit();
?>