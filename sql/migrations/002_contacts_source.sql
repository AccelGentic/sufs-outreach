-- Adds university_contacts.source, which import_contacts.php has been
-- writing since it started requiring a `source` column in its CSV.
-- Fresh installs get it from sql/schema.sql and don't need this.
--
--   mysql university_outreach < sql/migrations/002_contacts_source.sql
--
-- IF NOT EXISTS is MariaDB syntax (10.0+), which is what this app
-- targets, and makes the migration safe to re-run -- and safe on a
-- database where the column was already added by hand, which is how it
-- came to exist ahead of the schema file. On stock MySQL, which has no
-- IF NOT EXISTS for ADD COLUMN, drop those two words and skip the
-- statement if the column is already there.
--
-- NOT NULL DEFAULT '' matches what the importer actually writes (it
-- always binds a string, empty when the CSV cell is blank). Existing
-- rows, which predate the column, get '' -- meaning "no source
-- recorded", same as a blank cell.

ALTER TABLE university_contacts
    ADD COLUMN IF NOT EXISTS source VARCHAR(255) NOT NULL DEFAULT '' AFTER email;
