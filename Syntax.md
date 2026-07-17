# SPARTAN-TEST SYNTAX
Minimalistic PHP 8.5 Unit Testing Framework / Web Testing Framework

* Spartan test reads test-file line by line

Line read can be a:
 - PHP setup expression or block
 - Test-expression (or just `test`)
 - Test-result
 - Comment

* For test-expressions it calculates the result, then compares it to stored result
    - comparison is textual against the canonical (auto-generated) form
    - if no result stored in test-file, generated result is added to test-file
    - if the stored result differs only in formatting / key order (same value), the test
      is re-run in soft-regen mode that rewrites just those lines to canonical form and
      saves the file (reported as `reformat: N`) — so you may write expected results in
      any formatting you like
    - if the stored result differs in VALUE, an error is generated (the result is not changed;
      use `stest -g` to force-overwrite all results)
    - `stest -g` exits successfully for regenerated differences, but exits nonzero if an
      input file cannot be read or an updated test file cannot be saved

* STest catches
    - Return values
    - Exceptions (throwable)
    - Stdout output (echo, print)
    - PHP notices/warnings and errors


BASIC SYNTAX
-----------
* Spartan test is a set of expressions and their results
    - types of expressions:
        + "; php-code" PHP setup code to execute, no result comparison
        + "test-expression" - php-code that produce result
        + "    result" - stored test-expression result (valid php code)
        + "    ~ custom-result-test" - custom comparison method (see below)
        + "/url-path" - (see web-tests below)
        + "! test-expression" - critical test. Test execution will stop if this test failed
        + "? expression" - inspect Class/Variable - show class-name,parent-class,class-file-location


Sample spartan test:
```
#!/bin/env stest
/*
  first line makes test an executable script
*/
# math test
2*2;    # tests have 0 indentation
    4;  # result must be indented by 4 spaces; if no result present it will be auto-generated

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
- For `if`/`elseif`/`else`, keep `elseif` or `else` on the same physical line as the
  preceding closing brace (`} else {`). A separate zero-indented `else` is not yet
  recognized as continuation; legacy indentation also keeps it attached.
- Do not add `<?php` inside a setup block. The optional top-level `<?php` line in an
  older `.stest` file is treated as a comment for compatibility.

Multi-line test expressions are different: they still use 1-2 spaces for continuation,
and expected results still use exactly 4 spaces. The PHP-block rules above apply only
to entries whose first character is `;`.

See [multiline-setup.stest](examples/1-basics/multiline-setup.stest) for a runnable
example and [basic.stest](examples/1-basics/basic.stest) for the legacy indentation form.

@see [more complex example](https://github.com/parf/spartan-test/blob/main/examples/1-basics/basic.stest)

### Array Result Sorting
By default all results arrays are sorted by keys (unlimited DEPTH)\
To turn off this behaviour add `$ARG['sort']=0;`; to re-enable it back `$ARG['sort']=1;`

@see (https://github.com/parf/spartan-test/blob/main/examples/2-advanced/result-sorting.stest)

# Advanced Syntax / Advanced Tests

Instead of result you can use one(or more) advanced tests

`~`   - test for NON empty string

`~~`  - test for NON empty result:   `if (! $result) FAIL();`

`~ "substring"`  - substring present

`~ Class`  - is-descendant

`~ []`            - is-array

`~ [$a, $b, ..]`  - VALUES $a and $b are in resulting array

`~ [key => val]`    - KEY => VALUE is in resulting array

`~ [key => true]`    - KEY present

`~ [key => false]`    - KEY *NOT* present

`~ /regexp/x`     - is result matching regexp

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
- Repeated `--tag` options and comma-separated values are merged using the same rules.
- `--all` includes non-executable `.stest` files but does not override `@require-tag`.
- A positive-only tag query with no matches exits nonzero. If negative tags intentionally
  exclude every positive match, the empty result remains successful.
- Both discovery backends skip hidden files, `vendor`, and `node_modules` by default.
  When installed, `fd` additionally honors `.gitignore`, `.ignore`, and `.fdignore`.
  Use `-u` or `--unrestricted` to include these paths. Systems without `fd` use the
  `find` fallback.

Selection order is executable/`--all`, then `--recent`, tags, `--new`, and finally
execution or `--list`. Examples:

```bash
stest-all --tag=smoke
stest-all --tag="prod -long" --recent=2day
stest-all --list --all --tag=staging --new=4
stest-all --list --all --unrestricted
```

See [tagged-test.stest](examples/1-basics/tagged-test.stest) for file metadata syntax.

### You can have several Advanced tests for one expression

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
- `STest::error($message)` - terminate the current test file as a failure, call `Reporter::error()`, and contribute nonzero status
- `STest::alert($message)` - terminate the current test file as a failure, call `Reporter::alert()`, and contribute nonzero status
- `STest::debug($message, $level)` - show text to STDERR when `--debug=$level >= $level`
- `STest::inspect(/* "object | string className" */ $object, $show_line = 0)` - backend for `? object`
- `STest::runTest($file)`  -  execute OTHER stest in current context

# Web Tests

Web tests emulate web site queries; they keep all cookies and http_referrers, so it is easy to emulate user behaviour on sites

At bare minimum, web tests require all pages to be non-empty `code 200` (http success) pages\
When standard `php-error output` found on a page, error will be raised

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
