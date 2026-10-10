<?php
/**
 * stest-all post-run report (internal; invoked by bin/stest-all)
 *
 *   --rounds=DIR     DIR/0, DIR/1, ...: one directory per round (0 = main run, then --retry rounds), each with
 *                      list    - the files of that round, one per line, in GNU Parallel job order
 *                      results - JSON lines appended by `stest --result-file`
 *                      joblog  - GNU Parallel --joblog (exit codes, runtime of crashed jobs)
 *   --top=DIR        absolute top directory (key of the failed-tests state)
 *   --wall=SECONDS   stest-all elapsed time
 *   --jobs=N         parallel job limit used
 *   --quiet          print the summary only when something failed, on STDERR
 *   --color          colorize the text summary
 *   --json=FILE|-    write the JSON report ("-" = STDOUT, replaces the text summary)
 *   --slowest=N      list the N slowest files (stest process time, not time since suite start)
 *   --update-failed  remember failed files for `stest-all --rerun-failed`
 *   --started=EPOCH  stest-all start time (sets --wall)
 *   --print-failed   print the remembered failed files of --top and exit
 */

include __DIR__ . "/State.inc.php";

use stest\helper\State;

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? true;
    }
}

if (isset($opt['print-failed'])) { // --print-failed --top=DIR: the failed files remembered for DIR
    foreach (State::read('failed')[$opt['top'] ?? ''] ?? [] as $file) {
        echo $file, "\n";
    }
    exit(0);
}

if (isset($opt['started'])) {
    $opt['wall'] = microtime(true) - (float) str_replace(',', '.', $opt['started']);
}

// one round: [file => entry]
$readRound = function (string $dir): array {
    $files = array_values(array_filter(explode("\n", (string) @file_get_contents("$dir/list")), 'strlen'));
    $records = [];
    foreach (explode("\n", (string) @file_get_contents("$dir/results")) as $line) {
        if ($line !== '' && is_array($r = json_decode($line, true)) && isset($r['file'])) {
            $records[$r['file']] = $r;
        }
    }
    $jobs = []; // seq => [exit, signal, runtime]
    foreach (array_slice(explode("\n", (string) @file_get_contents("$dir/joblog")), 1) as $line) {
        $c = explode("\t", $line);
        if (count($c) >= 8 && ctype_digit($c[0])) {
            $jobs[(int) $c[0]] = ['exit' => (int) $c[6], 'signal' => (int) $c[7], 'runtime' => (float) trim($c[3])];
        }
    }
    $round = [];
    foreach ($files as $i => $file) {
        $job = $jobs[$i + 1] ?? null;
        if ($job === null) { // never started (GNU Parallel halted)
            $round[$file] = null;
            continue;
        }
        $r = $records[$file] ?? null;
        $e = [
            'file' => $file,
            'status' => $r['status'] ?? 'crash',
            'exit' => $job['exit'],
            'tests' => (int) ($r['tests'] ?? 0),
            'failed' => (int) ($r['failed'] ?? 0),
            'new' => (int) ($r['new'] ?? 0),
            'reformat' => (int) ($r['reformat'] ?? 0),
            // the file's own stest process time; a crashed job falls back to its GNU Parallel runtime
            'duration' => (float) ($r['duration'] ?? $job['runtime']),
        ];
        if ($job['signal']) {
            $e['status'] = 'crash';
            $e['message'] = "killed by signal {$job['signal']}";
        } elseif ($r === null) {
            $e['message'] = "exit {$job['exit']} without a result (PHP fatal error or exit())";
        } elseif ($job['exit'] && in_array($e['status'], ['pass', 'stop'], true)) {
            $e['status'] = 'fail';
        }
        if (isset($r['message']) && !isset($e['message'])) {
            $e['message'] = $r['message'];
        }
        if (!empty($r['details'])) {
            $e['details'] = $r['details'];
        }
        $e['ok'] = $e['exit'] === 0 && $e['status'] !== 'crash';
        $round[$file] = $e;
    }
    return $round;
};

$rounds = [];
for ($n = 0; is_dir(($opt['rounds'] ?? '') . "/$n"); $n++) {
    $rounds[] = $readRound($opt['rounds'] . "/$n");
}
$main = $rounds[0] ?? [];

$sum = ['files' => 0, 'passed' => 0, 'failed' => 0, 'stopped' => 0, 'crashed' => 0, 'skipped' => 0,
        'tests' => 0, 'failed_tests' => 0, 'new' => 0, 'reformat' => 0, 'retried' => 0, 'flaky' => 0];
$out = [];
foreach ($main as $file => $first) {
    if ($first === null) {
        $sum['skipped']++;
        continue;
    }
    // the last attempt decides; the main run's time is the file's duration
    $e = $first;
    $attempts = 1;
    foreach (array_slice($rounds, 1) as $round) {
        if (isset($round[$file])) {
            $e = $round[$file];
            $attempts++;
        }
    }
    $e['duration'] = $first['duration'];
    if ($attempts > 1) {
        $e['attempts'] = $attempts;
        $e['flaky'] = $e['ok'];
        $sum['retried']++;
        $e['ok'] && $sum['flaky']++;
    }
    $sum['files']++;
    $sum['tests'] += $e['tests'];
    $sum['failed_tests'] += $e['failed'];
    $sum['new'] += $e['new'];
    $sum['reformat'] += $e['reformat'];
    if ($e['status'] === 'crash') {
        $sum['crashed']++;
    } elseif (!$e['ok']) {
        $sum['failed']++;
    } elseif ($e['status'] === 'stop') {
        $sum['stopped']++;
    } else {
        $sum['passed']++;
    }
    $out[] = $e;
}
$bad = array_values(array_filter($out, fn($e) => !$e['ok']));

