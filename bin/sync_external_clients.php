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

    // 1. Fetch codes from external PostgreSQL
    $pgPdo = ExtDB::conn();
    $table = Config::get('EXT_PG_CLIENTS_TABLE', 'Clients');
    
    // Test connection first
    if (!ExtDB::isAvailable()) {
        throw new Exception("External PostgreSQL database is unreachable.");
    }

    $stmt = $pgPdo->query("SELECT \"Code\", \"Name\", \"Start_Date\", \"Sub\", \"Func\", \"Router\" FROM \"{$table}\" WHERE \"Code\" IS NOT NULL");
    $rawClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo $logPrefix . "Fetched " . count($rawClients) . " records from external PostgreSQL.\n";

    // 2. Sync to local MySQL ext_clients table
    $myPdo = DB::conn();
    
    // We use a transaction so the local list is not left empty if something fails
    $myPdo->beginTransaction();
    
    // Delete existing cached codes (transactional)
    $myPdo->exec('DELETE FROM ext_clients');
    
    // Batch insert new codes
    if (!empty($rawClients)) {
        $insertStmt = $myPdo->prepare('INSERT INTO ext_clients (code, name, start_date, sub, func, router) VALUES (?, ?, ?, ?, ?, ?)');
        $syncedCount = 0;
        foreach ($rawClients as $row) {
            $code = trim($row['Code'] ?? '');
            if ($code === '') {
                continue;
            }
            $name = isset($row['Name']) ? trim($row['Name']) : null;
            $startDate = isset($row['Start_Date']) ? trim($row['Start_Date']) : null;
            if ($startDate === '') $startDate = null;
            $sub = isset($row['Sub']) ? (int)$row['Sub'] : null;
            $func = isset($row['Func']) ? trim($row['Func']) : null;
            $router = isset($row['Router']) ? trim($row['Router']) : null;

            $insertStmt->execute([$code, $name, $startDate, $sub, $func, $router]);
            $syncedCount++;
        }
    }
    
    $myPdo->commit();

    // Store last sync timestamp
    try {
        $myPdo->prepare("INSERT INTO system_settings (`key`, `value`) VALUES ('last_ext_clients_sync', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
              ->execute([date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        echo $logPrefix . "WARNING: Failed to save sync timestamp: " . $e->getMessage() . "\n";
    }

    echo $logPrefix . "Successfully synchronized {$syncedCount} clients to MySQL cached ext_clients table.\n";
    exit(0);

} catch (Throwable $e) {
    if (isset($myPdo) && $myPdo->inTransaction()) {
        $myPdo->rollBack();
    }
    fwrite(STDERR, $logPrefix . "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
