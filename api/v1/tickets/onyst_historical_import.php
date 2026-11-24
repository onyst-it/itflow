<?php
declare(strict_types=1);

/*
 * One-time ONYST Syncro history transfer. Normal ticket creation, reply, mail,
 * and SLA entry points are intentionally outside this request handler.
 */
const ONYST_SYNCRO_IMPORT_ORIGIN = 'Syncro Import';
const ONYST_SYNCRO_IMPORT_PREFIX = 'SYNCRO-';
const ONYST_SYNCRO_IMPORT_PLAN = [
    'pilot' => [7, 'bb5d7d56272351b93d44d2f23bab47c2636d05a6c30685a52b850bdeae5409be'],
    'full' => [8855, 'ec8d9892f874240fd4ba277baf1d4219d42350f1a28eccb33232d27e2679592c'],
];

final class OnystSyncroImportError extends RuntimeException
{
}

function onystSyncroImportResponse(int $status, array $report): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function onystSyncroImportDate(mixed $value, DateTimeZone $timezone, bool $required = false): ?string
{
    if ($value === null || $value === '') {
        if ($required) {
            throw new OnystSyncroImportError('original_date_missing');
        }
        return null;
    }
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
        throw new OnystSyncroImportError('original_date_invalid');
    }
    $date = new DateTimeImmutable($value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
        throw new OnystSyncroImportError('original_date_invalid');
    }
    return $date->setTimezone($timezone)->format('Y-m-d H:i:s');
}

function onystSyncroImportId(mixed $value, bool $optional = false): int
{
    if ($optional && ($value === null || $value === 0 || $value === '0' || $value === '')) {
        return 0;
    }
    if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9]\d{0,9}$/D', (string) $value)) {
        throw new OnystSyncroImportError('invalid_id');
    }
    return (int) $value;
}

function onystSyncroImportText(mixed $value, int $limit): string
{
    if ($value === null) {
        return '';
    }
    if (!is_string($value) || strlen($value) > $limit || !preg_match('//u', $value)) {
        throw new OnystSyncroImportError('invalid_text');
    }
    return $value;
}

function onystSyncroImportBody(string $text, string $author, string $subject, string $timestamp): string
{
    $encode = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $header = array_filter([$author, $timestamp, $subject], static fn(string $value): bool => $value !== '');
    return ($header ? '<p><small>' . $encode(implode(' · ', $header)) . '</small></p>' : '')
        . nl2br($encode($text), false);
}

function onystSyncroImportTableExists(mysqli $db): bool
{
    return $db->query("SHOW TABLES LIKE 'onyst\\_syncro\\_ticket\\_import'")->num_rows === 1;
}

