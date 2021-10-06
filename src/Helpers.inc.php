<?php

/**
 * Helper classes for spartan test
 * included from stest.php
 */

namespace stest\helper;

include __DIR__."/Curl.inc.php";

class InstanceConfig {

    // InstanceName => Class
    // InstanceName => [Class, method]
    static $config = [];

    static $baseConfig = [];

    // InstanceName | InstanceName:params => instance
    static $I = [];

    static function debug($message, $level = 1) {
       (\STest::$ARG['debug'] ?? 0) >= $level && print($message."\n");
    }
    static function init($file = false) {
        $base = __DIR__."/config.json";
        if (! self::$baseConfig)
            self::$baseConfig = json_decode(file_get_contents($base), 1);
        if (! self::$baseConfig)
            throw new \ErrorException("Can't parse config '$base'");

        self::$config = self::$baseConfig;
        if (! $file)
            return;
        $DIR = $dir = realpath($file);
        while (($dir = dirname($dir)) != '/') {
            self::debug("Loading configs: $dir");
            foreach (["stest-config.local.json", "stest-config.json"] as $fn) {
                $f = "$dir/$fn";
                if (! file_exists($f))
                    continue;
		self::debug(" - $f", 2);
                $d = json_decode(file_get_contents($f), 1);
                if (is_null($d))
                    throw new \ErrorException("Can't parse config '$f'", 1);
                self::$config = array_replace_recursive(self::$config, $d);
            }
        }
        // lookup init file, include it
        self::_include_init($DIR, self::$config['init']);
    }

    // include init file
    // absolute path: include right away
    // relative path: look for file in current, parent directories, include first found
    static function _include_init($dir, $file) {
        if (! $file)
            return;
        if ($file[0] === '/') {
            self::debug("including $file");
            include_once $file;
            return;
        }
        while (($dir = dirname($dir)) != '/') {
            $f = "$dir/$file";
            if (! file_exists($f)) {
	        self::debug(" - missing $f", 2);
                continue;
            }
	    self::debug(" - $f");
            include_once $f;
            break;
        }
    }
}



/**
 * Various helper methods
 */


// Parse command line arguments into options and arguments (~ http://tldp.org/LDP/abs/html/standard-options.html)
// ./php-script -abc --d --c="VALUE" test1 test2
// -ab           is ['a' => true, 'b' -> true]
// --ab          is ['ab' => true]
// --ab="value"  is ['ab' => value]
// --ab=value    is ['ab' => value]
// --            is READ argument-list from STDIN
// @return : [ ['option' => $value, ...], ['arg1', 'arg2', ...] ]
function parseArgs(array $argv) : array { # [$options, $args]
    $options = [0 => []];
    $args = [];
    array_shift($argv);
    foreach ($argv as $a) {
        if ($a[0] !== '-') {
            $args[] = $a;
            continue;
        }
        if (strlen($a)<2) {
            echo "incorrect parameter: $a\n";
            exit(1);
        }
        // -abc
        if ($a[1] !== '-') { // -ab == ['a' => true, 'b' -> true]
            if (strpos($a, "=")) {
                echo "incorrect argument: $a\nuse --name=value instead";
                exit(1);
            }
            foreach (range(1, strlen($a)-1) as $p)
                $options[$a[$p]] = true;
            continue;
        }
        // "--"
        if ($a == "--") {
            while ($test = fgets(STDIN))
                $args[] = trim($test);
            continue;
        }
        $k = substr($a, 2); // cut leading "--"
        // --name
        // --name="value"
        // --name=value
        $v = true;
        if (strpos($k, "=")) {
            [$k, $v] = explode("=", $k);
            $v = trim($v, '"');
        }
        $options[$k] = $v;
    }

    return [$options, $args];
}

// anything to ~ PHP string
function x2s(/* mixed */ $x, $sort_keys = 0, int $deep=0) : string {
    if ($deep > 10)
        return "'nesting too deep!!'";
    if (is_string($x)) {
        #return "\"".str_replace('"', '\\"', $x)."\"";
        return var_export($x, true);
    }
    if ($x === NULL)
        return "NULL";
    if (is_bool($x))
        return $x ? "true" : "false";
    if (is_object($x)) {
        if (method_exists($x, '__toString'))
            return var_export(get_class($x).":".$x, 1);
        return var_export("Instance(".get_class($x).")", 1);
    }
    if (is_int($x))
        return $x;
    if (is_float($x))
        return sprintf("%G", $x); // short presentation of float
    if (! is_array($x))
        return "\"$x\""; // to_string
    if (($cnt = count($x)) > 30)
        return "\"... $cnt items\"";
    if ($sort_keys)
        ksort($x);
    $t = [];
    $i = 0;
    foreach ($x as $k => $v) {
        $q = ($i === $k) ? "" : "\"$k\"=>";
        $i++;
        $t[] = $q.x2s($v, $sort_keys, $deep+1);
    }
    return "[".join(", ", $t)."]";
}



