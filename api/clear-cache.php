<?php
header('Content-Type: application/json');
require_once '../config/database.php';

session_start();
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$cacheDirs = [
    '../cache',
    '../temp',
    '../views/cache'
];

$success = true;
foreach ($cacheDirs as $dir) {
    if (is_dir($dir)) {
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                if (!unlink($file)) {
                    $success = false;
                }
            }
        }
    }
}

echo json_encode(['success' => $success, 'message' => $success ? 'Cache cleared' : 'Some files could not be deleted']);
?>