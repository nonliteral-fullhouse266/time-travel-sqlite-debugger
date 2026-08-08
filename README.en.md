# ⏱️ Time-Travel SQLite Debugger (Database Time Machine)

An open-source, ultra-lightweight web application and CLI daemon designed to track and instantly rewind a corrupted or modified **SQLite** database file back to any previous state (e.g. 3 minutes ago) during local development — just like scrubbing a video.

> 🇹🇷 **Türkçe Dokümantasyon:** Türkçe rehber için lütfen [README.md](README.md) dosyasına bakın.

---

## 🚀 Features

- **⚡ Zero Dependencies:** Built with pure PHP 8+ and Vanilla JS. Requires no heavy frameworks or npm packages.
- **🌐 Full i18n / Multi-Language:** Translation files are completely decoupled in `lang/`. Switch languages dynamically from the UI (e.g., 🇹🇷 Turkish, 🇬🇧 English). Easily contribute by adding or updating `lang/xx.json` files.
- **💾 SQLite WAL Mode Aware:** Seamlessly backs up and restores `.sqlite-wal` and `.sqlite-shm` files for databases running `PRAGMA journal_mode=WAL;`.
- **🔍 Visual Data & Table Diff Viewer:** Compare row counts and table changes (`+3 rows in live`) between any historic snapshot and your current live database before restoring.
- **📌 Snapshot Pinning & Tagging:** Pin important snapshots with one click to protect them from auto-cleanup.
- **📥 One-Click Snapshot Export:** Download any historic database state directly as a `.sqlite` file.
- **⌨️ Keyboard Navigation Shortcuts:** Navigate through the timeline smoothly using **Left Arrow (⬅️)** and **Right Arrow (➡️)** keys.
- **🔄 Automatic Instant Snapshots (Watcher Daemon):** Background CLI script (`watcher.php`) listens for modifications in `database.sqlite` and automatically creates timestamped snapshots (`backups/1715000000_database.sqlite`).
- **🧹 Automatic Cleanup:** Maintains up to **50 backups**, purging older unpinned snapshots to prevent disk clutter.

---

## 📁 Project Layout

```
time-travel/
├── index.html        # Chrono Console UI (Tailwind CSS + Vanilla JS + i18n Engine)
├── api.php           # Snapshot listing, restoration, diff, export, and i18n API
├── watcher.php       # CLI background listener daemon script (WAL & signal aware)
├── lang.php          # Multi-language (i18n) helper class
├── lang/             # JSON language files directory
│   ├── tr.json       # Turkish language file
│   └── en.json       # English language file
├── database.sqlite   # Target SQLite database file (created automatically)
├── backups/          # Timestamped backups directory (created automatically)
├── README.md         # Turkish documentation
└── README.en.md      # English documentation
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
   `[+] Created snapshot: 1715000000_database.sqlite`
4. Drag the timeline slider or use **⬅️ / ➡️** arrow keys.
5. Click **"🔍 Compare Diff"** to inspect table row changes.
6. Click **"⚡ Restore To This State"** to rewind your database!

---

## 🔒 File Permissions (Linux / Unix)

```bash
chmod -R 775 .
```

---

## 📄 License

This project is open-source under the **MIT** license.
