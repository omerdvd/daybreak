-- Singleton table backing an admin-toggleable site-wide notice banner
-- (e.g. "Gmail verification emails are currently delayed").
SET NAMES utf8mb4;

CREATE TABLE site_notification (
  id          TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  message     TEXT NOT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 0,
  updated_by  BIGINT UNSIGNED NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_site_notification_singleton CHECK (id = 1),
  CONSTRAINT fk_site_notification_updated_by FOREIGN KEY (updated_by)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO site_notification (id, message, is_active) VALUES (1, '', 0);
