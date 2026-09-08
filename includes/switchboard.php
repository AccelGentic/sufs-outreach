<?php
/**
 * Switchboard mail driver -- NOT YET IMPLEMENTED.
 *
 * I couldn't find clear, current documentation for a transactional
 * email API called "Switchboard" with the auth method and endpoint
 * details needed to wire this up correctly. Once you provide:
 *   - the base URL for sending a message
 *   - the auth method (API key header, Bearer token, or Basic auth)
 *   - whether it expects JSON or form-encoded fields
 * this file gets filled in with a send_via_switchboard() function
 * matching the same signature as send_via_mailgun() / send_via_smtp(),
 * so send.php doesn't need any further changes.
 *
 * Until then, setting MAIL_DRIVER = 'switchboard' in config.php will
 * fail cleanly (every recipient logged as "failed" with this message)
 * rather than crashing with a missing-function fatal error.
 */

function send_via_switchboard(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $plainTextBody,
    string $replyToEmail,
    string $replyToName,
    ?string $ccEmail = null
): array {
    return [
        'success' => false,
        'error'   => 'Switchboard driver not yet implemented -- see includes/switchboard.php',
    ];
}
