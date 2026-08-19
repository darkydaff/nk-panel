<?php
/**
 * External PostgreSQL Database Connection
 * Connects to the external NoCoDB Postgres instance that holds the real Clients table.
 * Credentials are read from EXT_PG_* environment variables.
 * This class is only instantiated on Clients-related routes; Postgres being
 * unreachable will NOT affect the main MySQL-backed app.
 */
class ExtDB
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo)
            return self::$pdo;

        $host = Config::get('EXT_PG_HOST', 'pgdb');
        $port = Config::get('EXT_PG_PORT', '5432');
        $db = Config::get('EXT_PG_DB', 'nocodb');
        $user = Config::get('EXT_PG_USER', 'nocodb');
        $pass = Config::get('EXT_PG_PASSWORD', 'nocodb');
        $schema = Config::get('EXT_PG_SCHEMA', 'public');

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $db);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        self::$pdo = new PDO($dsn, $user, $pass, $options);

        // Set default search path
        self::$pdo->exec("SET search_path TO " . pg_escape_identifier_compat($schema));

        return self::$pdo;
    }

    /**
     * Reset the connection (useful after a connection failure, for retry logic).
     */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    /**
     * Test whether the external DB is reachable.
     */
    public static function isAvailable(): bool
    {
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
    public static function searchClients(string $search = '', int $limit = 50, int $offset = 0): array
    {
        $pdo = self::conn();
        $table = Config::get('EXT_PG_CLIENTS_TABLE', 'Clients');

        $search = trim($search);
        if ($search !== '') {
            $cleanSearch = ltrim($search, '#');
            $stmt = $pdo->prepare(
                "SELECT \"Code\", \"Name\", \"Start_Date\", \"Sub\", \"Func\", \"Router\", \"Domain\", \"tgid\" 
                 FROM \"{$table}\" 
                 WHERE \"Code\" ILIKE ? 
                    OR \"Code\" ILIKE ? 
                    OR \"Name\" ILIKE ? 
                    OR \"tgid\"::text ILIKE ?
                    OR \"Router\" ILIKE ?
                 ORDER BY \"Code\" LIMIT ? OFFSET ?"
            );
            $stmt->bindValue(1, '%' . $search . '%', PDO::PARAM_STR);
            $stmt->bindValue(2, '%' . $cleanSearch . '%', PDO::PARAM_STR);
            $stmt->bindValue(3, '%' . $search . '%', PDO::PARAM_STR);
            $stmt->bindValue(4, '%' . $cleanSearch . '%', PDO::PARAM_STR);
            $stmt->bindValue(5, '%' . $search . '%', PDO::PARAM_STR);
            $stmt->bindValue(6, $limit, PDO::PARAM_INT);
            $stmt->bindValue(7, $offset, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare(
                "SELECT \"Code\", \"Name\", \"Start_Date\", \"Sub\", \"Func\", \"Router\", \"Domain\", \"tgid\" 
                 FROM \"{$table}\" 
                 ORDER BY \"Code\" LIMIT ? OFFSET ?"
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Check whether a given Code exists in the external Clients table.
     */
    public static function clientCodeExists(string $code): bool
    {
        $pdo = self::conn();
        $table = Config::get('EXT_PG_CLIENTS_TABLE', 'Clients');
        $stmt = $pdo->prepare("SELECT 1 FROM \"{$table}\" WHERE \"Code\" = ? LIMIT 1");
        $stmt->execute([$code]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Count total clients matching optional search term.
     */
    public static function countClients(string $search = ''): int
    {
        $pdo = self::conn();
        $table = Config::get('EXT_PG_CLIENTS_TABLE', 'Clients');

        if ($search !== '') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM \"{$table}\" WHERE \"Code\" ILIKE ?");
            $stmt->execute(['%' . $search . '%']);
        } else {
            $stmt = $pdo->query("SELECT COUNT(*) FROM \"{$table}\"");
        }

        return (int) $stmt->fetchColumn();
    }

    /**
     * Synchronize external Postgres database clients to local MySQL ext_clients table.
     * Implements locking to prevent concurrent sync executions.
     * 
     * @return array Array containing success status, records synced count, and warning/error messages.
     * @throws Exception If PostgreSQL connection fails.
     */
    public static function sync(): array
    {
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

            // Fetch latest income payment per client from Finances_2026 table
            $paymentsMap = [];
            try {
                $payStmt = $pgPdo->query("
                    SELECT DISTINCT ON (\"Client_id\") \"Client_id\"::text AS client_id, \"Date\"::text AS date, \"Amount\"::numeric AS amount
                    FROM \"Finances_2026\"
                    WHERE TRIM(LOWER(\"Type\"::text)) = 'income' AND \"Client_id\" IS NOT NULL AND TRIM(\"Client_id\"::text) != ''
                    ORDER BY \"Client_id\", \"Date\"::text DESC, id DESC
                ");
                $rawPayments = $payStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rawPayments as $p) {
                    $cid = trim($p['client_id'] ?? '');
                    if ($cid === '')
                        continue;

                    $rawDate = trim($p['date'] ?? '');
                    $normDate = null;
                    if (!empty($rawDate)) {
                        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $rawDate, $m)) {
                            $normDate = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                        } elseif (strtotime($rawDate) !== false) {
                            $normDate = date('Y-m-d', strtotime($rawDate));
                        }
                    }

                    $amt = (isset($p['amount']) && $p['amount'] !== '') ? (float) $p['amount'] : null;
                    $paymentsMap[$cid] = [
                        'date' => $normDate,
                        'amount' => $amt,
                    ];
                }
            } catch (Throwable $e) {
                error_log("ExtDB sync payment fetch notice: " . $e->getMessage());
            }

            // Ensure last_payment_date column exists in local ext_clients table
            try {
                $colCheck = $pdo->query("SHOW COLUMNS FROM ext_clients LIKE 'last_payment_date'");
                if ($colCheck->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE ext_clients ADD COLUMN last_payment_date DATE NULL, ADD COLUMN last_payment_amount DECIMAL(10,2) NULL");
                }
            } catch (Throwable $e) {
                error_log("ExtDB sync column check notice: " . $e->getMessage());
            }

            // 3. Import to MySQL in transaction
            $pdo->beginTransaction();
            $syncedCount = 0;
            $activeCodes = [];

            if (!empty($rawClients)) {
                $insertStmt = $pdo->prepare('
                    INSERT INTO ext_clients (code, name, start_date, sub, func, router, domain, pass, tgid, last_payment_date, last_payment_amount) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        name = VALUES(name), 
                        start_date = VALUES(start_date), 
                        sub = VALUES(sub), 
                        func = VALUES(func), 
                        router = VALUES(router),
                        domain = VALUES(domain),
                        pass = VALUES(pass),
                        tgid = VALUES(tgid),
                        last_payment_date = VALUES(last_payment_date),
                        last_payment_amount = VALUES(last_payment_amount)
                ');

                foreach ($rawClients as $row) {
                    $code = trim($row['Code'] ?? '');
                    if ($code === '')
                        continue;
                    $activeCodes[] = $code;

                    $name = isset($row['Name']) ? trim($row['Name']) : null;
                    $startDate = isset($row['Start_Date']) ? trim($row['Start_Date']) : null;
                    if ($startDate === '')
                        $startDate = null;
                    $sub = isset($row['Sub']) ? (int) $row['Sub'] : null;
                    $func = isset($row['Func']) ? trim($row['Func']) : null;
                    $router = isset($row['Router']) ? trim($row['Router']) : null;
                    $domain = isset($row['Domain']) ? trim($row['Domain']) : null;
                    $pass = isset($row['Pass']) ? trim($row['Pass']) : null;
                    $tgid = isset($row['tgid']) ? trim($row['tgid']) : null;
                    if ($tgid === '')
                        $tgid = null;

                    $payInfo = $paymentsMap[$code] ?? null;
                    $lastPayDate = $payInfo['date'] ?? null;
                    $lastPayAmt = $payInfo['amount'] ?? null;

                    $insertStmt->execute([$code, $name, $startDate, $sub, $func, $router, $domain, $pass, $tgid, $lastPayDate, $lastPayAmt]);
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

            try {
                self::syncAllClientServerIds();
            } catch (Throwable $e) {
                $warnings[] = 'External DB server IDs sync failed: ' . $e->getMessage();
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
            } catch (Throwable $lockEx) {
            }

            throw $e;
        }
    }

    /**
     * Fetch all servers from external PostgreSQL "Servers" table.
     *
     * @return array Array of server records with keys: id, Servers (name), Country, Exp_Date
     */
    public static function getExternalServers(): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        try {
            $pgPdo = self::conn();
            $stmt = $pgPdo->query('SELECT "id", "Servers", "Country", "Exp_Date" FROM "Servers" ORDER BY "id" ASC');
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log("Failed to fetch external PostgreSQL servers: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get the latest server record (from MySQL) connected/assigned to a client code.
     */
    public static function getLatestServerForClientCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '')
            return null;

        $rawCode = ltrim($code, '#');
        $hashCode = '#' . $rawCode;

        $pdo = DB::conn();

        // 1. Check routers table first (connected server)
        $stmtRouter = $pdo->prepare("
            SELECT s.id, s.name, s.ext_server_id 
            FROM routers r 
            JOIN vpn_servers s ON r.server_id = s.id 
            WHERE (r.ext_client_code = ? OR r.ext_client_code = ?) 
              AND r.server_id IS NOT NULL 
            LIMIT 1
        ");
        $stmtRouter->execute([$code, $hashCode]);
        $server = $stmtRouter->fetch(PDO::FETCH_ASSOC);
        if ($server)
            return $server;

        // 2. Fall back to active vpn_clients configs
        $stmtClient = $pdo->prepare("
            SELECT s.id, s.name, s.ext_server_id 
            FROM vpn_clients c 
            JOIN vpn_servers s ON c.server_id = s.id 
            WHERE (c.ext_client_code = ? OR c.ext_client_code = ?) 
              AND c.status = 'active' 
            ORDER BY c.updated_at DESC, c.created_at DESC 
            LIMIT 1
        ");
        $stmtClient->execute([$code, $hashCode]);
        $server = $stmtClient->fetch(PDO::FETCH_ASSOC);
        return $server ?: null;
    }

    /**
     * Synchronize a specific client's Servers_id in external PostgreSQL based on their latest panel server.
     */
    public static function syncClientServerId(string $code): bool
    {
        if (!self::isAvailable()) {
            return false;
        }

        $code = trim($code);
        if ($code === '')
            return false;

        $localServer = self::getLatestServerForClientCode($code);
        if (!$localServer) {
            return false;
        }

        $extServerId = null;
        if (!empty($localServer['ext_server_id'])) {
            $extServerId = (int) $localServer['ext_server_id'];
        } else {
            // Find server by name in external Postgres "Servers" table
            $pgPdo = self::conn();
            $stmtSearch = $pgPdo->prepare('SELECT "id" FROM "Servers" WHERE "Servers" ILIKE ? LIMIT 1');
            $stmtSearch->execute([trim($localServer['name'])]);
            $foundId = $stmtSearch->fetchColumn();
            if ($foundId !== false) {
                $extServerId = (int) $foundId;
            }
        }

        if ($extServerId === null) {
            return false;
        }

        $rawCode = ltrim($code, '#');
        $hashCode = '#' . $rawCode;

        $pgPdo = self::conn();
        $table = Config::get('EXT_PG_CLIENTS_TABLE', 'Clients');
        $stmtUpd = $pgPdo->prepare("UPDATE \"{$table}\" SET \"Servers_id\" = ? WHERE \"Code\" = ? OR \"Code\" = ?");
        $stmtUpd->execute([$extServerId, $code, $hashCode]);
        return $stmtUpd->rowCount() > 0;
    }

    /**
     * Synchronize all clients' Servers_id in external PostgreSQL based on their latest panel servers.
     */
    public static function syncAllClientServerIds(): int
    {
        if (!self::isAvailable()) {
            return 0;
        }

        $pdo = DB::conn();
        // Gather all distinct client codes from ext_clients, routers, and vpn_clients
        $stmtCodes = $pdo->query("
            SELECT DISTINCT code FROM (
                SELECT code FROM ext_clients WHERE code IS NOT NULL AND code != ''
                UNION
                SELECT ext_client_code AS code FROM routers WHERE ext_client_code IS NOT NULL AND ext_client_code != ''
                UNION
                SELECT ext_client_code AS code FROM vpn_clients WHERE ext_client_code IS NOT NULL AND ext_client_code != ''
            ) AS combined_codes
        ");
        $codes = $stmtCodes->fetchAll(PDO::FETCH_COLUMN);

        $syncedCount = 0;
        foreach ($codes as $code) {
            try {
                if (self::syncClientServerId($code)) {
                    $syncedCount++;
                }
            } catch (Throwable $e) {
                error_log("Failed to sync server ID for client $code: " . $e->getMessage());
            }
        }
        return $syncedCount;
    }

    /**
     * Creates a standalone backup of the external PostgreSQL database.
     *
     * @param int $userId ID of the user performing the backup
     * @param string $type Backup trigger type ('manual' or 'automatic')
     * @return string Path to the created .sql backup file
     * @throws Exception If PostgreSQL is unreachable or dump fails
     */
    public static function createBackup(int $userId = 1, string $type = 'manual'): string
    {
        if (!self::isAvailable()) {
            throw new Exception("External PostgreSQL database is unreachable.");
        }

        $backupDir = '/var/www/html/backups/ext_db';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = date('Y-m-d_His');
        $backupFileName = "ext_pg_backup_{$timestamp}.sql";
        $backupPath = "{$backupDir}/{$backupFileName}";

        $pgHost = Config::get('EXT_PG_HOST', '157.22.175.250');
        $pgPort = Config::get('EXT_PG_PORT', '5434');
        $pgDb = Config::get('EXT_PG_DB', 'nocodb');
        $pgUser = Config::get('EXT_PG_USER', 'nocodb');
        $pgPass = Config::get('EXT_PG_PASSWORD', 'nocodb');

        $pgHostEsc = escapeshellarg($pgHost);
        $pgPortEsc = escapeshellarg($pgPort);
        $pgUserEsc = escapeshellarg($pgUser);
        $pgDbEsc = escapeshellarg($pgDb);
        $backupPathEsc = escapeshellarg($backupPath);

        $errPath = "/tmp/ext_pg_dump_{$timestamp}.err";
        $errPathEsc = escapeshellarg($errPath);

        // Try pg_dump CLI first
        $cmd = "PGPASSWORD=" . escapeshellarg($pgPass) . " pg_dump -h {$pgHostEsc} -p {$pgPortEsc} -U {$pgUserEsc} -d {$pgDbEsc} -F p --clean --if-exists > {$backupPathEsc} 2> {$errPathEsc}";
        @exec($cmd, $output, $returnVar);

        $dumpSuccess = ($returnVar === 0 && file_exists($backupPath) && filesize($backupPath) > 0);

        if (!$dumpSuccess) {
            // Fallback: Built-in PHP-native SQL Dumper via PDO
            self::dumpPostgresViaPdo($backupPath);
        }

        if (file_exists($errPath)) {
            @unlink($errPath);
        }

        if (!file_exists($backupPath) || filesize($backupPath) === 0) {
            throw new Exception("Failed to create external PostgreSQL database backup file.");
        }

        $fileSize = filesize($backupPath);

        // Insert record into local MySQL server_backups table
        $pdo = DB::conn();
        $stmtIns = $pdo->prepare("
            INSERT INTO server_backups 
            (server_id, backup_name, backup_path, backup_size, backup_type, status, created_by, backup_scope) 
            VALUES (NULL, ?, ?, ?, ?, 'completed', ?, 'ext_db')
        ");
        $stmtIns->execute([$backupFileName, $backupPath, $fileSize, $type, $userId]);

        // Trigger Telegram backup notification if configured
        try {
            require_once __DIR__ . '/BackupManager.php';
            $bm = new BackupManager();
            $errReason = '';
            $bm->sendToTelegram($backupPath, $errReason);
        } catch (Throwable $e) {
            error_log("Failed to send external DB backup to Telegram: " . $e->getMessage());
        }

        return $backupPath;
    }

    /**
     * Native PHP/PDO fallback SQL dumper for the whole external PostgreSQL database.
     */
    private static function dumpPostgresViaPdo(string $outputPath): void
    {
        $pgPdo = self::conn();

        $tablesStmt = $pgPdo->query("
            SELECT table_schema, table_name 
            FROM information_schema.tables 
            WHERE table_schema NOT IN ('pg_catalog', 'information_schema') 
              AND table_type = 'BASE TABLE'
            ORDER BY table_schema, table_name
        ");
        $tables = $tablesStmt->fetchAll(PDO::FETCH_ASSOC);

        $sql = "-- Whole External PostgreSQL Database Dump (pg_dump compatible)\n";
        $sql .= "-- Database: " . Config::get('EXT_PG_DB', 'nocodb') . "\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s UTC') . "\n\n";
        $sql .= "SET statement_timeout = 0;\n";
        $sql .= "SET client_encoding = 'UTF8';\n";
        $sql .= "SET standard_conforming_strings = on;\n\n";

        foreach ($tables as $tRow) {
            $schema = $tRow['table_schema'];
            $table = $tRow['table_name'];
            $quotedTable = '"' . str_replace('"', '""', $schema) . '"."' . str_replace('"', '""', $table) . '"';

            $sql .= "-- Table: {$schema}.{$table}\n";
            $sql .= "DROP TABLE IF EXISTS {$quotedTable} CASCADE;\n\n";

            // Fetch columns
            $colsStmt = $pgPdo->prepare("
                SELECT column_name, data_type, character_maximum_length, is_nullable, column_default 
                FROM information_schema.columns 
                WHERE table_schema = ? AND table_name = ? 
                ORDER BY ordinal_position
            ");
            $colsStmt->execute([$schema, $table]);
            $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);

            $colDefs = [];
            foreach ($cols as $col) {
                $cName = '"' . str_replace('"', '""', $col['column_name']) . '"';
                $cType = strtoupper($col['data_type']);
                if ($col['character_maximum_length']) {
                    $cType .= "({$col['character_maximum_length']})";
                }
                $nullDef = ($col['is_nullable'] === 'NO') ? ' NOT NULL' : '';
                $defaultDef = !empty($col['column_default']) ? ' DEFAULT ' . $col['column_default'] : '';
                $colDefs[] = "  {$cName} {$cType}{$defaultDef}{$nullDef}";
            }

            $sql .= "CREATE TABLE {$quotedTable} (\n" . implode(",\n", $colDefs) . "\n);\n\n";

            // Fetch rows
            $dataStmt = $pgPdo->query("SELECT * FROM {$quotedTable}");
            $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($rows)) {
                $colNames = array_keys($rows[0]);
                $quotedColNames = implode(', ', array_map(fn($c) => '"' . str_replace('"', '""', $c) . '"', $colNames));

                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $vals[] = 'NULL';
                        } elseif (is_bool($val)) {
                            $vals[] = $val ? 'TRUE' : 'FALSE';
                        } elseif (is_numeric($val)) {
                            $vals[] = $val;
                        } else {
                            $vals[] = $pgPdo->quote($val);
                        }
                    }
                    $sql .= "INSERT INTO {$quotedTable} ({$quotedColNames}) VALUES (" . implode(', ', $vals) . ");\n";
                }
                $sql .= "\n";
            }
        }

        file_put_contents($outputPath, $sql);
    }

    /**
     * Restore external PostgreSQL database from a SQL backup file.
     *
     * @param string $backupPath Path to the SQL backup file
     * @return bool True on success
     * @throws Exception If restoration fails
     */
    public static function restoreBackup(string $backupPath): bool
    {
        if (!file_exists($backupPath)) {
            throw new Exception("Backup file not found at: {$backupPath}");
        }

        if (!self::isAvailable()) {
            throw new Exception("External PostgreSQL database is unreachable.");
        }

        $pgHost = Config::get('EXT_PG_HOST', 'pgdb');
        $pgPort = Config::get('EXT_PG_PORT', '5432');
        $pgDb = Config::get('EXT_PG_DB', 'nocodb');
        $pgUser = Config::get('EXT_PG_USER', 'nocodb');
        $pgPass = Config::get('EXT_PG_PASSWORD', 'nocodb');

        $pgHostEsc = escapeshellarg($pgHost);
        $pgPortEsc = escapeshellarg($pgPort);
        $pgUserEsc = escapeshellarg($pgUser);
        $pgDbEsc = escapeshellarg($pgDb);
        $backupPathEsc = escapeshellarg($backupPath);

        $errPath = "/tmp/ext_pg_restore_" . time() . ".err";
        $errPathEsc = escapeshellarg($errPath);

        // Try psql CLI first
        $cmd = "PGPASSWORD=" . escapeshellarg($pgPass) . " psql -h {$pgHostEsc} -p {$pgPortEsc} -U {$pgUserEsc} -d {$pgDbEsc} -f {$backupPathEsc} 2> {$errPathEsc}";
        @exec($cmd, $output, $returnVar);

        if ($returnVar === 0) {
            if (file_exists($errPath))
                @unlink($errPath);
            return true;
        }

        // Fallback to PDO execution
        $sqlContent = file_get_contents($backupPath);
        if (empty(trim($sqlContent))) {
            throw new Exception("Backup file is empty.");
        }

        $pgPdo = self::conn();
        $pgPdo->exec($sqlContent);

        if (file_exists($errPath))
            @unlink($errPath);
        return true;
    }

    /**
     * Get single client record by Code from PostgreSQL Clients table.
     */
    public static function getClientByCode(string $code): ?array
    {
        if (!self::isAvailable()) return null;
        $pdo = self::conn();
        $table = Config::get('EXT_PG_CLIENTS_TABLE', 'Clients');
        $stmt = $pdo->prepare("SELECT * FROM \"{$table}\" WHERE \"Code\" = ? LIMIT 1");
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Extend a client's subscription by N months (+30 days per month).
     * Strictly increments Sub by N without altering Start_Date (leaving Start_Date to manual edits).
     * Also activates status to WORK if paused.
     */
    public static function extendClientSubscription(string $code, int $months = 1): array
    {
        if (!self::isAvailable()) throw new Exception("PostgreSQL database is unreachable.");
        $client = self::getClientByCode($code);
        if (!$client) throw new Exception("Client with code '{$code}' not found in external database.");

        $pdo = self::conn();
        $table = Config::get('EXT_PG_CLIENTS_TABLE', 'Clients');

        $startDateStr = $client['Start_Date'] ?? null;
        $currentSub = isset($client['Sub']) && $client['Sub'] !== null && is_numeric($client['Sub']) ? (int)$client['Sub'] : 0;
        
        // Never change Start_Date; simply increment Sub
        $newSub = max(1, $currentSub + $months);

        $stmt = $pdo->prepare("
            UPDATE \"{$table}\" 
            SET \"Sub\" = ?, \"Func\" = 'WORK'
            WHERE \"Code\" = ?
        ");
        $stmt->execute([$newSub, $code]);

        // Compute new expiration date
        $expiryFormatted = '-';
        $daysLeft = null;
        if ($startDateStr) {
            $startTs = strtotime($startDateStr);
            if ($startTs) {
                $expiryTs = $startTs + ($newSub * 30 * 86400);
                $expiryFormatted = date('d.m.Y', $expiryTs);
                $daysLeft = (int)round(($expiryTs - strtotime(date('Y-m-d'))) / 86400);
            }
        }

        try {
            self::sync();
        } catch (Throwable $e) {}

        return [
            'success' => true,
            'code' => $code,
            'start_date' => $startDateStr,
            'sub' => $newSub,
            'expiry_date' => $expiryFormatted,
            'days_left' => $daysLeft
        ];
    }

    /**
     * Create a new client record in PostgreSQL Clients table and sync locally.
     */
    public static function createClient(array $data): bool
    {
        if (!self::isAvailable()) throw new Exception("PostgreSQL database is unreachable.");
        $pdo = self::conn();
        $table = Config::get('EXT_PG_CLIENTS_TABLE', 'Clients');

        $code = trim($data['Code'] ?? '');
        if (empty($code)) throw new Exception("Client Code is required.");

        $cols = ['Code'];
        $vals = [$code];
        $placeholders = ['?'];

        $allowedCols = ['Name', 'Start_Date', 'Sub', 'Func', 'Router', 'Domain', 'Pass', 'tgid', 'Servers_id'];
        foreach ($allowedCols as $col) {
            if (array_key_exists($col, $data)) {
                $cols[] = "\"{$col}\"";
                $placeholders[] = '?';
                $val = $data[$col];
                if ($val === '' || $val === '-' || strtolower((string)$val) === 'null') $val = null;
                if ($col === 'Sub' || $col === 'Servers_id') {
                    $val = ($val !== null && $val !== '') ? (int)$val : null;
                } elseif ($col === 'Start_Date' && $val !== null) {
                    $val = self::normalizeDateToIso((string)$val);
                }
                $vals[] = $val;
            }
        }

        $colList = implode(', ', $cols);
        $phList = implode(', ', $placeholders);

        $stmt = $pdo->prepare("INSERT INTO \"{$table}\" ({$colList}) VALUES ({$phList})");
        $res = $stmt->execute($vals);

        try {
            self::sync();
        } catch (Throwable $e) {
            error_log("Failed to sync client after creation: " . $e->getMessage());
        }

        return $res;
    }

    /**
     * Update an existing client record in PostgreSQL Clients table and sync locally.
     */
    public static function updateClient(string $originalCode, array $data): bool
    {
        if (!self::isAvailable()) throw new Exception("PostgreSQL database is unreachable.");
        $pdo = self::conn();
        $table = Config::get('EXT_PG_CLIENTS_TABLE', 'Clients');

        $set = [];
        $params = [];

        $allowedCols = ['Code', 'Name', 'Start_Date', 'Sub', 'Func', 'Router', 'Domain', 'Pass', 'tgid', 'Servers_id'];
        foreach ($allowedCols as $col) {
            if (array_key_exists($col, $data)) {
                $set[] = "\"{$col}\" = ?";
                $val = $data[$col];
                if ($val === '' || $val === '-' || strtolower((string)$val) === 'null') $val = null;
                if ($col === 'Sub' || $col === 'Servers_id') {
                    $val = ($val !== null && $val !== '') ? (int)$val : null;
                } elseif ($col === 'Start_Date' && $val !== null) {
                    $val = self::normalizeDateToIso((string)$val);
                }
                $params[] = $val;
            }
        }

        if (empty($set)) return false;

        $params[] = $originalCode;
        $setList = implode(', ', $set);

        $stmt = $pdo->prepare("UPDATE \"{$table}\" SET {$setList} WHERE \"Code\" = ?");
        $res = $stmt->execute($params);

        try {
            self::sync();
        } catch (Throwable $e) {
            error_log("Failed to sync client after update: " . $e->getMessage());
        }

        return $res;
    }

    /**
     * Delete a client record from PostgreSQL Clients table and sync locally.
     */
    public static function deleteClient(string $code): bool
    {
        if (!self::isAvailable()) throw new Exception("PostgreSQL database is unreachable.");
        $pdo = self::conn();
        $table = Config::get('EXT_PG_CLIENTS_TABLE', 'Clients');

        $stmt = $pdo->prepare("DELETE FROM \"{$table}\" WHERE \"Code\" = ?");
        $res = $stmt->execute([$code]);

        try {
            self::sync();
        } catch (Throwable $e) {
            error_log("Failed to sync after client deletion: " . $e->getMessage());
        }

        return $res;
    }

    /**
     * List user tables in the PostgreSQL database.
     */
    public static function listTables(): array
    {
        if (!self::isAvailable()) return [];
        $pdo = self::conn();
        $schema = Config::get('EXT_PG_SCHEMA', 'public');
        $stmt = $pdo->prepare("
            SELECT table_name 
            FROM information_schema.tables 
            WHERE table_schema = ? AND table_type = 'BASE TABLE'
            ORDER BY table_name ASC
        ");
        $stmt->execute([$schema]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Get column schema definitions for a given PostgreSQL table.
     */
    public static function getTableColumns(string $tableName): array
    {
        if (!self::isAvailable()) return [];
        $pdo = self::conn();
        $schema = Config::get('EXT_PG_SCHEMA', 'public');
        $stmt = $pdo->prepare("
            SELECT column_name, data_type, is_nullable, column_default
            FROM information_schema.columns
            WHERE table_schema = ? AND table_name = ?
            ORDER BY ordinal_position ASC
        ");
        $stmt->execute([$schema, $tableName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Normalize a date string (e.g. DD.MM.YYYY, DD.MM.YYYY HH:MM:SS, ISO) into standard ISO YYYY-MM-DD [HH:MM:SS].
     */
    public static function normalizeDateToIso(?string $dateStr): ?string
    {
        if ($dateStr === null) return null;
        $str = trim((string)$dateStr);
        if ($str === '' || $str === '-' || strtolower($str) === 'null') return null;

        // DD.MM.YYYY [HH:MM[:SS]]
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:\s+(\d{1,2}:\d{2}(?::\d{2})?))?$/', $str, $m)) {
            $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $year = $m[3];
            $time = !empty($m[4]) ? " " . $m[4] : "";
            return "{$year}-{$month}-{$day}{$time}";
        }

        // YYYY-MM-DD [HH:MM[:SS]]
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T\s](\d{1,2}:\d{2}(?::\d{2})?))?$/', $str, $m)) {
            $year = $m[1];
            $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $day = str_pad($m[3], 2, '0', STR_PAD_LEFT);
            $time = !empty($m[4]) ? " " . $m[4] : "";
            return "{$year}-{$month}-{$day}{$time}";
        }

        $ts = strtotime($str);
        if ($ts && $ts > 0) {
            $hasTime = date('H:i:s', $ts) !== '00:00:00';
            return date($hasTime ? 'Y-m-d H:i:s' : 'Y-m-d', $ts);
        }

        return $str;
    }

    /**
     * Get row data and total count for a given PostgreSQL table with search, sorting and pagination.
     */
    public static function getTableData(string $tableName, string $search = '', int $limit = 50, int $offset = 0, string $sortCol = '', string $sortDir = 'ASC'): array
    {
        if (!self::isAvailable()) return ['rows' => [], 'total' => 0, 'columns' => []];
        $pdo = self::conn();

        $columns = self::getTableColumns($tableName);
        if (empty($columns)) return ['rows' => [], 'total' => 0, 'columns' => []];

        $colNames = array_column($columns, 'column_name');
        $colTypes = [];
        foreach ($columns as $c) {
            $colTypes[$c['column_name']] = strtolower((string)$c['data_type']);
        }

        $whereSql = '';
        $params = [];
        if ($search !== '') {
            $whereParts = [];
            foreach ($colNames as $col) {
                $whereParts[] = "\"{$col}\"::text ILIKE ?";
                $params[] = '%' . $search . '%';
            }
            $whereSql = 'WHERE ' . implode(' OR ', $whereParts);
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM \"{$tableName}\" {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $orderSql = '';
        if ($sortCol !== '' && in_array($sortCol, $colNames, true)) {
            $dir = strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC';
            $type = $colTypes[$sortCol] ?? '';
            $sortLower = strtolower($sortCol);

            // Chronological sorting for date/time columns
            if (str_contains($type, 'date') || str_contains($type, 'time') || in_array($sortLower, ['date', 'start_date', 'created_at', 'updated_at', 'last_payment_date', 'last_handshake', 'last_login_at'])) {
                $orderSql = "ORDER BY \"{$sortCol}\" {$dir} NULLS LAST";
            } else {
                $orderSql = "ORDER BY \"{$sortCol}\" {$dir}";
            }
        } elseif (in_array('id', $colNames, true)) {
            $orderSql = 'ORDER BY "id" ASC';
        } elseif (in_array('Code', $colNames, true)) {
            $orderSql = 'ORDER BY "Code" ASC';
        }

        $dataParams = array_merge($params, [$limit, $offset]);
        $dataStmt = $pdo->prepare("SELECT * FROM \"{$tableName}\" {$whereSql} {$orderSql} LIMIT ? OFFSET ?");
        $dataStmt->execute($dataParams);
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'rows' => $rows,
            'total' => $total,
            'columns' => $columns
        ];
    }

    /**
     * Dynamic row update for any PostgreSQL table.
     */
    public static function updateTableRow(string $tableName, array $pkWhere, array $fieldUpdates): bool
    {
        if (!self::isAvailable()) throw new Exception("PostgreSQL database is unreachable.");
        if (empty($pkWhere) || empty($fieldUpdates)) return false;

        $pdo = self::conn();

        $set = [];
        $params = [];
        foreach ($fieldUpdates as $col => $val) {
            $set[] = "\"{$col}\" = ?";
            $colLower = strtolower($col);
            if ($val === '' || $val === '-' || strtolower((string)$val) === 'null') {
                $params[] = null;
            } elseif (in_array($colLower, ['date', 'start_date', 'created_at', 'updated_at', 'last_payment_date', 'expiry_date'])) {
                $params[] = self::normalizeDateToIso((string)$val);
            } else {
                $params[] = $val;
            }
        }

        $where = [];
        foreach ($pkWhere as $col => $val) {
            $where[] = "\"{$col}\" = ?";
            $params[] = $val;
        }

        $setSql = implode(', ', $set);
        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare("UPDATE \"{$tableName}\" SET {$setSql} WHERE {$whereSql}");
        $res = $stmt->execute($params);

        if (strtolower($tableName) === 'clients' || strtolower($tableName) === 'finances_2026') {
            try { self::sync(); } catch (Throwable $e) {}
        }

        return $res;
    }

    /**
     * Dynamic row insertion into any PostgreSQL table.
     */
    public static function insertTableRow(string $tableName, array $data): bool
    {
        if (!self::isAvailable()) throw new Exception("PostgreSQL database is unreachable.");
        if (empty($data)) return false;

        $pdo = self::conn();

        $cols = [];
        $phs = [];
        $params = [];
        foreach ($data as $col => $val) {
            $cols[] = "\"{$col}\"";
            $phs[] = '?';
            $colLower = strtolower($col);
            if ($val === '' || $val === '-' || strtolower((string)$val) === 'null') {
                $params[] = null;
            } elseif (in_array($colLower, ['date', 'start_date', 'created_at', 'updated_at', 'last_payment_date', 'expiry_date'])) {
                $params[] = self::normalizeDateToIso((string)$val);
            } else {
                $params[] = $val;
            }
        }

        $colList = implode(', ', $cols);
        $phList = implode(', ', $phs);

        $stmt = $pdo->prepare("INSERT INTO \"{$tableName}\" ({$colList}) VALUES ({$phList})");
        $res = $stmt->execute($params);

        if (strtolower($tableName) === 'clients' || strtolower($tableName) === 'finances_2026') {
            try { self::sync(); } catch (Throwable $e) {}
        }

        return $res;
    }

    /**
     * Dynamic row deletion from any PostgreSQL table.
     */
    public static function deleteTableRow(string $tableName, array $pkWhere): bool
    {
        if (!self::isAvailable()) throw new Exception("PostgreSQL database is unreachable.");
        if (empty($pkWhere)) return false;

        $pdo = self::conn();

        $where = [];
        $params = [];
        foreach ($pkWhere as $col => $val) {
            $where[] = "\"{$col}\" = ?";
            $params[] = $val;
        }

        $whereSql = implode(' AND ', $where);
        $stmt = $pdo->prepare("DELETE FROM \"{$tableName}\" WHERE {$whereSql}");
        $res = $stmt->execute($params);

        if (strtolower($tableName) === 'clients' || strtolower($tableName) === 'finances_2026') {
            try { self::sync(); } catch (Throwable $e) {}
        }

        return $res;
    }

    /**
     * Import a SQL dump file into the internal PostgreSQL database.
     */
    public static function importSqlDump(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("SQL dump file not found at: {$filePath}");
        }

        if (!self::isAvailable()) {
            throw new Exception("PostgreSQL database is unreachable. Please ensure the pgdb container is running.");
        }

        $res = self::restoreBackup($filePath);
        if ($res) {
            try {
                $pdo = self::conn();
                $pdo->exec("SELECT setval('\"Finances_2026_id_seq\"', COALESCE((SELECT MAX(id) FROM \"Finances_2026\"), 1));");
                $pdo->exec("SELECT setval('\"Servers_id_seq\"', COALESCE((SELECT MAX(id) FROM \"Servers\"), 1));");
            } catch (Throwable $e) {}
            try { self::sync(); } catch (Throwable $e) {}
        }

        return [
            'success' => $res,
            'message' => 'SQL dump imported successfully into PostgreSQL.'
        ];
    }
}

/**
 * pg_escape_identifier_compat — wraps the schema name safely.
 * Falls back to simple quoting if the native function is not available.
 */
function pg_escape_identifier_compat(string $id): string
{
    // Replace any double quotes inside the identifier to prevent injection
    return '"' . str_replace('"', '""', $id) . '"';
}
