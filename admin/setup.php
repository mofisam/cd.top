<?php
// RUN THIS FILE ONCE TO CREATE YOUR ADMIN USER, THEN DELETE IT!
require_once '../config/database.php';

// Change these to your desired credentials
$adminUsername = 'admin';
$adminPassword = '12345678'; // CHANGE THIS!
$adminEmail = 'admin@checkdomain.top';

$success = createAdminUser($adminUsername, $adminPassword, $adminEmail);

if ($success) {
    echo "Admin user created successfully!<br>";
    echo "Username: $adminUsername<br>";
    echo "Password: $adminPassword<br>";
    echo "<strong>IMPORTANT: Delete this file (setup.php) immediately after use!</strong>";
} else {
    echo "Admin user already exists or creation failed.";
}
?>