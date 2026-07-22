# Features Overview

Nk-VPN Panel provides a robust set of features for managing AmneziaWG/WireGuard VPN deployments.

## Server Management

The panel allows you to connect multiple remote Linux servers via SSH.
-   **Automated Deployment:** When a server is added, the panel automatically installs Docker, deploys the AmneziaWG container, and configures iptables/routing on the remote host.
-   **Monitoring Agent:** A lightweight script (`monitor_metrics.sh`) is deployed to the server to securely push traffic statistics (upload/download speeds and byte counts) back to the panel's API using a unique token.

## Client Management

-   **Peer Configuration:** Administrators can create individual client configurations. The panel manages the generation of public/private keys and IP address assignment within the server's subnet.
-   **Limits and Expirations:** Clients can have data caps (Traffic Limits in GB/MB) or expiration dates set. Background cron jobs monitor these limits and automatically revoke access if thresholds are reached.
-   **Stats Syncing:** Client traffic stats are aggregated and viewable in real-time on the dashboard.

## External Subscriptions Integration

The panel is designed to work alongside external billing or user-management systems.
-   **PostgreSQL Sync:** If configured in `.env`, the panel can periodically sync user subscription states from an external PostgreSQL database into the local `ext_clients` table.
-   **Auto-Linking:** When a new VPN client is created, the system attempts to auto-link it to an `ext_client_code` based on naming conventions, allowing the VPN access to be governed by the external billing state.

## Keenetic Router Integration

This feature allows for seamless VPN configuration on compatible Keenetic routers.
-   **Device Management:** Routers are added to the panel with their CLI credentials.
-   **Configuration Push:** The panel can SSH into the Keenetic router, create the WireGuard interface, apply the private key/endpoint settings for a specific client, and enable the connection automatically.

## Routing Groups

Routing groups enable split-tunneling configuration directly from the panel.
-   **Domain/IP Lists:** Admins can define lists of domains or IP CIDR blocks.
-   **Router Application:** These routing groups can be pushed to Keenetic routers, directing traffic for specific domains through the VPN interface while leaving other traffic on the standard ISP connection.

## Telegram Bot Integration

A built-in, client-facing Telegram bot (`inc/TelegramClientBot.php`) provides self-service capabilities.
-   **Configuration Retrieval:** Linked users can request their AmneziaWG configuration file directly through the bot.
-   **Server Switching:** If multiple servers are available, users can seamlessly switch their active connection from one server to another using inline bot menus.
-   **Access Control:** Server visibility can be toggled per-server, and explicit allow/block lists can be defined.
-   **Activity Logging:** All bot interactions are logged in the `bot_activity_logs` table and viewable in the admin dashboard.

## Backup and Restore

A comprehensive backup system (`inc/BackupManager.php`) ensures data safety.
-   **Panel Backups:** Archives the entire local MySQL database and `.env` configuration.
-   **External DB Backups:** Can create standalone dumps of the connected external PostgreSQL database.
-   **Server Backups:** Backs up individual VPN server configurations (keys, client lists) for easy migration.
-   **Telegram Uploads:** Backups can be automatically sent to a designated secure Telegram channel/chat for off-site storage.