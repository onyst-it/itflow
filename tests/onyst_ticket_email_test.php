<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/functions/onyst_ticket_email.php';
$checks = 0;
function onystCheck($condition, $name): void {
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException($name);
}
$marker = "<i style='color: #808080'>##- Please type your reply above this line -##</i>";
$message = '<p>Example <strong>rich text</strong> reply.</p>';
$body = $marker . '<br><br>Hello,<br><br>--------------------------------<br>' . $message . '<br>--------------------------------<br><br>Ticket: ONY-30000<br><br>--<br>ONYST';
foreach (['update'=>'Ticket Updated','Updated'=>'Ticket Updated','resolved'=>'Ticket Resolved','Resolved'=>'Ticket Resolved'] as $event=>$label) {
    $subject = 'Ticket ' . $event . ' - [ONY-30000] - Example';
    onystCheck(onystTicketEmailSubject($subject) === $label . ' - [ONY-30000] - Example', 'Subject label: '.$event);
    $html = onystFormatTicketEmail($body,onystTicketEmailSubject($subject));
    onystCheck(substr_count($html,'<strong>'.$label.'</strong>') === 1, 'Heading label: '.$event);
    onystCheck(strpos($html,$message) !== false, 'Rich text retained');
    onystCheck(substr_count($html,'data-onyst-ticket-email="1"') === 1, 'One wrapper');
    onystCheck(onystFormatTicketEmail($html,$subject) === $html, 'Idempotent wrapper');
    onystCheck(substr_count($html,'<hr ') === 2, 'Two horizontal rules');
    onystCheck(strpos($html,'{{email_body}}') === false, 'Expanded body');
}
$standalone = $marker . '<br><br>Your ticket is resolved.<br><br>Please reply or <a href="https://example.invalid/reopen">re-open</a> for assistance.<br><br>--<br>ONYST';
$resolved = onystFormatTicketEmail($standalone,'Ticket resolved - [ONY-30000] - Example | (pending closure)');
onystCheck(strpos($resolved,'<strong>Ticket Resolved</strong>') !== false, 'Standalone Resolve heading');
onystCheck(strpos($resolved,'>re-open</a>') !== false, 'Standalone reopen link retained');
$watcher = onystFormatTicketEmail($body.'<br><br>----------------------------------------<br>YOU ARE A COLLABORATOR ON THIS TICKET','Ticket update - [ONY-30000] - Example');
onystCheck(substr_count($watcher,'YOU ARE A COLLABORATOR ON THIS TICKET') === 1, 'Collaborator notice retained once');
foreach ([$marker,'<span>----- REPLY ABOVE THIS LINE TO SEND A RESPONSE -----</span>','----- REPLY ABOVE THIS LINE TO SEND A RESPONSE -----','##- Please type your reply above this line -##'] as $separator) {
    onystCheck(onystStripQuotedTicketEmail('<p>Customer reply</p>'.$separator.'quoted content') === '<p>Customer reply</p>', 'Reply quote trimming');
}
$raw = "O'Brien \"quote\" \\path\nline\rreturn\0null" . chr(26) . ' \\n literal';
$escaped = strtr($raw,["\\"=>"\\\\",chr(0)=>'\\0',chr(10)=>'\\n',chr(13)=>'\\r',chr(26)=>'\\Z',"'"=>"\\'",'"'=>'\\"']);
onystCheck(onystUnescapeMailSql($escaped) === $raw,'SQL escaping round trip');
$title = onystFormatTicketEmail($body,'Ticket update - [ONY-30000] - <script>alert(1)</script>');
onystCheck(strpos($title,'<script>') === false && strpos($title,'&lt;script&gt;') !== false,'Escaped heading');
onystCheck(onystFormatTicketEmail('<p>Invoice</p>','Invoice 123') === '<p>Invoice</p>','Unrelated mail unchanged');
onystCheck(onystTicketEmailSubject('Ticket created - [ONY-30000] - Example') === 'Ticket created - [ONY-30000] - Example','Created subject unchanged');
echo json_encode(['passed'=>true,'checks'=>$checks,'database_access'=>false,'emails_sent'=>0]),PHP_EOL;
