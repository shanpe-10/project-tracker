# Laoag City CEO Project Tracker

Render service: laoag-city-ceo-project-tracker, My Workspace, Singapore, free Docker instance.
Neon PostgreSQL 18: separate empty production database. No local accounts, records or attachments are copied.
Private Neon S3-compatible storage holds attachments, profile photos and project recovery archives. Files are served through authenticated application endpoints.

Set the private values listed in .env.render.example in Render settings. Generate APP_KEY once as base64: followed by 32 cryptographically random bytes encoded as base64. Retain that same key across all deployments: it protects encrypted sessions and password viewing. Never commit real keys or database/storage credentials.

Startup runs migrations, creates the first Superadmin only if the users table is empty, compiles views, and refreshes deadline statuses before serving. It never overwrites an existing account. Remove TRACKER_BOOTSTRAP_PASSWORD from the service environment after first startup. Keep the initial credentials in protected local storage and change the password through Accounts after signing in.

Backups contain projects, Logs, attachments, numbering, deletion requests/decisions and import workflow/audit records. Account credentials, sessions and profile photos remain outside the project-only recovery package. Recovery must be tested in an isolated database. Keep independent secure copies of application secrets for disaster recovery.

The free Render instance sleeps after inactivity; its first response can be slow. Its in-process scheduler runs while the instance is awake. Status reads and startup refresh stale statuses, but an unattended daily backup is not guaranteed while the service sleeps. Use a continuously running paid instance or an explicitly configured external schedule if that guarantee is required. Do not use the container filesystem for permanent uploads.

Release checks use disposable databases ending in _test and isolated schemas. The workflow runs PostgreSQL and JavaScript regressions, audits locked dependencies, then builds and starts the production Docker image on a second empty test database. No production data is used for destructive verification.

Rollback: retain the prior commit and all Neon database/storage contents. Redeploy the last verified commit; do not drop or recreate the database. Snapshot the production database before future schema changes. The initial deployment has no previous online records to preserve.
