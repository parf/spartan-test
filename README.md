SPARTAN-TEST
============

Minimalistic PHP Unit Test Framework

Write your tests in style:
* your tests should be easier than your code
* run your tests as executable file
* test script is valid php code
* less cruft, more fun

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
    git clone git@github.com:parf/spartan-test.git ~/src/spartan-test
    ln -s ~/src/spartan-test/spartan-test ~/bin


SYNOPSIS
--------

    spartan-test test1.test [test2.test] ...
        - run test(s) / generate expected tests results, add expected results for new tests

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
    -c          - do not overwrite files, show processed colorified output
    --no-init   - do not check/include for init.php

    --help      - show this help
    --example   - show sample/example test
    --legal     - show copyright, license, author


LIMITATIONS
-----------

* one command per line, however you can use multiline php comments
* php eval limitations apply: no loops, control structures, no unset (see x_unset)

AUTOLOAD
--------

  test will search for "init.php" file in current and parents directories
  define your autoload and init functions there

NOTES
-----

* expected results are stored in php comments as json "#=json"
* suppress result check via "; " prefix : "; discard_my_result();"
* Style reccomendation: use "//" & "/* .. */" for your comments
* check out './test.test -c'
* check out 'watch "spartan-test xxx.test -o | tail"'

PROVIDED FUNCTIONS:
-------------------
* `**include_all**`( directory | array(dir, dir, ...), ext=".php" )
  * php include all files in directories (and subdirs), exclude hidden directories
  * example: `include_all( [ "/project/framework", "/project/lib" ] );`
* `**x_unset**`(array $a, $index)
  * allows you to test at least some unsets

HOW TO MAKE EXECUTABLE TESTS
----------------------------

    add "#!/bin/env spartan-test" as first line of your test
    chmod +x test1.test

    now you can run test as ./test1.test

CRON EXAMPLE
------------

* run all project tests every 30 min
* send email when errors occurred (use sms gateways to send sms)

    type `crontab -e` - add:

        */30 * * * *    find /project-dir -name "*.test" | spartan-test -- -s |& mail -E parf@example.com -s "Project Unit Test Errors"

EXAMPLES
--------
   type `spartan-test --example`  to see sample test
   more examples at https://github.com/parf/spartan-test/tree/master/examples

AUTHOR
------
  Sergey Porfiriev <parf@comfi.com>

COPYRIGHT
---------
  (C) 2010 Comfi.com

LICENSE
-------
  The MIT License (MIT) - http://www.opensource.org/licenses/mit-license.php

