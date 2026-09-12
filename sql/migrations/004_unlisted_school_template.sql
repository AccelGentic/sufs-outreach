-- Adds the 'unlisted_school' template type: the message a visitor can
-- send to the leadership of a school that isn't in the list yet, at the
-- address they typed into public/suggest_school.php themselves.
-- Fresh installs get this from sql/schema.sql and don't need it.
--
--   mysql university_outreach < sql/migrations/004_unlisted_school_template.sql
--
-- Widening an ENUM rewrites the column definition, so every existing
-- value has to be listed again -- dropping one would invalidate the rows
-- using it. The two originals are kept verbatim, and the new value is
-- added at the end so existing rows keep their stored ordinal.

ALTER TABLE email_templates
    MODIFY COLUMN type ENUM('outreach','confirmation','unlisted_school')
    NOT NULL DEFAULT 'outreach';

-- A placeholder row so the feature has something to render before you
-- write the real copy. The visitor cannot edit this text, so what is
-- here is exactly what goes out -- replace it at /admin/ before going
-- live. Skipped if a row of this type already exists.
INSERT INTO email_templates (name, type, subject, body, active)
SELECT
    'Default Unlisted School Template',
    'unlisted_school',
    'A message regarding {{school}}',
    '[PLACEHOLDER -- replace this with your organization''s real message.]\n\nDear {{recipient_email}},\n\n[Write the message that a supporter of {{school}} will send to its leadership here.]\n\nAvailable placeholders: {{school}}, {{sender_email}}, {{recipient_email}}.',
    1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM email_templates WHERE type = 'unlisted_school'
);
