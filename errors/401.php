<?php
$error_code = 401;
$error_title = 'Unauthorized Access';
$error_description = 'You need to be logged in to access this page. Please login and try again.';
$error_icon = 'fa-lock';
$show_search = false;

$suggestions = [
    'Make sure you are logged into your account',
    'Check if your session has expired',
    'Try logging out and back in',
    'Contact support if you believe this is an error'
];

include 'error.php';
?>