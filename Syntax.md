# SPARTAN-TEST SYNTAX
Minimalistic PHP 8.5 Unit Testing Framework / Web Testing Framework

* Spartan Test reads a test file line by line

Each line is one of:
 - a PHP setup statement or block
 - a test expression (or just `test`)
 - a test result
 - a comment

* For every test expression it computes the result and compares it with the stored one
    - the comparison is textual, against the canonical (auto-generated) form
    - if the file has no stored result, the generated result is added to the file
    - if the stored result differs only in formatting or key order (same value), the file
      is re-run in soft-regen mode, which rewrites just those lines to the canonical form
      and saves the file (reported as `reformat: N`). So you may write expected results in
      any formatting you like
    - if the stored result differs in VALUE, the test fails and the file is left unchanged
      (use `stest -g` to overwrite every stored result)
    - `stest -g` exits successfully after regenerating differences, but exits nonzero when
      an input file cannot be read or an updated file cannot be saved
    - `stest --read-only` (`-r`) never rewrites the file: a missing result is a failure
      (nothing is generated), formatting-only differences pass without the soft-regen
      rewrite, and `--generate` / `--save` / `--clean` are rejected. `stest-all --read-only`
      passes the option to every test. Use it in CI or on checkouts that must stay clean.

* Spartan Test captures
    - return values
    - exceptions (any Throwable)
    - STDOUT output (echo, print)
    - PHP notices, warnings, deprecations, and errors
      raised by the test's own code are the test's result (`['error' => 'E_DEPRECATED: ...']`) even
      when the project installs its own error handler; errors raised inside project code still go
      to the project's handler


BASIC SYNTAX
-----------
* A Spartan test is a list of expressions and their results
    - line types:
        + "; php-code" - PHP setup code; executed, no result comparison
        + "test-expression" - PHP code that produces a result
        + "    result" - the stored result of the test expression (valid PHP code)
        + "    ~ matcher" - custom comparison instead of an exact result (see below)
        + "/url-path" - web request (see Web Tests below)
        + "‼️ test-expression" - critical test; execution stops if it fails. Type it as
          "!! test-expression": stest rewrites "!!" to ‼️ when it first runs the file
          (not with --read-only). A single "!" is ordinary PHP negation, not a marker
        + "? expression" - inspect a class or variable: class name, parent class, and file location


Sample spartan test:
```
#!/bin/env stest
/*
  first line makes test an executable script
*/
# math test
2*2;    # tests are not indented
    4;  # results are indented by 4 spaces; a missing result is generated on the first run

/* lines starting with ";" are PHP setup code */
; $x = M_PI / 6;
sin($x) < 2;
    true;
range(3,4);
    [3, 4];
```

### PHP setup code and multi-line blocks

Setup code runs before or between tests without producing an expected-result entry.
Its first physical line must start with `;` in column one. This prefix is stest syntax,
not a replacement for PHP's terminating semicolon.

Simple setup statements stay on one line:

```php
; $timeout = 5;
; require_once __DIR__ . '/fixture.php';
```

For a multi-line statement or block, put `;` only on its first line. Spartan Test
accumulates source until PHP reports that the statement is syntactically complete, so
normal PHP indentation and zero-indented closing braces are supported. Exact formatting
is preserved when the test file is saved.

Supported multi-line forms include:

- assignments using closures, anonymous classes, arrays, calls, heredoc, and nowdoc;
- named `function` declarations;
- named `class` declarations, including methods and nested blocks;
- compound statements that remain syntactically incomplete until their closing clause,
  including loops and `do`/`while`;
- `if`/`elseif`/`else`, including `elseif`, `else if`, and `else` starting on their own
  unindented lines;
- `try`/`catch`, `try`/`finally`, and `try`/`catch`/`finally`. `catch` and `finally` may
  start on their own unindented lines.

```php
; $rent = function (array $data) use ($T) {
    return $T->Model($data);
};

; function normalizeRent(array $data): array {
    return array_filter($data);
}

; class RentFixture {
    public array $data = [];
}

; if ($environment === 'prod') {
    loadProductionFixture();
}
else {
    loadDevelopmentFixture();
}

; try {
    loadFixture();
} catch (RuntimeException $error) {
    recoverFixture($error);
} finally {
    closeFixture();
}
```

Rules and boundaries:

- The `;` prefix is mandatory for functions and classes. A bare `function` or `class`
  line is parsed as a test expression, not setup code.
