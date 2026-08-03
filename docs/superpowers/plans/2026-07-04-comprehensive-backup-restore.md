# Comprehensive Panel and Server Backup & Restore System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a robust backup/restore interface and CLI automation that handles full panel-wide states, individual servers, Telegram exports, and container rebuilds.

**Architecture:** Integrate CLI db clients (mariadb, postgresql) inside the Docker environment. Implement BackupManager for system orchestration, modify VpnServer to recover and redeploy keys, and build a backups management settings view.

**Tech Stack:** PHP 8.2, Apache, MySQL 8.4, PostgreSQL, Twig, Shell script, Docker, Telegram Bot API.

---

### Task 1: System Dependencies & Environment Setup

**Files:**
- Modify: `Dockerfile`
- Create: `migrations/026_backup_restore_system_support.sql`

- [ ] **Step 1: Install mariadb-client and postgresql-client in Dockerfile**
  Add client packages to `Dockerfile` dependencies.
  Modify `Dockerfile`:
  ```dockerfile
  RUN apt-get update && apt-get install -y \
      git \
      curl \
      libpng-dev \
      libonig-dev \
      libxml2-dev \
      libpq-dev \
      zip \
      unzip \
      sshpass \
      openssh-client \
      cron \
      mariadb-client \
      postgresql-client \
      && docker-php-ext-install pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd \
  ```

- [ ] **Step 2: Create SQL Migration for Nullable Server Backups and Private Key storage**
  Create `migrations/026_backup_restore_system_support.sql` with:
  ```sql
  -- 1. Modify server_backups table to allow nullable server_id for panel backups
  ALTER TABLE server_backups 
    MODIFY COLUMN server_id INT UNSIGNED NULL,
    ADD COLUMN backup_scope ENUM('panel', 'server') DEFAULT 'server' AFTER server_id;

  -- 2. Modify vpn_servers table to store temporary private keys during restoration
  ALTER TABLE vpn_servers 
    ADD COLUMN server_private_key TEXT NULL AFTER server_public_key;
  ```

- [ ] **Step 3: Run migrations via shell command**
  Run: `docker exec -i nk-panel-web php bin/refresh_configs.php` or execute the migration SQL manually on the MySQL database container.
  Expected output: Migrations successfully applied.

- [ ] **Step 4: Commit changes**
  ```bash
  git add Dockerfile migrations/026_backup_restore_system_support.sql
  git commit -m "feat(backup): install db clients and apply database schema updates"
  ```

---

### Task 2: Core Service - Backup Creation (`inc/BackupManager.php`)

**Files:**
- Create: `inc/BackupManager.php`

- [ ] **Step 1: Write BackupManager skeleton and createBackup methods**
  Write initial `inc/BackupManager.php` with MySQL/PostgreSQL dumping, `.env` compilation, server JSON export, and ZIP packaging.
  ```php
  <?php

  class BackupManager {
      private PDO $pdo;
      private string $backupDir = '/var/www/html/backups';

      public function __construct() {
          $this->pdo = DB::conn();
      }

      public function createPanelBackup(int $userId, string $type = 'manual'): string {
          $timestamp = date('Y-m-d_His');
          $tempDir = "/tmp/panel_backup_{$timestamp}";
          mkdir($tempDir, 0755, true);
          mkdir("{$tempDir}/servers", 0755, true);

          // 1. MySQL Dump
          $dbHost = Config::get('DB_HOST', 'db');
          $dbPort = Config::get('DB_PORT', '3306');
          $dbName = Config::get('DB_DATABASE', 'amnezia_panel');
          $dbUser = Config::get('DB_USERNAME', 'amnezia');
          $dbPass = Config::get('DB_PASSWORD', 'amnezia');
          $mysqlDumpPath = "{$tempDir}/panel_db.sql";
          $cmd = "mysqldump -h {$dbHost} -P {$dbPort} -u {$dbUser} -p{$dbPass} {$dbName} > {$mysqlDumpPath} 2>/dev/null";
          exec($cmd, $output, $returnVar);
          if ($returnVar !== 0) {
              throw new Exception("MySQL dump failed");
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
                  file_put_contents("{$tempDir}/servers/server_{$s['id']}.json", json_encode($serverBackup, JSON_PRETTY_PRINT));
              } catch (Exception $e) {
                  // Log and continue
              }
          }

          // 5. Zip it
          $zipPath = "{$this->backupDir}/panel/panel_backup_{$timestamp}.zip";
          if (!is_dir("{$this->backupDir}/panel")) {
              mkdir("{$this->backupDir}/panel", 0755, true);
          }

          $zip = new ZipArchive();
          if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
              throw new Exception("Cannot create ZIP file");
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
          $size = filesize($zipPath);
          $ins = $this->pdo->prepare("INSERT INTO server_backups (server_id, backup_name, backup_path, backup_size, backup_type, status, created_by, backup_scope) VALUES (NULL, ?, ?, ?, ?, 'completed', ?, 'panel')");
          $ins->execute([basename($zipPath), $zipPath, $size, $type, $userId]);

          return $zipPath;
      }

      public function sendToTelegram(string $filePath): bool {
          // Read settings from DB namespace 'backup'
          $stmt = $this->pdo->prepare("SELECT value FROM settings WHERE namespace = 'backup' AND `key` = ?");
          $stmt->execute(['telegram_settings']);
          $res = $stmt->fetch();
          if (!$res) return false;
          $settings = json_decode($res['value'], true);

          $botToken = $settings['bot_token'] ?? Config::get('TELEGRAM_BOT_TOKEN');
          $chatId = $settings['chat_id'] ?? Config::get('TELEGRAM_CHAT_ID');
          $enabled = $settings['enabled'] ?? false;

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
  ```

