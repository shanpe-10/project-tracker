# PostgreSQL migration and cutover

Status: PostgreSQL connection, schema installation, seed and live data transfer completed on 2026-10-03. Production CRUD and Apache authentication endpoint verified. UI and routes are unchanged.

## Requirements and credentials

Use PostgreSQL 16+ with ICU support, UTF8 encoding, PHP 8.2+ with `pdo_pgsql`, and `pg_dump` from the same PostgreSQL major version as the server. Installation found here: `D:\Programmer\PostgreSQL\18\bin`. Enable `extension=php_pdo_pgsql.dll` in both CLI/Apache php.ini, or use `php -d extension=php_pdo_pgsql.dll` for CLI commands. Restart Apache after editing its php.ini.

In pgAdmin, Server > Properties > Connection shows host, port, and username. The install password cannot be read from pgAdmin. Create a dedicated app role; keep passwords in the local `.env` or a PHP secrets file returning `['postgresql_password' => '...']`. Never commit credentials. `TRACKER_SECRETS_FILE` now reads `postgresql_password`, not the old MariaDB password key. `.env.example` contains no credentials.

Set `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT=5432`, `DB_DATABASE=lgu_project_tracker`, `DB_USERNAME=lgu_tracker_app`, `DB_PASSWORD`, `DB_SSLMODE`, `DB_SCHEMA=public`, and `PG_DUMP_BINARY`. If using an external secrets file, omit DB_PASSWORD entirely so its fallback is used. A blank DB_PASSWORD explicitly selects an empty password. Clear any stale DB_URL because it overrides individual fields. The existing production `.env` has intentionally not been overwritten: configure it during cutover. Migrated code requires PostgreSQL.

## Create and migrate

From pgAdmin Query Tool connected to the postgres maintenance database, as an administrator:

```sql
CREATE ROLE lgu_tracker_app LOGIN; -- set its password through pgAdmin's role dialog
CREATE DATABASE lgu_project_tracker OWNER lgu_tracker_app ENCODING 'UTF8' TEMPLATE template0;
```

From the complete app directory `D:\LGU LAOAG CITY\project-tracker`, with destination variables configured:

```powershell
php -d extension=php_pdo_pgsql.dll artisan config:clear
php -d extension=php_pdo_pgsql.dll artisan migrate --force
php -d extension=php_pdo_pgsql.dll artisan db:seed --force
```

The migration loads `database/lgu_project_tracker_schema.sql`; it replaces the unused Laravel starter users schema with the actual tracker schema. Seed is idempotent and creates only the project-number sequence. No demo login is installed. For a fresh installation, run `php artisan tracker:create-admin` interactively after migration. Do not create an administrator before transferring existing users.

Alternative schema-only setup: `psql -h HOST -U USER -d lgu_project_tracker -v ON_ERROR_STOP=1 --single-transaction -f database/lgu_project_tracker_schema.sql`. Use this for external management rather than subsequently mixing it with Laravel migration rollback tracking. Normal installations should use artisan migrate. Existing cache and job migrations use Laravel's PostgreSQL grammar.

## Transfer existing data

Back up MariaDB first and put the application into maintenance mode. Start the existing source MariaDB service. Configure PostgreSQL as the target in `.env`; the one-time transfer script accesses MySQL separately:

```powershell
$env:SOURCE_DB_HOST = '127.0.0.1'
$env:SOURCE_DB_PORT = '3306'
$env:SOURCE_DB_DATABASE = 'lgu_project_tracker'
$env:SOURCE_DB_USERNAME = 'lgu_tracker_app'
$env:SOURCE_SECRETS_FILE = 'D:/LGU LAOAG CITY/project-data/project-tracker-secrets.php'
php -d extension=php_pdo_pgsql.dll scripts/migrate-mysql-to-postgresql.php
```

Alternatively set SOURCE_DB_PASSWORD locally. The old secrets file's `database_password` is used only by this transfer script. Both pdo_mysql (transfer only) and pdo_pgsql must be enabled. Source is read under a consistent UTC snapshot; destination writes are atomic and require empty tracker tables. The script copies every column, including users/password hashes, histories, archives, audit entries and import rollback records; validates row counts; converts 0/1 to booleans; preserves IDs; resets identity sequences; preserves the next project number. Malformed JSON/dates, invalid foreign keys, out-of-range integers, or duplicate collation keys abort rather than silently discard records. Fix source records and retry into an empty destination. PostgreSQL sequence state is nontransactional; on a failed run rerunning the transfer resets identities at completion.

Historical `.sql`, `.frm`, `.MAD`, `.MAI`, zip backups and `originals/` files in the workspace are preserved as obsolete recovery sources. They are not PostgreSQL seeds. Restore a historical dump into a separate MariaDB database if the live source is unavailable, then point SOURCE_DB_DATABASE there. Never load a MySQL dump directly into PostgreSQL.

## Schema and behavior

