<?php
// Log 404 error
$request_uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
error_log("404 Not Found: $request_uri from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

$error_code = 404;
$error_title = 'Page Not Found';
$error_description = 'Oops! The page you\'re looking for seems to have vanished into thin air. Let\'s help you find what you need.';
$error_icon = 'fa-search';
$show_search = true;

$suggestions = [
    'Check the URL for typos or spelling errors',
    'Go back to the previous page',
    'Use the search bar below to find what you\'re looking for',
    'Browse popular domains on our homepage'
];

include 'error.php';
?>