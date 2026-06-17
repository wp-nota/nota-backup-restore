# Emergency Recovery *(Pro)*

Emergency Recovery allows you to restore your site even when the WordPress admin panel is completely inaccessible — for example after a failed update, a broken plugin, or a corrupted database.

## How It Works

The plugin generates a standalone emergency recovery URL that works independently of WordPress. You access it directly in the browser, bypassing the normal WordPress login system.

## Setup

1. Go to **Nota Backup → Settings → Emergency Recovery**
2. Enable the feature
3. Set a strong **recovery password**
4. Save the generated **recovery URL** in a safe place (outside your server)

## Accessing the Recovery Page

If your site goes down, open the recovery URL in your browser:
```
https://yourdomain.com/?wpbn_recovery=YOUR_TOKEN
```

You will be prompted for the recovery password.

## What You Can Do

From the emergency recovery page:
- **Restore a backup** — select any available backup and restore it
- **Download a backup** — download a ZIP to your computer
- **View backup list** — see all backups stored on the server

## ⚠️ Security Notes

- Use a strong, unique recovery password
- Save the recovery URL somewhere safe and offline (e.g. a password manager)
- The recovery page bypasses WordPress authentication — treat the URL and password as highly sensitive
- Do not share the recovery URL with anyone

## Difference from Installer PHP

| | Emergency Recovery | Installer PHP |
|---|---|---|
| Requires server file access | No | Yes (FTP/SFTP) |
| Works when WP is broken | ✅ | ✅ |
| Pre-installed on server | ✅ | ❌ (upload manually) |
| Password protected | ✅ | ❌ |
| Requires Pro | ✅ | ❌ |
