# Spartan Test

**PHP 8+ unit and web tests that read like PHP and run like scripts.**

Spartan Test is a small testing framework for developers who want tests to stay close
to the code they exercise. A test is an ordinary PHP expression followed by its
expected result, all inside a simple executable `.stest` file.

```php
#!/usr/bin/env stest

; $prices = [12, 18, 30];

array_sum($prices);
    60;
```

Run it directly:

```bash
chmod +x price.stest
./price.stest
```

That is a complete test.

## ✦ What Makes It Distinctive

- **▶ Tests are executable files.** Run one file directly, pass several files to
  `stest`, or discover a complete suite with `stest-all`.
- **↻ Expected results live beside the code.** If a result is missing, Spartan Test
  generates and saves it. Results stay readable, reviewable, and easy to track in Git.
- **◆ PHP remains visible.** Use normal expressions, application objects, closures,
  functions, exceptions, and setup code without wrapping every check in a test class.
- **◎ Unit and web tests use the same format.** Test return values, services, pages,
  redirects, cookies, APIs, headers, and response content from `.stest` files.
- **⚡ Repository-scale execution is built in.** `stest-all` provides discovery,
  file-level tags, selection filters, and high-I/O parallel execution.
- **✓ CI behavior is predictable.** Failures return nonzero, intentional skips remain
  successful, and infrastructure errors are not reported as passing tests.

## How A Test File Works

Each entry has a simple role:

```php
; $user = loadUser(42);     // setup: starts with ";" in column one

$user->displayName();       // expression under test
    'Ada Lovelace';         // expected result: four-space indentation
```

Spartan Test captures:

- return values;
- thrown exceptions and errors;
- stdout from `echo` and `print`;
- PHP notices and warnings;
- web responses and request state.

When an expected result is absent, the first run adds its canonical PHP representation
to the file. A later value change fails normally. Use `--generate` when updating stored
results is intentional.

You can also use focused matchers when an exact value is unnecessary:

```php
$responseBody;
    ~ "account created";
    ~ /request-id:\s+\w+/i;
```

Setup code supports normal PHP syntax. Multi-line PHP works as expected; only the first
physical line needs the `;` setup prefix.

## ◎ Unit And Web Testing

Unit-style tests call PHP directly:

```php
calculateTax(100, 0.2);
    20;
```

Web tests keep cookies and referrers between requests and can check pages, redirects,
JSON APIs, headers, XPath results, and response content:

```php
; \STest::domain('https://example.test');

/account;
    ~ "Welcome";
```

See [Web Tests](web-tests.md) for the request syntax and runnable examples.

## Running Tests

```bash
stest price.stest                 # run one file
stest first.stest second.stest    # run several files
stest price.stest -q              # show failures only
stest price.stest --generate      # intentionally refresh stored results
```

Use `stest-all` for a repository:

```bash
stest-all                         # executable .stest files
stest-all -q                      # quiet suite run
stest-all --all                   # include non-executable .stest files
stest-all --list                  # inspect the selected files
stest-all --tag="smoke -long"     # include and exclude file tags
```

By default, suite discovery skips hidden paths, `vendor`, and `node_modules`. Install
[`fd`](https://github.com/sharkdp/fd) for faster discovery in large repositories;
systems without it automatically use `find`. GNU Parallel powers suite execution.

Run `stest --help` or `stest-all --help` for all CLI options.

## ◆ Application Integration

Spartan Test searches the test directory and its parents for configuration and common
bootstrap files:

- `bootstrap/autoload.php`
- `vendor/autoload.php`
- `init.php`

Use `--init=/path/to/bootstrap.php` for a one-off override, or configure your project in
`stest-config.json` / `stest-config.local.json`.

## Installation

Requirements:

- PHP 8.0 or newer;
- GNU Parallel for `stest-all`;
- `fd` is optional and recommended for large repositories.

### Composer

```bash
composer require --dev parf/spartan-test
vendor/bin/stest price.stest
vendor/bin/stest-all -q
```

### Git

```bash
mkdir -p ~/src ~/bin
git clone https://github.com/parf/spartan-test.git ~/src/spartan-test
ln -s ~/src/spartan-test/bin/stest ~/bin/stest
ln -s ~/src/spartan-test/bin/stest-all ~/bin/stest-all
```

## Learn More

- [Complete syntax](Syntax.md)
- [Web testing](web-tests.md)
- [Configuration](Config.md)
- [Examples](examples/)
- [Changelog and new features](CHANGELOG)

Start with [the first test](examples/1-basics/1-first-test.stest), then explore
[advanced result matching](examples/1-basics/special-tests.stest).
