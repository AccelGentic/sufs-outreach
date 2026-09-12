-- University Outreach Tool - Database Schema
-- MariaDB / MySQL, InnoDB, utf8mb4

CREATE DATABASE IF NOT EXISTS university_outreach
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE university_outreach;

-- Universities. Visitors pick one -- it's both who they're contacting
-- and what determines the recipient list below.
CREATE TABLE universities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_university_name (name)
) ENGINE=InnoDB;

-- Contacts at each university. Admin-managed only (via import_contacts.php
-- or direct SQL) -- never editable from the public form.
-- Source fields per your data: college/uni, name, role, email, source
--
-- `source` records where a contact came from -- which list, roster or
-- import the row originated in. import_contacts.php requires the column
-- in its CSV and always writes a string (empty when the cell is blank),
-- hence NOT NULL DEFAULT '' rather than a nullable column: one
-- representation of "no source recorded" instead of both '' and NULL.
--
-- uniq_contact_email is what stops the same person being added twice by
-- overlapping imports; import_contacts.php upserts against it. The
-- comparison follows the column's utf8mb4_unicode_ci collation, so it
-- is case-insensitive and ignores trailing spaces -- 'Provost@x.edu'
-- and 'provost@x.edu' are the same contact, which is the intent, since
-- they are the same mailbox. The key is (university_id, email), not
-- email alone: one person can legitimately be a contact at two
-- institutions. 4 + 1020 bytes, well inside InnoDB's 3072-byte limit.
CREATE TABLE university_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    university_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    role VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    source VARCHAR(255) NOT NULL DEFAULT '',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_contact_university FOREIGN KEY (university_id)
        REFERENCES universities(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_contact_email (university_id, email),
    KEY idx_university_active (university_id, active)
) ENGINE=InnoDB;

-- The redirect pool used in staging mode: a small, deliberately flat
-- list of test addresses (your own team, a shared test inbox), NOT
-- tied to any university. Every message that would go to a real
-- contact -- personalized exactly as it would be for them -- gets
-- delivered to every active row here instead, regardless of which
-- institution was selected. Manage with plain INSERT/UPDATE statements;
-- it's meant to stay small.
CREATE TABLE staging_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_active (active)
) ENGINE=InnoDB;

-- Baseline email template(s). Placeholders get replaced server-side:
-- Sender-side (resolved once, when the draft is generated):
--   {{first_name}} {{last_name}} {{sender_email}} {{relationship}} {{university}} {{address}}
-- Recipient-side (resolved individually for each recipient, right before
-- sending, so the SAME edited draft still personalizes per person):
--   {{recipient_name}} {{recipient_role}} {{recipient_email}}
--
-- `type` distinguishes the outreach template (drafted into the editable
-- textarea a visitor sees) from the confirmation template (sent once,
-- automatically, to the visitor's own email after sending -- see
-- submissions.confirmation_sent_at below). The confirmation template
-- only has the sender-side placeholders above, plus:
--   {{recipient_count}} {{sent_count}} {{failed_count}}
--
-- `unlisted_school` is the message offered to a visitor whose
-- institution isn't in the list yet, sent to the leadership address
-- they typed into public/suggest_school.php themselves. Unlike the
-- outreach template it is NOT editable by the visitor -- they see it
-- read-only and choose whether to send it -- so the wording here is the
-- wording that goes out. Its placeholders are:
--   {{school}} {{sender_email}} {{recipient_email}}
CREATE TABLE email_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    type ENUM('outreach','confirmation','unlisted_school') NOT NULL DEFAULT 'outreach',
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Pre-filled list of relationship types a visitor can pick from
-- (Alumni, Parent, Donor, etc.) -- admin-managed, edit via SQL to add
-- or rename entries.
CREATE TABLE relationships (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_relationship_name (name)
) ENGINE=InnoDB;

