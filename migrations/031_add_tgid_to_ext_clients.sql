-- Add tgid field to ext_clients table to store Telegram ID
ALTER TABLE ext_clients
ADD COLUMN tgid VARCHAR(50) NULL;
