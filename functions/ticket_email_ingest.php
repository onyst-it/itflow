<?php
/** Native inbound ticket/reply operations; loaded by the parser and isolated tests. */
function addTicket($contact_id, $contact_name, $contact_email, $client_id, $date, $subject, $message, $attachments, $original_message_file, $ccs, $vendor_id = 0) {
    global $mysqli, $config_app_name, $company_name, $company_phone, $config_ticket_prefix, $config_ticket_client_general_notifications, $config_ticket_new_ticket_notification_email, $config_base_url, $config_ticket_from_name, $config_ticket_from_email, $config_ticket_default_billable, $allowed_extensions;
    $bad_pattern = "/do[\W_]*not[\W_]*reply|no[\W_]*reply/i"; // Email addresses to ignore

    // Atomically increment and get the new ticket number
    mysqli_query($mysqli, "
        UPDATE settings
        SET
            config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
            config_ticket_next_number = config_ticket_next_number + 1
        WHERE company_id = 1
    ");

    $ticket_number = mysqli_insert_id($mysqli);

    // Clean up the message
    $message = trim($message);
    // Remove DOCTYPE and meta tags
    $message = preg_replace('/<!DOCTYPE[^>]*>/i', '', $message);
    $message = preg_replace('/<meta[^>]*>/i', '', $message);
    // Remove <html>, <head>, <body> and their closing tags
    $message = preg_replace('/<\/?(html|head|body)[^>]*>/i', '', $message);
    // Collapse excess whitespace
    $message = preg_replace('/\s+/', ' ', $message);
    // Convert newlines to <br>
    $message = nl2br($message);
    // Wrap final formatted message
    $message = "<i>Email from: <b>$contact_name</b> &lt;$contact_email&gt; at $date:-</i> <br><br><div style='line-height:1.5;'>$message</div>";

    $ticket_prefix_esc = mysqli_real_escape_string($mysqli, $config_ticket_prefix);
    $message_esc = mysqli_real_escape_string($mysqli, $message);
    $contact_email_esc = mysqli_real_escape_string($mysqli, $contact_email);
    $client_id = intval($client_id);
    $vendor_id = intval($vendor_id);

    $url_key = randomString(32);

    mysqli_query($mysqli, "INSERT INTO tickets SET ticket_prefix = '$ticket_prefix_esc', ticket_number = $ticket_number, ticket_source = 'Email', ticket_subject = '$subject', ticket_details = '$message_esc', ticket_priority = 'Medium', ticket_status = 1, ticket_billable = $config_ticket_default_billable, ticket_created_by = 0, ticket_contact_id = $contact_id, ticket_vendor_id = $vendor_id, ticket_url_key = '$url_key', ticket_client_id = $client_id");
    $id = mysqli_insert_id($mysqli);
    applyTicketSla($id);

    // Logging
    logAudit("Ticket", "Create", "Email parser: Client contact $contact_email_esc created ticket $ticket_prefix_esc$ticket_number ($subject) ($id)", $client_id, $id);

    mkdirMissing('../uploads/tickets/');
    $att_dir = "../uploads/tickets/" . $id . "/";
    mkdirMissing($att_dir);

    // Move original .eml into the ticket folder
    rename("../uploads/tmp/{$original_message_file}", "{$att_dir}/{$original_message_file}");
    $original_message_file_esc = mysqli_real_escape_string($mysqli, $original_message_file);
    mysqli_query($mysqli, "INSERT INTO ticket_attachments SET ticket_attachment_name = 'Original-parsed-email.eml', ticket_attachment_reference_name = '$original_message_file_esc', ticket_attachment_ticket_id = $id");

    // Save non-inline attachments
    foreach ($attachments as $attachment) {
        $att_name = $attachment['name'];
        $att_extension = strtolower(pathinfo($att_name, PATHINFO_EXTENSION));

        if (in_array($att_extension, $allowed_extensions)) {
            $att_saved_filename = md5(uniqid(rand(), true)) . '.' . $att_extension;
            $att_saved_path = $att_dir . $att_saved_filename;
            file_put_contents($att_saved_path, $attachment['content']);

            $ticket_attachment_name = escapeSql($att_name);
            $ticket_attachment_reference_name = escapeSql($att_saved_filename);

            $ticket_attachment_name_esc = mysqli_real_escape_string($mysqli, $ticket_attachment_name);
            $ticket_attachment_reference_name_esc = mysqli_real_escape_string($mysqli, $ticket_attachment_reference_name);
            mysqli_query($mysqli, "INSERT INTO ticket_attachments SET ticket_attachment_name = '$ticket_attachment_name_esc', ticket_attachment_reference_name = '$ticket_attachment_reference_name_esc', ticket_attachment_ticket_id = $id");
        } else {
            $ticket_attachment_name_esc = mysqli_real_escape_string($mysqli, $att_name);
            logAudit("Ticket", "Edit", "Email parser: Blocked attachment $ticket_attachment_name_esc from Client contact $contact_email_esc for ticket $ticket_prefix_esc$ticket_number", $client_id, $id);
        }
    }

    // Add unknown guests as ticket watcher
    if ($contact_id == 0 && !preg_match($bad_pattern, $contact_email_esc)) {
        mysqli_query($mysqli, "INSERT INTO ticket_watchers SET watcher_email = '$contact_email_esc', watcher_ticket_id = $id");
    }

    // Add CCs as ticket watchers
    foreach ($ccs as $cc) {
        if (filter_var($cc, FILTER_VALIDATE_EMAIL) && !preg_match($bad_pattern, $cc)) {
            $cc_esc = mysqli_real_escape_string($mysqli, $cc);
            mysqli_query($mysqli, "INSERT INTO ticket_watchers SET watcher_email = '$cc_esc', watcher_ticket_id = $id");
        }
    }

    // External email
    $data = [];
    if ($config_ticket_client_general_notifications == 1 && !preg_match($bad_pattern, $contact_email)) {
        $subject_email = "Ticket created - [$config_ticket_prefix$ticket_number] - $subject";
        // SLA response commitment for this client + priority, empty when no SLA applies
        $sla_notice = getTicketSlaEmailNotice($id, $company_phone);
        $body = "<i style='color: #808080'>##- Please type your reply above this line -##</i><br><br>Hello $contact_name,<br><br>Thank you for your email. A ticket regarding \"$subject\" has been automatically created for you.<br><br>Ticket: $config_ticket_prefix$ticket_number<br>Subject: $subject<br>Status: New<br>Portal: <a href='https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$id&url_key=$url_key'>View ticket</a>$sla_notice<br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";
        $data[] = [
            'from' => $config_ticket_from_email,
            'from_name' => $config_ticket_from_name,
            'recipient' => $contact_email,
            'recipient_name' => $contact_name,
            'subject' => $subject_email,
            'body' => mysqli_real_escape_string($mysqli, $body)
        ];
    }

    // Internal email
    if ($config_ticket_new_ticket_notification_email) {
        if ($client_id == 0) {
            $client_name = "Guest";
            $client_uri = '';
        } else {
            $client_sql = mysqli_query($mysqli, "SELECT client_name FROM clients WHERE client_id = $client_id");
            $client_row = mysqli_fetch_assoc($client_sql);
            $client_name = escapeSql($client_row['client_name']);
            $client_uri = "&client_id=$client_id";
        }
        $email_subject = "$config_app_name - New Ticket - $client_name: $subject";
        $email_body = "Hello, <br><br>This is a notification that a new ticket has been raised in ITFlow. <br>Client: $client_name<br>Priority: Low (email parsed)<br>Link: https://$config_base_url/agent/ticket.php?ticket_id=$id$client_uri <br><br>--------------------------------<br><br><b>$subject</b><br>$message";

        $data[] = [
            'from' => $config_ticket_from_email,
            'from_name' => $config_ticket_from_name,
            'recipient' => $config_ticket_new_ticket_notification_email,
            'recipient_name' => $config_ticket_from_name,
            'subject' => $email_subject,
            'body' => mysqli_real_escape_string($mysqli, $email_body)
        ];
    }

    addToMailQueue($data);
    triggerCustomAction('ticket_create', $id);

    return true;
}

function addReply($from_email, $date, $subject, $ticket_number, $message, $attachments) {
    global $mysqli, $config_app_name, $company_name, $company_phone, $config_ticket_prefix, $config_base_url, $config_ticket_from_name, $config_ticket_from_email, $allowed_extensions;

    $ticket_reply_type = 'Client';
    // Accept both the ONYST/Syncro separator and older ITFlow email replies.
    $message = onystStripQuotedTicketEmail($message);
    // $message contains the raw HTML body from IMAP

    // 1) Remove the reply separator and everything below it (HTML-aware)
    // This matches: <i ...>##- Please type your reply above this line -##</i> and EVERYTHING after it
    $message = preg_replace(
        '/<i[^>]*>##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##<\/i>.*$/is',
        '',
        $message
    );

    // 2) Clean up the remaining message

    // Remove DOCTYPE and meta tags
    $message = preg_replace('/<!DOCTYPE[^>]*>/i', '', $message);
    $message = preg_replace('/<meta[^>]*>/i', '', $message);

    // Remove <html>, <head>, <body> and their closing tags
    $message = preg_replace('/<\/?(html|head|body)[^>]*>/i', '', $message);

    // Trim leading/trailing whitespace
    $message = trim($message);

    // Normalize line breaks to spaces
    $message = preg_replace('/\r\n|\r|\n/', ' ', $message);

    // Convert to <br> for HTML display
    $message = nl2br($message);

    // 3) Final wrapper
    $message = "<i>Email from: $from_email at $date:-</i><br><br><div style='line-height:1.5;'>$message</div>";

    $ticket_number_esc = intval($ticket_number);
    $message_esc = mysqli_real_escape_string($mysqli, $message);
    $from_email_esc = mysqli_real_escape_string($mysqli, $from_email);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_subject, ticket_status, ticket_contact_id, ticket_client_id, ticket_vendor_id, contact_email, client_name
        FROM tickets
        LEFT JOIN contacts on tickets.ticket_contact_id = contacts.contact_id
        LEFT JOIN clients on tickets.ticket_client_id = clients.client_id
        WHERE ticket_number = $ticket_number_esc LIMIT 1"));

    if ($row) {
        $ticket_id = intval($row['ticket_id']);
        $ticket_subject = escapeSql($row['ticket_subject']);
        $ticket_status = escapeSql($row['ticket_status']);
        $ticket_reply_contact = intval($row['ticket_contact_id']);
        $ticket_contact_email = escapeSql($row['contact_email']);
        $client_id = intval($row['ticket_client_id']);
        if ($client_id) {
            $client_uri = "&client_id=$client_id";
        } else {
            $client_uri = '';
        }
        $client_name = escapeSql($row['client_name']);

        if ($ticket_status == 5) {
            $config_ticket_prefix_esc = mysqli_real_escape_string($mysqli, $config_ticket_prefix);
            $ticket_number_esc2 = mysqli_real_escape_string($mysqli, $ticket_number);

            appNotify("Ticket", "Email parser: $from_email attempted to re-open ticket $config_ticket_prefix_esc$ticket_number_esc2 (ID $ticket_id) - check inbox manually to see email", "/agent/ticket.php?ticket_id=$ticket_id$client_uri", $client_id);

            $email_subject = "Action required: This ticket is already closed";
            $email_body = "Hi there, <br><br>You've tried to reply to a ticket that is closed - we won't see your response. <br><br>Please raise a new ticket by sending a new e-mail to our support address below. <br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";

            $data = [
                [
                    'from' => $config_ticket_from_email,
                    'from_name' => $config_ticket_from_name,
                    'recipient' => $from_email,
                    'recipient_name' => $from_email,
                    'subject' => $email_subject,
                    'body' => mysqli_real_escape_string($mysqli, $email_body)
                ]
            ];

            addToMailQueue($data);
            return true;
        }

        $sender = onystInboundContact($from_email, $client_id, intval($row['ticket_vendor_id']));
        if ($sender['state'] === 'matched') {
            $ticket_reply_contact = intval($sender['contact']['contact_id']);
        } else {
            $ticket_reply_type = 'Internal';
            $ticket_reply_contact = 0;
            $message = "<b>WARNING: Contact email mismatch or ambiguous sender</b><br>$message";
            $message_esc = mysqli_real_escape_string($mysqli, $message);
        }

        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$message_esc', ticket_reply_type = '$ticket_reply_type', ticket_reply_time_worked = '00:00:00', ticket_reply_by = $ticket_reply_contact, ticket_reply_ticket_id = $ticket_id");
        $reply_id = mysqli_insert_id($mysqli);

        $ticket_dir = "../uploads/tickets/" . $ticket_id . "/";
        mkdirMissing($ticket_dir);

        foreach ($attachments as $attachment) {
            $att_name = $attachment['name'];
            $att_extension = strtolower(pathinfo($att_name, PATHINFO_EXTENSION));

            if (in_array($att_extension, $allowed_extensions)) {
                $att_saved_filename = md5(uniqid(rand(), true)) . '.' . $att_extension;
                $att_saved_path = $ticket_dir . $att_saved_filename;
                file_put_contents($att_saved_path, $attachment['content']);

                $ticket_attachment_name = escapeSql($att_name);
                $ticket_attachment_reference_name = escapeSql($att_saved_filename);

                $ticket_attachment_name_esc = mysqli_real_escape_string($mysqli, $ticket_attachment_name);
                $ticket_attachment_reference_name_esc = mysqli_real_escape_string($mysqli, $ticket_attachment_reference_name);
                mysqli_query($mysqli, "INSERT INTO ticket_attachments SET ticket_attachment_name = '$ticket_attachment_name_esc', ticket_attachment_reference_name = '$ticket_attachment_reference_name_esc', ticket_attachment_reply_id = $reply_id, ticket_attachment_ticket_id = $ticket_id");
            } else {
                $ticket_attachment_name_esc = mysqli_real_escape_string($mysqli, $att_name);
                logAudit("Ticket", "Edit", "Email parser: Blocked attachment $ticket_attachment_name_esc from Client contact $from_email_esc for ticket $config_ticket_prefix$ticket_number_esc", $client_id, $ticket_id);
            }
        }

        $ticket_assigned_to_sql = mysqli_query($mysqli, "SELECT ticket_assigned_to FROM tickets WHERE ticket_id = $ticket_id LIMIT 1");
        if ($ticket_assigned_to_sql) {
            $row3 = mysqli_fetch_assoc($ticket_assigned_to_sql);
            $ticket_assigned_to = intval($row3['ticket_assigned_to']);

            if ($ticket_assigned_to) {
                $tech_sql = mysqli_query($mysqli, "SELECT user_email, user_name FROM users WHERE user_id = $ticket_assigned_to LIMIT 1");
                $tech_row = mysqli_fetch_assoc($tech_sql);
                $tech_email = escapeSql($tech_row['user_email']);
                $tech_name = escapeSql($tech_row['user_name']);

                $email_subject = "$config_app_name - Ticket updated - [$config_ticket_prefix$ticket_number] $ticket_subject";
                $email_body    = "Hello $tech_name,<br><br>A new reply has been added to the below ticket.<br><br>Client: $client_name<br>Ticket: $config_ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Link: https://$config_base_url/agent/ticket.php?ticket_id=$ticket_id$client_uri<br><br>--------------------------------<br>$message_esc";

                $data = [
                    [
                        'from' => $config_ticket_from_email,
                        'from_name' => $config_ticket_from_name,
                        'recipient' => $tech_email,
                        'recipient_name' => $tech_name,
                        'subject' => mysqli_real_escape_string($mysqli, $email_subject),
                        'body' => mysqli_real_escape_string($mysqli, $email_body)
                    ]
                ];
                addToMailQueue($data);
            }
        }

        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 2, ticket_resolved_at = NULL WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id LIMIT 1");
        resetTicketResolutionSla($ticket_id);

        // Only record the reopen when the ticket was not already open
        if (intval($ticket_status) !== 2) {
            logTicketHistory($ticket_id, "$from_email_esc replied by email, reopening the ticket");
        }

        logAudit("Ticket", "Edit", "Email parser: Client contact $from_email_esc updated ticket $config_ticket_prefix$ticket_number_esc ($subject)", $client_id, $ticket_id);
        triggerCustomAction('ticket_reply_client', $ticket_id);
        return true;
    } else {
        return false;
    }
}

