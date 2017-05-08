#!/bin/php
<?php

namespace {

    class STest extends \stest\STest {}
}

// #!/bin/php

/**
 * Spartan Test 3.0 - php 7.1 testing framework done right
 * RTFM: README.md
 */



namespace stest {

include __DIR__."/Helpers.inc.php";

// Dependency injection
//
// I($name)                     - get / create new named instance
// I($name, [arg1, arg2, ...])  - get / create new named-with-params instance
// I([$name, $instance], [arg1, arg2, ...])  - assign instance
function I(/*string | array */ $name, array $args=[]) { # Instance
    if (is_array($name)) {
        [$name, $i] = $name;
        $k = $name.":".json_encode($args);
        helper\InstanceConfig::$I[$k] = $i;
        return $i;
    }
    $k = $name.":".json_encode($args);
    if ($i = @helper\InstanceConfig::$I[$k])
        return $i;
    $class = @helper\InstanceConfig::$config[$name]; // class name
    if (! $class)
        throw new Exception("No definition for instance $name");
    if (is_array($class)) // Actual ClassName Provided by method
        $class = $class($args);
    return helper\InstanceConfig::$I[$k] = new $class($args);
}

// ----------------------------------------
//
// PUBLIC
//

const VERSION = 3.0;

// ----------------------------------------
//
// INTERNAL
//

class STest {

    static $args;
    static $tests;

    // optionExpand Short to LongOption or [Option => value, ...]
    static $optionExpand = [
        // shortOption to longOption
        's' => 'silent',            // show only errors
        'g' => 'generate',          // force test file overwriting, ignore test errors
        'S' => ['silent' => 0],     // no-silent
        'c' => 'color',             // force color
        'C' => ['color' => 0],      // force no-color
        'v' => 'verbose',           // show test lines being executed
        '1' => 'first_error',       // stop on first error in test
        // option to set of options
        'cron' => ['color' => 0, 'silent' => 1],   // --cron - show only errors, no colors
    ];

    // ---------------------------------------------------------------
    //
    // Static Methods
    //

    /**
     * stop test execution successfully
     * can be overriden by "--force"
     *
     * Usage:
     *   \STest::stop("message");            << test disable
     *   \STest::stop("message", 20170303);  << disable until 2017-03-03 ISO8601
     *
     * if (date("l") != "Monday") \STest::stop("Monday-only test");
     *
     */
    static function stop(string $message, int $until_yyyymmdd = 0) {
        if ($until_yyyymmdd && (int) date("Ymd") < $until_yyyymmdd)
            return;
        if (@self::$args['force'])
            return;
        throw new StopException($message);
    }

    /**
     * stop test execution, Emit Error: same level of severity as failed test
     * Usage:
     *   \STest::error("message");
     */
    static function error(string $message) {
        throw new ErrorException($message);
    }

    /**
     * stop test execution. Emit Alert: alerts processed by alert handler
     * Usage:
     *   \STest::alert("message");
     */
    static function alert(string $message) {
        throw new AlertException($message);
    }

     /**
     * Enable TESTING (already enabled bu default, call after calling disable)
     * Usage:
     *   \STest::enable();
     */
    //static function enable() {
    //    static::$on = 1;
    //}

     /**
     * disable TESTING
     *   Ex: disable dvp-only test part on production.
     * Usage:
     *   \STest::disable();
     */
    //static function disable() {
    //    static::$on = 0;
    //}

    // ---------------------------------------------------------------

    public $file;           // current filename

    function init($argv) {
        self::parseArgs($argv);
        // setup console
        I(['console', helper\Console::i([@self::$args['color'], @self::$args['silent']])]);
        if (@self::$args['debug'])
            I('console')->e("{green}Spartan Test v".VERSION."{/} on ".gethostname()." at ".date("Y-m-d H:i")."\n");
        set_error_handler('\\stest\Error::handler', E_ALL);
    }

