# Free pilot: GitHub → Render + Neon

This branch includes PHP 8.4 Docker hosting, PostgreSQL sessions, private uploads,
persistent holiday settings and cloud project backups. Start with a **new, empty pilot
database**. Local records and accounts are not uploaded automatically.

## 1. Free accounts

1. Open https://dashboard.render.com/register and https://console.neon.tech/signup.
2. Choose **GitHub**, sign in as `shanpe-10` and complete signup/verification yourself.
3. Keep both on **Free**. No paid database, worker, cron job or persistent disk is needed.
4. When connecting Render to GitHub, allow **only** `shanpe-10/project-tracker`.

If Neon verification hangs in the embedded browser, use normal Edge or Brave.

## 2. Neon database and private files

1. Create `project-tracker-pilot`, PostgreSQL **18**, on the Free plan.
2. Choose a region available to both Neon and Render; use the same or nearest region.
3. Click **Connect** and turn **connection pooling off**. The backup locks and PostgreSQL
   dump need a direct connection. Retain `sslmode=require` in the connection string.
4. Create an Object Storage bucket dedicated to this pilot and keep it **private**.
5. Obtain its S3 endpoint, bucket name, signing region, access key and secret key.
   Keep these in the dashboards, not GitHub, screenshots or chat.

## 3. Render deployment

1. Choose **New → Blueprint**, connect `shanpe-10/project-tracker`, branch `main`.
2. Use Blueprint path `render.yaml`. Review the single **Docker / Free** web service.
3. Enter these values in Render's private environment when prompted:

| Variable | Value |
|---|---|
| `DB_URL` | Neon's direct PostgreSQL connection string |
| `AWS_ENDPOINT` | Bucket S3 endpoint URL (`AWS_ENDPOINT_URL_S3` in Neon) |
| `AWS_BUCKET` | Private bucket name |
| `AWS_DEFAULT_REGION` | Bucket signing region (`AWS_REGION` in Neon) |
| `AWS_ACCESS_KEY_ID` | Bucket access key |
| `AWS_SECRET_ACCESS_KEY` | Bucket secret key |
| `TRACKER_BOOTSTRAP_NAME` | First pilot superadmin's display name |
| `TRACKER_BOOTSTRAP_USERNAME` | New pilot username, 3–80 letters, digits, dots, underscores or hyphens |
| `TRACKER_BOOTSTRAP_PASSWORD` | New pilot password, 12+ characters and at most 72 bytes |

The Blueprint generates `APP_KEY_BASE64` once. **Keep this key unchanged** across
deployments. Startup uses Render's HTTPS URL, migrates the database and creates the
first superadmin only if no accounts exist. It never resets an existing password.

4. Deploy the Blueprint and wait for GitHub checks and the Render build to succeed.
5. Open the service's **HTTPS URL** without `/public`. Sign in as the pilot superadmin.
6. Remove `TRACKER_BOOTSTRAP_PASSWORD` from Render's environment and redeploy.
7. In account management, create an **Admin** for projects and the needed Staff accounts.

Do not copy the local `.env` or secrets file into Render. Never run `migrate:fresh`
against a database with records.

## 4. Pilot checks

- Create a disposable project as Admin and view it through a second account/browser.
- Check automatic saved updates, editing names, and editor names in project logs.
- Upload a small attachment and profile photo; check authorized downloads.
- Redeploy once and confirm records, uploads and sessions remain available.
- Create a manual project backup, **Download latest backup**, and keep an independent copy.
- Check Staff cannot access admin backup controls or account management.
- Record final inspection and check Pending; record completion through the actual milestone date.

The editing indicator reports presence. It does not stream typed text. Stale saves
are rejected so users must reload the newer record before saving again.

## Free-plan behavior

Render Free sleeps when idle and may take about a minute to wake. Its disk is temporary;
durable records live in Neon and files in the private bucket. Schedules run only while
Render is awake. Statuses catch up at startup. Cloud backups are checked hourly while
awake, at most once automatically per day. Admin can also create a manual backup.

Private storage retains the latest project snapshot of seven days, four weeks and
three months; an object can cover multiple retention periods. Older snapshots are
automatically removed. The tracker downloads the latest archive; IT can retrieve
retained older archives from the private bucket. Backups include project records,
numbering, history, Excel export and attachments. They exclude accounts, profile
photos and system settings; IT restoration needs compatible accounts.

Monitor both dashboards' free quotas, especially database compute and file storage.
Active pages poll for changes and keep the services awake. This is a pilot with
limited usage and schedules, not always-on hosting.

Provider references: https://render.com/docs/free · https://render.com/docs/blueprint-spec ·
https://neon.com/blog/neon-free-plan-1-gb-per-project

## Updates

Push tested source changes to GitHub `main`; Render deploys after checks pass.
Keep passwords, keys, uploads and database dumps out of Git. Take a project backup
before changing record structure or schema.
