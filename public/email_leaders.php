<?php
/**
 * Step two of the unlisted-school flow: offer the visitor the chance to
 * send a prepared message to the leadership addresses they just typed
 * into suggest_school.php.
 *
 * The subject and body come from the active `unlisted_school` template
 * and are shown read-only -- unlike the main outreach flow, the visitor
 * cannot edit a word of what goes out over their name. The only thing
 * they supply is the recipient, and they supplied it on the previous
 * page.
 *
 * That makes this the one path in the app that sends to an address a
 * visitor typed rather than one an administrator curated, so it is
 * deliberately fenced in:
 *   - reachable only via the session set by suggest_school.php, never
 *     as a bookmarkable URL;
 *   - at most a handful of recipients, capped when the addresses are
 *     extracted;
 *   - the message body is fixed, so nothing a visitor writes reaches a
 *     recipient;
 *   - staging redirects delivery to staging_contacts exactly like the
 *     main send path, because these are real people;
 *   - every send is reported back to SCHOOL_REQUEST_TO_ADDRESS, so
 *     there is a record of what went out in whose name.
 */
require_once __DIR__ . '/../config.php';
session_name(SESSION_NAME);
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

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

$done = $_SESSION['unlisted_school_done'] ?? null;
unset($_SESSION['unlisted_school_done']);

$pending = $_SESSION['unlisted_school'] ?? null;

// Nothing to offer unless suggest_school.php put something here.
if (!$done && (!$pending || empty($pending['recipients']))) {
    header('Location: index.php');
    exit;
}

$errors = [];

if (!$done) {
    $school     = (string) $pending['school'];
    $senderEmail = (string) $pending['sender_email'];
    $recipients  = (array) $pending['recipients'];

    $template = get_active_template('unlisted_school');
    if (!$template) {
        // No template, nothing to send. Say so rather than offering a
        // button that would mail an empty message.
        $errors[] = 'This message isn\'t available right now.';
    }
}

