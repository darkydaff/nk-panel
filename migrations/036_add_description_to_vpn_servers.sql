-- Migration 036: Add description column to vpn_servers for user-friendly bot naming
ALTER TABLE vpn_servers ADD COLUMN description VARCHAR(255) NULL;
