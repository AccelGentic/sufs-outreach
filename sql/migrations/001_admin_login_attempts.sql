-- Migration for installs created before the browser-based template
-- editor (public/admin/) existed. Fresh installs get this table from
-- sql/schema.sql already and don't need to run this.
--
--   mysql university_outreach < sql/migrations/001_admin_login_attempts.sql

CREATE TABLE IF NOT EXISTS admin_login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;