- [ ] **Step 2: Commit BackupManager Creation**
  ```bash
  git add inc/BackupManager.php
  git commit -m "feat(backup): implement panel backup creation and Telegram dispatch"
  ```

---

### Task 3: Core Service - Backup Restoration (`inc/BackupManager.php`)

**Files:**
- Modify: `inc/BackupManager.php`

- [ ] **Step 1: Implement Panel and Server Restore methods in BackupManager**
  Add extraction, database schema overwriting, selective server restores, and server row creation logic to `inc/BackupManager.php`.
  ```php
  // Add to inc/BackupManager.php:

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
  ```

- [ ] **Step 2: Commit Restoration Logic**
  ```bash
  git add inc/BackupManager.php
  git commit -m "feat(backup): implement zip panel restore and server import logic"
  ```

---

### Task 4: VpnServer Re-deployment and Batch Client Sync

**Files:**
- Modify: `inc/VpnServer.php`

- [ ] **Step 1: Modify initializeServerConfig to write restored private keys**
  Update `initializeServerConfig` inside `inc/VpnServer.php` to write `$this->data['server_private_key']` when present.
  Modify `inc/VpnServer.php:519-539`:
  ```php
      private function initializeServerConfig(int $vpnPort): array
      {
          $containerName = $this->data['container_name'];
          $pdo = DB::conn();

          // Create directory
          $this->executeCommand("docker exec -i {$containerName} mkdir -p /opt/amnezia/awg", true);

          if (!empty($this->data['server_private_key'])) {
              // Restore existing keys
              $privKey = trim($this->data['server_private_key']);
              $psk = trim($this->data['preshared_key']);

              $this->executeCommand("echo \"{$privKey}\" | docker exec -i {$containerName} sh -c 'cat > /opt/amnezia/awg/server_private.key'", true);
              $this->executeCommand("echo \"{$psk}\" | docker exec -i {$containerName} sh -c 'cat > /opt/amnezia/awg/wireguard_psk.key'", true);
              $this->executeCommand("docker exec -i {$containerName} sh -c 'cat /opt/amnezia/awg/server_private.key | /usr/local/bin/awg pubkey > /opt/amnezia/awg/wireguard_server_public_key.key'", true);
              $this->executeCommand("docker exec -i {$containerName} chmod 600 /opt/amnezia/awg/server_private.key /opt/amnezia/awg/wireguard_psk.key /opt/amnezia/awg/wireguard_server_public_key.key", true);
              
              $pubKey = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/wireguard_server_public_key.key", true));
              
              // Securely clear private key from DB since it is successfully deployed
              $pdo->prepare("UPDATE vpn_servers SET server_private_key = NULL WHERE id = ?")->execute([$this->serverId]);
          } else {
              // Generate keys
              $this->executeCommand("docker exec -i {$containerName} sh -c 'cd /opt/amnezia/awg && umask 077 && /usr/local/bin/awg genkey | tee server_private.key | /usr/local/bin/awg pubkey > wireguard_server_public_key.key'", true, true);
              $this->executeCommand("docker exec -i {$containerName} sh -c 'cd /opt/amnezia/awg && /usr/local/bin/awg genpsk > wireguard_psk.key'", true, true);
              $this->executeCommand("docker exec -i {$containerName} chmod 600 /opt/amnezia/awg/server_private.key /opt/amnezia/awg/wireguard_psk.key /opt/amnezia/awg/wireguard_server_public_key.key", true, true);
              
              $privKey = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/server_private.key", true));
              $pubKey = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/wireguard_server_public_key.key", true));
              $psk = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/wireguard_psk.key", true));
          }

          if (empty($privKey) || empty($pubKey) || empty($psk)) {
              throw new Exception('Key generation failed inside container.');
          }
  ```

