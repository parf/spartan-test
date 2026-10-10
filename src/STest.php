<?php

namespace stest;

use stest\helper\InstanceConfig;

// dependency injectoion
use stest\helper\Console;

// colored output
use function stest\helper\x2s;
use function stest\helper\cut;

// var_export alike

/**
 * Spartan Test 4.x - PHP 8.5 testing framework done right
 * RTFM: README.md
 */

/**
 * todo:
 *  - use color schemes instead of actual colors - implement at least two lightBG, darkBG (three - phpStorm terminal changes some colors)
 *  - web services testing
 */

include __DIR__ . "/Helpers.inc.php";
include __DIR__ . "/Watch.inc.php";
include __DIR__ . "/State.inc.php";

// poor-man DI - Dependency Injection Container
//
// I($name)                     - get / create new named instance
// I($name, [arg1, arg2, ...])  - get / create new named-with-params instance
// I([$name, $instance], [arg1, arg2, ...])  - assign instance
function I(/*string | array */ $name, array $args = []) { # Instance
    if (is_array($name)) {
        [$name, $i] = $name;
        $k = $name . ":" . json_encode($args);
        InstanceConfig::$I[$k] = $i;
        return $i;
    }
    $k = $name . ":" . json_encode($args);
    if ($i = InstanceConfig::$I[$k] ?? 0) {
        return $i;
    }
    $class = InstanceConfig::$config[$name] ?? null; // class name
    if (!$class) {
        throw new \DomainException("No definition for instance $name");
    }
    if (is_array($class)) // Actual ClassName Provided by method
    {
        $class = $class($args);
    }
    return InstanceConfig::$I[$k] = new $class($args);
}

// ----------------------------------------
//
// PUBLIC
//

const VERSION = "4.1.0";
const DATE_BUILD = "2026-10-10";

//
// INTERNAL
//

class STest {

    private const PACKAGIST_PACKAGE = 'parf/spartan-test';
    private const PACKAGIST_METADATA_URL = 'https://repo.packagist.org/p2/parf/spartan-test.json';

    static $ARG;
    static $TESTS;
    static $FAILED = 0;

    // WEB TEST RELATED DATA
    static $DOMAIN = "";
    static $HEADERS = [];
    static $BODY = "";
    static $INFO = [];
    static $URL = "";     // last URL used, if set used as REFERRER
    static $PATH = "";    // last PATH used
    static $COOKIE = [];  // array cookie => value
    static $RESOLVE = []; // CURLOPT_RESOLVE entries ("host:port:ip") set by STest::domain(..., ip:)
    static $WebTest_TIMEOUT = 15;  // curl connect/transfer timeout (sec) for web tests; tests may override

    static function webTestTimeout(): int {
        return (int) (self::$ARG['timeout'] ?? self::$WebTest_TIMEOUT);
    }

    static $DIR;           // current directory

    // optionExpand Short to LongOption or [Option => value, ...]
    static $optionExpand = [
        // shortOption to longOption
        's' => 'silent',            // show only errors
        'q' => 'silent',            // quiet alias, matching stest-all
        'g' => 'generate',          // force test file overwriting, ignore test errors
        'S' => ['silent' => 0],     // no-silent
        'c' => 'color',             // force color
        'C' => ['color' => 0],      // force no-color
        'v' => 'verbose',           // show test lines being executed
        '1' => 'first_error',       // stop on first error in test
        'f' => 'force',             // ignore intentional STest::stop calls
        'r' => 'read_only',         // never rewrite .stest files
        'read-only' => 'read_only', // canonical spelling of -r
        'h' => 'help',              // show help
        // option to set of options
        'cron' => ['color' => 0, 'silent' => 1],   // --cron - show only errors, no colors
    ];

    // ---------------------------------------------------------------
    //
    // Static Methods
    //

    /**
     * run an *unmodified* test file at most once per period: skip it (like STest::stop)
     * when its content is unchanged since its last fully passing run less than $period ago
     * a pass records the file's sha1 in ~/.config/stest/once.json; any edit makes it run again
     * overridden by "-f", "--force", "--once=ignore"; "--once=reset" forgets the recorded pass
     * periods: "1day", "12h", "30min", "2weeks", "daily", "weekly", "hourly", seconds
     *
     * Usage:
     *   ; STest::runOnce("1day");
     * same as the tag "# @tag run-once(1day)", which skips the file before it executes
     */
    static function runOnce(string $period = "1day"): void {
        $seconds = helper\State::seconds($period);
        if ($seconds === null) {
            self::error("STest::runOnce: invalid period '$period'; expected e.g. 1day, 12h, 30min, weekly");
        }
        $file = realpath(i('stest')->file);
        self::$ONCE = $file;  // record the pass when this file finishes successfully
        if ($why = self::_onceSkip($file, $seconds)) {
            self::stop("runOnce($period): $why");
        }
    }

    static $ONCE = null;  // realpath of the running file when runOnce() let it run

    /**
     * report an unchanged failure only once per period (for cron alerting)
     * the file still runs; when it fails exactly as last time, its file is unchanged and that
     * failure was reported less than $period ago, the error output is dropped and the file
     * counts as stopped (exit 0); a new or different failure is reported, a pass clears the record
     * call it before any test line, or use the tag "# @tag fail-once(1day)". state: ~/.config/stest/fail-once.json
     *
     * Usage:
     *   ; STest::failOnce("1day");
     */
    static function failOnce(string $period = "1day"): void {
        $seconds = helper\State::seconds($period);
        if ($seconds === null) {
            self::error("STest::failOnce: invalid period '$period'; expected e.g. 1day, 12h, 30min, weekly");
        }
        self::_failOnceStart(realpath(i('stest')->file), $period);
    }

    static function _failOnceStart(string $file, string $period): void {
        $seconds = helper\State::seconds($period);
        if ((self::$FAIL_ONCE['file'] ?? null) !== $file) { // soft-regen re-runs setup lines: keep the buffer
            self::$FAIL_ONCE = ['file' => $file, 'period' => $period, 'seconds' => $seconds, 'hash' => sha1_file($file), 'settled' => false];
            static $guard = false;
            if (!$guard) {
                $guard = true;
                // a fatal error or exit() must not swallow the held-back error output
                register_shutdown_function(function () {
                    if (self::$FAIL_ONCE && !self::$FAIL_ONCE['settled']) {
                        self::$FAIL_ONCE['settled'] = true;
                        foreach (self::$FAIL_ONCE_BUF as [$s, $args]) {
                            i('out')->err($s, ...$args);
                        }
                    }
                });
            }
        }
    }

    static $FAIL_ONCE = null;     // failOnce() settings for the running file
    static $FAIL_ONCE_BUF = [];   // error output held back until the outcome is known

    // error output of a test file; held back while failOnce() is undecided
    static function _err(string $s, ...$args): void {
        if (self::$FAIL_ONCE && !self::$FAIL_ONCE['settled']) {
            self::$FAIL_ONCE_BUF[] = [$s, $args];
            return;
        }
        i('out')->err($s, ...$args);
    }

