# ⏱️ Time-Travel SQLite Debugger (Database Time Machine)

An open-source, ultra-lightweight web application and CLI daemon designed to track and instantly rewind a corrupted or modified **SQLite** database file back to any previous state (e.g. 3 minutes ago) during local development — just like scrubbing a video.

> 🇹🇷 **Türkçe Dokümantasyon:** Türkçe rehber için lütfen [README.tr.md](README.tr.md) dosyasına bakın.

---

**​⚠️ Important Note on Architecture & SQLite**

**​Strictly for Local Development:** This tool is designed to be a zero-dependency, plug-and-play visual scrubber for single-user debugging. It uses a raw POSIX file copy/rename mechanism to achieve instant "time travel" without requiring specific PHP extensions or complex .backup commands.
​Because a local debugging environment does not experience concurrent writes, race conditions, or active traffic, this file-copy method is perfectly safe here.
**​Do not use this tool or its file-copy logic in a production environment**, especially if your SQLite database uses WAL (Write-Ahead Logging) mode, as copying live database files during active transactions will lead to data corruption.

---

## 🚀 Features

- **⚡ Zero Dependencies:** Built with pure PHP 8+ and Vanilla JS. Requires no heavy frameworks or npm packages.
- **💾 WAL Checkpoint & Core Integrity:** Flushes all WAL frames via `PRAGMA wal_checkpoint(TRUNCATE);` before generating a snapshot. Backs up single, consistent `.sqlite` files and eliminates race conditions.
- **⏱️ Microtime & Unique ID Identification:** Uses `microtime(true)` + `uniqid()` (`{timestamp}_{uniqid}_database.sqlite`) to prevent snapshot naming collisions.
- **🛡️ Pre-Restore Safety Net:** Automatically creates a `pre-restore-safety-snapshot` right before overwriting the active database, preventing accidental data loss.
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

## 🔒 File Permissions (Linux / Unix)

Make sure you are inside the tool's directory, then run:

```bash
chmod -R 775 .
```
(Note: This grants the necessary read/write permissions for both your CLI and Web Server to manage the SQLite and backup files locally without friction.)

---

## 📄 License

This project is open-source under the **MIT** license.

