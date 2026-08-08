<?php
/**
 * Time-Travel SQLite Debugger - Language / i18n Helper
 */

class Lang {
    private static array $translations = [];
    private static string $currentLang = 'tr';
    private static string $langDir = __DIR__ . '/lang';

    /**
     * Initialize language setup
     */
    public static function init(string $langCode = 'tr'): void {
        self::$currentLang = self::sanitizeCode($langCode);
        
        $filePath = self::$langDir . '/' . self::$currentLang . '.json';
        if (!file_exists($filePath)) {
            // Fallback to English, then Turkish
            if (file_exists(self::$langDir . '/en.json')) {
                self::$currentLang = 'en';
                $filePath = self::$langDir . '/en.json';
            } elseif (file_exists(self::$langDir . '/tr.json')) {
                self::$currentLang = 'tr';
                $filePath = self::$langDir . '/tr.json';
            }
        }

        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            self::$translations = json_decode($content, true) ?? [];
        }
    }

    /**
     * Get translated text by dot-notated key (e.g., 'api.list_success')
     */
    public static function get(string $key, array $replace = []): string {
        $parts = explode('.', $key);
        $value = self::$translations;

        foreach ($parts as $part) {
            if (is_array($value) && isset($value[$part])) {
                $value = $value[$part];
            } else {
                return $key; // Return key as fallback if missing
            }
        }

        if (!is_string($value)) {
            return $key;
        }

        foreach ($replace as $var => $val) {
            $value = str_replace('{' . $var . '}', (string)$val, $value);
        }

        return $value;
    }

    /**
     * Get relative time string using current language
     */
    public static function getRelativeTime(int $timestamp): string {
        $diff = time() - $timestamp;

        if ($diff < 2) {
            return self::get('api.relative_time.just_now');
        }
        if ($diff < 60) {
            return self::get('api.relative_time.seconds_ago', ['seconds' => $diff]);
        }
        if ($diff < 3600) {
            $mins = floor($diff / 60);
            $secs = $diff % 60;
            return self::get('api.relative_time.minutes_ago', ['minutes' => $mins, 'seconds' => $secs]);
        }
        if ($diff < 86400) {
            $hours = floor($diff / 3600);
            $mins = floor(($diff % 3600) / 60);
            return self::get('api.relative_time.hours_ago', ['hours' => $hours, 'minutes' => $mins]);
        }
        $days = floor($diff / 86400);
        return self::get('api.relative_time.days_ago', ['days' => $days]);
    }

    /**
     * Get list of available language files
     */
    public static function getAvailableLanguages(): array {
        $languages = [];
        $files = glob(self::$langDir . '/*.json');

        if ($files) {
            foreach ($files as $file) {
                $content = file_get_contents($file);
                $json = json_decode($content, true);
                if ($json && isset($json['code'], $json['name'], $json['flag'])) {
                    $languages[] = [
                        'code' => $json['code'],
                        'name' => $json['name'],
                        'flag' => $json['flag']
                    ];
                }
            }
        }

        return $languages;
    }

    public static function getCurrentLang(): string {
        return self::$currentLang;
    }

    public static function getRawTranslations(): array {
        return self::$translations;
    }

    private static function sanitizeCode(string $code): string {
        return preg_replace('/[^a-z0-9_-]/i', '', strtolower($code));
    }
}

/**
 * Global helper function for shorthand translation
 */
function t(string $key, array $replace = []): string {
    return Lang::get($key, $replace);
}