- [ ] **Step 2: Add syncAllClientsToContainer to VpnServer**
  Implement `syncAllClientsToContainer` in `inc/VpnServer.php` to perform quick configuration state uploads.
  Add to `inc/VpnServer.php`:
  ```php
      public function syncAllClientsToContainer(): bool {
          if (!$this->data) return false;
          $containerName = $this->data['container_name'];
          $pdo = DB::conn();

          // Retrieve server private key from remote container to re-derive/construct
          $privKey = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/server_private.key 2>/dev/null", true));
          if (empty($privKey)) {
              return false;
          }

          $stmt = $pdo->prepare("SELECT name, client_ip, public_key, preshared_key, status FROM vpn_clients WHERE server_id = ?");
          $stmt->execute([$this->serverId]);
          $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

          // Build Interface section
          $vpnPort = $this->data['vpn_port'] ?: 51820;
          $subnetBase = substr($this->data['vpn_subnet'], 0, strrpos($this->data['vpn_subnet'], '.'));
          $awgParams = is_string($this->data['awg_params']) ? json_decode($this->data['awg_params'], true) : $this->data['awg_params'];
          $awgParams = $awgParams ?: [];

          $wgConfig = "[Interface]\n";
          $wgConfig .= "PrivateKey = {$privKey}\n";
          $wgConfig .= "Address = {$subnetBase}.1/24\n";
          $wgConfig .= "ListenPort = {$vpnPort}\n";
          $wgConfig .= "MTU = 1280\n";
          foreach ($awgParams as $key => $value) {
              if (empty($value) || $key === 'mimicry_type') continue;
              $wgConfig .= "{$key} = {$value}\n";
          }
          $wgConfig .= "\n";

          // Build Peer sections & clientsTable structure
          $clientsTable = [];
          foreach ($clients as $c) {
              if ($c['status'] !== 'active') continue;
              
              $wgConfig .= "[Peer]\n";
              $wgConfig .= "PublicKey = {$c['public_key']}\n";
              if (!empty($c['preshared_key'])) {
                  $wgConfig .= "PresharedKey = {$c['preshared_key']}\n";
              }
              $wgConfig .= "AllowedIPs = {$c['client_ip']}/32\n\n";

              $clientsTable[] = [
                  'name' => $c['name'],
                  'client_ip' => $c['client_ip'],
                  'public_key' => $c['public_key'],
                  'preshared_key' => $c['preshared_key']
              ];
          }

          $base64Config = base64_encode($wgConfig);
          $base64Table = base64_encode(json_encode($clientsTable));

          $this->executeCommand("echo \"{$base64Config}\" | docker exec -i {$containerName} sh -c 'base64 -d > /opt/amnezia/awg/wg0.conf'", true);
          $this->executeCommand("docker exec -i {$containerName} chmod 600 /opt/amnezia/awg/wg0.conf", true);
          $this->executeCommand("echo \"{$base64Table}\" | docker exec -i {$containerName} sh -c 'base64 -d > /opt/amnezia/awg/clientsTable'", true);

          // Apply rules and syncconf
          $this->executeCommand("docker exec -i {$containerName} bash -c '/usr/local/bin/awg syncconf wg0 <(/usr/local/bin/awg-quick strip /opt/amnezia/awg/wg0.conf)'", true);
          return true;
      }
  ```

- [ ] **Step 3: Trigger batch sync post deployment**
  Add client restoration check at the end of the `deploy` function in `inc/VpnServer.php` (line 150):
  ```php
              $stmt->execute([
                  $vpnPort,
                  $keys['public_key'],
                  $keys['preshared_key'],
                  json_encode($keys['awg_params']),
                  'active',
                  $this->serverId
              ]);

              // Re-fetch data
              $this->data = $this->loadServerData();
              // Sync all clients to container (which constructs final wg0.conf and syncconfs it)
              $this->syncAllClientsToContainer();

              return ['success' => true];
  ```

- [ ] **Step 4: Commit VpnServer Modifications**
  ```bash
  git add inc/VpnServer.php
  git commit -m "feat(backup): support server keys preservation and batch container config sync"
  ```

---

### Task 5: Web UI Router Integration & Settings Updates

**Files:**
- Modify: `public/index.php`
- Modify: `templates/settings.twig`

