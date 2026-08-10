# ⏱️ Time-Travel SQLite Debugger (Database Time Machine)

An open-source, ultra-lightweight web application and CLI daemon designed to track and instantly rewind a corrupted or modified **SQLite** database file back to any previous state (e.g. 3 minutes ago) during local development — just like scrubbing a video.

> 🇹🇷 **Türkçe Dokümantasyon:** Türkçe rehber için lütfen [README.tr.md](README.tr.md) dosyasına bakın.

---

**⚠️ Important Note on Architecture & Local Development**

- **Strictly for Local Development:** This tool is designed for zero-dependency, local development debugging.
- **WAL Checkpointing & Abort Protection:** Before creating any snapshot or performing a restore, the tool executes `PRAGMA wal_checkpoint(TRUNCATE);` via PDO. If the WAL checkpoint fails (e.g. database busy or locked), the operation strictly aborts to prevent recording or loading un-flushed data. When successful, all WAL frames write into the main `.sqlite` file, eliminating race conditions without requiring separate `-wal` or `-shm` backup files.
- **Pre-Restore Safety Net & Validation:** Prior to any restoration, an automatic `pre-restore-safety-snapshot` is generated. If safety snapshot creation fails, the restore process immediately aborts to protect current state. Post-restoration, `PRAGMA integrity_check;` validates database structure.
- **Localhost Security & Quotas:** API access is restricted strictly to socket peer IPs (`127.0.0.1` / `::1`), and disk usage is managed via 50-file unpinned snapshot count and 1 GB storage limits.

---

## 🚀 Features

- **⚡ Zero Dependencies:** Built with pure PHP 8+ and Vanilla JS. Requires no heavy frameworks or npm packages.
- **💾 WAL Checkpoint & Core Integrity:** Flushes all WAL frames via `PRAGMA wal_checkpoint(TRUNCATE);` before generating a snapshot. Strictly aborts snapshot generation if checkpoint fails.
- **⏱️ Microtime & Unique ID Identification:** Uses `microtime(true)` + `uniqid()` (`{timestamp}_{uniqid}_database.sqlite`) to prevent snapshot naming collisions.
- **🛡️ Pre-Restore Safety Net:** Automatically creates a `pre-restore-safety-snapshot` right before overwriting the active database. Aborts restoration immediately if safety backup fails.
- **✅ Post-Restore Integrity Verification:** Runs `PRAGMA integrity_check;` immediately after restoration and reports validation results back to the frontend.
- **🔍 Visual Data & Table Diff Viewer:** Compare row counts and table changes (`+3 rows in live`) between any historic snapshot and your current live database before restoring.
- **⚠️ Application Worker Restart Warning:** Prominently alerts developers after restore completion to restart running app workers (e.g. Laravel/PHP workers) to clear cached SQLite connections.
- **📌 Snapshot Pinning & Tagging:** Pin important snapshots with one click to protect them from auto-cleanup. Pinned snapshots are immune to deletion and can intentionally cause the total backup count to exceed the nominal 50-file limit.
- **📥 One-Click Snapshot Export:** Download any historic database state directly as a `.sqlite` file.
- **🧹 Dual Storage Cleanup Limits:** Automatically enforces both **50 unpinned backup count** and **1 GB storage limits**, purging oldest unpinned snapshots as needed while preserving pinned snapshots.
- **🔒 Localhost Security Check:** Restricts API requests (`api.php`) strictly by socket peer IP (`REMOTE_ADDR` matching `127.0.0.1` / `::1`), intentionally ignoring untrusted proxy headers (`X-Forwarded-For`, `X-Real-IP`).
- **🌐 Full i18n / Multi-Language:** Translation files are decoupled in `lang/` (🇹🇷 Turkish, 🇬🇧 English). Switch languages dynamically from the UI.

---

## 📁 Project Layout

```
time-travel/
├── index.html        # Chrono Console UI (Tailwind CSS + Vanilla JS + i18n Engine)
├── api.php           # Snapshot listing, restoration, WAL checkpoint, integrity check & API
├── watcher.php       # CLI background listener daemon script (WAL Checkpoint & Storage quota aware)
├── lang.php          # Multi-language (i18n) helper class
├── lang/             # JSON language files directory
│   ├── tr.json       # Turkish language file
│   └── en.json       # English language file
├── database.sqlite   # Target SQLite database file (created automatically)
├── backups/          # Timestamped backups directory (created automatically)
├── README.md         # Primary English documentation
└── README.tr.md      # Turkish documentation & guide
```

---

## 🌍 Translation Guide: Adding & Updating Language Files

### 1. Adding a New Language
Create a JSON file inside `lang/` matching the ISO code (e.g., `lang/es.json`, `lang/de.json`):

```json
{
  "code": "es",
  "name": "Español",
  "flag": "🇪🇸",
  "cli": { ... },
  "api": { ... },
  "ui":  { ... }
}
```

### 2. Updating Existing Translations
Open any file in `lang/*.json` and edit strings. Changes reflect immediately on the Web UI and CLI without restarting the server.

---

## 💻 Installation & Usage

### 1. Start the Watcher Daemon (Terminal 1)
```bash
php watcher.php
```
*To specify custom language or database file:*
```bash
php watcher.php database.sqlite en
```

### 2. Start Local Web Server (Terminal 2)
```bash
php -S 127.0.0.1:8000
```

### 3. Open Web Interface
Open your browser and navigate to: **`http://127.0.0.1:8000`**

---

## 🧪 How to Test

1. Open the web interface.
2. Click **"✍️ Add Test Data (DB Write)"**.
3. Observe terminal output from `watcher.php`:
   `[+] Created snapshot: 1770752711.1234_65c3b1a209e8f_database.sqlite`
4. Drag the timeline slider or use **⬅️ / ➡️** arrow keys.
5. Click **"🔍 Compare Diff"** to inspect table row changes.
6. Click **"⚡ Restore To This State"** to rewind your database! Observe the pre-restore safety snapshot creation, `PRAGMA integrity_check` verification, and worker restart notice.

---

## 🔒 File Permissions & Least Privilege (Linux / Unix)

For proper local read/write access between your CLI user and Web Server (e.g., `www-data` or PHP built-in server) without granting unnecessary execution bits to SQLite data or source files, apply granular permissions:

```bash
# Set directory permissions (775) for listing & writing
find . -type d -exec chmod 775 {} +

# Set standard file permissions (664) for read/write
find . -type f -exec chmod 664 {} +

# Grant execution permission strictly to the CLI daemon script
chmod +x watcher.php
```

---

## 📄 License

This project is open-source under the **MIT** license.

