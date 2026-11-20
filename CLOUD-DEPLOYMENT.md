# Existing Render deployment

See README.md for source setup, deployment settings, and release checks.
The current source uses Admin and Staff roles. Existing accounts retain their
usernames and password hashes. Startup bootstrap skips any nonempty users table.
Keep the existing application key and database/storage configuration in Render.
Full database recovery snapshots belong outside the source repository.
