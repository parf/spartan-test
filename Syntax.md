# SPARTAN-TEST SYNTAX
Minimalistic PHP 7, PHP 8 Unit Testing Framework / Web Testing Framework

* Spartan test reads test-file line by line

Line read can be a:
 - PHP-expression
 - Test-expression (or just `test`)
 - Test-result
 - Comment

* For test-expressions it calculate result, then compares it to stored result
    - if result exists and differ, error is generated
    - if no result stored in test-file, generated result is added to test-file

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
2*2;    # tests have 0 identation
    4;  # result must be idented by 4 spaces, if no result present - it will be auto-generated

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
To turn off this behaviour add `$ARG['sort']=0;`; to re-enable it back `$ARG['sort']=0;`

@see (https://github.com/parf/spartan-test/blob/main/examples/2-advanced/result-sorting.stest)

# Advanced Syntax / Advanced Tests

Instead of result you can use one(or more) advanced tests

`~`   - test for NON empty string

`~~`  - test for NON empty result:   `if (! $result) FAIL();`

`~ "substring"`  - substring present

`~ Class`  - is-descendant

`~ []`            - is-array

`~ [$a, $b, ..]`  - VALUES $a and $b are in resulting array

`~ [key=>val]`    - KEY => VALUE is in resulting array

`~ /regexp/x`     - is result matching regexp

@see [special tests](https://github.com/parf/spartan-test/blob/main/examples/1-basics/special-tests.stest)

### You can have several Advanced tests for one expression

```
\hb\Curl::get("example.com");
    ~ string
    ~ "Example"
    ~ "website"
    ~ /example/i;

```

# Build-in STest Methods

- `STest::domain()` - @see web-tests
- `STest::stop($message)` - stop test execution w/o error  (`--force` option ignores all ::stop)\
   example: `if (date("l") != "Monday") \STest::stop("Monday-only test");`
- `STest::stop($message, int $until_yyyymmdd)` - disable test until date
- `STest::error($message)` - stop test execution with Error
- `STest::alert($message)` - stop test execution with Alert
- `STest::debug($message, $level)` - show text to STDERR when `--debug=$level >= $level`
- `STest::inspect(/* "object | string className" */ $object, $show_line = 0)` - backend for `? object`
- `STest::runTest($file)`  -  execute OTHER stest in current context

# Web Tests

Web tests emulates web site queries, they kept all cookies and http_refferers\
So it is easy to emulate user's behaviour on sites

At bare minimum, web tests require all pages to be non-empty `code 200` (http success) pages\
Also when standard `php-error output` found on a page - error will be raised

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

Then do `chmod +x $filename.stest` to make it executable
