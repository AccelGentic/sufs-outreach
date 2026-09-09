<?php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/template_store.php';
require_once __DIR__ . '/../../includes/csrf.php';

admin_session_start();
admin_send_headers();
require_admin();

$errors = $_SESSION['admin_errors'] ?? [];
$notice = $_SESSION['admin_notice'] ?? null;
unset($_SESSION['admin_errors'], $_SESSION['admin_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['admin_errors'] = ['Your session expired. Please try again.'];
        header('Location: index.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['template_id'] ?? 0);

    if ($action === 'activate') {
        if (activate_template($id)) {
            $_SESSION['admin_notice'] = 'That template is now the live one -- it takes effect on the next message generated.';
        } else {
            $_SESSION['admin_errors'] = ['That template no longer exists.'];
        }
    } elseif ($action === 'deactivate') {
        $error = deactivate_template($id);
        if ($error === null) {
            $_SESSION['admin_notice'] = 'Template deactivated.';
        } else {
            $_SESSION['admin_errors'] = [$error];
        }
    }

    header('Location: index.php');
    exit;
}

$templates = get_all_templates();

// Which row each type's send path will actually use -- resolved with
// the same rule get_active_template() applies, so the "Live" badge
// reflects reality even if several rows of a type are somehow active.
$liveIds = [];
foreach (array_keys(TEMPLATE_TYPES) as $type) {
    $live = get_active_template($type);
    if ($live) {
        $liveIds[$type] = (int) $live['id'];
    }
}

$byType = [];
foreach ($templates as $t) {
    $byType[$t['type']][] = $t;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Email Templates</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<?php require __DIR__ . '/../../includes/staging_banner.php'; ?>
<main class="card card-wide">
  <div class="admin-bar">
    <h1>Email Templates</h1>
    <form method="post" action="logout.php" class="inline-form">
      <?= csrf_field() ?>
      <button type="submit" class="button-secondary button-inline">Sign out</button>
    </form>
  </div>

  <p class="hint">
    The <strong>live</strong> template of each type is the one the app
    actually uses. Editing a template takes effect immediately for every
    message generated after the save; messages already drafted or sent
    are untouched.
  </p>

  <?php if ($notice): ?>
    <div class="alert alert-success"><?= h($notice) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-error">
      <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php foreach (TEMPLATE_TYPES as $type => $typeLabel): ?>
    <section class="template-group">
      <h2><?= h($typeLabel) ?></h2>

      <?php if (empty($byType[$type])): ?>
        <p class="hint">
          No <?= h($type) ?> template exists yet.
          <?php if ($type === 'outreach'): ?>
            Visitors will get an empty message body until one is created and activated.
          <?php else: ?>
            The confirmation step is skipped entirely while there's none active.
          <?php endif; ?>
        </p>
      <?php else: ?>
        <?php if (!isset($liveIds[$type])): ?>
          <div class="alert alert-error">
            None of these is active, so
            <?= $type === 'outreach'
                ? 'visitors are being handed an empty message body'
                : 'no confirmation email is being sent' ?>.
            Activate one below.
          </div>
        <?php endif; ?>

        <table class="template-table">
          <thead>
            <tr><th>Name</th><th>Subject</th><th>Last changed</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($byType[$type] as $t): ?>
            <?php $isLive = isset($liveIds[$type]) && $liveIds[$type] === (int) $t['id']; ?>
            <tr class="<?= $isLive ? 'is-live' : '' ?>">
              <td><a href="edit.php?id=<?= (int) $t['id'] ?>"><?= h($t['name']) ?></a></td>
              <td class="subject-cell"><?= h($t['subject']) ?></td>
              <td class="nowrap"><?= h($t['updated_at']) ?></td>
              <td>
                <?php if ($isLive): ?>
                  <span class="badge badge-live">Live</span>
                <?php elseif ((int) $t['active'] === 1): ?>
                  <span class="badge badge-shadowed" title="Marked active, but another active template of this type takes precedence">Active (superseded)</span>
                <?php else: ?>
                  <span class="badge">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="nowrap">
                <a class="button-secondary button-inline" href="edit.php?id=<?= (int) $t['id'] ?>">Edit</a>
                <?php if (!$isLive): ?>
                  <form method="post" action="index.php" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="activate">
                    <input type="hidden" name="template_id" value="<?= (int) $t['id'] ?>">
                    <button type="submit" class="button-secondary button-inline">Make live</button>
                  </form>
                <?php elseif ($type === 'confirmation'): ?>
                  <form method="post" action="index.php" class="inline-form"
                        onsubmit="return confirm('Turn off the confirmation email? Visitors will stop receiving a receipt after they send.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="deactivate">
                    <input type="hidden" name="template_id" value="<?= (int) $t['id'] ?>">
                    <button type="submit" class="button-secondary button-inline">Turn off</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <p>
        <a class="button-secondary button-inline" href="edit.php?new=<?= h($type) ?>">
          New <?= $type === 'outreach' ? 'outreach' : 'confirmation' ?> template
        </a>
      </p>
    </section>
  <?php endforeach; ?>

  <p class="hint">
    Need a new version of the live text without taking the current one
    down? Create a new template, get it reading right, then use
    <em>Make live</em> -- that switches the old one off in the same step,
    and it stays here as a record of what was being sent before.
  </p>
</main>
</body>
</html>
