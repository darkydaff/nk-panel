<?php
/**
 * External PostgreSQL Database Connection
 * Connects to the external NoCoDB Postgres instance that holds the real Clients table.
 * Credentials are read from EXT_PG_* environment variables.
 * This class is only instantiated on Clients-related routes; Postgres being
 * unreachable will NOT affect the main MySQL-backed app.
 */
class ExtDB {
    private static ?PDO $pdo = null;

    public static function conn(): PDO {
        if (self::$pdo) return self::$pdo;

        $host   = Config::get('EXT_PG_HOST', '157.22.175.250');
        $port   = Config::get('EXT_PG_PORT', '5434');
        $db     = Config::get('EXT_PG_DB',   'nocodb');
        $user   = Config::get('EXT_PG_USER', 'nocodb');
        $pass   = Config::get('EXT_PG_PASSWORD', 'nocodb');
        $schema = Config::get('EXT_PG_SCHEMA', 'public');

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $db);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        self::$pdo = new PDO($dsn, $user, $pass, $options);

        // Set default search path
        self::$pdo->exec("SET search_path TO " . pg_escape_identifier_compat($schema));

        return self::$pdo;
    }

    /**
     * Reset the connection (useful after a connection failure, for retry logic).
     */
    public static function reset(): void {
        self::$pdo = null;
    }

    /**
     * Test whether the external DB is reachable.
     */
    public static function isAvailable(): bool {
        try {
            self::conn()->query('SELECT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Search for client codes in the external Clients table.
     *
     * @param string $search    Optional search term (matched against Code with ILIKE).
     * @param int    $limit     Max rows to return.
     * @param int    $offset    Row offset for pagination.
     * @return array            Array of rows with at least a 'Code' key.
     */
    public static function searchClients(string $search = '', int $limit = 50, int $offset = 0): array {
        $pdo   = self::conn();
        $table = Config::get('EXT_PG_CLIENTS_TABLE', 'Clients');

        if ($search !== '') {
            $stmt = $pdo->prepare(
                "SELECT \"Code\" FROM \"{$table}\" WHERE \"Code\" ILIKE ? ORDER BY \"Code\" LIMIT ? OFFSET ?"
            );
            $stmt->bindValue(1, '%' . $search . '%', PDO::PARAM_STR);
            $stmt->bindValue(2, $limit,  PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare(
                "SELECT \"Code\" FROM \"{$table}\" ORDER BY \"Code\" LIMIT ? OFFSET ?"
            );
            $stmt->bindValue(1, $limit,  PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
        }

        return $stmt->fetchAll();
    }

    /**
     * Check whether a given Code exists in the external Clients table.
     */
    public static function clientCodeExists(string $code): bool {
        $pdo = self::conn();
        $table = Config::get('EXT_PG_CLIENTS_TABLE', 'Clients');
        $stmt = $pdo->prepare("SELECT 1 FROM \"{$table}\" WHERE \"Code\" = ? LIMIT 1");
        $stmt->execute([$code]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Count total clients matching optional search term.
     */
    public static function countClients(string $search = ''): int {
        $pdo = self::conn();
        $table = Config::get('EXT_PG_CLIENTS_TABLE', 'Clients');

        if ($search !== '') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM \"{$table}\" WHERE \"Code\" ILIKE ?");
            $stmt->execute(['%' . $search . '%']);
        } else {
            $stmt = $pdo->query("SELECT COUNT(*) FROM \"{$table}\"");
        }

        return (int)$stmt->fetchColumn();
    }

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
}

/**
 * pg_escape_identifier_compat — wraps the schema name safely.
 * Falls back to simple quoting if the native function is not available.
 */
function pg_escape_identifier_compat(string $id): string {
    // Replace any double quotes inside the identifier to prevent injection
    return '"' . str_replace('"', '""', $id) . '"';
}
