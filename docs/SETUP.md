# Setup and Installation Guide

This guide explains how to install and configure the Nk-VPN Panel using Docker.

## Prerequisites

-   Docker installed and running.
-   Docker Compose (usually bundled with modern Docker installations).
-   Basic understanding of Linux terminal and file permissions.

## Installation Steps

1.  **Clone the repository (if applicable) or navigate to the project directory.**

2.  **Configuration:**
    Copy the sample environment file and configure your specific settings.
    ```bash
    cp .env.example .env
    ```
    Open `.env` in a text editor (e.g., `nano .env`) and set the following critical variables:
    *   `DB_PASSWORD`: Set a strong password for the MySQL root user.
    *   `ADMIN_EMAIL`: The email address for your initial administrator account.
    *   `ADMIN_PASSWORD`: The password for your initial administrator account.
    *   `APP_KEY` / `JWT_SECRET`: Generate a secure random string for signing JWT tokens and securing sessions.
    *   If you plan to use the external PostgreSQL sync, configure the `EXT_DB_*` variables.

3.  **Start the application:**
    Use Docker Compose to build and start the containers in detached mode.
    ```bash
    docker-compose up -d
    ```
    This will start the PHP-FPM/Apache container (`app`) and the MySQL database container (`db`).

4.  **Database Migrations:**
    The database migrations (located in `migrations/`) are designed to run automatically when the `db` container initializes for the first time. If you need to run them manually or encounter issues, you can execute them inside the `db` container.

5.  **Access the Panel:**
    Open your web browser and navigate to `http://localhost:8080` (or the IP address of your server). Log in using the `ADMIN_EMAIL` and `ADMIN_PASSWORD` you set in the `.env` file.

## Post-Installation Configurations

### 1. Generating a JWT Secret

If you didn't set a `JWT_SECRET` in your `.env`, you should generate one to secure API communications (used by the monitoring agent and telegram bot).

You can generate a strong key using the command line:
```bash
openssl rand -hex 32
```
Add the output to your `.env` file as `JWT_SECRET=your_generated_key`.

### 2. Setting up the Telegram Bot (Optional)

If you intend to use the client-facing Telegram bot for config delivery and server switching:
1.  Talk to [@BotFather](https://t.me/botfather) on Telegram to create a new bot and obtain an API token.
2.  Log in to the panel, go to **Settings**, and enter your bot token.
3.  Set the webhook URL to point to your panel's external address: `https://your-domain.com/api/telegram-bot/webhook` (HTTPS is required by Telegram).

### 3. Setting up Backups (Optional)

To enable automated backups to a Telegram chat:
1.  Create a Telegram Bot (if you haven't already for the client bot).
2.  Create a private Telegram channel or group and add the bot as an administrator.
3.  Find the Chat ID of the channel/group.
4.  Configure the Backup settings in the panel's **Settings** page.

## Troubleshooting

-   **Cannot connect to the database:** Ensure the `db` container is running (`docker ps`). Check the database logs: `docker-compose logs db`. Verify that the `.env` credentials match.
-   **Blank page on load:** Check the application logs in the `app` container: `docker-compose logs app`. Also, ensure you have run `composer install` if you are not using the pre-built Docker image.
-   **Permissions issues:** Ensure the `public/` directory and internal storage directories (if any) are readable/writable by the web server user (usually `www-data` in the Docker container).