<?php
/**
 * CLI importer for university contacts.
 *
 * Usage:
 *   php import_contacts.php /path/to/contacts.csv
 *
 * Expects a CSV with a header row containing (in any order):
 *   college/uni, name, role, email
 * (column names are normalized, so "College/Uni", "college_uni", etc.
 * all match).
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
$insertUniversity = $db->prepare('INSERT INTO universities (name) VALUES (?)');
$insertContact    = $db->prepare(
    'INSERT INTO university_contacts (university_id, name, role, email) VALUES (?, ?, ?, ?)'
);

$universityIdCache = [];
$imported = 0;
$skipped  = 0;

$fh = fopen($path, 'r');
$header = fgetcsv($fh);
if ($header === false) {
    fwrite(STDERR, "Could not read a header row from $path\n");
    exit(1);
}
$header = array_map('normalize_header', $header);
$col = array_flip($header); // column name -> index

$requiredCols = ['college_uni', 'name', 'role', 'email'];
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

    $insertContact->execute([$universityIdCache[$collegeUni], $name, $role, $email]);
    $imported++;
}

fclose($fh);

echo "Imported: $imported, Skipped: $skipped\n";
