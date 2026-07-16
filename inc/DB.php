<?php
class DB {
  private static ?PDO $pdo = null;

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
    
    // Auto-run schema updates if columns are missing
    self::checkAndRunMigrations(self::$pdo);
    
    return self::$pdo;
  }

  private static function checkAndRunMigrations(PDO $pdo): void {
    try {
      // Check if secret_token column exists in vpn_servers
      $stmt = $pdo->query("SHOW COLUMNS FROM vpn_servers LIKE 'secret_token'");
      $hasSecretToken = $stmt->rowCount() > 0;
      
      if (!$hasSecretToken) {
        // Run migration script
        $sqlPath = __DIR__ . '/../migrations/017_add_server_secret_token_and_speeds.sql';
        if (file_exists($sqlPath)) {
          $sql = file_get_contents($sqlPath);
          $pdo->exec($sql);
        }
      }

      // Check if last_endpoint_ip column exists in vpn_clients
      $stmt2 = $pdo->query("SHOW COLUMNS FROM vpn_clients LIKE 'last_endpoint_ip'");
      $hasGeoIP = $stmt2->rowCount() > 0;
      
      if (!$hasGeoIP) {
        // Run GeoIP migration script
        $sqlPath = __DIR__ . '/../migrations/018_add_client_geoip.sql';
        if (file_exists($sqlPath)) {
          $sql = file_get_contents($sqlPath);
          $pdo->exec($sql);
        }
      }

      // Check if latitude column exists in vpn_clients
      $stmt3 = $pdo->query("SHOW COLUMNS FROM vpn_clients LIKE 'latitude'");
      $hasCoords = $stmt3->rowCount() > 0;
      
      if (!$hasCoords) {
        // Run Coordinates migration script
        $sqlPath = __DIR__ . '/../migrations/019_add_client_coordinates.sql';
        if (file_exists($sqlPath)) {
          $sql = file_get_contents($sqlPath);
          $pdo->exec($sql);
        }
      }

      // Check if ext_client_code column exists in vpn_clients
      $stmt4 = $pdo->query("SHOW COLUMNS FROM vpn_clients LIKE 'ext_client_code'");
      $hasExtClientCode = $stmt4->rowCount() > 0;

      if (!$hasExtClientCode) {
        // Run external client link migration
        $sqlPath = __DIR__ . '/../migrations/020_add_client_entity_link.sql';
        if (file_exists($sqlPath)) {
          $sql = file_get_contents($sqlPath);
          $pdo->exec($sql);
        }
      }

      // Check if ext_clients table exists
      try {
        $pdo->query("SELECT 1 FROM ext_clients LIMIT 1");
        $hasExtClientsTable = true;
      } catch (Throwable $e) {
        $hasExtClientsTable = false;
      }

      if (!$hasExtClientsTable) {
        // Run external clients table migration
        $sqlPath = __DIR__ . '/../migrations/021_create_ext_clients_table.sql';
        if (file_exists($sqlPath)) {
          $sql = file_get_contents($sqlPath);
          $pdo->exec($sql);
        }
      }

      // Check if name column exists in ext_clients
      try {
        $stmtExtCols = $pdo->query("SHOW COLUMNS FROM ext_clients LIKE 'name'");
        $hasNameCol = $stmtExtCols->rowCount() > 0;
      } catch (Throwable $e) {
        $hasNameCol = false;
      }

      if (!$hasNameCol) {
        // Run migration to add name, start_date, and sub fields
        $sqlPath = __DIR__ . '/../migrations/022_add_fields_to_ext_clients.sql';
        if (file_exists($sqlPath)) {
          $sql = file_get_contents($sqlPath);
          $pdo->exec($sql);
        }
      }

      // Check if func column exists in ext_clients
      try {
        $stmtExtCols2 = $pdo->query("SHOW COLUMNS FROM ext_clients LIKE 'func'");
        $hasFuncCol = $stmtExtCols2->rowCount() > 0;
      } catch (Throwable $e) {
        $hasFuncCol = false;
      }

      if (!$hasFuncCol) {
        // Run migration to add func and router fields
        $sqlPath = __DIR__ . '/../migrations/023_add_func_to_ext_clients.sql';
        if (file_exists($sqlPath)) {
          $sql = file_get_contents($sqlPath);
          $pdo->exec($sql);
        }
      }

      // Check if system_settings table exists
      try {
        $stmtSettings = $pdo->query("SHOW TABLES LIKE 'system_settings'");
        $hasSettingsTable = $stmtSettings->rowCount() > 0;
      } catch (Throwable $e) {
        $hasSettingsTable = false;
      }

      if (!$hasSettingsTable) {
        // Run migration to create system_settings table
        $sqlPath = __DIR__ . '/../migrations/024_create_settings_table.sql';
        if (file_exists($sqlPath)) {
          $sql = file_get_contents($sqlPath);
          $pdo->exec($sql);
        }
      }

      // Check if bytes_sent column exists in ext_clients
      try {
        $stmtExtCols3 = $pdo->query("SHOW COLUMNS FROM ext_clients LIKE 'bytes_sent'");
        $hasTrafficCols = $stmtExtCols3->rowCount() > 0;
      } catch (Throwable $e) {
        $hasTrafficCols = false;
      }

      if (!$hasTrafficCols) {
        // Run migration to add bytes_sent and bytes_received fields
        $sqlPath = __DIR__ . '/../migrations/025_add_traffic_to_ext_clients.sql';
        if (file_exists($sqlPath)) {
          $sql = file_get_contents($sqlPath);
          $pdo->exec($sql);
        }
      }

      // Check if backup_scope column exists in server_backups
      try {
        $stmtBackupCols = $pdo->query("SHOW COLUMNS FROM server_backups LIKE 'backup_scope'");
        $hasBackupScope = $stmtBackupCols->rowCount() > 0;
      } catch (Throwable $e) {
        $hasBackupScope = false;
      }

      if (!$hasBackupScope) {
        // Run migration to support comprehensive backups
        $sqlPath = __DIR__ . '/../migrations/026_backup_restore_system_support.sql';
        if (file_exists($sqlPath)) {
          $sql = file_get_contents($sqlPath);
          $pdo->exec($sql);
        }
      }

      // Check if routers table exists
      try {
        $pdo->query("SELECT 1 FROM routers LIMIT 1");
        $hasRoutersTable = true;
      } catch (Throwable $e) {
        $hasRoutersTable = false;
      }

      if (!$hasRoutersTable) {
        $sqlPath = __DIR__ . '/../migrations/028_create_routers_table.sql';
        if (file_exists($sqlPath)) {
          $sql = file_get_contents($sqlPath);
          $pdo->exec($sql);
        }
      }

      // Check if domain column exists in ext_clients
      try {
        $stmtExtCols4 = $pdo->query("SHOW COLUMNS FROM ext_clients LIKE 'domain'");
        $hasDomainCol = $stmtExtCols4->rowCount() > 0;
      } catch (Throwable $e) {
        $hasDomainCol = false;
      }

      if (!$hasDomainCol) {
        $sqlPath = __DIR__ . '/../migrations/029_add_domain_pass_to_ext_clients.sql';
        if (file_exists($sqlPath)) {
          $sql = file_get_contents($sqlPath);
          $pdo->exec($sql);
        }
      }

      // Check if routing_groups table exists
      try {
        $pdo->query("SELECT 1 FROM routing_groups LIMIT 1");
        $hasRoutingGroupsTable = true;
      } catch (Throwable $e) {
        $hasRoutingGroupsTable = false;
      }

      if (!$hasRoutingGroupsTable) {
        $sqlPath = __DIR__ . '/../migrations/030_create_routing_groups.sql';
        if (file_exists($sqlPath)) {
          $sql = file_get_contents($sqlPath);
          $pdo->exec($sql);
        }
      }

      // Check if tgid column exists in ext_clients
      try {
        $stmtExtCols5 = $pdo->query("SHOW COLUMNS FROM ext_clients LIKE 'tgid'");
        $hasTgidCol = $stmtExtCols5->rowCount() > 0;
      } catch (Throwable $e) {
        $hasTgidCol = false;
      }

      if (!$hasTgidCol) {
        $sqlPath = __DIR__ . '/../migrations/031_add_tgid_to_ext_clients.sql';
        if (file_exists($sqlPath)) {
          $sql = file_get_contents($sqlPath);
          $pdo->exec($sql);
        }
      }

      // Always run clean duplicate settings migration on startup
      $sqlPath = __DIR__ . '/../migrations/032_clean_duplicate_settings.sql';
      if (file_exists($sqlPath)) {
        $sql = file_get_contents($sqlPath);
        $pdo->exec($sql);
      }

      // Check if last_notified_status column exists in ext_clients
      try {
        $stmtExtCols6 = $pdo->query("SHOW COLUMNS FROM ext_clients LIKE 'last_notified_status'");
        $hasLastNotifiedStatus = $stmtExtCols6->rowCount() > 0;
      } catch (Throwable $e) {
        $hasLastNotifiedStatus = false;
      }

      if (!$hasLastNotifiedStatus) {
        $sqlPath = __DIR__ . '/../migrations/033_add_last_notified_status_to_ext_clients.sql';
        if (file_exists($sqlPath)) {
          $sql = file_get_contents($sqlPath);
          $pdo->exec($sql);
        }
      }
    } catch (Throwable $e) {
      error_log("Database self-healing migration failed: " . $e->getMessage());
    }
  }
}