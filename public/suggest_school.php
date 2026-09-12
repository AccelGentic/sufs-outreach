<?php
/**
 * "My school isn't listed" -- lets a visitor whose institution is
 * missing from the dropdown tell us about it, and pass along the
 * leadership contacts they know.
 *
 * The result is one email to SCHOOL_REQUEST_TO_ADDRESS, sent through
 * whichever MAIL_DRIVER config.php selects (Mailgun by default), exactly
 * like the rest of the app's mail. Nothing is written to the database:
 * that address is the only record, so a failed send is reported to the
 * visitor rather than swallowed, and the details are written to the PHP
 * error log so the submission is still recoverable from the server.
 *
 * This is a dead end for these visitors otherwise -- with their school
 * absent from the list there is nothing on index.php they can complete
 * -- which is why it's a page of its own rather than a panel inside a
 * form they can't submit.
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

$enabled = school_requests_enabled();

$errors = [];
$sent   = !empty($_SESSION['school_request_sent']);
unset($_SESSION['school_request_sent']);

$schoolName   = trim((string) ($_GET['school'] ?? ''));
$contactEmail = '';
$leaders      = '';

// Timestamp of the last render, used as a bot trap below. Held in the
// session rather than a hidden field so it can't just be rewritten.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION['school_request_rendered_at'] = time();
}

if ($enabled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $schoolName   = trim((string) ($_POST['school_name'] ?? ''));
    $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
    $leaders      = trim((string) ($_POST['leaders'] ?? ''));

    // Spam defences, cheapest first. There is no database here, so no
    // per-IP counter to lean on -- these stop casual bots, and the real
    // answer if this ever gets hammered is a CAPTCHA (Turnstile, if
    // you're already behind Cloudflare).
    $honeypotFilled = trim((string) ($_POST['website'] ?? '')) !== '';
    $renderedAt     = (int) ($_SESSION['school_request_rendered_at'] ?? 0);
    $tooFast        = $renderedAt > 0 && (time() - $renderedAt) < 3;

    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    }
    if ($schoolName === '' || mb_strlen($schoolName) > 255) {
        $errors[] = 'Please enter the name of the school.';
    }
    if ($contactEmail === '' || !is_valid_email($contactEmail)) {
        $errors[] = 'Please enter a valid email address so we can follow up.';
    }
    if (mb_strlen($leaders) > 4000) {
        $errors[] = 'That message is too long -- please keep it under 4,000 characters.';
    }

    if (!$errors) {
        if ($honeypotFilled || $tooFast) {
            // Silently accept without sending. Telling a bot which check
            // it tripped just helps it past the next one, and a person
            // who somehow submits in under three seconds loses nothing
            // they can't re-send.
            $_SESSION['school_request_sent'] = true;
            header('Location: suggest_school.php');
            exit;
        }

        $isStaging = current_app_mode() === 'staging';

        $subject = single_line('School request: ' . $schoolName);
        if ($isStaging) {
            $subject = '[STAGING] ' . $subject;
        }
        if (mb_strlen($subject) > 255) {
            $subject = mb_substr($subject, 0, 255);
        }

        $htmlBody = '<p>Someone could not find their school in the list.</p>'
            . '<p><strong>School:</strong> ' . h($schoolName) . '<br>'
            . '<strong>From:</strong> ' . h($contactEmail) . '</p>';
        $htmlBody .= $leaders !== ''
            ? '<p><strong>Leadership contacts they gave:</strong></p><p>' . nl2br(h($leaders)) . '</p>'
            : '<p><em>They did not have any leadership contacts to pass on.</em></p>';
        $htmlBody .= '<p>Reply to this message to reach them directly.</p>';

        $plainBody = "Someone could not find their school in the list.\n\n"
            . "School: $schoolName\n"
            . "From:   $contactEmail\n\n"
            . ($leaders !== ''
                ? "Leadership contacts they gave:\n$leaders\n"
                : "They did not have any leadership contacts to pass on.\n")
            . "\nReply to this message to reach them directly.\n";

        /**
         * Two deliberate departures from how the rest of the app sends
         * mail, both because this message goes to our own inbox rather
         * than to a visitor or a university contact:
         *
         * - Reply-To is the visitor's address, not the fixed one from
         *   config. The whole point is to be able to hit reply and ask
         *   which campus they meant. It's validated above, so it can't
         *   carry a line break into the header.
         * - Staging does NOT redirect this to staging_contacts. That
         *   safeguard exists to keep test mail away from real people;
         *   here the recipient is the operator, who wants to see the
         *   test. The subject is prefixed instead, so a staging message
         *   is never mistaken for a real one.
         */
        $result = $sendMail(
            trim((string) SCHOOL_REQUEST_TO_ADDRESS),
            defined('SCHOOL_REQUEST_TO_NAME') ? (string) SCHOOL_REQUEST_TO_NAME : '',
            $subject,
            $htmlBody,
            $plainBody,
            $contactEmail,
            '',
            null
        );

        if ($result['success']) {
            unset($_SESSION['school_request_rendered_at']);
            $_SESSION['school_request_sent'] = true;
            // Redirect after POST so a refresh can't re-send it.
            header('Location: suggest_school.php');
            exit;
        }

        // Nothing was stored, so a failure here would otherwise lose what
        // they wrote. Tell them plainly, and put the whole submission in
        // the server log so it can still be recovered.
        error_log(sprintf(
            'suggest_school.php: notification failed (%s). School: %s | From: %s | Leaders: %s',
            $result['error'],
            $schoolName,
            $contactEmail,
            str_replace("\n", ' / ', $leaders)
        ));
        $errors[] = 'Sorry -- we could not send that just now. Please try again in a few minutes.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tell Us About Your School</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php require __DIR__ . '/../includes/staging_banner.php'; ?>
