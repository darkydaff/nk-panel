-- Migration 015: Fix TIMESTAMP default values
-- Explicitly set DEFAULT NULL for timestamp columns that should not default to NOW()

ALTER TABLE vpn_servers 
MODIFY COLUMN deployed_at TIMESTAMP NULL DEFAULT NULL,
MODIFY COLUMN last_check_at TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE vpn_clients 
MODIFY COLUMN last_handshake TIMESTAMP NULL DEFAULT NULL,
MODIFY COLUMN last_sync_at TIMESTAMP NULL DEFAULT NULL,
MODIFY COLUMN expires_at TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE api_tokens 
MODIFY COLUMN last_used_at TIMESTAMP NULL DEFAULT NULL,
MODIFY COLUMN expires_at TIMESTAMP NULL DEFAULT NULL;

-- Fix existing data: if last_handshake is equal to created_at (approx), it's likely a default value error
-- But actually, just setting them to NULL if they haven't synced yet is safer for clients with 0 traffic
UPDATE vpn_clients SET last_handshake = NULL WHERE bytes_sent = 0 AND bytes_received = 0 AND last_sync_at IS NULL;
UPDATE vpn_servers SET deployed_at = NULL WHERE deployed_at = created_at AND status = 'deploying';
