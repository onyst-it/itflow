# ONYST ITFlow customizations

The maintained application is https://github.com/onyst-it/itflow, production branch `master`. RunCloud's `app-itflow` deployment and ITFlow's built-in updater must both follow this fork's `master` branch.

## Changes captured on November 18, 2026

- ONYST ticket email HTML wrapper, including the 620px boundary, measured Syncro spacing, logo, urgent-support notice, postal/confidentiality footer, and booking button.
- Consistent **Ticket Updated** / **Ticket Resolved** subjects and headings, including standalone Resolve.
- Reply quote trimming for both the ONYST/Syncro separator and older ITFlow separators.
- Three selectable saved responses: standard resolution, no response, and completed job notes. Definitions are in `resources/onyst/canned-responses.json`.
- The migration-only Syncro contact endpoint, its validation contract and tests, and the schema for the source-to-destination mapping table. Existing ITFlow authentication and RBAC remain in use.

The silent Resolve split-button and bulk menu changes were stopped before implementation. They are not part of this release. The current standalone Resolve action still uses ITFlow's resolution notification flow.

## Deployment

RunCloud is connected to `onyst-it/itflow` on `master`; GitHub's active push webhook triggers deployment. The deployment script fast-forwards to `origin/master` and runs:

```sh
bash scripts/onyst_validate.sh
```

The validation script does not send mail, run migrations, change ticket state, or seed database records. Production secrets, uploaded files, database data, and live migration scope remain server-managed.

For a fresh installation only, review the mapping-table DDL in `contact-migration-schema.sql` and apply it explicitly to the intended database. Copy `api/v1/contacts/onyst-migration-config.example.php` to the ignored `onyst-migration-config.php` and configure only the approved API-key IDs, client, source IDs, and create window. The example is disabled and cannot create contacts.

To create missing saved responses on a new installation:

```sh
php scripts/onyst_seed_canned_responses.php --apply
```

Existing responses are preserved, including edits. Do not seed them automatically on every deployment.

## Upstream updates

Keep `origin` pointed at the ONYST fork. Fetch upstream ITFlow changes into a separate branch, merge them with the ONYST changes, run validation and review the result, then merge into this fork's `master`. A push to `master` is a production deployment. Do not reset the ONYST branch to upstream or use a discard-changes fork sync.

Database-backed saved responses survive application updates independently. Their definitions are also stored here for reproduction. Backups on the live server remain recovery material; committed source in the fork is the authoritative application code.
