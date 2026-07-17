<?php
/**
 * CLI Script to sync external PostgreSQL client codes to local MySQL ext_clients table.
 * Can be run manually or via cron.
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/ExtDB.php';

// Load environment configuration
Config::load(__DIR__ . '/../.env');

$logPrefix = '[' . date('Y-m-d H:i:s') . '] ';

try {
    echo $logPrefix . "Starting client synchronization...\n";
    $result = ExtDB::sync();
    
    if (isset($result['message'])) {
        echo $logPrefix . $result['message'] . "\n";
    } else {
        echo $logPrefix . "Successfully synchronized {$result['count']} clients to MySQL cached ext_clients table.\n";
    }
    
    if (!empty($result['warnings'])) {
        foreach ($result['warnings'] as $warning) {
            echo $logPrefix . "WARNING: " . $warning . "\n";
        }
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $logPrefix . "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
