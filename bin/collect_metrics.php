#!/usr/bin/env php
<?php

/**
 * Metrics Collector
 * 
 * Runs continuously and collects metrics every 30 seconds
 * Usage: php bin/collect_metrics.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/VpnServer.php';
require_once __DIR__ . '/../inc/VpnClient.php';
require_once __DIR__ . '/../inc/ServerMonitoring.php';

// Set timezone
date_default_timezone_set('UTC');

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/metrics_collector_errors.log');

// Write PID file for monitoring
$pidFile = '/var/run/collect_metrics.pid';
file_put_contents($pidFile, getmypid());

// Register shutdown function to clean up PID file
register_shutdown_function(function() use ($pidFile) {
    if (file_exists($pidFile)) {
        unlink($pidFile);
    }
});

echo "[" . date('Y-m-d H:i:s') . "] Metrics collector started (PID: " . getmypid() . ")\n";

// Main loop
while (true) {
    try {
        $startTime = microtime(true);
        
        // Get all active servers
        $servers = VpnServer::listAll();
        
        foreach ($servers as $server) {
            if ($server['status'] !== 'active') {
                continue;
            }
            
            try {
                echo "[" . date('Y-m-d H:i:s') . "] Collecting metrics for server #{$server['id']} ({$server['name']})\n";
                
                $monitoring = new ServerMonitoring($server['id']);
                
                // Collect client metrics
                $clientMetrics = $monitoring->collectClientMetrics();
                
                if (!empty($clientMetrics)) {
                    foreach ($clientMetrics as $cm) {
                        echo "  Client #{$cm['client_id']} ({$cm['client_name']}): UP={$cm['speed_up_kbps']}Kbps DOWN={$cm['speed_down_kbps']}Kbps\n";
                    }
                } else {
                    echo "  No active clients\n";
                }
                
            } catch (Exception $e) {
                echo "  ERROR: " . $e->getMessage() . "\n";
            }
        }
        
        // Clean old metrics
        ServerMonitoring::cleanOldMetrics();
        
        // Fetch current monitoring interval
        $stmtInterval = DB::conn()->prepare("SELECT value FROM settings WHERE namespace = 'monitoring' AND `key` = 'interval'");
        $stmtInterval->execute();
        $intervalVal = $stmtInterval->fetchColumn();
        $metricsInterval = $intervalVal ? (int)json_decode($intervalVal, true) : 30;

        // Calculate sleep time
        $executionTime = microtime(true) - $startTime;
        $sleepTime = max(0, $metricsInterval - $executionTime);
        
        echo "[" . date('Y-m-d H:i:s') . "] Collection completed in " . round($executionTime, 2) . "s, sleeping for " . round($sleepTime, 2) . "s\n\n";
        
        if ($sleepTime > 0) {
            sleep((int)$sleepTime);
        }
        
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] FATAL ERROR: " . $e->getMessage() . "\n";
        error_log("[FATAL] Metrics collector error: " . $e->getMessage());
        echo "Retrying in 30 seconds...\n\n";
        sleep(30);
    } catch (Error $e) {
        echo "[" . date('Y-m-d H:i:s') . "] CRITICAL ERROR: " . $e->getMessage() . "\n";
        error_log("[CRITICAL] Metrics collector error: " . $e->getMessage());
        echo "Retrying in 30 seconds...\n\n";
        sleep(30);
    }
}
