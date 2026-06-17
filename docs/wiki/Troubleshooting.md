# Troubleshooting

## Backup fails or stops mid-way

**Cause:** PHP memory limit or execution time limit reached during backup.

**Fix:**
1. Go to **Settings** and lower the **Chunk Size** (try `2–3 MB`)
2. Add large folders (cache, uploads) to **Exclusion Rules**
3. If possible, increase PHP memory limit in `php.ini` or `wp-config.php`:
   ```php
   define('WP_MEMORY_LIMIT', '256M');
   ```

---

## Backup stuck at 0% or "Preparing"

**Cause:** A previous backup left a corrupted state in the database.

**Fix:** Run this SQL query in phpMyAdmin or your database tool:
```sql
DELETE FROM wp_options WHERE option_name = 'wpbn_backup_state';
```
Then try starting a new backup.

---

## "ZipArchive not available" warning

**Cause:** The PHP `zip` extension is not enabled on your server.

**Fix:** Contact your hosting provider and ask them to enable the PHP `zip` extension.

---

## Download button is missing

**Cause:** The backup ZIP file no longer exists on the server (deleted manually or by cleanup).

**Fix:** The record remains in history but the local file is gone. You can delete the record or restore from a cloud copy if available *(Pro)*.

---

## Backup directory not writable

**Cause:** WordPress cannot write to `wp-content/uploads/nota-backup-restore/`.

**Fix:** Set directory permissions to `755` via FTP or hosting file manager.

---

## Restore fails or site breaks after restore

**Cause:** URL mismatch between backup source and current site, or incomplete restore.

**Fix:**
1. Make sure you entered the correct **New Site URL** in the restore dialog
2. Clear all caches after restore
3. If the site is broken, use the **Installer PHP** method for a clean restore

---

## Scheduled backups not running *(Pro)*

**Cause:** WordPress cron is not firing, or the schedule was not saved correctly.

**Fix:**
1. Check that at least one schedule is **enabled** in Settings
2. Verify WordPress cron is working — install a plugin like WP Crontrol to inspect scheduled events
3. If your hosting blocks WP-Cron, set up a real cron job:
   ```
   */5 * * * * wget -q -O - https://yourdomain.com/wp-cron.php?doing_wp_cron
   ```

---

## Still having issues?

[Report an issue on GitHub →](https://github.com/wp-nota/nota-backup-restore/issues)
