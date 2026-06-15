-- Add secret token for servers and speed columns for clients
-- This enables push-based real-time stats updates

-- Add secret_token to vpn_servers
ALTER TABLE vpn_servers ADD COLUMN secret_token VARCHAR(64) NULL UNIQUE;

-- Add speed columns to vpn_clients
ALTER TABLE vpn_clients ADD COLUMN speed_up_kbps DECIMAL(10,2) DEFAULT 0.00;
ALTER TABLE vpn_clients ADD COLUMN speed_down_kbps DECIMAL(10,2) DEFAULT 0.00;

-- Generate random tokens for existing servers
UPDATE vpn_servers SET secret_token = SHA2(CONCAT(RAND(), NOW(), id), 256) WHERE secret_token IS NULL;
