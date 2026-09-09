# University Outreach Tool

Apache + PHP + MariaDB app for Ubuntu 24.04 LTS. A visitor fills out a short
form, picks a university, picks how they relate to it (Alumni, Parent,
Donor, etc.), and gets a pre-filled (but editable) email drafted from a
baseline template. It's sent individually to that university's on-file
contacts -- a read-only list they can't add to or change -- personalizing
each copy with that recipient's own name and role.

## How it works

1. **`public/index.php`** -- visitor enters first/last name, email, picks
   **one** university (this single selection is both who they're
   affiliated with/related to *and* who's being contacted -- it drives
   the recipient list), picks their **relationship** to that university
   from a preset dropdown (Alumni, Parent/Guardian, Current Student,
   Faculty/Staff, Donor, Community Member, Other -- edit the
   `relationships` table to add/rename entries), and an optional address.
2. **`public/generate.php`** -- validates input, saves a `draft`
   submission row, and merges the visitor's info into the active
   baseline template. Only the *sender*-side placeholders are resolved
   here (see "Placeholders" below) -- recipient-side placeholders are
   deliberately left as literal text for now, since one draft has to
   serve every recipient.
3. **`public/review.php`** -- shows the generated subject/body in an
   editable box, and the university's contacts as a plain read-only list
   pulled fresh from the database (never from anything the browser sent).
4. **`public/send.php`** -- re-checks everything server-side, re-reads the
   recipient list from the database by the submission's stored
   `university_id`, resolves each recipient's own `{{recipient_name}}` /
   `{{recipient_role}}` into their personal copy of the (possibly edited)
   draft, sends one email per recipient via whichever mail driver is
   configured, CCs the submitter, logs every attempt, and marks the
   submission `sent`.
5. **`public/confirm.php`** -- thank-you page.

## Placeholders

Sender-side (resolved once, when the draft is generated -- what the
visitor sees on the review page is already the real text):
`{{first_name}}`, `{{last_name}}`, `{{sender_email}}`, `{{relationship}}`,
`{{university}}`, `{{address}}`.

Recipient-side (left as literal `{{...}}` text through review, resolved
individually for *each* recipient right before sending -- so the one
edited draft still personalizes per person): `{{recipient_name}}`,
`{{recipient_role}}`, `{{recipient_email}}`. If a visitor deletes these
from the editable box, everyone just gets that generic text instead --
nothing breaks either way.

Edit the baseline template in the browser at `/admin/` -- see "Editing
the email text" below. (The equivalent by hand is
`UPDATE email_templates SET subject = '...', body = '...' WHERE type =
'outreach';`, still available if you'd rather work in SQL.)

## Confirmation email

After sending, the visitor also gets a separate, one-time confirmation
email at their own address -- not the same thing as the CC copy they get
on every individual recipient message above; this is a single summary
notification. Its content comes from the `email_templates` row where
`type = 'confirmation'`, which ships as an obvious placeholder you're
expected to replace -- edit it at `/admin/` alongside the outreach
template.

It has its own placeholders -- all the sender-side ones above
(`{{first_name}}`, `{{university}}`, etc.), plus `{{recipient_count}}`
(how many real contacts the message went to), `{{sent_count}}`, and
`{{failed_count}}`. There's no `{{recipient_name}}`/`{{recipient_role}}`
here -- this isn't personalized per contact, it's one message to the
visitor about their own submission.

If no active `confirmation`-type template exists, this step is silently
skipped -- the main send isn't affected either way. Delivery is
best-effort and tracked independently: a failed confirmation is recorded
in `submissions.confirmation_error` (and `confirmation_sent_at` stays
null) rather than affecting `status` or the success message on
`confirm.php`. In staging mode, the confirmation is redirected to the
`staging_contacts` pool exactly like recipient messages are -- it never
reaches whatever address the visitor actually typed into the form while
testing.

## Editing the email text (admin)

Both templates are editable from a browser at **`/admin/`** on the same
vhost (e.g. `https://outreach.yourdomain.org/admin/`) -- no SQL, no
shell. Nothing on the visitor-facing pages links to it; you go there
directly.

