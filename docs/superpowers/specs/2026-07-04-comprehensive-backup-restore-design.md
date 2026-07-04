# Technical Design: Comprehensive Panel and Server Backup & Restore System

This document specifies the technical design for a comprehensive backup and restore system for the Nk-VPN panel. It covers full panel-wide backups (MySQL, PostgreSQL, config files), individual server backups (JSON format), automated offsite exports to Telegram, and individual server disaster-recovery container reconstruction.

---

## 1. System Requirements & Dependencies

To perform database dumps, the PHP Apache container must have native CLI database clients.

### Dockerfile Updates
We will add `mariadb-client` (for MySQL dumps) and `postgresql-client` (for PostgreSQL dumps) to the `apt-get install` block in the project's `Dockerfile`:
```dockerfile
# Install dependencies
RUN apt-get update && apt-get install -y \
    ...
    mariadb-client \
    postgresql-client \
    ...
```

---

## 2. Database Schema Changes

We will create a new database migration file `migrations/026_backup_restore_system_support.sql` containing:

1. **Alter `server_backups` Table**:
   - Make `server_id` nullable to support panel-wide backups (which do not map to a single server).
   - Add a column `backup_scope` (`ENUM('panel', 'server') DEFAULT 'server'`).
2. **Alter `vpn_servers` Table**:
   - Add `server_private_key TEXT NULL` to temporarily store the server's private key during a restoration deployment, which is then cleared.

```sql
-- migration file: 026_backup_restore_system_support.sql

-- 1. Modify server_backups table to allow nullable server_id for panel backups
ALTER TABLE server_backups 
  MODIFY COLUMN server_id INT UNSIGNED NULL,
  ADD COLUMN backup_scope ENUM('panel', 'server') DEFAULT 'server' AFTER server_id;

-- 2. Modify vpn_servers table to store temporary private keys during restoration
ALTER TABLE vpn_servers 
  ADD COLUMN server_private_key TEXT NULL AFTER server_public_key;
```

---

## 3. Core Backend Logic (`inc/BackupManager.php`)

We will introduce `inc/BackupManager.php` as a core service class.

### Class Blueprint

```php
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
    public function createPanelBackup(int $userId, string $type = 'manual'): string;

    /**
     * Restores a full panel backup or extracts and restores selected servers.
     */
    public function restorePanelBackup(string $zipPath, array $options = []): array;

    /**
     * Backs up a single VPN server, including fetching its private key from the VPS container.
     */
    public function createServerBackup(int $serverId, int $userId, string $type = 'manual'): string;

    /**
     * Restores an individual server JSON file, including settings and client list.
     */
    public function restoreServerBackup(array $backupData, ?int $targetServerId = null): array;

    /**
     * Transmits a backup archive file to Telegram.
     */
    public function sendToTelegram(string $filePath): bool;

    /**
     * Prunes local backups according to the retention policy.
     */
    public function pruneLocalBackups(): int;
}
```

### Detailed Execution Flows

#### 1. Panel-Wide Backup Flow (`createPanelBackup`)
1. Create a timestamped temp directory: `/tmp/panel_backup_XXXXXX`.
2. **MySQL Dump**: Execute `mysqldump -h db -u [user] -p[pass] amnezia_panel > /tmp/panel_backup_XXXXXX/panel_db.sql`.
3. **PostgreSQL Dump**: If external PostgreSQL is configured, execute `pg_dump -h [host] -U [user] -d [db] -F p > /tmp/panel_backup_XXXXXX/postgres_db.sql` (configured using PGPASSWORD environment variable).
4. **Environment Copy**: Copy `/var/www/html/.env` to `/tmp/panel_backup_XXXXXX/config.env`.
5. **Server Backups**: Loop through all servers and export their backup JSON files into `/tmp/panel_backup_XXXXXX/servers/server_[id].json` using `VpnServer::createBackup` logic (but fetching the remote private key too).
6. **Archive**: Compress `/tmp/panel_backup_XXXXXX` into `/var/www/html/backups/panel/panel_backup_YYYY-MM-DD_HHMMSS.zip`.
7. **Clean up**: Delete all files in the temp directory.
8. **Logging**: Record the backup file size, path, and state in the `server_backups` table with `backup_scope = 'panel'`.
9. **Dispatch Telegram**: If Telegram backup is enabled, send the ZIP file to the user's Telegram chat.

