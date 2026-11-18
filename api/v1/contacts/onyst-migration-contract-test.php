<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/onyst-migration-contract.php';
use function Onyst\SyncroContactMigration\{normalizeContact, validateRequest, fieldsHash, canonicalJson};
use Onyst\SyncroContactMigration\InputError;
$config = ['client_id'=>2,'source_customer_id'=>'90000001','source_contact_ids'=>['90000002']]; // Synthetic fixture; no live configuration or customer data.
$checks = 0;
function check(bool $value): void { global $checks; ++$checks; if (!$value) throw new RuntimeException("Failed contract check $checks"); }
foreach ([new stdClass(), (object)['name'=>null,'email'=>null,'phone'=>null,'mobile'=>null], (object)['name'=>'','email'=>'','phone'=>'','mobile'=>'']] as $source) {
    $fields = normalizeContact($source, 2);
    foreach (['contact_name','contact_email','contact_phone','contact_mobile'] as $field) check($fields[$field] === '');
    foreach (['contact_user_id','contact_primary','contact_billing','contact_technical'] as $field) check($fields[$field] === 0);
}
check(normalizeContact((object)['name'=>'Sam'], 2)['contact_name'] === 'Sam');
check(normalizeContact((object)['firstname'=>'Sam','lastname'=>''], 2)['contact_name'] === 'Sam');
check(normalizeContact((object)['name'=>'(empty)'], 2)['contact_name'] === '');
check(normalizeContact((object)['phone'=>'+1 (425) 123-4567'], 2)['contact_phone'] === '+1 (425) 123-4567');
check(normalizeContact((object)['name'=>"D'Arcy <Test>"], 2)['contact_name'] === "D'Arcy <Test>");
check(canonicalJson((object)['b'=>new stdClass(),'a'=>null]) === '{"a":null,"b":{}}');
$payload = ['client_id'=>2,'source_customer_id'=>'90000001','source_contact_id'=>'90000002',
    'source_snapshot_json'=>'{"id":90000002,"customer_id":90000001,"name":null,"email":"","phone":null}'];
$validated = validateRequest($payload, $config);
check($validated['dry_run'] === true);
check($validated['fields']['contact_name'] === '');
check($validated['fields']['contact_email'] === '');
foreach (['client_id'=>1,'source_contact_id'=>'999999999','source_customer_id'=>'90000003','dry_run'=>'false','source_snapshot_json'=>'{"id":1,"customer_id":90000001}'] as $field=>$badValue) {
    try { validateRequest(array_replace($payload, [$field=>$badValue]), $config); check(false); }
    catch (InputError) { check(true); }
}
foreach ([[], false, 2.0, null, '-2', '2x'] as $badId) {
    try { validateRequest(array_replace($payload, ['client_id'=>$badId]), $config); check(false); }
    catch (InputError) { check(true); }
}
$many = normalizeContact((object)['name'=>str_repeat('a',200)], 2); check(strlen($many['contact_name']) === 200);
try { normalizeContact((object)['name'=>str_repeat('a',201)], 2); check(false); } catch (InputError) { check(true); }
check(fieldsHash($validated['fields']) === fieldsHash($validated['fields']));
echo json_encode(['passed'=>true,'checks'=>$checks,'covers'=>['absent/null/empty fields','partial names','source-ID and client boundaries','safe dry-run default','preserved phone format','no portal/billing/technical privileges','original JSON null/object fidelity','destination length validation']]),"\n";
