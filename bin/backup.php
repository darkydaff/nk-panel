<?php
/**
 * CLI Backup Runner for Cron
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/BackupManager.php';
require_once __DIR__ . '/../inc/VpnServer.php';
require_once __DIR__ . '/../inc/VpnClient.php';

Config::load(__DIR__ . '/../.env');

try {
    $pdo = DB::conn();
    
    // Get settings schedule
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE namespace = 'backup' AND `key` = 'telegram_settings'");
    $stmt->execute();
    $res = $stmt->fetch();
    
    $schedule = 'disabled';
    if ($res) {
        $settings = json_decode($res['value'], true);
        $schedule = $settings['schedule'] ?? 'disabled';
    }

    if ($schedule === 'disabled') {
        echo "Backups are currently scheduled as disabled.\n";
        exit(0);
    }

    // Read time conditions (e.g. run daily if 24h passed since last auto backup)
    $stmtLast = $pdo->prepare("SELECT created_at FROM server_backups WHERE backup_type = 'automatic' AND status = 'completed' ORDER BY created_at DESC LIMIT 1");
    $stmtLast->execute();
    $last = $stmtLast->fetch();

    $shouldRun = false;
    if (!$last) {
        $shouldRun = true;
    } else {
        $lastTime = strtotime($last['created_at']);
        $diff = time() - $lastTime;
        if ($schedule === 'daily' && $diff >= 86000) { // ~24h
            $shouldRun = true;
        } elseif ($schedule === 'weekly' && $diff >= 604000) { // ~7 days
            $shouldRun = true;
        }
    }

    if ($shouldRun) {
        echo "Triggering automated backup...\n";
        $bm = new BackupManager();
        $path = $bm->createPanelBackup(0, 'automatic');
        echo "Backup zip created: {$path}\n";
        
        $errorReason = '';
        if ($bm->sendToTelegram($path, $errorReason)) {
            echo "Backup successfully uploaded to Telegram.\n";
        } else {
            echo "Telegram upload failed: " . (!empty($errorReason) ? $errorReason : "Disabled or not configured") . "\n";
        }
        
        $pruned = $bm->pruneLocalBackups();
        echo "Pruned {$pruned} expired local backups.\n";
    } else {
        echo "No backup is scheduled to run at this moment.\n";
    }
} catch (Exception $e) {
    echo "Backup execution failed: " . $e->getMessage() . "\n";
    exit(1);
}
