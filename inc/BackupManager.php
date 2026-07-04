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
        
        $cmd = "MYSQL_PWD=" . escapeshellarg($dbPass) . " mysqldump -h {$dbHostEsc} -P {$dbPortEsc} -u {$dbUserEsc} {$dbNameEsc} > {$mysqlDumpPathEsc} 2>&1";
        exec($cmd, $output, $returnVar);
        if ($returnVar !== 0) {
            $err = implode("\n", $output);
            throw new Exception("MySQL dump failed with exit code {$returnVar}. Error: {$err}");
        }

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

            $pgCmd = "PGPASSWORD=" . escapeshellarg($pgPass) . " pg_dump -h {$pgHostEsc} -p {$pgPortEsc} -U {$pgUserEsc} -d {$pgDbEsc} -F p > {$postgresDumpPathEsc} 2>&1";
            exec($pgCmd, $outputPg, $returnVarPg);
            if ($returnVarPg !== 0) {
                $errPg = implode("\n", $outputPg);
                error_log("PostgreSQL dump failed: {$errPg}");
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
                
                $stmtClients = $this->pdo->prepare("SELECT name, client_ip, public_key, private_key, preshared_key, config, status, expires_at FROM vpn_clients WHERE server_id = ?");
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
       * Transmits a backup archive file to Telegram.
       */
      public function sendToTelegram(string $filePath): bool {
          if (!file_exists($filePath)) {
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

          if (!$enabled || empty($botToken) || empty($chatId)) {
              return false;
          }

          $url = "https://api.telegram.org/bot{$botToken}/sendDocument";
          $ch = curl_init($url);
          curl_setopt($ch, CURLOPT_POST, true);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
          curl_setopt($ch, CURLOPT_POSTFIELDS, [
              'chat_id' => $chatId,
              'document' => new CURLFile($filePath),
              'caption' => "🛡️ Nk-VPN Panel Backup: " . basename($filePath)
          ]);
          $response = curl_exec($ch);
          $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
          curl_close($ch);

          return $httpCode === 200;
      }

      /**
       * Prunes local backups according to the retention policy.
       */
      public function pruneLocalBackups(): int {
          $stmt = $this->pdo->prepare("SELECT value FROM settings WHERE namespace = 'backup' AND `key` = 'retention_days'");
          $stmt->execute();
          $res = $stmt->fetch();
          $retentionDays = $res ? (int)json_decode($res['value'], true) : 7;

          $files = glob("{$this->backupDir}/panel/*.zip");
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
                  $cmd = "mysql -h {$dbHost} -P {$dbPort} -u {$dbUser} -p{$dbPass} {$dbName} < {$tempDir}/panel_db.sql 2>/dev/null";
                  exec($cmd, $output, $returnVar);
                  $results['mysql'] = ($returnVar === 0);
              }

              // 2. PostgreSQL Restore
              if (isset($options['restore_postgres']) && $options['restore_postgres'] && file_exists("{$tempDir}/postgres_db.sql")) {
                  $pgHost = Config::get('EXT_PG_HOST');
                  if (!empty($pgHost)) {
                      $pgPort = Config::get('EXT_PG_PORT', '5432');
                      $pgDb = Config::get('EXT_PG_DB');
                      $pgUser = Config::get('EXT_PG_USER');
                      $pgPass = Config::get('EXT_PG_PASSWORD');
                      $pgCmd = "PGPASSWORD='{$pgPass}' psql -h {$pgHost} -p {$pgPort} -U {$pgUser} -d {$pgDb} < {$tempDir}/postgres_db.sql 2>/dev/null";
                      exec($pgCmd, $outputPg, $returnVarPg);
                      $results['postgres'] = ($returnVarPg === 0);
                  }
              }

              // 3. Env Restore
              if (isset($options['restore_env']) && $options['restore_env'] && file_exists("{$tempDir}/config.env")) {
                  copy("{$tempDir}/config.env", '/var/www/html/.env');
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
                      awg_params = ?, status = 'deploying'
                  WHERE id = ?
              ");
              $stmt->execute([
                  $s['name'], $s['host'], $s['port'], $s['username'], $s['password'],
                  $s['container_name'], $s['vpn_port'], $s['vpn_subnet'],
                  $s['server_public_key'], $s['server_private_key'] ?? null, $s['preshared_key'],
                  is_array($s['awg_params']) ? json_encode($s['awg_params']) : $s['awg_params'],
                  $targetServerId
              ]);
              $serverId = $targetServerId;
          } else {
              // Create new
              $stmt = $this->pdo->prepare("
                  INSERT INTO vpn_servers 
                  (user_id, name, host, port, username, password, container_name, vpn_port, vpn_subnet, 
                   server_public_key, server_private_key, preshared_key, awg_params, status)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'deploying')
              ");
              $stmt->execute([
                  $s['user_id'], $s['name'], $s['host'], $s['port'], $s['username'], $s['password'],
                  $s['container_name'], $s['vpn_port'], $s['vpn_subnet'],
                  $s['server_public_key'], $s['server_private_key'] ?? null, $s['preshared_key'],
                  is_array($s['awg_params']) ? json_encode($s['awg_params']) : $s['awg_params']
              ]);
              $serverId = (int)$this->pdo->lastInsertId();
          }

          // Import clients
          $restoredClientsCount = 0;
          foreach ($clients as $c) {
              $stmt = $this->pdo->prepare("SELECT id FROM vpn_clients WHERE server_id = ? AND client_ip = ?");
              $stmt->execute([$serverId, $c['client_ip']]);
              if ($stmt->fetch()) continue; // skip duplicates

              $ins = $this->pdo->prepare("
                  INSERT INTO vpn_clients 
                  (server_id, user_id, name, client_ip, public_key, private_key, preshared_key, config, status, expires_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'disabled', ?)
              ");
              $ins->execute([
                  $serverId, $s['user_id'], $c['name'], $c['client_ip'],
                  $c['public_key'], $c['private_key'], $c['preshared_key'],
                  $c['config'], $c['expires_at']
              ]);
              $restoredClientsCount++;
          }

          return [
              'success' => true,
              'server_id' => $serverId,
              'name' => $s['name'],
              'clients_imported' => $restoredClientsCount
          ];
      }
  }
