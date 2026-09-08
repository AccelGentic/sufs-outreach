<?php
require_once __DIR__ . '/../config.php';
session_name(SESSION_NAME);
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

// Load whichever mail driver config.php selects, so a Mailgun-only
// deployment never needs vendor/ (PHPMailer), and vice versa.
$mailDriver = defined('MAIL_DRIVER') ? MAIL_DRIVER : 'mailgun';
if ($mailDriver === 'smtp') {
    require_once __DIR__ . '/../includes/smtp_mailer.php';
    $sendMail = 'send_via_smtp';
} elseif ($mailDriver === 'switchboard') {
    require_once __DIR__ . '/../includes/switchboard.php';
    $sendMail = 'send_via_switchboard';
} else {
    require_once __DIR__ . '/../includes/mailgun.php';
    $sendMail = 'send_via_mailgun';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: index.php');
    exit;
}

$sessionSubmissionId = (int) ($_SESSION['draft']['submission_id'] ?? 0);
$postedSubmissionId  = (int) ($_POST['submission_id'] ?? 0);

// The submission being sent must match the one this browser session
// generated. Combined with re-reading recipients from the DB below,
// this stops anyone from tampering with which university/recipients
// end up receiving mail.
if (!$sessionSubmissionId || $sessionSubmissionId !== $postedSubmissionId) {
    header('Location: index.php');
    exit;
}

$db = get_db();
$stmt = $db->prepare('SELECT * FROM submissions WHERE id = ? AND status = "draft"');
$stmt->execute([$sessionSubmissionId]);
$submission = $stmt->fetch();

if (!$submission) {
    unset($_SESSION['draft']);
    header('Location: index.php');
    exit;
}

// Always the live, admin-managed list -- never anything from $_POST.
// This is the real recipient list regardless of mode: staging redirects
// where mail actually gets delivered, but personalizes and counts
// against these real contacts so the whole flow looks and behaves
// exactly as it would in production.
$contacts = get_active_contacts((int) $submission['university_id']);
if (!$contacts) {
    $_SESSION['send_errors'] = ['No active recipients were found for this educational institution.'];
    header('Location: review.php');
    exit;
}

$isStaging = current_app_mode() === 'staging';
$stagingRecipients = [];
if ($isStaging) {
    $stagingRecipients = get_staging_recipients();
    if (!$stagingRecipients) {
        $_SESSION['send_errors'] = ['Staging mode has no test contacts configured. Add at least one active row to staging_contacts before sending.'];
        header('Location: review.php');
        exit;
    }
}

$subject = single_line(trim($_POST['subject'] ?? $submission['subject']));
$body    = trim($_POST['email_body'] ?? $submission['email_body']);

if ($subject === '' || $body === '') {
    $_SESSION['send_errors'] = ['Subject and message body cannot be empty.'];
    header('Location: review.php');
    exit;
}
if (mb_strlen($subject) > 255) {
    $subject = mb_substr($subject, 0, 255);
}
if (mb_strlen($body) > 20000) {
    $_SESSION['send_errors'] = ['Message body is too long.'];
    header('Location: review.php');
    exit;
}

// Strip everything except a small basic-formatting allowlist (see
// sanitize_email_html()) before this ever reaches a mail driver, then
// derive one plain-text fallback from the sanitized HTML -- both get
// personalized per recipient below, so this only needs to happen once.
$body = sanitize_email_html($body);
$plainTextMaster = html_body_to_plain_text($body);

// Reply-To is a fixed address from config -- NOT the submitter's own
// email -- so replies land in an org-monitored inbox rather than
// scattering across individual senders. The submitter still gets a
// copy via CC below, for their own records.
$replyToEmail = defined('MAIL_REPLY_TO_ADDRESS') ? MAIL_REPLY_TO_ADDRESS : MAIL_FROM_ADDRESS;
$replyToName  = defined('MAIL_REPLY_TO_NAME') ? MAIL_REPLY_TO_NAME : MAIL_FROM_NAME;
$ccEmail = MAIL_CC_SENDER ? $submission['email'] : null;

$sentCount = 0;
$failCount = 0;

