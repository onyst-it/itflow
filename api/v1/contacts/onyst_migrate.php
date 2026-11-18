<?php
declare(strict_types=1);

// Migration-only operation; the stock contacts/create.php remains untouched.
// Lives in api/v1/contacts so ITFlow's existing RBAC selects module_client.
define('ONYST_CONTACT_MIGRATION', true);
require_once __DIR__ . '/onyst-migration-contract.php';

use Onyst\SyncroContactMigration\InputError;
use function Onyst\SyncroContactMigration\{validateRequest, fieldsHash, normalizeDestination, canonicalJson};

function onystMigrationReply(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') onystMigrationReply(405, ['success' => false, 'code' => 'post_required']);
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) onystMigrationReply(415, ['success' => false, 'code' => 'json_required']);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 524288) onystMigrationReply(413, ['success' => false, 'code' => 'request_too_large']);

// Unmodified ITFlow API authentication also loads its user permission context.
require_once __DIR__ . '/../validate_api_key.php';
$configFile = __DIR__ . '/onyst-migration-config.php';
if (!is_file($configFile)) onystMigrationReply(503, ['success'=>false,'code'=>'migration_not_configured']);
$config = require $configFile;
if (!$config['enabled']) onystMigrationReply(503, ['success' => false, 'code' => 'migration_disabled']);
if (!is_array($_POST) || array_is_list($_POST)) onystMigrationReply(400, ['success' => false, 'code' => 'json_object_required']);
if (!function_exists('apiUserCanAccessClient') || !function_exists('lookupUserPermission') ||
    lookupUserPermission('module_client') < 2 || !apiUserCanAccessClient($config['client_id'])) {
    onystMigrationReply(403, ['success' => false, 'code' => 'client_permission_denied']);
}

