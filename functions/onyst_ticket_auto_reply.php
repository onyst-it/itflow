<?php

function onystCanAutoReplyTo(string $email, string $support_email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL)
        && strcasecmp($email, $support_email) !== 0
        && !preg_match('/do[\W_]*not[\W_]*reply|no[\W_]*reply|mailer-daemon|^postmaster@|^bounce[+@]/i', $email);
}

function onystScheduleTicketAutoReply(int $ticket_id, string $sender_email): void
{
    global $mysqli;

    // Only intake schedules this work. Existing tickets have an empty deadline.
    $sender_email = mysqli_real_escape_string($mysqli, $sender_email);
    mysqli_query($mysqli, "UPDATE tickets
        SET ticket_auto_reply_due_at = DATE_ADD(NOW(), INTERVAL 60 MINUTE),
            ticket_auto_reply_email = '$sender_email',
            ticket_updated_at = ticket_updated_at
        WHERE ticket_id = $ticket_id AND ticket_status = 1");
}

// The caller owns the transaction.
function onystQueueTicketAutoReply(int $ticket_id, string $from_email, string $from_name): bool
{
    global $mysqli;

    // Hold the ticket and owner preferences until the public comment and mail are saved.
    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT t.ticket_prefix, t.ticket_number,
            t.ticket_subject, c.contact_name, c.contact_email
        FROM tickets t
        JOIN contacts c ON c.contact_id = t.ticket_contact_id AND c.contact_archived_at IS NULL
        LEFT JOIN clients cl ON cl.client_id = t.ticket_client_id
        LEFT JOIN vendors v ON v.vendor_id = t.ticket_vendor_id
        WHERE t.ticket_id = $ticket_id AND t.ticket_auto_reply_due_at <= NOW()
          AND t.ticket_source = 'Email' AND t.ticket_status = 1
          AND TRIM(c.contact_email) = t.ticket_auto_reply_email
          AND t.ticket_archived_at IS NULL AND t.ticket_closed_at IS NULL AND t.ticket_resolved_at IS NULL
          AND t.ticket_first_response_at IS NULL AND t.ticket_assigned_to = 0
          AND t.ticket_priority = 'Medium' AND t.ticket_project_id = 0 AND t.ticket_schedule IS NULL
          AND COALESCE(t.ticket_category, '') IN ('', '0')
          AND (t.ticket_due_at IS NULL OR t.ticket_due_at > NOW())
          AND (t.ticket_response_due_at IS NULL OR t.ticket_response_due_at > NOW())
          AND (t.ticket_resolution_due_at IS NULL OR t.ticket_resolution_due_at > NOW())
          AND (t.ticket_updated_at IS NULL OR t.ticket_updated_at <= DATE_SUB(t.ticket_auto_reply_due_at, INTERVAL 60 MINUTE))
          AND ((c.contact_vendor_id > 0 AND c.contact_vendor_id = t.ticket_vendor_id)
            OR (c.contact_vendor_id = 0 AND c.contact_client_id > 0 AND c.contact_client_id = t.ticket_client_id))
          AND (t.ticket_client_id = 0 OR (cl.client_archived_at IS NULL AND cl.client_ticket_auto_reply = 1))
          AND (t.ticket_vendor_id = 0 OR (v.vendor_archived_at IS NULL AND v.vendor_ticket_auto_reply = 1))
          AND NOT EXISTS (SELECT 1 FROM ticket_history WHERE ticket_history_ticket_id = t.ticket_id)
          AND NOT EXISTS (SELECT 1 FROM tasks WHERE task_ticket_id = t.ticket_id)
        FOR UPDATE"));

    // A past comment counts as activity even if it has since been archived.
    $has_reply = $ticket && mysqli_num_rows(mysqli_query($mysqli, "SELECT ticket_reply_id
        FROM ticket_replies WHERE ticket_reply_ticket_id = $ticket_id LIMIT 1 FOR UPDATE")) > 0;

    mysqli_query($mysqli, "UPDATE tickets SET ticket_auto_reply_due_at = NULL, ticket_auto_reply_email = NULL, ticket_updated_at = ticket_updated_at
        WHERE ticket_id = $ticket_id AND ticket_auto_reply_due_at <= NOW()");
    if (!$ticket || $has_reply || !onystCanAutoReplyTo(trim($ticket['contact_email']), $from_email)) {
        return false;
    }
    if (mysqli_affected_rows($mysqli) !== 1) {
        throw new RuntimeException('Could not clear the pending ticket acknowledgment.');
    }

    $message = '<p>This is an automated acknowledgment from ONYST. We received your request and need a little more time to respond. Our team will follow up in this ticket.</p>'
        . '<p>For an urgent issue or business disruption, call <a href="tel:+14252432079">+1 (425) 243-2079</a> and choose option 1.</p>'
        . '<p>Reply to this email to add details or screenshots. Thank you for your patience.</p>';
    $reply = mysqli_real_escape_string($mysqli, $message);
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$reply', ticket_reply_type = 'Public',
        ticket_reply_auto_ack = 1, ticket_reply_time_worked = '00:00:00', ticket_reply_by = 0, ticket_reply_ticket_id = $ticket_id");

    $reference = $ticket['ticket_prefix'] . $ticket['ticket_number'];
    $html = onystRenderTicketMessage('Ticket Updated', $reference, $ticket['ticket_subject'], $message);
    $html = str_replace('data-onyst-ticket-email="1"', 'data-onyst-ticket-email="1" data-onyst-auto-reply="1"', $html);
    $email = array_map(static fn($value) => mysqli_real_escape_string($mysqli, (string) $value), [
        'from' => $from_email,
        'from_name' => $from_name,
        'recipient' => trim($ticket['contact_email']),
        'recipient_name' => $ticket['contact_name'],
        'subject' => "Ticket Updated - [$reference] - " . $ticket['ticket_subject'],
        'body' => $html,
    ]);
    if (!addToMailQueue([$email]) || mysqli_affected_rows($mysqli) !== 1) {
        throw new RuntimeException('Could not queue the ticket acknowledgment.');
    }

    logTicketHistory($ticket_id, 'ONYST automatic acknowledgment queued');
    return true;
}

function onystRunTicketAutoReplies(string $from_email, string $from_name): int
{
    global $mysqli;

    $count = 0;
    $failures = 0;
    $due = mysqli_query($mysqli, 'SELECT ticket_id FROM tickets WHERE ticket_auto_reply_due_at <= NOW()
        ORDER BY ticket_auto_reply_due_at, ticket_id LIMIT 100');
    while ($row = mysqli_fetch_assoc($due)) {
        $ticket_id = intval($row['ticket_id']);
        mysqli_begin_transaction($mysqli);
        try {
            $queued = onystQueueTicketAutoReply($ticket_id, $from_email, $from_name);
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit the ticket acknowledgment.');
            }
            $count += (int) $queued;
        } catch (Throwable $error) {
            mysqli_rollback($mysqli);
            $failures++;
            logApp('Ticket Auto Reply', 'error', "Could not acknowledge ticket ID $ticket_id.");
        }
    }
    if ($failures) {
        throw new RuntimeException("Could not queue $failures ticket acknowledgment(s). They will be retried.");
    }
    return $count;
}
