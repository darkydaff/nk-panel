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

    $stmt = $pgPdo->query("SELECT \"Code\", \"Name\", \"Start_Date\", \"Sub\", \"Func\", \"Router\", \"Domain\", \"Pass\" FROM \"{$table}\" WHERE \"Code\" IS NOT NULL");
    $rawClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo $logPrefix . "Fetched " . count($rawClients) . " records from external PostgreSQL.\n";

    // 2. Sync to local MySQL ext_clients table
    $myPdo = DB::conn();
    
    // We use a transaction so the local list is not left empty if something fails
    $myPdo->beginTransaction();
    
    // Batch insert/update new codes (non-destructive to preserve traffic)
    if (!empty($rawClients)) {
        $insertStmt = $myPdo->prepare('
            INSERT INTO ext_clients (code, name, start_date, sub, func, router, domain, pass) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                name = VALUES(name), 
                start_date = VALUES(start_date), 
                sub = VALUES(sub), 
                func = VALUES(func), 
                router = VALUES(router),
                domain = VALUES(domain),
                pass = VALUES(pass)
        ');
        $syncedCount = 0;
        $activeCodes = [];
        foreach ($rawClients as $row) {
            $code = trim($row['Code'] ?? '');
            if ($code === '') {
                continue;
            }
            $activeCodes[] = $code;
            $name = isset($row['Name']) ? trim($row['Name']) : null;
            $startDate = isset($row['Start_Date']) ? trim($row['Start_Date']) : null;
            if ($startDate === '') $startDate = null;
            $sub = isset($row['Sub']) ? (int)$row['Sub'] : null;
            $func = isset($row['Func']) ? trim($row['Func']) : null;
            $router = isset($row['Router']) ? trim($row['Router']) : null;
            $domain = isset($row['Domain']) ? trim($row['Domain']) : null;
            $pass = isset($row['Pass']) ? trim($row['Pass']) : null;

            $insertStmt->execute([$code, $name, $startDate, $sub, $func, $router, $domain, $pass]);
            $syncedCount++;
        }
        
        // Delete clients that are no longer in the external database
        if (!empty($activeCodes)) {
            $placeholders = implode(',', array_fill(0, count($activeCodes), '?'));
            $deleteStmt = $myPdo->prepare("DELETE FROM ext_clients WHERE code NOT IN ($placeholders)");
            $deleteStmt->execute($activeCodes);
        } else {
            $myPdo->exec('DELETE FROM ext_clients');
        }
    } else {
        $myPdo->exec('DELETE FROM ext_clients');
        $syncedCount = 0;
    }
    
    $myPdo->commit();

    // Run automatic client linking
    try {
        require_once __DIR__ . '/../inc/VpnClient.php';
        $linkedCount = VpnClient::autoLinkAll();
        echo $logPrefix . "Automatically linked {$linkedCount} configurations to client codes.\n";
    } catch (Throwable $e) {
        echo $logPrefix . "WARNING: Failed to auto-link clients: " . $e->getMessage() . "\n";
    }

    // Run router synchronization
    try {
        require_once __DIR__ . '/../inc/KeeneticRouter.php';
        require_once __DIR__ . '/../inc/RouterManager.php';
        $syncedRouters = RouterManager::syncRoutersFromExtClients();
        echo $logPrefix . "Automatically synchronized {$syncedRouters} router connections from external clients.\n";
    } catch (Throwable $e) {
        echo $logPrefix . "WARNING: Failed to sync router connections: " . $e->getMessage() . "\n";
    }

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