    // spartan-test -abc --d --c="VALUE" test1 test2
    PUBLIC function run(array $argv) {
        $this->init($argv);
        foreach (self::$args as $a => $v) {
            $a = str_replace("-", "_", $a); // "-" to "_"
            if (is_callable(["stest\\STest_Global_Commands", $a]))
                STest_Global_Commands::$a($v);
        }
        foreach (self::$tests as $test)
            $this->runTest($test);
    }

    function runTest($file) {
        $this->file = $file;
        # i('console')->e("*** {head}$file{/}\n");
        try {
            $T = helper\Parser::Reader($file);
        } catch(SyntaxErrorException $ex) {
            echo $ex->getMessage(), "\n";
            die;
        }
        $cmd = 0;
        foreach (self::$args as $a => $v) {
            $a = str_replace("-", "_", $a); // "-" to "_"
            if (is_callable(["stest\\STest_File_Commands", $a])) {
                $cmd++;
                STest_File_Commands::$a($T, $v);
                break; // execute only only command
            }
        }
        if (! $cmd)
            STest_File_Commands::test($T);
    }

    protected static function parseArgs($argv) {
        self::$args = helper\parseArgs($argv);
        self::$tests = self::$args[0];
        // default color = on
        self::$args += ['color' => 1];
        self::$args += ['sort' => 1];
        unset(self::$args[0]);
        // convert short options to long options using self::$optionExpand
        $to_fix = array_filter(
            self::$args,
            function ($v, $k) {
                if (@self::$optionExpand[$k])
                    return 1;
            },
            ARRAY_FILTER_USE_BOTH);
        foreach ($to_fix as $k => $v) {
            unset(self::$args[$k]);
            $nk = self::$optionExpand[$k];
            if (is_array($nk))
                self::$args = array_merge(self::$args, $nk);
            else
                self::$args[ $nk ] = $v;
        }
    }

    function reportTestException(Exception $ex, $line) {

    }

}

/**
 *
 */
class STest_Global_Commands {

    /**
     * debug: show parsed arguments as json
     */
    static function debug_args() {
        $a = STest::$args;
        $tests = @$a[0];
        unset($a[0]);
        echo json_encode(['args' => $a, 'tests' => $tests], JSON_PRETTY_PRINT)."\n";
    }


    /**
     * stop on first error encountered in test (-1)
     */
    static function first_error() {}

    /**
     * do not stop \STest::stop, stop on Error/Alert however (-f)
     */
    static function force() {}

    /**
     * show every line being tested (-v)
     */
    static function verbose() {}

    /**
     * re-generate test. replace all results (-g)
     */
    static function generate() {}


    /**
     * turn output coloring on/off - default on
     * --color=0 or -C - turn off
     */
    static function color() {}

    /**
     * show erorrs only (-s)
     */
    static function silent() {}

    /**
     * error handler
     * \stest\Error::$suppress;  - bitmask to suppress errors
     * \stest\Error::suppress_notices()  - ignore notices (suppress reporting)
     * \stest\Error::suppress_warnings() - ignore warnings (suppress reporting)
     */
    static function error_handler($v = '\\stest\\Error::handler') {
        #set_error_handler($v, E_ALL);
        throw new Exception("Unsupported");
    }


    /**
     * 'init' file to include, must provide autoload
     */
    static function init($v) {
    }

    /**
     * Enable/Disable result sorting - to be used inside test only
     * $ARG['sort'] = 0; // disable
     * $ARG['sort'] = 1; // enable (default)
     */
    static function sort($v) {
        throw new Exception("see docs");
    }

}

/**
 *
 * STest --$option File.stest
 *
 * static function $Option(ParsedTest $T, $option_value)
 *
 */
class STest_File_Commands {

