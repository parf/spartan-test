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

    /**
     * perform JSONPOST, read JSON back, decode and return it
     * 
     * curl test: 
     *   start web server: spartan-test/examples/3-web-tests/start-web-server
     *   curl -X POST "http://127.0.0.2:8080/jsonPost" -H 'Content-Type: application/json' -d '{"login":"my_username","password":"my_password"}'
     */
    function jsonPost(string $path, array $kv) /* : array|string  */ {
        $d = STest::$DOMAIN;
        if (! $d)
            throw new \stest\ErrorException("Set STest::\$DOMAIN first");
        if (substr(strtolower($d), 0, 4) != 'http')
            $d = "http://".$d;        
        $opts = [
            CURLOPT_HTTPHEADER => ["Content-type: application/json"],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($kv)
        ];
        $r = \hb\Curl::rq($d.$path, [], "POST", $opts, ['headers' => 1]);
        STest::$HEADERS = [];
        foreach ($r['headers'] ?? [] as $h => $v) {
            STest::$HEADERS[ucwords($h, "-")] = $v;  // "Set-Cookie" - use correct capitalization
        }        
        STest::$INFO = $r;
        STest::$BODY = $r['body'];
        STest::$URL = $d.$path;
        STest::$PATH = $path;
        $code = $r['http_code'] ?? 999;
        is_string(STest::$BODY) && STest::$BODY = self::_decodeBody(STest::$BODY);
        if ($code != 200)
            STest::$BODY = ['code' => $code, 'body' => $r['body']];
        return STest::$BODY;
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
        STest::$HEADERS = [];
        foreach ($r['headers'] ?? [] as $h => $v) {
            STest::$HEADERS[ucwords($h, "-")] = $v;  // "Set-Cookie" - use correct capitalization
        }
        unset($r['headers']);
        // BODY
        STest::$INFO = $r;
        STest::$BODY = $r['body'] ?? "";
        $ct = STest::$HEADERS['Content-Type'] ?? "";
        if (str_contains($ct, 'text/html') || str_contains($ct, 'text/plain')) {
            STest::$BODY = trim(STest::$BODY, "\n\r ");
        }
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
        if ($c = STest::$HEADERS['Set-Cookie'] ?? STest::$HEADERS['set-cookie'] ?? 0) {
            foreach ((array)$c as $kv) {
                [$k, $v] = explode('=', $kv);
                if ($p = strrpos($v, ";")) {
                    $v = substr($v, 0, $p);
                }
                STest::$COOKIE[$k] = $v;
            }
        }
        is_string(STest::$BODY) && STest::$BODY = self::_decodeBody(STest::$BODY);
        return STest::$BODY;
    }


    /**
     * @internal - decode
     *   Can ONLY be called AFTER self::rq / self::jsonPost
     *   Content-Type: json,msgpack,igbinary
     *   Content-Encoding: gzip,zstd,brotli
     */
    function _decodeBody(string $body) {
        $ce = STest::$HEADERS['Content-Encoding'] ?? "";
        if ($ce) {
            \STest::debug(" - Content-Encoding: $ce", 7);
            $body = match ($ce) {
                'gzip' => \gzdecode($body),
                'gzip-test' => \bin2hex($body),                
                'brotli' => \brotli_uncompress($body), # https://github.com/kjdev/php-ext-brotli
                'zstd' => \zstd_uncompress($body), # https://caniuse.com/zstd
                default => $body,
            };
        }

        $ct = STest::$HEADERS['Content-Type'] ?? "";
        if ($ct && \str_starts_with($ct, 'application/')) {
            preg_match("!application/(\w+)!", $ct, $r);
            \STest::debug(" - Content-Type: $ct => $r[1]", 7);            
            $body = match ($r[1]) {
                'json' => \json_decode($body, true),
                'igbinary' => \igbinary_unserialize($body),
                'msgpack' => \msgpack_unpack($body),
                default => $body,
            };
        }
        return $body;
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