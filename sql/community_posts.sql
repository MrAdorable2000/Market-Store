-- Admin-managed community posts for Home page: events, announcements and notices.
CREATE TABLE IF NOT EXISTS community_posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('announcement','event','notice') NOT NULL DEFAULT 'announcement',
    title VARCHAR(180) NOT NULL,
    body TEXT NULL,
    event_date DATETIME NULL,
    location VARCHAR(180) NULL,
    cta_label VARCHAR(60) NULL,
    cta_url VARCHAR(500) NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_community_posts_home (is_published, display_order, event_date, created_at),
    CONSTRAINT fk_community_posts_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