// --------------- --------------- --------------- --------------- --------------- --------------- --------------- --------------- ---------------

// CLASSES


/**
 * spartan test parser based on iterator with put-back
 */


class Parser {

    /**
     * Return:
     *
     *   [line, [lexem-type, value]]
     *
     * Lexem types:
     * - comment  - php comment
     * - expr     - expression
     * - test     - test
     * - result   - test-result (null if result is not in file)
     * - br       - empty line
     * - test-comment  - "test; # test-comment", always followed by test
     *
     * $source_file = join("\n", array_values($lexems) );
     */
    static function Reader(string $file)  { # Array or Generator | Exception
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false)
            throw new \InvalidArgumentException("file does not exists");

        // file lines starts with "1"
        $lines = array_merge([""], $lines);
        unset($lines[0]);

        // $ln - line number
        // $l  - line text
        // $I  - iterator
        // return value: [$ln, [type, $l]] | null

        // Single line lexems
        // - empty line
        // - # comment
        // - // comment
        $single_line = function ($ln, $l, $I) {
            $l = trim($l);
            if (! $l)
                return [$ln, ['br', '']];
            if ($l[0] == '#' || substr($l, 0, 2) == '//')
                return [$ln, ['comment', $l]];
            if ($l == '<?php') // treat as comment
                return [$ln, ['comment', $l]];
        };

        // Multi Line Comments
        // /* ... */
        $multi_line_comment = function ($ln, $l, $I) {
            $tl = trim($l); // trimmed line
            if (substr($tl, 0, 2) != '/*')
                return;
            $l = trim($l); // ident first line
            if (substr($tl, -2) == '*/')   // /* one-line */
                return [$ln, ['comment', $l]];
            while ([$nl, $s] = $I->getKV()) {
                $l .= "\n".$s;
                $tl = trim($s);
                if (substr($tl, -2) == '*/')
                    break;
            }
            return [$ln, ['comment', $l]];
        };

        // PHP Expression
        // "; ..." and all idented lines after this
        $php_expr = function ($ln, $l, $I) {
            if ($l[0] != ';')
                return;
            while ([$nl, $s] = $I->getKV()) {
                if (!$s || $s[0] !== ' ') { // empty line or non idented line
                    $I->putKV([$nl, $s]);
                    break;
                }
                # $l .= "\n  ".trim($s); // fix formatting to 2 spaces
                $l .= "\n".$s; // keep expression formatting as is
            }
            $l = trim($l);
            // auto-fix missing ";"
            if ($l[-1] !== ';')
                $l .= ';';
            return [$ln, ['expr', $l]];
        };

        // TEST expression (many lines idented by (1..3) spaces), then (optional)result (idented by 4 spaces)
        $test = function ($ln, $l, $I) {
            if ($l && $l[0] == ' ')
                return;
            $rz = ""; // result
            while ([$nl, $s] = $I->getKV()) { // nl - next-line#
                if (!$s || $s[0] != ' ') {
                    $I->putKV([$nl, $s]);
                    break;
                }
                if (substr($s, 0, 4) === '    ') { // 4 spaces or tab
                    $rz = $s;
                    while ([$nl, $s] = $I->getKV()) { // multi-line result set
                        if (substr($s, 0, 4) === '    ') {
                            $rz .= "\n$s"; // substr($s, 4);
                        } else {
                            $I->putKV([$nl, $s]);
                            break;
                        }
                    }
                    break;
                }
                if (substr($s, 0, 3) === '   ') { // 3
                    throw new \stest\SyntaxErrorException("Syntax Error on Line $ln: '$s'.\n".
                        " Test-Result should be idented by 4 spaces\n".
                        " Multi-line test should be idented by 2 spaces");
                }
                $l .= "\n".$s;  // spacing less than 4: multi-line test
            }
            $l = trim($l);
            $rz = trim($rz);
            // auto-fix missing ";"
            if ($l[-1] !== ';')
                $l .= ';';
            if ($rz && $rz[-1] !== ';')
                $rz .= ';';
            return [$ln, ['test', $l, $rz]];
        };

