# IPMdb Plesk deployment standard

The canonical source is the GitHub `build-week-2026` branch. Production changes must be built, reviewed, and validated in Git before deployment. Do not edit production files in the Plesk file manager.

## Target

- Web root: `/httpdocs/ipmdb/`
- Runtime: PHP 8.3+
- Required extensions: `curl`, `mbstring`, `pdo_mysql`
- Database: MariaDB 10.6+ or MySQL 8+
- Apache modules: headers; `.htaccess` overrides enabled

## Configuration

Create `/httpdocs/ipmdb/config.local.php` from `config.local.php.example`. It must never be committed or included in a public archive.

Configure a production database account with access only to the IPMdb database, an administrator password hash generated with `password_hash`, and an OpenAI API key if AI Map is enabled.

Before deploying, rotate any credential that has appeared in a prior export, message, screenshot, or package.

## Database

1. Back up the current production database.
2. Review `database/schema.sql` before applying it to an existing database.
3. Use `database/seed.sql` only in an empty judge or development environment—never in production.
4. Test asset creation, version archival, relationship writes, and provenance receipts against a staging copy first.

## Release procedure

1. Confirm `npm ci && npm test` passes on the exact commit.
2. Confirm GitHub Actions passes.
3. Export the current `/httpdocs/ipmdb/` directory and database as a rollback snapshot.
4. Upload the contents of this repository's `ipmdb/` directory to a new versioned staging directory.
5. Add the production-only `config.local.php` on the server.
6. Smoke-test the staging directory over HTTPS.
7. Switch the production directory atomically or during a short maintenance window.
8. Re-run the smoke checks and retain the rollback snapshot until sign-off.

## Smoke checks

- `/ipmdb/` loads and locks a test idea.
- `/ipmdb/ledger.php` and search return records without submitter email.
- `/ipmdb/viewer.php?asset_id=...` loads versions and relationships.
- `/ipmdb/provenance.php?asset_id=...` returns HTML and JSON receipts.
- `/ipmdb/relationship_explorer.php` renders nodes and edges.
- Login succeeds; logout is POST-only.
- Every admin mutation rejects a missing or invalid CSRF token.
- AI Map returns structured GPT-5.6 suggestions and does not write before approval.
- Server logs contain no credential or raw response-body leakage.

## Rollback

Restore the prior directory snapshot and database backup together. Application code and schema must remain from the same release.