    /**
     * decide a failOnce() file: $passed true = pass, false = failure, null = neither (stopped)
     * returns a message when the failure is a repeat to keep quiet; otherwise flushes the held output
     */
    static function _failOnceSettle(string $file, array $details, string $message, ?bool $passed): ?string {
        $f = self::$FAIL_ONCE;
        if (!$f || $f['settled']) {
            return null;
        }
        self::$FAIL_ONCE['settled'] = true;
        $quiet = null;
        if ($passed === false) {
            $signature = sha1(json_encode([$details, $message], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            $last = helper\State::read('fail-once')[$f['file']] ?? null;
            $age = time() - (int) ($last['reported'] ?? 0);
            if ($last && ($last['signature'] ?? '') === $signature && ($last['hash'] ?? '') === $f['hash'] && $age >= 0 && $age < $f['seconds']) {
                $quiet = "failOnce({$f['period']}): same failure as reported " . helper\State::ago($age) . " ago";
            } else {
                helper\State::update('fail-once', function (array $data) use ($f, $signature) {
                    $data[$f['file']] = ['signature' => $signature, 'hash' => $f['hash'], 'reported' => time()];
                    return $data;
                });
            }
        } elseif ($passed === true) {
            if (isset(helper\State::read('fail-once')[$f['file']])) {
                helper\State::update('fail-once', function (array $data) use ($f) {
                    unset($data[$f['file']]);
                    return $data;
                });
            }
        }
        $buffer = self::$FAIL_ONCE_BUF;
        self::$FAIL_ONCE_BUF = [];
        if ($quiet === null) {
            foreach ($buffer as [$s, $args]) {
                i('out')->err($s, ...$args);
            }
        }
        return $quiet;
    }

    // reason to skip $file (unchanged and passed within $seconds) | null
    static function _onceSkip(string $file, int $seconds): ?string {
        $mode = self::$ARG['once'] ?? null;
        if ($mode === 'ignore' || $mode === 'reset' || (self::$ARG['force'] ?? 0)) {
            return null;
        }
        $last = helper\State::read('once')[$file] ?? null;
        if (!$last || ($last['hash'] ?? '') !== sha1_file($file)) {
            return null;
        }
        $age = time() - (int) ($last['passed'] ?? 0);
        return $age >= 0 && $age < $seconds ? "unchanged, passed " . helper\State::ago($age) . " ago" : null;
    }

    static function _onceRecord(string $file, bool $passed): void {
        helper\State::update('once', function (array $data) use ($file, $passed) {
            if ($passed) {
                $data[$file] = ['hash' => sha1_file($file), 'passed' => time()];
            } else {
                unset($data[$file]);
            }
            return $data;
        });
    }

    /**
     * intentionally skip the rest of this test file successfully
     * calls Reporter::stop() and contributes exit status 0 when no earlier test failed
     * can be overridden by "-f" or "--force"
     *
     * Usage:
     *   \STest::stop("message");            << test disable
     *   \STest::stop("message", 20170303);  << disable until 2017-03-03 ISO8601
     *
     * if (date("l") != "Monday") \STest::stop("Monday-only test");
     *
     */
    static function stop(string $message, int $until_yyyymmdd = 0): void {
        if (self::$ARG['force'] ?? 0) {
            return;
        }
        if ($until_yyyymmdd && (int)date("Ymd") >= $until_yyyymmdd) {
            return;
        }
        throw new StopException($message);
    }


    static function phpVersionPlus(string $version="8.3.0", string $message=""): void {
        if (version_compare(PHP_VERSION, $version, '<'))
            throw new StopException("php{$version}+ only: $message");
    }

    // specific MAJOR.MINOR version ONLY. e.g "8.0"
    static function phpVersion(string $version="8.3", string $message=""): void {
        [$major, $minor] = explode(".", $version);
        if (PHP_MAJOR_VERSION != $major || PHP_MINOR_VERSION != $minor)
            throw new StopException("php{$version} only: $message");
    }

    /**
     * if php 8.3 or better
     */
    static function php83plus(string $message=""): void {
        self::phpVersionPlus("8.3.0", $message);
    }


    // ONLY specific version
    static function php80(string $message=""): void {
        self::phpVersion("8.0", $message);
    }

    // ONLY specific version
    static function php83(string $message=""): void {
        self::phpVersion("8.3", $message);
    }

    /**
     * Return the newest stable Spartan Test SemVer published on Packagist.
     *
     * The optional fetcher exists for deterministic/offline callers and tests.
     * It must return the Packagist P2 JSON document as a string.
     */
    static function latestVersion(?callable $fetch = null): string {
        $metadata = $fetch ? $fetch() : self::fetchPackagistMetadata();
        if (!is_string($metadata)) {
            throw new \UnexpectedValueException("Spartan Test version metadata must be a JSON string");
        }

        try {
            $decoded = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \UnexpectedValueException(
                "Invalid Spartan Test version metadata: " . $error->getMessage(),
                0,
                $error
            );
        }

        $packages = $decoded['packages'][self::PACKAGIST_PACKAGE] ?? null;
        if (!is_array($packages)) {
            throw new \UnexpectedValueException(
                "Spartan Test version metadata does not contain " . self::PACKAGIST_PACKAGE
            );
        }

        $latest = null;
        foreach ($packages as $package) {
            $version = is_array($package) ? ($package['version'] ?? null) : null;
            if (!is_string($version)) {
                continue;
            }
            $version = preg_replace('/^v(?=\d)/', '', $version);
            if (!self::isSemver($version) || str_contains(explode('+', $version, 2)[0], '-')) {
                continue;
            }
            if ($latest === null || self::compareSemver($version, $latest) > 0) {
                $latest = $version;
            }
        }

        if ($latest === null) {
            throw new \UnexpectedValueException(
                "Spartan Test version metadata contains no stable SemVer release"
            );
        }
        return $latest;
    }

    /**
     * Return the newest version when comparing this installation with Packagist.
     */
    static function checkLatestVersion(?callable $fetch = null): string {
        $published = self::latestVersion($fetch);
        return self::compareSemver($published, VERSION) > 0 ? $published : VERSION;
    }

    /**
     * Require a minimum Spartan Test SemVer for the current test file.
     *
     * $on_fail="stop" is a successful skip and honors --force.
     * $on_fail="error" is a test failure.
     */
    static function requireVersion(string $version, string $on_fail = 'stop'): void {
        if (!self::isSemver($version)) {
            throw new \InvalidArgumentException(
                "Invalid Spartan Test SemVer requirement '$version'; expected MAJOR.MINOR.PATCH"
            );
        }
        if (!in_array($on_fail, ['stop', 'error'], true)) {
            throw new \InvalidArgumentException(
                "Invalid Spartan Test version failure mode '$on_fail'; expected stop or error"
            );
        }
        if (self::compareSemver(VERSION, $version) >= 0) {
            return;
        }

        $message = "Spartan Test $version+ required; installed " . VERSION;
        if ($on_fail === 'stop') {
            self::stop($message);
            return;
        }
        self::error($message);
    }

    private static function fetchPackagistMetadata(): string {
        if (function_exists('curl_init')) {
            $curl = curl_init(self::PACKAGIST_METADATA_URL);
            if ($curl === false) {
                throw new \RuntimeException("Unable to initialize Spartan Test version check");
            }
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FAILONERROR => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_USERAGENT => "Spartan-Test/" . VERSION,
            ]);
            $metadata = curl_exec($curl);
            if ($metadata === false) {
                throw new \RuntimeException(
                    "Unable to query Packagist for Spartan Test versions: " . curl_error($curl)
                );
            }
            return $metadata;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'header' => "User-Agent: Spartan-Test/" . VERSION . "\r\n",
            ],
        ]);
        $metadata = @file_get_contents(self::PACKAGIST_METADATA_URL, false, $context);
        if ($metadata === false) {
            throw new \RuntimeException("Unable to query Packagist for Spartan Test versions");
        }
        return $metadata;
    }

    private static function isSemver(string $version): bool {
        return (bool) preg_match(
            '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
                . '(?:-(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)'
                . '(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?'
                . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D',
            $version
        );
    }

    private static function compareSemver(string $left, string $right): int {
        return version_compare(
            explode('+', $left, 2)[0],
            explode('+', $right, 2)[0]
        );
    }


    /**
     * stop test execution, Emit Error: same level of severity as failed test
     * Usage:
     *   \STest::error("message");
     */
    static function error(/*string*/ $message) {
        if (!is_string($message)) {
            $message = var_export($message, 1);
        }
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
     * show debug messages to STDERR when --debug=$level and level<=asked-level
     */
    static function debug($message, $level = 1) {
        (self::$ARG['debug'] ?? 0) >= $level && fwrite(STDERR, $message . "\n");
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
        if ($p = $rc->getParentClass()) {
            $class .= "(" . $p->getName() . ")";
        }
        // remove common part of path
        foreach (range(0, strlen($filename)) as $i) {
            if (!isset($filename[$i]) || !isset(self::$DIR[$i]) || $filename[$i] != self::$DIR[$i]) {
                $filename = substr($filename, $i);
                break;
            }
        }
        return "$class $filename" . ($show_line ? " " . $rc->getStartLine() : "");
    }


    /**
     * set domain for web-test ($ARG['DOMAIN'])
     *   honor --domain override, supports realms
     *
     * test domain for availability:
     *     fail_action =  "stop" | "error" | "alert"
     *
     * ip: send every request for this domain to that IP address (curl --resolve);
     *     Host header, cookies and TLS SNI keep the domain name. --ip="..." overrides it
     *     \STest::domain("www.example.com", ip: "172.16.1.1");
     */
    static function domain(string $domain, string $fail_action = "error", string $ip = "") {
        self::debug(" - domain-in: $domain", 4);
        if ($t = STest::$ARG['domain'] ?? 0) { # --domain="..." - overrides all
            $domain = $t;
        }
        if (($t = STest::$ARG['ip'] ?? 0) && $t !== true) { # --ip="..." - overrides the ip: argument
            $ip = $t;
        }
        if ($ip !== "" && filter_var(trim($ip, "[]"), FILTER_VALIDATE_IP) === false) {
            self::error("STest::domain: invalid ip '$ip'");
        }
        if (strpos($domain, "//") === false) {    # //domain | scheme://domain
            $domain = "https://" . $domain;
        }   // default scheme: https
        $realm = static::_realm($domain);
        if ($realm && ! (STest::$ARG['domain'] ?? 0)) {
            $domain = static::_realmUrl($domain, $realm);
        }
        STest::$RESOLVE = $ip === "" ? [] : [\hb\Curl::resolveEntry($domain, $ip)];
        \hb\Curl::test($domain, $fail_action, $ip); // check if web-server is up
        STest::$DOMAIN = $domain;
        self::debug(" - domain: $domain", 2);
    }

    /**
     * WebTest
     * Run XQuery on recently Curled web-page content
     * Ex:
     * /path
     *     ~;
     * \STest::xq("/book/[@author='Dockins']");
     * @param bool $as_array - see _xq method
     */
    static function xq(string $xpath, $as_array = false) {
        return static::_xq(static::$INFO['body'], $xpath, $as_array);
    }

    /**
     * Run XQuery on $html
     * as_array == false - return matches as combined HTML
     * as_array == true  - return array of HTML
     * as_array == "dom" - return https://www.php.net/manual/en/domxpath.query.php result
     */
    static function _xq($html, $query, $as_array = false) {
        $dom = new \DomDocument();
        $dom->strictErrorChecking = false;
        $dom->substituteEntities = false;
        $dom->loadHTML($html);
        $xpath = new \Domxpath($dom);
        $nodeList = $xpath->query($query);
        if ($as_array === 'dom') {
            return $nodeList;
        }
        if (!$as_array) {
            $r = "";
            foreach ($nodeList as $domElement)
                $r .= $dom->saveXML($domElement) . "\n";
            $r = trim($r);
        } else {
            $r = array();
            foreach ($nodeList as $domElement)
                $r[] = trim($dom->saveXML($domElement));
        }
        return $r;
    }

    // follow a-href on a recently spidered page by "text"
    // \STest::follow("page 1");
    static function follow(string $text) /* : array|string */ {
        // first link only !!
        $links = self::xq("//a[text()='$text']", "dom");
        if (! ($links[0]??0))
            return "link with text=\"$text\" not found";
        $url = $links[0]->getAttribute("href");
        return \stest\I('webtest')->get($url);
    }
    /**
     * build realm url - method for overloading
     * default: scheme://$realm.$domain
     */
    static function _realmUrl(string $domain, string $realm): string {
        self::debug(" - realm: $realm($domain)", 3);
        $r = parse_url($domain);
        if ($m = InstanceConfig::$config['realmUriMethod'] ?? 0) {
            $args = $r + ['realm' => $realm];
            $domain = $m($args); // parsed URI see @parse_url
            self::debug(" - using realmUriMethod: $m(".x2s($args).") => $domain", 4);
        } else {
            $domain = $r['scheme'] . "://" . $realm . "." . $r['host']; // default realm is: scheme://$realm.$domain
        }
        return $domain;
    }

    /**
     * get current realm - method for overloading
     * default search order:
     *   cli option: --domain=...
     *   cli option: --realm        << turn off realm detection (aka test production)
     *   cli option: --realm=...
     *   shell variable: `STEST_REALM`
     *   realmDetectMethod callback from config files
     *   stest-config.json[.local] files
     */
    static function _realm(string $domain = ""): string {
        $realm = null;
        // --realm - disable realms
        if ($r = STest::$ARG['realm'] ?? 0)
            return $r === true ? "" : $r;
        if ($r = getenv("STEST_REALM"))
            return $r;
        if ($m = InstanceConfig::$config['realmDetectMethod'] ?? 0) {
            $realm = $m($domain);
            self::debug(" - using realmDetectMethod: $m($domain) realm=$realm", 2);
            return $realm ?? "";
        }
        return InstanceConfig::$config['realm'] ?? "";
    }

    /**
     * Enable TESTING (already enabled by default, call after calling disable)
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
        if (self::$ARG['debug'] ?? 0) {
            I('out')->e("{green}Spartan Test v" . VERSION . "{/} on " . gethostname() . " at " . date("Y-m-d H:i") . "\n");
        }
        if (!self::$TESTS) {
            self::$ARG += ["help" => 1];
        }
        set_error_handler('\\stest\\Error::handler', E_ALL);
    }

    // stest -abc --d --c="VALUE" test1 test2
    public function run(array $argv) {
        $this->init($argv);
        self::$FAILED = 0;
        if (self::$ARG['watch'] ?? 0) {
            exit(helper\Watcher::run($argv, self::$TESTS));
        }
        foreach (self::$ARG as $a => $v) {
            $a = str_replace("-", "_", $a); // "-" to "_"
            if (is_callable(["stest\\STest_Global_Commands", $a])) {
                if (($r = STest_Global_Commands::$a($v)) !== null) {
                    die("$r\n");
                }
            }
        }
        foreach (self::$TESTS as $test)
            $this->runTest($test);
        exit(self::$FAILED ? min(255, self::$FAILED) : 0);
    }

    function runTest($file) {
        static $first = true;
        // the first file of a process is charged the PHP start-up and init time too
        $start = $first ? ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)) : microtime(true);
        $first = false;
        STest_File_Commands::_resetStats();
        self::$ONCE = null;
        self::$FAIL_ONCE = null;
        self::$FAIL_ONCE_BUF = [];
        $real = realpath($file) ?: $file;
        $once = self::$ARG['once'] ?? null;
        if ($once === 'reset' && is_file($real)) {
            self::_onceRecord($real, false);
        }
        // "# @tag run-once(1day)" / "# @tag fail-once(1day)" special-use tags, else --once=PERIOD
        $header = is_file($real) ? self::_headerDirectives($real) : [];
        $runOnce = $header['run-once'] ?? ($once === true ? '1day' : ($once === 'reset' || $once === 'ignore' ? null : $once));
        if (isset($header['invalid'])) {
            $message = "invalid tag '{$header['invalid']}'; expected e.g. run-once(1day), fail-once(12h), periods: 30min, weekly, 2days";
            i('out')->err("*** {alert}$real{/}. Error: $message\n");
            STest_File_Commands::_setStats(['status' => 'error', 'message' => $message]);
            self::$FAIL_ONCE = null;
            $failed = 1;
            self::$FAILED += 1;
        } elseif (($skip = $this->_onceAuto($file, $real, $runOnce)) !== null) {
            $failed = 0;
        } else {
            if (isset($header['fail-once'])) {
                self::_failOnceStart($real, $header['fail-once']);
            }
            $failed = $this->runTestFile($file);
            if ($runOnce !== null) {
                self::$ONCE = $real;  // every passing file is recorded
            }
            if (self::$ONCE !== null && !$failed && (STest_File_Commands::_stats()['status'] ?? '') === 'pass') {
                self::_onceRecord(self::$ONCE, true);
            }
        }
        if ($resultFile = self::$ARG['result-file'] ?? null) {
            self::_writeResult($resultFile, $file, (int) $failed, microtime(true) - $start);
        }
        return $failed;
    }

    /**
     * special-use tags in the "# @tag" / "# @require-tag" lines of the first four lines:
     *   # @tag web run-once(PERIOD)   - as STest::runOnce(PERIOD)
     *   # @tag fail-once(PERIOD)      - as STest::failOnce(PERIOD)
     * a bare "run-once" / "fail-once" means 1day; returns [name => period, 'invalid' => token]
     */
    static function _headerDirectives(string $file): array {
        $found = [];
        $fh = @fopen($file, 'r');
        for ($n = 0; $fh && $n < 4 && ($line = fgets($fh)) !== false; $n++) {
            if (!preg_match('/^\s*#\s*@(?:tag|require-tag)\s+(.*)$/', rtrim($line, "\r\n"), $m)) {
                continue;
            }
            foreach (preg_split('/[\s,]+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) as $token) {
                if (!preg_match('/^(run-once|fail-once)(?:\((.*)\))?$/', $token, $t)) {
                    continue;
                }
                $period = ($t[2] ?? '') === '' ? '1day' : $t[2];
                if (helper\State::seconds($period) === null) {
                    $found['invalid'] = $token;
                } else {
                    $found[$t[1]] = $period;
                }
            }
        }
        $fh && fclose($fh);
        return $found;
    }

    // runOnce period (--once=PERIOD or tag run-once): skip an unchanged, recently passed file without executing it; null = run it
    function _onceAuto(string $file, string $real, ?string $period): ?string {
        if ($period === null || !is_file($real)) {
            return null;
        }
        $why = self::_onceSkip($real, helper\State::seconds($period));
        if ($why === null) {
            return null;
        }
        $this->file = $file;
        $message = "runOnce($period): $why";
        i('out')->e("*** {bg_blue}{white}{bold}%s{/}\n {warn}Test stopped{/}: $message\n", $real);
        STest_File_Commands::_setStats(['status' => 'stop', 'message' => $message]);
        i('reporter')->stop($real, ['message' => $message, 'tests' => 0]);
        return $message;
    }

    // append one JSON line describing the finished file (used by stest-all and --watch)
    static function _writeResult(string $resultFile, string $file, int $failed, float $duration): void {
        $stats = STest_File_Commands::_stats();
        $status = $stats['status'] ?? ($failed ? 'fail' : 'pass');
        if ($failed && $status === 'pass') {
            $status = 'fail';
        }
        $record = [
            'file' => $file,
            'status' => $status,
            'tests' => $stats['tests'] ?? 0,
            'failed' => $failed,
            'new' => $stats['new'] ?? 0,
            'reformat' => $stats['reformat'] ?? 0,
            'saved' => STest_File_Commands::$saved,
            'duration' => round($duration, 3),
        ] + array_filter([
            'message' => $stats['message'] ?? null,
            'details' => $stats['details'] ?? null,
        ]);
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $fh = @fopen($resultFile, 'a');
        if ($fh === false) {
            fwrite(STDERR, "unable to write --result-file=$resultFile\n");
            return;
        }
        flock($fh, LOCK_EX);
        fwrite($fh, $json . "\n");
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    function runTestFile($file) {
        $this->file = $file;
        self::$DIR = \realpath(dirname($file));
        try {
            InstanceConfig::init($file);
            $T = helper\Parser::Reader($file);
        } catch (\Exception $ex) {
            i('out')->err("*** {alert}$file{/}. Error: " . $ex->getMessage() . "\n");
            STest_File_Commands::_setStats(['status' => 'error', 'message' => $ex->getMessage()]);
            self::$FAILED += 1;
            return 1;
        }
        $cmd = 0;
        foreach (self::$ARG as $a => $v) {
            $a = str_replace("-", "_", $a); // "-" to "_"
            if (is_callable(["stest\\STest_File_Commands", $a])) {
                $cmd++;
                if ($a === "test") {
                    $failed = (int) STest_File_Commands::$a($T, $v);
                    self::$FAILED += $failed;
                    return $failed;
                }
                $result = STest_File_Commands::$a($T, $v);
                if ($result === false) {
                    self::$FAILED += 1;
                    return 1;
                }
                break; // execute only only command
            }
        }
        if (!$cmd) {
            $failed = STest_File_Commands::test($T);
            // normal run records when a formatting-only ("sort-fail") mismatch was found;
            // re-run in soft-regen mode to fix formatting and save (real value diffs stay failures)
            if (STest_File_Commands::_softNeeded() && !(self::$ARG['soft'] ?? 0) && !(self::$ARG['generate'] ?? 0) && !(self::$ARG['read_only'] ?? 0)) {
                self::$ARG['soft'] = 1;
                $failed = STest_File_Commands::test($T);
                unset(self::$ARG['soft']);
            }
            self::$FAILED += (int) $failed;
            return (int) $failed;
        }
        return 0;
    }

    protected static function parseArgs($argv) {
        [self::$ARG, self::$TESTS] = helper\parseArgs($argv);
        self::$ARG += ['color' => 1, 'sort' => 1]; // Defaults
        // convert short options to long options using self::$optionExpand
        $to_fix = array_filter(
            self::$ARG,
            function ($v, $k) {
                if (self::$optionExpand[$k] ?? 0) {
                    return 1;
                }
            },
            ARRAY_FILTER_USE_BOTH);
        foreach ($to_fix as $k => $v) {
            unset(self::$ARG[$k]);
            $nk = self::$optionExpand[$k];
            if (is_array($nk)) {
                self::$ARG = array_merge(self::$ARG, $nk);
            } else {
                self::$ARG[$nk] = $v;
            }
        }
        if (array_key_exists('timeout', self::$ARG)) {
            $timeout = self::$ARG['timeout'] === true
                ? false
                : filter_var(self::$ARG['timeout'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($timeout === false) {
                fwrite(STDERR, "--timeout expects a positive integer number of seconds, e.g. --timeout=5\n");
                exit(1);
            }
            self::$ARG['timeout'] = $timeout;
        }
        if (array_key_exists('once', self::$ARG)) {
            $once = self::$ARG['once'];
            if ($once !== true && $once !== 'reset' && $once !== 'ignore' && helper\State::seconds((string) $once) === null) {
                fwrite(STDERR, "--once expects a period (1day, 12h, 30min, weekly, ...), reset, or ignore\n");
                exit(1);
            }
        }
        if (self::$ARG['read_only'] ?? 0) {
            foreach (['generate', 'save', 'clean'] as $writer) {
                if (self::$ARG[$writer] ?? 0) {
                    fwrite(STDERR, "--read-only cannot be combined with --$writer: both would rewrite the test file\n");
                    exit(1);
                }
            }
        }
    }

}

/**
 *
 * @see  README.md and Syntax.md for how to write and run tests
 * @see  https://github.com/parf/spartan-test for the latest documentation
 */
class STest_Global_Commands {

    /**
     * Any non-null return value is treated as a STOP signal, @see STest->run
     */

    /**
     * (-v) verbose: print every test line before it runs
     * example: stest -v filename.stest
     */
    static function verbose() {
    }

    /**
     * (-g) regenerate: replace every stored result with the current one
     * changed values are reported but do not fail the run; a file that cannot be saved does
     */
    static function generate() {
    }

    /**
     * colorize output (default: on)
     * --color=0 or -C turns color off
     */
    static function color() {
    }

    /**
     * (-s, -q) silent: print errors only, on STDERR; suppress all STDOUT
     */
    static function silent() {
    }

    /**
     * curl connect/transfer timeout for web requests, in seconds (default 15)
     * example: --timeout=5
     */
    static function timeout($v) {
    }

    /**
     * web-test: send every request to this IP, overriding STest::domain(..., ip:)
     * Host header, cookies and TLS SNI keep the domain name. example: --ip=172.16.1.1
     */
    static function ip($v) {
    }

    /**
     * --once[=1day]   skip files that are unchanged and passed within the period, as if each called STest::runOnce()
     * --once=reset    forget the recorded passes of the given files, then run them
     * --once=ignore   run files even when runOnce would skip them (passes are still recorded)
     */
    static function once($v) {
    }

    /**
     * re-run a test file each time it is saved (requires inotifywait from inotify-tools)
     * paths may be files or directories (recursive); results stest writes itself never trigger a re-run
     * example: stest --watch tests/ api.stest
     */
    static function watch() {
    }

    /**
     * append one JSON line per finished test file to PATH (used by stest-all and --watch)
     * fields: file, status, tests, failed, new, reformat, saved, duration, message, details
     */
    static function result_file($v) {
    }

    /**
     * send output to syslog (errors only: --syslog -s)
     */
    static function syslog() {
    }

    /**
     * (-1) stop the test file at the first failing test
     * inside a test: "; $ARG['first_error'] = 1;"
     */
    static function first_error() {
    }

    /**
     * (-f) force: run tests that call \STest::stop (normally a successful skip, exit 0)
     * \STest::error and \STest::alert still fail
     */
    static function force() {
    }

    /**
     * (-r) read-only: run tests without rewriting .stest files
     * a missing result fails instead of being generated
     * formatting-only differences pass and are left as written (no soft-regen rewrite)
     * cannot be combined with --generate, --save, or --clean
     */
    static function read_only() {
    }

    /**
     * call Reporter::alert instead of Reporter::fail when a test fails
     * inside a test: "; $ARG['alert'] = 1;"
     */
    static function alert() {
    }

    /**
     * use a custom error handler
     * provided handlers:
     *   \stest\Error::suppress_notices  - ignore notices (do not report them)
     *   \stest\Error::suppress_warnings - ignore warnings (do not report them)
     *   \stest\Error::$error_reporting  - bitmask of error levels to suppress
     */
    static function error_handler($v = '\\stest\\Error::handler') {
        #set_error_handler($v, E_ALL);
        throw new Exception("Unsupported");
    }

    /**
     * enable/disable sorting of array results (set it inside a test)
     * $ARG['sort'] = 0; // disable
     * $ARG['sort'] = 1; // enable (default)
     */
    static function sort($v) {
        # throw new Exception("see docs");
    }

    /**
     * print the current version and build date
     */
    static function version() {
        echo VERSION, " (build ", DATE_BUILD, ")\n";
        exit(0);
    }

    /**
     * show this help
     */
    static function help() {
        if (STest::$ARG['silent']??0) {
            return;
        }
        $e = [i('out'), 'e'];
        $h = function ($h, $title) use ($e) {
            $e("{head}%s{/}\n", $title);
            #$e($h["@"] . "\n"); // class doc
            foreach ($h as $method => $doc) {
                if (!$method || $method[0] == '_') {
                    continue;
                }
                if ($method[0] == "@") {
                    $e($doc."\n");
                    continue;                    
                }
                $e("  {blue}--{bold}%s{/}", str_pad($method, 16));
                $e(" {grey}%s{/}\n", str_replace("\n", "\n                     ", $doc));
            }
            $e("\n");
        };
        $e("{bold}{blue}STEST{/} {bold}(Spartan Test v" . VERSION . ") minimalistic PHP 8.5 testing framework done right{/}\n");
        $h(helper\Documentor::classDoc("\\stest\\STest_Global_Commands"), "Global Options --\$option");
        $h(helper\Documentor::classDoc("\\stest\\STest_File_Commands"), "File Options");
        #echo json_encode(helper\Documentor::classDoc("\\stest\\STest_Global_Commands"), JSON_PRETTY_PRINT), "\n";
        #echo json_encode(helper\Documentor::classDoc("\\stest\\STest_File_Commands"), JSON_PRETTY_PRINT), "\n";
    }

    /**
     * PHP 'init' file to include; it must set up autoloading
     * preferred: set "init" in stest-config.json at the root of your project
     */
    static function init($v) {
    }

    /**
     * debug: print the parsed command-line arguments as JSON
     */
    static function debug_args() {
        echo json_encode(['args' => STest::$ARG, 'tests' => STest::$TESTS], JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * debug: print the merged config (base + project configs) as JSON
     */
    static function debug_config() {
        echo json_encode(\stest\helper\InstanceConfig::$config, JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * debug output level: --debug=1 shows the most important messages, --debug=9 shows everything
     */
    static function debug() {
    }


    /**
     * for use in "crontab": no-colors, show errors only
     */
    static function cron() {
    }

} // class STest_Global_Commands

/**
 *
 * STest --$option File.stest
 *
 */
class STest_File_Commands {

    // static function $Option(ParsedTest $T, $option_value)
    private static $softNeeded = false;
    private static $stats = [];   // last test() outcome, @see STest::_writeResult
    static $saved = false;        // the current file was rewritten by save()

    static function _softNeeded(): bool {
        return self::$softNeeded;
    }

    // failOnce(): the same failure was already reported - report the file as stopped instead
    static function _failOnceQuiet(object $__t, string $message): int {
        i('out')->e("*** {bg_blue}{white}{bold}%s{/}\n {warn}Test stopped{/}: $message\n", $__t->filename);
        self::$stats = ['status' => 'stop', 'message' => $message, 'tests' => $__t->tests, 'new' => $__t->new, 'reformat' => $__t->reformat];
        i('reporter')->stop($__t->filename, ['message' => $message, 'tests' => $__t->tests]);
        return 0;
    }

    static function _resetStats(): void {
        self::$stats = [];
        self::$saved = false;
    }

    static function _setStats(array $stats): void {
        self::$stats = $stats;
    }

    static function _stats(): array {
        return self::$stats;
    }


    /** default action:
     * run the test file, compare results, generate and save missing ones
     * -v | --verbose   - print each statement as it runs
     * -g | --generate  - replace every stored result; value changes do not fail the run, an unsaved file does
     * -r | --read-only - never rewrite the file; a missing result fails
     */
    static function test(array /* parsed-test */ $__TEST) {
        self::$softNeeded = false;
        $__t = (object)[ // dummy object used to hide variables
            'T' => $__TEST,
            'fail' => 0, 'new' => 0, 'reformat' => 0, 'softNeeded' => 0, 'tests' => 0,
            'start' => microtime(1),
            'filename_shown' => 0,
            'filename' => realpath(i('stest')->file),
            'details' => [],
        ];

        $ARG = \STest::$ARG; // Visible inside TEST, can be modified inside test
        // show filename above first error
        $__err = function ($s, $reason = "failed") use ($__t) {
            if (!$__t->filename_shown) {
                STest::_err("*** {alert}%s %s{/}\n", $__t->filename, $reason);
                $__t->filename_shown = 1;
            }
            STest::_err($s . "\n");
        };

        $__tester = function (string &$expected, $got, $line, $code) use ($__err, &$ARG, $__t) {
            $showError = function ($err) use ($line, $code, $__err, $ARG, $__t) {
                // FAILED TEST
                $__t->fail++;
                if (!($ARG['soft'] ?? 0)) { // soft-regen pass already reported by normal pass
                    ($u = \STest::$URL) && $err .= "\n  url:   $u";
                    if ($errorCallback = InstanceConfig::$config['errorCallback'] ?? 0) {
                        if ($extra_info = $errorCallback($err, $line, $code, $ARG)) {
                            $err .= "\n  extra: " . x2s($extra_info);
                        }
                    }
                    $__err("{alert}L$line{/}: {red}$code{/}\n $err");
                }
                if ($ARG['first_error'] ?? 0) {
                    throw new StopException(
                        'temp' === $ARG['first_error'] ? "Critical test (" . helper\Parser::CRITICAL . ") failed" : "Stopping on first error"
                    );
                }
            };
            $exp = trim($expected, ";");
            $addDetail = function ($got, $err = "") use ($line, $code, $exp, $__t) {
                $d = [
                    'got'    => $got,
                    'line'   => $line,
                    'code'   => $code,
                    'expect' => $exp,
                    'error'  => $err,
                    'url'    => \STest::$URL,
                ];
                $__t->details[] = array_filter($d);
            };
            if (($exp[0] ?? 0) === '~') { // ~XXX special tests
                if ($err = self::_custom_result_syntax($exp, $got)) {
                    $addDetail("error", $err);
                    $showError($err);
                }
                return;
            }
            $got = x2s($got, $ARG['sort'] ?? "");   // result-as-php-code-string
            $got = str_replace("\n", "\n    ", $got); // result identation
            if ($exp == $got) {
                return;
            }
            if ($ARG['generate'] ?? 0) { // generate all results in test
                $expected = $got . ";"; // save corrected result
                $__err("CODE: {bold}{red}L$line{/}: $code");
                if ($exp) {
                    $addDetail($got);
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
            if (!$expected) { // NEW TEST - generate result
                if ($ARG['read_only'] ?? 0) { // never rewrite: an unverifiable result is a failure
                    $addDetail(cut($got), "read-only: no stored result");
                    $showError("read-only: no stored result, nothing saved\n  got:   {red}" . cut($got) . "{/}");
                    return;
                }
                $__t->new++;
                $expected = $got . ";"; // save generated result
                $__err("{bold}{blue}L$line{/}: $code");
                $__err(" got: {blue}$got{/}");
                return;
            }
            // formatting-only difference (same value, different spacing / key order)?
            $sameValue = false;
            try {
                $expCanon = str_replace("\n", "\n    ", x2s(eval("return $exp;"), $ARG['sort'] ?? ""));
                $sameValue = ($expCanon === $got);
            } catch (\Throwable $__ignore) { // expected not valid php => treat as real change
            }
            if ($sameValue) {
                if ($ARG['read_only'] ?? 0) { // same value, never rewrite
                    return;
                }
                if ($ARG['soft'] ?? 0) { // soft-regen pass: rewrite to canonical form
                    $expected = $got . ";";
                    $__t->reformat++;
                    i('out')->e(" {blue}reformat L$line{/}: $code\n");
                } else { // normal run: schedule a soft-regen pass to fix formatting
                    $__t->softNeeded = 1;
                }
                return;
            }
            $got = cut($got); # get rid of super long error results
            $addDetail($got);
            $showError("expect: {cyan}" . $exp . "{/}\n  got:   {red}$got{/}");
        };

        $ARG['verbose'] = $ARG['verbose'] ?? 0;
        $ARG['verbose'] && i('out')->e("*** {head}%s{/}\n", $__t->filename);

        try {
            //
            // MAIN TEST LOOP BEGIN ------------------
            //
            foreach ($__t->T as &$__line__tp_v_r) { // [ln, [tp, v, r]]
                [$__line, [$__type, $__code]] = $__line__tp_v_r;
                if ($__type == 'expr' || $__type == 'definition') {
                    // Named declarations survive eval() and cannot be declared
                    // again during the formatting-only soft-regeneration pass.
                    if ($__type == 'definition' && ($ARG['soft'] ?? 0)) {
                        continue;
                    }
                    try {
                        ($ARG['verbose']??0) && i('out')->e("{grey}%s{/}\n", $__code);
                        self::_push_php_compat_error_handler();
                        try {
                            eval($__code);
                        } finally {
                            restore_error_handler();
                        }
                        if ($__error = Error::get()) {
                            throw new ErrorException("PHP error in setup expression: " . x2s($__error));
                        }
                    } catch (StopException $__ex) {
                        throw $__ex;
                    } catch (\Exception $__ex) {
                        throw new ErrorException("Unexpected exception " . get_class($__ex) . " " . $__ex->getMessage());
                    } catch (\Error $__ex) {
                        throw new ErrorException("Unexpected error " . get_class($__ex) . " " . $__ex->getMessage());
                    }
                } // if-expr
                if ($__type == "test") {
                    $__t->tests++;
                    // "? code" - inspect code
                    if ($__code[0] . $__code[1] === '? ') {
                        $__code = "STest::inspect(" . trim(substr($__code, 2), ";") . ");";
                    }
                    // "‼️ code" (typed as "!! code") - critical test: stop the file if this line fails.
                    // A single "!" is ordinary PHP negation and has no special meaning.
                    if (($__critical = self::_critical_prefix($__code)) !== null) {
                        $__code = $__critical;
                        if (!($ARG['first_error'] ?? 0)) {
                            $ARG['first_error'] = 'temp';
                        }
                    }
                    try {
                        if ($__error = Error::get()) {
                            $__err("-- {alert}stest-internal unexpected error{/}: " . x2s($__error));
                        }  // this should NOT happend
                        // so far only web tests have custom syntax
                        $__code_ = self::_custom_test_syntax($__code);
                        ($ARG['verbose']??0) && i('out')->e("{cyan}%s{/}\n", $__code);
                        ob_start();
                        self::_push_php_compat_error_handler();
                        try {
                            $__rz = eval("return $__code_");
                        } finally {
                            restore_error_handler();
                        }
                        $__out = ob_get_clean();
                        if ($__out) {
                            $__rz = [$__rz, '$' => $__out];
                        }
                        if ($__error = Error::get()) {
                            $__rz = ['error' => $__error];
                        }
                    } catch (StopException $__ex) {
                        throw $__ex;
                    } catch (\Exception $__ex) {
                        $__rz = [get_class($__ex), $__ex->getMessage()];
                    } catch (\Error $__ex) {
                        if ($ARG['allowError'] ?? 0) {  // --allowError to not break on \Error Exceptions
                            $__rz = ["Error:" . get_class($__ex), $__ex->getMessage()];
                        } else {
                            $class = get_class($__ex);
                            $_err = "\Error Exception: $class(\"" . $__ex->getMessage() . "\")";
                            $trace = self::_backtrace($__ex);
                            \STest::error($_err . ($trace ? "\nTrace:\n$trace" : ""));
                        }
                    } catch (\Throwable $__ex) {
                        $__rz = ["Throwable:" . get_class($__ex), $__ex->getMessage()];
                    }
                    ($ARG['verbose']??0) && i('out')->e("    {green}%s{/}\n", $__line__tp_v_r[1][2] /*x2s($__rz) */);
                    $__tester($__line__tp_v_r[1][2], $__rz, $__line, $__code);
                    if ('temp' === ($ARG['first_error'] ?? 0)) {
                        unset($ARG['first_error']);
                    }
                } // if-test
            }
            //
            // MAIN TEST LOOP END ------------------
            //
        } catch (StopException $__ex) { // Stop/Error/Alert
            Error::get(); // terminal exceptions own the result; discard captured PHP-error state
            $m = $__ex->getMessage();
            $reason = str_replace(["Exception", "stest\\"], "", get_class($__ex)); // Stop/Error/Alert
            if ($reason === "Stop") {
                i('out')->e("*** {bg_blue}{white}{bold}%s{/}\n {warn}Test stopped{/} at line $__line : $m\n", $__t->filename);
            } else {
                $__err("{alert}$reason{/} at line $__line: $m\n    {cyan}$__code{/}");
            }
            $reportReason = $reason === "Stop" && $__t->fail ? "fail" : $reason;
            if ($quiet = STest::_failOnceSettle($__t->filename, $__t->details, $m, $reportReason === "Stop" ? null : false)) {
                return STest_File_Commands::_failOnceQuiet($__t, $quiet);
            }
            self::$stats = ['status' => strtolower($reportReason), 'message' => $m, 'tests' => $__t->tests, 'new' => $__t->new, 'reformat' => $__t->reformat, 'details' => $__t->details];
            i('reporter')->$reportReason($__t->filename, ['message' => $m, 'tests' => $__t->tests, 'new' => $__t->new, 'fail' => $__t->fail, 'details' => $__t->details]);
            // a stopped file still gets its typed "!!" markers rewritten to ‼️ (results are left as they are)
            if (helper\Parser::$criticalRewritten && !($ARG['read_only'] ?? 0) && !self::save($__t->T)) {
                $__t->fail++;
            }
            if ($ARG['generate'] ?? 0) {
                return 0;
            }
            return $reason === "Stop" ? (int) $__t->fail : max(1, $__t->fail);
        }

        // formatting-only mismatch found: defer reporting/saving to the soft-regen pass
        if ($__t->softNeeded && !($ARG['soft'] ?? 0)) {
            self::$softNeeded = true;
            return 0;
        }

        // Save before reporting so persistence failures are part of the result.
        $saveFailed = false;
        // "!!" prefixes rewritten to ‼️ while reading count as reformatting and require a save
        if (($__normalized = helper\Parser::$criticalRewritten) && !($ARG['read_only'] ?? 0)) {
            $__t->reformat += $__normalized;
        }
        $wantsSave = ($__t->new && !$__t->fail) || ($ARG['generate'] ?? 0) || (($ARG['soft'] ?? 0) && $__t->reformat) || $__normalized;
        if ($wantsSave && !($ARG['read_only'] ?? 0)) {
            if (!self::save($__t->T)) {
                $saveFailed = true;
                $__t->fail++;
                $__t->details[] = ['error' => 'Unable to save test file'];
            }
        }

        $dur = microtime(1) - $__t->start;
        $stat = "tests: " . $__t->tests;
        if ($dur > 0.1 || ($ARG['verbose']??0)) // require at least 0.1 sec
        {
            $stat .= " (" . sprintf("%0.2f", $dur) . "s)";
        }
        if ($new = $__t->new) {
            $stat .= ", {blue}{bold}new: $new{/}";
        }
        if ($reformat = $__t->reformat) {
            $stat .= ", {blue}reformat: $reformat{/}";
        }
        if ($quiet = STest::_failOnceSettle($__t->filename, $__t->details, "", !$__t->fail)) {
            return self::_failOnceQuiet($__t, $quiet);
        }
        if ($fail = $__t->fail) {
            $__err("{alert}>{/} $stat, {warn}failed: $fail{/}");
        } else {
            i('out')->e("*** {head}%s{/} $stat\n", $__t->filename);
        }

        self::$stats = ['status' => $fail ? 'fail' : 'pass', 'tests' => $__t->tests, 'new' => $__t->new, 'reformat' => $__t->reformat, 'details' => $__t->details];
        $how = $fail ? "fail" : "success";
        if ($ARG['alert']??0)
            $how = "alert";
        i('reporter')->$how($__t->filename, array_filter(['tests' => $__t->tests, 'new' => $__t->new, 'reformat' => $__t->reformat, 'fail' => $__t->fail, 'details' => $__t->details]));
        if ($ARG['generate'] ?? 0) {
            return (int) $saveFailed;
        }
        return $__t->fail;
    }

    // strip the critical-test marker ("‼️", "‼" or the typed "!!") from a test line; null = not critical
    static private function _critical_prefix(string $code): ?string {
        foreach ([helper\Parser::CRITICAL, "\u{203C}", "!!"] as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return substr($code, strlen($prefix));
            }
        }
        return null;
    }

    // get useful part of exception's backtrace as string
    // TODO - provide better backtrace - use getTrace
    static private function _backtrace(\Throwable $ex): string {
        $trace = $ex->getTraceAsString();
        $pos = strpos($trace, '/STest.php'); // find and cut part with STest backtrace
        $trace = substr($trace, 0, $pos);
        $pos = strrpos($trace, "\n");
        return substr($trace, 0, $pos);
    }

    static private function _push_php_compat_error_handler(): void {
        $previous = set_error_handler(function ($level, $message, $file, $line) use (&$previous) {
            if (
                $level === E_DEPRECATED
                && preg_match('/\bReflection\w+::setAccessible\(\)/', $message)
                && str_contains($message, "has no effect since PHP 8.1")
            ) {
                return true;
            }
            if ($previous) {
                return $previous($level, $message, $file, $line);
            }
            return false;
        });
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
            foreach (explode("\n", $exp) as $exp) {
                if ($r = self::_custom_result_syntax($exp, $got)) {
                    return $r;
                }
            }
            return;
        }
        # echo "<< $exp >>\n";
        $x = trim($exp, "~ ;");
        $err = ""; // test-error found
        if (!$x) { // "~" case = IS NOT EMPTY STRING CASE
            if (trim($exp) == '~~') {
                return $got ? null : "non empty result expected";
            }
            if (!is_string($got)) {
                if (is_array($got)) {
                    return "string expected got:array=" . substr(json_encode($got, JSON_UNESCAPED_SLASHES), 0, 60);
                }
                return "string expected got:" . gettype($got);
            }
            if (!$got) {
                return "non empty string expected got: empty string";
            }
            return;
        }
        switch ($x[0]) {
            case '"': // "substring"
                if (!is_string($got)) {
                    if (is_array($got)) {
                        return "string expected got:array=" . substr(json_encode($got, JSON_UNESCAPED_SLASHES), 0, 60);
                    }
                    return "string expected got:" . gettype($got);
                }
                $x = eval("return $x;");
                if (strpos($got, $x) !== false) {
                    return;
                }
                return "substring-expected: '{cyan}$x{/}'";
            case '[': // in-array
                $x = eval("return $x;");
                if (!is_array($got)) {
                    return "array expected";
                }
                foreach ($x as $k => $e) { // e - element
                    if (!is_int($k)) {
                        if ($e === true || $e === false) { // key exists / does not exist
                            if (array_key_exists($k, $got) === $e) {
                                continue;
                            }
                            return " array-key {cyan}\"$k\"{/} expected " . ($e ? "to exist" : "not to exist")
                                . ($e ? "" : ", got " . x2s($got[$k]));
                        }
                        if (($got[$k] ?? null) == $e) {
                            continue;
                        }
                        return " array-element {cyan}\"$k\" => " . x2s($e) . "{/} expected, got " . x2s($got[$k] ?? "null");
                    }
                    if (!in_array($e, $got)) {
                        return " array-element-expected: {cyan}" . x2s($e) . "{/}";
                    }
                }
                break;
            case '/': // regexp
                if (!is_string($got)) {
                    return "regexp match - string expected got:{cyan}" . x2s($got) . "{/}";
                }
                if (!preg_match($x, $got)) {
                    return " regexp match expected {cyan}" . x2s($x) . "{/}";
                }
                break;
            default:  // ~ ClassName
                if (strpos($x, " ")) {
                    return "{cyan} unsupported test: '$x'{/} ";
                }
                if (!is_object($got)) {
                    return "Object expected";
                }
                if (!is_a($got, $x)) {
                    return "{cyan}$x{/} descendant object expected, got {red}" . get_class($got) . "{/} object";
                }
                if (is_a($got, $x)) {
                    return;
                } // is subclass
        }
        return $err;
    }

    /**
     * INTERNAL
     * custom (non php compatible) test syntax
     * @see so far only web tests uses custom test syntax examples/web-tests.stest
     *
     * Webtest Custom Syntax:
     *   /uri $args
     *   post /uri $args
     *   jsonpost /uri $args
     *   follow "href-title"
     *
     */
    static private function _custom_test_syntax(string $test): string { # modified code
        if (!$test) {
            throw new ErrorException("Empty Test");
        }
        # /$path  == Webtest::GET
        if ($test[0] == '/') {
            $test = trim($test, "; ");
            $r = explode(" ", $test, 2);
            if (count($r) == 1) {
                $r = [$r[0], ""];
            }
            [$path, $args] = $r;
            if (!$args) {
                $args = "[]";
            }
            return "\stest\I('webtest')->get('$path', $args);";
        }
        # POST /$path  == Webtest::GET
        if (!strncasecmp($test, "post /", 5)) {
            $test = trim(substr($test, 5), "; ");
            $r = explode(" ", $test, 2);
            if (count($r) == 1) {
                $r = [$r[0], ""];
            }
            [$path, $args] = $r;
            if (!$args) {
                $args = "[]";
            }
            return "\stest\I('webtest')->post('$path', $args);";
        }
        # JSONPOST /$path  == Webtest::GET
        if (!strncasecmp($test, "jsonpost /", 9)) {
            $test = trim(substr($test, 9), "; ");
            $r = explode(" ", $test, 2);
            if (count($r) == 1) {
                $r = [$r[0], ""];
            }
            [$path, $args] = $r;
            if (!$args) {
                $args = "[]";
            }
            return "\stest\I('webtest')->jsonPost('$path', $args);";
        }
        # follow link by link's text
        # follow "a-href text"
        if (!strncasecmp($test, "follow ", 5)) {
            $name = trim(substr($test, 7), "; ");
            $test = "\STest::follow($name);";
        }
        return $test;
    }

    /**
     * print the normalized test to stdout (missing semicolons added, indentation fixed)
     */
    static function cat($T, $echo = 1): string { # test
        $s = "";
        foreach ($T as [$ln, $tv]) {
            [$tp, $v] = $tv;
            if ($tp == "test") {
                $r = $tv[2] ?? "";
                $s .= "$v\n" . ($r ? "    $r\n" : "");
                continue;
            }
            $s .= "$v\n";
        }
        if ($echo) {
            echo $s;
        }
        return $s;
    }

    /**
     * save the normalized test file (missing semicolons added, indentation fixed)
     */
    static function save($T): bool {
        $filename = i('stest')->file;
        if (\STest::$ARG['read_only'] ?? 0) {
            i('out')->err("*** {alert}%s{/}. Error: --read-only forbids rewriting the test file\n", $filename);
            return false;
        }
        $target = realpath($filename) ?: $filename;
        $s = self::cat($T, 0);
        $mode = @fileperms($target);
        $directory = dirname($target);
        $writable = is_writable($directory) && (!file_exists($target) || is_writable($target));
        $lockName = hash('sha256', $target);
        $lock = @fopen(sys_get_temp_dir() . "/stest-save-$lockName.lock", 'c');
        $locked = $lock !== false && @flock($lock, LOCK_EX | LOCK_NB);
        // Locking is best-effort; the unique temporary file and rename provide atomic replacement.
        $tmp = $writable ? @tempnam($directory, "." . basename($target) . ".") : false;
        $saved = false;

        if ($tmp !== false) {
            $written = @file_put_contents($tmp, $s, LOCK_EX);
            $modePreserved = $mode === false || @chmod($tmp, $mode & 0777);
            if ($written === strlen($s) && $modePreserved) {
                $saved = @rename($tmp, $target);
            }
            if (!$saved) {
                @unlink($tmp);
            }
        }

        if ($locked) {
            @flock($lock, LOCK_UN);
        }
        if ($lock !== false) {
            @fclose($lock);
        }

        if (!$saved) {
            i('out')->err("*** {alert}%s{/}. Error: unable to save test file\n", $filename);
            return false;
        }

        self::$saved = true;
        i('out')->e("*** {head}%s{/} saved\n", $filename);
        return true;
    }

    /**
     * remove every stored result from the test file
     */
    static function clean($T): bool {
        foreach ($T as &$v) {
            if ($v[1][0] == 'test') {
                unset($v[1][2]);
            }
        }
        return self::save($T);
    }

    /**
     * print the parsed, unprocessed test representation
     */
    static function debug_test($T) { # echo internal test presentation
        foreach ($T as [$ln, $tv]) {
            echo "$ln: ", json_encode($tv, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
        }
    }

    /**
     * web-test: override STest::domain(...)
     */
    #static function domain($T) {
    #    // php-doc only usage
    #}

    /**
     * web-test: add realm to domain specified in STest::domain(...)
     */
    #static function realm($T) {
    #   // php-doc only usage
    #}


} // class STest_File_Commands

/**
 * Error Handler
 */
class Error {  // error handler

    public static $error_reporting = 0;      // bit-mask to suppress errors

    private static $err = []; // php-errors from error handler - use get() to read

    // return error (if any), clean up error info
    static function get() { # string or array with errors
        $e = self::$err;
        self::$err = [];
        return sizeof($e) == 1 ? $e[0] : $e;
    }

    public static function suppress_notices() {
        self::$error_reporting |= E_NOTICE;
    }

    public static function suppress_warnings() {
        self::$error_reporting |= E_WARNING;
    }
    // if you want to suppress something else - change Error::$error_reporting

    // Error::handler
    static function handler($level, $message, $file, $line) {
        // you can hide notices and warnings with @
        // you can't hide errors
        if (!error_reporting() && ($level == E_WARNING || $level == E_NOTICE)) {
            STest::debug("\ndebug(4) $level, $message, $file, $line", 4);
            return;
        }
        if (STest::$ARG['debug'] ?? 0) {
            echo "\n$level, $message, $file, $line\n";
        }
        if ($level & self::$error_reporting) // allow people to debug ugly code
        {
            return;
        }
        static $map = array(
            E_NOTICE => 'NOTICE',
            E_WARNING => 'WARNING',
            E_USER_ERROR => 'USER ERROR',
            E_USER_WARNING => 'USER WARNING',
            E_USER_NOTICE => 'USER NOTICE',
            #E_STRICT => 'E_STRICT',
            E_DEPRECATED => 'E_DEPRECATED',
        );

        $type = $map[$level] ?? "ERROR#$level";
        $e = "$type: $message";
        if (substr($file, 0, strlen(__FILE__)) != __FILE__) {
            $e = [$e, $file, $line];
        }
        array_push(self::$err, $e);
    }

} // class Error


class Exception extends \Exception {
}

class SyntaxErrorException extends Exception {
}

class StopException extends Exception {
}   // \STest::stop("message")   -- Successfully Stop Test, ignore rest of the test
class ErrorException extends StopException {
}   // \STest::error("message")  -- UNSuccessfully Stop Test, ignore rest of the test
class AlertException extends StopException {
}   // \STest::alert("message")  -- UNSuccessfully Stop Test, send an alert, ignore rest of the test
