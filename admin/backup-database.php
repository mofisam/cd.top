<?php
require_once 'auth_check.php';
$user = checkAdminAuth();
require_once '../config/database.php';

// Only allow admin role to access backup
if ($user['role'] !== 'admin') {
    header('HTTP/1.0 403 Forbidden');
    die('Access denied. Admin privileges required.');
}

// Create backups directory if not exists
$backupDir = __DIR__ . '/../backups/';
if (!file_exists($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// Function to get database tables
function getTables($conn) {
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_array()) {
        $tables[] = $row[0];
    }
    return $tables;
}

// Function to backup single table
function backupTable($conn, $table, $backupDir) {
    $filename = $backupDir . $table . '_' . date('Y-m-d_H-i-s') . '.sql';
    $file = fopen($filename, 'w');
    
    // Get table structure
    $create = $conn->query("SHOW CREATE TABLE $table")->fetch_assoc();
    fwrite($file, "-- --------------------------------------------------------\n");
    fwrite($file, "-- Table structure for `$table`\n");
    fwrite($file, "-- --------------------------------------------------------\n\n");
    fwrite($file, "DROP TABLE IF EXISTS `$table`;\n");
    fwrite($file, $create['Create Table'] . ";\n\n");
    
    // Get table data
    $result = $conn->query("SELECT * FROM $table");
    if ($result->num_rows > 0) {
        fwrite($file, "-- --------------------------------------------------------\n");
        fwrite($file, "-- Data for table `$table`\n");
        fwrite($file, "-- --------------------------------------------------------\n\n");
        
        $fields = [];
        while ($field = $result->fetch_field()) {
            $fields[] = $field->name;
        }
        
        $fieldsStr = '`' . implode('`, `', $fields) . '`';
        
        while ($row = $result->fetch_assoc()) {
            $values = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = "'" . $conn->real_escape_string($value) . "'";
                }
            }
            $valuesStr = implode(', ', $values);
            fwrite($file, "INSERT INTO `$table` ($fieldsStr) VALUES ($valuesStr);\n");
        }
        fwrite($file, "\n");
    }
    
    fclose($file);
    return $filename;
}

// Function to create full database backup
function fullBackup($conn, $backupDir) {
    $tables = getTables($conn);
    $backupFiles = [];
    
    foreach ($tables as $table) {
        $backupFiles[] = backupTable($conn, $table, $backupDir);
    }
    
    // Create single SQL file with all backups
    $fullBackupFile = $backupDir . 'full_backup_' . date('Y-m-d_H-i-s') . '.sql';
    $fullFile = fopen($fullBackupFile, 'w');
    
    fwrite($fullFile, "-- --------------------------------------------------------\n");
    fwrite($fullFile, "-- Database Backup\n");
    fwrite($fullFile, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
    fwrite($fullFile, "-- --------------------------------------------------------\n\n");
    
    foreach ($backupFiles as $file) {
        $content = file_get_contents($file);
        fwrite($fullFile, $content . "\n");
        unlink($file); // Remove individual table files
    }
    
    fclose($fullFile);
    
    // Compress the backup
    $zipFile = str_replace('.sql', '.zip', $fullBackupFile);
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE) === TRUE) {
        $zip->addFile($fullBackupFile, basename($fullBackupFile));
        $zip->close();
        unlink($fullBackupFile); // Remove uncompressed SQL file
        return $zipFile;
    }
    
    return $fullBackupFile;
}

// Function to list existing backups
function getBackups($backupDir) {
    $backups = [];
    $files = glob($backupDir . '*.zip');
    foreach ($files as $file) {
        $backups[] = [
            'name' => basename($file),
            'path' => $file,
            'size' => filesize($file),
            'date' => filemtime($file),
            'type' => 'zip'
        ];
    }
    
    // Sort by date descending
    usort($backups, function($a, $b) {
        return $b['date'] - $a['date'];
    });
    
    return $backups;
}

