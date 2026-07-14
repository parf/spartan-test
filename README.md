SPARTAN-TEST
============

Minimalistic PHP 7, PHP 8 Unit Testing Framework / Web Testing Framework

Write your tests in style:
* Very simple tests
* Run as an executable file
* Both unit and web testing
* PHP code, minimal learning curve
* Less cruft, more fun

- [Syntax](https://github.com/parf/spartan-test/blob/main/Syntax.md)
- [Web Tests](https://github.com/parf/spartan-test/blob/main/web-tests.md)
- [Config](https://github.com/parf/spartan-test/blob/main/Config.md)

* Examples
    - [Basic test example](https://github.com/parf/spartan-test/blob/main/examples/1-basics/1-first-test.stest)
    - (advanced) [Custom comparison methods](https://github.dev/parf/spartan-test/blob/main/examples/1-basics/special-tests.stest)
    - [Web-tests](/web-tests.md)

* To see more help just run `stest --help` or `stest-all --help`

`STest::stop($message)` intentionally skips the rest of the current test file. A stopped
test is successful: it calls `Reporter::stop()` and contributes exit status `0` to
`stest`, `stest-all`, CI, and cron runs. `STest::error()` and `STest::alert()` are the
failure paths: they terminate the current file and contribute nonzero status.

## Selecting tagged tests with stest-all

`stest-all` can select complete test files using metadata in the first four physical
lines of each `.stest` file. The shebang counts as the first line. Direct `stest`
execution does not filter by these tags.

```text
#!/usr/bin/env stest
# @tag web smoke long
# @require-tag prod staging
```

`@tag` declares normal tags. These tests still run when no tag filter is provided.
`@require-tag` makes the file opt-in: at least one required tag must be explicitly
requested as a positive tag.

```bash
stest-all --tag=smoke
stest-all --tag="prod smoke"
stest-all --tag=prod,smoke
stest-all --tag="prod -long"
stest-all --tag=prod --tag=-long
```

Positive tags use OR matching. Negative tags exclude any file carrying that tag.
With only negative tags, `stest-all --tag=-long` runs otherwise eligible tests except
those tagged `long`; required-tag tests remain skipped. Tag selection composes with
`--all`, `--recent`, `--new`, and `--list`. `--all` includes non-executable files but
does not bypass `@require-tag`.


# Composer / Laravel Autoload Integration
Upon start spartan test includes `bootstrap/autoload.php` or `vendor/autoload` or `init.php` file from current or parent directories

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


- `stest` provides testing framework
- `stest-all` runs all or specific tests in parallel (gnu-parallel utility required)
