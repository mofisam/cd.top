<?php
session_start();
require_once '../config/database.php';

function checkAdminAuth() {
    // Check if session exists
    if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_token'])) {
        header('Location: login.php');
        exit();
    }
    
    // Verify session token
    $session = verifyAdminSession($_SESSION['admin_token']);
    
    if (!$session) {
        // Invalid session, clear it
        session_destroy();
        header('Location: login.php?expired=1');
        exit();
    }
    
    // Update session variables
    $_SESSION['admin_username'] = $session['username'];
    $_SESSION['admin_role'] = $session['role'];
    
    // Return user info
    return [
        'id' => $session['user_id'],
        'username' => $session['username'],
        'role' => $session['role']
    ];
}

// Function to check if user has specific role
function hasRole($requiredRole) {
    $user = checkAdminAuth();
    if ($requiredRole === 'admin' && $user['role'] !== 'admin') {
        header('HTTP/1.0 403 Forbidden');
        die('Access denied. Admin privileges required.');
    }
    return $user;
}
?>