-- Phase 1: password reset codes get a wrong-try counter.
ALTER TABLE password_resets ADD COLUMN IF NOT EXISTS attempts smallint NOT NULL DEFAULT 0;
