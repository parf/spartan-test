<?php

namespace stest;

use stest\helper\InstanceConfig; // dependency injectoion
use stest\helper\Console;        // colored output
use function stest\helper\x2s;   // var_export alike

/**
 * Spartan Test 3.0 - php 7.1 testing framework done right
 * RTFM: README.md
 */

include __DIR__."/Helpers.inc.php";

// poor-man DI - Dependency Injection
//
// I($name)                     - get / create new named instance
// I($name, [arg1, arg2, ...])  - get / create new named-with-params instance
// I([$name, $instance], [arg1, arg2, ...])  - assign instance
function I(/*string | array */ $name, array $args=[]) { # Instance
    if (is_array($name)) {
        [$name, $i] = $name;
        $k = $name.":".json_encode($args);
        InstanceConfig::$I[$k] = $i;
        return $i;
    }
    $k = $name.":".json_encode($args);
    if ($i = @InstanceConfig::$I[$k])
        return $i;
    $class = @InstanceConfig::$config[$name]; // class name
    if (! $class)
        throw new \DomainException("No definition for instance $name");
    if (is_array($class)) // Actual ClassName Provided by method
        $class = $class($args);
    return InstanceConfig::$I[$k] = new $class($args);
}

// ----------------------------------------
//
// PUBLIC
//

const VERSION = 3.0;

//
// INTERNAL
//

class STest {

    static $ARG;
    static $TESTS;

    // WEB TEST RELATED DATA
    static $DOMAIN = "";
    static $HEADERS = [];
    static $BODY = "";
    static $INFO = [];
    static $URL = "";     // last URL used, if set used as REFERRER
    static $PATH = "";    // last PATH used
    static $COOKIE = [];  // array cookie => value

