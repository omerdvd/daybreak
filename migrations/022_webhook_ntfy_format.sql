-- Add 'ntfy' to the webhook payload format enum, and a column for an
-- optional encrypted per-webhook secret (ntfy's Bearer token for protected
-- topics). Unlike Slack/Discord/Teams, whose secrets live in the webhook
-- URL itself, the ntfy token is stored encrypted via CredentialVault
-- rather than in plaintext — this instance is otherwise unexposed, but
-- the token gates a personal push channel and deserves better handling.
SET NAMES utf8mb4;

ALTER TABLE user_webhooks
  MODIFY COLUMN format ENUM('slack','discord','teams','ntfy','generic') NOT NULL DEFAULT 'generic',
  ADD COLUMN secret_enc VARCHAR(500) NULL AFTER filter_json;
