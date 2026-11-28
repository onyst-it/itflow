<?php

use DirectoryTree\ImapEngine\MessageParser;
use ZBateson\MailMimeParser\Header\AddressHeader;

/** The sender is distinct from the ticket's editable contacts and watchers. */
function onystTicketEmailSender(array $ticket): array
{
    global $mysqli;

    $empty = ['name' => '', 'email' => ''];
    if (($ticket['ticket_source'] ?? null) !== 'Email') {
        return $empty;
    }

    $ticket_id = (int) $ticket['ticket_id'];
    $original = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_attachment_reference_name
        FROM ticket_attachments
        WHERE ticket_attachment_ticket_id = $ticket_id
          AND (ticket_attachment_reply_id IS NULL OR ticket_attachment_reply_id = 0)
          AND ticket_attachment_name = 'Original-parsed-email.eml'
          AND ticket_attachment_private = 0
        ORDER BY ticket_attachment_id LIMIT 1"));
    $reference = $original['ticket_attachment_reference_name'] ?? '';

    if (preg_match('/\Aprocessed-eml-[A-Za-z0-9_-]+\.eml\z/', $reference)) {
        $root = realpath(__DIR__ . '/../uploads/tickets');
        if ($root !== false) {
            $directory = $root . DIRECTORY_SEPARATOR . $ticket_id;
            $file = realpath($directory . DIRECTORY_SEPARATOR . $reference);
            if ($file !== false && dirname($file) === $directory && is_file($file)) {
                $contents = @file_get_contents($file, false, null, 0, 128 * 1024);
                if (is_string($contents) && preg_match('/\r?\n\r?\n/', $contents, $boundary, PREG_OFFSET_CAPTURE)) {
                    return onystEmailSenderFromHeader(substr($contents, 0, $boundary[0][1]));
                }
            }
        }
    }

    // The parser also prepended this exact attribution to older ticket bodies.
    // It remains available when the original attachment has been removed.
    $details = substr((string) ($ticket['ticket_details'] ?? ''), 0, 8192);
    if (preg_match('/\A<i>Email from: <b>(.*?)<\/b> &lt;([^<>\r\n]*)&gt; at /s', $details, $matches)) {
        $name = trim(html_entity_decode(strip_tags(stripslashes($matches[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $email = trim(html_entity_decode(stripslashes($matches[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return [
            'name' => $name === 'Unknown' ? '' : $name,
            'email' => $email !== 'itflow-guest@example.com' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
        ];
    }

    return $empty;
}

function onystEmailSenderFromHeader(string $headers): array
{
    require_once __DIR__ . '/../libs/vendor/autoload.php';

    try {
        $from = MessageParser::parse($headers . "\r\n\r\n")->getHeader('from');
        if ($from instanceof AddressHeader) {
            $email = trim((string) $from->getEmail());

            return [
                'name' => trim((string) $from->getPersonName()),
                'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
            ];
        }
    } catch (Throwable $error) {
        // A malformed archive can never prevent an agent from opening the ticket.
    }

    return ['name' => '', 'email' => ''];
}
