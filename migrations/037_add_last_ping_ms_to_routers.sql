-- Add last_ping_ms column to routers table
ALTER TABLE routers ADD COLUMN last_ping_ms INT UNSIGNED NULL AFTER last_check_at;