        $unknown = function ($ln, $l, $I) {
            throw new \stest\SyntaxErrorException("Syntax Error on Line $ln: '$l'");
        };

        return Iterator_Put::i($lines)->processKV([$single_line, $multi_line_comment, $php_expr, $test, $unknown]);
    }

}


/**
 * Iterator with Put-item-back support
 *
 * Usage:
 *
 *   $I = Iterator_Put::i(Range(1, 20));
 *   foreach ($I as $k => $v) {
 *       if (case1) {
 *           while ($kv = $I0->getKV()) {
 *               if (check_kv()) {
 *                   ...
 *               } else {
 *                   $I->putKV($kv);  // put KV back
 *                   break;
 *               }
 *           }
 *
 *       }
 *
 *   }
 *
 *  Known limitation / issue:
 *    When you put item before reading from iterator, item is inserted at 2nd position.
 *
 *
 */
class Iterator_Put implements \Iterator  {

    static function i(/* Array|Traversable */ $a) { # Instance
        return new self($a);
    }

    public $buffer = []; // array of k => v
    public $G;           // Generator
    private $init = 0;


    function __construct($a) {
        $this->G = $this->generator($a);
    }

    // Put [$key => $value]
    public function put(array $kv) {
        $v = reset($kv);
        $this->putKV([key($kv), $v]);
    }


    // Get [k => v] | null
    public function get() { #  [k => v] | null
        $r = $this->getKV();
        return is_null($r) ? null : [$r[0] => $r[1]];
    }

    // Put [$key, $value]
    public function putKV(array $kv) {
        if (! $this->G->valid())
            throw new \Exception("Can't put");
        $this->buffer[] = [$kv[0], $kv[1]];
    }


    // Get next value as [k, $v]
    public function getKV() { #  [k, v] | null
        if ($this->init)      // trick to allow to put items after quering last element
            $this->G->next();
        else
            $this->init = 1;
        if (! $this->G->valid())
            return;
        $r = [$this->G->key(), $this->G->current()];
        return $r;
    }

    // Put Value (key is 0)
    public function putV($value) {
        $this->putKV([0, $value]);
    }

    // Get Value. ignore key
    public function getV() {
        $kv = $this->getKV();
        return $kv[1];
    }

    /**
     * Process Iterator with a set of handlers: function($k, $v, $I)
     *
     * handler should return:
     *  1. null          >> proceed to next handler
     *  2. [key, value]  >> add to result, start processing next-item with 1st handler
     *  3. []            >> item handled, do not add to result, start processing next-item with 1st handler
     *
     *  $final_handler - callback or ":predefined-handler"
     *
     *  We provide 3 default final handlers:
     *   :alert  : throw an exception if item is not processed by any of handlers
     *   :keep   : keep unprocessed items as is
     *   :remove : throw away unprocessed items (same as "" final handler)
     *
     */
    function process(array $handlers, $final_handler = ":alert") : array {
        if ($final_handler) {
            if ($final_handler[0] == ':')
                $final_handler = "Iterator_Put::process_".substr($final_handler, 1);
            $handlers[] = $final_handler;
        }
        $r = [];
        while ($kv = $this->getKV()) {
            foreach ($handlers as $h) {
                $t = $h($kv[0], $kv[1], $this);
                if (is_null($t))
                    continue;
                if (! is_array($t))
                    throw new \UnexpectedValueException("null or array must be returned by handler");
                if ($t)
                    $r[$t[0]] = $t[1];
                break;
            }
        }
        return $r;
    }

    /**
     * @see  process
     * Same as process, but result is array of [key, value]
     */
    function processKV(array $handlers, $final_handler = ":alert") : array {
        if ($final_handler) {
            if ($final_handler[0] == ':')
                $final_handler = "Iterator_Put::process_".substr($final_handler, 1);
            $handlers[] = $final_handler;
        }
        $r = [];
        while ($kv = $this->getKV()) {
            foreach ($handlers as $h) {
                $t = $h($kv[0], $kv[1], $this);
                if (is_null($t))
                    continue;
                if (! is_array($t))
                    throw new \UnexpectedValueException("null or array must be returned by handler");
                if ($t)
                    $r[] = $t;
                break;
            }
        }
        return $r;
    }

    static function process_alert($k, $v, $I) {
        throw new \InvalidArgumentException("item not processed. key: $k");
    }