function onystSyncroImportInstall(mysqli $db): void
{
    $db->query("CREATE TABLE IF NOT EXISTS onyst_syncro_ticket_import (
        source_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        source_number INT UNSIGNED NOT NULL UNIQUE,
        source_customer_id BIGINT UNSIGNED NOT NULL,
        client_id INT NOT NULL,
        vendor_id INT NOT NULL,
        contact_id INT NOT NULL,
        ticket_id INT NULL UNIQUE,
        source_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        original_timestamps LONGTEXT NULL,
        imported_at DATETIME(6) NULL,
        last_attempt_at DATETIME NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        last_error VARCHAR(200) NULL,
        INDEX pending_tickets (ticket_id, last_attempt_at, source_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function onystSyncroImportPreflight(mysqli $db): array
{
    $tables = ['tickets', 'ticket_replies', 'ticket_attachments', 'email_queue'];
    $engines = $db->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('tickets','ticket_replies','ticket_attachments','email_queue')")
        ->fetch_all(MYSQLI_ASSOC);
    if (count($engines) !== count($tables) || array_filter($engines, static fn(array $row): bool => $row['ENGINE'] !== 'InnoDB')) {
        throw new OnystSyncroImportError('ticket_tables_not_transactional');
    }
    $triggers = $db->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN ('tickets','ticket_replies','ticket_attachments')");
    if ($triggers->num_rows) {
        throw new OnystSyncroImportError('ticket_insert_triggers_require_review');
    }
    if ($db->query("SHOW COLUMNS FROM tickets LIKE 'ticket_auto_reply_due_at'")->num_rows !== 1) {
        throw new OnystSyncroImportError('ticket_auto_reply_schema_not_deployed');
    }
    return [
        'timezone' => $db->query('SELECT config_timezone FROM settings WHERE company_id=1')->fetch_row()[0],
        'statuses' => $db->query('SELECT ticket_status_id, ticket_status_name FROM ticket_statuses ORDER BY ticket_status_id')->fetch_all(MYSQLI_ASSOC),
    ];
}

function onystSyncroImportCheckOwner(mysqli $db, array $row): void
{
    $client = (int) $row['client_id'];
    $vendor = (int) $row['vendor_id'];
    $contact = (int) $row['contact_id'];
    if (!apiUserCanAccessClient($client) || ($client && $vendor) || ($contact && !$client && !$vendor)) {
        throw new OnystSyncroImportError('invalid_destination_owner');
    }
    if ($client && !$db->execute_query('SELECT client_id FROM clients WHERE client_id=?', [$client])->num_rows) {
        throw new OnystSyncroImportError('destination_client_missing');
    }
    if ($vendor && !$db->execute_query('SELECT vendor_id FROM vendors WHERE vendor_id=?
        AND vendor_client_id=0 AND vendor_archived_at IS NULL', [$vendor])->num_rows) {
        throw new OnystSyncroImportError('destination_vendor_unavailable');
    }
    if ($contact && !$db->execute_query('SELECT contact_id FROM contacts WHERE contact_id=?
        AND contact_client_id=? AND contact_vendor_id=? AND contact_archived_at IS NULL', [$contact, $client, $vendor])->num_rows) {
        throw new OnystSyncroImportError('destination_contact_mismatch');
    }
}

function onystSyncroImportRegister(mysqli $db, string $phase, array $input): array
{
    $approved = ONYST_SYNCRO_IMPORT_PLAN[$phase] ?? null;
    if (!$approved || !array_is_list($input) || count($input) !== $approved[0]) {
        throw new OnystSyncroImportError('unapproved_ticket_scope');
    }
    $keys = ['source_id', 'source_number', 'source_customer_id', 'client_id', 'vendor_id', 'contact_id'];
    $items = [];
    foreach ($input as $tuple) {
        if (!is_array($tuple) || !array_is_list($tuple) || count($tuple) !== 6) {
            throw new OnystSyncroImportError('invalid_plan_row');
        }
        $row = [];
        foreach ($keys as $index => $key) {
            $row[$key] = onystSyncroImportId($tuple[$index], $index >= 2);
        }
        if (isset($items[$row['source_id']]) || ($row['client_id'] && $row['vendor_id']) ||
            ($row['contact_id'] && !$row['client_id'] && !$row['vendor_id'])) {
            throw new OnystSyncroImportError('invalid_plan_row');
        }
        $items[$row['source_id']] = $row;
    }
    ksort($items, SORT_NUMERIC);
    $scope = implode("\n", array_map(static fn(array $row): string => implode(':', array_slice($row, 0, 3)), $items));
    if (!hash_equals($approved[1], hash('sha256', $scope))) {
        throw new OnystSyncroImportError('unapproved_ticket_scope');
    }

    onystSyncroImportInstall($db);
    $db->begin_transaction();
    try {
        $existing = [];
        $query = $db->query('SELECT source_id, source_number, source_customer_id, client_id, vendor_id, contact_id
            FROM onyst_syncro_ticket_import ORDER BY source_id FOR UPDATE');
        while ($row = $query->fetch_assoc()) {
            $existing[(int) $row['source_id']] = array_map('intval', $row);
        }
        $insert = $db->prepare('INSERT INTO onyst_syncro_ticket_import
            (source_id, source_number, source_customer_id, client_id, vendor_id, contact_id) VALUES (?,?,?,?,?,?)');
        foreach ($items as $sourceId => $row) {
            if (isset($existing[$sourceId])) {
                if ($existing[$sourceId] !== $row) {
                    throw new OnystSyncroImportError('registered_assignment_changed');
                }
            } else {
                $insert->execute(array_values($row));
            }
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
    return ['registered' => count($items), 'phase' => $phase, 'scope_sha256' => $approved[1]];
}

function onystSyncroImportStatus(mysqli $db): array
{
    if (!onystSyncroImportTableExists($db)) {
        return ['registered' => 0, 'imported' => 0, 'pending' => 0, 'auto_replies' => 0, 'queued_emails' => 0];
    }
    $counts = $db->query('SELECT COUNT(*) AS registered, COUNT(ticket_id) AS imported,
        COALESCE(SUM(ticket_id IS NULL),0) AS pending FROM onyst_syncro_ticket_import')->fetch_assoc();
    $autoReplies = $db->query("SELECT COUNT(*) FROM onyst_syncro_ticket_import m JOIN tickets t ON t.ticket_id=m.ticket_id
        WHERE t.ticket_auto_reply_due_at IS NOT NULL OR t.ticket_auto_reply_email IS NOT NULL
        OR t.ticket_sla_id<>0 OR EXISTS(SELECT 1 FROM ticket_replies r WHERE r.ticket_reply_ticket_id=t.ticket_id AND r.ticket_reply_auto_ack=1)")->fetch_row()[0];
    $queued = $db->query("SELECT COUNT(*) FROM email_queue WHERE email_subject LIKE '%[SYNCRO-%'")->fetch_row()[0];
    return array_map('intval', $counts) + ['auto_replies' => (int) $autoReplies, 'queued_emails' => (int) $queued];
}

function onystSyncroImportOne(mysqli $db, array $payload, string $appRoot, bool $preview): array
{
    $source = $payload['source'] ?? [];
    $id = onystSyncroImportId($source['id'] ?? null);
    $number = onystSyncroImportId($source['number'] ?? null);
    $customer = onystSyncroImportId($source['customer_id'] ?? null, true);
    $sourceStatus = onystSyncroImportText($source['status'] ?? null, 100);
    $subject = onystSyncroImportText($source['subject'] ?? null, 2000);
    $priority = onystSyncroImportText($source['priority'] ?? null, 200);
    if (mb_strlen($subject, 'UTF-8') > 500) {
        throw new OnystSyncroImportError('subject_exceeds_destination_limit');
    }
    $setting = $db->query('SELECT config_timezone FROM settings WHERE company_id=1')->fetch_row();
    $timezone = new DateTimeZone($setting[0]);
    $original = array_intersect_key($source, array_flip(['created_at', 'updated_at', 'resolved_at', 'closed_at', 'due_at', 'status']));
    $created = onystSyncroImportDate($source['created_at'] ?? null, $timezone, true);
    $updated = onystSyncroImportDate($source['updated_at'] ?? null, $timezone);
    $resolved = onystSyncroImportDate($source['resolved_at'] ?? null, $timezone);
    $closed = onystSyncroImportDate($source['closed_at'] ?? null, $timezone);
    $due = onystSyncroImportDate($source['due_at'] ?? null, $timezone);
    $status = $db->execute_query('SELECT ticket_status_id FROM ticket_statuses WHERE LOWER(TRIM(ticket_status_name))=LOWER(?)', [$sourceStatus]);
    if ($status->num_rows !== 1) {
        throw new OnystSyncroImportError('destination_status_unavailable');
    }
    $statusId = (int) $status->fetch_row()[0];

    $comments = $payload['comments'] ?? null;
    $attachments = $payload['attachments'] ?? null;
    if (!is_array($comments) || !array_is_list($comments) || !is_array($attachments) || !array_is_list($attachments)
        || count($comments) !== ($payload['source_comment_count'] ?? null)
        || count($attachments) !== ($payload['source_attachment_count'] ?? null)) {
        throw new OnystSyncroImportError('source_history_incomplete');
    }
    $commentIds = [];
    $replies = [];
    $attachmentsById = [];
    foreach ($comments as $comment) {
        $commentId = onystSyncroImportId($comment['id'] ?? null);
        if (isset($commentIds[$commentId]) || !is_bool($comment['private'] ?? null)) {
            throw new OnystSyncroImportError('invalid_original_reply');
        }
        $commentIds[$commentId] = true;
        $replyCreated = onystSyncroImportDate($comment['created_at'] ?? null, $timezone, true);
        $replyUpdated = onystSyncroImportDate($comment['updated_at'] ?? null, $timezone);
        $body = onystSyncroImportBody(
            onystSyncroImportText($comment['body'] ?? null, 4 * 1024 * 1024),
            onystSyncroImportText($comment['author'] ?? null, 800),
            onystSyncroImportText($comment['subject'] ?? null, 2000),
            $comment['created_at'],
        );
        $replies[$commentId] = [$body, $comment['private'] ? 'Internal' : 'Public', $replyCreated, $replyUpdated];
        $original['replies'][] = ['id' => $commentId, 'created_at' => $comment['created_at'], 'updated_at' => $comment['updated_at'] ?? null];
    }
    foreach ($attachments as $file) {
        $fileId = onystSyncroImportId($file['id'] ?? null);
        $replyId = onystSyncroImportId($file['comment_id'] ?? null, true);
        $name = onystSyncroImportText($file['name'] ?? null, 1020);
        $bytes = is_string($file['base64'] ?? null) ? base64_decode($file['base64'], true) : false;
        if (isset($attachmentsById[$fileId]) || (bool) $replyId && !isset($replies[$replyId]) ||
            $name === '' || mb_strlen($name, 'UTF-8') > 255 || preg_match('/[\/\\\\\x00-\x1f]/', $name) ||
            $bytes === false || strlen($bytes) !== ($file['size'] ?? null) || strlen($bytes) > 25 * 1024 * 1024) {
            throw new OnystSyncroImportError('source_attachment_unverified');
        }
        $fileCreated = onystSyncroImportDate($file['created_at'] ?? null, $timezone, true);
        $attachmentsById[$fileId] = [$name, $bytes, $fileCreated, $replyId];
        $original['attachments'][] = ['id' => $fileId, 'created_at' => $file['created_at'], 'sha256' => hash('sha256', $bytes)];
    }
    $hash = hash('sha256', json_encode([$source, $comments, $original['attachments'] ?? []], JSON_THROW_ON_ERROR));
    $paths = [];
    $directory = null;

    $db->begin_transaction();
    try {
        $row = $db->execute_query('SELECT * FROM onyst_syncro_ticket_import WHERE source_id=? FOR UPDATE', [$id])->fetch_assoc();
        if (!$row || (int) $row['source_number'] !== $number || (int) $row['source_customer_id'] !== $customer) {
            throw new OnystSyncroImportError('ticket_outside_approved_source_scope');
        }
        onystSyncroImportCheckOwner($db, $row);
        if ($row['ticket_id'] !== null) {
            if (!hash_equals($row['source_sha256'], $hash) ||
                !$db->execute_query('SELECT ticket_id FROM tickets WHERE ticket_id=? AND ticket_source=?', [$row['ticket_id'], ONYST_SYNCRO_IMPORT_ORIGIN])->num_rows) {
                throw new OnystSyncroImportError('previous_import_requires_review');
            }
            $db->commit();
            return ['result' => 'already_present', 'source_id' => $id, 'ticket_id' => (int) $row['ticket_id']];
        }

        $response = [
            'result' => $preview ? 'would_import' : 'imported', 'source_id' => $id, 'number' => $number,
            'client_id' => (int) $row['client_id'], 'vendor_id' => (int) $row['vendor_id'], 'contact_id' => (int) $row['contact_id'],
            'created_at' => $created, 'updated_at' => $updated, 'resolved_at' => $resolved, 'closed_at' => $closed,
            'reply_count' => count($replies), 'attachment_count' => count($attachmentsById), 'auto_reply_scheduled' => false,
        ];
        if ($preview) {
            $db->rollback();
            return $response;
        }
        if ($db->execute_query('SELECT ticket_id FROM tickets WHERE ticket_prefix=? AND ticket_number=?', [ONYST_SYNCRO_IMPORT_PREFIX, $number])->num_rows) {
            throw new OnystSyncroImportError('ticket_reference_already_exists');
        }

        $details = '';
        $db->execute_query("INSERT INTO tickets
            (ticket_prefix,ticket_number,ticket_source,ticket_subject,ticket_details,ticket_priority,ticket_status,ticket_url_key,
             ticket_created_at,ticket_updated_at,ticket_resolved_at,ticket_closed_at,ticket_due_at,ticket_created_by,
             ticket_client_id,ticket_vendor_id,ticket_contact_id,ticket_sla_id,ticket_auto_reply_due_at,ticket_auto_reply_email)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,0,NULL,NULL)",
            [ONYST_SYNCRO_IMPORT_PREFIX, $number, ONYST_SYNCRO_IMPORT_ORIGIN, $subject, $details, $priority ?: null, $statusId,
                bin2hex(random_bytes(24)), $created, $updated, $resolved, $closed, $due, $row['client_id'], $row['vendor_id'], $row['contact_id']]);
        $ticketId = (int) $db->insert_id;

        $replyIds = [];
        $insertReply = $db->prepare("INSERT INTO ticket_replies
            (ticket_reply,ticket_reply_type,ticket_reply_created_at,ticket_reply_updated_at,ticket_reply_by,ticket_reply_ticket_id,ticket_reply_auto_ack)
            VALUES (?,?,?,?,0,?,0)");
        foreach ($replies as $commentId => $values) {
            $insertReply->execute([...$values, $ticketId]);
            $replyIds[$commentId] = (int) $db->insert_id;
        }

        if ($attachmentsById) {
            $root = realpath($appRoot . '/uploads');
            if (!$root) {
                throw new OnystSyncroImportError('attachment_storage_unavailable');
            }
            $storage = $root . '/tickets';
            if ((!is_dir($storage) && !mkdir($storage, 0750, true)) || realpath($storage) !== $storage) {
                throw new OnystSyncroImportError('attachment_storage_unavailable');
            }
            $targetDirectory = $storage . '/' . $ticketId;
            if (!mkdir($targetDirectory, 0750)) {
                throw new OnystSyncroImportError('attachment_storage_unavailable');
            }
            $directory = $targetDirectory;
            $insertFile = $db->prepare('INSERT INTO ticket_attachments
                (ticket_attachment_name,ticket_attachment_reference_name,ticket_attachment_created_at,ticket_attachment_ticket_id,ticket_attachment_reply_id)
                VALUES (?,?,?,?,?)');
            foreach ($attachmentsById as [$name, $bytes, $fileCreated, $replyId]) {
                $reference = bin2hex(random_bytes(20)) . '.bin';
                $file = $directory . '/' . $reference;
                $paths[] = $file;
                if (file_put_contents($file, $bytes, LOCK_EX) !== strlen($bytes)) {
                    throw new OnystSyncroImportError('attachment_write_failed');
                }
                $insertFile->execute([$name, $reference, $fileCreated, $ticketId, $replyIds[$replyId] ?? null]);
            }
        }

        $stored = $db->execute_query('SELECT ticket_created_at,ticket_updated_at,ticket_resolved_at,ticket_closed_at,
            ticket_auto_reply_due_at,ticket_auto_reply_email,ticket_sla_id FROM tickets WHERE ticket_id=?', [$ticketId])->fetch_assoc();
        if (!$stored || $stored['ticket_created_at'] !== $created || $stored['ticket_updated_at'] !== $updated ||
            $stored['ticket_resolved_at'] !== $resolved || $stored['ticket_closed_at'] !== $closed ||
            $stored['ticket_auto_reply_due_at'] !== null || $stored['ticket_auto_reply_email'] !== null || (int) $stored['ticket_sla_id'] !== 0) {
            throw new OnystSyncroImportError('ticket_history_verification_failed');
        }
        $savedReplies = $db->execute_query('SELECT ticket_reply_type,ticket_reply_created_at,ticket_reply_updated_at,ticket_reply_auto_ack
            FROM ticket_replies WHERE ticket_reply_ticket_id=? ORDER BY ticket_reply_id', [$ticketId])->fetch_all(MYSQLI_ASSOC);
        if (count($savedReplies) !== count($replies)) {
            throw new OnystSyncroImportError('reply_count_verification_failed');
        }
        foreach (array_values($replies) as $index => $reply) {
            $saved = $savedReplies[$index];
            if ($saved['ticket_reply_type'] !== $reply[1] || $saved['ticket_reply_created_at'] !== $reply[2] ||
                $saved['ticket_reply_updated_at'] !== $reply[3] || (int) $saved['ticket_reply_auto_ack'] !== 0) {
                throw new OnystSyncroImportError('reply_history_verification_failed');
            }
        }
        $db->execute_query('UPDATE onyst_syncro_ticket_import SET ticket_id=?, source_sha256=?, original_timestamps=?,
            imported_at=NOW(6), last_error=NULL WHERE source_id=?', [$ticketId, $hash, json_encode($original, JSON_THROW_ON_ERROR), $id]);
        $db->commit();
        return $response + ['ticket_id' => $ticketId];
    } catch (Throwable $error) {
        $db->rollback();
        foreach ($paths as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if ($directory && is_dir($directory)) {
            rmdir($directory);
        }
        throw $error;
    }
}

if (defined('ONYST_SYNCRO_IMPORT_FUNCTIONS_ONLY')) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
    onystSyncroImportResponse(405, ['success' => false, 'error' => 'json_post_required']);
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 40 * 1024 * 1024) {
    onystSyncroImportResponse(413, ['success' => false, 'error' => 'request_size_exceeds_limit']);
}
$appRoot = realpath(__DIR__ . '/../../..');
if (gethostname() !== 'ony-runcloud-srv01' || $appRoot !== '/home/itflow/webapps/app-itflow') {
    onystSyncroImportResponse(404, ['success' => false, 'error' => 'wrong_app']);
}

require_once __DIR__ . '/../validate_api_key.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    if (strtolower($mysqli->query('SELECT DATABASE()')->fetch_row()[0]) !== 'app_itflow') {
        throw new OnystSyncroImportError('wrong_database');
    }
    $key = $mysqli->execute_query('SELECT api_key_id, api_key_user_id FROM api_keys WHERE api_key_secret=?', [$api_key])->fetch_assoc();
    if (!$key || (int) $key['api_key_id'] !== 1 || (int) $key['api_key_user_id'] !== 2 ||
        lookupUserPermission('module_support') < 2) {
        onystSyncroImportResponse(403, ['success' => false, 'error' => 'migration_key_not_authorized']);
    }

    $action = $_POST['action'] ?? null;
    if ($action === 'status') {
        $response = onystSyncroImportPreflight($mysqli) + onystSyncroImportStatus($mysqli);
    } elseif ($action === 'register') {
        onystSyncroImportPreflight($mysqli);
        $response = onystSyncroImportRegister($mysqli, (string) ($_POST['phase'] ?? ''), $_POST['tickets'] ?? []);
    } elseif ($action === 'pending') {
        $limit = max(1, min(40, (int) ($_POST['limit'] ?? 20)));
        $response = ['tickets' => $mysqli->query("SELECT source_id,source_number,source_customer_id,client_id,vendor_id,contact_id
            FROM onyst_syncro_ticket_import WHERE ticket_id IS NULL AND (last_attempt_at IS NULL OR last_attempt_at <= NOW() - INTERVAL 10 MINUTE)
            ORDER BY last_attempt_at IS NOT NULL,last_attempt_at,source_id LIMIT $limit")->fetch_all(MYSQLI_ASSOC)]
            + onystSyncroImportStatus($mysqli);
    } elseif ($action === 'defer') {
        $id = onystSyncroImportId($_POST['source_id'] ?? null);
        $reason = onystSyncroImportText($_POST['reason'] ?? null, 200);
        $mysqli->execute_query('UPDATE onyst_syncro_ticket_import SET attempts=attempts+1, last_attempt_at=NOW(), last_error=?
            WHERE source_id=? AND ticket_id IS NULL', [$reason, $id]);
        $response = ['result' => 'retained_for_retry', 'source_id' => $id];
    } elseif ($action === 'import') {
        $response = onystSyncroImportOne($mysqli, $_POST, $appRoot, ($_POST['preview'] ?? false) === true);
    } else {
        throw new OnystSyncroImportError('unknown_action');
    }
    onystSyncroImportResponse(200, ['success' => true] + $response);
} catch (OnystSyncroImportError $error) {
    onystSyncroImportResponse(409, ['success' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('ONYST Syncro ticket import failed: ' . get_class($error) . ' (' . $error->getCode() . ')');
    onystSyncroImportResponse(500, ['success' => false, 'error' => 'ticket_import_failed']);
}
