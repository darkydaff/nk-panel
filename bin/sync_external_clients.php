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

    $stmt = $pgPdo->query("SELECT \"Code\" FROM \"{$table}\" WHERE \"Code\" IS NOT NULL");
    $rawCodes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $codes = array_map('trim', $rawCodes);
    $codes = array_filter($codes); // remove empty values

    echo $logPrefix . "Fetched " . count($codes) . " codes from external PostgreSQL.\n";

    // 2. Sync to local MySQL ext_clients table
    $myPdo = DB::conn();
    
    // We use a transaction so the local list is not left empty if something fails
    $myPdo->beginTransaction();
    
    // Delete existing cached codes (transactional)
    $myPdo->exec('DELETE FROM ext_clients');
    
    // Batch insert new codes
    if (!empty($codes)) {
        $insertStmt = $myPdo->prepare('INSERT INTO ext_clients (code) VALUES (?)');
        foreach ($codes as $code) {
            $insertStmt->execute([$code]);
        }
    }
    
    $myPdo->commit();
    echo $logPrefix . "Successfully synchronized " . count($codes) . " codes to MySQL cached ext_clients table.\n";
    exit(0);

} catch (Throwable $e) {
    if (isset($myPdo) && $myPdo->inTransaction()) {
        $myPdo->rollBack();
    }
    fwrite(STDERR, $logPrefix . "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
