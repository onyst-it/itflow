<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$definitions = json_decode(file_get_contents(dirname(__DIR__).'/resources/onyst/canned-responses.json'),true,512,JSON_THROW_ON_ERROR);
if (!in_array('--apply',$argv,true)) {
    echo json_encode(['dry_run'=>true,'responses'=>array_column($definitions,'name'),'instruction'=>'Use --apply to create missing responses. Existing responses are never overwritten.']),PHP_EOL;
    exit;
}
chdir(dirname(__DIR__));
require 'config.php';
$mysqli->begin_transaction();
try {
    $created=0;$skipped=0;
    foreach ($definitions as $definition) {
        $name=$definition['name'];$body=$definition['body'];$category=(int)$definition['category_id'];
        $select=$mysqli->prepare('SELECT canned_response_id FROM canned_responses WHERE canned_response_name=? AND canned_response_archived_at IS NULL');
        $select->bind_param('s',$name);$select->execute();
        if ($select->get_result()->num_rows) {++$skipped;continue;}
        $insert=$mysqli->prepare('INSERT INTO canned_responses (canned_response_name,canned_response_body,canned_response_category_id) VALUES (?,?,?)');
        $insert->bind_param('ssi',$name,$body,$category);$insert->execute();++$created;
    }
    $mysqli->commit();
    echo json_encode(['created'=>$created,'existing_responses_preserved'=>$skipped]),PHP_EOL;
} catch (Throwable $error) {$mysqli->rollback();throw $error;}
