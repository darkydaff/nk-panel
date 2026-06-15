-- Add GeoIP coordinates to vpn_clients table
ALTER TABLE vpn_clients ADD COLUMN latitude DECIMAL(9, 6) NULL;
ALTER TABLE vpn_clients ADD COLUMN longitude DECIMAL(9, 6) NULL;
