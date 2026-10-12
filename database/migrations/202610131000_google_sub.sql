-- Phase 5: Google sign in. Students link their Google account by its stable `sub`.
ALTER TABLE users ADD COLUMN IF NOT EXISTS google_sub varchar(64) DEFAULT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_google_sub ON users (google_sub);
