# Architecture Overview

This document outlines the high-level architecture of the Nk-VPN Panel. The application is built using a custom, lightweight PHP MVC-inspired architecture for the backend and a modern build system (Vite + Tailwind CSS v4) for the frontend.

## Directory Structure

```
├── bin/            # Background daemon scripts and cron jobs
├── controllers/    # Request handlers (e.g., SettingsController)
├── docs/           # Documentation
├── inc/            # Core PHP classes (PSR-4 autoloaded)
├── migrations/     # Database migration scripts (SQL)
├── public/         # Web root directory (index.php, static assets)
├── src/            # Frontend source files (CSS, JS)
├── templates/      # Twig templates for views
└── vendor/         # Composer dependencies
```

## Backend Architecture

### Core Framework

The backend does not rely on a heavy framework like Laravel or Symfony. Instead, it utilizes a set of lightweight, purpose-built classes located in the `inc/` directory.

-   **PSR-4 Autoloading:** All classes in the `inc/` directory are automatically loaded via Composer's PSR-4 autoloader without namespaces.
-   **Routing (`inc/Router.php`):** A custom router handles HTTP requests. Routes are defined in `public/index.php`. It supports GET, POST, DELETE methods and simple pattern matching for URL parameters (e.g., `/servers/{id}`).
-   **Configuration (`inc/Config.php`):** Loads environment variables from a `.env` file and provides a simple interface to access them (`Config::get()`).
-   **Database Abstraction (`inc/DB.php`):** A singleton wrapper around PHP's PDO extension, providing secure, parameterized queries to the MySQL database.
-   **Authentication (`inc/Auth.php`, `inc/JWT.php`):** Handles both session-based authentication for the web interface and JWT-based authentication for API access.
-   **Templating (`inc/View.php`):** Integrates the Twig templating engine for rendering HTML views.

### Domain Models (Active Record Pattern)

The application uses an Active Record-like pattern where core entities manage both their data state and database interactions.

-   **`VpnServer.php`**: Represents a remote VPN server. Handles deployment, configuration generation, monitoring agent setup, and server-side backups via SSH.
-   **`VpnClient.php`**: Represents a VPN connection configuration (peer). Handles creation, revocation, traffic limit enforcement, and syncing statistics with the remote server.
-   **`ExtDB.php`**: Manages synchronization and interactions with an optional external PostgreSQL database (used for a broader subscription management system).
-   **`KeeneticRouter.php`**: Provides a client for interacting with Keenetic routers via their CLI interface over SSH.

### Entry Point (`public/index.php`)

All web requests are routed through `public/index.php` (Front Controller pattern). This file:
1. Initializes sessions and timezone.
2. Loads dependencies (Composer autoload) and configuration.
3. Establishes the database connection.
4. Initializes core services (Translator, View).
5. Defines all application routes using the `Router` class.
6. Dispatches the request.

## Frontend Architecture

The frontend is built using modern tooling while keeping the output lightweight.

-   **Build Tool (Vite):** Vite is used for rapid development and optimized production builds. The configuration is in `vite.config.js`.
-   **Styling (Tailwind CSS v4 & DaisyUI v5):** The UI is styled using Tailwind CSS for utility classes and DaisyUI for pre-built components (buttons, modals, tables, etc.).
-   **Templating (Twig):** Server-side rendering is handled by Twig templates located in `templates/`. These templates inject dynamic data from the backend controllers.
-   **JavaScript:** Vanilla JavaScript is used for interactivity (AJAX requests, DOM manipulation, charts via Chart.js).
-   **Assets:** Compiled assets are output to `public/css` and `public/js` and linked in the layout template.

## Background Processes

Several tasks run in the background to keep the system up-to-date:

-   **Monitoring (`bin/collect_metrics.php`, `bin/monitor_metrics.sh`):** Collects server and client traffic statistics.
-   **Daemons (`bin/telegram_bot_daemon.php`):** Runs the long-polling Telegram bot if webhooks are not used.
-   **Cron Jobs (`bin/check_expired_clients.php`, `bin/check_traffic_limits.php`, etc.):** Regularly check and enforce expiration dates and data caps.