#### 2. Telegram Send Flow (`sendToTelegram`)
Sends the backup archive directly via cURL `sendDocument` API:
```php
$url = "https://api.telegram.org/bot{$botToken}/sendDocument";
$postFields = [
    'chat_id' => $chatId,
    'document' => new CURLFile($filePath),
    'caption' => "🛡️ Nk-VPN Panel Backup: " . basename($filePath)
];
// Execute POST request via cURL
```

#### 3. Individual Server Disaster-Recovery Deployment
When a server needs to be deployed/rebuilt from a backup (Case: Container deleted, VPS wiped):
1. **Restore Server Entry**: Recreate the DB row in `vpn_servers` from the backup JSON, mapping the `server_private_key` field in the database.
2. **Standard Deployment Run**: Run `deploy()`. In `VpnServer::initializeServerConfig()`, modify the logic:
   - If `$this->data['server_private_key']` is present:
     - Instead of running `awg genkey` and `awg genpsk` on the remote VPS container, write the existing private key and preshared key directly to the server files:
       ```bash
       echo "{$this->data['server_private_key']}" > /opt/amnezia/awg/server_private.key
       echo "{$this->data['preshared_key']}" > /opt/amnezia/awg/wireguard_psk.key
       cat /opt/amnezia/awg/server_private.key | /usr/local/bin/awg pubkey > /opt/amnezia/awg/wireguard_server_public_key.key
       ```
3. **Database Cleansing**: After successful deployment, set `server_private_key = NULL` in `vpn_servers` for security.
4. **State Reconstruction**: Call `syncAllClientsToContainer()` to rebuild `wg0.conf` and `clientsTable` on the VPS.

#### 4. Batch Client Synchronization (`syncAllClientsToContainer`)
To rebuild a remote container's configurations in one go:
1. Fetch all active clients for the server from the database.
2. Construct the full contents of `wg0.conf`:
   - `[Interface]` section containing the private key, listen port, MTU, and AWG parameters.
   - Multiple `[Peer]` blocks containing the public key, preshared key, and IP address for each client.
3. Construct the `clientsTable` JSON array containing client identifiers and metadata.
4. Write both files to the remote container:
   - `echo "$wgConfig" | docker exec -i [container] sh -c 'cat > /opt/amnezia/awg/wg0.conf'`
   - `echo "$clientsJson" | docker exec -i [container] sh -c 'cat > /opt/amnezia/awg/clientsTable'`
5. Execute wireguard config sync:
   - `docker exec -i [container] bash -c '/usr/local/bin/awg syncconf wg0 <(/usr/local/bin/awg-quick strip /opt/amnezia/awg/wg0.conf)'`

---

## 4. UI & UX Flows

### Tab Additions in `settings.twig`
A new tab **"Backups"** will be added.

### Layout of the Backups Tab
```
+---------------------------------------------------------+
| [Tab: Profile]   [Tab: Users]   [Tab: Backups (Active)] |
+---------------------------------------------------------+
|                                                         |
|  1. Configuration Settings                              |
|  +---------------------------------------------------+  |
|  | [ ] Enable Telegram Upload                        |  |
|  | Bot Token: [••••••••••••••••••••••••••••••••••••] |  |
|  | Chat ID:   [ 1234567890                         ] |  |
|  | Retention: [ 7 ] Days   Schedule: [ Daily   ] [v] |  |
|  | [Save Configuration]                              |  |
|  +---------------------------------------------------+  |
|                                                         |
|  2. Create & Restore Backups                            |
|  +-------------------------+-------------------------+  |
|  | Target: [ Full Panel ]v | Drag and Drop Backup    |  |
|  | [ Create Backup Now ]   | ZIP/JSON files here     |  |
|  |                         | OR                      |  |
|  |                         | [ Select File ]         |  |
|  +-------------------------+-------------------------+  |
|                                                         |
|  3. Backup History                                      |
|  +---------------------------------------------------+  |
|  | Date        | Scope   | Type   | Size | Status    |  |
|  +-------------+---------+--------+------+-----------+  |
|  | 2026-07-04  | Panel   | Manual | 2MB  | Completed |  |
|  | 2026-07-03  | Server1 | Auto   | 15KB | Completed |  |
|  |             |         |        |      |           |  |
|  | [Download Icon]   [Restore Icon]   [Delete Icon]  |  |
|  +---------------------------------------------------+  |
+---------------------------------------------------------+
```

