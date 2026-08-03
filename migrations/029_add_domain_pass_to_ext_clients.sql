-- Add domain and pass fields to ext_clients table to cache credentials from external PostgreSQL
ALTER TABLE ext_clients
ADD COLUMN domain VARCHAR(255) NULL,
ADD COLUMN pass VARCHAR(255) NULL;
