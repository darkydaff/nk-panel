<?php
/**
 * Background Router Connectivity Check Job
 * Runs via cron to poll connected router states and update database records
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/KeeneticRouter.php';
require_once __DIR__ . '/../inc/RouterManager.php';

$logPrefix = "[" . date('Y-m-d H:i:s') . "] [RouterCheck] ";

try {
    echo $logPrefix . "Starting router status verification...\n";
    
    // Check all routers
    $results = RouterManager::checkAllRouters();
    
    $successCount = 0;
    $offlineCount = 0;
    $errorCount = 0;
    
    foreach ($results as $routerId => $res) {
        if ($res['success']) {
            if ($res['status'] === 'connected') {
                $successCount++;
            } else {
                $errorCount++;
            }
        } else {
            if (isset($res['status']) && $res['status'] === 'offline') {
                $offlineCount++;
            } else {
                $errorCount++;
            }
        }
    }
    
    echo $logPrefix . "Completed. Connected: {$successCount}, Offline: {$offlineCount}, Errors: {$errorCount}.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $logPrefix . "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
