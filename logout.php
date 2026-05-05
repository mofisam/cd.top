<?php
session_start();
require_once 'lib/Auth.php';

if (isset($_COOKIE['session_token'])) {
    $auth = new Auth();
    $auth->logout($_COOKIE['session_token']);
    setcookie('session_token', '', time() - 3600, '/');
}

session_destroy();
header('Location: login.php');
exit();
?>