SPARTAN-TEST
============

Minimalistic PHP 7, PHP 8 Unit Testing Framework / Web Testing Framework

Write your tests in style:
* tests should be simple
* run your tests as executable file
* test your code and/or test web pages
* test is ~ php code, minimal learning curve
* less cruft, more fun

- [Syntax](https://github.com/parf/spartan-test/blob/main/Syntax.md)
- [Web Tests](https://github.com/parf/spartan-test/blob/main/web-tests.md)

* Examples
    - [Basic test example](https://github.com/parf/spartan-test/blob/main/examples/1-basics/1-first-test.stest)
    - (advanced) [Custom comparison methods](https://github.dev/parf/spartan-test/blob/main/examples/1-basics/special-tests.stest)
    - [Web-tests](/web-tests.md)


# Composer / Laravel Autoload Integration
upon start spartan test includes `bootstrap/autoload.php` or `vendor/autoload` or `init.php` file from current or parent directories

You can specify your custom autoload file using "--init=$path_filename" option or via `stest.config` file


INSTALL (GIT)
-------
    mkdir -p ~/src ~/bin
    git clone https://github.com/parf/spartan-test.git ~/src/spartan-test
    ln -s ~/src/spartan-test/stest ~/bin


INSTALL (COMPOSER)
-------
    composer require parf/spartan-test
    ln -s ./vendor/bin/stest ~/bin
    ln -s ./vendor/bin/stest-all ~/bin
