<?php
require_once __DIR__ . '/../config.php';
session_name(SESSION_NAME);
session_start();
require_once __DIR__ . '/../includes/functions.php';

$confirm = $_SESSION['confirm'] ?? null;
unset($_SESSION['confirm']);

if (!$confirm) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Message Sent</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php require __DIR__ . '/../includes/staging_banner.php'; ?>
<main class="card">
  <h1>Thank you!</h1>
  <?php if (current_app_mode() === 'staging'): ?>
    <p class="hint">Since this is staging mode, every message above was redirected to the test contacts in <code>staging_contacts</code> instead of the real recipients -- the count below reflects those test deliveries, not real recipients.</p>
  <?php endif; ?>
  <?php if ($confirm['sent'] > 0): ?>
<p>Thank you for emailing your school’s leadership and urging them to endorse SUFS America’s Compact for Higher Education.
<p>
Please consider sharing the link to <a href="https://www.standupforscience.net/sufs-compact-higher-education" target="_blank">SUFS America's Compact</a> with colleagues, friends and other members of your school’s community. 
<p>
Finally, we invite you to join the growing number of science-saving activists who support our work by making a <a href="https://secure.qgiv.com/for/fuel-sufs-americas-compact-campaign/" target="_blank">contribution to SUFS</a>. 

  <?php endif; ?>
  <?php if ($confirm['failed'] > 0): ?>
    <p class="alert alert-error"><?= (int) $confirm['failed'] ?> message(s) could not be delivered. The site administrator can see details in the send log.</p>
  <?php endif; ?>
  <p><a href="index.php">Contact another educational institution</a></p>
</main>
</body>
</html>
