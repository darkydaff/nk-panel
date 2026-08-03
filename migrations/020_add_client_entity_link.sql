-- Add external client code link to vpn_clients table
-- NULL  = anonymous / standalone config (existing behaviour)
-- Value = Code from the external Postgres Clients DB
ALTER TABLE vpn_clients
    ADD COLUMN ext_client_code VARCHAR(100) NULL
        COMMENT 'Client Code from external Postgres DB; NULL = anonymous config';

ALTER TABLE vpn_clients
    ADD INDEX idx_ext_client_code (ext_client_code);