All nine tracker tables, primary keys, existing foreign keys (`ON DELETE SET NULL`), indexes, nullable fields, and defaults are preserved. Users and related references use bigint to cover the original unsigned-int range; IDs use GENERATED BY DEFAULT AS IDENTITY. Unsigned bigint values above PostgreSQL's signed-bigint maximum require manual remediation. Counters have nonnegative checks. Money uses numeric(15,2); dates use date; flags use boolean; JSON documents use jsonb; enums use varchar with CHECK constraints. Timestamp columns remain without time zone to preserve original wall-clock data, and connections use UTC. Update triggers replace ON UPDATE CURRENT_TIMESTAMP.

Username and project-number unique keys use an ICU case/accent-insensitive collation. JSONB preserves document meaning but may reorder object keys/remove duplicate keys. History.project_id and import rollback project references deliberately retain the original absence of a foreign key: adding cascades would change rollback/history behavior. Existing unsigned IDs become signed bigint; positive identities are generated by PostgreSQL.

Queries use bound values and the existing Laravel builder. Numeric project sorting safely handles alphanumeric values; JSON filters use ->>; date extraction returns null for malformed values; search remains case insensitive. SQLite-specific helper branches exist only to keep the current fast test suite usable. PostgreSQL backups use pg_dump, a temporary escaped PGPASSFILE, configured SSL mode, and the existing zip/checksum/rotation process. Restore extracted database.sql using `psql -v ON_ERROR_STOP=1 --single-transaction -d EMPTY_DATABASE -f database.sql`; restore application attachments separately from the zip.

## Verification

Create a separate database ending in `_test`, owned by the test role. Configure test variables in the shell (DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD, DB_DATABASE, PG_DUMP_BINARY) and run:

```powershell
php -d extension=php_pdo_pgsql.dll vendor/phpunit/phpunit/phpunit -c phpunit.pgsql.xml
php vendor/phpunit/phpunit/phpunit
```

The PostgreSQL suite creates and drops randomly named schemas only inside the dedicated test database. It exercises real schema constraints, CRUD, JSON/date filters, imports/rollback, login, permissions, search, and backups. Do not run older TrackerPreviewTest integration tests on production: they assume a preexisting admin ID.

After transfer, compare counts shown by the script with the source, check login/project edits/import rollback/attachments/backups, and clear configuration cache. Then bring the application out of maintenance mode. Keep MariaDB backups until acceptance. Production destination credentials and the stopped source service are the remaining cutover prerequisites.

## Changed files

Applied to the complete app and corresponding workspace copies:

- `.env.example`, `config/database.php`, `composer.json`: PostgreSQL defaults, secrets/SSL/schema/dump settings, remove SQLite creation from installation scripts.
- `app/Http/Controllers/TrackerDataController.php`, `TrackerAuthController.php`, `TrackerBackupController.php`, `app/Support/TrackerSql.php`: PostgreSQL queries, filters, sorting, backups; query-builder values remain bound.
- `database/lgu_project_tracker_schema.sql`, `database/migrations/0001_01_01_000000_create_users_table.php`: actual tracker schema and Laravel installer.
- `database/seeders/DatabaseSeeder.php`, `database/factories/UserFactory.php`: tracker-compatible seed/factory.
- `database/legacy/lgu_project_tracker_mysql_schema.sql`: clearly marked historical schema.
- `scripts/migrate-mysql-to-postgresql.php`: consistent snapshot transfer with validation/identity reset.
- `tests/Feature/ComprehensiveUatTest.php`, `PostgreSqlMigrationTest.php`, `TrackerPreviewTest.php`, `phpunit.pgsql.xml`, `.github/workflows/tests.yml`: real PostgreSQL suite and CI service job.
- `README.md`, `SETUP-AND-USER-GUIDE.md`, `MIGRATION-STATUS.md`, `POSTGRESQL-MIGRATION.md`: setup and cutover documentation.
- Workspace-only: `import_work/inspect-schema.php`, `import_work/generate-schema.php`, `project-tracker.env.staging`, `project-tracker-status.staging.md`.

Verified locally with PostgreSQL 18: SQL schema load; Laravel migrate, seed, rollback, re-migrate; PostgreSQL suite (21 tests / 180 assertions, including backup archive/checksum); existing suite (35 tests / 134 assertions / 15 PostgreSQL-only or legacy integration skips); PHP syntax checks. CI job is supplied but has not been run on GitHub. Source transfer attempt failed before reading data because MariaDB at 127.0.0.1:3306 refused connections. Production `.env`, database contents, PHP global configuration and existing PostgreSQL service were not changed.

PostgreSQL reference for the backup implementation: [pg_dump](https://www.postgresql.org/docs/18/app-pgdump.html) and [password-file format](https://www.postgresql.org/docs/18/libpq-pgpass.html).

## Completed live transfer — 2026-10-03

Transferred 3 users, 50 projects, 36 history records, 2 settings, 524 audit entries, 8 import jobs, 302 import changes, and the project-number sequence. Transfer row counts matched, identities were reset, and CRUD passed inside a rolled-back transaction on lgu_project_tracker. Apache's authentication session endpoint returned ok=true. The user configured the local production .env; PHP pdo_pgsql was enabled with an ini backup. Previous pending-transfer notes above describe the initial verification stage and are now superseded. Existing accounts/password hashes are preserved. Browser login and normal work should now be checked by the user. Keep the MariaDB source and backups until acceptance.

