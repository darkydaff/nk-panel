# Design Spec: Daily External Database Sync & Concurrency Protection

This document details the design for automatically synchronizing client configuration data from the external PostgreSQL database into the local MySQL cache at least once a day, with concurrency protection and background execution.

## 1. Goal
Ensure the panel's client subscriber database (`ext_clients`) and router configurations remain synchronized with the external database at least once every 24 hours without:
- Blocking page rendering for administrators.
- Relying entirely on cron execution (acting as a robust fallback/safety net).
- Running redundant concurrent sync queries (locking execution).

## 2. Refactoring Sync Logic (`inc/ExtDB.php`)
Currently, client synchronization logic is duplicated in `bin/sync_external_clients.php` and the POST `/api/ext-clients/sync` endpoint in `public/index.php`. We will extract and centralize this inside `inc/ExtDB.php` as a static method `sync()`.

### Method Definition
```php
/**
 * Synchronize external Postgres database clients to local MySQL ext_clients table.
 * Implements locking to prevent concurrent sync executions.
 * 
 * @return array Array containing success status, records synced count, and warning/error messages.
 * @throws Exception If PostgreSQL connection fails.
 */
public static function sync(): array {
    $pdo = DB::conn();

    // 1. Acquire execution lock
    $stmtLock = $pdo->prepare("SELECT `value` FROM system_settings WHERE `key` = 'ext_clients_sync_running' LIMIT 1");
    $stmtLock->execute();
    $lockVal = $stmtLock->fetchColumn();
    if ($lockVal && (time() - strtotime($lockVal)) < 300) {
        return [
            'success' => true,
            'message' => 'Sync already in progress.',
            'count' => 0
        ];
    }

    $pdo->prepare("INSERT INTO system_settings (`key`, `value`) VALUES ('ext_clients_sync_running', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
        ->execute([date('Y-m-d H:i:s')]);

    try {
        // 2. Fetch records from PostgreSQL
        if (!self::isAvailable()) {
            throw new Exception("External PostgreSQL database is unreachable.");
        }

        $pgPdo = self::conn();
        $table = Config::get('EXT_PG_CLIENTS_TABLE', 'Clients');
        $stmt = $pgPdo->query("SELECT \"Code\", \"Name\", \"Start_Date\", \"Sub\", \"Func\", \"Router\", \"Domain\", \"Pass\", \"tgid\" FROM \"{$table}\" WHERE \"Code\" IS NOT NULL");
        $rawClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Import to MySQL in transaction
        $pdo->beginTransaction();
        $syncedCount = 0;
        $activeCodes = [];

        if (!empty($rawClients)) {
            $insertStmt = $pdo->prepare('
                INSERT INTO ext_clients (code, name, start_date, sub, func, router, domain, pass, tgid) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    name = VALUES(name), 
                    start_date = VALUES(start_date), 
                    sub = VALUES(sub), 
                    func = VALUES(func), 
                    router = VALUES(router),
                    domain = VALUES(domain),
                    pass = VALUES(pass),
                    tgid = VALUES(tgid)
            ');

            foreach ($rawClients as $row) {
                $code = trim($row['Code'] ?? '');
                if ($code === '') continue;
                $activeCodes[] = $code;
                
                $name = isset($row['Name']) ? trim($row['Name']) : null;
                $startDate = isset($row['Start_Date']) ? trim($row['Start_Date']) : null;
                if ($startDate === '') $startDate = null;
                $sub = isset($row['Sub']) ? (int)$row['Sub'] : null;
                $func = isset($row['Func']) ? trim($row['Func']) : null;
                $router = isset($row['Router']) ? trim($row['Router']) : null;
                $domain = isset($row['Domain']) ? trim($row['Domain']) : null;
                $pass = isset($row['Pass']) ? trim($row['Pass']) : null;
                $tgid = isset($row['tgid']) ? trim($row['tgid']) : null;
                if ($tgid === '') $tgid = null;

                $insertStmt->execute([$code, $name, $startDate, $sub, $func, $router, $domain, $pass, $tgid]);
                $syncedCount++;
            }

            // Remove deprecated clients
            if (!empty($activeCodes)) {
                $placeholders = implode(',', array_fill(0, count($activeCodes), '?'));
                $deleteStmt = $pdo->prepare("DELETE FROM ext_clients WHERE code NOT IN ($placeholders)");
                $deleteStmt->execute($activeCodes);
            } else {
                $pdo->exec('DELETE FROM ext_clients');
            }
        } else {
            $pdo->exec('DELETE FROM ext_clients');
        }
        $pdo->commit();

        // 4. Auto-link and Router synchronization hooks
        $warnings = [];
        try {
            VpnClient::autoLinkAll();
        } catch (Throwable $e) {
            $warnings[] = 'Auto-linking clients failed: ' . $e->getMessage();
        }

        try {
            require_once __DIR__ . '/RouterManager.php';
            RouterManager::syncRoutersFromExtClients();
        } catch (Throwable $e) {
            $warnings[] = 'Router connections sync failed: ' . $e->getMessage();
        }

        // 5. Update last sync timestamp
        $pdo->prepare("INSERT INTO system_settings (`key`, `value`) VALUES ('last_ext_clients_sync', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
            ->execute([date('Y-m-d H:i:s')]);

        // 6. Release execution lock
        $pdo->prepare("DELETE FROM system_settings WHERE `key` = 'ext_clients_sync_running'")->execute();

        return [
            'success' => true,
            'count' => $syncedCount,
            'warnings' => $warnings
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Cleanup lock on failure
        try {
            $pdo->prepare("DELETE FROM system_settings WHERE `key` = 'ext_clients_sync_running'")->execute();
        } catch (Throwable $lockEx) {}
        
        throw $e;
    }
}
```

