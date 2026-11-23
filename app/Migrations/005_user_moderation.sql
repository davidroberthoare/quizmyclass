-- Super-admin moderation: a blocked user cannot sign in or keep a session.
ALTER TABLE users ADD COLUMN blocked_at TEXT;
