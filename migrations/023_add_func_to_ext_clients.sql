-- Add func and router fields to ext_clients table to cache WORK/PAUSE status and router model
ALTER TABLE ext_clients
ADD COLUMN func VARCHAR(50) NULL,
ADD COLUMN router VARCHAR(255) NULL;
