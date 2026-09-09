-- Stops the same contact being added twice by overlapping imports, by
-- making (university_id, email) unique. import_contacts.php upserts
-- against this key, so a re-import refreshes a contact instead of
-- duplicating them.
--
-- RUN scripts/dedupe_contacts.php --apply FIRST.
--
-- Adding a unique key to a table that still contains duplicates fails
-- with "Duplicate entry ... for key 'uniq_contact_email'", and names
-- only the first collision it hits -- so on a table with several you
-- would be fixing them one error at a time. The dedupe script merges
-- them all in one pass, and repoints email_log rows so nothing is lost:
--
--   php scripts/dedupe_contacts.php            # see what it would do
--   php scripts/dedupe_contacts.php --apply    # do it
--   mysql university_outreach < sql/migrations/003_contacts_unique_email.sql
--
-- The comparison uses the column's collation (utf8mb4_unicode_ci), so
-- it is case-insensitive and ignores trailing spaces -- the same
-- addresses the dedupe script treats as equal. Leading whitespace is
-- NOT ignored by the collation, but import_contacts.php trims every
-- value as it reads the CSV, so it never reaches the column.
--
-- IF NOT EXISTS is MariaDB syntax, making this safe to re-run.

ALTER TABLE university_contacts
    ADD UNIQUE KEY IF NOT EXISTS uniq_contact_email (university_id, email);
