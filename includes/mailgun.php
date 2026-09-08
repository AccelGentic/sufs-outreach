<?php
/**
 * Minimal Mailgun HTTP API client -- just PHP's curl extension
 * (already enabled by the install script), no SDK/Composer needed.
 * https://documentation.mailgun.com/en/latest/api-sending.html
 */

function send_via_mailgun(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $plainTextBody,
    string $replyToEmail,
    string $replyToName,
    ?string $ccEmail = null
): array {
    $url = rtrim(MAILGUN_API_BASE_URL, '/') . '/' . MAILGUN_DOMAIN . '/messages';

    $fields = [
        'from'       => MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . '>',
        'to'         => $toName !== '' ? "$toName <$toEmail>" : $toEmail,
        'subject'    => $subject,
        'html'       => $htmlBody,
        'text'       => $plainTextBody,
        'h:Reply-To' => $replyToName !== '' ? "$replyToName <$replyToEmail>" : $replyToEmail,
    ];
    if ($ccEmail) {
        $fields['cc'] = $ccEmail;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_USERPWD        => 'api:' . MAILGUN_API_KEY,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        return ['success' => false, 'error' => 'cURL error: ' . $curlError];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'error' => null];
    }

    $message = (string) $response;
    $decoded = json_decode($message, true);
    if (is_array($decoded) && isset($decoded['message'])) {
        $message = $decoded['message'];
    }

    return ['success' => false, 'error' => "HTTP $httpCode: $message"];
}
