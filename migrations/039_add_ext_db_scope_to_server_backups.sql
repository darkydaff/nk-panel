-- Modify server_backups table to allow ext_db scope for external PostgreSQL backups
ALTER TABLE server_backups 
  MODIFY COLUMN backup_scope ENUM('panel', 'server', 'ext_db') DEFAULT 'server';
