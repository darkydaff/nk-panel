-- Create system_settings table to store application configuration and state variables
CREATE TABLE IF NOT EXISTS system_settings (
    `key` VARCHAR(255) PRIMARY KEY,
    `value` TEXT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
