<?php
/**
 * Removes duplicate rows from university_contacts.
 *
 * Usage:
 *   php dedupe_contacts.php            # report what would change, change nothing
 *   php dedupe_contacts.php --apply    # actually merge and delete
 *
 * Duplicates arise because import_contacts.php inserts unconditionally:
 * re-running it, or importing two CSVs that overlap, adds the same
 * person again. The visible symptom is a recipient receiving the same
 * message two or three times.
 *
 * WHAT COUNTS AS A DUPLICATE
 * Two rows with the same university_id and the same email address,
 * compared case-insensitively and with surrounding whitespace ignored.
 * That is the definition that matches the harm: one mailbox at one
 * institution receiving one message per row. Name and role are
 * deliberately NOT part of the key -- "Dr. Alex Chen" and "Alex Chen"
 * are the same person, but no rule can tell that reliably, so this
 * script reports differing names/roles inside a group instead of
 * guessing. The same address at two different universities is left
 * alone; that is one person holding two posts, not a duplicate.
 *
 * WHICH ROW SURVIVES
 * The lowest id -- the earliest imported row. It keeps the original
 * created_at, and it is usually the one already referenced by
 * email_log, so fewer log rows have to be repointed.
 *
 * THE ACTIVE FLAG IS TREATED AS AN OPT-OUT
 * If any row in a duplicate group has active = 0, the surviving row is
 * set to 0 as well. `active = 0` is how this app records "stop emailing
 * this person" (see the README), so a merge that resurrected an
 * opted-out address would resume mailing someone who asked not to be
 * mailed. Silently not emailing someone is the cheaper mistake of the
 * two. Every instance is reported, so anything that was actually just a
 * stale row can be reactivated by hand afterwards.
 *
 * EMAIL_LOG IS REPOINTED, NEVER ORPHANED
 * email_log.contact_id references university_contacts(id) with no
 * ON DELETE clause, so MySQL RESTRICTs the delete: any contact that has
 * ever been sent a message cannot simply be removed. Its log rows are
 * moved onto the surviving contact first, inside the same transaction,
 * which both satisfies the constraint and keeps the audit trail intact
 * -- the history belongs to the person, and these rows are the same
 * person.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

$apply = in_array('--apply', $argv, true);

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo "Usage: php dedupe_contacts.php [--apply]\n\n";
    echo "  (no flags)  Report duplicates and what would change. Changes nothing.\n";
    echo "  --apply     Merge each duplicate group down to one row.\n";
    exit(0);
}

$db = get_db();

/**
 * The importer writes a `source` column that sql/schema.sql doesn't
 * declare, so it exists on some databases and not others. Probe rather
 * than assume, so this runs against either. The table and column names
 * here are literals in this file, never input, so interpolating them is
 * safe.
 */
