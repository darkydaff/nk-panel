# Amnezia VPN Web Management Panel (NK-Panel)

An advanced, comprehensive web-based management interface for managing Amnezia VPN servers, supporting AmneziaWG, 3proxy integration, Keenetic Router deployments, and S3-compatible automated backups.

## Table of Contents
1. [Overview](#overview)
2. [Key Features](#key-features)
3. [Technologies Used](#technologies-used)
4. [Prerequisites & Setup Instructions](#prerequisites--setup-instructions)
5. [Directory Structure](#directory-structure)
6. [Key Core Classes (`inc/`)](#key-core-classes-inc)
7. [API Endpoints Overview](#api-endpoints-overview)
8. [Background Jobs (Cron)](#background-jobs-cron)
9. [Security Practices](#security-practices)

---

## Overview

NK-Panel is designed to act as a centralized command center for deploying, monitoring, and managing VPN infrastructures based on AmneziaWG and Xray technologies. It provides a web UI built with TailwindCSS and Twig, communicating with a backend powered by PHP 8.2 and MySQL. The system heavily leverages Docker for seamless deployment.

## Key Features

- **Server Management**: Add, configure, and monitor multiple Amnezia VPN servers via SSH.
- **Client Management**: Create, delete, and manage VPN client configurations, track bandwidth usage, and monitor active connection status.
- **Keenetic Router Integration**: Directly push WireGuard configurations to Keenetic routers via API.
- **Telegram Bot Integration**: A dedicated bot that allows clients to fetch their configurations, view usage metrics, and interact with the panel.
- **Automated Backups**: Full support for local and S3-compatible cloud backups of the panel database and server states.
- **External DB Sync**: Synchronize client lists and billing states with external PostgreSQL/MySQL databases.
- **Metrics Collection**: Periodic tracking of sent/received bytes and active sessions.

## Technologies Used

- **Backend**: PHP 8.2+
- **Database**: MySQL 8.4 (Primary Data), PostgreSQL (External Sync Support)
- **Frontend**: Twig templating engine, TailwindCSS v4, DaisyUI v5, Vite v6.
- **Dependencies**: Composer (for `twig/twig`, `firebase/php-jwt`), NPM (for frontend assets).
- **Infrastructure**: Docker, Docker Compose, Caddy (Reverse Proxy).
- **Extensions Required**: `ext-pdo`, `ext-json`, `ext-curl`, `ext-gd`, `ext-sodium`.

## Prerequisites & Setup Instructions

### Prerequisites
- Docker and Docker Compose installed on the host machine.
- A domain name pointing to your server's IP address (if using Caddy for SSL).

### Setup

1. **Clone the repository** (if not already done).
2. **Environment Configuration**:
   Copy the example environment file and configure it:
   ```bash
   cp .env.example .env
   # Edit .env with your desired DB credentials, JWT secret, and Telegram bot token
   ```
3. **Build & Start Containers**:
   ```bash
   docker-compose up -d --build
   ```
4. **Database Initialization**:
   The initialization scripts in `migrations/` will automatically run on the first startup of the `db` container.
5. **Access the Panel**:
   Navigate to `http://<your-ip>:8084` (or `https://<your-domain>` if using the Caddy proxy).

## Directory Structure

```text
.
├── bin/                 # CLI scripts, background daemons, and cron jobs.
├── controllers/         # Controller logic for web views (e.g., SettingsController).
├── docs/                # Project plans and design documents.
├── inc/                 # Core PSR-4 auto-loaded PHP classes.
├── migrations/          # SQL files for DB schema initialization.
├── public/              # Document root containing index.php, CSS, JS, and image assets.
├── templates/           # Twig view templates (HTML).
├── docker-compose.yml   # Container orchestration configuration.
├── Dockerfile           # PHP + Apache + Cron container definition.
└── package.json / composer.json # Node and PHP dependency definitions.
```

## Key Core Classes (`inc/`)

- **`Auth.php` & `JWT.php`**: Handles user authentication, password hashing, and stateless JWT token issuance using `firebase/php-jwt`.
- **`DB.php` & `ExtDB.php`**: Singleton patterns for establishing PDO connections to the primary MySQL database and the secondary/external billing database.
- **`VpnServer.php`**: Handles all interactions with remote Amnezia servers via SSH. Facilitates client creation, deletion, and status checks.
- **`VpnClient.php`**: Manages the local state of VPN clients, associating them with servers and tracking bandwidth statistics.
- **`RouterManager.php` & `KeeneticRouter.php`**: Implements the logic to connect to and configure Keenetic Routers over HTTP/HTTPS APIs.
- **`BackupManager.php`**: Orchestrates local file backups, database dumps, and uploads to S3-compatible object storage.
- **`TelegramClientBot.php`**: Processes incoming Webhooks from Telegram, responding to user commands like `/start` or `/config`.
- **`Translator.php`**: A lightweight i18n utility relying on static lookup arrays.

## API Endpoints Overview

The application utilizes a custom router (`inc/Router.php`) defined in `public/index.php`.

### Web Routes
- `GET /`: Dashboard.
- `GET /login`, `POST /login`: Authentication.
- `GET /servers`, `GET /clients`, `GET /routers`: Resource listing views.

### API Routes (Require Admin Auth)
- **Servers**:
  - `POST /api/servers`: Add/update server.
  - `POST /api/servers/{id}/delete`: Delete server.
- **Clients**:
  - `GET /api/clients`: List clients.
  - `POST /api/clients`: Create a new client config.
  - `POST /api/clients/{id}/revoke`: Revoke access.
- **Routers**:
  - `POST /api/routers/{id}/check`: Validate router credentials and state.
  - `POST /api/routers/push-all`: Push pending configurations to routers.
- **Routing Groups**:
  - `GET /api/routing-groups`, `POST /api/routing-groups`: Manage IP/domain lists for split tunneling.

## Background Jobs (Cron)

Background tasks are managed via standard UNIX `cron` running inside the PHP-Apache Docker container. Output is logged to `/var/log/cron.log`.

- **`check_expired_clients.php`** (Hourly): Disables clients whose subscription dates have passed.
- **`check_traffic_limits.php`** (Hourly): Suspends clients exceeding bandwidth limits.
- **`sync_external_clients.php`** (Hourly): Syncs active clients with the `ExtDB` integration.
- **`backup.php`** (Every 30 mins): Executes scheduled system and database backups.
- **`check_routers.php`** (Every 15 mins): Polls Keenetic routers for connectivity status.
- **`monitor_metrics.sh`** (Every 3 mins): A shell script triggering PHP metrics collection.

## Security Practices

- **CSRF Protection**: Enforced for all state-mutating HTTP methods (POST, PUT, PATCH, DELETE) via `inc/CSRF.php`. Tokens must be passed in the `csrf_token` POST field or `X-CSRF-Token` header.
- **SQL Injection Prevention**: All dynamic queries must use strictly parameterized PDO prepared statements (`:param` or `?`).
- **N+1 Query Avoidance**: Bulk operations use batched `WHERE id IN (...)` logic to optimize database performance.
- **Authentication**: Stateless JWT combined with strict password hashing (`password_hash`).
