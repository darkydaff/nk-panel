-- Add ext_server_id to vpn_servers to map local servers to external PostgreSQL Servers id
ALTER TABLE vpn_servers ADD COLUMN ext_server_id INT NULL;