    // --debug-test
    static function debug_test($T) { # echo internal test presentation
        foreach ($T as [$ln, $tv]) {
            echo "$ln: ", json_encode($tv, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
        }
    }

    /**
     * `cat` processed test tp stdout (add missing semicolons, correct identation)
     */
    static function cat($T, $echo = 1) : string { # test
        $s = "";
        foreach ($T as [$ln, $tv]) {
            [$tp, $v] = $tv;
            if ($tp  == "test") {
                $r = @$tv[2];
                $s .= "$v\n".($r ? "    $r\n" : "");
                continue;
            }
            $s .= "$v\n";
        }
        if ($echo)
            echo $s;
        return $s;
    }

    // default action
    // --generate   - regenerate test, ignore errors
    // --force - ignore STest::stop
    // -v | --verbose - show statements being executed
    static function test($T) {
        // using i('o') to hide varibles
        i(["o", (object)
            ['T' => $T,
             'filename_shown' => 0,
            'fail' => 0,
            'new' => 0,
            'tests' => 0,
            'start' => microtime(1),
            ]
            ]);
        $ARG = \STest::$args; // Visible inside TEST

        // i(console)->err wrapper
        $__err = function($s, $reason = "failed") {
            if (! i('o')->filename_shown) {
                i('console')->err("*** {alert}%s %s{/}\n", i('stest')->file, $reason);
                i('o')->filename_shown = 1;
            }
            i('console')->err($s);
        };

        $__tester = function(string &$expected, $got, $line, $code) use ($__err, &$ARG) {
            $exp = trim($expected, ";");
            $got = helper\x2s($got, @$ARG['sort']);
            if ($exp == $got)
                return;
            if (@$ARG['generate']) { // generate all results in test
                i('o')->fail++;
                $expected = $got.";"; // save corrected result
                return;
            }
            $code = str_replace("\n", " ", $code);
            $code = preg_replace("/\s+/", " ", $code);
            if (! $expected) { // NEW TEST - generate result
                i('o')->new++;
                $expected = $got.";"; // save generated result
                $__err("{bold}{blue}L$line{/}: $code\n");
                $__err(" got: {blue}$got{/}\n");
                return;
            }
            // FAILED TEST
            i('o')->fail++;
            $__err("{alert}L$line{/}: {red}$code{/}\n");
            $__err(" expected: {cyan}".$exp."{/}\n");
            $__err(" got: {red}$got{/}\n");
            if (@$ARG['first_error'])
                throw new StopException("Stopping on first error");
        };

        $__expr_exception = function (\Exception $ex, $line) use ($__err) {
            $m = $ex->getMessage();
            $class = get_class($ex);
            if ( is_a($ex, "\stest\Exception")) {
                if (is_a($ex, "\stest\StopException")) {
                    i('console')->e("*** {head}%s{/} {warn}Test stopped{/} at line $line : $m\n", i('stest')->file);
                    return;
                }
                $err = "TestFlow Exception `$class`";
                if (is_a($ex, "\stest\ErrorException"))
                    $err = "TEST ERROR";
                if (is_a($ex, "\stest\AlertException"))
                    $err = " !!!  TEST ALERT  !!! ";
                i('stest')->reportTestException($ex, $line);
                $__err("{alert}$err{/} at line $line: $m\n", "Stopped");
                return;
            }
            // \Exception
            $__err("{alert}$err{/} at line $line: $m\n", "Unsuccessfully Stopped");
        };

        @$ARG['verbose'] && i('console')->e("*** {head}%s{/}\n", i('stest')->file);

        // MAIN TEST LOOP BEGIN ------------------
        foreach (i('o')->T as &$__line__tp_v_r) { // [ln, [tp, v, r]]
            [$__line, [$__type, $__code]] = $__line__tp_v_r;
            if ($__type == 'expr') {
                try {
                    @$ARG['verbose'] && i('console')->e("{grey}%s{/}\n", $__code);
                    eval($__code);
                } catch(\Exception $__exception) {
                    $__expr_exception($__exception, $__line);
                    return;
                }
            }
            if ($__type  == "test") {
                i('o')->tests++;
                try {
                    @$ARG['verbose'] && i('console')->e("{cyan}%s{/}\n", $__code);
                    ob_start();
                    $__rz = eval("return $__code");
                    $__out = ob_get_clean();
                    if ($__out)
                        $__rz  = [$__rz, '$' => $__out];
                    if ($__error = Error::get())
                        $__rz = [$__rz, 'error' => $__error];
                } catch(\Exception $__exception) {
                    $__rz = [get_class($__exception), $__exception->getMessage()];
                }
                @$ARG['verbose'] && i('console')->e("    {green}%s{/}\n", helper\x2s($__rz));
                try {
                    $__tester($__line__tp_v_r[1][2], $__rz, $__line, $__code);
                } catch(\stest\StopException $__exception) {
                    i('console')->e("*** {head}%s{/} {warn}Test stopped{/} at line $__line : ".$__exception->getMessage()."\n", i('stest')->file);
                    return;
                }
            }
        }
        // MAIN TEST LOOP END ------------------

        $dur = microtime(1) - i('o')->start;
        $stat = "tests: ".i('o')->tests;
        if ($dur > 0.1) // require at least 0.1 sec
            $stat .= " (".sprintf("%0.2f", $dur)."s)";
        if ($new = i('o')->new)
            $stat .= ", {blue}{bold}new: $new{/}";
        if ($fail = i('o')->fail) {
            $__err("{alert}>{/} $stat, {warn}failed: $fail{/}\n");
        } else {
            i('console')->e("*** {head}%s{/} $stat\n", i('stest')->file);
        }

        // save test when '--generate' option, or new items were added and no tests failed
        if ((i('o')->new && ! i('o')->fail) || @$ARG['generate'])
            self::save(i('o')->T);
    }

    /**
     * save corrected test (missing ";" added, identation fixed)
     * called from "test"
     */
    static function save($T) {
        # echo json_encode($T);
        $filename = i('stest')->file;
        i('console')->e("*** {head}%s{/} saved\n", $filename);
        $s = self::cat($T, 0);
        file_put_contents($filename, $s);
    }

    /**
     * remove all generated results from test
     */
    static function clean($T) {
        foreach ($T as &$v) {
            if ($v[1][0] == 'test')
                unset($v[1][2]);
        }
        self::save($T);
    }

}


/**
 * Error Handler
 */
class Error {  // error handler

