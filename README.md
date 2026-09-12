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
   # Enable the modules FIRST: Apache treats a directive from a module
   # it hasn't loaded as a fatal error and refuses to start, so a
   # missing mod_headers means "Invalid command 'Header'" and a site
   # that won't come up.
   sudo a2enmod ssl headers rewrite
   apache2ctl -M | grep -E 'ssl|headers|rewrite'   # confirm all three
   sudo certbot certonly --webroot -w .../public -d outreach.yourdomain.org
   sudo cp scripts/apache-vhost-ssl-example.conf \
     /etc/apache2/sites-available/university-outreach.conf
   # edit ServerName / certificate paths, then:
   sudo apache2ctl configtest && sudo systemctl reload apache2
   sudo certbot renew --dry-run
   # Confirm the security headers actually arrive (the vhost wraps them
   # in <IfModule>, so a forgotten a2enmod fails quietly rather than
   # loudly):
   curl -sI https://outreach.yourdomain.org | grep -iE 'strict-transport|x-frame|nosniff'
   ```
6. Import your contact list:
   ```
   php scripts/import_contacts.php /path/to/contacts.csv
   ```
   Expects a header row with columns `college/uni,name,role,email,source`
   (column names are normalized, so `College/Uni`, `college_uni`, etc.
   all match). `source` records which list or roster a contact came
   from. Safe to re-run -- contacts are matched on institution plus
   email and updated in place rather than duplicated. See "Duplicate
   contacts" above if you are upgrading a database that predates that.
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

## When a visitor's school isn't listed

`public/suggest_school.php` lets someone whose institution is missing
tell you about it, and pass on any leadership contacts they know. Each
submission is emailed to `SCHOOL_REQUEST_TO_ADDRESS` through whichever
`MAIL_DRIVER` is configured -- the same path the rest of the app's mail
takes.

Set the address in `config.php`:

```php
define('SCHOOL_REQUEST_TO_ADDRESS', 'you@yourdomain.org');
define('SCHOOL_REQUEST_TO_NAME', 'Outreach Team');
```

Left as `CHANGE_ME`, the whole feature switches itself off and the links
offering it disappear, rather than inviting visitors to fill in a form
that would go nowhere.

Visitors reach it two ways, both on `index.php`:

- **From the typeahead, when nothing matches.** That's the moment
  someone learns their school isn't listed, so the offer appears right
  there under "No matching educational institutions" rather than in a
  banner everyone reads before they have the problem.
- **From a quiet line under the field**, always visible. This catches
  the visitor who mistypes, gets three wrong results instead of an empty
  list, and would never trigger the empty state at all.

Whatever they typed carries over as a prefill, so they don't retype the
name they just failed to find.

Three things behave differently here to the rest of the app, each on
purpose:

- **Nothing is stored.** The notification email is the only record, so a
  failed send is reported to the visitor instead of being swallowed, and
  the full submission is written to the PHP error log -- recoverable
  from the server if the mail never arrives.
- **Reply-To is the visitor's address**, not the fixed one from config,
  so you can just hit reply and ask which campus they meant. That's safe
  here because this message goes to your own inbox rather than out to
  university contacts.
- **Staging does not redirect it.** The `staging_contacts` safeguard
  exists to keep test mail away from real people; here the recipient is
  you, and you want to see the test. The subject is prefixed
  `[STAGING]` instead.

Spam defences are a honeypot field, a three-second minimum fill time,
CSRF, and length caps -- enough for casual bots. With no database table
there's no per-IP counter behind it, so if this ever gets hammered the
answer is a CAPTCHA (Turnstile, if you're already behind Cloudflare).

## Duplicate contacts

`import_contacts.php` inserts every CSV row unconditionally, so
re-running it -- or importing two lists that overlap -- adds the same
person again, and they then receive one copy of each message per row.

```
php scripts/dedupe_contacts.php            # report only, changes nothing
php scripts/dedupe_contacts.php --apply    # merge each group down to one row
```

Two rows count as duplicates when they share a `university_id` **and**
an email address, compared case-insensitively and ignoring surrounding
whitespace. That matches the actual harm -- one mailbox at one
institution getting the message twice. Names and roles are not part of
the comparison, because "Dr. Alex Chen" and "Alex Chen" are the same
person and no rule can tell that reliably; the script reports differing
names, roles and sources within a group instead of guessing. The same
address at two different institutions is left alone -- that's one person
holding two posts.

The earliest row (lowest id) survives. Two behaviours worth knowing
before you run it with `--apply`:

- **An opt-out wins.** If any row in a group has `active = 0`, the
  surviving row is set to `0` too. Since `active = 0` is how this app
  records "stop emailing this person", a merge that dropped it would
  resume mailing someone who asked not to be. Each instance is reported,
  so a row that was merely stale can be reactivated afterwards.
- **Send history is moved, not deleted.** `email_log.contact_id`
  references `university_contacts(id)` with no `ON DELETE`, so MySQL
  refuses to delete any contact that has ever been emailed. The script
  repoints those log rows onto the surviving contact first, in the same
  transaction -- the audit trail survives, and a failure anywhere rolls
  the whole run back rather than leaving a half-merged state.

Take a backup first; the dry-run output reminds you with the exact
`mysqldump` command.

### Stopping them coming back

Fresh installs get this from `sql/schema.sql` already. On an existing
database, **clean up first, then add the constraint** -- in that order:

```
php scripts/dedupe_contacts.php --apply
mysql university_outreach < sql/migrations/002_contacts_source.sql
mysql university_outreach < sql/migrations/003_contacts_unique_email.sql
```

Adding the unique key to a table that still holds duplicates fails, and
the error names only the first collision it meets -- so you would be
fixing them one error at a time. The dedupe script clears them all in
one pass.

With `uniq_contact_email (university_id, email)` in place,
`import_contacts.php` upserts against it: re-running an import, or
importing two lists that overlap, now refreshes each contact instead of
adding them again. It reports how many rows were new versus updated.

One thing it deliberately will not do is change `active`. A re-import
can correct someone's name, role or source, but it cannot set them back
to active -- otherwise a routine roster refresh would quietly resume
mail to everyone who had asked you to stop. Reactivating a contact stays
a deliberate act.

## Behind Cloudflare (or any other proxy)

If the site is proxied, `REMOTE_ADDR` is the proxy's address, not the
visitor's -- and this app keys two things on the client IP:

- **the submission throttle** (`MAX_SUBMISSIONS_PER_HOUR_PER_IP`), which
  turns into a site-wide cap: after 10 submissions in an hour from
  *anyone*, every visitor gets "Too many submissions recently from this
  email or network";
- **the admin login lockout**, where one person mistyping their password
  five times locks out every other admin.

`submissions.ip_address` also stops being a usable audit trail. Fix it
once, at the server level, so the app and the logs both see real
addresses without any app code changes:

```
sudo scripts/cloudflare-remoteip.sh    # fetches Cloudflare's current ranges
sudo a2enmod remoteip
sudo a2enconf cloudflare-remoteip
sudo apache2ctl configtest && sudo systemctl reload apache2
```

The script validates what it downloads and refuses to write a partial
or non-CIDR list, keeps a `.bak`, and reverts if `configtest` fails --
a malformed file here takes Apache down on the next reload. Re-run it
periodically (it only reloads when the ranges actually changed):

```
0 4 1 * * root /var/www/university-outreach/scripts/cloudflare-remoteip.sh --quiet
```

`scripts/cloudflare-remoteip-example.conf` shows what it generates, but
the ranges in it are a point-in-time snapshot -- generate the real one.

**Firewall 80/443 to Cloudflare's ranges as well** (or use a
`cloudflared` tunnel). `RemoteIPTrustedProxy` is an allowlist of peers
whose `CF-Connecting-IP` header is believed; if the origin stays
directly reachable, anyone who finds its address can send that header
themselves and get a fresh identity per request -- which defeats both
throttles rather than merely degrading them. Trusting the header
without locking down the origin is worse than not setting it at all.

Also worth knowing:

- **Use Full (Strict) TLS mode, not Flexible.** Flexible leaves the
  Cloudflare-to-origin leg in cleartext across the public internet, admin
  password included. The Let's Encrypt certificate in
  `apache-vhost-ssl-example.conf` satisfies Full (Strict); a Cloudflare
  Origin CA certificate is the alternative.
- **HTTPS detection already works.** `admin_session_start()` checks
  `X-Forwarded-Proto`, which Cloudflare sets, so the session cookie
  still gets `Secure` even when the origin leg is plain HTTP.
- **Don't turn on "Cache Everything" without excluding this app.**
  Nothing here is cacheable by default and the admin pages send
  `Cache-Control: private, no-store`, but an edge rule that overrides
  origin cache headers could still store a page carrying one admin's
  CSRF token and serve it to someone else. Exclude `/admin/` and all
  PHP from any such rule.
- **`send.php` sends sequentially, one message per recipient**, and
  Cloudflare cuts a request off at 100 seconds (**error 524**) on the
  Free, Pro and Business plans. A long contact list on a slow SMTP
  relay can cross that, and the visitor sees an error while PHP keeps
  sending -- so they may retry and double-send. Staging multiplies it
  (contacts x staging contacts). The Mailgun HTTP driver is
  substantially faster than SMTP here.
- **Certificate renewal** through an orange-clouded record usually
  works, but breaks under "Always Use HTTPS" edge redirects or a WAF
  challenge on the challenge path. Use DNS-01
  (`python3-certbot-dns-cloudflare`) or grey-cloud during issuance, and
  confirm with `certbot renew --dry-run` *after* Cloudflare is live.
- **Mailgun DNS:** SPF and DKIM are TXT records and can't be proxied,
  so they're unaffected. The one to watch is Mailgun's tracking
  **CNAME** -- it must stay DNS-only (grey cloud); proxying it breaks
  open/click tracking and link rewriting.
- **Turnstile** is the natural fit for the CAPTCHA suggested below, if
  you're on Cloudflare already.
- Restricting `/admin/` by IP in the vhost only works once
  `mod_remoteip` is configured -- before that it matches Cloudflare's
  address, not the admin's. **Cloudflare Access** in front of `/admin/`
  is the stronger option, since the login form is then never publicly
  reachable.

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
- `includes/site_header.php` -- the site header (logo centred, primary
  nav to its left) shared by the visitor-facing pages. The Blog and
  Contact Us links are placeholders pointing at `#`.
