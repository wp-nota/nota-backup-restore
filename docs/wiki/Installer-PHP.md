# Installer PHP

The Installer PHP is a standalone restore script that works without WordPress. It is the recommended method for restoring a site when WordPress is broken or inaccessible.

## What is it?

When you click **Download → Installer PHP**, the plugin generates a self-contained PHP script pre-configured with your backup file details. Upload this script to your server and run it in a browser to restore your site.

## How to Use

### Step 1 — Download the Installer
1. Go to **Nota Backup** (main page or Backup History)
2. Click **Download ▾ → Installer PHP** next to the backup you want to restore
3. Save the `installer.php` file to your computer

### Step 2 — Upload to Server
Upload both files to your server root (or the directory where you want to restore):
- `installer.php`
- The backup ZIP file (e.g. `wpbn-backup-2024-01-15.zip`)

You can use FTP, SFTP, or your hosting file manager.

### Step 3 — Run the Installer
Open the installer in your browser:
```
https://yourdomain.com/installer.php
```

### Step 4 — Follow the Steps
The installer will guide you through:
1. **Database credentials** — enter your database host, name, username and password
2. **Site URL** — confirm or change the site URL (useful when migrating)
3. **Restore** — the installer extracts the ZIP and imports the database

### Step 5 — Delete the Installer
After a successful restore, **immediately delete** `installer.php` from your server for security.

## ⚠️ Security Notice

The installer file provides full access to restore your database and files. Never leave it on your server after use. The file is designed to self-delete after a successful restore, but always verify it is removed.

## Use Cases

- WordPress admin panel is inaccessible (white screen, broken site)
- Migrating to a new server or hosting provider
- Restoring to a local development environment
- Setting up a staging site from a production backup
