-- Email queue migration for Crofund admin
-- Run this once in the target database before enabling the queue worker.

CREATE TABLE IF NOT EXISTS email_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_type VARCHAR(100) NOT NULL,
    dedupe_key VARCHAR(191) DEFAULT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    recipient_name VARCHAR(255) DEFAULT NULL,
    subject VARCHAR(500) NOT NULL,
    template_name VARCHAR(150) NOT NULL,
    template_data LONGTEXT NOT NULL,
    priority INT NOT NULL DEFAULT 50,
    status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
    provider VARCHAR(100) DEFAULT NULL,
    locked_by VARCHAR(100) DEFAULT NULL,
    locked_at DATETIME DEFAULT NULL,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 5,
    last_attempt_at DATETIME DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    error_message TEXT DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_queue_dedupe (dedupe_key),
    KEY idx_email_queue_pick (status, available_at, priority, id),
    KEY idx_email_queue_recipient (recipient_email),
    KEY idx_email_queue_locked (locked_at),
    KEY idx_email_queue_message_type (message_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_queue_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email_queue_id BIGINT UNSIGNED NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    success TINYINT(1) NOT NULL DEFAULT 0,
    provider_response TEXT DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    worker_id VARCHAR(100) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_attempts_queue (email_queue_id, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
