# Backup Types

Nota Backup & Restore supports three backup types.

## Full Backup

Backs up everything — all files and the complete database.

**Includes:**
- All WordPress core files
- `wp-content` (themes, plugins, uploads, etc.)
- Complete MySQL database (all tables)

**Best for:** Full site migration, disaster recovery

## Database Only *(Pro)*

Backs up only the MySQL database — no files.

**Includes:**
- All database tables (or selected tables)
- Exported as SQL inside the ZIP

**Best for:** Before running database migrations, plugin updates that modify DB schema

## Files Only *(Pro)*

Backs up only the file system — no database.

**Includes:**
- All WordPress files (or selected folders)
- Does not include any database content

**Best for:** Before theme or plugin file edits, after large media uploads

---

## Choosing the Right Type

| Situation | Recommended Type |
|---|---|
| Before a major update | Full |
| Before editing theme files | Files Only |
| Before a WooCommerce migration | Database Only |
| Weekly automated backup | Full |
| Daily incremental-style backup | Database Only |
