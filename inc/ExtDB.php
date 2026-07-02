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
}

/**
 * pg_escape_identifier_compat — wraps the schema name safely.
 * Falls back to simple quoting if the native function is not available.
 */
function pg_escape_identifier_compat(string $id): string {
    // Replace any double quotes inside the identifier to prevent injection
    return '"' . str_replace('"', '""', $id) . '"';
}
