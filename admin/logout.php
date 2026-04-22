<?php
session_start();
require_once '../config/database.php';

if (isset($_SESSION['admin_id']) && isset($_SESSION['admin_token'])) {
    // Log activity before invalidating session
    logAdminActivity($_SESSION['admin_id'], 'LOGOUT', 'User logged out');
    
    // Invalidate session in database
    invalidateAdminSession($_SESSION['admin_token']);
}

// Destroy session
session_destroy();

header('Location: login.php');
exit();
?>