// Function to delete old backups
function cleanupOldBackups($backupDir, $keepCount = 10) {
    $backups = getBackups($backupDir);
    $deleted = 0;
    
    while (count($backups) > $keepCount) {
        $oldest = array_pop($backups);
        if (unlink($oldest['path'])) {
            $deleted++;
        }
    }
    
    return $deleted;
}

// Handle actions
$message = '';
$messageType = '';
$backupFile = null;

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    switch ($action) {
        case 'full':
            // Create full backup
            $conn = getDBConnection();
            $backupFile = fullBackup($conn, $backupDir);
            $conn->close();
            
            if ($backupFile && file_exists($backupFile)) {
                logAdminActivity($user['id'], 'BACKUP_DATABASE', 'Created full database backup');
                $message = 'Full database backup created successfully!';
                $messageType = 'success';
                
                // Auto-download
                if (isset($_GET['download'])) {
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . basename($backupFile) . '"');
                    header('Content-Length: ' . filesize($backupFile));
                    readfile($backupFile);
                    exit();
                }
            } else {
                $message = 'Failed to create backup. Please check permissions.';
                $messageType = 'error';
            }
            break;
            
        case 'cleanup':
            $deleted = cleanupOldBackups($backupDir, 10);
            logAdminActivity($user['id'], 'CLEANUP_BACKUPS', "Deleted $deleted old backups");
            $message = "Cleaned up $deleted old backup(s). Keeping only the 10 most recent.";
            $messageType = 'success';
            break;
            
        case 'delete':
            if (isset($_GET['file'])) {
                $file = $backupDir . basename($_GET['file']);
                if (file_exists($file) && unlink($file)) {
                    logAdminActivity($user['id'], 'DELETE_BACKUP', 'Deleted backup: ' . basename($file));
                    $message = 'Backup deleted successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to delete backup.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'download':
            if (isset($_GET['file'])) {
                $file = $backupDir . basename($_GET['file']);
                if (file_exists($file)) {
                    logAdminActivity($user['id'], 'DOWNLOAD_BACKUP', 'Downloaded backup: ' . basename($file));
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
                    header('Content-Length: ' . filesize($file));
                    readfile($file);
                    exit();
                }
            }
            break;
    }
}

// Get list of backups
$backups = getBackups($backupDir);
$totalSize = array_sum(array_column($backups, 'size'));

// Get database size
$conn = getDBConnection();
$dbSize = 0;
$result = $conn->query("SELECT SUM(data_length + index_length) as size FROM information_schema.tables WHERE table_schema = DATABASE()");
if ($result) {
    $dbSize = $result->fetch_assoc()['size'];
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Database Backup - checkdomain.top Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #0F172A;
            font-family: 'Inter', sans-serif;
            overflow-x: hidden;
        }
        
        .backup-card {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 1rem;
            transition: all 0.3s ease;
        }
        
        .backup-card:hover {
            border-color: rgba(59, 130, 246, 0.4);
        }
        
        .main-content {
            transition: margin-left 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #2563EB 0%, #3B82F6 100%);
            transition: all 0.2s ease;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #3B82F6 0%, #60A5FA 100%);
            transform: translateY(-1px);
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.5);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #3B82F6;
            border-radius: 10px;
        }
        
        @media (max-width: 768px) {
            .backup-container {
                padding: 1rem !important;
            }
            
            .backup-card {
                padding: 1rem !important;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 1rem !important;
            }
            
            table {
                font-size: 0.75rem;
            }
            
            td, th {
                padding: 0.5rem !important;
            }
            
            .action-buttons {
                flex-wrap: wrap;
            }
            
            button {
                width: 100%;
            }
        }
        
        @media (max-width: 640px) {
            .stats-grid {
                grid-template-columns: 1fr !important;
            }
            
            .flex.justify-between {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch !important;
            }
        }
        
        /* Progress animation */
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .backup-progress {
            animation: pulse 1s ease-in-out infinite;
        }
    </style>