    PUBLIC static $suppress = 0;      // bit-mask to suppress errors

    private static $err = []; // php-errors from error handler - use get() to read

    // return error (if any), clean up error info
    static function get() { # string or array with errors
        $e=self::$err;
        self::$err = array();
        return sizeof($e) == 1 ? $e[0] : $e;
    }

    PUBLIC static function suppress_notices() { self::$suppress |= E_NOTICE; }
    PUBLIC static function suppress_warnings() { self::$suppress |= E_WARNING; }
    // if you want to suppress something else - change Error::$suppress

    // Error::handler
    static function handler($level, $message, $file, $line, $context) {
        // you can hide notices and warnings with @
        // you can't hide errors
        if (!error_reporting() && ($level == E_WARNING || $level == E_NOTICE))
            return;
        if ($level & self::$suppress) // allow people to debug ugly code
            return;
        static $map=array(
                          E_NOTICE       => 'NOTICE',
                          E_WARNING      => 'WARNING',
                          E_USER_ERROR   => 'USER ERROR',
                          E_USER_WARNING => 'USER WARNING',
                          E_USER_NOTICE  => 'USER NOTICE',
                          E_STRICT       => 'E_STRICT',
                          );
        $type = ($t = @$map[$level]) ? $t : "ERROR#$level";
        $e = "$type: $message";
        if (substr($file, 0, strlen(__FILE__)) != __FILE__)
            $e = array($e, $file, $line);
        array_push(self::$err, $e);
    }

} // class Error




class Exception extends \Exception {}

class SyntaxErrorException extends Exception  {}

class StopException extends Exception  {}   // \STest::stop("message")   -- Successfully Stop Test, ignore rest of the test
class ErrorException extends Exception {}   // \STest::error("message")  -- UNSuccessfully Stop Test, ignore rest of the test
class AlertException extends Exception {}   // \STest::alert("message")  -- UNSuccessfully Stop Test, send an alert, ignore rest of the test

// RUN TESTS

helper\InstanceConfig::$config = [
    'stest'   => 'STest',
    'console' => 'stest\helper\Console',
] + helper\InstanceConfig::$config;

I("stest")->run($argv);

} // namespace