**One-time setup.** On the server:

```
php scripts/make_admin_hash.php
```

It prompts for a password (twice, hidden) and prints a
`define('ADMIN_PASSWORD_HASH', '...');` line -- paste that into
`config.php`, replacing the `CHANGE_ME` placeholder. There are no admin
accounts in the database; it's one shared password, hashed with
`password_hash()`. Until a real hash is in place the admin area refuses
every login rather than falling open, so a half-finished install is
never an editable one.

If you're upgrading an existing install (one whose database predates
this feature), also load the small table the login throttle uses:

```
mysql university_outreach < sql/migrations/001_admin_login_attempts.sql
```

**What you can do there.**

- **Edit** either template's name, subject, and body. Saving the live
  template takes effect on the very next message generated -- drafts
  already in progress and anything already sent are untouched.
- **Preview** before saving: sample values are merged into the
  placeholders, and the result is run through the exact sanitizer
  `send.php` uses -- so what you see is what actually goes out,
  including the plain-text alternative that non-HTML mail clients show.
- **Insert placeholders** by clicking them in the side panel, with a
  note on what each one resolves to for that template type.
- **Create a new version** without disturbing the live one: new
  templates are saved inactive, and *Make live* switches them on and
  switches the previous one off in the same step. The old one stays
  in the list as a record of what was being sent before.
- **Turn the confirmation email off** entirely (`send.php` skips that
  step when no confirmation template is active). The outreach template
  can't be switched off -- visitors would get an empty message body --
  so activating a replacement is how you retire one.

**Warnings you'll see.** If the subject or body contains something that
looks like a placeholder but isn't one -- a typo like `{{firstname}}`,
or `{{ first_name }}` with spaces inside the braces -- the editor flags
it. Neither is an error anywhere in the send path: the merge is a
literal string swap, so an unrecognized token is simply mailed out
as-is to every recipient. It's much cheaper to catch at edit time.

