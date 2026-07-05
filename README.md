# System Monitoring Updater

This folder contains the small CLI updater/health-monitor script.

It is designed to:

- read settings from the root `.env`
- ping the server
- verify the license on first run
- check updates on later runs
- detect `full` or `partial` version type
- create a pre-update JSON backup of `.env` and database settings
- download update packages in chunks
- reuse the same version package if it is already present
- merge and apply updates with replace-only sync
- clean downloaded zip and temp folders after apply
- write a complete run log to `system_monitoring/system_monitoring.log`
- store state in `update_data/updater.json`

## Run

```bash
php system_monitoring/bootstrap.php
```

Optional flags:

```bash
php system_monitoring/bootstrap.php --manual
php system_monitoring/bootstrap.php --download-update
php system_monitoring/bootstrap.php --force-update-check
```

## Environment

The updater reads the root `.env` file.

Example:

```env
license="LIC-XXXX-XXXX-XXXX-XXXX"
targethost="http://system.localhost"
softwareid="testproject"
currentversion="0.0.0"
auto_recovery=true
auto_download_update=true
update_mode=partial
update_target_root="D:/path/to/your/project"
```

## Key Fields

- `license` - license key used for verification and update checks
- `targethost` - base server host
- `softwareid` - project slug sent to the server
- `currentversion` - local installed version
- `auto_recovery` - run recovery flow when ping fails
- `auto_download_update` - automatically download and apply updates when found
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
4. Save baseline state to `update_data/updater.json`
5. Check `POST /api/system_monitoring/update/check`
6. If an update exists, download the archive in chunks
7. Merge the chunks into a zip
8. Extract the archive
9. Copy files into the configured target root

## State File

The updater stores its runtime state in:

```text
update_data/updater.json
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

## Notes

- `softwareid` is treated as the project slug.
- The updater no longer hardcodes a project name.
- A lock file prevents overlapping runs.
- This script is designed for Task Scheduler, cron, or manual execution.
