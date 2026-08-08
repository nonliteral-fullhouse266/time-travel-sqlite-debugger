<?php
/**
 * Time-Travel SQLite Debugger - CLI Watcher
 * 
 * Continuous background listener (daemon) for detecting modifications
 * in target SQLite database file (including WAL files) and generating
 * timestamped backups. Supports pinned snapshots, atomic writes, and retry backoffs.
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
cliLog(" " . t('cli.monitoring_db') . " : {$targetDb} (WAL & Atomic Retry Aware)", 'info');
cliLog(" " . t('cli.backup_dir') . "    : {$backupDir}", 'info');
cliLog(" " . t('cli.max_backups') . "   : {$maxBackups}", 'info');
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
            createBackup($targetDb, $backupDir, $maxBackups);
        }
    } 
    // Detect modification by main DB or WAL file change
    elseif ($currentMtime !== $lastMtime || $currentSize !== $lastSize || $currentWalMtime !== $lastWalMtime) {
        cliLog(t('cli.mod_detected'), 'warn');
        createBackup($targetDb, $backupDir, $maxBackups);

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
 * Creates timestamped backup using atomic file rename with retry backoff.
 */
function createBackup(string $targetDb, string $backupDir, int $maxBackups): void {
    $timestamp = time();
    $backupFilename = "{$timestamp}_database.sqlite";
    $backupPath = "{$backupDir}/{$backupFilename}";

    if (safeAtomicCopy($targetDb, $backupPath)) {
        $wal = $targetDb . '-wal';
        $shm = $targetDb . '-shm';
        if (file_exists($wal)) safeAtomicCopy($wal, "{$backupDir}/{$timestamp}_database.sqlite-wal");
        if (file_exists($shm)) safeAtomicCopy($shm, "{$backupDir}/{$timestamp}_database.sqlite-shm");

        cliLog(t('cli.created_snapshot', [
            'file' => $backupFilename,
            'size' => filesize($backupPath)
        ]), 'success');
        enforceMaxBackups($backupDir, $maxBackups);
    } else {
        $error = error_get_last()['message'] ?? 'Dosya kilitli veya erişilemiyor';
        cliLog(t('cli.failed_snapshot', ['error' => $error]), 'warn');
    }
}

/**
 * Ensures directory holds at most $maxBackups unpinned snapshots.
 */
function enforceMaxBackups(string $backupDir, int $maxBackups): void {
    $backups = glob($backupDir . '/*_database.sqlite');
    if (!$backups) return;

    $backupMap = [];
    foreach ($backups as $filePath) {
        $filename = basename($filePath);
        if (str_contains($filename, '_pinned_')) {
            continue;
        }
        if (preg_match('/^(\d+)_database\.sqlite$/', $filename, $matches)) {
            $backupMap[(int)$matches[1]] = $filePath;
        }
    }

    ksort($backupMap);

    $total = count($backupMap);
    if ($total > $maxBackups) {
        $toDeleteCount = $total - $maxBackups;
        $deleted = 0;

        foreach ($backupMap as $timestamp => $filePath) {
            if ($deleted >= $toDeleteCount) break;
            
            if (@unlink($filePath)) {
                @unlink("{$backupDir}/{$timestamp}_database.sqlite-wal");
                @unlink("{$backupDir}/{$timestamp}_database.sqlite-shm");

                cliLog(t('cli.purged_old', ['file' => basename($filePath)]), 'info');
                $deleted++;
            } else {
                cliLog(t('cli.failed_purge', ['file' => basename($filePath)]), 'error');
            }
        }
    }
}
