# SPARTAN-TEST SYNTAX
Minimalistic PHP 7, PHP 8 Unit Testing Framework / Web Testing Framework

* Spartan test reads test-file line by line

Line read can be a:
 - PHP-expression
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

* STest catches
    - Return values
    - Exceptions (throwable)
    - Stdout output (echo, print)
    - PHP notices/warnings and errors


BASIC SYNTAX
-----------
* Spartan test is a set of expressions and their results
    - types of expressions:
        + "; php-code" php code to execute, no testing
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

/* lines starting with ";" are just php-expressions */
; $x = M_PI / 6;  # php-expression prefixed by ";";
sin($x) < 2;
    true;
range(3,4);
    [3, 4];
```

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
- When `fd` is installed, hidden and ignored files are skipped by default. Use `-u` or
  `--unrestricted` to include paths excluded by hidden-name rules, `.gitignore`,
  `.ignore`, or `.fdignore`. `vendor` and `node_modules` directories are also skipped
  by default with either discovery backend and included by `--unrestricted`. Systems
  without `fd` use the `find` fallback.

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
