<?php

class BackupManager {
    private PDO $pdo;
    private string $backupDir = '/var/www/html/backups';

    public function __construct() {
        $this->pdo = DB::conn();
    }

    /**
     * Creates a full panel backup containing panel MySQL, external PostgreSQL,
     * environment configuration, and individual server backups.
     */
    public function createPanelBackup(int $userId, string $type = 'manual'): string {
        if ($userId <= 0) {
            $stmtUser = $this->pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
            $adminId = $stmtUser->fetchColumn();
            if ($adminId) {
                $userId = (int)$adminId;
            } else {
                $stmtUser = $this->pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
                $firstId = $stmtUser->fetchColumn();
                $userId = $firstId ? (int)$firstId : 1;
            }
        }

        $timestamp = date('Y-m-d_His');
        $tempDir = "/tmp/panel_backup_{$timestamp}";
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        if (!is_dir("{$tempDir}/servers")) {
            mkdir("{$tempDir}/servers", 0755, true);
        }

        // 1. MySQL Dump
        $dbHost = Config::get('DB_HOST', 'db');
        $dbPort = Config::get('DB_PORT', '3306');
        $dbName = Config::get('DB_DATABASE', 'amnezia_panel');
        $dbUser = Config::get('DB_USERNAME', 'amnezia');
        $dbPass = Config::get('DB_PASSWORD', 'amnezia');
        $mysqlDumpPath = "{$tempDir}/panel_db.sql";
        
        $dbHostEsc = escapeshellarg($dbHost);
        $dbPortEsc = escapeshellarg($dbPort);
        $dbUserEsc = escapeshellarg($dbUser);
        $dbNameEsc = escapeshellarg($dbName);
        $mysqlDumpPathEsc = escapeshellarg($mysqlDumpPath);
        
        $dbErrorPath = "{$tempDir}/mysql_dump.err";
        $dbErrorPathEsc = escapeshellarg($dbErrorPath);
        
        $cmdIgnore = "MYSQL_PWD=" . escapeshellarg($dbPass) . " mysqldump --no-tablespaces -h {$dbHostEsc} -P {$dbPortEsc} -u {$dbUserEsc} --ignore-table={$dbName}.client_metrics --ignore-table={$dbName}.server_metrics {$dbNameEsc} > {$mysqlDumpPathEsc} 2> {$dbErrorPathEsc}";
        exec($cmdIgnore, $output, $returnVar);
        if ($returnVar !== 0) {
            $err = file_exists($dbErrorPath) ? trim(file_get_contents($dbErrorPath)) : 'Unknown error';
            throw new Exception("MySQL dump failed with exit code {$returnVar}. Error: {$err}");
        }

        // Dump ONLY the schema of large metrics tables (no data)
        // to prevent massive backup sizes while ensuring table structures exist on restore
        $cmdSchema = "MYSQL_PWD=" . escapeshellarg($dbPass) . " mysqldump --no-tablespaces --no-data -h {$dbHostEsc} -P {$dbPortEsc} -u {$dbUserEsc} {$dbNameEsc} client_metrics server_metrics >> {$mysqlDumpPathEsc} 2>/dev/null";
        exec($cmdSchema, $outputSchema, $returnVarSchema);

        // 2. PostgreSQL Dump
        $pgHost = Config::get('EXT_PG_HOST');
        if (!empty($pgHost)) {
            $pgPort = Config::get('EXT_PG_PORT', '5432');
            $pgDb = Config::get('EXT_PG_DB');
            $pgUser = Config::get('EXT_PG_USER');
            $pgPass = Config::get('EXT_PG_PASSWORD');
            $postgresDumpPath = "{$tempDir}/postgres_db.sql";

            $pgHostEsc = escapeshellarg($pgHost);
            $pgPortEsc = escapeshellarg($pgPort);
            $pgUserEsc = escapeshellarg($pgUser);
            $pgDbEsc = escapeshellarg($pgDb);
            $postgresDumpPathEsc = escapeshellarg($postgresDumpPath);

            $pgErrorPath = "{$tempDir}/pg_dump.err";
            $pgErrorPathEsc = escapeshellarg($pgErrorPath);

            $pgCmd = "PGPASSWORD=" . escapeshellarg($pgPass) . " pg_dump -h {$pgHostEsc} -p {$pgPortEsc} -U {$pgUserEsc} -d {$pgDbEsc} -F p --clean --if-exists > {$postgresDumpPathEsc} 2> {$pgErrorPathEsc}";
            @exec($pgCmd, $outputPg, $returnVarPg);

            if ($returnVarPg !== 0 || !file_exists($postgresDumpPath) || filesize($postgresDumpPath) === 0) {
                try {
                    require_once __DIR__ . '/ExtDB.php';
                    if (ExtDB::isAvailable()) {
                        ExtDB::dumpPostgresViaPdo($postgresDumpPath);
                    }
                } catch (Throwable $extEx) {
                    error_log("PostgreSQL dump fallback failed: " . $extEx->getMessage());
                }
            }
        }

        // 3. Env Copy
        if (file_exists('/var/www/html/.env')) {
            copy('/var/www/html/.env', "{$tempDir}/config.env");
        }

        // 4. Individual Server JSON Exports
        $stmt = $this->pdo->query("SELECT id FROM vpn_servers");
        $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($servers as $s) {
            try {
                $server = new VpnServer((int)$s['id']);
                $sData = $server->getData();
                
                // Extract private key from remote container dynamically
                $containerName = $sData['container_name'];
                $privKey = trim($server->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/server_private.key 2>/dev/null", true));
                
                $stmtClients = $this->pdo->prepare("SELECT user_id, name, client_ip, public_key, private_key, preshared_key, config, status, expires_at, traffic_limit, ext_client_code, created_at FROM vpn_clients WHERE server_id = ?");
                $stmtClients->execute([$s['id']]);
                $clients = $stmtClients->fetchAll(PDO::FETCH_ASSOC);

                $serverBackup = [
                    'server' => array_merge($sData, ['server_private_key' => $privKey]),
                    'clients' => $clients,
                    'backup_date' => date('Y-m-d H:i:s'),
                    'version' => '1.0'
                ];
                file_put_contents("{$tempDir}/servers/server_{$s['id']}.json", json_encode($serverBackup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } catch (Exception $e) {
                // Log warning and continue
                error_log("Failed to back up server ID {$s['id']}: " . $e->getMessage());
            }
        }

        // 5. Zip it
        $zipPath = "{$this->backupDir}/panel/panel_backup_{$timestamp}.zip";
        if (!is_dir("{$this->backupDir}/panel")) {
            mkdir("{$this->backupDir}/panel", 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Cannot create ZIP file at {$zipPath}");
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($tempDir) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
        $zip->close();

        // Cleanup temp files
        exec("rm -rf {$tempDir}");

        // Insert backup record
        $size = file_exists($zipPath) ? filesize($zipPath) : 0;
        $ins = $this->pdo->prepare("
            INSERT INTO server_backups 
            (server_id, backup_name, backup_path, backup_size, backup_type, status, created_by, backup_scope) 
            VALUES (NULL, ?, ?, ?, ?, 'completed', ?, 'panel')
        ");
        $ins->execute([basename($zipPath), $zipPath, $size, $type, $userId]);

        return $zipPath;
      }

      /**
       * Creates a standalone backup of the external PostgreSQL database.
       */
      public function createExtDbBackup(int $userId, string $type = 'manual'): string {
          require_once __DIR__ . '/ExtDB.php';
          return ExtDB::createBackup($userId, $type);
      }

      /**
       * Transmits a backup archive file to Telegram.
       */
       public function sendToTelegram(string $filePath, string &$errorReason = ''): bool {
           if (!file_exists($filePath)) {
               $errorReason = 'Backup file not found on disk';
               return false;
           }

           // Read settings from DB namespace 'backup'
           $stmt = $this->pdo->prepare("SELECT value FROM settings WHERE namespace = 'backup' AND `key` = 'telegram_settings'");
           $stmt->execute();
           $res = $stmt->fetch();
           
           $botToken = Config::get('TELEGRAM_BOT_TOKEN');
           $chatId = Config::get('TELEGRAM_CHAT_ID');
           $enabled = false;

           if ($res) {
               $settings = json_decode($res['value'], true);
               $botToken = $settings['bot_token'] ?: $botToken;
               $chatId = $settings['chat_id'] ?: $chatId;
               $enabled = $settings['enabled'] ?? false;
           }

           if (!$enabled) {
               return false;
           }

           if (empty($botToken) || empty($chatId)) {
               $errorReason = 'Telegram integration is enabled but Bot Token or Chat ID is empty';
               $this->pdo->prepare("UPDATE server_backups SET error_message = ? WHERE backup_path = ?")->execute([$errorReason, $filePath]);
               return false;
           }

            $caption = str_contains(basename($filePath), 'ext_pg_backup')
                ? "🐘 External PostgreSQL DB Backup: " . basename($filePath)
                : "🛡️ Nk-VPN Panel Backup: " . basename($filePath);

            $url = "https://api.telegram.org/bot{$botToken}/sendDocument";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'chat_id' => $chatId,
                'document' => new CURLFile($filePath),
                'caption' => $caption
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($response === false) {
                $errorReason = curl_error($ch);
            }
            curl_close($ch);

            if ($httpCode === 200) {
                return true;
            } else {
                if (empty($errorReason)) {
                    $data = json_decode($response, true);
                    $errorReason = $data['description'] ?? 'HTTP Code ' . $httpCode;
                }
                $this->pdo->prepare("UPDATE server_backups SET error_message = ? WHERE backup_path = ?")->execute(['Telegram upload failed: ' . $errorReason, $filePath]);
                return false;
            }
        }

      /**
       * Prunes local backups according to the retention policy.
       */
       public function pruneLocalBackups(): int {
           $stmt = $this->pdo->prepare("SELECT value FROM settings WHERE namespace = 'backup' AND `key` = 'retention_days'");
           $stmt->execute();
           $res = $stmt->fetch();
           $retentionDays = $res ? (int)json_decode($res['value'], true) : 7;

           $files = array_merge(
               glob("{$this->backupDir}/panel/*.zip") ?: [],
               glob("{$this->backupDir}/ext_db/*.sql") ?: []
           );
           $deleted = 0;
           foreach ($files as $file) {
               if (filemtime($file) < (time() - ($retentionDays * 86400))) {
                   unlink($file);
                   $this->pdo->prepare("DELETE FROM server_backups WHERE backup_path = ?")->execute([$file]);
                   $deleted++;
               }
           }
           return $deleted;
       }

      public function restorePanelBackup(string $zipPath, array $options = []): array {
          $tempDir = "/tmp/panel_restore_" . time();
          mkdir($tempDir, 0755, true);

          $zip = new ZipArchive();
          if ($zip->open($zipPath) !== true) {
              throw new Exception("Unable to open backup ZIP");
          }
          $zip->extractTo($tempDir);
          $zip->close();

          $results = [
              'mysql' => false,
              'postgres' => false,
              'env' => false,
              'servers_restored' => []
          ];

          // Case A: Restore everything
          if (empty($options['selective_servers'])) {
              // 1. MySQL Restore
              if (isset($options['restore_mysql']) && $options['restore_mysql'] && file_exists("{$tempDir}/panel_db.sql")) {
                  $dbHost = Config::get('DB_HOST', 'db');
                  $dbPort = Config::get('DB_PORT', '3306');
                  $dbName = Config::get('DB_DATABASE', 'amnezia_panel');
                  $dbUser = Config::get('DB_USERNAME', 'amnezia');
                  $dbPass = Config::get('DB_PASSWORD', 'amnezia');
                  
                  $dbHostEsc = escapeshellarg($dbHost);
                  $dbPortEsc = escapeshellarg($dbPort);
                  $dbUserEsc = escapeshellarg($dbUser);
                  $dbNameEsc = escapeshellarg($dbName);
                  $sqlPathEsc = escapeshellarg("{$tempDir}/panel_db.sql");

                  $dbErrorPath = "{$tempDir}/mysql_restore.err";
                  $dbErrorPathEsc = escapeshellarg($dbErrorPath);

                  $cmd = "MYSQL_PWD=" . escapeshellarg($dbPass) . " mysql -h {$dbHostEsc} -P {$dbPortEsc} -u {$dbUserEsc} {$dbNameEsc} < {$sqlPathEsc} 2> {$dbErrorPathEsc}";
                  exec($cmd, $output, $returnVar);
                  if ($returnVar !== 0) {
                      $err = file_exists($dbErrorPath) ? trim(file_get_contents($dbErrorPath)) : 'Unknown error';
                      throw new Exception("MySQL restore failed with exit code {$returnVar}. Error: {$err}");
                  }
                  $results['mysql'] = true;
              }

              // 2. PostgreSQL Restore
              $pgHost = Config::get('EXT_PG_HOST');
              if (isset($options['restore_postgres']) && $options['restore_postgres'] && !empty($pgHost) && file_exists("{$tempDir}/postgres_db.sql")) {
                  $pgPort = Config::get('EXT_PG_PORT', '5432');
                  $pgDb = Config::get('EXT_PG_DB');
                  $pgUser = Config::get('EXT_PG_USER');
                  $pgPass = Config::get('EXT_PG_PASSWORD');
                  
                  $pgHostEsc = escapeshellarg($pgHost);
                  $pgPortEsc = escapeshellarg($pgPort);
                  $pgUserEsc = escapeshellarg($pgUser);
                  $pgDbEsc = escapeshellarg($pgDb);
                  $sqlPathEsc = escapeshellarg("{$tempDir}/postgres_db.sql");

                  $pgErrorPath = "{$tempDir}/pg_restore.err";
                  $pgErrorPathEsc = escapeshellarg($pgErrorPath);

                  $pgCmd = "PGPASSWORD=" . escapeshellarg($pgPass) . " psql -h {$pgHostEsc} -p {$pgPortEsc} -U {$pgUserEsc} -d {$pgDbEsc} < {$sqlPathEsc} 2> {$pgErrorPathEsc}";
                  exec($pgCmd, $outputPg, $returnVarPg);
                  if ($returnVarPg !== 0) {
                      $errPg = file_exists($pgErrorPath) ? trim(file_get_contents($pgErrorPath)) : 'Unknown error';
                      throw new Exception("PostgreSQL restore failed with exit code {$returnVarPg}. Error: {$errPg}");
                  }
                  $results['postgres'] = true;
              }

              // 3. Env Restore
              if (isset($options['restore_env']) && $options['restore_env'] && file_exists("{$tempDir}/config.env")) {
                  $envPath = '/var/www/html/.env';
                  if (file_exists($envPath)) {
                      @chmod($envPath, 0666);
                      @unlink($envPath);
                  }
                  if (!@copy("{$tempDir}/config.env", $envPath)) {
                      $content = file_get_contents("{$tempDir}/config.env");
                      if (@file_put_contents($envPath, $content) === false) {
                          throw new Exception("Unable to restore .env file due to write permissions on {$envPath}. Please ensure the file is writable by the web server.");
                      }
                  }
                  $results['env'] = true;
              }
          } else {
              // Case B: Selective restore from extracted servers directory
              foreach ($options['selective_servers'] as $serverId) {
                  $serverJsonPath = "{$tempDir}/servers/server_{$serverId}.json";
                  if (file_exists($serverJsonPath)) {
                      $serverData = json_decode(file_get_contents($serverJsonPath), true);
                      $res = $this->restoreServerBackup($serverData);
                      $results['servers_restored'][] = $res;
                  }
              }
          }

          exec("rm -rf {$tempDir}");
          return $results;
      }

      public function restoreServerBackup(array $backupData, ?int $targetServerId = null): array {
          $s = $backupData['server'];
          $clients = $backupData['clients'] ?? [];

          // Determine if we overwrite or create a new server
          if ($targetServerId === null) {
              // Check if host IP already exists
              $stmt = $this->pdo->prepare("SELECT id FROM vpn_servers WHERE host = ?");
              $stmt->execute([$s['host']]);
              $existing = $stmt->fetch();
              if ($existing) {
                  $targetServerId = (int)$existing['id'];
              }
          }

          if ($targetServerId !== null) {
              // Overwrite existing
              $stmt = $this->pdo->prepare("
                  UPDATE vpn_servers 
                  SET name = ?, host = ?, port = ?, username = ?, password = ?, 
                      container_name = ?, vpn_port = ?, vpn_subnet = ?, 
                      server_public_key = ?, server_private_key = ?, preshared_key = ?, 
                      awg_params = ?, status = 'deploying', secret_token = COALESCE(?, secret_token)
                  WHERE id = ?
              ");
              $stmt->execute([
                  $s['name'], $s['host'], $s['port'], $s['username'], $s['password'],
                  $s['container_name'], $s['vpn_port'], $s['vpn_subnet'],
                  $s['server_public_key'], $s['server_private_key'] ?? null, $s['preshared_key'],
                  is_array($s['awg_params']) ? json_encode($s['awg_params']) : $s['awg_params'],
                  $s['secret_token'] ?? null,
                  $targetServerId
              ]);
              $serverId = $targetServerId;
          } else {
              // Create new
              $stmt = $this->pdo->prepare("
                  INSERT INTO vpn_servers 
                  (user_id, name, host, port, username, password, container_name, vpn_port, vpn_subnet, 
                   server_public_key, server_private_key, preshared_key, awg_params, status, secret_token)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'deploying', ?)
              ");
              $stmt->execute([
                  $s['user_id'] ?? 1, $s['name'], $s['host'], $s['port'],
                  $s['username'] ?? null, $s['password'] ?? null,
                  $s['container_name'], $s['vpn_port'], $s['vpn_subnet'],
                  $s['server_public_key'], $s['server_private_key'] ?? null, $s['preshared_key'],
                  is_array($s['awg_params']) ? json_encode($s['awg_params']) : $s['awg_params'],
                  $s['secret_token'] ?? bin2hex(random_bytes(32))
              ]);
              $serverId = (int)$this->pdo->lastInsertId();
          }

          // Import clients
          $restoredClientsCount = 0;
          foreach ($clients as $c) {
              $stmt = $this->pdo->prepare("SELECT id FROM vpn_clients WHERE server_id = ? AND client_ip = ?");
              $stmt->execute([$serverId, $c['client_ip']]);
              $existing = $stmt->fetch();
              
              if ($existing) {
                  $clientId = (int)$existing['id'];
              } else {
                  $ins = $this->pdo->prepare("
                      INSERT INTO vpn_clients 
                      (server_id, user_id, name, client_ip, public_key, private_key, preshared_key, config, status, expires_at, traffic_limit, ext_client_code, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                  ");
                  $ins->execute([
                      $serverId,
                      $c['user_id'] ?? $s['user_id'] ?? 1,
                      $c['name'],
                      $c['client_ip'],
                      $c['public_key'],
                      $c['private_key'],
                      $c['preshared_key'],
                      $c['config'],
                      $c['status'] ?? 'active',
                      $c['expires_at'] ?? null,
                      $c['traffic_limit'] ?? null,
                      $c['ext_client_code'] ?? null,
                      $c['created_at'] ?? date('Y-m-d H:i:s')
                  ]);
                  $clientId = (int)$this->pdo->lastInsertId();
                  $restoredClientsCount++;
              }

              // Update router link if ext_client_code exists
              if (!empty($c['ext_client_code'])) {
                  $updRouter = $this->pdo->prepare("
                      UPDATE routers 
                      SET vpn_client_id = ?, server_id = ? 
                      WHERE ext_client_code = ?
                  ");
                  $updRouter->execute([$clientId, $serverId, $c['ext_client_code']]);
              }
          }

          return [
              'success' => true,
              'server_id' => $serverId,
              'name' => $s['name'],
              'clients_imported' => $restoredClientsCount
          ];
      }
  }
