-- Migration: Remove QR Code column and translations
-- Description: Completely removes QR code logic from the database schema

ALTER TABLE vpn_clients DROP COLUMN qr_code;

DELETE FROM translations WHERE `key` = 'clients.qr_code';