    static $DIR;           // current directory

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
        'h' => 'help',              // show help
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
        if (@self::$ARG['force'])
            return;
        throw new StopException($message);
    }

    /**
     * stop test execution, Emit Error: same level of severity as failed test
     * Usage:
     *   \STest::error("message");
     */
    static function error(/*string*/ $message) {
        if (! is_string($message))
            $message = var_export($message, 1);
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
     * Useful for debugging
     * inspect $object
     * show ClassName, ParentClass class_filename (non-common path wirh current test)
     */
    static function inspect(/* "object | string className" */ $object, $show_line = 0) {
        $class = is_object($object) ? get_class($object) : $object;
        $rc = new \ReflectionClass($class);
        $filename = $rc->getFileName();
        if ($p = $rc->getParentClass())
            $class .= "(".$p->getName().")";
        // remove common part of path
        foreach (range(0, strlen($filename)) as $i) {
            if (! isset($filename[$i]) || ! isset(self::$DIR[$i]) || $filename[$i] != self::$DIR[$i]) {
                $filename = substr($filename, $i);
                break;
            }
        }
        return "$class $filename".($show_line ? " ".$rc->getStartLine() : "");
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
        I(['out', Console::i(@self::$ARG)]); // color, silent, syslog
        if (@self::$ARG['debug'])
            I('out')->e("{green}Spartan Test v".VERSION."{/} on ".gethostname()." at ".date("Y-m-d H:i")."\n");
        if (! self::$TESTS)
            self::$ARG += ["help" => 1];
        set_error_handler('\\stest\\Error::handler', E_ALL);
    }

    // spartan-test -abc --d --c="VALUE" test1 test2
    PUBLIC function run(array $argv) {
        $this->init($argv);
        foreach (self::$ARG as $a => $v) {
            $a = str_replace("-", "_", $a); // "-" to "_"
            if (is_callable(["stest\\STest_Global_Commands", $a])) {
                if (($r = STest_Global_Commands::$a($v)) !== null)
                    die("$r\n");
            }
        }
        foreach (self::$TESTS as $test)
            $this->runTest($test);
    }

    function runTest($file) {
        $this->file = $file;
        self::$DIR = \realpath(dirname($file));
        try {
            InstanceConfig::init($file);
            $T = helper\Parser::Reader($file);
        } catch(\Exception $ex) {
            i('out')->e("*** {alert}$file{/}. Error: ".$ex->getMessage());
            return;
        }
        $cmd = 0;
        foreach (self::$ARG as $a => $v) {
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
        [self::$ARG, self::$TESTS] = helper\parseArgs($argv);
        self::$ARG += ['color' => 1, 'sort' => 1]; // Defaults
        // convert short options to long options using self::$optionExpand
        $to_fix = array_filter(
            self::$ARG,
            function ($v, $k) {
                if (@self::$optionExpand[$k])
                    return 1;
            },
            ARRAY_FILTER_USE_BOTH);
        foreach ($to_fix as $k => $v) {
            unset(self::$ARG[$k]);
            $nk = self::$optionExpand[$k];
            if (is_array($nk))
                self::$ARG = array_merge(self::$ARG, $nk);
            else
                self::$ARG[ $nk ] = $v;
        }
    }

}

/**
 *
 *  @see  Readme.md file for details: how to write/execute tests
 */
class STest_Global_Commands {

    /**
     * Any non-null return value treated as STOP signal, @see STest->run
     */

    /**
     * show every line being tested (-v)
     * stest -v filename.stest
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
     * output to syslog (to send errors-only to syslog: --syslog -s)
     */
    static function syslog() {}

    /**
     * stop on first error encountered in test (-1)
     * inside test: "; $ARG['first_error'] = 1;"
     */
    static function first_error() {}

    /**
     * do not stop \STest::stop, stop on Error/Alert however (-f)
     */
    static function force() {}

    /**
     * error handler
     * \stest\Error::$error_reporting;  - bitmask to suppress errors
     * \stest\Error::suppress_notices()  - ignore notices (suppress reporting)
     * \stest\Error::suppress_warnings() - ignore warnings (suppress reporting)
     */
    static function error_handler($v = '\\stest\\Error::handler') {
        #set_error_handler($v, E_ALL);
        throw new Exception("Unsupported");
    }

    /**
     * Enable/Disable result sorting - to be used inside test only
     * $ARG['sort'] = 0; // disable
     * $ARG['sort'] = 1; // enable (default)
     */
    static function sort($v) {
        # throw new Exception("see docs");
    }

    /**
     * this help
     */
    static function help() {
        if (STest::$ARG['silent'])
            return;
        $e = [i('out'), 'e'];
        $h = function ($h, $title) use ($e) {
            $e("{head}%s{/}\n", $title);
            $e($h["@"]. "\n"); // class doc
            foreach ($h as $method => $doc) {
                if (! $method || $method{0} == '_')
                    continue;
                $e("  {blue}{bold}%s{/}", str_pad($method, 16));
                $e(" {grey}%s{/}\n", str_replace("\n", "\n                   ", $doc));
            }
            $e("\n");
        };
        $e("{bold}Spartan Test v".VERSION." minimalistic php 7.1 testing framework done right{/}\n");
        $h ( helper\Documentor::classDoc("\\stest\\STest_Global_Commands"), "Global Options");
        $h ( helper\Documentor::classDoc("\\stest\\STest_File_Commands"), "File Options");
        #echo json_encode(helper\Documentor::classDoc("\\stest\\STest_Global_Commands"), JSON_PRETTY_PRINT), "\n";
        #echo json_encode(helper\Documentor::classDoc("\\stest\\STest_File_Commands"), JSON_PRETTY_PRINT), "\n";
    }

    /**
     * 'init' php file to include, must provide autoload
     * suggested use - specify 'init' in `stest.config` file @ root of your project
     */
    static function init($v) {
    }

    /**
     * execute text based on tag value and "# @tag space-demimited-tag-list" tag comment, @see --tag for more details
     */
    static function tag($v) {
        if ($v === true) {
            $e = [i('out'), 'e'];
            $e("{head}STest --tag=\"...\" option{/}\n");
            $e("comma separated list of tag groups, test will be executed if it matches any group\n");
            $e("{blue}tag1,tag2,tag3{/} - execute test if it have tag1 or tag2 or tag3\n");
            $e("{blue}tag1 tag2,tag3{/} - execute test if it have (tag1 and tag2) or tag3\n");
            $e("{blue}-tag1{/} - execute test if it does NOT have tag1\n");
            $e("{blue}tag1 -tag2{/} - execute test if it have tag1 and does not have tag2\n");
        }
        return "";
    }

    /**
     * debug: show parsed arguments as json
     */
    static function debug_args() {
        echo json_encode(['args' => STest::$ARG, 'tests' => STest::$TESTS], JSON_PRETTY_PRINT)."\n";
    }

    /**
     * Debug test - some methods will provide additional information
     */
    static function debug() {}

} // class STest_Global_Commands

/**
 *
 * STest --$option File.stest
 *
 */
class STest_File_Commands {

    // static function $Option(ParsedTest $T, $option_value)


    /** default action:
      * perform testing, generate and save new results
      * -v | --verbose  - show statements being executed
      * -g | --generate - regenerate test, ignore errors
      */
    static function test(array /* parsed-test */ $__TEST) {
        $__t = (object) // dummy object used to hide variables
            ['T' => $__TEST,
            'fail' => 0, 'new' => 0, 'tests' => 0,
            'start' => microtime(1),
             'filename_shown' => 0,
            'filename' => realpath(i('stest')->file),
            ];

        $ARG = \STest::$ARG; // Visible inside TEST, can be modified inside test
        // show filename above first error
        $__err = function($s, $reason = "failed") use ($__t) {
            if (! $__t->filename_shown) {
                i('out')->err("*** {alert}%s %s{/}\n", $__t->filename, $reason);
                $__t->filename_shown = 1;
            }
            i('out')->err($s."\n");
        };

        $__tester = function(string &$expected, $got, $line, $code) use ($__err, &$ARG, $__t) {
            $showError = function($err) use ($line, $code, $__err, $ARG, $__t) {
                // FAILED TEST
                $__t->fail++;
                $__err("{alert}L$line{/}: {red}$code{/}\n $err");
                if (@$ARG['first_error'])
                    throw new StopException("Stopping on first error");
            };
            $exp = trim($expected, ";");
            if (@$exp{0} == '~') { // ~XXX special tests
                if ($err = self::_custom_result_syntax($exp, $got))
                    $showError($err);
                return;
            }
            $got = x2s($got, @$ARG['sort']);   // result-as-php-code-string
            $got = str_replace("\n", "\n    ", $got); // result identation
            if ($exp == $got)
                return;
            if (@$ARG['generate']) { // generate all results in test
                $expected = $got.";"; // save corrected result
                $__err("CODE: {bold}{red}L$line{/}: $code");
                if ($exp) {
                    $__t->fail++;
                    $__err(" old: {red}$exp{/}");
                } else {
                    $__t->new++;
                }
                $__err(" new: {blue}$got{/}");
                return;
            }
            $code = str_replace("\n", " ", $code);
            $code = preg_replace("/\s+/", " ", $code);
            if (! $expected) { // NEW TEST - generate result
                $__t->new++;
                $expected = $got.";"; // save generated result
                $__err("{bold}{blue}L$line{/}: $code");
                $__err(" got: {blue}$got{/}");
                return;
            }

            $showError("expect: {cyan}".$exp."{/}\n  got:   {red}$got{/}");
        };

        @$ARG['verbose'] && i('out')->e("*** {head}%s{/}\n", $__t->filename);

        try {
            //
            // MAIN TEST LOOP BEGIN ------------------
            //
            foreach ($__t->T as &$__line__tp_v_r) { // [ln, [tp, v, r]]
                [$__line, [$__type, $__code]] = $__line__tp_v_r;
                if ($__type == 'expr') {
                    try {
                        @$ARG['verbose'] && i('out')->e("{grey}%s{/}\n", $__code);
                        eval($__code);
                    } catch(StopException $__ex) {
                        throw $__ex;
                    } catch(\Exception $__ex) {
                        throw new ErrorException("Unexpected exception ".get_class($__ex)." ".$__ex->getMessage());
                    }
                } // if-expr
                if ($__type  == "test") {
                    $__t->tests++;
                    if ($__code{0}.$__code{1} === '? ') { // "? code" - inspect code
                        $__code = "STest::inspect(".trim(substr($__code, 2), ";").");";
                    }
                    if ($__code{0} === '!') { // "! code" - turn ON stop-on-first-error for this line. ("!" symbol is ignored)
                        if (! @$ARG['first_error'])
                            $ARG['first_error'] = 'temp';
                        $__code = substr($__code, 1);
                    }
                    try {
                        if ($__error = Error::get())
                            $__err("-- {alert}stest-internal unexpected error{/}: ". x2s($__error));  // this should NOT happend
                        $__code_ = self::_custom_test_syntax($__code); // so far only web tests have custom syntax
                        @$ARG['verbose'] && i('out')->e("{cyan}%s{/}\n", $__code);
                        ob_start();
                        $__rz = eval("return $__code_");
                        $__out = ob_get_clean();
                        if ($__out)
                            $__rz  = [$__rz, '$' => $__out];
                        if ($__error = Error::get())
                            $__rz = ['error' => $__error];
                    } catch(StopException $__ex) {
                        throw $__ex;
                    } catch(\Exception $__ex) {
                        $__rz = [get_class($__ex), $__ex->getMessage()];
                    } catch(\Error $__ex) {
                        if (@$ARG['allowError']) {  // --allowError to not break on \Error Exceptions
                            $__rz = ["Error:".get_class($__ex), $__ex->getMessage()];
                        } else {
                            $class = get_class($__ex);
                            $_err = "\Error Exception: $class(\"".$__ex->getMessage()."\")";
                            $trace = self::_backtrace($__ex);
                            \STest::error($_err.($trace ? "\nTrace:\n$trace" : ""));
                        }
                    } catch(\Throwable $__ex) {
                        $__rz = ["Throwable:".get_class($__ex), $__ex->getMessage()];
                    }
                    @$ARG['verbose'] && i('out')->e("    {green}%s{/}\n", $__line__tp_v_r[1][2] /*x2s($__rz) */);
                    $__tester($__line__tp_v_r[1][2], $__rz, $__line, $__code);
                    if ('temp' === @$ARG['first_error'])
                        unset($ARG['first_error']);
                } // if-test
            }
            //
            // MAIN TEST LOOP END ------------------
            //
        } catch (StopException $__ex) { // Stop/Error/Alert
            $m = $__ex->getMessage();
            $reason = str_replace(["Exception", "stest\\"], "", get_class($__ex)); // Stop/Error/Alert
            if ($reason === "Stop")
                i('out')->e("*** {bg_blue}{white}{bold}%s{/}\n {warn}Test stopped{/} at line $__line : $m\n", $__t->filename);
            else
                $__err("{alert}$reason{/} at line $__line: $m\n    {cyan}$__code{/}");
            i('reporter')->$reason($__t->filename, ['message' => $m]);
            return;
        }

        $dur = microtime(1) - $__t->start;
        $stat = "tests: ".$__t->tests;
        if ($dur > 0.1) // require at least 0.1 sec
            $stat .= " (".sprintf("%0.2f", $dur)."s)";
        if ($new = $__t->new)
            $stat .= ", {blue}{bold}new: $new{/}";
        if ($fail = $__t->fail) {
            $__err("{alert}>{/} $stat, {warn}failed: $fail{/}");
        } else {
            i('out')->e("*** {head}%s{/} $stat\n", $__t->filename);
        }

        // save test when '--generate' option, or new items were added and no tests failed
        if (($__t->new && ! $__t->fail) || @$ARG['generate'])
            self::save($__t->T);

        $how = $fail ? "fail" : "success";
        i('reporter')->$how($__t->filename, array_filter(['tests' => $__t->tests, 'new' => $__t->new, 'fail' => $__t->fail]));
    }

    // get useful part of exception's backtrace as string
    // TODO - provide better backtrace - use getTrace
    static private function _backtrace(\Throwable $ex) : string {
        $trace = $ex->getTraceAsString();
        $pos = strpos($trace, '/STest.php'); // find and cut part with STest backtrace
        $trace = substr($trace, 0, $pos);
        $pos = strrpos($trace, "\n");
        return substr($trace, 0, $pos);
    }


    /**
     * INTERNAL
     * custom (non php compatible) result syntax
     * custom results looks like  "~ ..."
     *
     * TEST
     *     ~               // non-empty string
     *     ~~              // non-empty result: if (!$x) FAIL;
     *     ~ "SubString"   // have substring
     *     ~ Class         // is-descentant
     *     ~ []            // is-array
     *     ~ [$a, $b, ..]  // is $a and $b are in resulting array
     *     ~ [key=>val]    // is in resulting array
     *     ~ /regexp/x     // is result matching regexp
     *     ~ method args   // special method (todo)
     *
     * @see examples/special-tests.stest
     */
    static private function _custom_result_syntax(string $exp, $got) /*: ?string*/ { # error | null
        if (strpos($exp, "\n")) { // several tests
            foreach(explode("\n", $exp) as $exp) {
                if ($r = self::_custom_result_syntax($exp, $got))
                    return $r;
            }
            return;
        }
        # echo "<< $exp >>\n";
        $x = trim($exp, "~ ");
        $err = ""; // test-error found
        if (! $x) { // "~" case = IS NOT EMPTY STRING CASE
            if (trim($exp) == '~~')
                return $got ? null : "non empty result expected";
            if (! is_string($got))
                return "string expected got:".gettype($got);
            if (! $got)
                return "non empty string expected got: empty string";
            return;
        }
        switch ($x{0}) {
            case '"': // "substring"
                if (! is_string($got))
                    return "string expected got:".gettype($got);
                $x = eval("return $x;");
                if (strpos($got, $x) !== false)
                    return;
                return "substring-expected: '{cyan}$x{/}'";
            case '[': // in-array
                $x = eval("return $x;");
                if (! is_array($got))
                    return "array expected";
                foreach ($x as $k => $e) { // e - element
                    if (! is_int($k)) {
                        if (@$got[$k] == $e)
                            continue;
                        return " array-element {cyan}\"$k\" => ".x2s($e)."{/} expected, got ".x2s(@$got[$k]);
                    }
                    if (! in_array($e, $got))
                        return " array-element-expected: {cyan}".x2s($e)."{/}";
                }
                break;
            case '/': // regexp
                if (! is_string($got))
                    return "regexp match - string expected got:{cyan}".x2s($got)."{/}";
                if (! preg_match($x, $got))
                    return " regexp match expected {cyan}".x2s($x)."{/}";
                break;
            default:  // ~ ClassName
                if (strpos($x, " "))
                    return "{cyan} unsupported test: '$x'{/} ";
                if (! is_object($got))
                    return "Object expected";
                if (! is_a($got, $x))
                    return "{cyan}$x{/} descendant object expected, got {red}".get_class($got)."{/} object";
                if (is_a($got, $x))
                    return; // is subclass
        }
        return $err;
    }

    /**
     * INTERNAL
     * custom (non php compatible) test syntax
     * @see so far only web tests uses custom test syntax examples/web-tests.stest
     */
    static private function _custom_test_syntax(string $test) : string { # modified code
        if (! $test)
            throw new ErrorException("Empty Test");
        # /$path  == Webtest::GET
        if ($test{0} == '/') {
            $test = trim($test, ";");
            @[$path, $args] = explode(" ", $test, 2);
            if (! $args)
                $args = "[]";
            return "\stest\I('webtest')->get('$path', $args);";
        }
        # POST /$path  == Webtest::GET
        if (! strncasecmp($test, "post /", 5)) {
            $test = trim(substr($test, 5), ";");
            @[$path, $args] = explode(" ", $test, 2);
            if (! $args)
                $args = "[]";
            return "\stest\I('webtest')->post('$path', $args);";
        }


        return $test;
    }

/*
    static function webtest_get(string $url, array $args=[]) {
        return "web-test";
    }
    */

    /**
     * `cat` processed test to stdout (add missing semicolons, correct identation)
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

    /**
     * save corrected test (missing ";" added, identation fixed)
     */
    static function save($T) {
        # echo json_encode($T);
        $filename = i('stest')->file;
        i('out')->e("*** {head}%s{/} saved\n", $filename);
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

    /**
     * Execute test that matches given tags description, @see --tag for details
     */
    static function tag($v) {

    }

    /**
     * show parsed unprocessed test text
     */
    static function debug_test($T) { # echo internal test presentation
        foreach ($T as [$ln, $tv]) {
            echo "$ln: ", json_encode($tv, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
        }
    }


} // class STest_File_Commands

/**
 * Error Handler
 */
class Error {  // error handler

    PUBLIC static $error_reporting = 0;      // bit-mask to suppress errors

    private static $err = []; // php-errors from error handler - use get() to read

    // return error (if any), clean up error info
    static function get() { # string or array with errors
        $e = self::$err;
        self::$err = [];
        return sizeof($e) == 1 ? $e[0] : $e;
    }

    PUBLIC static function suppress_notices() { self::$error_reporting |= E_NOTICE; }
    PUBLIC static function suppress_warnings() { self::$error_reporting |= E_WARNING; }
    // if you want to suppress something else - change Error::$error_reporting

    // Error::handler
    static function handler($level, $message, $file, $line, $context) {
        // you can hide notices and warnings with @
        // you can't hide errors
        if (!error_reporting() && ($level == E_WARNING || $level == E_NOTICE))
            return;
        if ($level & self::$error_reporting) // allow people to debug ugly code
            return;
        static $map=array(
                          E_NOTICE       => 'NOTICE',
                          E_WARNING      => 'WARNING',
                          E_USER_ERROR   => 'USER ERROR',
                          E_USER_WARNING => 'USER WARNING',
                          E_USER_NOTICE  => 'USER NOTICE',
                          E_STRICT       => 'E_STRICT',
                          E_DEPRECATED   => 'E_DEPRECATED',
                          );
        $type = ($t = @$map[$level]) ? $t : "ERROR#$level";
        $e = "$type: $message";
        if (substr($file, 0, strlen(__FILE__)) != __FILE__)
            $e = [$e, $file, $line];
        array_push(self::$err, $e);
    }

} // class Error



class Exception extends \Exception {}

class SyntaxErrorException extends Exception  {}

class StopException extends Exception  {}   // \STest::stop("message")   -- Successfully Stop Test, ignore rest of the test
class ErrorException extends StopException {}   // \STest::error("message")  -- UNSuccessfully Stop Test, ignore rest of the test
class AlertException extends StopException {}   // \STest::alert("message")  -- UNSuccessfully Stop Test, send an alert, ignore rest of the test



