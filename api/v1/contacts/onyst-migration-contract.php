<?php
declare(strict_types=1);

namespace Onyst\SyncroContactMigration;

if (!defined('ONYST_CONTACT_MIGRATION') && PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

final class InputError extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}

function scalarField(object $source, string $field, int $limit = 200): string
{
    $value = $source->{$field} ?? '';
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InputError('invalid_field_type', "Invalid scalar field: $field");
    }
    $text = trim((string)$value);
    if (preg_match('//u', $text) !== 1 || str_contains($text, "\0")) {
        throw new InputError('invalid_text', "Invalid text in field: $field");
    }
    if ($limit > 0 && preg_match_all('/./us', $text) > $limit) {
        throw new InputError('field_too_long', "Field exceeds destination length: $field");
    }
    return $text;
}

function sourceName(object $source, string $field): string
{
    $name = scalarField($source, $field);
    return $name === '(empty)' ? '' : $name;
}

function canonicalValue(mixed $value): mixed
{
    if (is_object($value)) {
        $values = get_object_vars($value);
        ksort($values, SORT_STRING);
        $result = new \stdClass();
        foreach ($values as $key => $item) $result->{$key} = canonicalValue($item);
        return $result;
    }
    if (is_array($value)) return array_map(__NAMESPACE__ . '\\canonicalValue', $value);
    return $value;
}

function canonicalJson(mixed $value): string
{
    return json_encode(canonicalValue($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
}

function fieldsHash(array $fields): string
{
    return hash('sha256', canonicalJson((object)$fields));
}

/** Missing/null personal details become genuine blanks; no placeholder is stored. */
function normalizeContact(object $source, int $clientId): array
{
    $name = sourceName($source, 'name') ?: sourceName($source, 'fullname');
    if ($name === '') $name = trim(sourceName($source, 'firstname') . ' ' . sourceName($source, 'lastname'));
    if (preg_match_all('/./us', $name) > 200) throw new InputError('field_too_long', 'Combined name exceeds destination length.');
    $properties = $source->properties ?? new \stdClass();
    if (!is_object($properties) && $properties !== []) throw new InputError('invalid_properties', 'Source properties must be an object.');
    if ($properties === []) $properties = new \stdClass();
    $notes = scalarField($source, 'notes', 0);
    if (strlen($notes) > 65535) throw new InputError('field_too_long', 'Notes exceed the destination TEXT limit; original source must be retained.');
    return [
        'contact_name' => $name,
        'contact_title' => scalarField($properties, 'title'),
        'contact_department' => scalarField($source, 'department'),
        'contact_email' => scalarField($source, 'email'),
        'contact_phone_country_code' => scalarField($source, 'phone_country_code', 10),
        'contact_phone' => scalarField($source, 'phone'),
        'contact_extension' => scalarField($source, 'extension'),
        'contact_mobile_country_code' => scalarField($source, 'mobile_country_code', 10),
        'contact_mobile' => scalarField($source, 'mobile'),
        'contact_notes' => $notes,
        'contact_primary' => 0,
        'contact_important' => 0,
        'contact_billing' => 0,
        'contact_technical' => 0,
        'contact_location_id' => 0,
        'contact_vendor_id' => 0,
        'contact_user_id' => 0,
        'contact_client_id' => $clientId,
    ];
}

function normalizeDestination(array $row): array
{
    $fields = normalizeContact(new \stdClass(), (int)$row['contact_client_id']);
    foreach ($fields as $key => $default) {
        $fields[$key] = is_int($default) ? (int)($row[$key] ?? 0) : (string)($row[$key] ?? '');
    }
    return $fields;
}

function validateRequest(array $payload, array $config): array
{
    foreach (['client_id', 'source_customer_id', 'source_contact_id'] as $field) {
        $value = $payload[$field] ?? null;
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,31}$/D', (string)$value)) {
            throw new InputError('invalid_identity', "A positive source/client ID is required: $field");
        }
    }
    if (($payload['client_id'] ?? null) !== $config['client_id'] &&
        (string)($payload['client_id'] ?? '') !== (string)$config['client_id']) {
        throw new InputError('wrong_client', 'This pilot endpoint accepts only its configured ITFlow client.', 403);
    }
    $sourceId = (string)($payload['source_contact_id'] ?? '');
    $customerId = (string)($payload['source_customer_id'] ?? '');
    if ($customerId !== $config['source_customer_id'] || !in_array($sourceId, $config['source_contact_ids'], true)) {
        throw new InputError('source_out_of_scope', 'Source contact is outside the approved Harbor pilot.', 403);
    }
    if (isset($payload['dry_run']) && !is_bool($payload['dry_run'])) throw new InputError('invalid_dry_run', 'dry_run must be a JSON boolean.');
    $snapshot = $payload['source_snapshot_json'] ?? null;
    if (!is_string($snapshot) || strlen($snapshot) > 262144) throw new InputError('invalid_snapshot', 'A bounded original source snapshot is required.');
    try { $source = json_decode($snapshot, false, 64, JSON_THROW_ON_ERROR); }
    catch (\JsonException) { throw new InputError('invalid_snapshot', 'Source snapshot is invalid JSON.'); }
    if (!is_object($source) || (string)($source->id ?? '') !== $sourceId || (string)($source->customer_id ?? '') !== $customerId) {
        throw new InputError('snapshot_identity_mismatch', 'Source snapshot identity does not match its migration key.');
    }
    return [
        'source_id' => $sourceId, 'customer_id' => $customerId,
        'source' => $source, 'snapshot_json' => $snapshot,
        'source_hash' => hash('sha256', canonicalJson($source)),
        'fields' => normalizeContact($source, $config['client_id']),
        'dry_run' => $payload['dry_run'] ?? true,
    ];
}
