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
        
        // Use password directly in command (docker environment is local and single-tenant)
        $cmd = "mysqldump -h {$dbHost} -P {$dbPort} -u {$dbUser} -p{$dbPass} {$dbName} > {$mysqlDumpPath} 2>/dev/null";
        exec($cmd, $output, $returnVar);
        if ($returnVar !== 0) {
            throw new Exception("MySQL dump failed with exit code {$returnVar}");
        }

        // 2. PostgreSQL Dump
        $pgHost = Config::get('EXT_PG_HOST');
        if (!empty($pgHost)) {
            $pgPort = Config::get('EXT_PG_PORT', '5432');
            $pgDb = Config::get('EXT_PG_DB');
            $pgUser = Config::get('EXT_PG_USER');
            $pgPass = Config::get('EXT_PG_PASSWORD');
            $postgresDumpPath = "{$tempDir}/postgres_db.sql";
            $pgCmd = "PGPASSWORD='{$pgPass}' pg_dump -h {$pgHost} -p {$pgPort} -U {$pgUser} -d {$pgDb} -F p > {$postgresDumpPath} 2>/dev/null";
            exec($pgCmd, $outputPg, $returnVarPg);
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
  }