- Each new top-level setup statement starts with its own `;`. Continuation lines do not.
- A missing final PHP semicolon is still auto-added when adding it makes the complete
  setup statement valid. Explicit semicolons are recommended for clarity.
- Legacy indented setup continuations remain supported, but indentation is no longer
  required for a PHP fragment that is still syntactically incomplete.
- Named functions and classes are evaluated once. A formatting-only soft-regeneration
  pass skips their declarations to prevent `Cannot redeclare` errors.
- PHP function and class names remain process-global. If one `stest` command receives
  several files, declarations across those files must use unique names or namespaces.
- Closures, anonymous classes, assignments, and `try` blocks are setup expressions and
  run again during soft-regeneration so their local variables are recreated.
- Official named-declaration support is currently limited to `function` and `class`.
  Do not use `interface`, `trait`, `enum`, or attributed declarations in `.stest` setup
  blocks: they are not yet protected from redeclaration during soft-regeneration.
- Do not add `<?php` inside a setup block. The optional top-level `<?php` line in an
  older `.stest` file is treated as a comment for compatibility.

Multi-line test expressions are different: they still use 1-2 spaces for continuation,
and expected results still use exactly 4 spaces. The PHP-block rules above apply only
to entries whose first character is `;`.

See [multiline-setup.stest](examples/1-basics/multiline-setup.stest) for a runnable
example and [basic.stest](examples/1-basics/basic.stest) for the legacy indentation form.

