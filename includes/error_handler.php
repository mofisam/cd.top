<?php
// Custom error handler for PHP errors
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    $error_type = '';
    switch ($errno) {
        case E_ERROR:
            $error_type = 'Fatal Error';
            break;
        case E_WARNING:
            $error_type = 'Warning';
            break;
        case E_NOTICE:
            $error_type = 'Notice';
            break;
        default:
            $error_type = 'Unknown Error';
    }
    
    // Log error
    error_log("[$error_type] $errstr in $errfile on line $errline");
    
    // In production, show friendly error page for fatal errors
    if ($errno === E_ERROR && !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'])) {
        header('Location: /errors/500.php');
        exit();
    }
    
    return false;
}

set_error_handler('customErrorHandler');

// Custom exception handler
function customExceptionHandler($exception) {
    error_log("Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
    
    if (!in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'])) {
        header('Location: /errors/500.php');
        exit();
    }
}

set_exception_handler('customExceptionHandler');

// 404 handler for missing pages
function handle404() {
    $uri = $_SERVER['REQUEST_URI'];
    $extension = pathinfo($uri, PATHINFO_EXTENSION);
    
    // Don't redirect for missing assets
    if (in_array($extension, ['css', 'js', 'jpg', 'png', 'gif', 'ico', 'svg'])) {
        return false;
    }
    
    http_response_code(404);
    include __DIR__ . '/../errors/404.php';
    exit();
}

// Register 404 handler for missing files
register_shutdown_function(function() {
    $lastError = error_get_last();
    if ($lastError && $lastError['type'] === E_ERROR) {
        if (strpos($lastError['message'], 'Failed opening required') !== false) {
            handle404();
        }
    }
});
?>