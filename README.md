# Nk-VPN Panel (Amnezia VPN Web Management Panel)

A comprehensive, self-hosted web management panel designed for managing Amnezia VPN servers and clients, with features like Telegram Bot integration, Keenetic Router support, routing groups, advanced monitoring, and backup capabilities.

## Key Features

-   **Multi-Server Management:** Easily deploy and manage multiple VPN servers from a single dashboard.
-   **Client Management:** Create, configure, monitor, and revoke VPN clients. Features automatic linking, traffic limits, and expiration tracking.
-   **Advanced Monitoring:** Real-time metrics for servers and individual clients (upload/download speeds, data usage).
-   **Keenetic Router Integration:** Automatically sync VPN clients with Keenetic routers and push configurations/routing groups directly.
-   **Routing Groups:** Create and manage routing groups (domain/IP lists) and assign them to clients and routers for advanced split tunneling.
-   **Telegram Bot integration:** A client-facing Telegram bot for easy configuration retrieval and server switching.
-   **Comprehensive Backups:** System-wide, external DB, and individual server backups with automated Telegram uploads.
-   **Multi-Language Support:** Localized interface (English, Russian, Spanish, German, French, Chinese) managed via database translations.

## Documentation

For a detailed understanding of the system, please refer to the documentation in the `docs/` directory:

-   [Architecture](docs/ARCHITECTURE.md) - System design, tech stack, and codebase structure.
-   [Setup & Installation](docs/SETUP.md) - Instructions for local development and production deployment using Docker.
-   [Database & Migrations](docs/DATABASE.md) - Schema overview and migration system.
-   [Features Overview](docs/FEATURES.md) - Detailed explanation of core functionalities.
-   [API Reference](docs/API.md) - Internal API endpoints and their usage.
-   [Development Guide](docs/DEVELOPMENT.md) - Coding standards, frontend build processes, and testing.

## Technologies Used

-   **Backend:** PHP 8.4+, Custom Framework (PSR-4 autoloading, custom routing).
-   **Database:** MySQL/MariaDB (via PDO). External PostgreSQL integration available.
-   **Frontend:** Vite, Tailwind CSS v4, DaisyUI v5, Twig templating engine.
-   **Infrastructure:** Docker, Docker Compose.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
