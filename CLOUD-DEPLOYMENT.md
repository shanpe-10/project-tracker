# Existing Render deployment

See README.md for source setup, deployment settings, and release checks.
Normal account management uses Admin and Staff roles. A separate Superadmin
account has read-only access to detailed Accountability logs and is protected
from ordinary account management. Startup initializes the reserved `admin` and
`superadmin` accounts once; other accounts and project data are preserved.
The initialization marker preserves subsequent password changes on redeploy.
Keep the existing application key and database/storage configuration in Render.
Full database recovery snapshots belong outside the source repository.
