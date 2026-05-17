-- Migration: add totp_last_used_step to users
-- Stores the most recently accepted TOTP time-step to prevent replay attacks.
-- NULL means no TOTP authentication has been performed yet.
ALTER TABLE users
    ADD COLUMN totp_last_used_step INT DEFAULT NULL
        COMMENT 'Most recently accepted TOTP counter step; NULL = never used';
