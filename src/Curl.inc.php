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
        \STest::debug(" - Curl::$method url='".$curl_opts[CURLOPT_URL]."'", 2);
	if ($curl_opts[CURLOPT_POSTFIELDS] ?? 0)
            \STest::debug(" - postfields params='".$curl_opts[CURLOPT_POSTFIELDS]."'", 3);
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

