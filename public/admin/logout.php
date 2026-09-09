<?php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

admin_session_start();
admin_send_headers();

// POST-only, with a CSRF token: a plain <a href="logout.php"> could be
// triggered by any page that manages to get the browser to load that
// URL (an <img> tag is enough), which is a nuisance rather than a
// danger, but it costs nothing to do properly.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    admin_logout();
}

header('Location: login.php');
exit;
