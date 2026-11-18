<?php
// Pure checks run on every deploy. Database checks are CLI-only and explicitly opt-in.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/functions/onyst_ticket_email.php';
require_once dirname(__DIR__) . '/functions/onyst_ticket_resolution.php';
$checks = 0;
function resolutionCheck(bool $ok, string $name): void {
    global $checks;
    ++$checks;
    if (!$ok) throw new RuntimeException($name);
}
function resolutionThrows(callable $action, string $class): void {
    try { $action(); } catch (Throwable $error) {
        resolutionCheck($error instanceof $class, 'Expected exception: ' . $class);
        return;
    }
    throw new RuntimeException('Expected failure was accepted.');
}
resolutionCheck(array_keys(onystResolutionModes()) === ['silent','standard','no_response'], 'Only three supported actions');
resolutionCheck(onystResolutionResponse('silent') === null, 'Silent mode needs no response or DB');
foreach (['', 'complete', 'job_completion', 'Resolved', 'default'] as $mode) {
    resolutionThrows(fn() => onystResolutionResponse($mode), InvalidArgumentException::class);
}
$subject = 'Ticket Resolved - [TEST-42] - Example | (pending closure)';
$old = '<i>##- Please type your reply above this line -##</i><br>Your ticket has been marked as solved and is pending closure.';
$wrapper = file_get_contents(dirname(__DIR__) . '/functions/onyst_ticket_email_wrapper.html');
resolutionCheck(hash('sha256', $wrapper) === '860c16fd71d2a1d0ddad65b1d26121df59529c01751c3fa48f1c6074bbba4d74', 'Approved wrapper byte-for-byte unchanged');
foreach ([$old, str_replace('{{email_body}}', $old, $wrapper), '<p>Unmarked message</p>'] as $legacy) {
    resolutionCheck(onystFormatTicketEmail($legacy, $subject) === null, 'Legacy default rejected by queue formatter');
    resolutionCheck(!onystResolutionDeliveryAllowed($subject, $legacy), 'Old queued/retried message cannot reach SMTP');
}
foreach (['<p>This ticket is now marked as Resolved/Closed.</p>', '<p>We haven\'t heard back from you, so this ticket is now marked as Resolved/Closed.</p>', '<p>My authored closing reply.</p>', '<p><img src="cid:example" alt="Screenshot"></p>'] as $body) {
    foreach ([false, true] as $watcher) {
        $html = onystRenderTicketMessage('Ticket Resolved', 'TEST-42', 'Example', $body, $watcher);
        resolutionCheck(onystResolutionDeliveryAllowed($subject, $html), 'Explicit message accepted');
        resolutionCheck(onystFormatTicketEmail($html, $subject) === $html, 'Explicit message is not rewrapped');
        resolutionCheck(strpos($html, $body) !== false && substr_count($html, '<hr ') === 2, 'Selected message inside approved rules');
        resolutionCheck(strpos($html, 'marked as solved and is pending closure') === false, 'No built-in prose appended');
        resolutionCheck(substr_count($html, 'YOU ARE A COLLABORATOR ON THIS TICKET') === ($watcher ? 1 : 0), 'Watcher notice inside wrapper');
    }
}
resolutionCheck(onystTicketEmailSubject(str_replace('Resolved', 'resolved', $subject)) === $subject, 'Resolved subject and heading share label');
resolutionThrows(fn() => onystRenderTicketMessage('Ticket Resolved', 'TEST-42', 'Example', '<p>&nbsp;</p>'), InvalidArgumentException::class);
resolutionCheck(onystResolutionDeliveryAllowed('Invoice 42', '<p>Invoice</p>'), 'Unrelated email unaffected');

if (!in_array('--database', $argv, true)) {
    echo json_encode(['passed'=>true,'checks'=>$checks,'database_access'=>false,'emails_sent'=>0]), PHP_EOL;
    exit;
}

