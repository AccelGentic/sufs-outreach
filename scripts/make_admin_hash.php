<?php
/**
 * Generates the ADMIN_PASSWORD_HASH value for config.php.
 *
 *   php scripts/make_admin_hash.php
 *
 * Prompts for the password (hidden where the terminal supports it),
 * prints the hash, and never writes it anywhere -- paste the line it
 * prints into config.php yourself. Passing the password as a command
 * line argument is deliberately not supported, since that would leave
 * it in your shell history and in the process list.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

function prompt_hidden(string $label): string
{
    echo $label;

    // stty is available on the Ubuntu target; if it isn't, fall back to
    // a visible prompt rather than failing outright.
    $hasStty = false;
    exec('stty -g 2>/dev/null', $sttyOut, $sttyStatus);
    if ($sttyStatus === 0 && $sttyOut) {
        $original = $sttyOut[0];
        $hasStty = true;
        shell_exec('stty -echo');
    }

    $value = trim((string) fgets(STDIN));

    if ($hasStty) {
        shell_exec('stty ' . escapeshellarg($original));
        echo "\n";
    }

    return $value;
}

$password = prompt_hidden('New admin password: ');
$confirm  = prompt_hidden('Repeat password:    ');

if ($password === '') {
    exit("Password cannot be empty.\n");
}
if ($password !== $confirm) {
    exit("Passwords did not match.\n");
}
if (strlen($password) < 12) {
    exit("Use at least 12 characters -- this single password is the only thing protecting the admin area.\n");
}

$hash = password_hash($password, PASSWORD_DEFAULT);

echo "\nAdd this line to config.php (replacing any existing ADMIN_PASSWORD_HASH):\n\n";
echo "define('ADMIN_PASSWORD_HASH', '" . str_replace("'", "\\'", $hash) . "');\n\n";
