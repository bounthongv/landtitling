<?php
/**
 * Outbound email seam.
 *
 * Delivery uses PHP's mail(), which hands off to the system MTA (Postfix on the
 * VPS). That means the server needs a working MTA before OTPs can be delivered —
 * see docs. Nothing here throws: a failed send returns false and is logged, so a
 * mail outage can never take the API down.
 */

function mail_is_configured(): bool
{
    $cfg = require __DIR__ . '/config.php';
    return !empty($cfg['mail_from']);
}

/**
 * Send a simple UTF-8 HTML email.
 * @return bool true if the MTA accepted the message.
 */
function mail_send(string $to, string $subject, string $htmlBody): bool
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('mail_send: invalid recipient');
        return false;
    }
    if (!mail_is_configured()) {
        error_log('mail_send: MAIL_FROM not configured; dropping mail to ' . $to);
        return false;
    }
    $cfg = require __DIR__ . '/config.php';
    $fromName = $cfg['mail_from_name'] ?: 'Land Fee';

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $fromName . ' <' . $cfg['mail_from'] . '>',
        'Reply-To: ' . $cfg['mail_from'],
        'X-Mailer: PHP/' . PHP_VERSION,
    ];

    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, implode("\r\n", $headers));
    if (!$ok) {
        error_log('mail_send: delivery failed for ' . $to);
    }
    return $ok;
}