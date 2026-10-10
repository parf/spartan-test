<?php

namespace stest\helper;

use function stest\i;

/**
 * stest --watch PATH...
 *
 * Re-runs a .stest file each time it is saved. PATH may be a file or a directory
 * (watched recursively). Explicit files run once at start.
 *
 * stest itself rewrites test files (new results, reformatting, ‼️ markers); those
 * writes must not trigger another run. After each run the watcher remembers the
 * content hash the file is expected to have: the hash after the run when stest
 * saved the file (reported through --result-file), otherwise the hash the run
 * started with. An event whose current content still has that hash is ignored,
 * so stest's own saves never loop, while an edit made during a run still re-runs.
 */
class Watcher {

    const DEBOUNCE = 0.15; // seconds without events before a burst is processed

    static function run(array $argv, array $paths): int {
        if (!self::which('inotifywait')) {
            fwrite(STDERR, "stest --watch requires inotifywait (install inotify-tools)\n");
            return 1;
        }
        $paths = $paths ?: ['.'];
        $dirs = $files = [];
        foreach ($paths as $p) {
            $real = realpath($p);
            if ($real === false) {
                fwrite(STDERR, "stest --watch: no such file or directory: $p\n");
                return 1;
            }
            if (is_dir($real)) {
                $dirs[$real] = 1;
            } else {
                $files[$real] = 1;
            }
        }

        $stest = self::stestCommand($argv);
        $procs = [];
        $exclude = '/(vendor|node_modules|\.git)(/|$)';
        if ($dirs) {
            $procs[] = self::spawn(array_merge(
                ['inotifywait', '-m', '-q', '-r', '-e', 'close_write', '-e', 'moved_to', '--format', '%w%f', '--exclude', $exclude],
                array_keys($dirs)
            ));
        }
        if ($files) {
            $parents = array_unique(array_map('dirname', array_keys($files)));
            $procs[] = self::spawn(array_merge(
                ['inotifywait', '-m', '-q', '-e', 'close_write', '-e', 'moved_to', '--format', '%w%f'],
                $parents
            ));
        }
        $pipes = array_map(fn($p) => $p[1], $procs);

        $known = []; // path => content hash stest is expected to leave
        foreach (array_keys($files) as $file) {
            self::runFile($stest, $file, $known);
        }
        self::waiting($paths);

        $buffer = array_fill(0, count($pipes), "");
        $pending = [];
        $last = 0.0;
        while (true) {
            $read = $pipes;
            $write = $except = null;
            $timeout = $pending ? self::DEBOUNCE : null;
            $n = @stream_select($read, $write, $except, $timeout === null ? null : 0, $timeout === null ? 0 : (int) ($timeout * 1e6));
            if ($n === false) {
                return 1;
            }
            foreach ($read as $pipe) {
                $k = array_search($pipe, $pipes, true);
                $chunk = fread($pipe, 65536);
                if ($chunk === '' || $chunk === false) {
                    if (feof($pipe)) {
                        fwrite(STDERR, "stest --watch: inotifywait exited\n");
                        return 1;
                    }
                    continue;
                }
                $buffer[$k] .= $chunk;
                while (($nl = strpos($buffer[$k], "\n")) !== false) {
                    $path = substr($buffer[$k], 0, $nl);
                    $buffer[$k] = substr($buffer[$k], $nl + 1);
                    if (self::wanted($path, $dirs, $files)) {
                        $pending[$path] = 1;
                        $last = microtime(true);
                    }
                }
            }
            if ($pending && microtime(true) - $last >= self::DEBOUNCE) {
                $ran = 0;
                foreach (array_keys($pending) as $path) {
                    if (is_file($path) && sha1_file($path) !== ($known[$path] ?? null)) {
                        self::runFile($stest, $path, $known);
                        $ran++;
                    }
                }
                $pending = [];
                $ran && self::waiting($paths);
            }
        }
    }

    // is this event path a test file we watch?
    static function wanted(string $path, array $dirs, array $files): bool {
        if (isset($files[$path])) {
            return true;
        }
        if (!str_ends_with($path, '.stest')) {
            return false;
        }
        foreach (array_keys($dirs) as $dir) {
            if (str_starts_with($path, $dir . '/')) {
                $rel = substr($path, strlen($dir) + 1);
                // hidden files/dirs (stest's own ".name.stest.XXXX" temp files), vendor, node_modules
                return !preg_match('!(^|/)(\.|vendor/|node_modules/)!', $rel);
            }
        }
        return false;
    }

    // run one file in a child stest and remember the hash it should have afterwards
    static function runFile(array $stest, string $path, array &$known): void {
        $before = sha1_file($path);
        $result = tempnam(sys_get_temp_dir(), 'stest-watch.');
        i('out')->e("{bold}{blue}--- %s{/} %s\n", date('H:i:s'), self::display($path));
        $p = proc_open(array_merge($stest, ["--result-file=$result", $path]), [STDIN, STDOUT, STDERR], $pipes);
        is_resource($p) && proc_close($p);
        $record = json_decode((string) @file_get_contents($result), true);
        @unlink($result);
        $known[$path] = ($record['saved'] ?? false) && is_file($path) ? sha1_file($path) : $before;
    }

    static function waiting(array $paths): void {
        i('out')->e("{grey}watching %s (Ctrl-C to stop){/}\n", implode(' ', $paths));
    }

    // child command: same PHP and stest launcher, the user's options without --watch / --result-file
    static function stestCommand(array $argv): array {
        $bin = realpath($argv[0]) ?: dirname(__DIR__) . '/bin/stest';
        $opts = array_values(array_filter(array_slice($argv, 1), fn($a) =>
            $a !== '' && $a[0] === '-' && $a !== '--watch' && $a !== '--' && !str_starts_with($a, '--result-file')));
        return array_merge([PHP_BINARY, $bin], $opts);
    }

    static function spawn(array $cmd): array {
        $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($p)) {
            fwrite(STDERR, "stest --watch: unable to start inotifywait\n");
            exit(1);
        }
        stream_set_blocking($pipes[1], false);
        return [$p, $pipes[1]];
    }

    static function display(string $path): string {
        $cwd = getcwd() . '/';
        return str_starts_with($path, $cwd) ? substr($path, strlen($cwd)) : $path;
    }

    static function which(string $cmd): bool {
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $dir) {
            if ($dir !== '' && is_executable("$dir/$cmd")) {
                return true;
            }
        }
        return false;
    }
}
