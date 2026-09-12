<?php
require_once __DIR__ . '/db.php';

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function get_universities(): array
{
    $stmt = get_db()->query('SELECT id, name FROM universities ORDER BY name ASC');
    return $stmt->fetchAll();
}

function get_university(int $id): ?array
{
    $stmt = get_db()->prepare('SELECT id, name FROM universities WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Normalizes config.php's APP_MODE to exactly 'staging' or 'production'
 * -- anything unset or misconfigured falls back to 'production' rather
 * than silently staying in staging, so a missing/bad config can't
 * accidentally suppress real sends.
 */
function current_app_mode(): string
{
    return (defined('APP_MODE') && APP_MODE === 'staging') ? 'staging' : 'production';
}

/**
 * Contacts who should receive outreach mail for a university. Always
 * read fresh from the DB -- never trust a recipient list coming from
 * the browser. Always the real university_contacts, regardless of
 * APP_MODE -- staging mode still shows and personalizes against the
 * real recipient list (see get_staging_recipients()), it just redirects
 * where the mail actually gets delivered.
 */
function get_active_contacts(int $universityId): array
{
    $stmt = get_db()->prepare(
        'SELECT id, name, role, email FROM university_contacts
         WHERE university_id = ? AND active = 1
         ORDER BY name ASC'
    );
    $stmt->execute([$universityId]);
    return $stmt->fetchAll();
}

/**
 * The small, institution-independent pool of test addresses everything
 * gets redirected to in staging mode -- deliberately not filtered by
 * university, since staging redirects delivery no matter which
 * institution was selected.
 */
function get_staging_recipients(): array
{
    $stmt = get_db()->query(
        'SELECT id, name, email FROM staging_contacts WHERE active = 1 ORDER BY name ASC'
    );
    return $stmt->fetchAll();
}

/**
 * The active template of the given type -- 'outreach' (the editable
 * draft a visitor sees) or 'confirmation' (sent once, automatically, to
 * the visitor's own address after sending).
 */
function get_active_template(string $type = 'outreach'): ?array
{
    $stmt = get_db()->prepare(
        'SELECT id, subject, body FROM email_templates WHERE type = ? AND active = 1 ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$type]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_relationships(): array
{
    $stmt = get_db()->query('SELECT id, name FROM relationships ORDER BY sort_order ASC, name ASC');
    return $stmt->fetchAll();
}

function get_relationship(int $id): ?array
{
    $stmt = get_db()->prepare('SELECT id, name FROM relationships WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Sanitize visitor-typed HTML for use as an email body. Strips every tag
 * except a small "basic formatting" allowlist, and rebuilds any <a> tag
 * from scratch keeping only a validated href -- so no other attribute
 * (onclick, style, a javascript: URL, etc.) can ride along inside an
 * otherwise-allowed tag. strip_tags() alone only filters tag names, not
 * what's inside them, which is why the <a> rebuild step exists.
 */
function sanitize_email_html(string $html): string
{
    // strip_tags() removes tag *names* but leaves a tag's inner text
    // behind -- for most tags that's fine (nothing to hide), but for
    // <script>/<style> it would leave raw JS/CSS code sitting in the
    // email as stray visible text. Remove those blocks wholesale first.
    $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);

    // A blank line is how anyone typing plain text signals a new
    // paragraph -- but a raw "\n\n" has no visual effect once this is
    // sent as actual HTML (browsers/mail clients collapse whitespace),
    // so without this step every paragraph would run together. Skip it
    // entirely if the visitor already used <p>/<ul>/<ol> themselves --
    // that's a deliberate structure, so leave it exactly as written.
    $html = auto_paragraph_html($html);

    $allowedTags = '<b><strong><i><em><u><br><p><ul><ol><li><a>';
    $html = strip_tags($html, $allowedTags);

    return (string) preg_replace_callback(
        '/<a\b[^>]*>/i',
        function (array $m): string {
            if (preg_match('/href\s*=\s*(["\'])(.*?)\1/i', $m[0], $hrefMatch)) {
                $href = html_entity_decode($hrefMatch[2], ENT_QUOTES);
                if (preg_match('#^(https?://|mailto:)#i', $href)) {
                    return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '">';
                }
            }
            // No safe href found -- drop the tag but keep the link text
            // (the closing </a> left behind is harmless, browsers and
            // mail clients ignore an unmatched closing tag).
            return '';
        },
        $html
    );
}

/**
 * Turn blank-line-separated blocks of plain text into <p> paragraphs,
 * with a single newline within a block becoming <br>. Left alone
 * entirely if the text already contains a <p>, <ul>, or <ol> -- that
 * signals the visitor structured it deliberately, so this shouldn't
 * second-guess it.
 */
function auto_paragraph_html(string $html): string
{
    if (preg_match('/<(p|ul|ol)\b/i', $html)) {
        return $html;
    }

    $normalized = str_replace(["\r\n", "\r"], "\n", $html);
    $blocks = preg_split('/\n\s*\n+/', trim($normalized));

    $paragraphs = array_map(
        fn (string $block): string => '<p>' . str_replace("\n", '<br>', trim($block)) . '</p>',
        $blocks
    );

    return implode('', $paragraphs);
}

/**
 * Best-effort plain-text rendering of a sanitized HTML body, for the
 * AltBody/text fallback every HTML email should carry for clients that
 * don't render HTML. Turns structural tags into line breaks and keeps
 * link URLs as visible "text (url)" text before stripping everything
 * else -- a bare strip_tags() would silently drop every link's URL.
 */
function html_body_to_plain_text(string $html): string
{
    $text = preg_replace('/<a\s[^>]*href\s*=\s*"([^"]*)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $html);
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = preg_replace('/<\/p>/i', "\n\n", $text);
    $text = preg_replace('/<\/li>/i', "\n", $text);
    $text = strip_tags($text);

    return trim($text);
}

function render_template(string $text, array $vars): string
{
    $replacements = [];
    foreach ($vars as $key => $value) {
        $replacements['{{' . $key . '}}'] = (string) $value;
    }
    return strtr($text, $replacements);
}

/**
 * Strip characters that could be used for header/line injection when a
 * value is going to be used as an email Subject or other header field.
 */
function single_line(string $value): string
{
    $value = str_replace(["\r", "\n"], ' ', $value);
    return trim($value);
}

function is_valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Simple abuse throttle: counts recent submissions from this email
 * address and this IP address in the last hour.
 */
function too_many_recent_submissions(string $email, string $ip): bool
{
    $db = get_db();

    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM submissions WHERE email = ? AND created_at > (NOW() - INTERVAL 1 HOUR)'
    );
    $stmt->execute([$email]);
    if ((int) $stmt->fetchColumn() >= MAX_SUBMISSIONS_PER_HOUR_PER_EMAIL) {
        return true;
    }

    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM submissions WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 1 HOUR)'
    );
    $stmt->execute([$ip]);
    if ((int) $stmt->fetchColumn() >= MAX_SUBMISSIONS_PER_HOUR_PER_IP) {
        return true;
    }

    return false;
}

/**
 * Which {{placeholders}} are meaningful for a given template type.
 * Used by the admin editor to document what's available and to warn
 * about tokens that will never be replaced -- a typo like
 * {{firstname}} isn't an error anywhere in the send path, it just
 * ships as literal text to every recipient, so it's worth catching at
 * edit time.
 */
function template_placeholders(string $type): array
{
    $sender = [
        'first_name'   => "The visitor's first name",
        'last_name'    => "The visitor's last name",
        'sender_email' => "The visitor's own email address",
        'relationship' => 'How they relate to the institution (Alumni, Parent, Donor, ...)',
        'university'   => 'The institution they selected',
        'address'      => "The visitor's address, if they entered one",
    ];

    if ($type === 'confirmation') {
        return $sender + [
            'recipient_count' => 'How many contacts the message went to',
            'sent_count'      => 'How many copies were delivered successfully',
            'failed_count'    => 'How many copies failed',
        ];
    }

    // The unlisted-school message has none of the sender-side
    // placeholders above: it comes from suggest_school.php, which asks
    // only for the school, the visitor's email and the leadership
    // contacts they know -- there is no name, relationship or address to
    // merge in. Referencing one of those here would ship the literal
    // {{token}} to a university leader.
    if ($type === 'unlisted_school') {
        return [
            'school'          => "The school the visitor said was missing",
            'sender_email'    => "The visitor's own email address",
            'recipient_email' => "The leadership address this copy is going to",
        ];
    }

    return $sender + [
        'recipient_name'  => "Each recipient's own name (filled in per person at send time)",
        'recipient_role'  => "Each recipient's role (filled in per person at send time)",
        'recipient_email' => "Each recipient's email address (filled in per person at send time)",
    ];
}

/**
 * Placeholder-looking tokens in $text that render_template() will not
 * replace for this template type -- either an unknown name, or a known
 * name written with stray whitespace ({{ first_name }}), which is just
 * as dead since the merge does a literal string swap on '{{name}}'.
 * Returns the raw tokens as written, de-duplicated.
 */
function unknown_placeholders(string $text, string $type): array
{
    if (!preg_match_all('/\{\{[^{}]*\}\}/', $text, $matches)) {
        return [];
    }

    $known = array_keys(template_placeholders($type));
    $unknown = [];
    foreach ($matches[0] as $token) {
        $name = substr($token, 2, -2);
        if (!in_array($name, $known, true)) {
            $unknown[$token] = true;
        }
    }

    return array_keys($unknown);
}

/**
 * Stand-in values used to render the admin editor's preview, so an
 * admin sees a realistic message rather than raw {{tokens}}.
 */
function sample_template_vars(string $type): array
{
    $sender = [
        'first_name'   => 'Jordan',
        'last_name'    => 'Rivera',
        'sender_email' => 'jordan.rivera@example.edu',
        'relationship' => 'Alumni',
        'university'   => 'Example State University',
        'address'      => '123 Main Street, Springfield, IL 62701',
    ];

    if ($type === 'confirmation') {
        return $sender + [
            'recipient_count' => '4',
            'sent_count'      => '4',
            'failed_count'    => '0',
        ];
    }

    if ($type === 'unlisted_school') {
        return [
            'school'          => 'Springfield A&M',
            'sender_email'    => 'jordan.rivera@example.edu',
            'recipient_email' => 'provost@springfield.edu',
        ];
    }

    return $sender + [
        'recipient_name'  => 'Dr. Alex Chen',
        'recipient_role'  => 'Provost',
        'recipient_email' => 'provost@example.edu',
    ];
}

/**
 * Whether the "my school isn't listed" form is usable. It emails a fixed
 * address from config.php and keeps no database record, so without a
 * real address configured there is nowhere for a submission to go --
 * in which case the links offering it are hidden rather than leading
 * visitors into a form that would silently discard what they wrote.
 */
function school_requests_enabled(): bool
{
    return defined('SCHOOL_REQUEST_TO_ADDRESS')
        && is_valid_email(trim((string) SCHOOL_REQUEST_TO_ADDRESS));
}

/**
 * Pulls email addresses out of the free-text "leadership contacts" box
 * on suggest_school.php, so the visitor can be offered the chance to
 * write to them. People type things like
 * "Dr. Jane Doe, Provost, jdoe@example.edu" one per line, so the
 * addresses are found rather than parsed out of a fixed format.
 *
 * De-duplicated case-insensitively, validated with the same rule as
 * everywhere else, and capped: whatever is pasted in, this can only ever
 * turn into a handful of messages.
 */
function extract_email_addresses(string $text, int $limit = 5): array
{
    if (!preg_match_all('/[^\s<>,;:"\'()\[\]]+@[^\s<>,;:"\'()\[\]]+/', $text, $matches)) {
        return [];
    }

    $found = [];
    foreach ($matches[0] as $candidate) {
        // Trailing punctuation is common when an address ends a sentence.
        $candidate = rtrim($candidate, '.,;:)>]');
        if (!is_valid_email($candidate)) {
            continue;
        }
        $key = mb_strtolower($candidate);
        if (!isset($found[$key])) {
            $found[$key] = $candidate;
        }
        if (count($found) >= $limit) {
            break;
        }
    }

    return array_values($found);
}
