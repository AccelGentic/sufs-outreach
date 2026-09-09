<?php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/template_store.php';
require_once __DIR__ . '/../../includes/csrf.php';

admin_session_start();
admin_send_headers();
require_admin();

$errors   = [];
$warnings = $_SESSION['admin_warnings'] ?? [];
$notice   = $_SESSION['admin_notice'] ?? null;
unset($_SESSION['admin_warnings'], $_SESSION['admin_notice']);

$preview = null; // populated by the Preview button, never persisted

/**
 * Work out which template is being edited (or that a new one is being
 * created) from either the GET query or the round-tripped POST, and
 * seed the form fields accordingly.
 */
$templateId = 0;
$template   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $templateId = (int) ($_POST['template_id'] ?? 0);
} else {
    $templateId = (int) ($_GET['id'] ?? 0);
}

if ($templateId > 0) {
    $template = get_template($templateId);
    if (!$template) {
        $_SESSION['admin_errors'] = ['That template no longer exists.'];
        header('Location: index.php');
        exit;
    }
}

$isNew = $template === null;

// A new template's type comes from the link that got here (or from the
// posted form on a validation round trip) and is fixed from then on:
// the two types have different placeholders and different send paths,
// so switching an existing template's type would silently change what
// it means.
if ($isNew) {
    $type = (string) ($_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['type'] ?? '')
        : ($_GET['new'] ?? 'outreach'));
    if (!array_key_exists($type, TEMPLATE_TYPES)) {
        $type = 'outreach';
    }
} else {
    $type = (string) $template['type'];
}

$name    = $isNew ? '' : (string) $template['name'];
$subject = $isNew ? '' : (string) $template['subject'];
$body    = $isNew ? '' : (string) $template['body'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please submit the form again.';
    } else {
        // single_line() on the subject mirrors what send.php does to it
        // anyway -- storing it already stripped means what's shown here
        // is what actually goes out.
        $name    = trim((string) ($_POST['name'] ?? ''));
        $subject = single_line((string) ($_POST['subject'] ?? ''));
        $body    = (string) ($_POST['body'] ?? '');

        $action = (string) ($_POST['action'] ?? 'preview');
        $errors = validate_template_input($name, $type, $subject, $body);

        if (!$errors && $action === 'save') {
            $unknown = array_unique(array_merge(
                unknown_placeholders($subject, $type),
                unknown_placeholders($body, $type)
            ));

            if ($isNew) {
                $newId = create_template($name, $type, $subject, $body);
                $_SESSION['admin_notice'] = 'Template created. It is saved as inactive -- use "Make live" on the template list when you want the app to start using it.';
            } else {
                update_template($templateId, $name, $subject, $body);
                $newId = $templateId;
                $_SESSION['admin_notice'] = 'Saved.'
                    . ((int) $template['active'] === 1
                        ? ' This is the live template, so the new text applies to every message generated from now on.'
                        : ' This template is inactive, so nothing being sent changes until you make it live.');
            }

            if ($unknown) {
                $_SESSION['admin_warnings'] = [
                    'Saved, but these look like placeholders and will be sent as literal text because nothing replaces them: '
                    . implode(', ', $unknown)
                    . '. Placeholders are case-sensitive and take no spaces inside the braces.',
                ];
            }

            header('Location: edit.php?id=' . $newId);
            exit;
        }

        if (!$errors) {
            // Preview: render exactly the way the message will be built
            // -- sample values merged in, then run through the same
            // sanitizer send.php uses, then the same plain-text
            // derivation used for the text/plain alternative.
            $vars = sample_template_vars($type);
            $previewSubject = render_template($subject, $vars);
            $previewHtml    = sanitize_email_html(render_template($body, $vars));

            $preview = [
                'subject' => $previewSubject,
                'html'    => $previewHtml,
                'text'    => html_body_to_plain_text($previewHtml),
            ];

            $unknown = array_unique(array_merge(
                unknown_placeholders($subject, $type),
                unknown_placeholders($body, $type)
            ));
            if ($unknown) {
                $warnings[] = 'These look like placeholders but nothing replaces them, so they would be sent as literal text: '
                    . implode(', ', $unknown)
                    . '. Placeholders are case-sensitive and take no spaces inside the braces.';
            }
        }
    }
}

