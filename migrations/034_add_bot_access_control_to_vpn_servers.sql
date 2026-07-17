-- Add fields for Telegram bot server access control
ALTER TABLE vpn_servers
ADD COLUMN show_in_bot TINYINT(1) NOT NULL DEFAULT 1,
ADD COLUMN allowed_clients TEXT NULL,
ADD COLUMN blocked_clients TEXT NULL;
