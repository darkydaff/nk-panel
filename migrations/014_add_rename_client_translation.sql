-- Migration 014: Add rename client translation
INSERT IGNORE INTO translations (locale, category, key_name, translation) VALUES 
('en', 'clients', 'rename_client', 'Rename Client'),
('ru', 'clients', 'rename_client', 'Переименовать клиента'),
('es', 'clients', 'rename_client', 'Renombrar cliente'),
('de', 'clients', 'rename_client', 'Client umbenennen'),
('fr', 'clients', 'rename_client', 'Renommer le client'),
('zh', 'clients', 'rename_client', '重命名客户端')
ON DUPLICATE KEY UPDATE translation = VALUES(translation);