    static function process_keep($k, $v, $I) {
        return [$k, $v];
    }

    static function process_remove($k, $v, $I) {
        return [];
    }

    // ------------------ ------------------ ------------------ ------------------ ------------------
    // INTERNAL

    protected function generator(/* Array|Traversable */ $a) { # next value | NULL
        foreach ($a as $k => $v) {
            while ($b = array_pop($this->buffer)) {
                yield $b[0] => $b[1];
            }
            yield $k => $v;
        }
        while ($b = array_pop($this->buffer)) {
            yield $b[0] => $b[1];
        }
    }


    // Interface Iterator implementation
    public function getIterator() {
        return $this->G;
    }

    public function rewind() {
        if ($this->init)
            $this->G->next();  // foreach after we call get
    }

    public function current() {
        return $this->G->current();
    }

    public function key() {
        return $this->G->key();
    }

    public function next() {
        return $this->G->next();
    }

    public function valid() {
        return $this->G->valid();
    }

} // class Iterator_Put


/**
 * Linux console colored output to stdout and stderr
 *
 * Console::e("{bold}{red}im bold and red{/}")     << STDOUT
 * Console::err("{bold}{red}im bold and red{/}")   << STDERR
 * Console::s($text, "style") ~ Console::e("{style}").$text.Console::e("{/}")
 *
 */
class Console {

    // http://en.wikipedia.org/wiki/ANSI_escape_code
    // man 5 termcap
    // ESC sequences


    static $style =  [
        // styles
        // italic and blink may not work depending of your terminal
        'bold'      => "\033[1m",
        'dark'      => "\033[2m",
        'italic'    => "\033[3m",
        'underline' => "\033[4m",
        'blink'     => "\033[5m",
        'reverse'   => "\033[7m",
        'concealed' => "\033[8m",
        // foreground colors
        'black'     => "\033[30m",
        'red'       => "\033[31m",
        'green'     => "\033[32m",
        'yellow'    => "\033[33m",
        'blue'      => "\033[34m",
        'magenta'   => "\033[35m",
        'cyan'      => "\033[36m",
        'white'     => "\033[37m",

        #\e[${attr};38;05;${code}m 145
        'grey'     => "\033[0;38;05;145m",
        'bgrey'     => "\033[1;38;05;145m",  // bold+grey

        // background colors
        'bg_black'   => "\033[40m",
        'bg_red'     => "\033[41m",
        'bg_green'   => "\033[42m",
        'bg_yellow'  => "\033[43m",
        'bg_blue'    => "\033[44m",
        'bg_magenta' => "\033[45m",
        'bg_cyan'    => "\033[46m",
        'bg_white'   => "\033[47m",

        '/' => "\033[0m",  // TURN OFF all styles

        'clear' => "\033[2J\033[1;1H",

        // functionality -  colors

        'head'    => "\033[42m\033[1m",  // header#1 : yellow bold on green
        'info'     => "\033[0;38;05;145m",
        'warn'    => "\033[31m\033[1m",  // red + bold
        'alert'    => "\033[41m\033[1m\033[33m", // yellow bold on red
    ];


    // Colorify output:
    // @param $s    - sprintf pattern with "{...}" ecapes
    // @param $args - sprintf arguments
    // Ex:  Console::e("{red}{bg_cyan}%s#{bold}{underline}%0.2f{/}", "test", M_PI)
    static function e(string $s, ...$args) {
        echo self::r($s, $args);
    }

    static function err(string $s, ...$args) {
        fwrite(STDERR, self::r($s, $args));
    }

    // Replace "{...}" sequences
    // apply sprintf
    /* protected */ static function r(string $s, array $args) {
        $s = preg_replace_callback("!{([a-z_/]+)}!s", [get_called_class(), "r_callback"], $s);
        return $args ? sprintf($s, ...$args) : $s;
    }

    // r("...") callback
    static function r_callback($match) {
        if ($m = self::$style[$match[1]]??0)
            return $m;
        return $match[0]; // original !!
    }


    // i([color, silent, syslog])
    static function I(array $config) { # Instance
        @['color' => $color, 'silent' => $silent, 'syslog' => $syslog] = $config;
        $m = (int)$silent + 2*(int)$color + 4*(int)$syslog;
        $map = [0 => 'ConsoleMono',
                1 => 'ConsoleMonoErrorOnly',
                2 => 'Console',
                3 => 'ConsoleErrorOnly',
                4 => 'ConsoleSyslog',
                5 => 'ConsoleSyslogErrorOnly',
                6 => 'ConsoleSyslog',           // ignore color
                7 => 'ConsoleSyslogErrorOnly',  // ignore color
                ];
        $class = "stest\\helper\\".$map[$m];
        return new $class();
    }

}

