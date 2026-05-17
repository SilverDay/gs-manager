-- Migration: add expires_at column to ai_cache table
-- Enables TTL-based expiry of cached AI responses.
ALTER TABLE ai_cache
    ADD COLUMN expires_at DATETIME NOT NULL DEFAULT (NOW() + INTERVAL 30 DAY)
        COMMENT 'Cache entry expiry timestamp; responses beyond this date are ignored',
    ADD INDEX idx_ai_cache_expires (expires_at);
