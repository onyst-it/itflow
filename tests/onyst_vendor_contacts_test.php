<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/functions/onyst_vendor_contacts.php';
$checks = 0;
function vendorCheck(bool $condition, string $label): void {
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException($label);
}
$clientContact = ['contact_id'=>101,'contact_client_id'=>11,'contact_vendor_id'=>0,'contact_primary'=>0,'contact_archived_at'=>null,'client_archived_at'=>null];
$vendorContact = ['contact_id'=>201,'contact_client_id'=>0,'contact_vendor_id'=>21,'contact_primary'=>1,'contact_archived_at'=>null,'vendor_id'=>21,'vendor_client_id'=>0,'vendor_archived_at'=>null];
vendorCheck(onystVendorFitsTicket(['vendor_client_id'=>0,'vendor_archived_at'=>null],11),'Global vendor for a client');
vendorCheck(!onystVendorFitsTicket(['vendor_client_id'=>12,'vendor_archived_at'=>null],11),'Other client vendor rejected');
vendorCheck(onystContactFitsTicket($clientContact,11,21),'Client contact on client/vendor ticket');
vendorCheck(!onystContactFitsTicket($clientContact,12,21),'Other client contact rejected');
vendorCheck(onystContactFitsTicket($vendorContact,11,21),'Selected vendor contact accepted');
vendorCheck(!onystContactFitsTicket($vendorContact,11,22),'Other vendor contact rejected');
vendorCheck(!onystContactFitsTicket($vendorContact,0,0),'Unassigned ticket cannot match every vendor');
vendorCheck(onystChooseInboundContact([$vendorContact])['contact']['owner_vendor_id']===21,'Inbound vendor assignment');
vendorCheck(onystChooseInboundContact([$vendorContact])['contact']['owner_client_id']===0,'Global vendor has no invented client');
vendorCheck(onystChooseInboundContact([$clientContact])['contact']['owner_client_id']===11,'Client routing preserved');
vendorCheck(onystChooseInboundContact([$clientContact,$vendorContact])['state']==='ambiguous','Shared client/vendor email not guessed');
$second=$vendorContact;$second['contact_vendor_id']=22;$second['vendor_id']=22;
vendorCheck(onystChooseInboundContact([$vendorContact,$second])['state']==='ambiguous','Shared vendor email not guessed');
$second=$vendorContact;$second['contact_primary']=0;
vendorCheck(onystChooseInboundContact([$second,$vendorContact])['contact']['contact_primary']===1,'Duplicate contact within owner chooses primary');
$second=$vendorContact;$second['vendor_archived_at']='2026-01-01';
vendorCheck(onystChooseInboundContact([$second])['state']==='none','Archived vendor excluded');
$second=$vendorContact;$second['contact_archived_at']='2026-01-01';
vendorCheck(onystChooseInboundContact([$second])['state']==='none','Archived vendor contact excluded');
vendorCheck(onystTicketContactScope(0,0)==='0=1','No client-zero wildcard');
if (!in_array('--database',$argv,true)) {
    echo json_encode(['passed'=>true,'checks'=>$checks,'database_access'=>false,'emails_sent'=>0]),PHP_EOL;
    exit;
}

