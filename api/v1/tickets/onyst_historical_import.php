<?php
declare(strict_types=1);

/*
 * One-time ONYST Syncro history transfer. Normal ticket creation, reply, mail,
 * and SLA entry points are intentionally outside this request handler.
 */
const ONYST_SYNCRO_IMPORT_ORIGIN = 'Syncro Import';
const ONYST_SYNCRO_IMPORT_PREFIX = 'ONY-';
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
        'original_attachment_retrieval' => $db->query("SHOW COLUMNS FROM ticket_attachments WHERE Field='ticket_attachment_private'")->num_rows === 1,
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


function onystSyncroImportRelabel(mysqli $db, int $expectedCount, bool $preview): array
{
    $db->begin_transaction();
    try {
        $settings = $db->query('SELECT config_ticket_prefix,config_ticket_next_number
            FROM settings WHERE company_id=1 FOR UPDATE')->fetch_assoc();
        if (!$settings || $settings['config_ticket_prefix'] !== ONYST_SYNCRO_IMPORT_PREFIX ||
            (int) $settings['config_ticket_next_number'] < 30000) {
            throw new OnystSyncroImportError('native_ticket_numbering_changed');
        }

        $sql = 'SELECT m.source_id,m.source_number,t.ticket_id,t.ticket_number,t.ticket_source,t.ticket_prefix,
            t.ticket_created_at,t.ticket_updated_at,t.ticket_resolved_at,t.ticket_closed_at,t.ticket_due_at
            FROM onyst_syncro_ticket_import m LEFT JOIN tickets t ON t.ticket_id=m.ticket_id
            WHERE m.ticket_id IS NOT NULL ORDER BY m.source_id FOR UPDATE';
        $before = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
        if (count($before) !== $expectedCount) {
            throw new OnystSyncroImportError('imported_ticket_count_changed');
        }

        $currentRefs = [];
        $refs = $db->execute_query('SELECT ticket_id,ticket_number FROM tickets WHERE ticket_prefix=?', [ONYST_SYNCRO_IMPORT_PREFIX]);
        while ($ref = $refs->fetch_assoc()) {
            $currentRefs[(int) $ref['ticket_number']][] = (int) $ref['ticket_id'];
        }
        $oldPrefixCount = 0;
        foreach ($before as $row) {
            $number = (int) $row['source_number'];
            if (!$row['ticket_id'] || $number <= 0 || $number >= 30000 || $number !== (int) $row['ticket_number'] ||
                $row['ticket_source'] !== ONYST_SYNCRO_IMPORT_ORIGIN ||
                !in_array($row['ticket_prefix'], ['SYNCRO-', ONYST_SYNCRO_IMPORT_PREFIX], true)) {
                throw new OnystSyncroImportError('imported_ticket_identity_changed');
            }
            if (array_diff($currentRefs[$number] ?? [], [(int) $row['ticket_id']])) {
                throw new OnystSyncroImportError('ticket_reference_collision');
            }
            if ($row['ticket_prefix'] === 'SYNCRO-') {
                $oldPrefixCount++;
            }
        }

        if (!$preview && $oldPrefixCount) {
            $db->execute_query("UPDATE tickets t JOIN onyst_syncro_ticket_import m ON m.ticket_id=t.ticket_id
                SET t.ticket_prefix=?,t.ticket_updated_at=t.ticket_updated_at
                WHERE t.ticket_source=? AND t.ticket_prefix='SYNCRO-'",
                [ONYST_SYNCRO_IMPORT_PREFIX, ONYST_SYNCRO_IMPORT_ORIGIN]);
            if ($db->affected_rows !== $oldPrefixCount) {
                throw new OnystSyncroImportError('prefix_update_count_changed');
            }

            $after = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
            foreach ($before as $index => $row) {
                $row['ticket_prefix'] = ONYST_SYNCRO_IMPORT_PREFIX;
                if ($row !== ($after[$index] ?? null)) {
                    throw new OnystSyncroImportError('original_ticket_dates_or_identity_changed');
                }
            }
        }
        $db->commit();
        return [
            'prefix' => ONYST_SYNCRO_IMPORT_PREFIX,
            'verified_imported_tickets' => count($before),
            'legacy_prefix_remaining' => $preview ? $oldPrefixCount : 0,
            'prefixes_changed' => $preview ? 0 : $oldPrefixCount,
            'native_next_number' => (int) $settings['config_ticket_next_number'],
            'original_numbers_and_timestamps_verified' => true,
        ];
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
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
    $queued = $db->query("SELECT COUNT(*) FROM email_queue e WHERE EXISTS (
        SELECT 1 FROM onyst_syncro_ticket_import m JOIN tickets t ON t.ticket_id=m.ticket_id
        WHERE e.email_queued_at >= m.imported_at - INTERVAL 1 SECOND
        AND (LOCATE(CONCAT('[', t.ticket_prefix, t.ticket_number, ']'), e.email_subject) > 0
             OR LOCATE(CONCAT('[SYNCRO-', m.source_number, ']'), e.email_subject) > 0))")->fetch_row()[0];
    return array_map('intval', $counts) + ['auto_replies' => (int) $autoReplies, 'queued_emails' => (int) $queued];
}

final class OnystSyncroImportTemporaryFile
{
    public readonly string $path;
    /** @var resource */
    public $stream;

    public function __construct()
    {
        $directory = rtrim(sys_get_temp_dir(), '/\\') . '/itflow-syncro-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new OnystSyncroImportError('attachment_temporary_storage_unavailable');
        }
        $this->path = $directory . '/original';
        $stream = fopen($this->path, 'x+b');
        if ($stream === false) {
            rmdir($directory);
            throw new OnystSyncroImportError('attachment_temporary_storage_unavailable');
        }
        $this->stream = $stream;
    }

    public function __destruct()
    {
        fclose($this->stream);
        unlink($this->path);
        rmdir(dirname($this->path));
    }
}

function onystSyncroImportDownload(string $url, int $size, ?string $md5): array
{
    // Syncro signs the original file URL for this S3 bucket. Do not forward Syncro
    // credentials or follow redirects. Never log the signed URL.
    if (strlen($url) > 8192 || !preg_match(
        '~\Ahttps://s3\.amazonaws\.com/attachments\.servably\.com/[^?\s#]+[?][^\s#]+\z~D',
        $url
    )) {
        throw new OnystSyncroImportError('original_attachment_url_unrecognized');
    }
    if ($size < 0 || $size > 500 * 1024 * 1024 ||
        ($md5 !== null && !preg_match('/^[a-f0-9]{32}$/D', $md5))) {
        throw new OnystSyncroImportError('original_attachment_metadata_invalid');
    }

    $staged = new OnystSyncroImportTemporaryFile();
    $stream = $staged->stream;
    if (!function_exists('curl_init') || !function_exists('curl_exec')) {
        throw new OnystSyncroImportError('original_attachment_transport_unavailable');
    }
    $curl = curl_init($url);
    $sha = hash_init('sha256');
    $md = hash_init('md5');
    $received = 0;
    curl_setopt_array($curl, [
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 14,
        CURLOPT_PATH_AS_IS => true,
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($stream, $size, $sha, $md, &$received): int {
            $length = strlen($chunk);
            if ($received + $length > $size || fwrite($stream, $chunk) !== $length) {
                return 0;
            }
            $received += $length;
            hash_update($sha, $chunk);
            hash_update($md, $chunk);

            return $length;
        },
    ]);

    $downloaded = curl_exec($curl);
    $http = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($http !== 200) {
        throw new OnystSyncroImportError('original_attachment_download_http_' . $http);
    }
    if ($downloaded === false || $received !== $size ||
        ($md5 !== null && !hash_equals($md5, hash_final($md)))) {
        throw new OnystSyncroImportError('original_attachment_bytes_mismatch');
    }

    rewind($stream);
    return [$staged, hash_final($sha)];
}

function onystSyncroImportSaveAttachment(string $path, array $file): void
{
    [, $staged, , , $private, $size, $sha] = $file;
    if ($private) {
        $key = backupEncryptionKey();
        if (!backupSealArchive(['attachment' => $staged->path], $path, $key)) {
            throw new OnystSyncroImportError('private_attachment_storage_failed');
        }
        $opened = openPrivateTicketAttachment($path);
        if ($opened === null) {
            throw new OnystSyncroImportError('private_attachment_readback_failed');
        }
        [$archive, $saved, $savedSize] = $opened;
        $hash = hash_init('sha256');
        $verifiedSize = hash_update_stream($hash, $saved);
        fclose($saved);
        $archive->close();
        $verified = $size === $savedSize && $size === $verifiedSize && hash_equals($sha, hash_final($hash));
    } else {
        $saved = fopen($path, 'xb');
        if ($saved === false) {
            throw new OnystSyncroImportError('attachment_storage_unavailable');
        }
        $savedSize = stream_copy_to_stream($staged->stream, $saved);
        fclose($saved);
        $verified = $savedSize === $size && hash_file('sha256', $path) === $sha;
    }
    if (!$verified) {
        throw new OnystSyncroImportError('attachment_readback_mismatch');
    }
}

function onystSyncroImportAttachmentAudit(mysqli $db): array
{
    $counts = $db->query('SELECT COUNT(*) AS files, COUNT(DISTINCT a.ticket_attachment_ticket_id) AS tickets_with_files,
        COALESCE(SUM(a.ticket_attachment_private), 0) AS private_files
        FROM ticket_attachments a JOIN onyst_syncro_ticket_import m ON a.ticket_attachment_ticket_id = m.ticket_id')
        ->fetch_assoc();
    $referenceMismatches = $db->execute_query('SELECT COUNT(*) FROM onyst_syncro_ticket_import m
        LEFT JOIN tickets t ON m.ticket_id = t.ticket_id WHERE m.ticket_id IS NOT NULL AND
        (t.ticket_id IS NULL OR t.ticket_prefix <> ? OR t.ticket_number <> m.source_number OR t.ticket_source <> ?)',
        [ONYST_SYNCRO_IMPORT_PREFIX, ONYST_SYNCRO_IMPORT_ORIGIN])->fetch_row()[0];
    $pendingReasons = $db->query('SELECT COALESCE(last_error,\'never_attempted\') AS reason, COUNT(*) AS tickets
        FROM onyst_syncro_ticket_import WHERE ticket_id IS NULL GROUP BY last_error ORDER BY tickets DESC')
        ->fetch_all(MYSQLI_ASSOC);

    return onystSyncroImportStatus($db) + array_map('intval', $counts) + [
        'reference_mismatches' => (int) $referenceMismatches,
        'pending_reasons' => $pendingReasons,
    ];
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
    $setting = $db->query('SELECT config_timezone,config_ticket_prefix,config_ticket_next_number FROM settings WHERE company_id=1')->fetch_row();
    if ($setting[1] !== ONYST_SYNCRO_IMPORT_PREFIX || (int) $setting[2] < 30000 || $number >= 30000) {
        throw new OnystSyncroImportError('native_ticket_numbering_changed');
    }
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
    // Confirm the scope before contacting storage for any file. Recheck the same row
    // under the write lock in case another runner saved it during the downloads.
    $scope = $db->execute_query('SELECT * FROM onyst_syncro_ticket_import WHERE source_id=?', [$id])->fetch_assoc();
    if (!$scope || (int) $scope['source_number'] !== $number || (int) $scope['source_customer_id'] !== $customer) {
        throw new OnystSyncroImportError('ticket_outside_approved_source_scope');
    }
    onystSyncroImportCheckOwner($db, $scope);

    $totalSize = 0;
    foreach ($attachments as $file) {
        $fileId = onystSyncroImportId($file['id'] ?? null);
        $replyId = onystSyncroImportId($file['comment_id'] ?? null, true);
        $name = onystSyncroImportText($file['name'] ?? null, 1020);
        $size = $file['size'] ?? null;
        $private = $file['private'] ?? isset($file['url']);
        if (isset($attachmentsById[$fileId]) || ($replyId && !isset($replies[$replyId])) ||
            $name === '' || mb_strlen($name, 'UTF-8') > 255 || preg_match('/[\/\\\\\x00-\x1f]/', $name) ||
            !is_bool($private) || !is_int($size) || $size < 0 || ($totalSize += $size) > 500 * 1024 * 1024) {
            throw new OnystSyncroImportError('source_attachment_unverified');
        }
        $private = $private || ($replyId && $replies[$replyId][1] === 'Internal');
        $fileCreated = onystSyncroImportDate($file['created_at'] ?? null, $timezone, true);
        onystSyncroImportDate($file['updated_at'] ?? null, $timezone);
        if (isset($file['url'])) {
            if (!is_string($file['url']) || (isset($file['md5']) && !is_string($file['md5']))) {
                throw new OnystSyncroImportError('original_attachment_metadata_invalid');
            }
            [$staged, $sha] = onystSyncroImportDownload($file['url'], $size, $file['md5'] ?? null);
        } else {
            $bytes = is_string($file['base64'] ?? null) ? base64_decode($file['base64'], true) : false;
            if ($bytes === false || strlen($bytes) !== $size || $size > 25 * 1024 * 1024) {
                throw new OnystSyncroImportError('source_attachment_unverified');
            }
            $staged = new OnystSyncroImportTemporaryFile();
            if (fwrite($staged->stream, $bytes) !== $size) {
                throw new OnystSyncroImportError('attachment_temporary_storage_unavailable');
            }
            rewind($staged->stream);
            $sha = hash('sha256', $bytes);
        }

        $attachmentsById[$fileId] = [$name, $staged, $fileCreated, $replyId, $private, $size, $sha];
        $original['attachments'][] = [
            'id' => $fileId, 'created_at' => $file['created_at'], 'sha256' => $sha,
        ] + (isset($file['url']) ? [
            'updated_at' => $file['updated_at'] ?? null, 'name' => $name, 'comment_id' => $replyId ?: null,
            'private' => $private, 'size' => $size,
        ] : []);
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
            'reply_count' => count($replies), 'attachment_count' => count($attachmentsById),
            'attachment_bytes_verified' => true, 'auto_reply_scheduled' => false,
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
                (ticket_attachment_name,ticket_attachment_reference_name,ticket_attachment_created_at,ticket_attachment_ticket_id,
                 ticket_attachment_reply_id,ticket_attachment_private) VALUES (?,?,?,?,?,?)');
            $expectedFiles = [];
            foreach ($attachmentsById as $file) {
                [$name, , $fileCreated, $replyId, $private] = $file;
                $reference = bin2hex(random_bytes(20)) . '.bin';
                $path = $directory . '/' . $reference;
                $paths[] = $path;
                onystSyncroImportSaveAttachment($path, $file);
                $savedReplyId = $replyIds[$replyId] ?? null;
                $insertFile->execute([$name, $reference, $fileCreated, $ticketId, $savedReplyId, (int) $private]);
                $expectedFiles[] = [$name, $reference, $fileCreated, $savedReplyId, (int) $private];
            }
            $savedFiles = $db->execute_query('SELECT ticket_attachment_name,ticket_attachment_reference_name,
                ticket_attachment_created_at,ticket_attachment_reply_id,ticket_attachment_private FROM ticket_attachments
                WHERE ticket_attachment_ticket_id=? ORDER BY ticket_attachment_id', [$ticketId])->fetch_all(MYSQLI_NUM);
            if ($savedFiles !== $expectedFiles) {
                throw new OnystSyncroImportError('attachment_metadata_readback_mismatch');
            }
        }

        $stored = $db->execute_query('SELECT ticket_prefix,ticket_number,ticket_created_at,ticket_updated_at,ticket_resolved_at,ticket_closed_at,ticket_due_at,
            ticket_auto_reply_due_at,ticket_auto_reply_email,ticket_sla_id FROM tickets WHERE ticket_id=?', [$ticketId])->fetch_assoc();
        if (!$stored || $stored['ticket_created_at'] !== $created || $stored['ticket_updated_at'] !== $updated ||
            $stored['ticket_resolved_at'] !== $resolved || $stored['ticket_closed_at'] !== $closed || $stored['ticket_due_at'] !== $due ||
            $stored['ticket_prefix'] !== ONYST_SYNCRO_IMPORT_PREFIX || (int) $stored['ticket_number'] !== $number ||
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
    } elseif ($action === 'attachment_audit') {
        $response = onystSyncroImportPreflight($mysqli) + onystSyncroImportAttachmentAudit($mysqli);
    } elseif ($action === 'register') {
        onystSyncroImportPreflight($mysqli);
        $response = onystSyncroImportRegister($mysqli, (string) ($_POST['phase'] ?? ''), $_POST['tickets'] ?? []);
    } elseif ($action === 'prefix_preview' || $action === 'prefix_apply') {
        onystSyncroImportPreflight($mysqli);
        $response = onystSyncroImportRelabel($mysqli, onystSyncroImportId($_POST['expected_count'] ?? null), $action === 'prefix_preview');
    } elseif ($action === 'pending') {
        $legacy = $mysqli->execute_query('SELECT 1 FROM onyst_syncro_ticket_import m
            JOIN tickets t ON t.ticket_id=m.ticket_id WHERE t.ticket_prefix<>? LIMIT 1', [ONYST_SYNCRO_IMPORT_PREFIX]);
        if ($legacy->num_rows) {
            throw new OnystSyncroImportError('prefix_correction_required');
        }
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
    $diagnostic = ($_POST['preview'] ?? false) === true ? [
        'exception' => get_class($error),
        'import_line' => $error->getFile() === __FILE__ ? $error->getLine() : null,
    ] : [];
    onystSyncroImportResponse(500, ['success' => false, 'error' => 'ticket_import_failed'] + $diagnostic);
}
