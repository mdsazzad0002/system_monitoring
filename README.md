# System Monitoring Updater

This folder contains the small CLI updater/health-monitor script.

It is designed to:

- read settings from the root `.env`
- use `.env` plus `config.php` as the single global configuration source
- ping the server
- verify the license on first run
- check updates on later runs
- detect `full` or `partial` version type
- create a pre-update JSON backup of `.env` and database settings
- run and send a database backup twice per day on schedule
- download update packages in chunks
- reuse the same version package if it is already present
- merge and apply updates with replace-only sync
- clean downloaded zip and temp folders after apply
- write a complete run log to a day-wise file like `system_monitoring_update_data/system_monitoring-YYYY-MM-DD.log`
- store state in `system_monitoring_update_data/updater.json`
- read primary updater settings from `system_monitoring_update_data/system_monitoring.json`
- cache the latest update response in `system_monitoring_update_data/runtime_cache.json`

## Run

```bash
php system_monitoring/bootstrap.php
```

Optional flags:

```bash
php system_monitoring/bootstrap.php --manual
php system_monitoring/bootstrap.php --download-update
php system_monitoring/bootstrap.php --force-update-check
php system_monitoring/bootstrap.php --backup-now
```

## Environment

The updater reads the root `.env` file.

Example `.env` still works as fallback, but JSON is preferred for updater settings:

```env
license="LIC-XXXX-XXXX-XXXX-XXXX"
```

Preferred JSON config:

```json
{
  "license": "LIC-XXXX-XXXX-XXXX-XXXX",
  "target_host": "http://system.localhost",
  "software_id": "testproject",
  "current_version": "3",
  "update_mode": "partial",
  "auto_recovery": true,
  "auto_download_update": true,
  "database_backup_stale_retry_minutes": 10,
  "database_backup_retry_minutes": 30
}
```

License behavior:

- `license_required=true` blocks startup when the license key is missing
- `allow_unlicensed=true` is for developer or coder mode only
```

## Key Fields

- `license` - license key used for verification and update checks
- `targethost` - base server host
- `softwareid` - project slug sent to the server
- `currentversion` - local installed version
- `auto_recovery` - run recovery flow when ping fails
- `auto_download_update` - automatically download and apply updates when found
- `auto_database_backup` - automatically generate and send database backups
- `database_backup_times` - comma-separated times for the 2 daily backups
- `database_backup_min_gap_hours` - minimum gap between successful backups
- `database_backup_retry_minutes` - retry wait when a non-busy backup fails
- `database_backup_stale_retry_minutes` - retry wait when no successful backup exists within the minimum gap window
- `database_backup_chunk_size` - upload chunk size for backup files
- `database_backup_root` - local temp folder used before upload completes
- `license_required` - blocks updater startup when `license` is missing
- `allow_unlicensed` - developer escape hatch for local testing only
- `update_mode` - `partial` or `full`, used when the server does not send a version type
- `update_target_root` - where extracted files are copied
- `download_chunk_size` - size of each chunk in bytes
- `download_timeout` - timeout used for each download request

## Safe Apply Rules

This rebuild intentionally avoids the earlier destructive behavior.

- update files are copied over existing files
- target contents are not cleared first
- if `update_target_root` is missing, the updater applies to the project root by default
- the updater keeps files that are not part of the package
- downloaded packages are removed after a successful apply

## Runtime Flow

1. Load `.env`
2. Ping `GET /api/system_monitoring/ping`
3. Verify license on first run
4. Save baseline state to `system_monitoring_update_data/updater.json`
5. Check `POST /api/system_monitoring/update/check`
6. If a database backup is due, generate it and send it in chunks
7. If an update exists, download the archive in chunks
8. Merge the chunks into a zip
9. Extract the archive
10. Copy files into the configured target root

## State File

The updater stores its runtime state in:

```text
system_monitoring_update_data/updater.json
```

It keeps:

- last boot time
- last ping result
- license state
- last update check result
- download progress
- last applied package
- last backup metadata
- history events

The runtime cache keeps:

- the last background spawn window
- the last update check response
- the cache expiry timestamp
- the source used for the response, either `remote` or `cache`

Update cache TTL:

- default: `3600` seconds
- override with `update_cache_ttl_seconds` in `.env`

## Notes

- `softwareid` is treated as the project slug.
- The updater no longer hardcodes a project name.
- A lock file prevents overlapping runs.
- This script is designed for Task Scheduler, cron, or manual execution.