// Never invoke the HTTP handler, custom hooks, cron, SMTP, or COMMIT here.
// All test changes stay in InnoDB transactions and are rolled back in finally.
chdir(dirname(__DIR__));
require 'config.php';
require 'functions.php';
require 'includes/inc_set_timezone.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$session_user_id = 1;
$session_name = 'ONYST rollback verification';
$session_is_admin = true;
$session_ip = '127.0.0.1';
$session_user_agent = 'ONYST rollback verification';
$tables = ['tickets','ticket_replies','email_queue','ticket_history','sla_history','logs','app_logs','canned_responses','tasks','ticket_watchers'];
foreach ($tables as $table) {
    $engine = mysqli_fetch_row(onystResolutionQuery("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'"))[0] ?? '';
    resolutionCheck(strcasecmp($engine, 'InnoDB') === 0, 'Rollback support: ' . $table);
}
$ticket = mysqli_fetch_assoc(onystResolutionQuery("SELECT * FROM tickets WHERE ticket_id=1"));
resolutionCheck($ticket && $ticket['ticket_subject'] === 'ITFlow Ticket Test' && intval($ticket['ticket_client_id']) === 0, 'Explicit disposable test ticket only');
$baselineTicket = $ticket;
$counts = [];
foreach (['ticket_replies','email_queue','ticket_history','sla_history','logs','app_logs','tasks'] as $table) {
    $counts[$table] = intval(mysqli_fetch_row(onystResolutionQuery("SELECT COUNT(*) FROM $table"))[0]);
}
function resetResolutionTestTicket(): void {
    onystResolutionQuery('UPDATE tickets SET ticket_status=1,ticket_resolved_at=NULL WHERE ticket_id=1');
}
foreach (['silent','standard','no_response'] as $mode) {
    mysqli_begin_transaction($mysqli);
    try {
        resetResolutionTestTicket();
        $before = intval(mysqli_fetch_row(onystResolutionQuery('SELECT COALESCE(MAX(email_id),0) FROM email_queue'))[0]);
        $replyBefore = intval(mysqli_fetch_row(onystResolutionQuery('SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id=1'))[0]);
        $result = onystApplyResolutions([1,1], $mode);
        resolutionCheck($result['changed'] === [1], 'One resolution: ' . $mode);
        resolutionCheck(intval(mysqli_fetch_row(onystResolutionQuery('SELECT ticket_status FROM tickets WHERE ticket_id=1'))[0]) === 4, 'Status Resolved: ' . $mode);
        $queue = onystResolutionQuery("SELECT email_subject,email_content FROM email_queue WHERE email_id>$before");
        if ($mode === 'silent') {
            resolutionCheck(mysqli_num_rows($queue) === 0 && $result['emails'] === 0, 'Silent queues no email');
            resolutionCheck(intval(mysqli_fetch_row(onystResolutionQuery('SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id=1'))[0]) === $replyBefore, 'Silent creates no public reply');
        } else {
            $response = onystResolutionResponse($mode);
            resolutionCheck(mysqli_num_rows($queue) > 0 && mysqli_num_rows($queue) === $result['emails'], 'Selected response queued');
            while ($mail = mysqli_fetch_assoc($queue)) {
                resolutionCheck(strpos($mail['email_content'], $response['canned_response_body']) !== false, 'Exact saved response retained');
                resolutionCheck(onystResolutionDeliveryAllowed($mail['email_subject'], $mail['email_content']), 'Queue passes final delivery guard');
                resolutionCheck(strpos($mail['email_content'], 'marked as solved and is pending closure') === false, 'No default text in queued email');
            }
            resolutionCheck(intval(mysqli_fetch_row(onystResolutionQuery('SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id=1'))[0]) === $replyBefore + 1, 'One public closing reply');
        }
        $repeat = onystApplyResolutions([1], $mode);
        resolutionCheck($repeat['changed'] === [] && $repeat['emails'] === 0, 'Repeat click does not duplicate email or resolve');
    } finally { mysqli_rollback($mysqli); }
}
mysqli_begin_transaction($mysqli);
try {
    resetResolutionTestTicket();
    onystResolutionQuery("INSERT INTO tasks SET task_name='ONYST rollback-only task gate',task_ticket_id=1");
    $result = onystApplyResolutions([1], 'silent');
    resolutionCheck($result['changed'] === [] && $result['emails'] === 0 && $result['skipped'][0]['reason'] === 'open tasks', 'Open tasks prevent resolution');
} finally { mysqli_rollback($mysqli); }
mysqli_begin_transaction($mysqli);
try {
    resetResolutionTestTicket();
    // Deliberately hide the saved response inside this uncommitted transaction.
    onystResolutionQuery("UPDATE canned_responses SET canned_response_archived_at=NOW() WHERE canned_response_name='Resolved - Standard'");
    resolutionThrows(fn() => onystApplyResolutions([1], 'standard'), RuntimeException::class);
    resolutionCheck(intval(mysqli_fetch_row(onystResolutionQuery('SELECT ticket_status FROM tickets WHERE ticket_id=1'))[0]) === 1, 'Missing response does not resolve or fall back');
} finally { mysqli_rollback($mysqli); }
mysqli_begin_transaction($mysqli);
try {
    $before = intval(mysqli_fetch_row(onystResolutionQuery('SELECT COUNT(*) FROM email_queue'))[0]);
    $mail = array_map(fn($value) => mysqli_real_escape_string($mysqli, $value), ['from'=>'help@example.invalid','from_name'=>'Test','recipient'=>'test@example.invalid','recipient_name'=>'Test','subject'=>$subject,'body'=>$old]);
    resolutionCheck(!addToMailQueue([$mail]), 'Queue boundary rejects native default');
    resolutionCheck(intval(mysqli_fetch_row(onystResolutionQuery('SELECT COUNT(*) FROM email_queue'))[0]) === $before, 'Blocked default inserts no queue row');
} finally { mysqli_rollback($mysqli); }
mysqli_begin_transaction($mysqli);
try {
    resetResolutionTestTicket();
    onystResolutionQuery('DELETE FROM ticket_watchers WHERE watcher_ticket_id=1');
    onystResolutionQuery('UPDATE tickets SET ticket_contact_id=0 WHERE ticket_id=1');
    $result = onystApplyResolutions([1], 'no_response');
    resolutionCheck($result['changed'] === [] && $result['emails'] === 0, 'No recipients: no resolution or email');
} finally { mysqli_rollback($mysqli); }
foreach ($counts as $table=>$count) {
    resolutionCheck(intval(mysqli_fetch_row(onystResolutionQuery("SELECT COUNT(*) FROM $table"))[0]) === $count, 'No committed rows: ' . $table);
}
resolutionCheck(mysqli_fetch_assoc(onystResolutionQuery('SELECT * FROM tickets WHERE ticket_id=1')) === $baselineTicket, 'Original test ticket fully restored');
echo json_encode(['passed'=>true,'checks'=>$checks,'database_checks'=>'rolled back','emails_sent'=>0,'commits'=>0]), PHP_EOL;