## 3. Web Panel Auto-Trigger Integration
We will expose the status of the sync operation globally on the admin dashboard and trigger the background AJAX sync when appropriate.

### A. Global Page Load Check (`public/index.php`)
Before initializing the template views globally, we will check the status of the sync:
```php
$needsDbSync = false;
$user = Auth::user();
if ($user && Auth::isAdmin()) {
    try {
        $pdo = DB::conn();
        $stmtSync = $pdo->query("SELECT `value` FROM system_settings WHERE `key` = 'last_ext_clients_sync' LIMIT 1");
        $lastSyncVal = $stmtSync->fetchColumn();
        
        // If never synced or last sync is older than 24 hours (86400 seconds)
        if (!$lastSyncVal || (time() - strtotime($lastSyncVal)) > 86400) {
            $needsDbSync = true;
        }
    } catch (Throwable $e) {
        // Table does not exist or database unreachable
    }
}

// Pass to template context
View::init(__DIR__ . '/../templates', [
    // ...
    'needs_db_sync' => $needsDbSync,
]);
```

### B. Trigger Script in Layout (`templates/layout.twig`)
We will add a script tag inside the base layout block in `templates/layout.twig` that fires if `needs_db_sync` is passed as true:
```twig
{% if needs_db_sync %}
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Perform silent background synchronization of external database clients
        fetch('/api/ext-clients/sync', { 
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('[Sync] Background client synchronization completed. ' + (data.message || ('Synced ' + (data.count || 0) + ' records.')));
            } else {
                console.warn('[Sync] Background sync failed: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('[Sync] Error during background synchronization request: ', error);
        });
    });
</script>
{% endif %}
```

## 4. Endpoint & CLI Adaptation
We will update existing files to invoke the refactored `ExtDB::sync()` function.

### A. `/api/ext-clients/sync` API Route (`public/index.php`)
```php
Router::post('/api/ext-clients/sync', function () {
    requireAuth();
    header('Content-Type: application/json');

    try {
        $result = ExtDB::sync();
        echo json_encode($result);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
});
```

### B. CLI Sync Script (`bin/sync_external_clients.php`)
```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/ExtDB.php';

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
```

## 5. Verification & Test Plan
- **Database Lock Test**: Send consecutive requests to `/api/ext-clients/sync` concurrently. Verify that only the first request performs the sync work while the second request yields `"Sync already in progress."`.
- **Automatic Trigger Test**: Manually modify the `last_ext_clients_sync` value in `system_settings` to be `2020-01-01 00:00:00` (or delete it). Load the admin dashboard. Verify in the browser dev tools (Network tab) that an asynchronous `POST /api/ext-clients/sync` is fired and completes successfully.
- **Manual Trigger Test**: Click the "Sync DB" button on the clients screen, and verify it still updates the system status correctly.
- **CLI Trigger Test**: Execute `php bin/sync_external_clients.php` and verify correct exit codes and logs.
