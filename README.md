# LGU Laoag Project Tracker

The Project Tracker is a shared system for project records, dates, reminders, attachments, exports, and backups. Employees use it in a browser; the database and application run on the LGU server.

**Free online pilot:** follow [Render + Neon deployment](DEPLOYMENT.md). The repository includes a Dockerfile and a free Render Blueprint with private cloud uploads and PostgreSQL sessions.

**Start here:** [Setup and user guide](SETUP-AND-USER-GUIDE.md). It includes the step-by-step server and database setup, first administrator account, staff onboarding, backups, and daily use.

For a new installation, the schema-only database file is [database/lgu_project_tracker_schema.sql](database/lgu_project_tracker_schema.sql). It contains no accounts or project data. The first administrator is created interactively with `php artisan tracker:create-admin` after the database connection is configured.

The tracker is intended to be opened through Apache at `/project-tracker/public/` or an IT-configured address. Keep `.env`, database credentials, attachments, profile photos, and backup files outside the public website folder.

Deployment checks use `php vendor/bin/phpunit -c phpunit.cloud.xml` against a dedicated PostgreSQL database whose name ends in `_test`. GitHub Actions checks PostgreSQL behavior and builds the production Docker image. Frontend regression tests use `node --test tests/*.test.cjs`.

See [PostgreSQL migration and cutover](POSTGRESQL-MIGRATION.md) for configuration, data transfer, testing, and backups. Historical MySQL schemas and backup archives are obsolete migration sources only.
