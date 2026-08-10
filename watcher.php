<?php
/**
 * Time-Travel SQLite Debugger - CLI Watcher
 * 
 * Continuous background listener (daemon) for detecting modifications
 * in target SQLite database file and generating microtime+uniqid snapshots.
 * Flushes WAL via PRAGMA wal_checkpoint(TRUNCATE) before backing up.
 * Enforces maximum count (50) and total storage size (1GB) limits.
 * 
 * Usage: php watcher.php [path/to/database.sqlite] [lang_code]
 * Example: php watcher.php database.sqlite en
 */

require_once __DIR__ . '/lang.php';

date_default_timezone_set('Europe/Istanbul');

// Ensure CLI execution
if (php_sapi_name() !== 'cli') {
    die("This script must be executed from the command line (CLI).\nUsage: php watcher.php [database_path] [lang_code]\n");
}

// Configuration
$targetDb   = $argv[1] ?? 'database.sqlite';
$langCode   = $argv[2] ?? 'tr';
$backupDir  = __DIR__ . '/backups';
$maxBackups = 50;
$maxStorageBytes = 1 * 1024 * 1024 * 1024; // 1 GB storage limit
$checkIntervalSeconds = 1;

// Initialize language system
Lang::init($langCode);

// ANSI Color helper functions for clean terminal output
function cliLog(string $message, string $type = 'info'): void {
    $timestamp = date('Y-m-d H:i:s');
    $colors = [
        'info'    => "\033[36m", // Cyan
        'success' => "\033[32m", // Green
        'warn'    => "\033[33m", // Yellow
        'error'   => "\033[31m", // Red
        'reset'   => "\033[0m"   // Reset
    ];
    $color = $colors[$type] ?? $colors['info'];
    $reset = $colors['reset'];
    
    echo "[{$timestamp}] {$color}{$message}{$reset}\n";
}

/**
 * Convert bytes into human-readable string
 */
function formatBytes(int $bytes, int $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Shutdown hook for graceful termination
register_shutdown_function(function() {
    cliLog(t('cli.watcher_stopped'), 'warn');
});

// Ensure backup directory exists
if (!is_dir($backupDir)) {
    if (!mkdir($backupDir, 0755, true)) {
        cliLog(t('cli.failed_create_dir', ['dir' => $backupDir]), 'error');
        exit(1);
    }
    cliLog(t('cli.created_dir', ['dir' => $backupDir]), 'success');
}

cliLog("==========================================", 'info');
cliLog(" " . t('cli.watcher_started'), 'success');
cliLog(" " . t('cli.monitoring_db') . " : {$targetDb} (WAL Checkpoint & Microtime Unique ID)", 'info');
cliLog(" " . t('cli.backup_dir') . "    : {$backupDir}", 'info');
cliLog(" " . t('cli.max_backups') . "   : {$maxBackups}", 'info');
cliLog(" Max Storage Limit : " . formatBytes($maxStorageBytes), 'info');
cliLog(" " . t('cli.interval') . "      : {$checkIntervalSeconds}s", 'info');
cliLog("==========================================", 'info');

$lastMtime = 0;
$lastSize  = 0;
$walDb     = $targetDb . '-wal';
$lastWalMtime = 0;

while (true) {
    clearstatcache(true, $targetDb);
    if (file_exists($walDb)) clearstatcache(true, $walDb);

    if (!file_exists($targetDb)) {
        cliLog(t('cli.waiting_db', ['db' => $targetDb]), 'warn');
        sleep($checkIntervalSeconds);
        continue;
    }

    $currentMtime = filemtime($targetDb);
    $currentSize  = filesize($targetDb);
    $currentWalMtime = file_exists($walDb) ? filemtime($walDb) : 0;

    // Initial check baseline
    if ($lastMtime === 0) {
        $lastMtime = $currentMtime;
        $lastSize  = $currentSize;
        $lastWalMtime = $currentWalMtime;
        cliLog(t('cli.baseline_set', ['db' => $targetDb, 'size' => $currentSize]), 'info');
        
        $existingBackups = glob($backupDir . '/*_database.sqlite');
        if (empty($existingBackups)) {
            createBackup($targetDb, $backupDir, $maxBackups, $maxStorageBytes);
        }
    } 
    // Detect modification by main DB or WAL file change
    elseif ($currentMtime !== $lastMtime || $currentSize !== $lastSize || $currentWalMtime !== $lastWalMtime) {
        cliLog(t('cli.mod_detected'), 'warn');
        createBackup($targetDb, $backupDir, $maxBackups, $maxStorageBytes);

        $lastMtime    = filemtime($targetDb);
        $lastSize     = filesize($targetDb);
        $lastWalMtime = file_exists($walDb) ? filemtime($walDb) : 0;
    }

    sleep($checkIntervalSeconds);
}

/**
 * Perform thread-safe atomic copy with retry backoff
 */
function safeAtomicCopy(string $src, string $dst): bool {
    $tmp = "{$dst}.tmp";
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        if (@copy($src, $tmp) && @rename($tmp, $dst)) {
            return true;
        }
        if (file_exists($tmp)) @unlink($tmp);
        usleep(50000); // 50ms backoff
    }
    return false;
}

