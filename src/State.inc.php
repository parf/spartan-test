<?php

namespace stest\helper;

/**
 * Persistent per-user state: ${XDG_CONFIG_HOME:-~/.config}/stest/NAME.json
 *
 *   once.json   - realpath(test file) => ['hash' => sha1 of the file after its last pass, 'passed' => unix time]
 *   failed.json - realpath(stest-all top directory) => [failed test paths, relative to it]
 *
 * Many stest processes write concurrently (stest-all), so every update is a
 * locked read-modify-write followed by an atomic rename.
 */
class State {

    static function dir(): string {
        $base = getenv('XDG_CONFIG_HOME') ?: ((getenv('HOME') ?: sys_get_temp_dir()) . '/.config');
        return "$base/stest";
    }

    static function read(string $name): array {
        // stest's error handler sees warnings even under "@": test for the file first
        $file = self::dir() . "/$name.json";
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data) ? $data : [];
    }

    // $fn(array $data): array - returns the new content; false when the state cannot be written
    static function update(string $name, callable $fn): bool {
        // failures are reported below; keep PHP warnings out of stest's per-test error capture
        set_error_handler(fn() => true);
        try {
            return self::_update($name, $fn);
        } finally {
            restore_error_handler();
        }
    }

    private static function _update(string $name, callable $fn): bool {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            fwrite(STDERR, "stest: unable to create state directory $dir\n");
            return false;
        }
        $lock = @fopen("$dir/$name.lock", 'c');
        if ($lock === false) {
            fwrite(STDERR, "stest: unable to lock $dir/$name.json\n");
            return false;
        }
        flock($lock, LOCK_EX);
        try {
            $data = $fn(self::read($name));
            $tmp = @tempnam($dir, ".$name.");
            $ok = $tmp !== false
                && @file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") !== false
                && @rename($tmp, "$dir/$name.json");
            if (!$ok) {
                $tmp && @unlink($tmp);
                fwrite(STDERR, "stest: unable to write $dir/$name.json\n");
            }
            return $ok;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * "1day", "2days", "12h", "30min", "1week", "daily", "weekly", "hourly", "3600" (seconds) => seconds | null
     */
    static function seconds(string $period): ?int {
        $named = ['hourly' => 3600, 'daily' => 86400, 'weekly' => 604800];
        if (isset($named[$period])) {
            return $named[$period];
        }
        if (!preg_match('/^([1-9][0-9]{0,6})\s*(s|sec|m|min|h|hour|d|day|w|week|)s?$/', $period, $m)) {
            return null;
        }
        $unit = ['' => 1, 's' => 1, 'sec' => 1, 'm' => 60, 'min' => 60, 'h' => 3600, 'hour' => 3600,
                 'd' => 86400, 'day' => 86400, 'w' => 604800, 'week' => 604800][$m[2]];
        return (int) $m[1] * $unit;
    }

    // "3h 12m" style age for messages
    static function ago(int $seconds): string {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . "m";
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . "h " . intdiv($seconds % 3600, 60) . "m";
        }
        return intdiv($seconds, 86400) . "d " . intdiv($seconds % 86400, 3600) . "h";
    }
}