**Access control.** A single password, an idle timeout
(`ADMIN_SESSION_TIMEOUT`), and a per-IP lockout after repeated failures
(`ADMIN_MAX_LOGIN_ATTEMPTS` within `ADMIN_LOCKOUT_MINUTES`, tracked in
`admin_login_attempts` rather than in the session, so clearing cookies
doesn't reset it). Admin pages send `noindex`/`X-Frame-Options` headers,
the session cookie is `HttpOnly`/`SameSite=Lax` (and `Secure` once
you're on HTTPS), and every state-changing action is CSRF-protected. If
you want a second lock on the door, the example vhost has a commented
block for restricting `/admin/` by IP.

Serve this over HTTPS. The admin password crosses the wire on every
sign-in.

## Staging vs. production

`config.php`'s `APP_MODE` (`'staging'` or `'production'`) controls where
mail actually gets *delivered*. Everything else stays identical between
the two modes: the real recipient list for whichever institution is
selected is what's shown on the review page and what personalization
(`{{recipient_name}}`, `{{recipient_role}}`) is computed against -- so
staging looks and behaves exactly like production would. The only
difference is that in staging, each of those real-contact-personalized
messages gets redirected to every active row in `staging_contacts`
instead of the real contact, regardless of which institution was
picked. The message content is identical to what the real contact would
have received; it just never reaches them.

Manage `staging_contacts` with plain SQL -- it's meant to stay small
(your own team, a shared test inbox):
```sql
INSERT INTO staging_contacts (name, email) VALUES ('QA Tester', 'you@yourdomain.org');
```
Since every real contact's message fans out to every staging address,
sending to an institution with 5 real contacts while 3 staging
addresses are configured means 15 actual deliveries -- keep the staging
list small, as intended, or that multiplies fast. If `staging_contacts`
has no active rows, sending is blocked with a clear error rather than
silently doing nothing.

The staging banner shown on every visitor-facing page makes this
unmistakable while it's active. `submissions.mode` also records which
mode each submission was created under, so old test runs stay
distinguishable from real ones later. The example `config.php.example`
defaults to `'staging'` deliberately -- flipping to `'production'`
should be a conscious step, not something inherited by accident from a
copied file.

## Why recipients can't be edited or spoofed

- The contact list on the review page is queried live from
  `university_contacts` by `university_id` and rendered as plain text, not
  form fields -- there's nothing for the browser to submit back. (Staging
  mode still shows this real list -- see "Staging vs. production" above
  for what actually changes.)
- `send.php` re-derives the recipient list from the database using the
  submission's own stored `university_id`. It never trusts a recipient
  list from the request.
- `submission_id` is cross-checked against the value stored in the
  visitor's own PHP session, so one visitor can't trigger a send for
  someone else's draft.
- The subject line is stripped of line breaks before sending, to prevent
  email header injection. All DB queries use parameterized statements.

## From and Reply-To are fixed, not the visitor's

`MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` and `MAIL_REPLY_TO_ADDRESS`/
`MAIL_REPLY_TO_NAME` in `config.php` are used for every outgoing message --
never the visitor's own email. Replies land in an org-monitored inbox
instead of scattering across individual senders. The visitor still gets
their own copy via CC (`MAIL_CC_SENDER`), so they have a record of what
went out under their name.

## Sending mail: Mailgun, SMTP, or Switchboard

`config.php`'s `MAIL_DRIVER` picks which one `send.php` uses:

- **`'mailgun'`** (default) -- uses Mailgun's HTTP API via `curl`. No
  extra dependency needed; `php-curl` is installed by the setup script.
  Fill in `MAILGUN_API_KEY`, `MAILGUN_DOMAIN` (from the Mailgun
  dashboard: **Sending > Domain settings** for the domain, **Settings >
  API Keys** for the key). Mailgun walks you through the SPF/DKIM DNS
  records for your sending domain when you add it -- get those in place
  before sending real volume, or messages will land in spam.
- **`'smtp'`** -- uses your existing authenticated SMTP relay via
  PHPMailer. Fill in `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`,
  `SMTP_SECURE`. Needs PHPMailer installed first:
  ```
  cd /var/www/university-outreach
  composer require phpmailer/phpmailer
  ```
  (the install script offers to do this for you). `send.php` only loads
  `vendor/autoload.php` when `MAIL_DRIVER = 'smtp'`, so a Mailgun-only
  deployment never needs Composer or `vendor/` at all.
- **`'switchboard'`** -- **not yet functional.** Switchboard's public API
  docs (`https://api.oneswitchboard.com/v1/docs`) only expose read/export
  endpoints for `email_blasts` (`GET /v1/email_blasts`, `GET .../{id}`,
  `GET .../{id}/email_messages`, and two export endpoints) -- there's no
  documented way to create or send a blast via the API, unlike their SMS
  `broadcasts` resource, which explicitly has both. `includes/switchboard.php`
  is stubbed in with the right function signature so wiring it up later
  is a one-file change, but right now selecting this driver logs every
  recipient as a clean "failed" rather than actually sending. Before using
  it: confirm with Switchboard whether a transactional-send endpoint
  exists that isn't in the public docs (their beta feature list doesn't
  show one either), or stick with Mailgun/SMTP.

`MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MAIL_REPLY_TO_ADDRESS`,
`MAIL_REPLY_TO_NAME`, and `MAIL_CC_SENDER` apply the same regardless of
driver.

## Setup on Ubuntu 24.04

1. Copy this whole folder to the server, e.g. `/var/www/university-outreach`.
2. Run `scripts/install_ubuntu24.sh`. It installs Apache, MariaDB, and PHP,
   creates the app's DB user, loads `sql/schema.sql`, and sets file
   permissions. It also offers to install PHPMailer via Composer if
   you'll be using the SMTP driver. Read the script first -- it pauses
   for the MariaDB secure-installation wizard and for you to set the app
   DB password.
3. Point an Apache vhost's `DocumentRoot` at `.../public` -- see
   `scripts/apache-vhost-example.conf`. Everything else (`config.php`,
   `includes/`, `vendor/`, `sql/`, `scripts/`) stays outside the document
   root and is not web-reachable.
