-- Add fields to ext_clients table to cache Name, Start_Date, and Sub duration
ALTER TABLE ext_clients
ADD COLUMN name VARCHAR(255) NULL,
ADD COLUMN start_date DATE NULL,
ADD COLUMN sub INT NULL;
