<?php
require_once __DIR__ . '/../config.php';
session_name(SESSION_NAME);
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: index.php');
    exit;
}

$first_name      = trim($_POST['first_name'] ?? '');
$last_name       = trim($_POST['last_name'] ?? '');
$email           = trim($_POST['email'] ?? '');
$university_id   = (int) ($_POST['university_id'] ?? 0);
$relationship_id = (int) ($_POST['relationship_id'] ?? 0);
$address         = trim($_POST['address'] ?? '');

$errors = [];

if ($first_name === '' || mb_strlen($first_name) > 100) {
    $errors[] = 'First name is required.';
}
if ($last_name === '' || mb_strlen($last_name) > 100) {
    $errors[] = 'Last name is required.';
}
if ($email === '' || !is_valid_email($email)) {
    $errors[] = 'A valid email address is required.';
}

// One university selection now drives both "who am I related to" and
// "who is being contacted" -- the recipient list comes from this same ID.
$university = $university_id ? get_university($university_id) : null;
if (!$university) {
    $errors[] = 'Please select a valid educational institution.';
}

$relationship = $relationship_id ? get_relationship($relationship_id) : null;
if (!$relationship) {
    $errors[] = 'Please select your relationship to the educational institution.';
}

if (mb_strlen($address) > 500) {
    $errors[] = 'Address is too long.';
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!$errors && too_many_recent_submissions($email, $ip)) {
    $errors[] = 'Too many submissions recently from this email or network. Please try again later.';
}

if ($errors) {
    $_SESSION['form_errors'] = $errors;
    $_SESSION['form_old'] = $_POST;
    header('Location: index.php');
    exit;
}

$contacts = get_active_contacts($university['id']);
if (!$contacts) {
    $_SESSION['form_errors'] = ['There are no contacts on file for that educational institution yet. Please contact the site administrator.'];
    $_SESSION['form_old'] = $_POST;
    header('Location: index.php');
    exit;
}

$template = get_active_template();
$vars = [
    'first_name'   => $first_name,
    'last_name'    => $last_name,
    'sender_email' => $email,
    'relationship' => $relationship['name'],
    'university'   => $university['name'],
    'address'      => $address,
];

$subject = $template ? render_template($template['subject'], $vars) : ('Regarding ' . $university['name']);
$body    = $template ? render_template($template['body'], $vars) : '';

$db = get_db();
$stmt = $db->prepare(
    'INSERT INTO submissions
        (first_name, last_name, email, relationship_id, university_id, address, subject, email_body, status, mode, ip_address)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, "draft", ?, ?)'
);
$stmt->execute([$first_name, $last_name, $email, $relationship['id'], $university['id'], $address ?: null, $subject, $body, current_app_mode(), $ip]);
$submissionId = (int) $db->lastInsertId();

// The draft lives in the session. Recipients are always re-read from the
// database by university_id on later steps -- never taken from client
// input -- so this session data cannot be used to redirect mail elsewhere.
$_SESSION['draft'] = [
    'submission_id' => $submissionId,
    'university_id' => $university['id'],
];

header('Location: review.php');
exit;
