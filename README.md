# Project Tracker source code

Editable Laravel application source, prepared 20 November 2026 from the current
Project Tracker application and the existing Render cloud integrations.

## Start here

- `app/`: PHP controllers, models, commands, and business rules.
- `resources/views/`: Blade page templates.
- `public/js/` and `public/css/`: application JavaScript and styles.
- `routes/`, `database/`, and `tests/`: endpoints, migrations, and regressions.
- `Dockerfile`, `deploy/`, and `render.yaml`: the existing Render deployment.

This folder is a source project. It does not install Windows services or contain
the Windows installer, runtime bundles, private configuration, or office records.
Composer installs PHP dependencies; the checked-in public assets serve the UI.

## Run a separate development copy

Requirements: PHP 8.4.1 or later with pdo_pgsql, mbstring, zip, intl and XML;
Composer 2; and PostgreSQL 18. Node.js 22 or later is used for JavaScript tests
and optional Vite asset work.

1. Create an empty development PostgreSQL database, such as `project_tracker_dev`.
2. Run `composer install` and copy `.env.example` to `.env`.
3. Fill the development database connection in `.env`, then run
   `php scripts/initialize-environment.php` to generate this copy's encryption key.
4. Run `php artisan migrate` and `php artisan tracker:initialize-source-accounts`.
5. Run `php artisan serve` and open the address it prints.

Use a separate development database and media folders for this copy. Do not copy
the installed office host's `.env`, database, accounts, or private files into Git.
For optional Vite work, run `npm ci` and `npm run build`.

## Render deployment

Existing site: https://laoag-city-ceo-project-tracker.onrender.com
Repository: https://github.com/shanpe-10/project-tracker
Branch: `laoag-city-ceo-project-tracker`

The existing service builds `Dockerfile`; `deploy/start.sh` starts the application
and applies pending migrations. Keep the existing Render application key,
PostgreSQL connection, and private S3 storage settings. Values belong in Render's
private environment, using `.env.render.example` as the list of required keys.
Never generate a replacement key for the existing hosted application.

Normal account management uses Admin and Staff roles. The source edition also
has a restricted Superadmin account that opens the read-only Accountability
page. This role is hidden from the normal account directory, cannot be assigned
there, and cannot manage accounts or change project data.

The source setup command initializes the reserved `admin` and `superadmin`
accounts once, using the requested initial credentials. On an existing database,
this changes those two reserved accounts and preserves other accounts and
project records. The initialization marker prevents later deployments from
resetting passwords. Docker startup runs this command after database migrations.

Accountability records include the actor, action, time, affected record,
field changes where available, and request context. Credentials are excluded.
Older entries show only the evidence originally recorded. Clearing the ordinary
Admin log view does not hide preserved entries from Superadmin.

Attachments, profile photos, holiday settings and cloud project backups retain
the existing persistent storage integrations. Download the latest project backup
from Administration. Keep a full database recovery dump before schema upgrades.

## Checks

Use a dedicated PostgreSQL database whose name ends in `_test` and run
`php scripts/run-release-checks.php`. The tests isolate schemas and media.
The GitHub cloud-release workflow also checks the production Docker startup.

The free Render instance can sleep while idle; scheduled jobs run while it is
awake. Existing online records stay in the cloud database and private storage.
