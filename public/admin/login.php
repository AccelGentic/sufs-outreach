<?php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/csrf.php';

admin_session_start();
admin_send_headers();

if (admin_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$errors = [];
$ip = admin_client_ip();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!admin_is_configured()) {
        $errors[] = 'The admin area has not been set up yet. Run scripts/make_admin_hash.php and put the resulting ADMIN_PASSWORD_HASH in config.php.';
    } elseif (admin_login_locked_out($ip)) {
        $errors[] = 'Too many failed attempts. Try again in ' . admin_lockout_minutes() . ' minutes.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        if ($password !== '' && password_verify($password, ADMIN_PASSWORD_HASH)) {
            admin_record_login_attempt($ip, true);
            admin_clear_login_attempts($ip);
            admin_login();
            header('Location: index.php');
            exit;
        }

        admin_record_login_attempt($ip, false);
        // Same message whether the password was empty or simply wrong --
        // nothing here should help someone probe for a valid one.
        $errors[] = 'Incorrect password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin Sign In</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<main class="card card-narrow">
  <h1>Admin Sign In</h1>
  <p class="hint">Editing the email templates for this outreach tool.</p>

  <?php if (!admin_is_configured()): ?>
    <div class="alert alert-error">
      No admin password is configured yet. On the server, run
      <code>php scripts/make_admin_hash.php</code> and paste the
      <code>ADMIN_PASSWORD_HASH</code> line it prints into
      <code>config.php</code>.
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" action="login.php">
    <?= csrf_field() ?>
    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password" autofocus required>
    <button type="submit">Sign in</button>
  </form>
</main>
</body>
</html>
