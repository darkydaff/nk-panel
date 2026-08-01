# Internal API Reference

The Nk-VPN Panel exposes a number of internal API endpoints, primarily used by the frontend interface (AJAX), the remote monitoring agents, and the Telegram Webhook.

All API routes are prefixed with `/api/` and return JSON responses unless otherwise noted. Most endpoints require authentication (either a valid session cookie or a Bearer JWT token).

## Authentication

Endpoints marked as `Auth Required` need one of the following:
1.  **Session:** An active PHP session cookie (used by the web UI).
2.  **JWT:** An `Authorization: Bearer <token>` header (used by monitoring agents and external scripts).

To obtain a JWT for API access, an administrator can generate a persistent token via the **Settings -> API Access** tab in the web interface.

## Endpoint Summary

### Metrics & Monitoring
Used by the web dashboard and remote `monitor_metrics.sh` agents.

*   `POST /api/servers/report-metrics`
    *   **Auth:** Requires a valid server `secret_token` in the JSON payload, not a user token.
    *   **Description:** Receives traffic and speed metrics pushed from a remote VPN server.
*   `GET /api/dashboard/metrics`
    *   **Auth Required:** Yes
    *   **Description:** Returns aggregated bandwidth metrics for the dashboard charts.
*   `GET /api/servers/{id}/metrics`
    *   **Auth Required:** Yes
    *   **Description:** Returns historical metrics for a specific server.
*   `GET /api/clients/{id}/metrics`
    *   **Auth Required:** Yes
    *   **Description:** Returns historical metrics for a specific client.

### Server Management
*   `GET /api/servers`
    *   **Auth Required:** Yes
    *   **Description:** Lists available servers based on user role.
*   `POST /api/servers/create`
    *   **Auth Required:** Yes
    *   **Description:** Creates a new VPN server entry.
*   `DELETE /api/servers/{id}/delete`
    *   **Auth Required:** Yes
    *   **Description:** Deletes a server.
*   `POST /api/servers/sync-ext-ids`
    *   **Auth Required:** Yes (Admin)
    *   **Description:** Forces synchronization of client server IDs to the external PostgreSQL database.

### Client Management
*   `GET /api/clients`
    *   **Auth Required:** Yes
    *   **Description:** Lists clients belonging to the authenticated user.
*   `POST /api/clients/create`
    *   **Auth Required:** Yes
    *   **Description:** Creates a new VPN client configuration.
*   `GET /api/clients/{id}/details`
    *   **Auth Required:** Yes
    *   **Description:** Retrieves detailed client data, including live stats syncing.
*   `POST /api/clients/{id}/revoke` / `POST /api/clients/{id}/restore`
    *   **Auth Required:** Yes
    *   **Description:** Disables/Enables a client's access on the remote server.
*   `POST /api/clients/{id}/extend` / `POST /api/clients/{id}/set-expiration`
    *   **Auth Required:** Yes
    *   **Description:** Modifies client subscription/expiration dates.
*   `POST /api/clients/{id}/set-traffic-limit`
    *   **Auth Required:** Yes
    *   **Description:** Sets a data cap (in bytes) for a specific client.

### External Clients (Subscription Management)
*   `GET /api/ext-clients/search`
    *   **Auth Required:** Yes
    *   **Description:** Autocomplete endpoint for searching external client codes.
*   `POST /api/ext-clients/sync`
    *   **Auth Required:** Yes
    *   **Description:** Manually triggers a pull from the external PostgreSQL database to the local MySQL cache.

### Backups
*   `POST /api/servers/{id}/backup`
    *   **Auth Required:** Yes
    *   **Description:** Creates a backup of a specific server.
*   `POST /api/backups/ext-db/create` / `POST /api/backups/ext-db/restore/{id}`
    *   **Auth Required:** Yes (Admin)
    *   **Description:** Creates or restores a standalone backup of the external PostgreSQL database.
*   `DELETE /api/backups/{id}`
    *   **Auth Required:** Yes
    *   **Description:** Deletes a specific backup archive.

### Routers (Keenetic Integration)
*   `GET /api/routers`
    *   **Auth Required:** Yes (Admin)
    *   **Description:** Lists all configured Keenetic routers.
*   `POST /api/routers/{id}/push`
    *   **Auth Required:** Yes (Admin)
    *   **Description:** Pushes the AmneziaWG configuration to the router.
*   `POST /api/routers/{id}/push-routing-groups`
    *   **Auth Required:** Yes (Admin)
    *   **Description:** Applies specific routing group policies to the router.
*   `GET /api/routers/{id}/interfaces`
    *   **Auth Required:** Yes (Admin)
    *   **Description:** Fetches current network interfaces from the router via CLI.

### Routing Groups
*   `GET /api/routing-groups`
    *   **Auth Required:** Yes (Admin)
    *   **Description:** Lists all routing groups (domain/IP lists).
*   `POST /api/routing-groups`
    *   **Auth Required:** Yes (Admin)
    *   **Description:** Creates or updates a routing group.
*   `POST /api/routing-groups/{id}/delete`
    *   **Auth Required:** Yes (Admin)
    *   **Description:** Deletes a routing group.

### Telegram Webhook
*   `POST /api/telegram-bot/webhook`
    *   **Auth Required:** No (Validates payload structure internally)
    *   **Description:** Receives updates from the Telegram API for the client-facing bot.

## Response Format

Most successful requests return a JSON object with a `success: true` flag and associated data.
```json
{
    "success": true,
    "message": "Operation completed."
}
```

Errors return an appropriate HTTP status code (e.g., 400, 401, 403, 500) and a JSON object containing an `error` message.
```json
{
    "error": "Detailed error message"
}
```