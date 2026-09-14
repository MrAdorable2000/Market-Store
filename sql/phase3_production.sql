-- Isoko Ryacu Phase 3 production support objects.
-- Run once on the production database. Safe to re-run.
CREATE TABLE IF NOT EXISTS api_rate_limits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rate_key VARCHAR(190) NOT NULL,
  window_started_at DATETIME NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rate_key_window (rate_key, window_started_at),
  KEY idx_rate_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
