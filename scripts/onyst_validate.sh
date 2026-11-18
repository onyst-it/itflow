#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
for file in functions/app.php cron/ticket_email_parser.php functions/onyst_ticket_email.php api/v1/contacts/onyst-migration-contract.php api/v1/contacts/onyst-migration-contract-test.php api/v1/contacts/onyst_migrate.php api/v1/contacts/onyst-migration-config.example.php scripts/onyst_seed_canned_responses.php tests/onyst_ticket_email_test.php; do
    php -l "$file"
done
php tests/onyst_ticket_email_test.php
php api/v1/contacts/onyst-migration-contract-test.php
php scripts/onyst_seed_canned_responses.php
for private_file in config.php api/v1/contacts/onyst-migration-config.php; do
    if git ls-files --error-unmatch -- "$private_file" >/dev/null 2>&1; then
        echo "Refusing deployment: a server-only configuration file is tracked: $private_file" >&2
        exit 1
    fi
done
echo 'ONYST validation passed. No email, migration, or database writes were performed.'
