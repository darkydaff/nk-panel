# Design Spec: Telegram Bot Activity Logs & Admin Dashboard

This document details the design for tracking Telegram Client Bot user activities (actions, server changes, access controls, errors) and presenting them on a dedicated tab in the admin panel with live statistics and charts.

## 1. Goal
Provide administrators with full visibility into bot client activity ("who, what, when, where"), including:
- Access control logs (identifying unauthorized users attempting to use the bot so they can be bound).
- Real-time client configuration changes (tracking which client/router is switching to which server).
- Interactive stats/charts visualising activity trends and action distributions.

## 2. Database Schema
We will create a new table `bot_activity_logs` via a SQL migration:

```sql
CREATE TABLE IF NOT EXISTS bot_activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tg_id BIGINT NOT NULL,
    tg_name VARCHAR(255) NOT NULL,
    client_code VARCHAR(255) NULL,
    action VARCHAR(50) NOT NULL,
    details TEXT NULL,
    raw_data TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_tg_id (tg_id),
    INDEX idx_client_code (client_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Self-Healing Migration
We will add a check in `inc/DB.php::checkAndRunMigrations()` to execute this script if the table does not exist:
```php
// Check if bot_activity_logs table exists
try {
    $pdo->query("SELECT 1 FROM bot_activity_logs LIMIT 1");
    $hasLogsTable = true;
} catch (Throwable $e) {
    $hasLogsTable = false;
}

if (!$hasLogsTable) {
    $sqlPath = __DIR__ . '/../migrations/035_create_bot_activity_logs_table.sql';
    if (file_exists($sqlPath)) {
        $sql = file_get_contents($sqlPath);
        $pdo->exec($sql);
    }
}
```

## 3. Backend Logging Logic (`inc/TelegramClientBot.php`)

A centralized static helper method `logActivity` will be added to the `TelegramClientBot` class:

```php
public static function logActivity(int $tgId, string $tgName, ?string $clientCode, string $action, ?string $details = null, ?string $rawData = null): void {
    try {
        $pdo = DB::conn();
        $stmt = $pdo->prepare("
            INSERT INTO bot_activity_logs (tg_id, tg_name, client_code, action, details, raw_data)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tgId, $tgName, $clientCode, $action, $details, $rawData]);
    } catch (Throwable $e) {
        error_log("Failed to log bot activity: " . $e->getMessage());
    }
}
```

This method will be called at key points within `handleUpdate` and `handleCallback`:
- **unauthorized**: Fired when access validation fails (ID not associated with any active client).
- **view_menu**: Fired when `/start` or `/help` commands are executed, or when returning to the main menu.
- **show_version**: Fired when checking router firmware versions.
- **select_router**: Fired when selecting a specific router from the menu.
- **view_servers**: Fired when requesting a list of available servers for a router.
- **change_server**: Fired when starting a server-switching job.
- **change_server_success**: Fired when the router configuration update is successful.
- **change_server_error**: Fired if server-switching fails.

## 4. Admin Routing (`public/index.php`)

Add the `/bot-logs` route to handle statistics aggregation, search filtering, and logs pagination:
- **Total Count / Unique Users / Switches / Errors** stats are queried directly.
- **Trend Data** (grouped by day for the last 7 days) and **Action Distribution** are queried to fuel Chart.js.
- **Logs Pagination** with a default of 25 records per page, filterable by categories: `switches`, `access`, `errors`.

## 5. User Interface Design (`templates/bot_logs.twig`)

We will create a new twig file representing a premium control panel layout:
- **Metrics Summary Cards**: Grid of 4 summary panels using DaisyUI stats styles.
- **Charts Row**: Two canvas elements loaded with Chart.js:
  - Line Chart (Trend) displaying daily requests.
  - Doughnut Chart (Distribution) showing actions.
- **Log Table Stream**: A clean tabular interface with pagination.
- **Navigation sidebar link**: Appended in `templates/layout.twig` under the Admin conditional check.

## 6. Verification & Test Plan
- **Database Hook:** Trigger database initialization and verify that the `bot_activity_logs` table is automatically created.
- **Logger Tests:** Emulate Telegram webhook payloads for unauthorized access, version requests, and server switches, verifying that records are correctly logged to the database.
- **Dashboard Review:** Open the dashboard tab `/bot-logs` and verify stats are aggregated properly and the Chart.js visual canvas updates dynamically.