-- One row per visitor who fills out the form.
-- `university_id` is the single university selection -- it's both who
-- the visitor is relationship to AND who's being contacted, and it's
-- what drives the recipient list. `relationship_id` is how the visitor
-- relates to that university (Alumni, Parent, Donor, etc.). `mode`
-- records whether config.php's APP_MODE was 'staging' or 'production'
-- at the moment this was created, so old test submissions stay
-- distinguishable from real ones after a mode switch.
-- `confirmation_sent_at`/`confirmation_error` track the one-time
-- confirmation email sent to the visitor's own address -- separate
-- from the main outreach send, so a confirmation failure never affects
-- `status` or what the visitor sees on the confirmation page.
CREATE TABLE submissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    relationship_id INT UNSIGNED NOT NULL,
    university_id INT UNSIGNED NOT NULL,
    address VARCHAR(500) DEFAULT NULL,
    subject VARCHAR(255) DEFAULT NULL,
    email_body TEXT DEFAULT NULL,
    status ENUM('draft','sent','failed') NOT NULL DEFAULT 'draft',
    mode ENUM('staging','production') NOT NULL DEFAULT 'production',
    confirmation_sent_at TIMESTAMP NULL DEFAULT NULL,
    confirmation_error VARCHAR(500) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_submission_university FOREIGN KEY (university_id)
        REFERENCES universities(id),
    CONSTRAINT fk_submission_relationship FOREIGN KEY (relationship_id)
        REFERENCES relationships(id),
    KEY idx_status (status),
    KEY idx_email_created (email, created_at)
) ENGINE=InnoDB;

-- Failed admin login attempts, per IP, for the browser-based template
-- editor under public/admin/. Only used for brute-force throttling
-- (config.php: ADMIN_MAX_LOGIN_ATTEMPTS / ADMIN_LOCKOUT_MINUTES) --
-- rows older than 7 days are pruned opportunistically on login.
CREATE TABLE admin_login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;

-- Per-recipient send log -- audit trail for every message actually sent.
CREATE TABLE email_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id INT UNSIGNED NOT NULL,
    contact_id INT UNSIGNED NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    status ENUM('sent','failed') NOT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_submission FOREIGN KEY (submission_id)
        REFERENCES submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_log_contact FOREIGN KEY (contact_id)
        REFERENCES university_contacts(id)
) ENGINE=InnoDB;

-- Starter template -- edit this to your organization's real baseline script.
INSERT INTO email_templates (name, type, subject, body, active) VALUES (
  'Default Outreach Template',
  'outreach',
  'A message regarding {{university}}',
  'Dear {{recipient_name}},\n\nMy name is {{first_name}} {{last_name}}, a {{relationship}} of {{university}}. I am writing to you in your role as {{recipient_role}} regarding an important matter.\n\n[Write your message here.]\n\nThank you for your time and consideration.\n\nSincerely,\n{{first_name}} {{last_name}}\n{{sender_email}}',
  1
);

-- Placeholder confirmation email, sent once to the visitor's own address
-- after sending. Replace subject/body with your organization's real copy
-- -- see the placeholders documented above the email_templates table.
INSERT INTO email_templates (name, type, subject, body, active) VALUES (
  'Default Confirmation Template',
  'confirmation',
  'Your message to {{university}} has been sent',
  '[PLACEHOLDER -- replace this with your organization''s confirmation email text.]\n\nDear {{first_name}},\n\n[Write your confirmation message here.]\n\nAvailable placeholders: {{first_name}}, {{last_name}}, {{sender_email}}, {{relationship}}, {{university}}, {{address}}, {{recipient_count}}, {{sent_count}}, {{failed_count}}.',
  1
);

-- Placeholder for the message a visitor can send to the leadership of a
-- school that isn't in the list yet. Replace subject/body with your real
-- copy -- the visitor cannot edit it, so this text is exactly what goes
-- out over their name. Editable at /admin/ like the other two.
INSERT INTO email_templates (name, type, subject, body, active) VALUES (
  'Default Unlisted School Template',
  'unlisted_school',
  'A message regarding {{school}}',
  '[PLACEHOLDER -- replace this with your organization''s real message.]\n\nDear {{recipient_email}},\n\n[Write the message that a supporter of {{school}} will send to its leadership here.]\n\nAvailable placeholders: {{school}}, {{sender_email}}, {{recipient_email}}.',
  1
);

-- Starter relationship types -- edit/add rows as needed for your audience.
INSERT INTO relationships (name, sort_order) VALUES
  ('Alumni', 10),
  ('Parent/Guardian', 20),
  ('Current Student', 30),
  ('Faculty/Staff', 40),
  ('Donor', 50),
  ('Community Member', 60),
  ('Other', 70);
