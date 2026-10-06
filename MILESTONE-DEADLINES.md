# Automatic milestone due dates

Implemented on 5 October 2026 in the active application at `D:/LGU LAOAG CITY/project-tracker`.

- Entering or changing the Bidding Date immediately recalculates separate, read-only **Pre-inspection Due Date**, **Notice to Proceed Due Date**, and **Material Testing Due Date** fields. Each is one **calendar month** after bidding. Month ends clamp to the last valid day: 31 January 2026 becomes 28 February 2026, and 31 January 2024 becomes 29 February 2024.
- The Pre Inspection, Notice to Proceed, and new **Material Testing Date** fields retain the **actual milestone dates**. Automatic calculations do not fill or overwrite them. Due dates also appear in project details and the printable document.
- **Final Inspection Due Date** uses the extended completion date when available; otherwise the explicit Contract End Date. When those are absent, it uses Notice to Proceed, or Contract Date if NTP is absent, plus Contract Duration in calendar days. Accepted examples: `60`, `60CD`, `60 CD`, `60 calendar days`. Working-day durations are not guessed; enter an explicit Contract End Date for those cases.
- Open **Admin → Timeline day settings → Final inspection advance reminder**. Choose a standard duration or **Custom**, enter 1–3,650 days, and click **Save timeline days**. The default is 15 days before the effective contract end date. Only administrators can change the shared setting. Custom controls remain stable until saving, preventing a blur-induced layout shift from swallowing the Save click.
- Final inspection remains due on the contract end date and becomes overdue the following day. Recording an actual inspection date that has already occurred, resolving its alert, archiving the project, or marking the project Completed/Released removes the active reminder. Future scheduled inspection dates do not falsely count as completed inspections.
- **Admin → Timeline day settings → Pre-inspection / NTP / Material testing** is now one shared advance-reminder setting for all three bidding-based alerts. The due dates still remain one calendar month after bidding. Without a saved shared setting, the existing material-testing lead time is retained. Separate NTP/material controls were removed; hidden legacy values remain unchanged for records without bidding dates. Legacy NTP-award alerts no longer duplicate bidding-based NTP issuance alerts.
- All five due dates, including **SWA Due Date**, appear together in a compact row above the actual inspection/completion fields. The six Resolved Alerts categories use one compact desktop row, wrapping on smaller screens.
- Saving valid actual pre-inspection, NTP, material-testing, final-inspection or SWA dates on/before today removes matching active alerts and displays **Resolved** entries in Resolved Alerts. Entries use the actual milestone date as their resolved date. Future dates remain scheduled; clearing a date reopens its alert when due, unless it was also manually resolved. Automatic entries offer **View** instead of an ineffective Restore button; edit/clear the actual date to reopen. Manual resolution flags and their Restore actions are preserved. Legacy NTP records without bidding retain their earlier timeline behavior. NOA remains unchanged because its existing date anchors the NOA compliance timeline.
- Material Testing Date is validated and included in saving, details, printing, import preview, date filtering, CSV exports, formatted Excel exports and project backups. It is appended to existing CSV/Excel columns, preserving earlier column positions. Older spreadsheets that omit it preserve existing values on overwrite. No binary template rewrite was needed.
- After the approved limited removal, **Mark Done** is available only for **NOA** and **legacy NTP** reminders without a valid bidding date. Pre-inspection, bidding-based NTP, material testing, final inspection and SWA alerts offer **Enter date** for permitted editors. Their actual dates resolve the alerts. Date-based alerts have no manual bulk-selection checkbox; the bulk controls appear only when eligible manual reminders are present. The project Resolve menu follows the same policy, including NOA warning reminders.
- Individual and bulk handlers reject date-based manual dismissals. The project save/edit APIs reject new date-based manual markers and new global timeline dismissals. Existing historical markers are preserved during ordinary edits and may still be restored; no database migration, backfill, or deletion of old resolution history was performed. Historic imports retain their existing metadata handling.
- Success feedback now allows clicks through to the form, retaining its close button. This fixes a toast covering Save during rapid edits.

- Dashboard notifications, active-alert project filtering, Resolve options and Resolved Alerts use the new milestones and the correct derived due dates. Upcoming and overdue variants share their resolution state.

## Data and deployment

Deadlines are derived from existing project JSON; no database schema migration or production backfill is required. Original milestone values, records, accounts, permissions, credentials and application keys were preserved. Existing spreadsheet formats remain compatible. Refresh the browser with **Ctrl+F5** to load the updated JavaScript.

## Verification

- PostgreSQL regression suite: **64 tests, 604 assertions**, all passing in `tracker_audit_test` with isolated schemas.
- **Ten JavaScript test scripts** pass, including month ends, leap years, duration/extension precedence, warning/due/overdue boundaries, resolution, and actual-date preservation.
- **Seven browser checks**, zero JavaScript errors, in the isolated `tracker_runtime_test` demo on localhost:8081. Date-based alerts exposed Enter date instead of Mark Done, actual dates resolved them and clearing dates reopened them, the project Resolve menu contained only eligible NOA reminders, NOA and legacy NTP Restore still worked, bulk resolution selected only legacy NTP and left material testing pending, SWA resolved on its actual date, and viewers received no edit/manual controls or API access. All dummy projects from this walkthrough were deleted.
- Print PDF contains every new due date on **one A4 page**; its rendered image was visually checked for overflow and readability. This is a representative record, not a guarantee for arbitrarily large project content.
- PHP/JavaScript syntax checks pass. Production row counts and row fingerprints were compared with a fresh pre-change baseline. One concurrent live `project.saved` event occurred during verification: the project count stayed at 50, and history/audit counts each increased by one. These live changes were preserved; exact fingerprint equality cannot be claimed. All automated test writes used dedicated test databases.

Repeat tests from the active application directory with the standalone runtime on PATH:

```powershell
php vendor/bin/phpunit --configuration phpunit.audit.xml
node --test tests/*.test.cjs
```

## Files changed

- `app/Support/TrackerMilestones.php`
- `app/Http/Controllers/TrackerDataController.php`
- `public/js/utils.js`
- `public/js/timeline.js`
- `public/js/projects.js`
- `resources/views/tracker.blade.php`
- `tests/Feature/FocusedAuditTest.php`
- `tests/milestone-deadlines.test.cjs`
- `tests/medium-priority.test.cjs`
- `app/Support/TrackerProjectInput.php`
- `app/Support/FormattedProjectExport.php`
- `app/Http/Controllers/TrackerBackupController.php`
- `public/js/excel.js`
- `public/css/styles.css`
- `tests/uat-import-inputs.test.cjs`
- `tests/compact-alerts.test.cjs`
- `app/Http/Controllers/TrackerMediaController.php`
- `tests/manual-resolution.test.cjs`
- `MILESTONE-DEADLINES.md`

Matching source/test files were synchronized to the D: partial workspace. Small pre-change file snapshots and demo verification artifacts are retained in `D:/LGU LAOAG CITY/offline-doc-manager/milestone_work` , `D:/LGU LAOAG CITY/offline-doc-manager/compact_alerts_work` and `D:/LGU LAOAG CITY/offline-doc-manager/manual_resolution_work`; inactive C: application copies were left unchanged.