// Every writable table is connection-local TEMPORARY storage. No production rows,
// configuration, email queue entries, or uploads are modified by these tests.
$app = dirname(__DIR__);
if (file_exists($app.'/functions/custom/custom_action_handler.php')) throw new RuntimeException('Run these tests in an isolated checkout without custom hooks.');
require $app.'/config.php';
require $app.'/functions.php';
require $app.'/includes/inc_set_timezone.php';
require $app.'/functions/ticket_email_ingest.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$settingsFixture = mysqli_fetch_assoc(mysqli_query($mysqli,'SELECT * FROM settings WHERE company_id=1'));
$tables=['settings','vendors','contacts','clients','tickets','ticket_replies','ticket_watchers','ticket_attachments','email_queue','logs','app_logs','notifications','ticket_history','sla_history','tasks','canned_responses','sla_assignments','slas'];
foreach ($tables as $table) {
    $ddl = mysqli_fetch_row(mysqli_query($mysqli,"SHOW CREATE TABLE `$table`"))[1];
    mysqli_query($mysqli, preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl, 1));
}
$columns=implode(',',array_map(fn($column)=>'`'.$column.'`',array_keys($settingsFixture)));
$values=implode(',',array_map(fn($value)=>$value===null?'NULL':"'".mysqli_real_escape_string($mysqli,(string)$value)."'",array_values($settingsFixture)));
mysqli_query($mysqli,"INSERT INTO settings ($columns) VALUES ($values)");
unset($settingsFixture,$values);
mysqli_query($mysqli,"UPDATE settings SET config_ticket_next_number=900000,config_ticket_from_email='help@fixture.invalid',config_ticket_from_name='Fixture Support',config_smtp_provider='standard_smtp' WHERE company_id=1");
$session_is_admin=true;$session_user_id=1;$session_name='Fixture Agent';$session_ip='127.0.0.1';$session_user_agent='Isolated fixture';
mysqli_query($mysqli,"INSERT INTO clients SET client_id=11,client_name='Fixture Client',client_currency_code='USD',client_net_terms=0");
mysqli_query($mysqli,"INSERT INTO clients SET client_id=12,client_name='Other Fixture Client',client_currency_code='USD',client_net_terms=0");
mysqli_query($mysqli,"INSERT INTO contacts SET contact_id=101,contact_name='Fixture Client Person',contact_email='client@fixture.invalid',contact_client_id=11");
mysqli_query($mysqli,"INSERT INTO vendors SET vendor_id=21,vendor_name='Fixture Vendor',vendor_contact_name='Fixture Vendor Person',vendor_email='vendor@fixture.invalid',vendor_client_id=0");
mysqli_query($mysqli,"INSERT INTO vendors SET vendor_id=22,vendor_name='Other Fixture Vendor',vendor_email='other@fixture.invalid',vendor_client_id=12");
define('FROM_DB_UPDATER',true);
$runMigration=function() use($mysqli,$app) { require $app.'/admin/database_updates/2.7.9.php'; };
$runMigration();$runMigration();
vendorCheck(intval(mysqli_fetch_row(mysqli_query($mysqli,'SELECT COUNT(*) FROM contacts WHERE contact_vendor_id>0'))[0])===2,'Migration backfill is idempotent');
vendorCheck(intval(mysqli_fetch_row(mysqli_query($mysqli,'SELECT contact_client_id FROM contacts WHERE contact_id=101'))[0])===11,'Client ownership unchanged');
$vendorId=intval(mysqli_fetch_row(mysqli_query($mysqli,'SELECT contact_id FROM contacts WHERE contact_vendor_id=21'))[0]);
$new=onystSaveVendorContact(21,0,['name'=>'Second Vendor Person','email'=>'second@fixture.invalid','primary'=>1]);
vendorCheck(intval(mysqli_fetch_row(mysqli_query($mysqli,"SELECT contact_primary FROM contacts WHERE contact_id=$vendorId"))[0])===0,'Primary scoped to this vendor');
vendorCheck(intval(mysqli_fetch_row(mysqli_query($mysqli,"SELECT contact_user_id+contact_client_id FROM contacts WHERE contact_id=$new"))[0])===0,'No portal account or client identity');
onystSaveVendorContact(21,$new,['name'=>'Edited Vendor Person','email'=>'second@fixture.invalid','primary'=>1]);
vendorCheck(onystInboundContact('SECOND@fixture.invalid')['contact']['owner_vendor_id']===21,'Case-insensitive vendor sender lookup');
vendorCheck(onystInboundContact('other@fixture.invalid',0,21)['state']==='none','Other vendor cannot reply as known contact');
vendorCheck(onystInboundContact('vendor@fixture.invalid',0,0)['state']==='none','Unassigned inbound reply is not vendor-wide');
mysqli_query($mysqli,"INSERT INTO contacts SET contact_name='Shared Address',contact_email='vendor@fixture.invalid',contact_client_id=11");
vendorCheck(onystInboundContact('vendor@fixture.invalid')['state']==='ambiguous','Cross-owner address is held for manual assignment');
vendorCheck(onystInboundContact('vendor@fixture.invalid',0,21)['state']==='matched','Ticket context disambiguates vendor reply');
vendorCheck(onystInboundContact('vendor@fixture.invalid',11,21)['state']==='ambiguous','Shared email in a combined client/vendor scope remains ambiguous');
vendorCheck(onystInboundContact('vendor@fixture.invalid',11,21,$vendorId)['state']==='matched','Existing ticket primary contact resolves a shared address');
foreach (['client','vendor','second@fixture.invalid'] as $term) vendorCheck(count(onystPartySearch($term,'ticket'))>0,'Combined ticket search: '.$term);
foreach (onystPartySearch('Fixture','contact') as $choice) vendorCheck(strpos($choice['value'],'contact:')!==0,'New Contact searches owners only');
try { onystValidateTicketContact(11,22,$new); throw new RuntimeException('Wrong vendor was accepted'); }
catch (InvalidArgumentException $expected) { vendorCheck(true,'Forged contact/vendor pair rejected'); }
$sandbox=sys_get_temp_dir().'/onyst-vendor-test-'.bin2hex(random_bytes(6));
mkdir($sandbox.'/cron',0700,true);mkdir($sandbox.'/uploads/tmp',0700,true);chdir($sandbox.'/cron');
file_put_contents($sandbox.'/uploads/tmp/fixture.eml',"From: second@fixture.invalid\r\nSubject: Fixture vendor ticket\r\n\r\nFixture");
$config_app_name='Fixture';$company_name='Fixture';$company_phone='';$config_ticket_prefix='FIX-';$config_base_url='fixture.invalid';
$config_ticket_from_name='Fixture Support';$config_ticket_from_email='help@fixture.invalid';$config_ticket_default_billable=0;
$config_ticket_client_general_notifications=1;$config_ticket_new_ticket_notification_email='';$allowed_extensions=[];
vendorCheck(addTicket($new,'Edited Vendor Person','second@fixture.invalid',0,date('Y-m-d H:i:s'),'Fixture vendor ticket','<p>Fixture body</p>',[],'fixture.eml',[],21),'Native inbound ticket created');
$ticket=mysqli_fetch_assoc(mysqli_query($mysqli,'SELECT * FROM tickets ORDER BY ticket_id DESC LIMIT 1'));
$ticketId=intval($ticket['ticket_id']);$number=intval($ticket['ticket_number']);
vendorCheck(intval($ticket['ticket_vendor_id'])===21 && intval($ticket['ticket_contact_id'])===$new && intval($ticket['ticket_client_id'])===0,'Inbound ticket correctly belongs to vendor/contact');
vendorCheck(intval(mysqli_fetch_row(mysqli_query($mysqli,'SELECT COUNT(*) FROM ticket_watchers'))[0])===0,'Known vendor not duplicated as guest watcher');
vendorCheck(intval(mysqli_fetch_row(mysqli_query($mysqli,'SELECT COUNT(*) FROM email_queue'))[0])===0,'Inbound parsing does not queue the old immediate acknowledgment');
vendorCheck($ticket['ticket_auto_reply_email']==='second@fixture.invalid' && $ticket['ticket_auto_reply_due_at']!==null,'Delayed acknowledgment is recorded for the original vendor sender');
vendorCheck(addReply('second@fixture.invalid',date('Y-m-d H:i:s'),'Fixture vendor ticket',$number,'<p>Vendor response</p>',[]),'Native vendor reply accepted');
$reply=mysqli_fetch_assoc(mysqli_query($mysqli,'SELECT * FROM ticket_replies ORDER BY ticket_reply_id DESC LIMIT 1'));
vendorCheck($reply['ticket_reply_type']==='Client' && intval($reply['ticket_reply_by'])===$new,'Vendor reply retains native external-reply behavior');
addReply('other@fixture.invalid',date('Y-m-d H:i:s'),'Fixture vendor ticket',$number,'<p>Other vendor</p>',[]);
$reply=mysqli_fetch_assoc(mysqli_query($mysqli,'SELECT * FROM ticket_replies ORDER BY ticket_reply_id DESC LIMIT 1'));
vendorCheck($reply['ticket_reply_type']==='Internal','Unrelated vendor reply stays internal');
mysqli_query($mysqli,"INSERT INTO canned_responses SET canned_response_name='Resolved - Standard',canned_response_body='<p>Explicit fixture resolution.</p>',canned_response_category_id=0");
mysqli_begin_transaction($mysqli);
$resolved=onystApplyResolutions([$ticketId],'standard');
vendorCheck($resolved['changed']===[$ticketId] && $resolved['emails']===1,'Existing resolution sends to vendor contact');
$mail=mysqli_fetch_assoc(mysqli_query($mysqli,'SELECT * FROM email_queue ORDER BY email_id DESC LIMIT 1'));
vendorCheck($mail['email_recipient']==='second@fixture.invalid' && strpos($mail['email_content'],'<strong>Ticket Resolved</strong>')!==false,'Approved branded outbound vendor resolution');
mysqli_rollback($mysqli);
echo json_encode(['passed'=>true,'checks'=>$checks,'database_tests'=>'connection-local temporary tables','production_rows_changed'=>0,'emails_sent'=>0]),PHP_EOL;
