# LGU Project Tracker: setup and user guide

This guide is for the LGU staff who will install and use the Project Tracker. The **IT setup** is done once on the server. Employees can then follow the **Daily use** section. Keep this guide with the system handover documents.

## Before you begin

- Choose one office computer or server to host the tracker. Employees will open it in a web browser over the LGU network. They do not need to install the tracker on each computer.
- Ask IT to keep the server, database, and backups in an access controlled location. Give each employee their own account; do not share the administrator password.
- If you are moving an existing tracker, make a verified backup of its database, attachment folder, and profile photo folder before changing the installation.

## Part A — IT setup on the server

### 1. Install the required software

Use standalone Apache and a supported thread-safe x64 PHP version compatible with Laravel 12. This installation uses Apache **2.4.69** and PHP **8.4.26**, installed under `D:\LGU LAOAG CITY\runtime`. XAMPP is not required. PHP needs the `pdo_pgsql`, `mbstring`, `dom`, and `zip` extensions. Composer is needed when installing from source. Node.js is needed only when rebuilding the front-end assets.

Start the **LGUProjectTrackerApache** Windows service and the separately installed **postgresql-x64-18** service. Both are configured for automatic startup. See `STANDALONE-SERVER.md` for service controls and recovery steps. Keep the tracker folder at `D:\LGU LAOAG CITY\project-tracker` or update the paths in these steps to match the actual location.

### 2. Prepare the database

For a **new, empty installation**, have a database administrator open pgAdmin or psql and import [`database/lgu_project_tracker_schema.sql`](database/lgu_project_tracker_schema.sql). First create a UTF8 PostgreSQL database, then load this file to create tracker tables and the starting project number. The file contains **no employee accounts or project records**.

For a **move from MariaDB**, create and migrate an empty PostgreSQL database, then run the data-transfer command in [POSTGRESQL-MIGRATION.md](POSTGRESQL-MIGRATION.md). MySQL dumps cannot be restored directly into PostgreSQL. For a move from PostgreSQL, restore the approved PostgreSQL backup into an empty database.

Ask the database administrator to create a dedicated database account for the tracker. It should connect to `lgu_project_tracker` and have the permissions needed for everyday reads and writes. The manual backup feature also needs database dump permissions; verify it in step 8. Do not use the PostgreSQL superuser account as the app account.

### 3. Configure the application

If `.env` is missing, copy `.env.example` to `.env`. In `.env`, set:

```text
APP_ENV=production
APP_DEBUG=false
APP_URL=http://YOUR-SERVER/project-tracker/public
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=lgu_project_tracker
DB_USERNAME=YOUR-TRACKER-DATABASE-USER
TRACKER_SECRETS_FILE="D:/LGU LAOAG CITY/project-data/project-tracker-secrets.php"
```

Replace `YOUR-SERVER` with the server name or address that employees will use. Put the database password in the file named by `TRACKER_SECRETS_FILE`, **outside** the public website folder:

```php
<?php
return ['postgresql_password' => 'YOUR-STRONG-DATABASE-PASSWORD'];
```

Limit access to `.env` and the secrets file to the server administrators and the account running Apache. Do not email these files or include them in employee handouts.

From a terminal in the tracker folder, run:

```powershell
composer install --no-dev --optimize-autoloader
& "D:\LGU LAOAG CITY\runtime\php\php.exe" artisan key:generate
& "D:\LGU LAOAG CITY\runtime\php\php.exe" artisan config:clear
```

Generate the application key **once** for a new installation. Keep the same `.env` and key when moving an existing installation. The tracker tables are provided by the schema file above; the default Laravel migrations alone do not install them.

If the supplied package does not contain `public/build`, install Node.js and run `npm ci` followed by `npm run build`. The current tracker also serves its own JavaScript and CSS files from `public`.

### 4. Prepare files and folders

