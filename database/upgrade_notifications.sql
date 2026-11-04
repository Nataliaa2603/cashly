-- Ejecutar una sola vez en phpMyAdmin sobre la base de datos cashly.
CREATE TABLE IF NOT EXISTS notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 notification_key VARCHAR(190) NOT NULL,
 title VARCHAR(180) NOT NULL,
 message VARCHAR(500) NOT NULL,
 notification_type VARCHAR(30) NOT NULL DEFAULT 'info',
 related_url VARCHAR(255) NOT NULL DEFAULT '?page=inicio',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 read_at DATETIME NULL,
 UNIQUE KEY uq_notification_user_key(user_id,notification_key),
 INDEX idx_notification_user_read(user_id,read_at),
 CONSTRAINT fk_notification_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