function table_has_column(PDO $db, string $table, string $column): bool
{
    try {
        $db->query("SELECT $column FROM $table LIMIT 0");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

$hasSource = table_has_column($db, 'university_contacts', 'source');

$columns = 'c.id, c.university_id, c.name, c.role, c.email, c.active'
    . ($hasSource ? ', c.source' : '');

$rows = $db->query(
    "SELECT $columns, u.name AS university_name
     FROM university_contacts c
     JOIN universities u ON u.id = c.university_id
     ORDER BY c.university_id ASC, c.id ASC"
)->fetchAll();

/**
 * Grouped in PHP rather than with GROUP BY: the normalisation applied
 * here is then the single definition of "same address", instead of
 * being split between SQL's collation rules and this script's.
 */
$groups = [];
foreach ($rows as $row) {
    $key = $row['university_id'] . '|' . mb_strtolower(trim((string) $row['email']));
    $groups[$key][] = $row;
}

$duplicateGroups = array_values(array_filter($groups, fn (array $g): bool => count($g) > 1));

if (!$duplicateGroups) {
    echo "No duplicate contacts found (" . count($rows) . " rows checked).\n";
    exit(0);
}

// How many log rows point at each row that's about to go away.
$countLog = $db->prepare('SELECT COUNT(*) FROM email_log WHERE contact_id = ?');

$totalRemove = 0;
$totalLogMoves = 0;
$optOutGroups = 0;
$plan = [];

foreach ($duplicateGroups as $group) {
    $keep = $group[0];                    // lowest id -- the list is ordered
    $remove = array_slice($group, 1);

    $anyInactive = false;
    foreach ($group as $row) {
        if ((int) $row['active'] === 0) {
            $anyInactive = true;
        }
    }
    $forceInactive = $anyInactive && (int) $keep['active'] === 1;
    if ($forceInactive) {
        $optOutGroups++;
    }

    $logMoves = 0;
    foreach ($remove as $row) {
        $countLog->execute([$row['id']]);
        $logMoves += (int) $countLog->fetchColumn();
    }

    $totalRemove += count($remove);
    $totalLogMoves += $logMoves;

    $plan[] = [
        'keep'          => $keep,
        'remove'        => $remove,
        'forceInactive' => $forceInactive,
        'logMoves'      => $logMoves,
    ];
}

// ---- Report ----------------------------------------------------------

function describe(array $row, bool $hasSource): string
{
    $line = sprintf(
        '#%-6s %s <%s>',
        $row['id'],
        $row['name'] !== '' ? $row['name'] : '(no name)',
        $row['email']
    );
    $line .= ' -- ' . ($row['role'] !== '' ? $row['role'] : '(no role)');
    if ((int) $row['active'] === 0) {
        $line .= ' [inactive]';
    }
    if ($hasSource && ($row['source'] ?? '') !== '') {
        $line .= ' {source: ' . $row['source'] . '}';
    }
    return $line;
}

echo ($apply ? "Applying" : "Dry run") . " -- "
    . count($duplicateGroups) . " duplicate group(s) across " . count($rows) . " contacts\n\n";

foreach ($plan as $entry) {
    $keep = $entry['keep'];
    echo $keep['university_name'] . "\n";
    echo '  keep    ' . describe($keep, $hasSource) . "\n";

    foreach ($entry['remove'] as $row) {
        echo '  remove  ' . describe($row, $hasSource);
        // Anything the operator might want to preserve by hand before
        // the row disappears.
        $diffs = [];
        if (trim((string) $row['name']) !== trim((string) $keep['name'])) {
            $diffs[] = 'name';
        }
        if (trim((string) $row['role']) !== trim((string) $keep['role'])) {
            $diffs[] = 'role';
        }
        if ($hasSource && ($row['source'] ?? '') !== ($keep['source'] ?? '')) {
            $diffs[] = 'source';
        }
        if ($diffs) {
            echo '  (differs from the kept row: ' . implode(', ', $diffs) . ')';
        }
        echo "\n";
    }

    if ($entry['logMoves'] > 0) {
        echo '  ' . $entry['logMoves'] . " send-log row(s) move to #" . $keep['id'] . "\n";
    }
    if ($entry['forceInactive']) {
        echo "  ! this group contains an inactive row, so #" . $keep['id']
            . " will be set inactive too (treating it as an opt-out)\n";
    }
    echo "\n";
}

echo "Summary: remove $totalRemove row(s), repoint $totalLogMoves send-log row(s)";
echo $optOutGroups > 0 ? ", deactivate $optOutGroups surviving row(s)\n" : "\n";

if (!$apply) {
    echo "\nNothing was changed. Re-run with --apply to make these changes.\n";
    echo "Take a backup first:  mysqldump " . DB_NAME . " university_contacts email_log > backup.sql\n";
    exit(0);
}

// ---- Apply -----------------------------------------------------------

$db->beginTransaction();
try {
    $moveLog     = $db->prepare('UPDATE email_log SET contact_id = ? WHERE contact_id = ?');
    $deactivate  = $db->prepare('UPDATE university_contacts SET active = 0 WHERE id = ?');

    $removedTotal = 0;
    $movedTotal = 0;

    foreach ($plan as $entry) {
        $keepId = (int) $entry['keep']['id'];
        $removeIds = array_map(fn (array $r): int => (int) $r['id'], $entry['remove']);

        // Must happen before the delete: the FK is RESTRICT, so a
        // contact with log rows can't be removed while they point at it.
        foreach ($removeIds as $id) {
            $moveLog->execute([$keepId, $id]);
            $movedTotal += $moveLog->rowCount();
        }

        if ($entry['forceInactive']) {
            $deactivate->execute([$keepId]);
        }

        $placeholders = implode(',', array_fill(0, count($removeIds), '?'));
        $del = $db->prepare("DELETE FROM university_contacts WHERE id IN ($placeholders)");
        $del->execute($removeIds);
        $removedTotal += $del->rowCount();
    }

    $db->commit();
    echo "\nDone: removed $removedTotal contact row(s), repointed $movedTotal send-log row(s).\n";
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "\nFailed, rolled back -- nothing was changed.\n" . $e->getMessage() . "\n");
    exit(1);
}

echo "\nTo stop duplicates coming back, add a uniqueness constraint:\n";
echo "  ALTER TABLE university_contacts ADD UNIQUE KEY uniq_contact_email (university_id, email);\n";
echo "and have import_contacts.php use INSERT ... ON DUPLICATE KEY UPDATE.\n";
echo "Note the constraint compares email under the column's collation, which is\n";
echo "case-insensitive for utf8mb4_unicode_ci -- matching how this script compares.\n";
