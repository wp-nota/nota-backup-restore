# Cloud Storage *(Pro)*

Nota Backup & Restore Pro can automatically upload backups to cloud storage after each backup completes.

## Supported Providers

| Provider | Auth Method |
|---|---|
| Google Drive | OAuth 2.0 |
| Amazon S3 | Access Key + Secret |
| Wasabi | Access Key + Secret |
| Dropbox | OAuth 2.0 |
| Microsoft OneDrive | OAuth 2.0 |
| FTP / SFTP | Username + Password |

## Setup

Go to **Nota Backup → Settings** and select the cloud provider tab.

### Google Drive
1. Create a project in [Google Cloud Console](https://console.cloud.google.com/)
2. Enable the Google Drive API
3. Create OAuth 2.0 credentials (Desktop app)
4. Enter **Client ID** and **Client Secret** in Settings
5. Click **Connect** and authorize access
6. Select a destination folder

### Amazon S3 / Wasabi
1. Create a bucket in your S3/Wasabi account
2. Create an IAM user with `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject` permissions
3. Enter **Access Key**, **Secret Key**, **Region**, and **Bucket** in Settings

### Dropbox
1. Go to [Dropbox App Console](https://www.dropbox.com/developers/apps)
2. Create a new app with **Full Dropbox** access
3. Enter the app credentials in Settings
4. Click **Connect** and authorize

### OneDrive
1. Register an app in [Azure Portal](https://portal.azure.com/)
2. Add `Files.ReadWrite` permission
3. Enter credentials in Settings
4. Click **Connect** and authorize

### FTP / SFTP
1. Enter your FTP host, username, password, and port
2. Set the remote directory path
3. Click **Test Connection** to verify

## Local File Handling

After a successful cloud upload, you can configure the plugin to:
- **Keep** the local ZIP file
- **Delete** the local ZIP file (cloud only)

This setting is available per cloud provider in **Settings**.