- [ ] **Step 1: Add Backups settings tab HTML to settings.twig**
  Insert the settings tab buttons and panels.
  Modify `templates/settings.twig:38-46`:
  ```twig
      <div class="flex gap-2 mb-6 overflow-x-auto pb-2">
          <button onclick="showTab('profile')" id="tab-profile" class="tab-btn tab-btn-active px-4 py-2 text-sm font-medium rounded-lg transition-colors whitespace-nowrap">
              <i class="fas fa-user mr-2"></i>{{ t('settings.profile') }}
          </button>
          {% if user.role == 'admin' %}
          <button onclick="showTab('users')" id="tab-users" class="tab-btn px-4 py-2 text-sm font-medium rounded-lg transition-colors whitespace-nowrap">
              <i class="fas fa-users mr-2"></i>{{ t('settings.users') }}
          </button>
          <button onclick="showTab('backups')" id="tab-backups" class="tab-btn px-4 py-2 text-sm font-medium rounded-lg transition-colors whitespace-nowrap">
              <i class="fas fa-database mr-2"></i>Backups
          </button>
          {% endif %}
      </div>
  ```

  And add the content panel container inside `templates/settings.twig` before `{% endif %}` (around line 183):
  ```twig
      <!-- Backups Tab Content -->
      <div id="content-backups" class="hidden">
          <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
              <!-- Configuration Card -->
              <div class="lg:col-span-1 panel overflow-hidden border-slate-800/60 bg-slate-900/60 backdrop-blur">
                  <div class="px-5 py-4 border-b border-slate-700 bg-slate-800/30">
                      <h2 class="text-base font-semibold text-white"><i class="fas fa-cog mr-2 text-accent"></i>Backup Settings</h2>
                  </div>
                  <div class="p-5 space-y-4">
                      <form method="POST" action="/settings/backup-config">
                          <div class="space-y-4">
                              <label class="flex items-center text-sm font-medium text-slate-300">
                                  <input type="checkbox" name="enabled" value="1" {% if backup_settings.enabled %}checked{% endif %} class="rounded bg-slate-800 border-slate-700 mr-2 text-accent">
                                  Enable Telegram Offsite
                              </label>
                              <div>
                                  <label class="block text-xs font-medium text-slate-400 mb-1">Bot Token</label>
                                  <input type="password" name="bot_token" value="{{ backup_settings.bot_token }}" class="input-field w-full px-3 py-2 text-sm">
                              </div>
                              <div>
                                  <label class="block text-xs font-medium text-slate-400 mb-1">Chat ID</label>
                                  <input type="text" name="chat_id" value="{{ backup_settings.chat_id }}" class="input-field w-full px-3 py-2 text-sm">
                              </div>
                              <div>
                                  <label class="block text-xs font-medium text-slate-400 mb-1">Backup Schedule</label>
                                  <select name="schedule" class="input-field w-full px-3 py-2 text-sm">
                                      <option value="disabled" {% if backup_settings.schedule == 'disabled' %}selected{% endif %}>Disabled</option>
                                      <option value="daily" {% if backup_settings.schedule == 'daily' %}selected{% endif %}>Daily</option>
                                      <option value="weekly" {% if backup_settings.schedule == 'weekly' %}selected{% endif %}>Weekly</option>
                                  </select>
                              </div>
                              <div>
                                  <label class="block text-xs font-medium text-slate-400 mb-1">Local Retention (Days)</label>
                                  <input type="number" name="retention_days" value="{{ backup_settings.retention_days|default(7) }}" class="input-field w-full px-3 py-2 text-sm">
                              </div>
                              <button type="submit" class="btn-primary w-full py-2 text-sm">Save Config</button>
                          </div>
                      </form>
                  </div>
              </div>

              <!-- Create & Drag Restore Zone -->
              <div class="lg:col-span-2 panel overflow-hidden border-slate-800/60 bg-slate-900/60 backdrop-blur">
                  <div class="px-5 py-4 border-b border-slate-700 bg-slate-800/30">
                      <h2 class="text-base font-semibold text-white"><i class="fas fa-upload mr-2 text-accent"></i>Manual Backup & Restore</h2>
                  </div>
                  <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-6">
                      <!-- Manual trigger -->
                      <form method="POST" action="/settings/backup-create">
                          <div class="space-y-4">
                              <h3 class="text-sm font-semibold text-slate-200">Create Backup</h3>
                              <div>
                                  <label class="block text-xs font-medium text-slate-400 mb-1">Backup Target</label>
                                  <select name="target" class="input-field w-full px-3 py-2 text-sm">
                                      <option value="panel">Full Panel (ZIP)</option>
                                      {% for s in servers %}
                                      <option value="{{ s.id }}">Server: {{ s.name }} (JSON)</option>
                                      {% endfor %}
                                  </select>
                              </div>
                              <button type="submit" class="btn-primary w-full py-2 text-sm">Create Backup Now</button>
                          </div>
                      </form>

                      <!-- Drag Drop Restore -->
                      <div class="space-y-4">
                          <h3 class="text-sm font-semibold text-slate-200">Restore Backup</h3>
                          <div id="drop-zone" class="border-2 border-dashed border-slate-700 hover:border-accent rounded-lg p-6 flex flex-col items-center justify-center cursor-pointer transition-colors h-36">
                              <i class="fas fa-file-archive text-2xl text-slate-500 mb-2"></i>
                              <p class="text-xs text-slate-400 text-center">Drag & Drop zip/json backup file here</p>
                              <input type="file" id="restore-file-input" class="hidden" accept=".zip,.json">
                          </div>
                      </div>
                  </div>
              </div>
          </div>

          <!-- History Log -->
          <div class="panel overflow-hidden border-slate-800/60 bg-slate-900/60 backdrop-blur">
              <div class="px-5 py-4 border-b border-slate-700 bg-slate-800/30">
                  <h2 class="text-base font-semibold text-white"><i class="fas fa-history mr-2 text-accent"></i>Backup History</h2>
              </div>
              <div class="table-wrapper">
                  <table class="min-w-full">
                      <thead>
                          <tr class="bg-slate-800/50">
                              <th class="px-4 py-3 text-left text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Date & Time</th>
                              <th class="px-4 py-3 text-left text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Scope</th>
                              <th class="px-4 py-3 text-left text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Type</th>
                              <th class="px-4 py-3 text-left text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Size</th>
                              <th class="px-4 py-3 text-left text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Status</th>
                              <th class="px-4 py-3 text-right text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Actions</th>
                          </tr>
                      </thead>
                      <tbody class="divide-y divide-slate-700">
                          {% for b in backups %}
                          <tr class="hover:bg-slate-800/30 transition-colors">
                              <td class="px-4 py-3 text-sm text-white">{{ b.created_at }}</td>
                              <td class="px-4 py-3 text-sm">
                                  {% if b.backup_scope == 'panel' %}
                                  <span class="bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 text-xs px-2 py-0.5 rounded">Panel</span>
                                  {% else %}
                                  <span class="bg-slate-700 text-slate-300 text-xs px-2 py-0.5 rounded">Server</span>
                                  {% endif %}
                              </td>
                              <td class="px-4 py-3 text-sm text-slate-300">{{ b.backup_type }}</td>
                              <td class="px-4 py-3 text-sm text-slate-300">{{ (b.backup_size / 1024) | number_format(1) }} KB</td>
                              <td class="px-4 py-3 text-sm">
                                  {% if b.status == 'completed' %}
                                  <span class="text-emerald-400 text-xs"><i class="fas fa-check-circle mr-1"></i>Completed</span>
                                  {% else %}
                                  <span class="text-red-400 text-xs" title="{{ b.error_message }}"><i class="fas fa-exclamation-circle mr-1"></i>Failed</span>
                                  {% endif %}
                              </td>
                              <td class="px-4 py-3 text-right space-x-2">
                                  <a href="/settings/backup-download/{{ b.id }}" class="text-cyan-400 hover:text-cyan-300 text-xs px-2 py-1"><i class="fas fa-download"></i></a>
                                  <a href="/settings/backup-delete/{{ b.id }}" onclick="return confirm('Delete backup?')" class="text-red-400 hover:text-red-300 text-xs px-2 py-1"><i class="fas fa-trash"></i></a>
                              </td>
                          </tr>
                          {% endfor %}
                      </tbody>
                  </table>
              </div>
          </div>
      </div>
  ```

