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
    } catch (Throwable $e) {
      error_log("Database self-healing migration failed: " . $e->getMessage());
    }
  }
}