<?php require __DIR__ . '/../includes/site_header.php'; ?>
<main class="card">
  <h1>Tell Us About Your School</h1>

  <?php if (!$enabled): ?>
    <div class="alert alert-error">
      This form isn't set up yet. Please check back later.
    </div>
    <p><a href="index.php">&larr; Back to the form</a></p>

  <?php elseif ($sent): ?>
    <div class="alert alert-success">
      <p><strong>Thank you &mdash; that's on its way to us.</strong></p>
      <p>
        We'll look into adding your school. If we need to check anything,
        we'll reply to the address you gave.
      </p>
    </div>
    <p><a href="index.php">&larr; Back to the form</a></p>

  <?php else: ?>
    <p>
      If your school isn't in the list yet, tell us about it and we'll
      look into adding it. If you know who leads it, that helps us get
      there faster &mdash; but don't worry if you don't.
    </p>

    <?php if ($errors): ?>
      <div class="alert alert-error">
        <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" action="suggest_school.php">
      <?= csrf_field() ?>

      <label for="school_name">School name *</label>
      <input type="text" id="school_name" name="school_name" required maxlength="255"
             value="<?= h($schoolName) ?>">

      <label for="contact_email">Your email *</label>
      <p class="hint">So we can get back to you if we need to check something.</p>
      <input type="email" id="contact_email" name="contact_email" required maxlength="255"
             value="<?= h($contactEmail) ?>">

      <label for="leaders">Leadership contacts you know (optional)</label>
      <p class="hint">
        A name, role and email for anyone in your school's leadership &mdash;
        one per line, in whatever form you have them. Anything else you
        think we should know is welcome here too.
      </p>
      <textarea id="leaders" name="leaders" rows="6" maxlength="4000"
                placeholder="Dr. Jane Doe, Provost, jdoe@example.edu"><?= h($leaders) ?></textarea>

      <!-- Left empty by people, filled in by bots. Hidden from both
           screen readers and the tab order so it can't trap anyone. -->
      <div class="honeypot" aria-hidden="true">
        <label for="website">Website</label>
        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
      </div>

      <div class="actions">
        <a href="index.php" class="button-secondary">Cancel</a>
        <button type="submit">Send</button>
      </div>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
