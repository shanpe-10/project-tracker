# Standalone server: LGU Project Tracker

The tracker runs independently of XAMPP. Its application remains Laravel 12 with Bootstrap and PostgreSQL; Node.js/npm remain frontend build tools.

## Open the application

Open http://localhost/project-tracker/public/ or http://127.0.0.1/project-tracker/public/.
You can also double-click `D:\LGU LAOAG CITY\Open Project Tracker.cmd`.
The launcher opens the app and requests Windows administrator permission only when stopped services need starting.

## Installed components

| Component | Location / service |
|---|---|
| Apache 2.4.69 x64 | `D:\LGU LAOAG CITY\runtime\apache`; service `LGUProjectTrackerApache` |
| PHP 8.4.26 x64 thread-safe | `D:\LGU LAOAG CITY\runtime\php` |
| Laravel application | `D:\LGU LAOAG CITY\project-tracker` |
| PostgreSQL 18.6 | `D:\Programmer\PostgreSQL\18`; service `postgresql-x64-18` |
| Private attachments / photos / secrets | `D:\LGU LAOAG CITY\project-data` |

Apache runs as LocalService with a dedicated service SID, not an administrator. It serves only Laravel's public directory. The original URL prefix is mapped to that directory; `.env`, application sources, private media and database recovery files have no web mapping.
Apache is configured for delayed automatic startup, depends on PostgreSQL, and has automatic crash restart delays. PostgreSQL remains an automatic Windows service with crash restart delays.
The existing Windows scheduler task uses `scripts/run-scheduler-hidden.vbs`, which locates the independent PHP installation. Existing task timing, account and backup enabled/disabled preference are preserved.

## Service controls

In PowerShell:

```powershell
& "D:\LGU LAOAG CITY\project-tracker\scripts\manage-server.ps1" -Action Status
& "D:\LGU LAOAG CITY\project-tracker\scripts\manage-server.ps1" -Action Start
& "D:\LGU LAOAG CITY\project-tracker\scripts\manage-server.ps1" -Action Restart
& "D:\LGU LAOAG CITY\project-tracker\scripts\manage-server.ps1" -Action Stop
```

Start/Restart/Stop request administrator access when needed. Stop stops only the tracker web server; PostgreSQL may serve other databases and remains running.
Do not also start Apache from XAMPP: both would try to use port 80. XAMPP remains installed for rollback and unrelated applications. MySQL was not stopped or uninstalled by this change, and the tracker does not connect to it.

## Configuration and logs

- Apache configuration: `runtime\apache\conf\httpd-tracker.conf`.
- PHP configuration: `runtime\php\php.ini` (PostgreSQL, curl, mbstring, fileinfo, ZIP and OPcache enabled).
- Apache error log: `runtime\apache\logs\error.log`.
- Apache access logs: `runtime\apache\logs\access.log*` (seven rotating copies).
- PHP errors: `runtime\logs\php-error.log`.
- Laravel errors: `project-tracker\storage\logs\laravel.log`.
- PostgreSQL errors: `D:\Programmer\PostgreSQL\18\data\log`.

Restart Apache after changing php.ini or its server configuration. Keep the existing APP_KEY and database credentials. No Laravel key regeneration or schema migration is necessary for this runtime change.
Apache retains the existing LAN listening behavior, but the current Windows firewall rules reference the old XAMPP executable. Office-network access therefore requires an administrator to add a rule for `D:\LGU LAOAG CITY\runtime\apache\bin\httpd.exe` on TCP port 80, scoped to the trusted office network and appropriate profiles, followed by testing from an employee computer. Firewall rules were not changed during cutover. HTTPS configuration is a separate deployment task.

## Project backups

Manual and automatic backups remain project-data-only. In Admin, Browse lists folders on the host computer inside the page; a Windows service cannot show the old native desktop dialog. You may also type a full destination path.
The server service must have Modify access to any newly selected destination. For a different protected destination, ask the administrator to grant access to `NT SERVICE\LGUProjectTrackerApache` specifically. Do not grant broad public access to backups or credentials.
Keep the existing backup toggle preference. With automatic backups enabled, the daily check remains at 02:00 Asia/Taipei. Project ZIPs exclude accounts, application files, profile photos and system settings.

## PostgreSQL recovery performed

The stopped cluster was copied offline, including WAL and configuration; all 3,169 file SHA-256 hashes matched. It had no external tablespaces or linked directories. Windows logs recorded file/volume-access failures during sleep/resume. Normal service startup completed crash recovery without resetting or recreating databases.
After recovery, a private logical database safety dump was also created. This one-time migration safeguard is separate from the application's project-only backup feature; no automatic full-system backups were added.
The verified copy is identified by `D:\LGU LAOAG CITY\server-migration\verified-recovery-copy.txt`. Do not restore an offline cluster copy over a running server. Retain it until the new setup has been accepted; then discuss intentional cleanup with the administrator.
If volume-access crashes recur, inspect D: storage connectivity/drivers and Windows sleep/resume events. Service restart settings cannot repair a recurring storage fault. No disk repair, power-policy change or database reinstall was performed.

## Verification

Standalone PHP passed Composer platform checks and 40 PostgreSQL regression tests (348 assertions). All six JavaScript regression files passed. Eleven browser walkthrough flows passed through the Apache Windows service using `tracker_runtime_test`, isolated storage, sessions, media and backup destinations.
The walkthrough covered login validation, empty results, project creation/read/edit/delete, attachment upload/preview/download, server-folder navigation/validation/selection/cancel, manual project-only backup, stale edits, CSRF rejection, import/rollback, Excel export, printing and permissions. A service probe confirmed Apache's PHP handler had PostgreSQL, curl and ZIP extensions loaded. The demo backup checksum, spreadsheet, attachment and SQL scope were verified.
All eight tracked production table counts and row fingerprints matched the pre-cutover baseline. Existing user accounts, project records, IDs, history, imports, settings, database schema, APP_KEY and credentials were preserved.
Test artifacts and scripts are in `D:\LGU LAOAG CITY\offline-doc-manager\server_migration`; the isolated demo database contains only dummy data. A physical reboot and testing from another office computer remain manual checks.

## Rollback

If required, stop `LGUProjectTrackerApache` first, then start the original Apache from XAMPP. Its existing D: alias and original PHP installation remain available. Do not start both servers on port 80. Current application code works with either server; code safety copies are inside the private migration recovery directory. No database rollback is required for the server replacement.

## Maintaining the runtime

Use supported Apache/PHP releases and verify download hashes. PHP's official Windows releases were used; Apache Lounge supplies the Windows Apache binaries referenced by Apache and PHP documentation. Version metadata and package hashes are saved in `runtime\runtime-downloads.json`.
Stage updates on a separate local port and test them before replacing the runtime. The one-time provisioning/recovery scripts are diagnostic records, not scheduled jobs; do not rerun them against an existing installation.
