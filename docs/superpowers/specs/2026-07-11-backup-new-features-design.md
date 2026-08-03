# Backup System Extension for New Features

This design spec details extensions to the backup and restore system of the Nk-VPN Panel to accommodate features introduced since the original backup implementation. Specifically, this includes preserving `secret_token` (for server agent metrics authentication), client traffic limits (`traffic_limit`), client owners (`user_id`), external billing references (`ext_client_code`), client creation timestamps (`created_at`), and maintaining Keenetic router links (`routers` table) post-restoration.

## Background

Since the comprehensive backup system was created, several major features have been added:
1. **Server Agent Monitoring**: The panel authenticates remote metric collectors using a `secret_token` stored on `vpn_servers`.
2. **Traffic Limits**: Clients can have a `traffic_limit` set.
3. **External Client Mapping**: Clients can be associated with an external PostgreSQL billing database via `ext_client_code`.
4. **Keenetic Routers**: Keenetic router configurations are stored in the database, mapping an `ext_client_code` to a specific `vpn_client_id` and `server_id`.

If individual server backup JSON exports are created or restored, these new columns and references must be preserved. Furthermore, when restoring client accounts, any matching router links in the `routers` table must be updated to reference the newly restored/mapped client IDs and server IDs.

## Proposed Changes

### 1. Update Backup Schema Exports

#### `inc/VpnServer.php` (`createBackup`)
* Modify the query fetching clients to select: `id`, `user_id`, `name`, `client_ip`, `public_key`, `private_key`, `preshared_key`, `config`, `status`, `expires_at`, `traffic_limit`, `ext_client_code`, `created_at`.
* Include `secret_token` and `id` in the `server` details array written to JSON.

#### `inc/BackupManager.php` (`createPanelBackup`)
* Modify the query fetching clients to select: `user_id`, `name`, `client_ip`, `public_key`, `private_key`, `preshared_key`, `config`, `status`, `expires_at`, `traffic_limit`, `ext_client_code`, `created_at`.
* The server information is retrieved via `$server->getData()` which automatically includes `secret_token` and all other db columns.

### 2. Update Restore Logic

#### `inc/BackupManager.php` (`restoreServerBackup`)
* **Server updates**: Update `secret_token = COALESCE(?, secret_token)` to update the token if provided in the backup, or preserve the existing one if not.
* **Server creation**: Set `secret_token` in the SQL `INSERT` statement. Use `$s['secret_token'] ?? bin2hex(random_bytes(32))` as parameters.
* **Client restoration**:
  * Modify the insert query to include: `user_id`, `traffic_limit`, `ext_client_code`, `created_at`.
  * Retrieve the `$clientId` of the restored client (whether newly created or existing duplicate).
  * Check if `$c['ext_client_code']` is set. If present, execute an update on the `routers` table to bind the router matching the `ext_client_code` to the restored `$clientId` and `$serverId`.

## Verification Plan

### Automated Verification
* Run CLI backup (`bin/backup.php`) to ensure backup archives generate successfully.
* Verify backup JSON structure manually.

### Manual Verification
* Perform a manual individual server backup.
* Restore/overwrite the server backup and verify:
  * Client owners (`user_id`), traffic limits (`traffic_limit`), and external codes (`ext_client_code`) are correctly restored.
  * Server `secret_token` remains intact.
  * Linked Keenetic router references (`vpn_client_id` and `server_id`) in `routers` are successfully updated.
