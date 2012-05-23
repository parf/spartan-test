SPARTAN-TEST
============

Minimalistic PHP Unit Test Framework

Write your tests in style:
* your tests should be easier than your code
* run your tests as executable file
* no clutter (assert, expect) - code and results only
* more fun

DESCRIPTION
-----------

* read test files with php code line by line
* read expected results from php comments
* generate expected results if they are not available (update file if needed)
* compare results with expected results
* checks return values, thrown exceptions, stdout output (echo, print), php errors

INSTALL
-------

    mkdir -p ~/src ~/bin
    git clone git@github.com:parf/spartan-test.git ~/src
    ln -s ~/src/spartan-test/spartan-test ~/bin


SYNOPSIS
========

    spartan-test test1.test [test2.test] ...
        - run testi(s) / generate expected tests results, add expected results for new tests

    ./test1.test
        - run any test as executable script

    spartan-test -g FILE...    |   ./test1.test -g
        - reGenerate expected tests results, update input file

    spartan-test --
        - read file list from stdin
        example: find . -name "*.test" | spartan-test --

OPTIONS
-------
    --color    - force terminal colors
    --nocolor  - suppress colors
    -o         - do not overwrite files, just show output
    -- --stdin - read file list from stdin
    --clean    - remove test results from source file
    -s --silent - suppress output when no-errors (for use in cron)
    --halt-on-errors - Do not allow PHP errors inside tests
    --help      - show this help
    --example   - show sample/example test
    --legal     - show copyright, license, author


LIMITATIONS
-----------

* one command per line
* php eval limitations apply: no loops, control structures, no unset

AUTOLOAD
--------

  test will search for "init.php" file in current and parents directories
  define your autoload and init functions there

NOTES
-----

* expected results are stored in php comments as json "#=json"
* suppress result check via "- " prefix : "- discard_my_result();"
* suppress result check by providing "#=-" comment (old way, keep test as valid php)

HOW TO MAKE EXECUTABLE TESTS
----------------------------

    add "#!/bin/env spartan-test" as first line of your test
    chmod +x your_test

    now you can run test as ./test-name

CRON EXAMPLE
------------

* run all project tests every 30 min
* send email when errors occured (use sms gateways to send sms)

    type "crontab -e" - add
    */30 * * * *    find /project-dir -name "*.test" | spartan-test -- -s |& mail -E parf@example.com -s "Project Unit Test Errors"

SAMPLE TEST
-----------
   type spartan-test --example  to see sample text

AUTHOR
------
  Sergey Porfiriev <parf@comfi.com>

COPYRIGHT
---------
  (C) 2010 Comfi.com

LICENSE
-------
  The MIT License (MIT) - http://www.opensource.org/licenses/mit-license.php

