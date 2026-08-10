<?php
/**
 * Time-Travel SQLite Debugger - API Endpoint
 * 
 * Handles snapshot inventory listing, database restoration, WAL handling,
 * snapshot pinning, pre-restore safety snapshots, integrity verification,
 * test write simulation, snapshot download, data diff, and live inspection endpoints.
 */

require_once __DIR__ . '/lang.php';

date_default_timezone_set('Europe/Istanbul');

// Security Configuration: Restrict API access to localhost
$allowOnlyLocalhost = true;

if ($allowOnlyLocalhost) {
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $allowedIps = ['127.0.0.1', '::1', 'localhost'];
    if (!in_array($remoteIp, $allowedIps, true)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'   => false,
            'message'   => 'Access denied. Time-Travel SQLite Debugger is restricted to localhost requests.',
            'remote_ip' => $remoteIp
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

$targetDb  = __DIR__ . '/database.sqlite';
$backupDir = __DIR__ . '/backups';

/**
 * Helper to output standardized JSON response
 */
function sendJsonResponse(bool $success, string $message, array $data = [], int $httpCode = 200): void {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'   => $success,
        'message'   => $message,
        'data'      => $data,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
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
 * Helper to locate snapshot file by identifier (filename, timestamp, or uniqid).
 * Strictly prevents path traversal, glob wildcards, and directory escape.
 */
function findSnapshotFile(string $backupDir, string $identifier): ?string {
    $identifier = trim($identifier);
    if ($identifier === '' || str_contains($identifier, '/') || str_contains($identifier, '\\') || str_contains($identifier, '..')) {
        return null;
    }

    // Sanitize against glob metacharacters (*, ?, [, ])
    $safeId = preg_replace('/[^a-zA-Z0-9._-]/', '', $identifier);
    if (empty($safeId)) return null;

    $backupDirReal = realpath($backupDir);
    if (!$backupDirReal) return null;

    $directPath = "{$backupDirReal}/{$safeId}";
    if (file_exists($directPath)) {
        $real = realpath($directPath);
        if ($real && str_starts_with($real, $backupDirReal . DIRECTORY_SEPARATOR)) {
            return $real;
        }
    }

    // Match by timestamp or uniqid substring safely without raw glob metacharacters
    $matches = glob($backupDirReal . '/*' . globquote($safeId) . '*_database.sqlite');
    if ($matches) {
        foreach ($matches as $match) {
            $real = realpath($match);
            if ($real && str_starts_with($real, $backupDirReal . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }
    }

    return null;
}

/**
 * Escape glob special characters
 */
function globquote(string $string): string {
    return preg_replace('/[   *?\[\]!{} ]/', '\\\\$0', $string);
}

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? 'list';
    $lang   = $_GET['lang']   ?? $_POST['lang']   ?? 'tr';

    $inputRaw = file_get_contents('php://input');
    if ($inputRaw) {
        $jsonData = json_decode($inputRaw, true);
        if (is_array($jsonData)) {
            if (isset($jsonData['action'])) $action = $jsonData['action'];
            if (isset($jsonData['lang']))   $lang   = $jsonData['lang'];
        }
    }

    Lang::init($lang);

    switch ($action) {
        case 'i18n':
            sendJsonResponse(true, 'Translations loaded', [
                'current_lang'        => Lang::getCurrentLang(),
                'available_languages' => Lang::getAvailableLanguages(),
                'translations'        => Lang::getRawTranslations()
            ]);
            break;

        case 'download':
            $identifier = $_GET['identifier'] ?? $_GET['timestamp'] ?? $_POST['identifier'] ?? $_POST['timestamp'] ?? null;
            if (!$identifier) {
                sendJsonResponse(false, t('api.no_timestamp'), [], 400);
            }
            $filePath = findSnapshotFile($backupDir, (string)$identifier);
            if (!$filePath) {
                sendJsonResponse(false, t('api.file_not_found', ['file' => $identifier]), [], 404);
            }

            $downloadName = "snapshot_" . basename($filePath);

            header('Content-Type: application/x-sqlite3');
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;

        case 'list':
            $backups = [];
            
            if (is_dir($backupDir)) {
                $files = glob($backupDir . '/*_database.sqlite');
                if ($files) {
                    foreach ($files as $filePath) {
                        $filename = basename($filePath);
                        // Regex matches {timestamp}_{uniqid}_(pinned|prerestore)?_database.sqlite as well as legacy {timestamp}_database.sqlite
                        if (preg_match('/^([0-9.]+)(?:_([a-zA-Z0-9]{13}))?(_pinned|_prerestore)?_database\.sqlite$/', $filename, $matches)) {
                            $rawTs    = (float)$matches[1];
                            $ts       = (int)$rawTs;
                            $uniqid   = $matches[2] ?? '';
                            $tag      = $matches[3] ?? '';
                            $isPinned = ($tag === '_pinned');
                            $isSafety = ($tag === '_prerestore');
                            $size     = filesize($filePath);

                            $backups[] = [
                                'timestamp'     => $rawTs,
                                'ts_int'        => $ts,
                                'uniqid'        => $uniqid,
                                'filename'      => $filename,
                                'is_pinned'     => $isPinned,
                                'is_safety'     => $isSafety,
                                'datetime'      => date('Y-m-d H:i:s', $ts),
                                'time_formatted'=> date('H:i:s', $ts),
                                'size'          => $size,
                                'size_formatted'=> formatBytes($size),
                                'relative_time' => Lang::getRelativeTime($ts)
                            ];
                        }
                    }
                }
            }

            usort($backups, function ($a, $b) {
                return $a['timestamp'] <=> $b['timestamp'];
            });

            $dbExists = file_exists($targetDb);
            $dbSize   = $dbExists ? filesize($targetDb) : 0;
            if (file_exists($targetDb . '-wal')) $dbSize += filesize($targetDb . '-wal');

            $dbStatus = [
                'exists'         => $dbExists,
                'path'           => basename($targetDb),
                'size'           => $dbSize,
                'size_formatted' => formatBytes($dbSize),
                'last_modified'  => $dbExists ? date('Y-m-d H:i:s', filemtime($targetDb)) : null,
                'relative_mtime' => $dbExists ? Lang::getRelativeTime(filemtime($targetDb)) : null,
                'is_writable'    => $dbExists ? is_writable($targetDb) : (is_writable(__DIR__)),
                'is_wal_mode'    => file_exists($targetDb . '-wal')
            ];

            sendJsonResponse(true, t('api.list_success'), [
                'current_lang'        => Lang::getCurrentLang(),
                'available_languages' => Lang::getAvailableLanguages(),
                'total_backups'       => count($backups),
                'backups'             => $backups,
                'current_db'          => $dbStatus
            ]);
            break;

        case 'toggle_pin':
            $identifier = $_GET['identifier'] ?? $_GET['timestamp'] ?? $_POST['identifier'] ?? $_POST['timestamp'] ?? ($jsonData['identifier'] ?? $jsonData['timestamp'] ?? null);
            if (!$identifier) sendJsonResponse(false, t('api.no_timestamp'), [], 400);

            $oldPath = findSnapshotFile($backupDir, (string)$identifier);
            if (!$oldPath) sendJsonResponse(false, t('api.file_not_found', ['file' => $identifier]), [], 404);

            $filename = basename($oldPath);

            if (str_contains($filename, '_pinned_')) {
                $newFilename = str_replace('_pinned_database.sqlite', '_database.sqlite', $filename);
                $newPath = "{$backupDir}/{$newFilename}";
                rename($oldPath, $newPath);
                $msg = "Snapshot iğnesi kaldırıldı.";
                $pinnedStatus = false;
            } else {
                $newFilename = str_replace('_database.sqlite', '_pinned_database.sqlite', $filename);
                $newPath = "{$backupDir}/{$newFilename}";
                rename($oldPath, $newPath);
                $msg = "Snapshot 📌 olarak iğnelendi.";
                $pinnedStatus = true;
            }

            sendJsonResponse(true, $msg, [
                'identifier'=> $identifier,
                'is_pinned' => $pinnedStatus,
                'filename'  => $newFilename
            ]);
            break;

        case 'diff':
            $identifier = $_GET['identifier'] ?? $_GET['timestamp'] ?? $_POST['identifier'] ?? $_POST['timestamp'] ?? ($jsonData['identifier'] ?? $jsonData['timestamp'] ?? null);
            if (!$identifier) sendJsonResponse(false, t('api.no_timestamp'), [], 400);

            $snapshotDb = findSnapshotFile($backupDir, (string)$identifier);
            if (!$snapshotDb) sendJsonResponse(false, t('api.file_not_found', ['file' => $identifier]), [], 404);

            $diffData = [];

            if (extension_loaded('pdo_sqlite') || (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers()))) {
                try {
                    $pdoCurrent = file_exists($targetDb) ? new PDO("sqlite:" . $targetDb) : null;
                    $pdoSnapshot = new PDO("sqlite:" . $snapshotDb);

                    $tablesSnap = $pdoSnapshot->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);

                    foreach ($tablesSnap as $tbl) {
                        $countSnap = $pdoSnapshot->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
                        $countCurrent = 0;
                        if ($pdoCurrent) {
                            try {
                                $countCurrent = $pdoCurrent->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
                            } catch (Exception $e) { $countCurrent = 0; }
                        }

                        $diffVal = $countCurrent - $countSnap;
                        $diffText = ($diffVal > 0) ? "+{$diffVal} satır (Canlıda)" : (($diffVal < 0) ? "{$diffVal} satır" : "Değişim yok");

                        $diffData[] = [
                            'table'         => $tbl,
                            'snapshot_rows' => $countSnap,
                            'current_rows'  => $countCurrent,
                            'diff_value'    => $diffVal,
                            'diff_text'     => $diffText
                        ];
                    }
                } catch (Exception $e) {
                    $diffData[] = ['table' => 'Bilgi', 'diff_text' => $e->getMessage()];
                }
            } else {
                $diffData[] = [
                    'table' => 'Dosya Boyutu Karşılaştırması',
                    'snapshot_rows' => formatBytes(filesize($snapshotDb)),
                    'current_rows'  => file_exists($targetDb) ? formatBytes(filesize($targetDb)) : '0 B',
                    'diff_text'     => 'PDO SQLite eklentisi olmadan tablo detayı alınamaz.'
                ];
            }

            sendJsonResponse(true, "Diff özeti hazırlandı.", [
                'identifier' => $identifier,
                'filename'   => basename($snapshotDb),
                'diff'       => $diffData
            ]);
            break;

        case 'restore':
            $identifier = $_GET['identifier'] ?? $_GET['timestamp'] ?? $_POST['identifier'] ?? $_POST['timestamp'] ?? ($jsonData['identifier'] ?? $jsonData['timestamp'] ?? null);

            if (!$identifier) {
                sendJsonResponse(false, t('api.no_timestamp'), [], 400);
            }

            $backupFilePath = findSnapshotFile($backupDir, (string)$identifier);

            if (!$backupFilePath || !file_exists($backupFilePath)) {
                sendJsonResponse(false, t('api.file_not_found', ['file' => $identifier]), [], 404);
            }

            $backupFilename = basename($backupFilePath);

            if (file_exists($targetDb) && !is_writable($targetDb)) {
                sendJsonResponse(false, t('api.no_write_perm', ['file' => $targetDb]), [], 403);
            }

            // Phase 2: Automatic Pre-Restore Safety Snapshot
            $safetySnapshotName = null;
            if (file_exists($targetDb) && filesize($targetDb) > 0) {
                checkpointWal($targetDb);
                $safeTs  = sprintf('%.4f', microtime(true));
                $safeUid = uniqid();
                $safetySnapshotName = "{$safeTs}_{$safeUid}_prerestore_database.sqlite";
                $safetyPath = "{$backupDir}/{$safetySnapshotName}";
                safeAtomicCopy($targetDb, $safetyPath);
            }

            // Perform Restore Copy
            if (!safeAtomicCopy($backupFilePath, $targetDb)) {
                $err = error_get_last()['message'] ?? 'Dosya kilitli veya yazma hatası';
                sendJsonResponse(false, t('api.restore_error', ['error' => $err]), [], 500);
            }

            // Clean up any stale active WAL / SHM files so restored main DB is used
            $walTarget = $targetDb . '-wal';
            if (file_exists($walTarget)) @unlink($walTarget);
            $shmTarget = $targetDb . '-shm';
            if (file_exists($shmTarget)) @unlink($shmTarget);

            @touch($targetDb);

            // Phase 2: Post-Restore Integrity Verification
            $integrityCheckResult = 'ok';
            if (extension_loaded('pdo_sqlite') || (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers()))) {
                try {
                    $pdoRestored = new PDO("sqlite:" . $targetDb);
                    $pdoRestored->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $stmt = $pdoRestored->query("PRAGMA integrity_check;");
                    $integrityCheckResult = $stmt ? $stmt->fetchColumn() : 'unknown';
                } catch (Throwable $e) {
                    $integrityCheckResult = 'error: ' . $e->getMessage();
                }
            }

            sendJsonResponse(true, t('api.restore_success', ['file' => $backupFilename]), [
                'restored_file'     => $backupFilename,
                'safety_snapshot'   => $safetySnapshotName,
                'integrity_check'   => $integrityCheckResult
            ]);
            break;

        case 'test_write':
            $timestamp = date('Y-m-d H:i:s');
            
            if (extension_loaded('pdo_sqlite') || (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers()))) {
                try {
                    $pdo = new PDO("sqlite:" . $targetDb);
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS system_logs (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            event_name TEXT NOT NULL,
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                        )
                    ");

                    $stmt = $pdo->prepare("INSERT INTO system_logs (event_name) VALUES (:event)");
                    $eventName = "Time Machine Test Entry - " . date('H:i:s');
                    $stmt->execute([':event' => $eventName]);
                    $lastId = $pdo->lastInsertId();
                    $count = $pdo->query("SELECT COUNT(*) FROM system_logs")->fetchColumn();

                    sendJsonResponse(true, t('api.test_write_success', ['id' => $lastId]), [
                        'last_inserted_id' => $lastId,
                        'event_name'       => $eventName,
                        'total_records'    => $count
                    ]);
                } catch (PDOException $e) {
                    sendJsonResponse(false, t('api.test_write_error', ['error' => $e->getMessage()]), [], 500);
                }
            } else {
                $dummyContent = "SQLite format 3\0 -- Time Machine Event: " . $timestamp . " -- random: " . rand(1000, 9999) . "\n";
                if (!file_exists($targetDb)) {
                    file_put_contents($targetDb, $dummyContent);
                } else {
                    file_put_contents($targetDb, " -- Event: " . $timestamp . " #" . rand(100,999) . "\n", FILE_APPEND);
                }
                touch($targetDb);

                sendJsonResponse(true, t('api.test_write_fallback', ['time' => $timestamp]), [
                    'mode' => 'fallback_file_write'
                ]);
            }
            break;

        default:
            sendJsonResponse(false, t('api.unknown_action', ['action' => $action]), [], 400);
            break;
    }

} catch (Throwable $e) {
    sendJsonResponse(false, t('api.system_error', ['error' => $e->getMessage()]), [], 500);
}

