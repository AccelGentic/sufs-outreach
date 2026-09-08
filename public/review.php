<?php
require_once __DIR__ . '/../config.php';
session_name(SESSION_NAME);
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

if (empty($_SESSION['draft']['submission_id'])) {
    header('Location: index.php');
    exit;
}

$submissionId = (int) $_SESSION['draft']['submission_id'];
$universityId = (int) $_SESSION['draft']['university_id'];

$db = get_db();
$stmt = $db->prepare('SELECT * FROM submissions WHERE id = ? AND status = "draft"');
$stmt->execute([$submissionId]);
$submission = $stmt->fetch();

if (!$submission) {
    unset($_SESSION['draft']);
    header('Location: index.php');
    exit;
}

$university = get_university($universityId);
$relationship = get_relationship((int) $submission['relationship_id']);
$contacts = get_active_contacts($universityId); // fresh from DB, read-only display only

$sendErrors = $_SESSION['send_errors'] ?? [];
unset($_SESSION['send_errors']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Review Your Message</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php require __DIR__ . '/../includes/staging_banner.php'; ?>
<main class="card">
  <h1>Review Your Message</h1>
  <p>
    Sending as <strong><?= h($submission['first_name'] . ' ' . $submission['last_name']) ?></strong>
    (<?= h($submission['email']) ?>), a <strong><?= h($relationship['name'] ?? '') ?></strong>
    of <strong><?= h($university['name']) ?></strong>.
  </p>

  <?php if ($sendErrors): ?>
    <div class="alert alert-error">
      <ul><?php foreach ($sendErrors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <section class="recipients">
    <h2>Recipients (<?= count($contacts) ?>)</h2>
    <p class="hint">This list is set by the site administrator and can't be edited here. Click a name to preview their personalized email.</p>
    <ul>
      <?php foreach ($contacts as $c): ?>
        <li>
          <button type="button" class="recipient-preview-link"
                  data-name="<?= h($c['name']) ?>"
                  data-role="<?= h($c['role']) ?>"
                  data-email="<?= h($c['email']) ?>">
            <?= h($c['name']) ?>
          </button>
          &mdash; <?= h($c['role']) ?> (<?= h($c['email']) ?>)
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <form action="send.php" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="submission_id" value="<?= (int) $submission['id'] ?>">

    <label for="subject">Subject</label>
    <input type="text" id="subject" name="subject" maxlength="255"
           value="<?= h($submission['subject']) ?>">

    <label for="email_body">Message (editable)</label>
    <p class="hint">
      If your message includes <code>{{recipient_name}}</code> or
      <code>{{recipient_role}}</code>, each person gets their own copy with
      their name and role filled in automatically. Remove those if you'd
      rather use one generic greeting for everyone. Basic HTML is supported
      for formatting -- <code>&lt;b&gt;</code>, <code>&lt;i&gt;</code>,
      <code>&lt;u&gt;</code>, <code>&lt;br&gt;</code>, <code>&lt;p&gt;</code>,
      <code>&lt;ul&gt;</code>/<code>&lt;li&gt;</code>, and
      <code>&lt;a href="..."&gt;</code> for links; anything else typed in
      is stripped out before sending. Leave a blank line between
      paragraphs and it'll become a proper paragraph break automatically.
    </p>
    <textarea id="email_body" name="email_body" rows="14"><?= h($submission['email_body']) ?></textarea>

    <div class="actions">
      <a href="index.php" class="button-secondary">Start over</a>
      <button type="submit">Send Email</button>
    </div>
  </form>

  <dialog id="preview-dialog">
    <h2>Preview for <span id="preview-recipient-name"></span></h2>
    <p class="hint">
      This is a live preview using whatever's currently in the Subject and
      Message boxes above, rendered the same way it'll actually be sent
      (basic HTML formatting shown, anything else stripped) -- reopen it
      after editing to see the update.
    </p>
    <div class="preview-field"><span class="preview-label">To:</span> <span id="preview-to"></span></div>
    <div class="preview-field"><span class="preview-label">Subject:</span> <span id="preview-subject"></span></div>
    <div id="preview-body"></div>
    <button type="button" id="preview-close" class="button-secondary">Close</button>
  </dialog>
</main>

<script>
(function () {
  // Mirrors send.php's per-recipient merge: literal {{key}} tokens in the
  // current draft get swapped for this one recipient's own values. Only
  // recipient-side placeholders are handled here -- sender-side ones
  // ({{first_name}}, {{university}}, etc.) are already resolved into real
  // text by the time this page loads, so there's nothing left to merge
  // for those.
  function renderTemplate(text, vars) {
    Object.keys(vars).forEach(function (key) {
      text = text.split('{{' + key + '}}').join(vars[key]);
    });
    return text;
  }

  // Mirrors auto_paragraph_html() in includes/functions.php: a blank
  // line becomes a new <p>, a single newline within a block becomes
  // <br>. Skipped if the text already has a <p>/<ul>/<ol> -- that's a
  // deliberate structure, so this shouldn't second-guess it.
  function autoParagraphHtml(html) {
    if (/<(p|ul|ol)\b/i.test(html)) return html;
    var normalized = html.replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
    var blocks = normalized.split(/\n\s*\n+/);
    return blocks.map(function (block) {
      return '<p>' + block.trim().replace(/\n/g, '<br>') + '</p>';
    }).join('');
  }

  // Mirrors sanitize_email_html() in includes/functions.php closely
  // enough for preview purposes: only these tag names survive, every
  // attribute is stripped except a validated href on <a>. Uses the
  // browser's own HTML parser (via a detached element) rather than
  // regexes, so it handles real-world markup correctly.
  var ALLOWED_TAGS = ['B', 'STRONG', 'I', 'EM', 'U', 'BR', 'P', 'UL', 'OL', 'LI', 'A'];

  function sanitizeEmailHtml(html) {
    html = autoParagraphHtml(html);

    var container = document.createElement('div');
    container.innerHTML = html;

    function clean(node) {
      Array.prototype.slice.call(node.childNodes).forEach(function (child) {
        if (child.nodeType === 1) {
          clean(child);
          if (ALLOWED_TAGS.indexOf(child.tagName) === -1) {
            while (child.firstChild) node.insertBefore(child.firstChild, child);
            node.removeChild(child);
            return;
          }
          var href = child.getAttribute('href');
          Array.prototype.slice.call(child.attributes).forEach(function (attr) {
            child.removeAttribute(attr.name);
          });
          if (child.tagName === 'A' && href && /^(https?:\/\/|mailto:)/i.test(href)) {
            child.setAttribute('href', href);
          }
        } else if (child.nodeType !== 3) {
          node.removeChild(child);
        }
      });
    }

    clean(container);
    return container.innerHTML;
  }

  function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  var dialog = document.getElementById('preview-dialog');
  document.getElementById('preview-close').addEventListener('click', function () {
    dialog.close();
  });

  document.querySelectorAll('.recipient-preview-link').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var name = btn.dataset.name;
      var role = btn.dataset.role;
      var email = btn.dataset.email;

      // Plain variant for the subject (never parsed as markup); escaped
      // variant for the HTML body, so a name/role containing "&" or "<"
      // can't be mistaken for markup once it's substituted in.
      var plainVars = { recipient_name: name, recipient_role: role, recipient_email: email };
      var htmlVars = {
        recipient_name: escapeHtml(name),
        recipient_role: escapeHtml(role),
        recipient_email: escapeHtml(email)
      };

      // Same subject sanitization send.php applies (strip line breaks)
      // before merging, so the preview matches what actually gets sent.
      var rawSubject = document.getElementById('subject').value.replace(/[\r\n]+/g, ' ').trim();
      var rawBody = document.getElementById('email_body').value;

      document.getElementById('preview-recipient-name').textContent = name;
      document.getElementById('preview-to').textContent = name + ' <' + email + '>';
      document.getElementById('preview-subject').textContent = renderTemplate(rawSubject, plainVars);
      document.getElementById('preview-body').innerHTML = sanitizeEmailHtml(renderTemplate(rawBody, htmlVars));

      dialog.showModal();
    });
  });
})();
</script>
</body>
</html>