The Apache account must be able to write to Laravel's `storage` and `bootstrap/cache` folders. By default, project attachments are stored in `D:\LGU LAOAG CITY\project-data\attachments` and profile photos in `D:\LGU LAOAG CITY\project-data\profile-photos`. You may change these with `TRACKER_ATTACHMENTS_PATH` and `TRACKER_PROFILE_PHOTOS_PATH` in `.env`. Give the Apache account access to those folders and keep them out of the public website folder.

### 5. Create the first IT Superadmin

For a new installation without a Superadmin, run this command on the server:

```powershell
& "D:\LGU LAOAG CITY\runtime\php\php.exe" artisan tracker:create-superadmin
```

Enter IT's full name, username and a password of at least 12 characters when prompted. The command refuses to create another bootstrap Superadmin if one already exists. If you restored an installation with a Superadmin, use that existing account.

Superadmin manages accounts only. Sign in to **Accounts**, choose **Create account**, and create an active **Admin** account for project operations. Admin automatically receives all project permissions and access to Timeline, Recent Activity and Automatic Backup. Account management remains exclusive to Superadmin.

### 6. Open the tracker

On the server, open `http://localhost/project-tracker/public/`. On an employee computer, use `http://YOUR-SERVER/project-tracker/public/`. Use the link provided by IT if it differs. The URL must open the login screen; do not open an HTML file directly.

Sign in as Admin, add a sample project, reopen it and confirm it appears for another authorized employee. Remove the sample after checking if it is no longer needed.

### 7. Create employee accounts through IT

Employees contact the **IT department** for new accounts. Public account requests are disabled. IT signs in as Superadmin, creates an account in **Accounts**, sets its role and active status, and provides the credentials to the employee. Existing pending accounts can still be approved or rejected.

- **Admin:** all project and operational actions by default, including add, edit, import, export, delete, delete all records, archive/restore, eligible alert resolution, timeline settings, activity and backups. No account management.
- **Staff:** project viewing and only the actions granted by IT. Use **Permissions** on the account row to assign or revoke Staff capabilities.
- **Superadmin:** account creation, editing, deletion, approval and Staff permission management. Its workspace is limited to Accounts.

Give employees the tracker link and their own account. Ask them to sign out on shared computers.

### 8. Set up and test backups

In **Admin → Automatic project backups**, use **Browse…** to select a server folder (or enter its absolute path) that is accessible to the server and protected from ordinary users. Select **Back up projects now** and check that a ZIP file and its `.sha256` file appear in the destination. A successful message on screen alone is not the whole check.

For automatic daily backups, IT must configure Windows Task Scheduler to run `"D:\LGU LAOAG CITY\runtime\php\php.exe" "D:\LGU LAOAG CITY\project-tracker\artisan" schedule:run` **every minute** under a service account with access to the database and backup folder. The tracker schedules its backup check at **2:00 AM Asia/Taipei time**. Confirm a new daily backup appears the next day.

Automatic and manual `project-data-*.zip` archives contain project records, project history, numbering and ID sequences, an Excel export, and project attachments. They exclude accounts, application code, profile photos and system settings. Restore their data-only SQL into a compatible tracker schema with the original user IDs/accounts available; handle existing project/history/numbering rows before loading to prevent duplicate IDs. These archives are not fresh-installation backups. The Excel export can also be loaded through the import preview.


### 9. Before employees begin

- Confirm the tracker link works from an employee computer on the LGU network.
- Confirm Admin has all project and operational actions without individual permission grants.
- Confirm Staff sees only the actions assigned by IT, and Superadmin opens Accounts without project navigation.
- Confirm a project can be added, edited, searched, printed, exported, and viewed on another computer.
- Confirm a manual backup works and its ZIP can be opened.
- Record who maintains the server and whom employees should contact for access or errors.

## Part B — Daily use for employees

### Sign in and find your way around