foreach ($contacts as $contact) {
    // The edited subject/body may still contain literal {{recipient_name}}
    // / {{recipient_role}} tokens (left unresolved on purpose through
    // generate.php and review.php, since one draft serves every
    // recipient) -- resolve those per-recipient right here, so each
    // person gets their own name/role merged in without needing a
    // separate copy of the letter per contact.
    //
    // Two variants: the HTML body needs these values HTML-escaped before
    // they're substituted into markup (a name or role containing "&" or
    // "<" -- plausible in imported contact data -- would otherwise land
    // in the HTML unescaped); the plain-text body and subject use the
    // raw values since they're never parsed as markup.
    $recipientVarsPlain = [
        'recipient_name'  => $contact['name'],
        'recipient_role'  => $contact['role'],
        'recipient_email' => $contact['email'],
    ];
    $recipientVarsHtml = [
        'recipient_name'  => htmlspecialchars($contact['name'], ENT_QUOTES, 'UTF-8'),
        'recipient_role'  => htmlspecialchars($contact['role'], ENT_QUOTES, 'UTF-8'),
        'recipient_email' => htmlspecialchars($contact['email'], ENT_QUOTES, 'UTF-8'),
    ];

    $personalizedSubject   = render_template($subject, $recipientVarsPlain);
    $personalizedHtmlBody  = render_template($body, $recipientVarsHtml);
    $personalizedPlainBody = render_template($plainTextMaster, $recipientVarsPlain);

    // In staging mode, this message -- personalized exactly as it would
    // be for the real contact above -- is delivered to every staging
    // address instead of the real one, so it never actually reaches
    // them. In production it's delivered to the real contact as normal.
    $deliveryTargets = $isStaging ? $stagingRecipients : [$contact];

    foreach ($deliveryTargets as $target) {
        $result = $sendMail(
            $target['email'],
            $target['name'],
            $personalizedSubject,
            $personalizedHtmlBody,
            $personalizedPlainBody,
            $replyToEmail,
            $replyToName,
            $ccEmail
        );

        // contact_id always points at the real contact this message
        // simulates, even in staging -- recipient_email records where it
        // actually went, which may be a staging address instead.
        if ($result['success']) {
            $logStmt = $db->prepare(
                'INSERT INTO email_log (submission_id, contact_id, recipient_email, status) VALUES (?, ?, ?, "sent")'
            );
            $logStmt->execute([$submission['id'], $contact['id'], $target['email']]);
            $sentCount++;
        } else {
            $logStmt = $db->prepare(
                'INSERT INTO email_log (submission_id, contact_id, recipient_email, status, error_message)
                 VALUES (?, ?, ?, "failed", ?)'
            );
            $logStmt->execute([$submission['id'], $contact['id'], $target['email'], substr($result['error'], 0, 500)]);
            $failCount++;
        }
    }
}

$finalStatus = $failCount === 0 ? 'sent' : ($sentCount > 0 ? 'sent' : 'failed');
$updateStmt = $db->prepare(
    'UPDATE submissions SET status = ?, subject = ?, email_body = ?, sent_at = NOW() WHERE id = ?'
);
$updateStmt->execute([$finalStatus, $subject, $body, $submission['id']]);

// --- Confirmation email to the submitter, if a confirmation template is
// configured. Best-effort and tracked separately from the send above --
// a missing/failed confirmation never changes submissions.status or
// what the visitor sees on confirm.php, just what's recorded here.
$confirmationTemplate = get_active_template('confirmation');
if ($confirmationTemplate) {
    $university   = get_university((int) $submission['university_id']);
    $relationship = get_relationship((int) $submission['relationship_id']);

    $confirmationVars = [
        'first_name'      => $submission['first_name'],
        'last_name'       => $submission['last_name'],
        'sender_email'    => $submission['email'],
        'relationship'    => $relationship['name'] ?? '',
        'university'      => $university['name'] ?? '',
        'address'         => (string) ($submission['address'] ?? ''),
        'recipient_count' => (string) count($contacts),
        'sent_count'      => (string) $sentCount,
        'failed_count'    => (string) $failCount,
    ];

    $confirmationSubject   = single_line(render_template($confirmationTemplate['subject'], $confirmationVars));
    $confirmationHtmlBody  = sanitize_email_html(render_template($confirmationTemplate['body'], $confirmationVars));
    $confirmationPlainBody = html_body_to_plain_text($confirmationHtmlBody);

    // Same staging safeguard as recipient messages: this never reaches
    // a real inbox while testing, no matter what email the visitor
    // actually typed into the form.
    $confirmationTargets = $isStaging ? $stagingRecipients : [[
        'name'  => trim($submission['first_name'] . ' ' . $submission['last_name']),
        'email' => $submission['email'],
    ]];

    $confirmationError = null;
    foreach ($confirmationTargets as $target) {
        $confirmationResult = $sendMail(
            $target['email'],
            $target['name'],
            $confirmationSubject,
            $confirmationHtmlBody,
            $confirmationPlainBody,
            $replyToEmail,
            $replyToName,
            null
        );
        if (!$confirmationResult['success']) {
            $confirmationError = $confirmationResult['error'];
        }
    }

    if ($confirmationError === null) {
        $db->prepare('UPDATE submissions SET confirmation_sent_at = NOW(), confirmation_error = NULL WHERE id = ?')
            ->execute([$submission['id']]);
    } else {
        $db->prepare('UPDATE submissions SET confirmation_sent_at = NULL, confirmation_error = ? WHERE id = ?')
            ->execute([substr($confirmationError, 0, 500), $submission['id']]);
    }
}

unset($_SESSION['draft']);
$_SESSION['confirm'] = [
    'sent'   => $sentCount,
    'failed' => $failCount,
];

header('Location: confirm.php');
exit;
