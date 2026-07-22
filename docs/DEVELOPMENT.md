# Development Guide

This document outlines the development workflow, coding standards, and build processes for contributing to the Nk-VPN Panel.

## Requirements

-   PHP 8.4+ (The system strictly relies on modern PHP 8.4 features, though development containers might use 8.5-fpm).
-   Composer for PHP dependency management.
-   Node.js (v18+) and npm (or bun/yarn) for frontend asset building.

## Backend Development

### Standards and Best Practices

1.  **Strict Typing:** Always use strict typing (`declare(strict_types=1);` where applicable) and utilize PHP 8 type hints for arguments and return types.
2.  **Security First:**
    -   *SQL Injection:* NEVER concatenate user input into SQL strings. Always use PDO prepared statements with parameter binding (e.g., `WHERE id = :id` or `WHERE id = ?`).
    -   *CSRF:* Any route handling POST/PUT/DELETE requests that mutates state must be protected by CSRF tokens (handled automatically by the `Router` wrapper if implemented correctly, or verified manually).
3.  **Database Queries:**
    -   Avoid N+1 queries. Use `IN(...)` clauses to batch process records where possible.
    -   *PHPStan Warning:* When building dynamic `IN(...)` arrays, always check that the array is not empty before executing the query (e.g., `if (!empty($ids))`) to prevent SQL syntax errors like `WHERE id IN ()`.
4.  **Enums:** Use PHP 8.1+ Enums for database status fields or fixed sets of values (e.g., `\Enums\ClientStatus`).

### Code Analysis and Testing

-   **Static Analysis:** Run PHPStan to catch type errors and potential bugs before committing.
    ```bash
    ./vendor/bin/phpstan analyze
    ```
    *Note: If testing within a restricted Docker sandbox, you may need to run `composer install --ignore-platform-reqs` first to install PHPStan.*

-   **Unit Testing:** Tests are located in `tests/Unit/`.
    ```bash
    ./vendor/bin/phpunit
    ```
    -   Tests modifying global state (`$_SERVER`, static properties like `Translator::$translations`) must record the original state in `setUp()` and restore it in `tearDown()` using `ReflectionProperty` if necessary to ensure test isolation.

## Frontend Development

The frontend utilizes Vite as a build tool to process Tailwind CSS v4 and vanilla JS.

### Directory Layout
-   `src/css/app.css`: The main CSS entry point, where Tailwind is imported.
-   `vite.config.js`: Configuration for the Vite bundler.
-   `public/css/app.css`: The output location for compiled CSS.

### Building Assets

1.  **Install dependencies:**
    ```bash
    npm install
    ```
    *(Or `npm install --legacy-peer-deps` if facing dependency conflicts).*

2.  **Development Mode (Watch):**
    To continuously compile CSS/JS while making changes, run:
    ```bash
    npm run dev
    ```
    This watches the `templates/` and `src/` directories and rebuilds `public/css/app.css` on the fly.

3.  **Production Build:**
    Before committing frontend changes, generate a minified build:
    ```bash
    npm run build
    ```

### Templates

-   Views are written in Twig (`templates/*.twig`).
-   To add new Tailwind classes to a template, simply write them. If the Vite watcher (`npm run dev`) is running, the CSS will be updated automatically. Ensure you commit the generated `public/css/app.css` alongside your template changes if you are adding new classes.

## Ephemeral Testing

For quick, non-destructive tests or ad-hoc scripts, use the `scratch/` directory. Files placed here are generally ignored by Git and are safe for temporary scratchpads.