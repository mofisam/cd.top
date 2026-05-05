<?php
$error_code = 403;
$error_title = 'Access Forbidden';
$error_description = 'You don\'t have permission to access this page. This area is restricted to authorized personnel only.';
$error_icon = 'fa-ban';
$show_search = false;

$suggestions = [
    'Verify you have the correct permissions',
    'Contact your administrator for access',
    'Return to the homepage and try a different section',
    'If you believe this is an error, please contact support'
];

include 'error.php';
?>