$inTransaction = false;
try {
    // Pin this temporary migration to the already-authorized integration key.
    $keyStatement = $mysqli->prepare('SELECT api_key_id, api_key_user_id FROM api_keys WHERE api_key_secret = ? LIMIT 1');
    $keyStatement->bind_param('s', $api_key);
    $keyStatement->execute();
    $key = $keyStatement->get_result()->fetch_assoc();
    if (!$key || (int)$key['api_key_id'] !== $config['api_key_id'] || (int)$key['api_key_user_id'] !== $config['api_key_user_id']) {
        throw new InputError('wrong_migration_key', 'This key is not authorized for the Harbor migration.', 403);
    }
    $request = validateRequest($_POST, $config);
    $sourceId = $request['source_id'];
    $customerId = $request['customer_id'];
    $clientId = $config['client_id'];
    $fields = $request['fields'];
    $snapshot = $request['snapshot_json'];
    $sourceHash = $request['source_hash'];
    $fieldsJson = canonicalJson((object)$fields);

    if (!$request['dry_run']) {
        $mysqli->begin_transaction();
        $inTransaction = true;
    }
    $clientSql = 'SELECT client_id, client_archived_at FROM clients WHERE client_id = ?' . ($inTransaction ? ' FOR UPDATE' : '');
    $statement = $mysqli->prepare($clientSql);
    $statement->bind_param('i', $clientId);
    $statement->execute();
    $client = $statement->get_result()->fetch_assoc();
    if (!$client || $client['client_archived_at'] !== null) throw new InputError('client_unavailable', 'Configured client is missing or archived.', 409);

    $newReservation = false;
    if ($inTransaction) {
        // The unique source ID is reserved in the SAME transaction as creation.
        $statement = $mysqli->prepare("INSERT INTO onyst_syncro_contact_map (source_system, source_contact_id, source_customer_id, client_id, source_snapshot_json, source_hash, imported_fields_json) VALUES ('syncro', ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE source_contact_id = VALUES(source_contact_id)");
        $statement->bind_param('ssisss', $sourceId, $customerId, $clientId, $snapshot, $sourceHash, $fieldsJson);
        $statement->execute();
        $newReservation = $statement->affected_rows === 1;
    }
    $statement = $mysqli->prepare("SELECT * FROM onyst_syncro_contact_map WHERE source_system = 'syncro' AND source_contact_id = ?" . ($inTransaction ? ' FOR UPDATE' : ''));
    $statement->bind_param('s', $sourceId);
    $statement->execute();
    $mapping = $statement->get_result()->fetch_assoc();
    if ($mapping && ((int)$mapping['client_id'] !== $clientId || $mapping['source_customer_id'] !== $customerId)) {
        throw new InputError('mapping_conflict', 'Source ID is already bound to another client or source customer.', 409);
    }
    $created = false;
    $contactId = isset($mapping['contact_id']) ? (int)$mapping['contact_id'] : null;
    if ($mapping && $contactId === null && !$newReservation) {
        throw new InputError('unresolved_reservation', 'A committed reservation has no destination ID. Reconcile it before any create retry.', 409);
    }
    if ($contactId === null && $request['dry_run']) {
        onystMigrationReply(200, ['success' => true, 'count' => 1, 'data' => [[
            'source_contact_id' => $sourceId, 'client_id' => $clientId, 'contact_id' => null,
            'dry_run' => true, 'would_create' => true, 'created' => false,
            'normalized_fields' => $fields, 'source_hash' => $sourceHash,
        ]]]);
    }
    if ($contactId === null) {
        if (!$config['allow_create'] || time() > strtotime($config['allow_create_until_utc'])) {
            throw new InputError('creation_closed', 'New migration creates are disabled or their time window has expired.', 403);
        }
        // Do not silently identify existing people by their names or email.
        $statement = $mysqli->prepare("SELECT COUNT(*) AS total FROM contacts c LEFT JOIN onyst_syncro_contact_map m ON m.source_system='syncro' AND m.contact_id=c.contact_id AND m.client_id=c.contact_client_id WHERE c.contact_client_id=? AND m.contact_id IS NULL");
        $statement->bind_param('i', $clientId);
        $statement->execute();
        if ((int)$statement->get_result()->fetch_assoc()['total'] !== 0) throw new InputError('unmapped_destination_contacts', 'Existing unmapped client contacts require identity reconciliation before migration.', 409);
        $columns = array_keys($fields);
        $values = array_values($fields);
        $statement = $mysqli->prepare('INSERT INTO contacts (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
        $types = implode('', array_map(static fn($value) => is_int($value) ? 'i' : 's', $values));
        $statement->bind_param($types, ...$values);
        $statement->execute();
        $contactId = (int)$mysqli->insert_id;
        if ($contactId <= 0) throw new \RuntimeException('Missing inserted contact ID.');
        $created = true;
    }
    $statement = $mysqli->prepare('SELECT * FROM contacts WHERE contact_id = ? AND contact_client_id = ?');
    $statement->bind_param('ii', $contactId, $clientId);
    $statement->execute();
    $contact = $statement->get_result()->fetch_assoc();
    if (!$contact || $contact['contact_archived_at'] !== null) throw new InputError('mapped_contact_unavailable', 'Mapped contact is missing, archived, or belongs to another client; it will not be recreated.', 409);
    $actualFields = normalizeDestination($contact);
    $destinationHash = fieldsHash($actualFields);
    if ($created && !hash_equals(fieldsHash($fields), $destinationHash)) throw new \RuntimeException('Created fields did not match read-back.');

    if ($created) {
        $statement = $mysqli->prepare("UPDATE onyst_syncro_contact_map SET contact_id=?, destination_hash=?, last_verified_at=UTC_TIMESTAMP() WHERE source_system='syncro' AND source_contact_id=? AND contact_id IS NULL");
        $statement->bind_param('iss', $contactId, $destinationHash, $sourceId);
        $statement->execute();
        if ($statement->affected_rows !== 1) throw new \RuntimeException('Could not persist migration ID.');
        logAudit('Contact', 'Create', "Syncro contact $sourceId via migration API ($api_key_name)", $clientId, $contactId);
        logAudit('API', 'Success', "Migrated Syncro contact $sourceId ($api_key_name)", $clientId, $contactId);
    }
    $sourceChanged = !$created && !hash_equals((string)$mapping['source_hash'], $sourceHash);
    $destinationChanged = !$created && !hash_equals((string)$mapping['destination_hash'], $destinationHash);
    if ($inTransaction) {
        $mysqli->commit();
        $inTransaction = false;
    }
    onystMigrationReply(200, ['success' => true, 'count' => 1, 'data' => [[
        'source_contact_id' => $sourceId, 'client_id' => $clientId, 'contact_id' => $contactId,
        'dry_run' => $request['dry_run'], 'created' => $created, 'would_create' => false,
        'source_changed' => $sourceChanged, 'destination_changed' => $destinationChanged,
        'normalized_fields' => $fields, 'current_fields' => $actualFields,
        'source_hash' => $sourceHash, 'destination_hash' => $destinationHash,
    ]]]);
} catch (InputError $error) {
    if ($inTransaction) $mysqli->rollback();
    onystMigrationReply($error->status, ['success' => false, 'code' => $error->reason, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    if ($inTransaction) $mysqli->rollback();
    onystMigrationReply(500, ['success' => false, 'code' => 'migration_transaction_failed', 'message' => 'The migration did not commit. Inspect the app before retrying.', 'error_class' => get_class($error)]);
}
