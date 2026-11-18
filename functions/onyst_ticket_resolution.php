<?php
// ONYST_RESOLUTION_V3: invoked only by authenticated, CSRF-checked agent actions.
function onystResolutionModes(): array {
    return ['silent' => 'Silently Resolve', 'standard' => 'Resolve - Send Standard Resolution Note', 'no_response' => 'Resolve - Send Inactivity Explanation'];
}
function onystResolutionResponse(string $mode): ?array {
    global $mysqli;
    if (!array_key_exists($mode, onystResolutionModes())) throw new InvalidArgumentException('Choose a valid resolution action.');
    if ($mode === 'silent') return null;
    $names = ['standard' => 'Resolved - Standard', 'no_response' => 'Resolved - No Response'];
    $name = mysqli_real_escape_string($mysqli, $names[$mode]);
    $r = mysqli_query($mysqli, "SELECT canned_response_id, canned_response_name, canned_response_body, canned_response_category_id FROM canned_responses WHERE canned_response_name='$name' AND canned_response_archived_at IS NULL");
    $response = $r && mysqli_num_rows($r) === 1 ? mysqli_fetch_assoc($r) : null;
    if (!$response || !onystTicketMessageHasContent($response['canned_response_body'])) {
        throw new RuntimeException('The selected saved resolution response is missing, ambiguous, or empty. No default message will be sent.');
    }
    return $response;
}
function onystResolutionQuery(string $sql) {
    global $mysqli;
    $result = mysqli_query($mysqli, $sql);
    if ($result === false) throw new RuntimeException('The resolution could not be saved.');
    return $result;
}
/** The caller must hold a transaction. This function never sends SMTP or invokes external hooks. */
function onystApplyResolutions(array $ids, string $mode): array {
    global $mysqli, $session_user_id, $session_name;
    if (!intval(mysqli_fetch_row(onystResolutionQuery('SELECT @@in_transaction'))[0])) throw new LogicException('Resolution requires a transaction.');
    $response = onystResolutionResponse($mode);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    sort($ids);
    if (!$ids) throw new InvalidArgumentException('Select at least one ticket.');
    $settings = mysqli_fetch_assoc(onystResolutionQuery('SELECT config_ticket_from_name,config_ticket_from_email,config_smtp_provider FROM settings WHERE company_id=1'));
    if ($response && (empty($settings['config_smtp_provider']) || !filter_var($settings['config_ticket_from_email'], FILTER_VALIDATE_EMAIL))) throw new RuntimeException('Email sending is not configured. Use Silently Resolve or fix the mail settings.');
    $result = ['changed' => [], 'skipped' => [], 'emails' => 0];
    foreach ($ids as $id) {
        $ticket = mysqli_fetch_assoc(onystResolutionQuery("SELECT t.*,c.contact_name,c.contact_email FROM tickets t LEFT JOIN contacts c ON c.contact_id=t.ticket_contact_id WHERE t.ticket_id=$id FOR UPDATE"));
        if (!$ticket) { $result['skipped'][] = ['id'=>$id,'reason'=>'not found']; continue; }
        $client = intval($ticket['ticket_client_id']);
        if ($client) enforceClientAccess($client);
        if (in_array(intval($ticket['ticket_status']), [4,5], true)) { $result['skipped'][] = ['id'=>$id,'reason'=>'already resolved or closed']; continue; }
        $tasks = intval(mysqli_fetch_row(onystResolutionQuery("SELECT COUNT(*) FROM tasks WHERE task_ticket_id=$id AND task_completed_at IS NULL"))[0]);
        if ($tasks) { $result['skipped'][] = ['id'=>$id,'reason'=>'open tasks']; continue; }
        $recipients = [];
        if ($response) {
            $category = intval($response['canned_response_category_id']);
            if ($category && $category !== intval($ticket['ticket_category'])) throw new RuntimeException('The saved response does not apply to one of the selected ticket categories.');
            $contact = trim((string)($ticket['contact_email'] ?? ''));
            if (filter_var($contact, FILTER_VALIDATE_EMAIL)) $recipients[strtolower($contact)] = ['email'=>$contact,'name'=>$ticket['contact_name'] ?? $contact,'watcher'=>false];
            $watchers = onystResolutionQuery("SELECT watcher_email FROM ticket_watchers WHERE watcher_ticket_id=$id");
            while ($row = mysqli_fetch_assoc($watchers)) {
                $email = trim($row['watcher_email']);
                if (filter_var($email,FILTER_VALIDATE_EMAIL) && !isset($recipients[strtolower($email)])) $recipients[strtolower($email)] = ['email'=>$email,'name'=>$email,'watcher'=>true];
            }
            if (!$recipients) { $result['skipped'][] = ['id'=>$id,'reason'=>'no valid email recipient']; continue; }
        }
        $ref = $ticket['ticket_prefix'] . $ticket['ticket_number'];
        onystResolutionQuery("UPDATE tickets SET ticket_status=4,ticket_resolved_at=NOW(),ticket_updated_at=NOW() WHERE ticket_id=$id");
        if (empty($ticket['ticket_first_response_at'])) setTicketFirstResponse($id);
        syncTicketSlaClock($id);
        setTicketResolutionSlaMet($id);
        if (mysqli_errno($mysqli)) throw new RuntimeException('Could not update ticket SLA.');
        if ($response) {
            $body = mysqli_real_escape_string($mysqli, $response['canned_response_body']);
            $actor = intval($session_user_id);
            onystResolutionQuery("INSERT INTO ticket_replies SET ticket_reply='$body',ticket_reply_type='Public',ticket_reply_time_worked='00:00:00',ticket_reply_by=$actor,ticket_reply_ticket_id=$id");
            $subject = "Ticket Resolved - [$ref] - " . $ticket['ticket_subject'] . ' | (pending closure)';
            foreach ($recipients as $recipient) {
                $html = onystRenderTicketMessage('Ticket Resolved', $ref, $ticket['ticket_subject'], $response['canned_response_body'], $recipient['watcher']);
                $email = ['from'=>$settings['config_ticket_from_email'],'from_name'=>$settings['config_ticket_from_name'],'recipient'=>$recipient['email'],'recipient_name'=>$recipient['name'],'subject'=>$subject,'body'=>$html];
                $email = array_map(fn($value) => mysqli_real_escape_string($mysqli,(string)$value),$email);
                if (!addToMailQueue([$email]) || mysqli_errno($mysqli)) throw new RuntimeException('The closing email could not be queued. The resolution was not saved.');
                $result['emails']++;
            }
        }
        $actorName = escapeSql($session_name);
        $description = $mode === 'silent' ? "$actorName silently resolved the ticket (no email)" : "$actorName resolved the ticket using " . escapeSql($response['canned_response_name']);
        logTicketHistory($id,$description);
        logAudit('Ticket','Resolved',$description,$client,$id);
        if (mysqli_errno($mysqli)) throw new RuntimeException('Could not record the ticket history.');
        $result['changed'][] = $id;
    }
    return $result;
}
function onystHandleResolution(array $ids, string $mode): void {
    global $mysqli;
    validateCSRFToken();
    enforceUserPermission('module_support',2);
    mysqli_begin_transaction($mysqli);
    try {
        $result = onystApplyResolutions($ids,$mode);
        if (!mysqli_commit($mysqli)) throw new RuntimeException('The resolution could not be committed.');
    } catch (Throwable $error) {
        mysqli_rollback($mysqli);
        flashAlert(escapeHtml($error->getMessage()),'error');
        redirect();
        return;
    }
    foreach ($result['changed'] as $id) triggerCustomAction('ticket_resolve',$id);
    $count = count($result['changed']);
    $message = $mode === 'silent' ? "Silently resolved $count ticket(s). No email queued." : "Resolved $count ticket(s); queued " . $result['emails'] . ' closing email(s).';
    if ($result['skipped']) {
        $reasons = array_count_values(array_column($result['skipped'], 'reason'));
        $message .= ' Skipped: ' . implode('; ', array_map(fn($reason, $count) => $count . ' ' . $reason, array_keys($reasons), $reasons)) . '.';
    }
    flashAlert($message);
    redirect();
}
