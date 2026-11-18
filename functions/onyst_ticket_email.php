<?php
/** ONYST ticket email branding, matched to Syncro comment_body and HTML wrapper. */
function onystUnescapeMailSql($value) {
    return strtr($value, ['\\0' => chr(0), '\\n' => chr(10), '\\r' => chr(13), '\\Z' => chr(26), '\\\\' => '\\', "\\'" => "'", '\\"' => '"']);
}
function onystStripQuotedTicketEmail($message) {
    return preg_replace('/(?:<(?:span|i)\b[^>]*>\s*)?(?:-{5}\s*REPLY\s+ABOVE\s+THIS\s+LINE\s+TO\s+SEND\s+A\s+RESPONSE\s*-{5}|##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##).*$/is', '', $message);
}
/** One event label supplies both the subject and the heading. */
function onystTicketEmailDetails($subject) {
    if (!preg_match('/^Ticket (update|updated|resolved) - \[([^\]]+)\] - (.+?)(?: \| \(pending closure\))?$/is', $subject, $matches)) {
        return null;
    }
    return [
        'label' => strtolower($matches[1]) === 'resolved' ? 'Ticket Resolved' : 'Ticket Updated',
        'number' => $matches[2],
        'title' => $matches[3],
    ];
}
function onystTicketEmailSubject($subject) {
    $details = onystTicketEmailDetails($subject);
    if ($details === null) return $subject;
    return preg_replace('/^Ticket (?:update|updated|resolved)(?= - \[)/i', $details['label'], $subject, 1);
}
function onystCleanTicketEmailBody($body) {
    $body = preg_replace('/<i\b[^>]*>##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##<\/i>(?:<br>){0,2}/i', '', $body, 1);
    return preg_replace('/<br><br>--<br>.*?(?=<br><br>----------------------------------------<br>YOU ARE A COLLABORATOR|$)/s', '', $body);
}
function onystFormatTicketEmail($body, $subject) {
    $legacyMarker = '##- Please type your reply above this line -##';
    if (strpos($body, 'data-onyst-ticket-email="1"') !== false || strpos($body, $legacyMarker) === false) {
        return $body;
    }
    $content = onystCleanTicketEmailBody($body);
    $details = onystTicketEmailDetails($subject);
    if ($details !== null) {
        $watcherMarker = '<br><br>----------------------------------------<br>YOU ARE A COLLABORATOR ON THIS TICKET';
        $watcher = strpos($body, $watcherMarker) !== false;
        $content = str_replace($watcherMarker, '', $content);
        $opening = '<br><br>--------------------------------<br>';
        $closing = '<br>--------------------------------<br><br>';
        $start = strpos($body, $opening);
        $end = strrpos($body, $closing);
        $resolutionInstructions = '';
        if ($start !== false && $end !== false && $end > $start) {
            $content = substr($body, $start + strlen($opening), $end - $start - strlen($opening));
            if ($details['label'] === 'Ticket Resolved') {
                $tail = substr($body, $end + strlen($closing));
                $metadata = strpos($tail, '<br><br>Ticket: ');
                if ($metadata !== false) $resolutionInstructions = '<p>' . substr($tail, 0, $metadata) . '</p>';
            }
        }
        // Resolve without a reply (including bulk resolution) has no comment delimiters.
        // Keep its existing resolution/reopen content inside the same heading and rules.
        $number = htmlspecialchars($details['number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = htmlspecialchars($details['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $content = '<p style="text-align: center;"><strong>' . $details['label'] . '</strong> &ndash; Ticket # ' . $number . ': ' . $title . '</p>'
            . '<hr style="margin-bottom: 25px;" />' . $content . '<hr style="margin-top: 25px;margin-bottom: 25px;" />' . $resolutionInstructions;
        if ($watcher) $content .= '<p style="color: #959595;font-size: 11px;">YOU ARE A COLLABORATOR ON THIS TICKET</p>';
    }
    $wrapper = file_get_contents(__DIR__ . '/onyst_ticket_email_wrapper.html');
    return str_replace('{{email_body}}', $content, $wrapper);
}
