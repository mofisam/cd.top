<?php
// Log 500 error details
error_log("500 Internal Server Error at " . ($_SERVER['REQUEST_URI'] ?? 'unknown'));

$error_code = 500;
$error_title = 'Server Error';
$error_description = 'Something went wrong on our end. Our team has been notified and we\'re working to fix the issue.';
$error_icon = 'fa-server';
$show_search = false;

$suggestions = [
    'Try refreshing the page in a few minutes',
    'Clear your browser cache',
    'Check our status page for updates',
    'Contact support if the issue persists'
];

include 'error.php';
?>