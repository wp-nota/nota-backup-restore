# Configuration

Go to **Nota Backup → Settings** to configure the plugin.

## General Settings

### Max Backups
Maximum number of backup files to keep on the server. When the limit is reached, the oldest backup is automatically deleted before a new one is created.

- Default: `5`
- Recommended: `3–10` depending on available disk space

### Chunk Size (MB)
Each backup is processed in chunks to avoid PHP memory and timeout limits. The plugin automatically calculates an optimal chunk size based on available memory.

- Default: auto-calculated
- Manual range: `2–50 MB`
- If backups fail mid-way, try lowering this value

### Files Per Chunk
Number of files processed per chunk. Calculated automatically from chunk size (`chunk_size_mb × 40`).

- Set to `0` for automatic
- Manual override: `10–2000`

## Exclusion Rules

You can exclude specific files or folders from backups under **Settings → Exclusions**.

**Common exclusions:**
- `wp-content/uploads/nota-backup-restore` — backup directory itself (excluded by default)
- `wp-content/cache` — cache files (large, not needed in backup)
- `wp-content/uploads/large-video-folder` — large media folders

Enter one path per line, relative to the WordPress root.

## Pro Settings

The following settings are available in the **Pro** version:

- **Cloud storage** — Google Drive, S3, Wasabi, Dropbox, OneDrive, FTP/SFTP
- **Scheduled backups** — automatic daily/weekly/monthly backups
- **Email notifications** — alerts on success or failure
- **Encryption** — AES-256 password protection for backup files
