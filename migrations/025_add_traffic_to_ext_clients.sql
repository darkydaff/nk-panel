-- Add persistent aggregate traffic tracking to ext_clients
ALTER TABLE ext_clients
ADD COLUMN bytes_sent BIGINT UNSIGNED DEFAULT 0,
ADD COLUMN bytes_received BIGINT UNSIGNED DEFAULT 0;

-- Initialize with sum of current configs
UPDATE ext_clients ec
SET 
  ec.bytes_sent = IFNULL((SELECT SUM(vc.bytes_sent) FROM vpn_clients vc WHERE vc.ext_client_code = ec.code), 0),
  ec.bytes_received = IFNULL((SELECT SUM(vc.bytes_received) FROM vpn_clients vc WHERE vc.ext_client_code = ec.code), 0);