@see [more complex example](https://github.com/parf/spartan-test/blob/main/examples/1-basics/basic.stest)

### Array Result Sorting
By default, array results are sorted by key, recursively.\
Disable it with `; $ARG['sort'] = 0;` and re-enable it with `; $ARG['sort'] = 1;`

@see (https://github.com/parf/spartan-test/blob/main/examples/2-advanced/result-sorting.stest)

# Advanced Syntax / Advanced Tests

Instead of an exact result you can use one or more matchers

`~`   - result is a non-empty string

`~~`  - result is truthy:   `if (! $result) FAIL();`

`~ "substring"`  - result contains the substring

`~ Class`  - result is an instance of Class or a descendant

`~ []`            - result is an array

`~ [$a, $b, ..]`  - result array contains the VALUES $a and $b

`~ [key => val]`    - result array contains KEY => VALUE

`~ [key => true]`    - result array has KEY (`array_key_exists`; the value may be `0`, `''`, `null`, `false`)

`~ [key => false]`    - result array does NOT have KEY (a present key holding `false` or `null` still fails)

`~ /regexp/x`     - result matches the regular expression

@see [special tests](https://github.com/parf/spartan-test/blob/main/examples/1-basics/special-tests.stest)

## stest-all file tags

Tags select complete files during `stest-all` discovery. They do not select individual
expressions, and direct `stest file.stest` execution does not filter by them.

Declare tags in the first four physical lines of the file. The executable shebang is
line one. Declarations after line four are ordinary comments and are ignored by
`stest-all`.

```text
#!/usr/bin/env stest
# @tag web smoke long
# @require-tag prod staging
```

- `@tag web smoke long` declares normal tags. The file runs normally when `--tag` is omitted.
- `@require-tag prod staging` makes the file opt-in. `prod` or `staging` must be explicitly
  requested as a positive tag.
- Positive selector tags are alternatives: `--tag="prod smoke"` means `prod` OR `smoke`.
- A selector prefixed with `-` excludes matching files: `--tag="prod -long"` selects
  `prod` files except those also tagged `long`.
- Negative selectors never satisfy `@require-tag`. With `--tag=-long`, required-tag
  files remain skipped because no required tag was positively requested.
- `--all` ignores the opt-in rule: files with `@require-tag` run as if one of their tags
  had been requested. It composes with the other filters, e.g. `--all --tag=-long` runs
  every file except those tagged `long`.
- `--list-tags` prints `tag count` for every tag (`@tag` and `@require-tag`) in the selected
  files, most used first; `--list-required` reports only `@require-tag` tags. Both include
  opt-in files and honor `-x`, `-u`, `--recent`, `--new`, and `--tag`. A file counts once
  per tag.
- Special-use tags `run-once(PERIOD)` and `fail-once(PERIOD)` (bare = `1day`) are written in
  `@tag` / `@require-tag` lines like any tag: `# @tag web run-once(1day)`. `stest` and
  `stest-all` apply them as `STest::runOnce(PERIOD)` / `STest::failOnce(PERIOD)` (see
  Built-in STest Methods); `stest-all` lists and selects them by name: `--tag=run-once`,
  `--tag=-fail-once`, and `--list-tags` shows `run-once 3`. An invalid period fails the file.
- Repeated `--tag` options and comma-separated values are merged using the same rules.
- Executable and non-executable `.stest` files are included by default. Use
  `-x` or `--executable` to select only files with the executable bit set.
- A positive-only tag query with no matches exits nonzero. If negative tags intentionally
  exclude every positive match, the empty result remains successful.
- Both discovery backends skip hidden files, `vendor`, and `node_modules` by default.
  When installed, `fd` additionally honors `.gitignore`, `.ignore`, and `.fdignore`.
  Use `-u` or `--unrestricted` to include these paths. Systems without `fd` use the
  `find` fallback.

Selection order is optional `--executable` filtering, then `--recent`, tags, `--new`,
and finally execution or `--list`. Examples:

```bash
stest-all --tag=smoke
stest-all --tag="prod -long" --recent=2day
stest-all --list --tag=staging --new=4
stest-all --list --executable --unrestricted
stest-all --all --tag=-long
stest-all --list-required
```

See [tagged-test.stest](examples/1-basics/tagged-test.stest) for file metadata syntax.

## stest-all runs: summary, retry, JSON, state

Every run ends with a summary: files, tests, wall time, and each failed, crashed, or flaky file.

```text
--- stest-all: 120 files, 3400 tests, 41.20s (-j 40)
    passed 115, failed 2, crashed 1, stopped 2 | failed tests 5, retried 3 files, 1 passed on retry
    FAIL  ./api/orders.stest  2 of 30 tests
    CRASH ./api/import.stest  exit 255 without a result (PHP fatal error or exit())
    FLAKY ./web/search.stest  passed on attempt 2
```

- `-q` stays quiet: a passing run prints nothing; a failing run prints the summary on STDERR.
- `--summary` is `-q` that always ends with the summary, on STDOUT, also when everything passed.
- `--retry=N` re-runs the failed files after the whole run, up to N more rounds, so caches the
  suite warmed up are in place. A file that passes on a retry counts as passed (flaky). With
  `-q`, job output is held back and only each file's last attempt is printed, so a recovered
  failure never reaches cron. Without `-q` every round is shown live.
- `--slowest[=10]` lists the slowest files by their own `stest` process time (PHP start-up,
  init, and tests), measured inside each process, never time since the suite started.
- `--json` prints a JSON report on STDOUT and sends test output to STDERR; `--json=FILE` writes
  it to FILE and keeps the normal output. The report holds the summary and every file's
  `status` (`pass`, `fail`, `stop`, `error`, `alert`, `crash`), `exit`, `tests`, `failed`,
  `new`, `reformat`, `duration`, `attempts`/`flaky` after retries, `message`, and failure `details`.
- `--rerun-failed` runs only the files that failed in earlier runs of the same top directory.
  Each run updates that list: files it ran are replaced by their new outcome, others are kept.
  Opt-in `@require-tag` files on the list run without their tag; `--tag`, `--new`, and
  `--shard` still filter.
- `--shard=K/N` runs every N-th file of the final selection, starting with the K-th, to split
  a suite across N CI machines.
- `--once[=PERIOD]`, `--once=reset`, `--once=ignore` are passed to every `stest`.
- A requested tag that no discovered file declares prints
  `stest-all: unknown tag 'smoek'; known tags: ...` on STDERR. An excluded tag nobody declares
  (`--tag=-centerex` in a product without it) is silent: there is nothing to exclude.
- Exit status is the number of files still failing (101 = more than 100), as GNU Parallel reports it.

State lives in `${XDG_CONFIG_HOME:-~/.config}/stest/`: `failed.json` (for `--rerun-failed`),
`once.json` (`runOnce`), `fail-once.json` (`failOnce`).

```bash
stest-all -q --retry=2                 # cron: warm-cache retries, silent unless something still fails
stest-all --json=report.json --slowest
stest-all --rerun-failed
stest-all --shard=2/4 --json > shard-2.json
```

## Watch mode

`stest --watch PATH...` re-runs a test file each time it is saved. PATH may be files or
directories (recursive; hidden files, `vendor`, `node_modules`, `.git` are skipped); listed files
run once at start. Requires `inotifywait` (inotify-tools). Other options, e.g. `-v`, are passed
to every run.

stest rewrites test files itself (new results, reformatting, `‼️`); those writes never trigger
another run. After each run the watcher remembers the content hash the file should have: the
hash stest saved, or the one the run started with. An event whose content still matches is
ignored, while an edit made by hand, even during a run, triggers a new run.

```bash
stest --watch tests/ api.stest
```

### Several matchers may follow one expression

```
\hb\Curl::get("example.com");
    ~ string
    ~ "Example"
    ~ "website"
    ~ /example/i;

```

# Built-in STest Methods

- `STest::domain()` - @see web-tests
- `STest::stop($message)` - intentionally skip the rest of the current test file successfully when no
  test has already failed. It calls `Reporter::stop()`, does not increment the failure count, and
  contributes process exit status `0` to direct `stest`, `stest-all`, CI, and cron runs. After a real
  failure, including `--first_error`, the failure takes precedence: `Reporter::fail()` is called and the
  process exits nonzero. The `--force` option ignores all `::stop` calls.\
   example: `if (date("l") != "Monday") \STest::stop("Monday-only test");`
- `STest::stop($message, int $until_yyyymmdd)` - successfully disable the test until the date; execution resumes on that date
- `STest::runOnce($period = "1day")` - run an *unmodified* file at most once per period: when the
  file's sha1 equals the one recorded after its last fully passing run, less than `$period` ago,
  the file stops like `STest::stop()` (exit 0). Any edit makes it run again; a failure is never
  recorded. Same as the special-use tag `# @tag run-once(1day)`, which skips the file before
  anything executes. `--force` / `--once=ignore` run it anyway; `--once=reset` forgets the pass;
  `--once[=PERIOD]` treats every file as if it called `runOnce(PERIOD)`.
  Periods: `1day`, `2days`, `12h`, `30min`, `1week`, `daily`, `weekly`, `hourly`, or seconds.
- `STest::failOnce($period = "1day")` - for cron alerting: report an unchanged failure once per
  period. The file always runs; when it fails exactly as last time (same failures, unchanged file)
  less than `$period` after that failure was reported, its error output is dropped and the file
  counts as stopped (exit 0). A new or different failure is reported; a pass clears the record.
  Call it before any test line, or use the tag `# @tag fail-once(1day)`.
- `STest::error($message)` - terminate the current test file as a failure, call `Reporter::error()`, and contribute nonzero status
- `STest::alert($message)` - terminate the current test file as a failure, call `Reporter::alert()`, and contribute nonzero status
- `STest::latestVersion()` - query Packagist and return the newest stable published
  Spartan Test SemVer. Network, HTTP, malformed JSON, and missing-version failures throw
  explicit exceptions instead of being treated as "up to date".
- `STest::checkLatestVersion()` - compare the Packagist release with the installed
  `\stest\VERSION` and return the newer version string.
- `STest::requireVersion($version, $on_fail = 'stop')` - require a minimum strict SemVer
  (`MAJOR.MINOR.PATCH`, optional prerelease/build metadata). An unmet requirement uses
  the normal successful `STest::stop()` path by default; use `on_fail: 'error'` for a
  nonzero test failure. `--force` bypasses only the `stop` mode.
- `STest::debug($message, $level)` - show text to STDERR when `--debug=$level >= $level`
- `STest::inspect(/* "object | string className" */ $object, $show_line = 0)` - backend for `? object`
- `STest::runTest($file)`  -  run another .stest file in the current context

```php
; \STest::runOnce('1day');
; \STest::failOnce('12h');
; \STest::requireVersion('4.0.0');
; \STest::requireVersion('4.1.0', on_fail: 'error');
; $latest = \STest::checkLatestVersion();
```

# Web Tests

Web tests emulate a browser session: cookies and the HTTP referrer are kept between requests, so user flows are easy to script.

At a minimum, every page must return a non-empty HTTP `200` response.\
A page that contains standard PHP error output fails the test.

@see [Web Tests](https://github.com/parf/spartan-test/blob/main/web-tests.md)

---

* [Examples](https://github.com/parf/spartan-test/blob/main/examples)



USING STEST
-----------
Create file `$filename.stest` starting with
```
#!/bin/env stest
<?php

# your test
```

Then write `chmod +x $filename.stest` to make it executable
