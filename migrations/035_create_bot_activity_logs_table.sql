CREATE TABLE IF NOT EXISTS bot_activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tg_id BIGINT NOT NULL,
    tg_name VARCHAR(255) NOT NULL,
    client_code VARCHAR(255) NULL,
    action VARCHAR(50) NOT NULL,
    details TEXT NULL,
    raw_data TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_tg_id (tg_id),
    INDEX idx_client_code (client_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
