<?php
$error_code = 400;
$error_title = 'Bad Request';
$error_description = 'The server could not understand your request. Please check your input and try again.';
$error_icon = 'fa-exclamation-circle';
$show_search = false;

$suggestions = [
    'Check the URL for typos or invalid characters',
    'Clear your browser cache and cookies',
    'Try accessing the page from a different browser',
    'Contact support if the issue persists'
];

include 'error.php';
?>