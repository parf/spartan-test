# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Spartan-test is a minimalistic PHP 8+ unit and web testing framework that emphasizes simplicity and minimal learning curve. Tests are written in PHP with a special syntax where test expressions and their expected results are stored in `.stest` files. The framework automatically generates expected results when missing and compares them on subsequent runs.

## Commands

### Running Tests

```bash
# Run a single test file
./path/to/test.stest
# or
bin/stest path/to/test.stest

# Run all tests in parallel (requires gnu-parallel)
bin/stest-all

# Run all tests quietly (errors only)
bin/stest-all -q

# List all test files
bin/stest-all --list
```

### Common Test Options

```bash
# Verbose mode - show every line being tested
stest -v test.stest

# Regenerate all results (ignore errors, force overwrite)
stest -g test.stest

# Silent mode - show errors only on STDERR
stest -s test.stest

# Stop on first error
stest -1 test.stest

# Show debug info (1-9 levels)
stest --debug=9 test.stest

# For cron: no colors, errors only
stest --cron test.stest

# Force test execution (ignore STest::stop calls)
stest --force test.stest
```

### Composer Commands

```bash
composer test          # Run all tests
composer test-quite    # Run tests quietly
composer test-list     # List all tests
```

## Architecture

### Core Components

**`src/STest.php`** - Main test framework class
- `STest` class: Core testing logic, contains all test execution machinery
- Poor-man DI container using `I($name, $args)` function
- Handles test parsing, execution, result comparison, and output formatting

**`src/Helpers.inc.php`** - Helper utilities
- `InstanceConfig`: Configuration loading from multiple sources (base config, project configs)
- `Reporter`: Test result reporting and output formatting
- Various helper functions for output coloring, string manipulation, etc.

**`src/WebTest.inc.php`** - Web testing functionality
- `WebTest` class: HTTP request/response testing
- Supports GET, POST, JSONPOST operations
- Cookie/session preservation across requests
- Automatic PHP error detection on pages

**`src/Curl.inc.php`** - HTTP client wrapper
- `Curl` class: Low-level HTTP operations
- Used by WebTest for actual HTTP communication

**`bin/stest`** - Executable entry point for single test
- Thin wrapper that loads STest.php and runs tests

**`bin/stest-all`** - Shell script to run all tests in parallel
- Uses GNU parallel to execute multiple `.stest` files concurrently

### Configuration System

Configuration is loaded hierarchically (later sources override earlier):

1. **`src/config.json`** - Bundled base config (always loaded)
2. **`stest-config.json`** - Project-specific config (searched in current/parent dirs)
3. **`stest-config.local.json`** - Local overrides (git-ignored, searched in current/parent dirs)
4. **Command-line options** - Override everything

Config specifies which classes to use for core components:
- `"stest"`: Main test class (default: `STest`)
- `"reporter"`: Reporter class (default: `stest\helper\Reporter`)
- `"webtest"`: Web test class (default: `hb\WebTest`)
- `"init"`: Autoload file paths to search (default: `["bootstrap/autoload.php", "vendor/autoload.php", "init.php"]`)
- `"realm"`, `"realmUriMethod"`, `"realmDetectMethod"`: Web test realm configuration
- `"errorCallback"`: Optional callback to enrich error results

### Test File Format (.stest)

Test files use a special syntax:
- **Unindented lines**: Test expressions (PHP code that returns a value)
- **4-space indented lines**: Expected results (auto-generated if missing)
- **Lines starting with `;`**: PHP expressions to execute (no testing)
- **Lines starting with `!`**: Critical tests (execution stops if failed)
- **Lines starting with `?`**: Inspect class/variable (debugging)
- **Lines starting with `/`**: Web test GET requests
- **Lines starting with `POST /`**: Web test POST requests
- **Lines starting with `JSONPOST /`**: Web test JSON API requests
- **Lines starting with `FOLLOW "`**: Follow link by text
- **Lines starting with `~ `**: Custom comparison tests (substring, regex, array checks, etc.)

### Extension Points

To extend the framework:

1. **Custom Reporter**: Create a class extending `stest\helper\Reporter`, specify in config as `"reporter":"YourClass"`
   - Useful for sending Slack notifications on errors/alerts

2. **Custom WebTest**: Create a class extending `hb\WebTest`, specify in config as `"webtest":"YourClass"`
   - Add custom response validation by creating `test_YourTestName()` methods

3. **Custom STest**: Extend `STest` class with additional functionality, specify in config as `"stest":"YourClass"`

4. **Error Callbacks**: Add `"errorCallback": "Class::method"` to config to enrich error output with custom data

### Dependency Injection

The framework uses a simple DI container:
- `I($name)` - Get/create instance by name
- `I($name, [$arg1, $arg2])` - Get/create instance with constructor args
- `I([$name, $instance])` - Register instance

Instance names are defined in config files (`"stest"`, `"reporter"`, `"webtest"`).

### Web Testing Architecture

Web tests maintain state across requests:
- `STest::$DOMAIN` - Current domain
- `STest::$URL` - Last URL requested
- `STest::$PATH` - Last path requested
- `STest::$BODY` - Response body
- `STest::$COOKIE` - All cookies (preserved across requests)
- `STest::$HEADERS` - Response headers
- `STest::$INFO` - Full curl response info

Realms allow testing against different environments (dev/staging/prod) by modifying domain dynamically via `--realm` option or environment variables.

### Result Comparison

By default, array results are sorted recursively by keys before comparison (can be disabled with `$ARG['sort']=0`).

Advanced comparison operators (lines starting with `~ `):
- `~` or `~~` - Test for non-empty result
- `~ "substring"` - Contains substring
- `~ ClassName` - Is instance/descendant of class
- `~ []` - Is array
- `~ [$a, $b]` - Array contains values
- `~ [key => val]` - Array contains key-value pair
- `~ [key => true]` - Array has key
- `~ [key => false]` - Array doesn't have key
- `~ /regexp/` - Matches regex

## Development Workflow

When adding features:
1. Test files are in `examples/` organized by category (1-basics, 2-advanced, 3-web-tests)
2. Core framework code is in `src/`
3. Run existing tests to ensure no regressions: `composer test`
4. Make test files executable: `chmod +x test.stest`

When modifying test parsing or execution:
- Main parsing logic is in `STest::run()` method
- Test execution happens in `STest::_process_test()` method
- Result comparison in `STest::_compare()` method