- [ ] **Step 2: Add routing controller integration in SettingsController.php**
  Inject settings retrieval data to `settings.twig` data inside `controllers/SettingsController.php` (around lines 17-21):
  ```php
          $stmtBackup = $this->pdo->prepare("SELECT `key`, value FROM settings WHERE namespace = 'backup'");
          $stmtBackup->execute();
          $backupRows = $stmtBackup->fetchAll(PDO::FETCH_ASSOC);
          $backupSettings = ['enabled' => false, 'bot_token' => '', 'chat_id' => '', 'schedule' => 'disabled', 'retention_days' => 7];
          foreach ($backupRows as $r) {
              if ($r['key'] === 'telegram_settings') {
                  $backupSettings = array_merge($backupSettings, json_decode($r['value'], true));
              }
              if ($r['key'] === 'retention_days') {
                  $backupSettings['retention_days'] = (int)json_decode($r['value'], true);
              }
          }

          $stmtLog = $this->pdo->query("SELECT id, backup_name, backup_size, backup_type, status, error_message, created_at, backup_scope FROM server_backups ORDER BY created_at DESC LIMIT 50");
          $backups = $stmtLog->fetchAll(PDO::FETCH_ASSOC);

          $serversList = VpnServer::listAll();

          $data = [
              'translation_stats' => $stats,
              'users' => $users,
              'openrouter_key' => $apiKey,
              'backup_settings' => $backupSettings,
              'backups' => $backups,
              'servers' => $serversList
          ];
  ```

- [ ] **Step 3: Add routes for Backup Config, Create, Download, and Delete in public/index.php**
  Add the HTTP post/get actions for backups management at the end of the routing mapping in `public/index.php`.
  Add before the bottom of `public/index.php`:
  ```php
  // Save Backup Config
  Router::post('/settings/backup-config', function () {
      requireAdmin();
      $pdo = DB::conn();
      $enabled = isset($_POST['enabled']) ? true : false;
      $botToken = trim($_POST['bot_token'] ?? '');
      $chatId = trim($_POST['chat_id'] ?? '');
      $schedule = $_POST['schedule'] ?? 'disabled';
      $retentionDays = (int)($_POST['retention_days'] ?? 7);

      $tgVal = json_encode(['enabled' => $enabled, 'bot_token' => $botToken, 'chat_id' => $chatId, 'schedule' => $schedule]);
      $retVal = json_encode($retentionDays);

      $stmt = $pdo->prepare("INSERT INTO settings (namespace, `key`, value) VALUES ('backup', 'telegram_settings', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
      $stmt->execute([$tgVal]);

      $stmt = $pdo->prepare("INSERT INTO settings (namespace, `key`, value) VALUES ('backup', 'retention_days', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
      $stmt->execute([$retVal]);

      $_SESSION['settings_success'] = 'Backup configuration saved successfully';
      redirect('/settings#backups');
  });

  // Create Backup
  Router::post('/settings/backup-create', function () {
      requireAdmin();
      $target = $_POST['target'] ?? 'panel';
      $user = Auth::user();

      try {
          $bm = new BackupManager();
          if ($target === 'panel') {
              $path = $bm->createPanelBackup($user['id']);
              $bm->sendToTelegram($path);
              $_SESSION['settings_success'] = 'Full panel backup successfully created';
          } else {
              $serverId = (int)$target;
              $server = new VpnServer($serverId);
              $path = $server->createBackup($user['id']);
              $_SESSION['settings_success'] = 'Server backup successfully created';
          }
      } catch (Exception $e) {
          $_SESSION['settings_error'] = 'Backup failed: ' . $e->getMessage();
      }
      redirect('/settings#backups');
  });

  // Download Backup
  Router::get('/settings/backup-download/{id}', function ($params) {
      requireAdmin();
      $id = (int)$params['id'];
      $pdo = DB::conn();

      $stmt = $pdo->prepare("SELECT backup_path, backup_name FROM server_backups WHERE id = ?");
      $stmt->execute([$id]);
      $backup = $stmt->fetch();

      if ($backup && file_exists($backup['backup_path'])) {
          header('Content-Description: File Transfer');
          header('Content-Type: application/octet-stream');
          header('Content-Disposition: attachment; filename="' . basename($backup['backup_name']) . '"');
          header('Expires: 0');
          header('Cache-Control: must-revalidate');
          header('Pragma: public');
          header('Content-Length: ' . filesize($backup['backup_path']));
          readfile($backup['backup_path']);
          exit;
      }
      $_SESSION['settings_error'] = 'Backup file not found';
      redirect('/settings#backups');
  });

  // Delete Backup
  Router::get('/settings/backup-delete/{id}', function ($params) {
      requireAdmin();
      $id = (int)$params['id'];
      VpnServer::deleteBackup($id);
      $_SESSION['settings_success'] = 'Backup deleted successfully';
      redirect('/settings#backups');
  });
  ```

- [ ] **Step 4: Commit UI Changes**
  ```bash
  git add templates/settings.twig controllers/SettingsController.php public/index.php
  git commit -m "feat(backup): integrate UI configuration settings page and download actions"
  ```

