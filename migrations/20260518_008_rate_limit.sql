-- Migration: rate_limit_attempts table
-- Used by RateLimitMiddleware to implement a sliding-window per action+IP bucket.
CREATE TABLE IF NOT EXISTS rate_limit_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bucket       VARCHAR(128)    NOT NULL COMMENT 'sha256(action:ip)',
    window_start DATETIME        NOT NULL COMMENT 'Start of the current counting window',
    hits         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_bucket_window (bucket, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