4. Copy `config.php.example` to `config.php` (in the app root, *not* in
   `public/`) and fill in your DB password, `MAIL_DRIVER`, and the
   matching mail settings below it -- see "Sending mail" above.
5. Get a TLS certificate before this goes live -- the form collects
   personal data, and the admin password crosses the wire on every
   sign-in. Either `sudo certbot --apache` (certbot rewrites the vhost
   for you), or issue the certificate without touching your config and
   use the ready-made HTTPS vhost:
   ```
   sudo a2enmod ssl headers rewrite
   sudo certbot certonly --webroot -w .../public -d outreach.yourdomain.org
   sudo cp scripts/apache-vhost-ssl-example.conf \
     /etc/apache2/sites-available/university-outreach.conf
   # edit ServerName / certificate paths, then:
   sudo apache2ctl configtest && sudo systemctl reload apache2
   sudo certbot renew --dry-run
   ```
6. Import your contact list:
   ```
   php scripts/import_contacts.php /path/to/contacts.csv
   ```
   Expects a header row with columns `college/uni,name,role,email` (column
   names are normalized, so `College/Uni`, `college_uni`, etc. all match).
7. Set an admin password so you can edit the email text from a browser:
   ```
   php scripts/make_admin_hash.php
   ```
   and paste the line it prints into `config.php`. The templates are
   then editable at `https://your-host/admin/` -- see "Editing the
   email text" above.
8. Edit the relationship options to fit your audience -- the
   `relationships` table (via SQL) controls the dropdown; add, rename,
   or reorder entries (`sort_order` controls display order).

## Abuse & deliverability notes

- Basic per-email and per-IP hourly throttling is built in
  (`config.php`: `MAX_SUBMISSIONS_PER_HOUR_PER_EMAIL` /
  `MAX_SUBMISSIONS_PER_HOUR_PER_IP`). If this form will be public-facing,
  consider adding a CAPTCHA (hCaptcha/reCAPTCHA) to `public/index.php`.
- Every send attempt (success or failure) is logged to `email_log` for an
  audit trail -- `error_message` has the raw response from whichever mail
  driver is in use.
- To stop emailing a specific contact, set `active = 0` on their
  `university_contacts` row rather than deleting it -- keeps history intact
  and they stop receiving mail immediately.
- Even with opted-in recipients, it's good practice for the generated
  message to clearly identify your organization and give recipients a way
  to ask to stop receiving this kind of message.

## Files

- `sql/schema.sql` -- fresh-install schema (universities, contacts,
  staging_contacts, templates, relationships, submissions, send log) +
  starter template and relationship rows.
- `config.php.example` -- copy to `config.php` and fill in.
- `includes/` -- DB connection, CSRF helpers, shared functions, admin
  authentication (`admin_auth.php`), template read/write for the admin
  editor (`template_store.php`), and the mail drivers (`mailgun.php`,
  `smtp_mailer.php`, `switchboard.php` -- the last one is a stub, see
  "Sending mail" above) -- not web-reachable.
- `composer.json` -- PHPMailer dependency (only needed for the SMTP driver).
- `public/` -- the actual web app; this is the only folder Apache serves.
- `public/admin/` -- the password-protected email template editor (see
  "Editing the email text" above).
- `sql/migrations/` -- schema changes for installs that predate a
  feature; fresh installs get everything from `sql/schema.sql`.
- `scripts/install_ubuntu24.sh` -- provisioning script.
- `scripts/import_contacts.php` -- CLI CSV importer.
- `scripts/make_admin_hash.php` -- CLI helper that generates the
  `ADMIN_PASSWORD_HASH` line for `config.php`.
- `scripts/apache-vhost-example.conf` -- example vhost, plain HTTP, for
  bringing a host up before certificates exist.
- `scripts/apache-vhost-ssl-example.conf` -- the HTTPS vhost to run in
  production: port 80 redirect (with the ACME challenge path left
  reachable so renewals keep working), TLS, HSTS, and security headers.
