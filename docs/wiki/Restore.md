# Restore *(Pro)*

The restore wizard allows you to restore a backup directly from the WordPress admin panel.

## How to Restore

1. Go to **Nota Backup** (main page)
2. Find the backup in the **Backups** list
3. Click **Actions → Restore**
4. Select restore mode
5. Type `I confirm` in the confirmation field
6. Click **Yes, Restore**

## Restore Modes

### Full Restore
Restores both the database and all files. Use this for complete site recovery.

### Database Only
Restores only the database. You can select specific tables to restore.

### Files Only
Restores only the file system. You can select specific folders or files.

## URL Handling

If the backup was created on a different URL (e.g. restoring from staging to production), the plugin automatically detects the difference and performs a search-replace on the database to update all URLs.

You can manually set the **New Site URL** in the restore dialog if needed.

## ⚠️ Warning

- Restoring **overwrites** your current database and files
- This action **cannot be undone**
- Always create a fresh backup before restoring an older one

## Restoring Without WordPress Access

If your site is broken and you cannot access the admin panel, use the **Installer PHP** method instead.

→ See [Installer PHP](Installer-PHP)
