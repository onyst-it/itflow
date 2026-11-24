<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/functions/onyst_ticket_auto_reply.php';

$checks = 0;
function assertAutoReply(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$from = 'help@fixture.invalid';
assertAutoReply(onystCanAutoReplyTo('person@fixture.invalid', $from), 'Valid sender excluded.');
foreach (['do-not-reply@fixture.invalid', 'noreply@fixture.invalid', 'HELP@fixture.invalid', 'not-an-address'] as $email) {
    assertAutoReply(!onystCanAutoReplyTo($email, $from), 'Invalid automatic reply recipient accepted.');
}
assertAutoReply(onystIsDefaultTicketCreatedEmail('Ticket created - [FIX-1] - Original issue'), 'Legacy inbound confirmation was not recognized.');
assertAutoReply(onystIsDefaultTicketCreatedEmail('Ticket Created [FIX-1] - Original issue'), 'Legacy agent confirmation was not recognized.');
assertAutoReply(!onystIsDefaultTicketCreatedEmail('ITFlow - New Ticket - Client: Issue'), 'Internal ticket alert was blocked.');
assertAutoReply(!onystIsDefaultTicketCreatedEmail('Ticket Updated - [FIX-1] - Issue'), 'Public reply was blocked.');

if (in_array('--database', $argv, true)) {
    // This suite requires an isolated loopback database loaded with the previous schema.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $mysqli = new mysqli('127.0.0.1', 'root', getenv('ONYST_ACK_FIXTURE_PASSWORD') ?: '', 'itflow_ack_fixture', (int) getenv('ONYST_ACK_FIXTURE_PORT'));
    $mysqli->set_charset('utf8mb4');
    $mysqli->query("INSERT INTO clients (client_id, client_name, client_currency_code, client_net_terms) VALUES
        (11, 'Fixture client', 'USD', 0), (12, 'Client with opt-out tag', 'USD', 0)");
    $mysqli->query("INSERT INTO tags (tag_id, tag_name, tag_type, tag_color, tag_icon) VALUES (31, 'no auto-responder', 1, 'secondary', 'tag')");
    $mysqli->query('INSERT INTO client_tags (client_id, tag_id) VALUES (12, 31)');
    $mysqli->query("INSERT INTO vendors (vendor_id, vendor_name) VALUES (21, 'Fixture vendor'), (22, 'Opted-out vendor')");
    $mysqli->query("INSERT INTO contacts (contact_id, contact_name, contact_email, contact_client_id, contact_vendor_id) VALUES
        (101, 'Client contact', 'client@fixture.invalid', 11, 0),
        (102, 'Tagged client contact', 'tagged@fixture.invalid', 12, 0),
        (201, 'Vendor contact', 'vendor@fixture.invalid', 0, 21),
        (202, 'Opted-out vendor contact', 'optout@fixture.invalid', 0, 22),
        (103, 'Updated contact address', 'current@fixture.invalid', 11, 0)");

    define('FROM_DB_UPDATER', true);
    $migration = dirname(__DIR__) . '/admin/database_updates/2.8.0.php';
    require $migration;
    require $migration;
    $mysqli->query('UPDATE vendors SET vendor_ticket_auto_reply = 0 WHERE vendor_id = 22');
    assertAutoReply((int) $mysqli->query('SELECT client_ticket_auto_reply FROM clients WHERE client_id = 12')->fetch_column() === 0, 'Existing client opt-out tag did not carry over.');

    $fixtures = [
        [1, 11, 0, 101, 'client@fixture.invalid', 'DATE_SUB(NOW(), INTERVAL 1 MINUTE)'],
        [2, 0, 21, 201, 'vendor@fixture.invalid', 'DATE_SUB(NOW(), INTERVAL 1 MINUTE)'],
        [3, 12, 0, 102, 'tagged@fixture.invalid', 'NOW()'],
        [4, 0, 22, 202, 'optout@fixture.invalid', 'NOW()'],
        [5, 11, 0, 101, 'client@fixture.invalid', 'DATE_ADD(NOW(), INTERVAL 1 MINUTE)'],
        [6, 11, 0, 101, 'client@fixture.invalid', 'NOW(), ticket_assigned_to = 3'],
        [7, 11, 0, 101, 'client@fixture.invalid', 'NOW()'],
        [8, 11, 0, 101, 'client@fixture.invalid', 'NOW()'],
        [9, 11, 0, 101, 'client@fixture.invalid', 'NOW(), ticket_updated_at = NOW()'],
        [10, 11, 0, 101, 'client@fixture.invalid', 'NOW(), ticket_due_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)'],
        [11, 11, 0, 103, 'original@fixture.invalid', 'NOW()'],
        [12, 11, 0, 101, 'client@fixture.invalid', 'NULL'],
    ];
    foreach ($fixtures as [$id, $client, $vendor, $contact, $email, $due]) {
        $mysqli->query("INSERT INTO tickets SET ticket_id = $id, ticket_number = $id, ticket_prefix = 'FIX-',
            ticket_source = 'Email', ticket_status = 1, ticket_subject = 'Fixture issue', ticket_details = 'Help',
            ticket_created_by = 0, ticket_priority = 'Medium', ticket_client_id = $client,
            ticket_vendor_id = $vendor, ticket_contact_id = $contact, ticket_auto_reply_email = '$email',
            ticket_auto_reply_due_at = $due");
    }
    $mysqli->query('UPDATE tickets SET ticket_status = 2, ticket_updated_at = ticket_updated_at WHERE ticket_id = 7');
    $mysqli->query("INSERT INTO ticket_replies SET ticket_reply_type = 'Internal', ticket_reply_by = 1,
        ticket_reply_ticket_id = 8, ticket_reply = 'Staff started work', ticket_reply_archived_at = NOW()");
    assertAutoReply(onystRunTicketAutoReplies($from, 'Fixture support') === 2, 'Only the untouched, opted-in client and vendor should receive mail.');
    assertAutoReply(onystRunTicketAutoReplies($from, 'Fixture support') === 0, 'A repeated run created duplicate mail.');
    assertAutoReply((int) $mysqli->query("SELECT COUNT(*) FROM tickets WHERE ticket_id IN (1,2) AND ticket_status = 1 AND ticket_first_response_at IS NULL")->fetch_column() === 2, 'The acknowledgment changed New or counted as an SLA response.');
    assertAutoReply((int) $mysqli->query("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_auto_ack = 1 AND ticket_reply_type = 'Public'")->fetch_column() === 2, 'Acknowledgments were not saved as public replies.');
    $mail = $mysqli->query('SELECT email_recipient, email_content, email_subject FROM email_queue ORDER BY email_id')->fetch_all(MYSQLI_ASSOC);
    assertAutoReply(array_column($mail, 'email_recipient') === ['client@fixture.invalid', 'vendor@fixture.invalid'], 'Unexpected acknowledgment recipients.');
    assertAutoReply($mail[0]['email_subject'] === 'Ticket Updated - [FIX-1] - Fixture issue', 'The acknowledgment does not use the usual ticket reply subject.');
    assertAutoReply(str_contains($mail[0]['email_content'], 'data-onyst-auto-reply="1"'), 'The mail queue cannot apply its auto-response header.');
    assertAutoReply((int) $mysqli->query('SELECT COUNT(*) FROM tickets WHERE ticket_auto_reply_due_at IS NOT NULL')->fetch_column() === 1, 'Unqualified tickets still have a pending acknowledgment.');
    onystScheduleTicketAutoReply(12, 'client@fixture.invalid');
    assertAutoReply((int) $mysqli->query('SELECT TIMESTAMPDIFF(MINUTE, NOW(), ticket_auto_reply_due_at) FROM tickets WHERE ticket_id = 12')->fetch_column() >= 59, 'Intake did not schedule a one-hour delay.');

    $mysqli->query('UPDATE tickets SET ticket_auto_reply_due_at = NOW(), ticket_updated_at = ticket_updated_at WHERE ticket_id = 12');
    $mysqli->query("ALTER TABLE email_queue ADD CONSTRAINT test_queue_failure CHECK (email_subject NOT LIKE '%[FIX-12]%')");
    $failed = false;
    try {
        onystRunTicketAutoReplies($from, 'Fixture support');
    } catch (RuntimeException $expected) {
        $failed = true;
    }
    assertAutoReply($failed, 'A failed queue write appeared successful.');
    assertAutoReply((int) $mysqli->query('SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = 12')->fetch_column() === 0, 'A reply was saved without its email.');
    assertAutoReply((bool) $mysqli->query('SELECT ticket_auto_reply_due_at FROM tickets WHERE ticket_id = 12')->fetch_column(), 'A failed send lost its pending acknowledgment.');
    $mysqli->query('ALTER TABLE email_queue DROP CONSTRAINT test_queue_failure');
    assertAutoReply(onystRunTicketAutoReplies($from, 'Fixture support') === 1, 'The failed acknowledgment could not be retried.');
}

echo json_encode(['passed' => true, 'checks' => $checks, 'emails_sent' => 0]), PHP_EOL;