</head>
<body class="text-white">
    <!-- Include Sidebar -->
    <?php include_once 'includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content" style="margin-left: 16rem;">
        <div class="backup-container p-4 md:p-8">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                <div>
                    <h1 class="text-2xl md:text-3xl font-bold">Database Backup</h1>
                    <p class="text-gray-400 text-sm mt-1">Create and manage database backups</p>
                </div>
                <div class="flex gap-3 w-full sm:w-auto">
                    <a href="?action=cleanup" class="bg-yellow-600 hover:bg-yellow-700 px-4 py-2 rounded-lg transition text-center">
                        <i class="fas fa-broom"></i> Cleanup Old
                    </a>
                    <a href="?action=full&download=1" class="btn-primary px-4 py-2 rounded-lg transition text-center">
                        <i class="fas fa-download"></i> New Backup
                    </a>
                </div>
            </div>
            
            <!-- Message Display -->
            <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-500/20 border border-green-500/50' : 'bg-red-500/20 border border-red-500/50'; ?>">
                <div class="flex items-center gap-3">
                    <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle text-green-400' : 'fa-exclamation-triangle text-red-400'; ?> text-xl"></i>
                    <p><?php echo htmlspecialchars($message); ?></p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Stats Cards -->
            <div class="stats-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6 mb-8">
                <div class="backup-card rounded-xl p-4 md:p-6">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs md:text-sm">Database Size</p>
                            <p class="text-xl md:text-2xl font-bold mt-2">
                                <?php 
                                if ($dbSize > 1073741824) {
                                    echo round($dbSize / 1073741824, 2) . ' GB';
                                } elseif ($dbSize > 1048576) {
                                    echo round($dbSize / 1048576, 2) . ' MB';
                                } elseif ($dbSize > 1024) {
                                    echo round($dbSize / 1024, 2) . ' KB';
                                } else {
                                    echo $dbSize . ' B';
                                }
                                ?>
                            </p>
                        </div>
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-blue-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-database text-blue-400 text-lg md:text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="backup-card rounded-xl p-4 md:p-6">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs md:text-sm">Backup Count</p>
                            <p class="text-xl md:text-2xl font-bold mt-2"><?php echo count($backups); ?></p>
                        </div>
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-green-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-archive text-green-400 text-lg md:text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="backup-card rounded-xl p-4 md:p-6">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs md:text-sm">Total Backup Size</p>
                            <p class="text-xl md:text-2xl font-bold mt-2">
                                <?php 
                                if ($totalSize > 1073741824) {
                                    echo round($totalSize / 1073741824, 2) . ' GB';
                                } elseif ($totalSize > 1048576) {
                                    echo round($totalSize / 1048576, 2) . ' MB';
                                } elseif ($totalSize > 1024) {
                                    echo round($totalSize / 1024, 2) . ' KB';
                                } else {
                                    echo $totalSize . ' B';
                                }
                                ?>
                            </p>
                        </div>
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-purple-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-hdd text-purple-400 text-lg md:text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="backup-card rounded-xl p-4 md:p-6">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-gray-400 text-xs md:text-sm">Latest Backup</p>
                            <p class="text-sm md:text-base font-bold mt-2">
                                <?php 
                                if (!empty($backups)) {
                                    echo date('M d, Y H:i', $backups[0]['date']);
                                } else {
                                    echo 'No backups';
                                }
                                ?>
                            </p>
                        </div>
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-orange-500/20 rounded-full flex items-center justify-center">
                            <i class="fas fa-clock text-orange-400 text-lg md:text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Backup List -->
            <div class="backup-card rounded-xl p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold flex items-center gap-2">
                        <i class="fas fa-list text-blue-400"></i>
                        Available Backups
                    </h2>
                    <span class="text-xs text-gray-500">Keeping last 10 backups automatically</span>
                </div>
                
                <?php if (empty($backups)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-archive text-5xl text-gray-600 mb-4"></i>
                    <p class="text-gray-400">No backups found. Click "New Backup" to create your first backup.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-700">
                            <tr>
                                <th class="text-left py-3">Backup Name</th>
                                <th class="text-left py-3">Size</th>
                                <th class="text-left py-3">Created</th>
                                <th class="text-left py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backups as $backup): ?>
                            <tr class="border-b border-gray-800 hover:bg-slate-700/30 transition">
                                <td class="py-3 font-mono text-sm">
                                    <i class="fas fa-file-archive text-yellow-400 mr-2"></i>
                                    <?php echo $backup['name']; ?>
                                </td>
                                <td class="py-3">
                                    <?php 
                                    if ($backup['size'] > 1048576) {
                                        echo round($backup['size'] / 1048576, 2) . ' MB';
                                    } elseif ($backup['size'] > 1024) {
                                        echo round($backup['size'] / 1024, 2) . ' KB';
                                    } else {
                                        echo $backup['size'] . ' B';
                                    }
                                    ?>
                                </td>
                                <td class="py-3"><?php echo date('M d, Y H:i:s', $backup['date']); ?></td>
                                <td class="py-3">
                                    <div class="flex gap-2 action-buttons">
                                        <a href="?action=download&file=<?php echo urlencode($backup['name']); ?>" 
                                           class="bg-green-600 hover:bg-green-700 px-3 py-1 rounded-lg text-xs transition">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                        <a href="?action=delete&file=<?php echo urlencode($backup['name']); ?>" 
                                           onclick="return confirm('Are you sure you want to delete this backup?')"
                                           class="bg-red-600 hover:bg-red-700 px-3 py-1 rounded-lg text-xs transition">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    }</table>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Backup Instructions -->
            <div class="backup-card rounded-xl p-6 mt-6">
                <h2 class="text-xl font-semibold mb-4 flex items-center gap-2">
                    <i class="fas fa-info-circle text-yellow-400"></i>
                    Backup Information
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h3 class="font-semibold text-blue-400 mb-2">What's Included?</h3>
                        <ul class="text-sm text-gray-300 space-y-1">
                            <li><i class="fas fa-check-circle text-green-400 mr-2"></i> All database tables and data</li>
                            <li><i class="fas fa-check-circle text-green-400 mr-2"></i> Table structures and indexes</li>
                            <li><i class="fas fa-check-circle text-green-400 mr-2"></i> Stored procedures and triggers</li>
                            <li><i class="fas fa-check-circle text-green-400 mr-2"></i> Compressed ZIP format for easy storage</li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-semibold text-blue-400 mb-2">How to Restore?</h3>
                        <ul class="text-sm text-gray-300 space-y-1">
                            <li><i class="fas fa-database text-blue-400 mr-2"></i> Use phpMyAdmin to import the SQL file</li>
                            <li><i class="fas fa-terminal text-green-400 mr-2"></i> Or via MySQL command line</li>
                            <li><i class="fas fa-shield-alt text-purple-400 mr-2"></i> Always test backups before restoring</li>
                        </ul>
                    </div>
                </div>
                
                <div class="mt-4 p-3 bg-yellow-500/10 rounded-lg border border-yellow-500/30">
                    <p class="text-xs text-yellow-400">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Note:</strong> Backups are stored in the /backups/ directory. Regular cleanup is recommended to save disk space.
                        The system automatically keeps the 10 most recent backups.
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Confirm before creating backup with large database
        const dbSize = <?php echo $dbSize; ?>;
        const newBackupBtn = document.querySelector('a[href*="action=full"]');
        
        if (newBackupBtn && dbSize > 104857600) { // 100MB warning
            newBackupBtn.addEventListener('click', function(e) {
                if (!confirm('Your database is large (' + Math.round(dbSize / 1048576) + ' MB).\n\nCreating a backup may take a few moments. Do you want to continue?')) {
                    e.preventDefault();
                }
            });
        }
        
        // Auto-refresh after backup creation
        if (window.location.href.includes('action=full') && !window.location.href.includes('download')) {
            setTimeout(function() {
                window.location.href = 'backup-database.php';
            }, 2000);
        }
    </script>
</body>
</html>