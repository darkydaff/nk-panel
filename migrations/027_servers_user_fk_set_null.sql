-- Migration 027: Change vpn_servers.user_id FK from CASCADE to SET NULL
-- Prevents server records from being deleted when their owner account is removed.
-- Servers become "unowned" (user_id = NULL) instead of deleted.

ALTER TABLE vpn_servers
    DROP FOREIGN KEY vpn_servers_ibfk_1;   -- drop the old CASCADE constraint

ALTER TABLE vpn_servers
    MODIFY COLUMN user_id INT NULL,        -- allow NULL (unowned servers)
    ADD CONSTRAINT fk_vpn_servers_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
