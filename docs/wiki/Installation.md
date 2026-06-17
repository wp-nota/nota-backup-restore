# Installation

## From WordPress Admin

1. Go to **Plugins → Add New**
2. Search for **Nota Backup & Restore**
3. Click **Install Now** then **Activate**

## Manual Installation

1. Download the ZIP from [WordPress.org](https://wordpress.org/plugins/nota-backup-restore/)
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP file and click **Install Now**
4. Click **Activate Plugin**

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 5.0 |
| PHP | 7.4 |
| PHP ZipArchive extension | Required |
| MySQL | 5.6 |

## After Activation

- A **Nota Backup** menu item appears in the WordPress admin sidebar
- The backup directory is automatically created at `wp-content/uploads/nota-backup-restore/`
- The plugin creates a `wpbn_backups` database table to track backup records

## Uninstallation

Deactivating the plugin does **not** delete your backup files or database records. To fully remove all data, delete the plugin from **Plugins → Installed Plugins**.