---

### Task 6: Web UI Integration - Restore Modal and File Drag-Drop

**Files:**
- Modify: `templates/settings.twig`
- Modify: `public/index.php`

- [ ] **Step 1: Add Drop Zone Javascript and Modal HTML to settings.twig**
  Insert drop zone actions and HTML modals inside `templates/settings.twig` before `{% endblock %}`.
  Add to `templates/settings.twig`:
  ```html
  <!-- Restore Confirmation Modal -->
  <div id="restoreModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center hidden">
      <div class="bg-slate-900 border border-slate-800 rounded-xl max-w-lg w-full overflow-hidden shadow-2xl">
          <div class="px-5 py-4 border-b border-slate-800 bg-slate-900/50 flex justify-between items-center">
              <h3 class="text-base font-semibold text-white"><i class="fas fa-history mr-2 text-accent"></i>Confirm Restoration</h3>
              <button onclick="closeRestoreModal()" class="text-slate-400 hover:text-slate-200"><i class="fas fa-times"></i></button>
          </div>
          <form id="restore-form" method="POST" action="/settings/backup-restore">
              <input type="hidden" name="filepath" id="restore-filepath">
              <input type="hidden" name="scope" id="restore-scope">
              <div class="p-5 space-y-4">
                  <!-- Panel Restore Options -->
                  <div id="panel-restore-options" class="hidden space-y-4">
                      <div class="bg-red-500/10 border border-red-500/20 text-red-300 px-4 py-3 rounded-lg text-xs">
                          ⚠️ WARNING: A Full Panel restore will override your current database configurations and credentials.
                      </div>
                      <div class="space-y-2">
                          <label class="flex items-center text-sm font-medium text-slate-300">
                              <input type="checkbox" name="restore_mysql" value="1" checked class="rounded bg-slate-800 border-slate-700 mr-2 text-accent">
                              Restore Panel Database Schema & Data
                          </label>
                          <label class="flex items-center text-sm font-medium text-slate-300">
                              <input type="checkbox" name="restore_postgres" value="1" checked class="rounded bg-slate-800 border-slate-700 mr-2 text-accent">
                              Restore External PostgreSQL DB Data
                          </label>
                          <label class="flex items-center text-sm font-medium text-slate-300">
                              <input type="checkbox" name="restore_env" value="1" checked class="rounded bg-slate-800 border-slate-700 mr-2 text-accent">
                              Restore Environment Variables (.env)
                          </label>
                      </div>
                      <div class="pt-2 border-t border-slate-800">
                          <label class="block text-xs text-slate-400 mb-1 font-semibold uppercase">Selective Server Restore</label>
                          <p class="text-xs text-slate-500 mb-3">Leave empty to restore all. Check boxes below to ONLY restore specific VPN servers:</p>
                          <div id="panel-selective-servers" class="space-y-1.5 max-h-36 overflow-y-auto pr-2">
                              <!-- populated dynamically -->
                          </div>
                      </div>
                      <div>
                          <label class="block text-xs text-slate-400 mb-1">Type "RESTORE" to confirm</label>
                          <input type="text" id="confirm-input" class="input-field w-full px-3 py-2 text-sm font-mono" required placeholder="RESTORE">
                      </div>
                  </div>

                  <!-- Server Restore Options -->
                  <div id="server-restore-options" class="hidden space-y-4">
                      <div class="bg-slate-800/40 p-4 rounded-lg border border-slate-800">
                          <div class="text-xs text-slate-400">Target Host Config:</div>
                          <div class="text-sm font-bold text-white mb-2" id="backup-server-host"></div>
                          <div>
                              <label class="block text-xs text-slate-400 mb-1">Restoration Action:</label>
                              <select name="server_action" id="server-action-select" class="input-field w-full px-3 py-2 text-sm" onchange="toggleServerSelect()">
                                  <option value="overwrite">Overwrite existing server with matching Host</option>
                                  <option value="new">Import as a brand new VPN Server Copy</option>
                              </select>
                          </div>
                          <div id="target-server-select-div" class="mt-3">
                              <label class="block text-xs text-slate-400 mb-1">Target server to overwrite:</label>
                              <select name="target_server_id" id="target-server-select" class="input-field w-full px-3 py-2 text-sm">
                                  <!-- populated dynamically -->
                              </select>
                          </div>
                      </div>
                      <label class="flex items-center text-sm font-medium text-slate-300">
                          <input type="checkbox" name="sync_keys" value="1" checked class="rounded bg-slate-800 border-slate-700 mr-2 text-accent">
                          Sync restored keys & active clients directly to remote VPS
                      </label>
                  </div>
              </div>
              <div class="px-5 py-4 border-t border-slate-800 bg-slate-900/50 flex justify-end space-x-3">
                  <button type="button" onclick="closeRestoreModal()" class="px-4 py-2 bg-slate-800 hover:bg-slate-750 text-slate-300 rounded-lg text-sm transition-colors">Cancel</button>
                  <button type="submit" id="restore-submit-btn" class="btn-primary px-5 py-2 text-sm">Proceed Restoration</button>
              </div>
          </form>
      </div>
  </div>

  <script>
  const dropZone = document.getElementById('drop-zone');
  const fileInput = document.getElementById('restore-file-input');

  dropZone.addEventListener('click', () => fileInput.click());

  dropZone.addEventListener('dragover', (e) => {
      e.preventDefault();
      dropZone.classList.add('border-accent');
  });

  dropZone.addEventListener('dragleave', () => {
      dropZone.classList.remove('border-accent');
  });

  dropZone.addEventListener('drop', (e) => {
      e.preventDefault();
      dropZone.classList.remove('border-accent');
      if (e.dataTransfer.files.length) {
          handleBackupUpload(e.dataTransfer.files[0]);
      }
  });

  fileInput.addEventListener('change', (e) => {
      if (e.target.files.length) {
          handleBackupUpload(e.target.files[0]);
      }
  });

  async function handleBackupUpload(file) {
      const formData = new FormData();
      formData.append('backup_file', file);

      try {
          const res = await fetch('/settings/backup-analyze', {
              method: 'POST',
              body: formData
          });
          const data = await res.json();
          if (data.error) {
              alert(data.error);
              return;
          }
          
          document.getElementById('restore-filepath').value = data.filepath;
          document.getElementById('restore-scope').value = data.scope;
          
          if (data.scope === 'panel') {
              document.getElementById('panel-restore-options').classList.remove('hidden');
              document.getElementById('server-restore-options').classList.add('hidden');
              
              // Populate selective server checkboxes
              const serverDiv = document.getElementById('panel-selective-servers');
              serverDiv.innerHTML = '';
              data.servers.forEach(srv => {
                  serverDiv.innerHTML += `
                      <label class="flex items-center text-xs text-slate-300">
                          <input type="checkbox" name="selective_servers[]" value="${srv.id}" class="rounded bg-slate-800 border-slate-700 mr-2 text-accent">
                          ${srv.name} (${srv.host})
                      </label>
                  `;
              });
          } else {
              document.getElementById('panel-restore-options').classList.add('hidden');
              document.getElementById('server-restore-options').classList.remove('hidden');
              document.getElementById('backup-server-host').innerText = `${data.server.name} (${data.server.host})`;
              
              // Populate target servers overwrite dropdown
              const select = document.getElementById('target-server-select');
              select.innerHTML = '';
              data.all_servers.forEach(srv => {
                  const selected = srv.host === data.server.host ? 'selected' : '';
                  select.innerHTML += `<option value="${srv.id}" ${selected}>${srv.name} (${srv.host})</option>`;
              });
              toggleServerSelect();
          }
          
          document.getElementById('restoreModal').classList.remove('hidden');
      } catch (err) {
          alert('Upload failed: ' . err);
      }
  }

  function toggleServerSelect() {
      const val = document.getElementById('server-action-select').value;
      const selectDiv = document.getElementById('target-server-select-div');
      if (val === 'overwrite') {
          selectDiv.classList.remove('hidden');
      } else {
          selectDiv.classList.add('hidden');
      }
  }

  function closeRestoreModal() {
      document.getElementById('restoreModal').classList.add('hidden');
      document.getElementById('confirm-input').value = '';
  }

  document.getElementById('restore-form').addEventListener('submit', function (e) {
      const scope = document.getElementById('restore-scope').value;
      if (scope === 'panel') {
          const confirmText = document.getElementById('confirm-input').value;
          const selectiveChecked = document.querySelectorAll('input[name="selective_servers[]"]:checked').length > 0;
          if (!selectiveChecked && confirmText !== 'RESTORE') {
              e.preventDefault();
              alert('Please type RESTORE to confirm entire panel wipe.');
          }
      }
  });
  </script>
  ```