// suppress non stderr
class ConsoleErrorOnly extends Console {
    static function e(string $s, ...$args) { }
}

/**
 *
 * No colors console (see Console)
 *
 */
class ConsoleMono extends Console {

    // r("...") callback
    static function r_callback($match) {
        if ($m = @self::$style[$match[1]])
            return "";
        return $match[0]; // original !!
    }

}

// suppress non stderr
class ConsoleMonoErrorOnly extends ConsoleMono {

    static function e(string $s, ...$args) { }

}

class ConsoleSyslog extends ConsoleMono {

    static function e(string $s, ...$args) {
        Alerter::syslog(self::r($s, $args), LOG_NOTICE);
    }

    static function err(string $s, ...$args) {
        Alerter::syslog(self::r($s, $args), LOG_WARNING);
    }

}

class ConsoleSyslogErrorOnly extends ConsoleSyslog {
    static function e(string $s, ...$args) {}
}

// -------------------------
// Documentor
//


/**
 * stripped down Parf's PHP-DOC/RUN
 */
class Documentor {

    static function classDoc($class) { # ["" => classDoc, "method" => "MethodDoc"]
        $D = []; // class doc + all method docs
        $rc = new \ReflectionClass($class);
        $doc = function(/* Reflection$X */  $m) : string { # clean doc
            $d = $m->getDocComment();
            $d = str_replace(["/**", "*/"], "", $d);
            return trim(preg_replace("!^[/ ]+\* ?!m", "", $d));
        };
        $D["@"] = $doc($rc);
        foreach ($rc->getMethods() as $m) {
            $D[$m->name] = $doc($m);
        }
        return $D;
    }

}

/**
* Reporter
* extend this class and specify reporter in config: "reporter" : "ReporterClass"
*
* receive test result notifications: success/fail, STest::stop/error/alert
*
*/
class Reporter {

    /**
     * called upon successful test execution
     */
    function success(string $test, array $stats = []) {
#        echo __METHOD__."(".x2s(func_get_args()).")\n";
    }

    /**
     * STest::stop endpoint
     * default: considered as success
     */
    function stop(string $test, array $stats = []) {
        $this->success($test, $stats);
    }

    /**
     * called upon UN-successful test execution (at least one test failed)
     */
    function fail(string $test, array $stats = []) {
#       echo __METHOD__."(".x2s(func_get_args()).")\n";
    }

    /**
     * STest::error endpoint
     * stats['message'] contains message
     * default: considered as fail
     */
    function error(string $test, array $stats = []) {
        $this->fail($test, $stats);
    }

    /**
     * case 1: STest::alert endpoint
     * case 2: $ARG['alert-on-fail'] used and test failed
     * stats['message'] contains message
     */
    function alert(string $test, array $stats = []) {
#        echo __METHOD__."(".x2s(func_get_args()).")\n";
    }

}

/**
 * Alerter - connectivity with other systems
 */
class Alerter {


    static function jsonPost(string $url, array $kv) {
        $c = curl_init($url);
        curl_setopt($c, CURLOPT_HEADER, false);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($c, CURLOPT_HTTPHEADER, ["Content-type: application/json"]);
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($kv));
        $r = curl_exec($c);
        $status = curl_getinfo($c, CURLINFO_HTTP_CODE);
        if ( $status != 201 && $status != 200 ) // 200 & 201 = OK
            throw new \RuntimeException("Error: call to URL $url failed with status $status, response $r, curl_error " . curl_error($c) . ", curl_errno " . curl_errno($c));
        curl_close($c);
        return json_decode($r, 1);
    }

    static function post(string $url, array $kv) {
        $c = curl_init($url);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, $kv);
        $r = curl_exec($c);
        $status = curl_getinfo($c, CURLINFO_HTTP_CODE);
        if ( $status != 201 && $status != 200 ) // 200 & 201 = OK
            throw new \RuntimeException("Error: call to URL $url failed with status $status, response $r, curl_error " . curl_error($c) . ", curl_errno " . curl_errno($c));
        curl_close($c);
        return $r;
    }

    static function syslog(string $message, int $level = LOG_WARNING) {
        openlog("stest", 0, LOG_LOCAL0);
        syslog($level, $message);
        closelog();
    }

}


