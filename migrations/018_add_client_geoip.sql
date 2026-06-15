-- Add GeoIP columns to vpn_clients table
ALTER TABLE vpn_clients ADD COLUMN last_endpoint_ip VARCHAR(50) NULL;
ALTER TABLE vpn_clients ADD COLUMN country VARCHAR(100) NULL;
ALTER TABLE vpn_clients ADD COLUMN city VARCHAR(100) NULL;
ALTER TABLE vpn_clients ADD COLUMN isp VARCHAR(255) NULL;

-- Insert English translations
INSERT INTO translations (language_code, translation_key, translation_value) VALUES
('en', 'clients.geoip_title', 'GeoIP Information'),
('en', 'clients.last_endpoint', 'Last Endpoint IP'),
('en', 'clients.country', 'Country'),
('en', 'clients.city', 'City'),
('en', 'clients.isp', 'ISP')
ON DUPLICATE KEY UPDATE translation_value=VALUES(translation_value);

-- Insert Russian translations
INSERT INTO translations (language_code, translation_key, translation_value) VALUES
('ru', 'clients.geoip_title', 'Информация о GeoIP'),
('ru', 'clients.last_endpoint', 'Последний IP-адрес подключения'),
('ru', 'clients.country', 'Страна'),
('ru', 'clients.city', 'Город'),
('ru', 'clients.isp', 'Провайдер')
ON DUPLICATE KEY UPDATE translation_value=VALUES(translation_value);