- [ ] **Step 2: Add Backup Analyze endpoint to public/index.php**
  Implement `/settings/backup-analyze` to process the uploaded file, extract details and return JSON data.
  Add inside `public/index.php`:
  ```php
  // Analyze Backup Upload (AJAX)
  Router::post('/settings/backup-analyze', function () {
      requireAdmin();
      header('Content-Type: application/json');

      if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
          echo json_encode(['error' => 'File upload failed']);
          return;
      }

      $tmpPath = $_FILES['backup_file']['tmp_name'];
      $origName = $_FILES['backup_file']['name'];
      $ext = pathinfo($origName, PATHINFO_EXTENSION);

      // Move file to temporary backup folder
      $destDir = '/var/www/html/backups/temp';
      if (!is_dir($destDir)) mkdir($destDir, 0755, true);
      $filepath = $destDir . '/' . uniqid() . '.' . $ext;
      move_uploaded_file($tmpPath, $filepath);

      if ($ext === 'zip') {
          $zip = new ZipArchive();
          if ($zip->open($filepath) !== true) {
              echo json_encode(['error' => 'Invalid ZIP file']);
              return;
          }
          $tempExtract = "/tmp/analyze_" . time();
          mkdir($tempExtract);
          $zip->extractTo($tempExtract);
          $zip->close();

          $servers = [];
          $serverFiles = glob("{$tempExtract}/servers/*.json");
          foreach ($serverFiles as $sf) {
              $sData = json_decode(file_get_contents($sf), true);
              if (isset($sData['server'])) {
                  $servers[] = [
                      'id' => $sData['server']['id'],
                      'name' => $sData['server']['name'],
                      'host' => $sData['server']['host']
                  ];
              }
          }
          exec("rm -rf {$tempExtract}");

          echo json_encode([
              'scope' => 'panel',
              'filepath' => $filepath,
              'servers' => $servers
          ]);
      } else {
          // JSON Individual Server Backup
          $sData = json_decode(file_get_contents($filepath), true);
          if (!isset($sData['server'])) {
              echo json_encode(['error' => 'Invalid server JSON format']);
              return;
          }
          
          $allServers = VpnServer::listAll();

          echo json_encode([
              'scope' => 'server',
              'filepath' => $filepath,
              'server' => $sData['server'],
              'all_servers' => $allServers
          ]);
      }
  });
  ```

