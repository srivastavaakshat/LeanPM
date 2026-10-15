-- Sign in with Google: the Google account id linked to a LeanPM user (set on first Google sign-in).
ALTER TABLE users ADD COLUMN google_sub VARCHAR(64) NULL;
CREATE UNIQUE INDEX users_google_sub ON users (google_sub);
