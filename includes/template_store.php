<?php
/**
 * Read/write access to email_templates for the admin editor.
 *
 * Kept separate from functions.php on purpose: the visitor-facing pages
 * only ever need get_active_template(), so nothing that can modify a
 * template is loaded by the code paths a visitor can reach.
 */
require_once __DIR__ . '/db.php';

const TEMPLATE_TYPES = [
    'outreach'        => 'Outreach email (the draft each visitor reviews and sends)',
    'confirmation'    => 'Confirmation email (the receipt sent to the visitor afterwards)',
    'unlisted_school' => "Unlisted school email (sent as-is to a school that isn't in the list yet)",
];

/** Hard cap on a template body, matching send.php's own limit -- a
 *  longer template would generate a draft that send.php then refuses. */
const TEMPLATE_BODY_MAX = 20000;

function get_all_templates(): array
{
    return get_db()->query(
        'SELECT id, name, type, subject, body, active, updated_at
         FROM email_templates
         ORDER BY type ASC, active DESC, id DESC'
    )->fetchAll();
}

function get_template(int $id): ?array
{
    $stmt = get_db()->prepare(
        'SELECT id, name, type, subject, body, active, updated_at FROM email_templates WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function update_template(int $id, string $name, string $subject, string $body): void
{
    $stmt = get_db()->prepare(
        'UPDATE email_templates SET name = ?, subject = ?, body = ? WHERE id = ?'
    );
    $stmt->execute([$name, $subject, $body, $id]);
}

function create_template(string $name, string $type, string $subject, string $body): int
{
    $db = get_db();
    // Created inactive: a brand-new template shouldn't start going out
    // to real recipients the moment it's saved. The admin activates it
    // deliberately once it reads right.
    $stmt = $db->prepare(
        'INSERT INTO email_templates (name, type, subject, body, active) VALUES (?, ?, ?, ?, 0)'
    );
    $stmt->execute([$name, $type, $subject, $body]);

    return (int) $db->lastInsertId();
}

/**
 * Activating a template deactivates every other template of the same
 * type, so exactly one is live per type. Without this, get_active_template()
 * silently picks the highest id among several active rows -- true, but
 * not something an admin should have to reason about.
 */
function activate_template(int $id): bool
{
    $template = get_template($id);
    if (!$template) {
        return false;
    }

    $db = get_db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('UPDATE email_templates SET active = 0 WHERE type = ? AND id <> ?');
        $stmt->execute([$template['type'], $id]);

        $stmt = $db->prepare('UPDATE email_templates SET active = 1 WHERE id = ?');
        $stmt->execute([$id]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return true;
}

/**
 * Deactivating the live outreach template is blocked: generate.php falls
 * back to an empty body with no template at all, which would quietly
 * hand every visitor a blank message. Confirmation templates are safe to
 * turn off -- send.php skips that step when none is active, by design.
 */
function deactivate_template(int $id): ?string
{
    $template = get_template($id);
    if (!$template) {
        return 'That template no longer exists.';
    }
    if ($template['type'] === 'outreach') {
        return 'The outreach template can\'t be switched off -- visitors would get an empty message. Activate a different outreach template instead, which deactivates this one automatically.';
    }

    $stmt = get_db()->prepare('UPDATE email_templates SET active = 0 WHERE id = ?');
    $stmt->execute([$id]);

    return null;
}

/**
 * Validation shared by the create and edit paths. Returns a list of
 * human-readable errors; empty means the values are safe to store.
 */
function validate_template_input(string $name, string $type, string $subject, string $body): array
{
    $errors = [];

    if ($name === '' || mb_strlen($name) > 150) {
        $errors[] = 'Give the template a name of 1-150 characters.';
    }
    if (!array_key_exists($type, TEMPLATE_TYPES)) {
        $errors[] = 'Pick a valid template type.';
    }
    if ($subject === '') {
        $errors[] = 'The subject line cannot be empty.';
    } elseif (mb_strlen($subject) > 255) {
        $errors[] = 'The subject line is limited to 255 characters.';
    }
    if (trim($body) === '') {
        $errors[] = 'The message body cannot be empty.';
    } elseif (mb_strlen($body) > TEMPLATE_BODY_MAX) {
        $errors[] = 'The message body is limited to ' . number_format(TEMPLATE_BODY_MAX) . ' characters.';
    }

    return $errors;
}