- [ ] **Step 3: Add Restore Action handler in public/index.php**
  Implement `/settings/backup-restore` to execute the selected options of restore.
  Add inside `public/index.php`:
  ```php
  // Submit Backup Restore
  Router::post('/settings/backup-restore', function () {
      requireAdmin();
      $filepath = $_POST['filepath'] ?? '';
      $scope = $_POST['scope'] ?? '';

      if (empty($filepath) || !file_exists($filepath)) {
          $_SESSION['settings_error'] = 'Backup file not found';
          redirect('/settings#backups');
      }

      try {
          $bm = new BackupManager();
          if ($scope === 'panel') {
              $options = [
                  'restore_mysql' => isset($_POST['restore_mysql']),
                  'restore_postgres' => isset($_POST['restore_postgres']),
                  'restore_env' => isset($_POST['restore_env']),
                  'selective_servers' => $_POST['selective_servers'] ?? []
              ];

              $res = $bm->restorePanelBackup($filepath, $options);
              if (empty($options['selective_servers'])) {
                  $_SESSION['settings_success'] = 'Panel successfully restored. Sessions might have been reset.';
              } else {
                  $_SESSION['settings_success'] = 'Selective servers and clients restored successfully: ' . count($res['servers_restored']);
              }
          } else {
              // Server JSON Restore
              $serverData = json_decode(file_get_contents($filepath), true);
              $action = $_POST['server_action'] ?? 'new';
              $targetServerId = ($action === 'overwrite') ? (int)($_POST['target_server_id'] ?? 0) : null;
              
              $res = $bm->restoreServerBackup($serverData, $targetServerId);
              
              if (isset($_POST['sync_keys'])) {
                  $server = new VpnServer($res['server_id']);
                  // Trigger deploy redirect to rebuild the container with restored keys
                  redirect("/servers/{$res['server_id']}/deploy");
              } else {
                  $_SESSION['settings_success'] = "Server '{$res['name']}' database entries restored successfully.";
              }
          }
      } catch (Exception $e) {
          $_SESSION['settings_error'] = 'Restore failed: ' . $e->getMessage();
      }

      // Cleanup uploaded file
      unlink($filepath);
      redirect('/settings#backups');
  });
  ```

- [ ] **Step 4: Commit Restore UI Integrations**
  ```bash
  git add templates/settings.twig public/index.php
  git commit -m "feat(backup): implement drag-drop analysis upload and restore modals UI"
  ```

---

### Task 7: CLI Automation Script (`bin/backup.php`)

**Files:**
- Create: `bin/backup.php`

- [ ] **Step 1: Write backup CLI script**
  Create `bin/backup.php` to handle automation:
  ```php
  <?php
  /**
   * CLI Backup Runner for Cron
   */
  require_once __DIR__ . '/../vendor/autoload.php';
  require_once __DIR__ . '/../inc/Config.php';
  require_once __DIR__ . '/../inc/DB.php';
  require_once __DIR__ . '/../inc/BackupManager.php';

  Config::load(__DIR__ . '/../.env');

  try {
      $pdo = DB::conn();
      
      // Get settings schedule
      $stmt = $pdo->prepare("SELECT value FROM settings WHERE namespace = 'backup' AND `key` = 'telegram_settings'");
      $stmt->execute();
      $res = $stmt->fetch();
      
      $schedule = 'disabled';
      if ($res) {
          $settings = json_decode($res['value'], true);
          $schedule = $settings['schedule'] ?? 'disabled';
      }

      if ($schedule === 'disabled') {
          echo "Backups are currently scheduled as disabled.\n";
          exit(0);
      }

      // Read time conditions (e.g. run daily if 24h passed since last auto backup)
      $stmtLast = $pdo->prepare("SELECT created_at FROM server_backups WHERE backup_type = 'automatic' AND status = 'completed' ORDER BY created_at DESC LIMIT 1");
      $stmtLast->execute();
      $last = $stmtLast->fetch();

      $shouldRun = false;
      if (!$last) {
          $shouldRun = true;
      } else {
          $lastTime = strtotime($last['created_at']);
          $diff = time() - $lastTime;
          if ($schedule === 'daily' && $diff >= 86000) { // ~24h
              $shouldRun = true;
          } elseif ($schedule === 'weekly' && $diff >= 604000) { // ~7 days
              $shouldRun = true;
          }
      }

      if ($shouldRun) {
          echo "Triggering automated backup...\n";
          $bm = new BackupManager();
          $path = $bm->createPanelBackup(0, 'automatic');
          echo "Backup zip created: {$path}\n";
          
          if ($bm->sendToTelegram($path)) {
              echo "Backup successfully uploaded to Telegram.\n";
          }
          
          $pruned = $bm->pruneLocalBackups();
          echo "Pruned {$pruned} expired local backups.\n";
      } else {
          echo "No backup is scheduled to run at this moment.\n";
      }
  } catch (Exception $e) {
      echo "Backup execution failed: " . $e->getMessage() . "\n";
      exit(1);
  }
  ```

- [ ] **Step 2: Commit CLI Script**
  ```bash
  git add bin/backup.php
  git commit -m "feat(backup): add automated CLI backup runner script for cron job integration"
  ```