- `includes/` -- DB connection, CSRF helpers, shared functions, admin
  authentication (`admin_auth.php`), template read/write for the admin
  editor (`template_store.php`), and the mail drivers (`mailgun.php`,
  `smtp_mailer.php`, `switchboard.php` -- the last one is a stub, see
  "Sending mail" above) -- not web-reachable.
- `composer.json` -- PHPMailer dependency (only needed for the SMTP driver).
- `public/` -- the actual web app; this is the only folder Apache serves.
- `public/admin/` -- the password-protected email template editor (see
  "Editing the email text" above).
- `public/suggest_school.php` -- the "my school isn't listed" form (see
  "When a visitor's school isn't listed" above).
- `sql/migrations/` -- schema changes for installs that predate a
  feature; fresh installs get everything from `sql/schema.sql`.
- `scripts/install_ubuntu24.sh` -- provisioning script.
- `scripts/import_contacts.php` -- CLI CSV importer.
- `scripts/dedupe_contacts.php` -- CLI cleanup for duplicate contacts
  created by overlapping imports (see "Duplicate contacts" below).
- `scripts/make_admin_hash.php` -- CLI helper that generates the
  `ADMIN_PASSWORD_HASH` line for `config.php`.
- `scripts/cloudflare-remoteip.sh` -- generates the Apache
  `mod_remoteip` config from Cloudflare's published ranges, so the app
  sees real client IPs when proxied (see "Behind Cloudflare" above).
- `scripts/cloudflare-remoteip-example.conf` -- reference copy of what
  that script generates.
- `scripts/apache-vhost-example.conf` -- example vhost, plain HTTP, for
  bringing a host up before certificates exist.
- `scripts/apache-vhost-ssl-example.conf` -- the HTTPS vhost to run in
  production: port 80 redirect (with the ACME challenge path left
  reachable so renewals keep working), TLS, HSTS, and security headers.