1. Open the link provided by IT and sign in with your own account. For a new account or Staff permission changes, contact the IT department.
2. **Dashboard → Needs attention** groups alerts into one row per project, showing its number, count and summary. Use its search and Previous/Next controls to browse results.
3. Red **Alerts** buttons open individual reminders with due dates, statuses and completion dates. The notification bell works on every page: click a project name to open Project Details, or its **Alerts** button to open Project Details with that project’s alerts dropdown. Both actions close Notifications.
4. **View All Projects** lists current projects. Search or filter the list, clear filters to restore the full list, and click a project title to open its details.
5. **Historical Archive** holds archived projects and offers restoration to authorized users. **Resolved Alerts** lists completed reminders.
6. Choose **How to use this tracker** for the in-app instructions. Admin also has **Admin** for Timeline, Recent Activity and Automatic Backup.

Admin receives every project and operational permission by default. Staff uses its IT-assigned permissions. Account management is exclusive to Superadmin; Superadmin opens directly to Accounts.

### Add or edit one project

1. Choose **Add Project** and complete the required title, bidding date, approved budget and winning bidder. Check the information against the source documents before saving.
2. The tracker assigns a project number when the new record is saved.
3. To update a record, click its title and choose **Edit**. Add or remove attachments there when authorized, then save and reopen the record to confirm the changes.
4. Enter contract duration as numeric **calendar days**. Whole numbers and decimals are accepted; leave words such as “days” out of the value.

If an action is unavailable to Staff, ask IT to review the assigned permissions. Admin does not require individual project permission grants.

### Handle dates and reminders

- **Pre-Inspection, Notice to Proceed and Material Testing** are due one calendar month after the bidding date. Month-end dates use the last valid day of the following month.
- **Contract due date** is contract date plus numeric duration in calendar days, including recorded extensions. **Final Inspection** alerts start seven days before this due date by default. Admin can edit the lead time under Timeline.
- Record actual milestone dates in **Project Details → Edit** when work is completed. Completed milestones become **Resolved** and retain their completion dates; NOA and NTP resolve automatically when their completion dates are recorded.
- Use **Mark Done** or **Resolve** only when offered for eligible NOA or legacy NTP alerts. Handle other milestone alerts through their actual completion dates.
- In **Resolved Alerts**, Restore reopens a manually resolved alert. For an automatically resolved milestone, edit or clear the relevant completion date instead.

### Import many projects

1. Choose **Import** and **Download template**.
2. Put one project on each row. Keep the column headings and use `YYYY-MM-DD` for dates. Use non-negative amounts with no more than two decimal places.
3. Choose the completed file. The tracker shows a preview before saving anything.
4. Correct invalid cells. For a duplicate, choose **Overwrite existing project** only when you mean to update that record; choose **Add as a new project** when it is a different project. The new project number is assigned automatically.
5. Review the choices and continue. Check the import result and a few project details afterward.

### Export, print, and archive

- **Export** downloads a spreadsheet of the selected project list. Check the filters before exporting.
- Open a project and choose **Print** for a reference copy. The printed page is **not an official document**.
- Archive older projects instead of deleting them when your office still needs their history. They remain in **Historical Archive**.

### When something goes wrong

- If sign-in fails, check the username and password. For a new account, activation or Staff permission changes, contact the IT department. There is no public account request form.
- If the page cannot connect, ask IT to confirm that Apache and PostgreSQL are running on the server. Do not try to repair the database yourself.
- If a save says the project changed, refresh the page, check the latest details, and make your change again.
- For other errors, note the exact message and the project number, then contact your administrator or IT support. **Never send your password**.

## Handover record

Before the system goes live, IT should fill in and store this information securely:

| Item | LGU entry |
| --- | --- |
| Tracker link for employees | |
| Server name and IT contact | |
| Database administrator contact | |
| First tracker administrator | |
| Backup destination and person checking it | |
| Attachment and profile photo backup locations | |
| Date of last successful restore test | |

Database migration and commands: [POSTGRESQL-MIGRATION.md](POSTGRESQL-MIGRATION.md). Older Word/PDF guide copies are historical and their database instructions are superseded by this guide.



