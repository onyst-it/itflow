#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
for file in functions/app.php cron/ticket_email_parser.php functions/onyst_ticket_email.php api/v1/contacts/onyst-migration-contract.php api/v1/contacts/onyst-migration-contract-test.php api/v1/contacts/onyst_migrate.php api/v1/contacts/onyst-migration-config.example.php scripts/onyst_seed_canned_responses.php tests/onyst_ticket_email_test.php functions/onyst_ticket_resolution.php agent/post/onyst_resolution.php agent/includes/onyst_resolve_button.php agent/includes/onyst_bulk_resolution_menu.php agent/modals/ticket/ticket_bulk_resolve.php agent/ticket.php agent/ticket_list.php agent/project.php agent/ajax.php agent/post/ticket.php cron/mail_queue.php tests/onyst_ticket_resolution_test.php; do
    php -l "$file"
done
for file in functions/onyst_vendor_contacts.php functions/ticket_email_ingest.php admin/database_updates/2.7.9.php agent/vendor_contacts.php agent/post/onyst_vendor_contact.php agent/post/contact.php agent/contact.php agent/contacts.php agent/ajax.php agent/modals/contact/contact.php agent/modals/contact/contact_add.php agent/modals/vendor/vendor_contact_edit.php agent/includes/onyst_contact_owner_picker.php agent/includes/onyst_ticket_party_picker.php agent/modals/ticket/ticket_add.php agent/modals/ticket/ticket_contact.php agent/modals/ticket/ticket_edit.php agent/modals/ticket/ticket_edit_vendor.php tests/onyst_vendor_contacts_test.php; do
    php -l "$file"
done
php tests/onyst_vendor_contacts_test.php
for file in admin/database_updates/2.8.0.php agent/modals/client/client_edit.php agent/modals/vendor/vendor_edit.php agent/post/client.php agent/post/vendor.php client/ticket.php cron/ticket_auto_reply.php functions/onyst_ticket_auto_reply.php guest/guest_view_ticket.php includes/cron_jobs.php tests/onyst_ticket_auto_reply_test.php; do
    php -l "$file"
done
php tests/onyst_ticket_auto_reply_test.php
php tests/onyst_ticket_email_test.php
php tests/onyst_ticket_resolution_test.php
php api/v1/contacts/onyst-migration-contract-test.php
php scripts/onyst_seed_canned_responses.php
for private_file in config.php api/v1/contacts/onyst-migration-config.php; do
    if git ls-files --error-unmatch -- "$private_file" >/dev/null 2>&1; then
        echo "Refusing deployment: a server-only configuration file is tracked: $private_file" >&2
        exit 1
    fi
done
echo 'ONYST validation passed. No email, migration, or database writes were performed.'