if (!$done && !$errors && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $isStaging = current_app_mode() === 'staging';
        $stagingRecipients = [];
        if ($isStaging) {
            $stagingRecipients = get_staging_recipients();
            if (!$stagingRecipients) {
                $errors[] = 'Staging mode has no test contacts configured. Add at least one active row to staging_contacts before sending.';
            }
        }

        if (!$errors) {
            $replyToEmail = defined('MAIL_REPLY_TO_ADDRESS') ? MAIL_REPLY_TO_ADDRESS : MAIL_FROM_ADDRESS;
            $replyToName  = defined('MAIL_REPLY_TO_NAME') ? MAIL_REPLY_TO_NAME : MAIL_FROM_NAME;

            $sentTo  = [];
            $failed  = [];

            foreach ($recipients as $recipient) {
                $vars = [
                    'school'          => $school,
                    'sender_email'    => $senderEmail,
                    'recipient_email' => $recipient,
                ];
                $htmlVars = array_map(
                    fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'),
                    $vars
                );

                $subject   = single_line(render_template($template['subject'], $vars));
                $htmlBody  = sanitize_email_html(render_template($template['body'], $htmlVars));
                $plainBody = html_body_to_plain_text($htmlBody);

                // Same safeguard as send.php: in staging this goes to the
                // test pool instead, personalised exactly as the real
                // recipient would have received it.
                $targets = $isStaging
                    ? $stagingRecipients
                    : [['name' => '', 'email' => $recipient]];

                $ok = true;
                foreach ($targets as $target) {
                    $result = $sendMail(
                        $target['email'],
                        (string) ($target['name'] ?? ''),
                        $subject,
                        $htmlBody,
                        $plainBody,
                        $replyToEmail,
                        $replyToName,
                        null
                    );
                    if (!$result['success']) {
                        $ok = false;
                        error_log(sprintf(
                            'email_leaders.php: send to %s failed: %s',
                            $target['email'],
                            $result['error']
                        ));
                    }
                }

                if ($ok) {
                    $sentTo[] = $recipient;
                } else {
                    $failed[] = $recipient;
                }
            }

            // Tell the operator what went out in a visitor's name. There
            // is no table behind this flow, so without this there would
            // be no record of it at all.
            if ($sentTo && school_requests_enabled()) {
                $noticeSubject = single_line(
                    ($isStaging ? '[STAGING] ' : '')
                    . 'Unlisted school message sent: ' . $school
                );
                $noticeHtml = '<p>A visitor sent the unlisted-school message.</p>'
                    . '<p><strong>School:</strong> ' . h($school) . '<br>'
                    . '<strong>From:</strong> ' . h($senderEmail) . '<br>'
                    . '<strong>Sent to:</strong> ' . h(implode(', ', $sentTo)) . '</p>';
                if ($failed) {
                    $noticeHtml .= '<p><strong>Failed:</strong> ' . h(implode(', ', $failed)) . '</p>';
                }
                $sendMail(
                    trim((string) SCHOOL_REQUEST_TO_ADDRESS),
                    defined('SCHOOL_REQUEST_TO_NAME') ? (string) SCHOOL_REQUEST_TO_NAME : '',
                    $noticeSubject,
                    $noticeHtml,
                    html_body_to_plain_text($noticeHtml),
                    $senderEmail,
                    '',
                    null
                );
            }

            // Clear the pending state before redirecting so a refresh
            // cannot send the same message twice.
            unset($_SESSION['unlisted_school']);
            $_SESSION['unlisted_school_done'] = [
                'sent'   => count($sentTo),
                'failed' => count($failed),
            ];
            header('Location: email_leaders.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Send a Message to Your School's Leadership</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php require __DIR__ . '/../includes/staging_banner.php'; ?>
<?php require __DIR__ . '/../includes/site_header.php'; ?>
<main class="card">

<?php if ($done): ?>
  <h1>Thank you!</h1>
  <?php if ($done['sent'] > 0): ?>
    <div class="alert alert-success">
      Your message is on its way to
      <?= (int) $done['sent'] ?> <?= $done['sent'] === 1 ? 'address' : 'addresses' ?>.
    </div>
  <?php endif; ?>
  <?php if ($done['failed'] > 0): ?>
    <p class="alert alert-error">
      <?= (int) $done['failed'] ?> message(s) could not be delivered.
      The site administrator can see the details.
    </p>
  <?php endif; ?>
  <p><a href="index.php">Contact another educational institution</a></p>

<?php else: ?>
  <h1>One More Thing You Can Do</h1>
  <p>
    Thanks &mdash; we've got your note about <strong><?= h($school) ?></strong>.
    While we work on adding it, you can send the message below straight to
    the <?= count($recipients) === 1 ? 'address' : 'addresses' ?> you gave us.
  </p>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if (!empty($template)): ?>
    <section class="recipients">
      <h2>Going to (<?= count($recipients) ?>)</h2>
      <p class="hint">These are the addresses you entered on the previous step.</p>
      <ul>
        <?php foreach ($recipients as $r): ?>
          <li><?= h($r) ?></li>
        <?php endforeach; ?>
      </ul>
    </section>

    <?php
    // Rendered with the first recipient's details, the same way it will
    // be sent. Read-only on purpose: this message goes out under the
    // visitor's name but in our words, so it is not theirs to rewrite.
    $previewVars = [
        'school'          => htmlspecialchars($school, ENT_QUOTES, 'UTF-8'),
        'sender_email'    => htmlspecialchars($senderEmail, ENT_QUOTES, 'UTF-8'),
        'recipient_email' => htmlspecialchars($recipients[0], ENT_QUOTES, 'UTF-8'),
    ];
    $previewSubject = single_line(render_template($template['subject'], [
        'school'          => $school,
        'sender_email'    => $senderEmail,
        'recipient_email' => $recipients[0],
    ]));
    $previewHtml = sanitize_email_html(render_template($template['body'], $previewVars));
    ?>
    <h2 class="message-heading">The message</h2>
    <div class="preview-field"><span class="preview-label">Subject:</span> <?= h($previewSubject) ?></div>
    <iframe class="preview-frame preview-frame-compact" sandbox="" title="The message that will be sent"
            srcdoc="<?= h('<!DOCTYPE html><meta charset="utf-8"><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#222;margin:12px}</style>' . $previewHtml) ?>"></iframe>

    <form method="post" action="email_leaders.php">
      <?= csrf_field() ?>
      <div class="actions">
        <a href="index.php" class="button-secondary">No thanks</a>
        <button type="submit">Send this message</button>
      </div>
    </form>
  <?php else: ?>
    <p><a href="index.php">&larr; Back to the form</a></p>
  <?php endif; ?>
<?php endif; ?>

</main>
</body>
</html>
