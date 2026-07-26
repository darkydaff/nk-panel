<?php
class DB {
  private static ?PDO $pdo = null;
  private static bool $migrationsChecked = false;

  public static function conn(): PDO {
    if (self::$pdo) return self::$pdo;
    $host = Config::get('DB_HOST', '127.0.0.1');
    $port = Config::get('DB_PORT', '3306');
    $db   = Config::get('DB_DATABASE', 'amnezia_panel');
    $user = Config::get('DB_USERNAME', 'amnezia');
    $pass = Config::get('DB_PASSWORD', '');
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);
    $options = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ];
    self::$pdo = new PDO($dsn, $user, $pass, $options);
    
    // Explicitly set UTF-8 encoding for connection
    self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Standardize MySQL connection timezone to UTC
    self::$pdo->exec("SET time_zone = '+00:00'");
    
    // Auto-run schema updates if not checked yet during this request
    if (!self::$migrationsChecked) {
      self::checkAndRunMigrations(self::$pdo);
      self::$migrationsChecked = true;
    }
    
    return self::$pdo;
  }

  private static function checkAndRunMigrations(PDO $pdo): void {
    try {
      // 1. Ensure migration tracking table exists
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS `schema_migrations` (
          `migration` VARCHAR(255) NOT NULL PRIMARY KEY,
          `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
      ");

      // 2. Fetch set of already executed migrations
      $stmt = $pdo->query("SELECT `migration` FROM `schema_migrations`");
      $executedMap = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);

      // 3. Scan migrations directory
      $migrationsDir = __DIR__ . '/../migrations';
      if (!is_dir($migrationsDir)) {
        return;
      }

      $files = glob($migrationsDir . '/*.sql');
      if (empty($files)) {
        return;
      }

      sort($files, SORT_STRING);

      // 4. Backwards compatibility: If tracking table is empty, detect existing schema to mark legacy migrations as executed
      if (empty($executedMap)) {
        $legacyExecuted = self::detectLegacyExecutedMigrations($pdo, $files);
        foreach ($legacyExecuted as $filename) {
          $insStmt = $pdo->prepare("INSERT IGNORE INTO `schema_migrations` (`migration`) VALUES (?)");
          $insStmt->execute([$filename]);
          $executedMap[$filename] = true;
        }
      }

      // 5. Execute pending migrations
      $insStmt = $pdo->prepare("INSERT INTO `schema_migrations` (`migration`) VALUES (?) ON DUPLICATE KEY UPDATE `executed_at` = VALUES(`executed_at`)");
      foreach ($files as $filePath) {
        $filename = basename($filePath);

        // Self-healing check: if 041 was falsely recorded as executed when column is missing
        if ($filename === '041_add_last_payment_to_ext_clients.sql' && isset($executedMap[$filename])) {
          try {
            $chk = $pdo->query("SHOW COLUMNS FROM ext_clients LIKE 'last_payment_date'");
            if ($chk->rowCount() === 0) {
              unset($executedMap[$filename]);
            }
          } catch (Throwable $e) {}
        }

        if (isset($executedMap[$filename])) {
          continue;
        }

        $sql = file_get_contents($filePath);
        if ($sql !== false && trim($sql) !== '') {
          try {
            $pdo->exec($sql);
          } catch (Throwable $e) {
            // Ignore duplicate column/table errors if a migration was partially applied before
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate column name') || 
                str_contains($msg, 'already exists') || 
                str_contains($msg, 'Duplicate key name')) {
              error_log("Notice: Migration {$filename} skipped duplicate DDL: " . $msg);
            } else {
              throw $e;
            }
          }
        }

        $insStmt->execute([$filename]);
        $executedMap[$filename] = true;
      }
    } catch (Throwable $e) {
      error_log("Database self-healing migration failed: " . $e->getMessage());
    }
  }

  /**
   * Detect legacy migrations executed before schema_migrations tracking table was introduced
   */
  private static function detectLegacyExecutedMigrations(PDO $pdo, array $files): array {
    $executed = [];
    
    $hasVpnServers = false;
    $hasExtClients = false;
    $hasLastRouterId = false;

    try {
      $stmt = $pdo->query("SHOW TABLES LIKE 'vpn_servers'");
      $hasVpnServers = $stmt->rowCount() > 0;
    } catch (Throwable $e) {}

    try {
      $stmt = $pdo->query("SHOW TABLES LIKE 'ext_clients'");
      $hasExtClients = $stmt->rowCount() > 0;
    } catch (Throwable $e) {}

    if ($hasExtClients) {
      try {
        $stmt = $pdo->query("SHOW COLUMNS FROM ext_clients LIKE 'last_router_id'");
        $hasLastRouterId = $stmt->rowCount() > 0;
      } catch (Throwable $e) {}
    }

    foreach ($files as $filePath) {
      $filename = basename($filePath);
      
      if ($hasLastRouterId) {
        if ($filename <= '040_add_last_router_id_to_ext_clients.sql') {
          $executed[] = $filename;
        }
      } elseif ($hasExtClients) {
        if ($filename <= '021_create_ext_clients_table.sql') {
          $executed[] = $filename;
        }
      } elseif ($hasVpnServers) {
        if ($filename <= '001_init.sql') {
          $executed[] = $filename;
        }
      }
    }

    return $executed;
  }
}