<?php

namespace hb;

/**
 * WebTest for Spartan Tests
 * class instance available as i('webtest')
 *
 * To provide your own implementation: specify your class in stest-config.json {webtest:Classname}
 *
 * Extend this class to modify/add your generic response tests:
 *   just add method test_{$YourTestName}
 *
 */

use \STest;

/**
 * Spartan Web Tests
 */
class WebTest {

    // mostly internal/debug-level
    // when set code-200 discovered errors reported as \stest\ErrorExceptions
    // when unset code-200 discovered errors reported inside \STest::$BODY
    public $error_as_exception = 1;

    function __construct() {
        if (! STest::$DOMAIN)
            STest::error("no DOMAIN configured, set STest::\$DOMAIN first");
        Curl::test(STest::$DOMAIN); // fast check if service online
    }

    // report discovered error on code=200 page
    function error($message) {
        if ($this->error_as_exception) {
            STest::error($message);  // << throw stest\ErrorException
            return;
        }
        STest::$BODY = ['ErrorException' => $message, 'code' => STest::$INFO['http_code'] ?? "?"];
    }

    function get(string $path, array $args = []) {
        return $this->rq($path, $args, "GET");
    }

    function post(string $path, array $args = []) {
        return $this->rq($path, $args, "POST");
    }

    // method: GET/POST
    function rq(string $path, array $args, string $method) {
        $d = STest::$DOMAIN;
        if (! $d)
            throw new \stest\ErrorException("Set STest::\$DOMAIN first");
        if (substr(strtolower($d), 0, 4) != 'http')
            $d = "http://".$d;
        $curl_opts = [];
        if (STest::$URL)
            $curl_opts += [CURLOPT_REFERER => STest::$URL];
        if (STest::$COOKIE) {
            $s = [];
            foreach (STest::$COOKIE as $k => $v)
                $s[] ="$k=$v";
            $curl_opts += [CURLOPT_COOKIE => join("; ", $s)];
            #var_dump($curl_opts);
        }
        $r = \hb\Curl::rq($d.$path, $args, $method, $curl_opts, ['headers' => 1]);
        // HEADERS
        STest::$HEADERS = $r['headers'] ?? [];
        unset($r['headers']);
        // BODY
        STest::$INFO = $r;
        STest::$BODY = trim($r['body'] ?? "");
        STest::$URL = $d.$path;
        STest::$PATH = $path;
        // HTTP CODE
        $code = $r['http_code'] ?? 999;
        if ($code == 200) {
            $this->_applyTests($r['body'], $r); // throw exception or place error inside STest::$BODY
        } else {
            // real BODY is in $INFO['BODY']
            if ($code == 301 || $code == 302 || $code == 303) {
                $redirect = str_replace($d, '$DOMAIN', $r['redirect_url']);
                STest::$BODY = ['code' => $code, 'redirect' => $redirect];
            } else {
                STest::$BODY = ['code' => $code, 'size' => strlen(STest::$BODY)]; // test with "~ ['code' => 404]"
            }
        }

        if ($c = STest::$HEADERS['Set-Cookie'] ?? 0) {
            foreach ((array)$c as $kv) {
                [$k, $v] = explode('=', $kv);
                STest::$COOKIE[$k] = $v;
            }
        }
        return STest::$BODY;
    }

    /**
     * Apply additional tests to http_code=200 pages
     * test any method named "test_*"
     */
    function _applyTests(string $body, array $info) : void {
        $methods = get_class_methods($this);
        foreach ($methods as $m) {
            if (substr($m, 0, 5) === 'test_') {
                $err = $this->$m($body, $info);
                if ($err)
                    $this->error($err);
            }
        }
    }

    function test_empty(string $body) { # null | "Error"
        if (! trim($body))
            return "Empty Response";
    }


    # Catch PHP errors on a page:
    # <br />\n<b>Warning</b>:  Division by zero in <b>/rd/research.local/spartan-test/examples/3-web-tests/web-server.php</b> on line <b>15</b><br />
    # <br />\n<b>Notice</b>:  Undefined index: url in <b>/rd/research.local/spartan-test/examples/3-web-tests/web-server.php</b> on line <b>50</b><br />
    # <br />\n<b>Parse error</b>: ... on line <b>88</b><br />
    # <br />\n<b>Fatal error</b>:  Uncaught Error: Call ... on line <b>88</b><br />
    function test_PHPError(string $body) { # null | "Error"
        $x = preg_match("!<br />\n<b>(Notice|Warning|Parse error|Fatal error)</b>:  (.*?) in <b>(.*?)</b> on line <b>(.*?)</b><br />!s", $body, $m);
        if (! $x)
            return;
        array_shift($m);
        return $m; // join(", ", $m);
    }

    /**
     * find XDebug error info | null
     *
     */
    function detectXDebugError(string $html) { # null | (string) XDebug-error
        # START: <table class='xdebug-error
        # ERROR: <span style='background-color: #cc0000; color: #fce94f; font-size: x-large;'>( ! )</span> Warning: Division by zero in /rd/research.local/spartan-test/examples/3-web-tests/web-server.php on line <i>15</i>

    }

}