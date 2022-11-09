<?php

namespace hb;

use \STest;

/**
 * Curl wrappers
 */
class Curl {

    /**
     * test is service's port is active
     * @throws \stest\StopException
     */
    static function test(string $url, $level = "error")  {
        static $test_done = [];
        if ($test_done[$url]??0)
            return; // avoid extra tests
        $d = \parse_url($url);
        $host = $d['host'] ?? "";
        if (! $host)
            throw new \Error("can't parse hostname from url=$url");
        $port = $d['port'] ?? ( (\strtolower($d['scheme'] ?? "") === 'https') ? 443 : 80);
        \STest::debug(" - Curl::test($host:$port)", 2);
        $fp = @fsockopen($host, $port, $errno, $errstr, 5);
        if (! $fp) {
            \STest::$level("no service on `$url` port:$port : err#$errno '$errstr'");
            return;
        }
        $test_done[$url] = 1;
        fclose($fp);
    }

    static function get($url, array $params = [], array $curl_opts = [], array $opts = []) {
        return self::rq($url, $params, "GET", $curl_opts, $opts);
    }

    static function post($url, array $params = [], array $curl_opts = [], array $opts = []) {
        return self::rq($url, $params, "POST", $curl_opts, $opts);
    }

    /**
     *
     * perform curl request
     *
     * $method: GET/POST    -- @todo add other methods
     *
     * $opts:
     * 'headers' => 1  : get HTTP headers in ['headers'] as header => value
     * 'timeout' : curl connect/transfer timeouts
     * 'retries' : how many times to retry failed request (default 1)
     *
     * @return @see http://php.net/manual/en/function.curl-getinfo.php + ['body' => .., "headers" => ...] | ['error' => string | array ]
     *
     */
    static function rq($url, array $params = [], $method = "GET", array $curl_opts = [], array $opts = []) { #
        $timeout = ($opts['timeout']??0) ?: 5; // 5 sec
        $default = [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_USERAGENT => 'HB::Curl',

            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,

            // we intend to curl trusted sources, remove CERT checks
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,

            // CURLOPT_FOLLOWLOCATION => true,
            // CURLOPT_ENCODING       => "",       // handle all encodings
            // CURLOPT_AUTOREFERER    => true,     // set referer on redirect
            // CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,

        ];
        if ($opts['headers'] ?? 0)
            $curl_opts += [CURLOPT_HEADER => 1];

        if ($method === 'POST') {
            $curl_opts +=[
                CURLOPT_URL => $url,
                CURLOPT_POST => 1,
                // CURLOPT_POSTFIELDS => $params,     // 'multipart/form-data' : Super slow, seems like a Curl bug
                CURLOPT_POSTFIELDS => http_build_query($params),  // 'application/x-www-form-urlencoded'
                ];
        }
        if ($method === 'GET') {
            $dl = strpos($url, '?') ? "&" : "?";
            $curl_opts += [
                CURLOPT_URL => $url.($params ? $dl.http_build_query($params) : "")
                ];
        }
        $ch = curl_init();
        curl_setopt_array($ch, $curl_opts + $default);
        $data = false;
        foreach (range(1, $opts['retries'] ?? 1) as $r) {
            $data = curl_exec($ch);
            if ($data !== false)
                break;
            usleep(100000 << $r); // 0.1, ...
        }
        if (! $data)
            return ['error' => curl_error($ch)];
        $info = curl_getinfo($ch);

        if ($opts['headers'] ?? 0) {
            $h = [];
            $headers = substr($data, 0, $info['header_size'] ?? 0); // as string
            foreach (explode("\n", $headers) as $e) {
                $kv = explode(":", $e, 2);
                if (count($kv) == 1) {
                    $kv = ["", $kv[0]];
                }
                [$k, $v] = $kv;
                $k = trim($k);
                if (! $k)
                    continue;
                if ($v === NULL) { // no key - save to $headers[0]
                    $v = $k;
                    $k = 0;
                }
                $v = trim($v);
                if (isset($h[$k])) {
                    $h[$k] = (array) $h[$k];
                    $h[$k][] = $v;
                } else {
                    $h[$k] = $v;
                }
            }
            $info['headers'] = $h;
            $info['body'] = substr($data, $info['header_size']??0);
        } else {
            $info['body'] = $data;
        }
        curl_close($ch);
        return $info;
    }


} // Class Curl


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