$placeholders = template_placeholders($type);
$typeLabel    = TEMPLATE_TYPES[$type];
$isLive       = !$isNew && (int) $template['active'] === 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $isNew ? 'New Template' : 'Edit Template' ?></title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<?php require __DIR__ . '/../../includes/staging_banner.php'; ?>
<main class="card card-wide">
  <div class="admin-bar">
    <h1><?= $isNew ? 'New Template' : 'Edit Template' ?></h1>
    <form method="post" action="logout.php" class="inline-form">
      <?= csrf_field() ?>
      <button type="submit" class="button-secondary button-inline">Sign out</button>
    </form>
  </div>

  <p class="hint">
    <a href="index.php">&larr; All templates</a> &nbsp;|&nbsp; <?= h($typeLabel) ?>
    <?php if ($isLive): ?>
      &nbsp;|&nbsp; <span class="badge badge-live">Live</span>
    <?php elseif (!$isNew): ?>
      &nbsp;|&nbsp; <span class="badge">Inactive</span>
    <?php endif; ?>
  </p>

  <?php if ($isLive): ?>
    <div class="alert alert-warning">
      This is the template currently in use. Saving changes it for every
      message generated from now on.
    </div>
  <?php endif; ?>

  <?php if ($notice): ?>
    <div class="alert alert-success"><?= h($notice) ?></div>
  <?php endif; ?>
  <?php if ($warnings): ?>
    <div class="alert alert-warning">
      <ul><?php foreach ($warnings as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-error">
      <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="editor-layout">
    <form method="post" action="edit.php" class="editor-form">
      <?= csrf_field() ?>
      <input type="hidden" name="template_id" value="<?= (int) $templateId ?>">
      <input type="hidden" name="type" value="<?= h($type) ?>">

      <label for="name">Template name <span class="hint-inline">(for your reference only -- never shown to anyone)</span></label>
      <input type="text" id="name" name="name" maxlength="150" required value="<?= h($name) ?>">

      <label for="subject">Subject line</label>
      <input type="text" id="subject" name="subject" maxlength="255" required value="<?= h($subject) ?>">

      <label for="body">Message body</label>
      <p class="hint">
        Basic HTML is supported -- <code>&lt;b&gt;</code>,
        <code>&lt;i&gt;</code>, <code>&lt;u&gt;</code>,
        <code>&lt;br&gt;</code>, <code>&lt;p&gt;</code>,
        <code>&lt;ul&gt;</code>/<code>&lt;li&gt;</code> and
        <code>&lt;a href="..."&gt;</code>. Anything else is stripped
        before sending. Plain text is fine too: leave a blank line
        between paragraphs and it becomes a paragraph break
        automatically.
      </p>
      <textarea id="body" name="body" rows="22" required
                maxlength="<?= TEMPLATE_BODY_MAX ?>"><?= h($body) ?></textarea>
      <p class="hint"><span id="body-count"><?= mb_strlen($body) ?></span> / <?= number_format(TEMPLATE_BODY_MAX) ?> characters</p>

      <div class="actions">
        <a href="index.php" class="button-secondary">Cancel</a>
        <button type="submit" name="action" value="preview" class="button-secondary">Preview</button>
        <button type="submit" name="action" value="save">Save</button>
      </div>
    </form>

    <aside class="placeholder-panel">
      <h2>Placeholders</h2>
      <p class="hint">Click one to insert it at the cursor. They're replaced automatically when a message goes out.</p>
      <ul class="placeholder-list">
        <?php foreach ($placeholders as $key => $description): ?>
          <li>
            <button type="button" class="placeholder-insert" data-token="{{<?= h($key) ?>}}">{{<?= h($key) ?>}}</button>
            <span class="placeholder-desc"><?= h($description) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($type === 'outreach'): ?>
        <p class="hint">
          <strong>{{recipient_name}}</strong>, <strong>{{recipient_role}}</strong>
          and <strong>{{recipient_email}}</strong> are filled in separately
          for each person on the recipient list, right before sending --
          so they stay visible as literal text on the visitor's review
          page, and that's expected. Everything else is already resolved
          by the time the visitor sees the draft.
        </p>
      <?php else: ?>
        <p class="hint">
          This one goes to the visitor's own address after their messages
          are sent, so there are no per-recipient placeholders here.
        </p>
      <?php endif; ?>
    </aside>
  </div>

  <?php if ($preview): ?>
    <section class="preview-panel">
      <h2>Preview</h2>
      <p class="hint">
        Sample values are merged in, then the text is run through the
        same sanitizer used at send time -- so this is what a real
        message looks like, not a rough approximation. Nothing here is
        saved; use <em>Save</em> to keep the changes.
      </p>
      <div class="preview-field"><span class="preview-label">Subject:</span> <?= h($preview['subject']) ?></div>
      <iframe class="preview-frame" sandbox="" title="Rendered email preview"
              srcdoc="<?= h('<!DOCTYPE html><meta charset="utf-8"><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#222;margin:12px}</style>' . $preview['html']) ?>"></iframe>

      <details class="preview-plain">
        <summary>Plain-text version (what mail clients that don't render HTML will show)</summary>
        <pre><?= h($preview['text']) ?></pre>
      </details>
    </section>
  <?php endif; ?>
</main>

<script>
(function () {
  var body    = document.getElementById('body');
  var subject = document.getElementById('subject');
  var count   = document.getElementById('body-count');

  // Placeholders go into whichever field the admin was last typing in,
  // defaulting to the body -- inserting into the subject is rarer but
  // {{university}} there is a normal thing to want.
  var lastFocused = body;
  [body, subject].forEach(function (field) {
    field.addEventListener('focus', function () { lastFocused = field; });
  });

  body.addEventListener('input', function () {
    count.textContent = body.value.length;
  });

  document.querySelectorAll('.placeholder-insert').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var token = btn.dataset.token;
      var field = lastFocused;
      var start = field.selectionStart;
      var end   = field.selectionEnd;

      if (typeof start !== 'number') {
        field.value += token;
      } else {
        field.value = field.value.slice(0, start) + token + field.value.slice(end);
        field.selectionStart = field.selectionEnd = start + token.length;
      }

      field.focus();
      if (field === body) count.textContent = body.value.length;
    });
  });
})();
</script>
</body>
</html>
