# LGU Laoag Project Tracker

The Project Tracker is a shared system for project records, dates, reminders, attachments, exports, and backups. Employees use it in a browser; the database and application run on the LGU server.

**Start here:** [Setup and user guide](SETUP-AND-USER-GUIDE.md). It includes the step-by-step server and database setup, first administrator account, staff onboarding, backups, and daily use. The guide was revised on 30 October 2026 for the current password-viewing, deletion/history and calendar features.

For a new installation, the schema-only database file is [database/lgu_project_tracker_schema.sql](database/lgu_project_tracker_schema.sql). It contains no accounts or project data. Create the first IT Superadmin interactively with `php artisan tracker:create-superadmin` after configuring the database. Superadmin then creates Admin and Staff accounts in Accounts; its workspace also manages working days and holidays.

The tracker is intended to be opened through Apache at `/project-tracker/public/` or an IT-configured address. Keep `.env`, database credentials, attachments, profile photos, and backup files outside the public website folder.

Developers can check the application with `php artisan test`, `node --test tests/*.test.cjs`, and `npm run build`. SQLite remains available only for fast regression tests. PostgreSQL integration tests use `phpunit.pgsql.xml` and the actual production schema.

See [PostgreSQL migration and cutover](POSTGRESQL-MIGRATION.md) for configuration, data transfer, testing, and backups. Historical MySQL schemas and backup archives are obsolete migration sources only.