### Drag-and-Drop / Restore Confirmation Modals

#### Case A: User drops a Full Panel ZIP
A modal prompts the user:
```
+--------------------------------------------------------+
| Confirm Panel Restore                                  |
+--------------------------------------------------------+
| You uploaded a Panel Backup ZIP. Choose action:        |
|                                                        |
| (•) Restore Entire Panel (Database & Configs)          |
|     [x] Restore Database | [x] Restore .env Settings   |
|     ⚠️ WARNING: Overwrites current panel database.     |
|     Please type 'RESTORE' to confirm: [           ]    |
|                                                        |
| ( ) Restore Specific VPN Servers Only                  |
|     Select servers to extract and restore:             |
|     [x] Server A (185.10.20.1) - 45 Clients            |
|     [ ] Server B (90.150.12.3) - 12 Clients            |
|                                                        |
|                           [ Cancel ] [ Proceed ]       |
+--------------------------------------------------------+
```

#### Case B: User drops a Server JSON File
A modal prompts the user:
```
+--------------------------------------------------------+
| Restore Individual VPN Server                          |
+--------------------------------------------------------+
| Server Backup: "Server A" (IP: 185.10.20.1)            |
| Status: Contains 45 VPN Clients.                       |
|                                                        |
| Where should this server config be restored?           |
| (•) Overwrite existing server:                         |
|     [ Server A (185.10.20.1)                       ]v  |
| ( ) Import as a new separate VPN server                |
|                                                        |
| [x] Automatically rebuild container & sync client keys |
|     (re-installs WireGuard container on the VPS)       |
|                                                        |
|                           [ Cancel ] [ Restore Server] |
+--------------------------------------------------------+
```

---

## 5. Automated CLI Operations (Cron)

A backup runner script `bin/backup.php` will be triggered by Cron:
*   Checks `backup_schedule` and `telegram_enabled` database settings.
*   Executes `BackupManager::createPanelBackup(userId: 0, type: 'automatic')`.
*   Triggers pruning via `BackupManager::pruneLocalBackups()`.

Setup in `/etc/cron.d/amnezia-cron`:
```cron
# Automated backups checked every hour (runs backup if due based on schedule)
0 * * * * www-data cd /var/www/html && /usr/local/bin/php bin/backup.php >> /var/log/cron.log 2>&1
```

---

## 6. Verification Plan

### Manual Verification Flow
1. **Setup**: Populate the panel with 1-2 servers and generate 3-5 client configurations. Configure a Telegram bot with token/chat ID in the Settings UI.
2. **Panel Backup Test**: Click "Create Backup Now" for the panel target. Check that the ZIP is generated locally, a database record is created in `server_backups`, and the file is uploaded to the Telegram chat.
3. **Deconstruct Server Test**: Delete a server from the dashboard (this deletes the container on the remote VPS).
4. **Selective Server Restore Test**:
   - Upload the Panel ZIP file.
   - Select "Restore Specific VPN Servers Only" and check the box next to the deleted server.
   - Proceed with the restore.
   - Verify the server row is re-added to the dashboard, all 45 clients are restored to the database.
5. **Redeployment Test**: Click "Deploy" for the restored server. Check SSH logs to confirm that the container is rebuilt, the *original* keys are written back to `/opt/amnezia/awg/`, and `syncAllClientsToContainer()` executes.
6. **Connection Check**: Try to connect with a client device using an old configuration file. It must successfully handshake and route traffic.
