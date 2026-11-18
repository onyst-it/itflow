<?php
// Copy to onyst-migration-config.php on the server and configure the approved pilot scope.
// The live file is deliberately ignored by Git. Never put API secrets in this file.
return [
    'enabled' => false,
    'allow_create' => false,
    'allow_create_until_utc' => '1970-01-01T00:00:00Z',
    'api_key_id' => 0,
    'api_key_user_id' => 0,
    'client_id' => 0,
    'source_customer_id' => '',
    'source_contact_ids' => [],
];
