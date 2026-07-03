-- Create ext_clients table to cache codes from external Postgres
CREATE TABLE IF NOT EXISTS ext_clients (
    code VARCHAR(100) PRIMARY KEY,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