/**
 * Executes PRAGMA wal_checkpoint(TRUNCATE) via PDO to flush all WAL frames into main database file.
 */
function checkpointWal(string $dbPath): bool {
    if (!file_exists($dbPath) || filesize($dbPath) === 0) return false;
    if (extension_loaded('pdo_sqlite') || (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers()))) {
        try {
            $pdo = new PDO("sqlite:" . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("PRAGMA wal_checkpoint(TRUNCATE);");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
    return false;
}

/**
 * Creates microtime+uniqid snapshot after performing WAL checkpoint.
 */
function createBackup(string $targetDb, string $backupDir, int $maxBackups, int $maxStorageBytes): void {
    checkpointWal($targetDb);

    $ts = sprintf('%.4f', microtime(true));
    $uniqid = uniqid();
    $backupFilename = "{$ts}_{$uniqid}_database.sqlite";
    $backupPath = "{$backupDir}/{$backupFilename}";

    if (safeAtomicCopy($targetDb, $backupPath)) {
        cliLog(t('cli.created_snapshot', [
            'file' => $backupFilename,
            'size' => filesize($backupPath)
        ]), 'success');
        enforceStorageLimits($backupDir, $maxBackups, $maxStorageBytes);
    } else {
        $error = error_get_last()['message'] ?? 'Dosya kilitli veya erişilemiyor';
        cliLog(t('cli.failed_snapshot', ['error' => $error]), 'warn');
    }
}

/**
 * Ensures backup directory respects maximum snapshot count (50) and storage limit (1GB).
 */
function enforceStorageLimits(string $backupDir, int $maxBackups, int $maxStorageBytes): void {
    $backups = glob($backupDir . '/*_database.sqlite');
    if (!$backups) return;

    $unpinned = [];
    foreach ($backups as $filePath) {
        $filename = basename($filePath);
        if (str_contains($filename, '_pinned_')) {
            continue;
        }
        $mtime = filemtime($filePath);
        $size = filesize($filePath);
        
        $unpinned[] = [
            'path'     => $filePath,
            'filename' => $filename,
            'mtime'    => $mtime,
            'size'     => $size
        ];
    }

    // Sort by modification time ascending (oldest first)
    usort($unpinned, function ($a, $b) {
        return $a['mtime'] <=> $b['mtime'];
    });

    $totalCount = count($unpinned);
    $totalSize  = array_sum(array_column($unpinned, 'size'));

    while (!empty($unpinned) && ($totalCount > $maxBackups || $totalSize > $maxStorageBytes)) {
        $oldest   = array_shift($unpinned);
        $filePath = $oldest['path'];

        if (@unlink($filePath)) {
            // Also clean legacy wal/shm if present
            $tsPrefix = preg_replace('/_database\.sqlite$/', '', $filePath);
            @unlink("{$tsPrefix}_database.sqlite-wal");
            @unlink("{$tsPrefix}_database.sqlite-shm");

            cliLog(t('cli.purged_old', ['file' => $oldest['filename']]), 'info');
            $totalCount--;
            $totalSize -= $oldest['size'];
        } else {
            cliLog(t('cli.failed_purge', ['file' => $oldest['filename']]), 'error');
            break; // prevent infinite loop if file is locked
        }
    }
}

