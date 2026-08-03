-- 1. Modify server_backups table to allow nullable server_id for panel backups
ALTER TABLE server_backups 
  MODIFY COLUMN server_id INT UNSIGNED NULL,
  ADD COLUMN backup_scope ENUM('panel', 'server') DEFAULT 'server' AFTER server_id;

-- 2. Modify vpn_servers table to store temporary private keys during restoration
ALTER TABLE vpn_servers 
  ADD COLUMN server_private_key TEXT NULL AFTER server_public_key;
