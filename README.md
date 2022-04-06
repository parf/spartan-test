SPARTAN-TEST
============

Minimalistic PHP 7, PHP 8 Unit Testing Framework / Web Testing Framework

Write your tests in style:
* tests should be simple
* run your tests as executable file
* test your code and/or test web pages
* test is ~ php code, minimal learning curve
* less cruft, more fun

DESCRIPTION
-----------
* Spartan test is a set of expressions and comments
    - types of expressions:
        + "; php-code" php code to execute, no testing
        + "test-expression" - php-code that product result
        + "    result" - stored test-expression result (valid php code)
        + "    ~ custom-result-test" - function comparison (see below)
        + "/url-path" - (see web-test)
        + "! test-expression" - critical test. Test execution will stop if this test failed

* Spartan test reads test-file line by line

* for test-expressions it calculate result, then compares it to stored result
    - if result exists and differ, error is generated
    - if no result stored in test-file, generated result is added to test-file

* stest catches 
    - return values
    - exceptions
    - stdout output (echo, print)
    - php notices/warnings and errors

Sample spartan test:
```
#!/bin/env stest
# first line makes test an executable script
# math test
2*2;    # test have 0 identation, result must be idented by 4 spaces;
    4;
; $x = M_PI / 6;  # php-expression prefixed by ";";
sin($x) < 2;
    true;
range(3,4);
    [3, 4];
```

USING STEST
-----------
create file starting with
```
#!/bin/env stest
<?php

# your test
```

INSTALL
-------
    mkdir -p ~/src ~/bin
    git clone https://github.com/parf/spartan-test.git ~/src/spartan-test
    ln -s ~/src/spartan-test/stest ~/bin
