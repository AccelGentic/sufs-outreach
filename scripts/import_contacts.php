<?php
/**
 * CLI importer for university contacts.
 *
 * Usage:
 *   php import_contacts.php /path/to/contacts.csv
 *
 * Expects a CSV with a header row containing (in any order):
 *   college/uni, name, role, email, source
 * (column names are normalized, so "College/Uni", "college_uni", etc.
 * all match).
 *
 * Safe to re-run: contacts are matched on (university_id, email) and
 * updated in place rather than duplicated, provided the unique key from
 * sql/migrations/003_contacts_unique_email.sql is in place. If you are
 * adding that key to a database that already has duplicates, run
 * scripts/dedupe_contacts.php --apply first.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script may only be run from the command line.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

function normalize_header(string $h): string
{
    $h = strtolower(trim($h));
    $h = preg_replace('/[^a-z0-9]+/', '_', $h);
    return trim($h, '_');
}

function csv_val(array $row, array $col, string $key): string
{
    return isset($col[$key], $row[$col[$key]]) ? trim((string) $row[$col[$key]]) : '';
}

$path = $argv[1] ?? null;
if (!$path || !is_readable($path)) {
    fwrite(STDERR, "Usage: php import_contacts.php /path/to/contacts.csv\n");
    exit(1);
}

$db = get_db();

$findUniversity   = $db->prepare('SELECT id FROM universities WHERE name = ?');
$findContact      = $db->prepare(
    'SELECT id FROM university_contacts WHERE university_id = ? AND email = ?'
);
$insertUniversity = $db->prepare('INSERT INTO universities (name) VALUES (?)');
/**
 * Upsert against uniq_contact_email (university_id, email), so
 * re-running an import -- or importing two lists that overlap --
 * refreshes a contact instead of adding them a second time and mailing
 * them twice.
 *
 * `active` is deliberately absent from the UPDATE list. It is how this
 * app records "stop emailing this person", so letting an import set it
 * back to 1 would silently resume mail to someone who opted out. A
 * re-import can correct a name, role or source; it cannot un-unsubscribe
 * anyone. Reactivating is a deliberate act, done by hand.
 *
 * Needs the unique key to exist -- see
 * sql/migrations/003_contacts_unique_email.sql. Without it this
 * statement is a plain INSERT and duplicates come back.
 */
$insertContact    = $db->prepare(
    'INSERT INTO university_contacts (university_id, name, role, email, source)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        name   = VALUES(name),
        role   = VALUES(role),
        source = VALUES(source)'
);

$universityIdCache = [];
$imported = 0;
$updated  = 0;
$skipped  = 0;

$fh = fopen($path, 'r');
$header = fgetcsv($fh);
if ($header === false) {
    fwrite(STDERR, "Could not read a header row from $path\n");
    exit(1);
}
$header = array_map('normalize_header', $header);
$col = array_flip($header); // column name -> index

$requiredCols = ['college_uni', 'name', 'role', 'email', 'source'];
foreach ($requiredCols as $rc) {
    if (!isset($col[$rc])) {
        fwrite(STDERR, "Missing expected column '$rc' in CSV header (found: " . implode(', ', $header) . ")\n");
        exit(1);
    }
}

while (($row = fgetcsv($fh)) !== false) {
    $collegeUni = csv_val($row, $col, 'college_uni');
    $name       = csv_val($row, $col, 'name');
    $role       = csv_val($row, $col, 'role');
    $email      = csv_val($row, $col, 'email');
    $source	= csv_val($row, $col, 'source');

    if ($collegeUni === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $skipped++;
        continue;
    }

    if (!isset($universityIdCache[$collegeUni])) {
        $findUniversity->execute([$collegeUni]);
        $existing = $findUniversity->fetch();
        if ($existing) {
            $universityIdCache[$collegeUni] = (int) $existing['id'];
        } else {
            $insertUniversity->execute([$collegeUni]);
            $universityIdCache[$collegeUni] = (int) $db->lastInsertId();
        }
    }

    // Look the contact up first purely so the summary can say whether
    // each row was new or a refresh. Reading affected-rows back from the
    // upsert instead would be one query fewer, but its 1/2/0 convention
    // is specific to MySQL's driver, and a miscounted summary that
    // reports every refreshed contact as newly imported is exactly the
    // reassurance you don't want when checking whether a re-import
    // behaved. This matches on the same (university_id, email) the
    // unique key does, under the same collation.
    $findContact->execute([$universityIdCache[$collegeUni], $email]);
    $isExisting = (bool) $findContact->fetchColumn();

    $insertContact->execute([$universityIdCache[$collegeUni], $name, $role, $email, $source]);

    if ($isExisting) {
        $updated++;
    } else {
        $imported++;
    }
}

fclose($fh);

echo "Imported: $imported new, Updated: $updated existing, Skipped: $skipped\n";