$slowest = [];
if (isset($opt['slowest'])) {
    $slowest = $out;
    usort($slowest, fn($a, $b) => $b['duration'] <=> $a['duration'] ?: strcmp($a['file'], $b['file']));
    $slowest = array_slice($slowest, 0, max(1, (int) $opt['slowest']));
}

// --- failed-tests state for --rerun-failed: (previous - files run now) + failed now
if (isset($opt['update-failed']) && isset($opt['top'])) {
    $top = $opt['top'];
    $ran = array_column($out, 'file');
    $now = array_column($bad, 'file');
    $previous = State::read('failed')[$top] ?? [];
    $next = array_values(array_unique(array_merge(array_diff($previous, $ran), $now)));
    sort($next);
    if ($next !== $previous && ($next || $previous)) {
        State::update('failed', function (array $data) use ($top, $next) {
            if ($next) {
                $data[$top] = $next;
            } else {
                unset($data[$top]);
            }
            return $data;
        });
    }
}

// --- JSON
$json = $opt['json'] ?? null;
if ($json !== null) {
    $report = [
        'version' => trim((string) @shell_exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg(dirname(__DIR__) . "/bin/stest") . " --version 2>/dev/null")),
        'top' => $opt['top'] ?? null,
        'duration' => round((float) ($opt['wall'] ?? 0), 3),
        'jobs' => (int) ($opt['jobs'] ?? 0),
        'ok' => !$bad,
        'summary' => $sum,
        'files' => array_map(function ($e) {
            unset($e['ok']);
            return $e;
        }, $out),
    ];
    if ($slowest) {
        $report['slowest'] = array_map(fn($e) => ['file' => $e['file'], 'duration' => $e['duration']], $slowest);
    }
    $s = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
    if ($json === '-' || $json === true) {
        echo $s;
        exit(min(101, count($bad))); // STDOUT carries only the JSON document
    }
    if (@file_put_contents($json, $s) === false) {
        fwrite(STDERR, "stest-all: unable to write --json=$json\n");
    }
}

// --- text summary
$color = isset($opt['color']);
$c = function (string $code, string $s) use ($color) {
    return $color ? "\033[{$code}m$s\033[0m" : $s;
};
$quiet = isset($opt['quiet']);
if (!$quiet || $bad) {
    $t = "--- stest-all: {$sum['files']} files, {$sum['tests']} tests, " . sprintf("%.2fs", (float) ($opt['wall'] ?? 0));
    if (!empty($opt['jobs'])) {
        $t .= " (-j {$opt['jobs']})";
    }
    $parts = ["passed {$sum['passed']}"];
    $sum['failed'] && $parts[] = $c('1;31', "failed {$sum['failed']}");
    $sum['crashed'] && $parts[] = $c('1;31', "crashed {$sum['crashed']}");
    $sum['stopped'] && $parts[] = "stopped {$sum['stopped']}";
    $sum['skipped'] && $parts[] = "not run {$sum['skipped']}";
    $extra = [];
    $sum['failed_tests'] && $extra[] = "failed tests {$sum['failed_tests']}";
    $sum['new'] && $extra[] = "new {$sum['new']}";
    $sum['reformat'] && $extra[] = "reformat {$sum['reformat']}";
    $sum['retried'] && $extra[] = $c('33', "retried {$sum['retried']} files, {$sum['flaky']} passed on retry");
    $t .= "\n    " . implode(", ", $parts) . ($extra ? " | " . implode(", ", $extra) : "") . "\n";
    foreach ($bad as $e) {
        $label = strtoupper($e['status'] === 'pass' || $e['status'] === 'stop' ? 'fail' : $e['status']);
        $message = isset($e['message']) ? mb_strimwidth(strtok($e['message'], "\n"), 0, 100, "...") : "";
        $why = $e['failed'] ? "{$e['failed']} of {$e['tests']} tests" . ($message !== "" ? ": $message" : "") : ($message !== "" ? $message : "exit {$e['exit']}");
        $t .= "    " . $c('1;31', str_pad($label, 5)) . " {$e['file']}  $why\n";
    }
    foreach ($out as $e) {
        if (!empty($e['flaky'])) {
            $t .= "    " . $c('33', "FLAKY") . " {$e['file']}  passed on attempt {$e['attempts']}\n";
        }
    }
    fwrite($quiet ? STDERR : STDOUT, $t);
}
if ($slowest) {
    $t = "--- slowest " . count($slowest) . " (stest process time per file)\n";
    foreach ($slowest as $e) {
        $t .= sprintf("    %8.2fs  %s\n", $e['duration'], $e['file']);
    }
    echo $t;
}

// GNU Parallel convention: number of failed files, 101 = more than 100
exit(min(101, count($bad)));
