# Extended Backup System Schema & Router Mapping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend individual server and panel backup routines to store newly introduced server and client schema fields, restore them properly, and update router mapping associations when clients are restored.

**Architecture:** Update SQL queries and JSON array construction in `VpnServer::createBackup` and `BackupManager::createPanelBackup`. Modify server insert/update, client insert, and post-restore router updates in `BackupManager::restoreServerBackup`.

**Tech Stack:** PHP, MySQL (PDO), Slim/Custom PHP Routing.

---

### Task 1: Update VpnServer Backup Schema Exports

**Files:**
- Modify: [VpnServer.php](file:///d:/GitHub/nk-panel/inc/VpnServer.php) (lines 905-940)

- [ ] **Step 1: Update client SELECT and server backup array in VpnServer.php**
  Replace lines 905-940 in `inc/VpnServer.php` with the new schema configuration details (including `secret_token`, `traffic_limit`, `ext_client_code`, `user_id`, and `created_at`).

```php
            // Get all clients for this server
            $stmt = $pdo->prepare('
                SELECT id, user_id, name, client_ip, public_key, private_key, preshared_key, 
                       config, status, expires_at, traffic_limit, ext_client_code, created_at
                FROM vpn_clients 
                WHERE server_id = ?
            ');
            $stmt->execute([$this->serverId]);
            $clients = $stmt->fetchAll();

            // Extract private key from remote container dynamically if possible
            $privKey = null;
            try {
                $containerName = $this->data['container_name'];
                $privKey = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/server_private.key 2>/dev/null", true));
            } catch (Exception $e) {
                // Ignore error if server is offline or unreachable, keep it null
            }

            // Prepare backup data
            $backupData = [
                'server' => [
                    'id' => $this->serverId,
                    'name' => $this->data['name'],
                    'host' => $this->data['host'],
                    'port' => $this->data['port'],
                    'vpn_port' => $this->data['vpn_port'],
                    'vpn_subnet' => $this->data['vpn_subnet'],
                    'container_name' => $this->data['container_name'],
                    'server_public_key' => $this->data['server_public_key'],
                    'server_private_key' => $privKey,
                    'preshared_key' => $this->data['preshared_key'],
                    'awg_params' => $this->data['awg_params'],
                    'secret_token' => $this->data['secret_token'] ?? null,
                ],
                'clients' => $clients,
                'backup_date' => date('Y-m-d H:i:s'),
                'version' => '1.0'
            ];
```

- [ ] **Step 2: Verify code syntax by executing PHP syntax check**
  Run: `php -l inc/VpnServer.php`
  Expected: "No syntax errors detected in inc/VpnServer.php"

- [ ] **Step 3: Commit VpnServer changes**
```bash
git add inc/VpnServer.php
git commit -m "feat(backup): include new columns and server token in VpnServer backups"
```

---

### Task 2: Update BackupManager Panel Backup Schema Exports

**Files:**
- Modify: [BackupManager.php](file:///d:/GitHub/nk-panel/inc/BackupManager.php) (lines 97-99)

- [ ] **Step 1: Update client SELECT statement in BackupManager.php**
  Replace lines 97-99 in `inc/BackupManager.php` with:

```php
                $stmtClients = $this->pdo->prepare("SELECT user_id, name, client_ip, public_key, private_key, preshared_key, config, status, expires_at, traffic_limit, ext_client_code, created_at FROM vpn_clients WHERE server_id = ?");
```

- [ ] **Step 2: Verify code syntax**
  Run: `php -l inc/BackupManager.php`
  Expected: "No syntax errors detected in inc/BackupManager.php"

- [ ] **Step 3: Commit BackupManager exports update**
```bash
git add inc/BackupManager.php
git commit -m "feat(backup): export new client columns in panel backup"
```

---

### Task 3: Update BackupManager Server Restoration and Router Linking Logic

**Files:**
- Modify: [BackupManager.php](file:///d:/GitHub/nk-panel/inc/BackupManager.php) (lines 360-412)

- [ ] **Step 1: Update restoreServerBackup method implementation in BackupManager.php**
  Replace lines 360-412 in `inc/BackupManager.php` to restore `secret_token` and client parameters, and execute post-restore router linkage updates:

```php
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
```

- [ ] **Step 2: Run syntax validation**
  Run: `php -l inc/BackupManager.php`
  Expected: "No syntax errors detected in inc/BackupManager.php"

- [ ] **Step 3: Commit BackupManager restore changes**
```bash
git add inc/BackupManager.php
git commit -m "feat(backup): restore new columns and rebind matching Keenetic routers post-restore"
```

---

### Task 4: End-to-End Verification

**Files:**
- Test backup / restore logic: [backup.php](file:///d:/GitHub/nk-panel/bin/backup.php)

- [ ] **Step 1: Test CLI automated backup generation**
  Run: `php bin/backup.php`
  Expected: Script runs without SQL errors and outputs current backup configuration details.
