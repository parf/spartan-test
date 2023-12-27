<?php
# run as: php -S 127.0.0.2:8080 -d display_errors=1 ./web-server.php


function p($d, $header="") {
    if (! $d)
        return;
    echo $header." ".json_encode($d, /* JSON_PRETTY_PRINT | */ JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
};


Pages::_dispatch($_SERVER['SCRIPT_NAME']);

class Pages {

    PUBLIC static function _dispatch(string $path) {
        $W = new Pages();
        $p = str_replace(["-", ".", "/"], "_", trim($path, "/")) ?: "index";
        if ($p[0] == '_')
            die("internal-only path: '$path' - '$p'");
        $m = [$W, $p];
        if (! is_callable($m))
            $m = [$W, '_default'];
        $m($_GET + $_POST);
        die;
    }

    function index() {
        echo "Welcome to test server";
    }

    function _default() {
        header("HTTP/1.0 404 Not Found");
        echo "Page \"".$_SERVER['REQUEST_URI']."\" Not Found";
    }

    function info() {
        $s = $_SERVER;
        echo join(" ", [$s['REQUEST_METHOD'], $s['REQUEST_URI'], $s['SERVER_PROTOCOL']])."\n";
        p($_POST, "POST");
        p($_GET, "GET");
        p($_COOKIE, "COOKIE");
    }

    // @param: k, v
    function setcookie($kv) {
       setcookie($kv['k'], $kv['v']);
       setcookie($kv['k']."_", $kv['v']);
       echo "cookie set";
    }

   function cookies() {
        p($_COOKIE, "COOKIE");
   }

   function SERVER($kv) {
        if ($f = @$kv['field'])
            $_SERVER = [$f => $_SERVER[$f]];
        p($_SERVER, "_SERVER");
   }

    function redirect($kv) {
        $url  = @$kv['url'] ?? 'http://example.com/myOtherPage.php';
        $code = @$kv['code'] ?? 302;
        header("Location: $url", true, $code);
    }

    function code($kv) {
        $url = @$kv['url'] ?? 'http://example.com/myOtherPage.php';
        header("Location: $url");
    }

    function notice() {
        $a['d'];
    }

    function notice2() {
        trigger_error("user notice", E_USER_NOTICE);
    }

    function warning() {
        1/0;
    }

    function warning2() {
        trigger_error("user warning", E_USER_WARNING);
    }

    function error() {
        xxx();
    }

    function error2() {
        trigger_error("user error", E_USER_ERROR);
    }

    function error3() {
        $a = null;
        $a->method();
    }

    function parseError() {
        eval("sdfsd"); // Parse error
    }

    function empty_response() {
    }

    function non_empty_response() {
        echo "bla bla";
    }

    function sleep($t = 1) {
        sleep($t);
        echo $t;
    }


    static $sample_array = ["a" => ["b" => "some data"], "c" => [1,2,3], 'd' => 3.141];

    /**
     * data + GZip
     */
    function dataGzip() {
        header('Content-Encoding: gzip');
        header('Content-Type: application/octet-stream');
        $data = \gzencode("sample gzip text");
        header('Content-Length: ' . strlen($data));
        echo $data;
    }

    function dataGzipTest() {
        header('Content-Type: application/octet-stream');
        header('Content-Encoding: gzip-test');
        echo \gzencode("sample gzip text");
    }

    /**
     * JSON Data + ZSTD
     */
    function dataZstd() {
        header('Content-Type: application/octet-stream');        
        header('Content-Encoding: zstd');
        echo \zstd_compress("sample zstd text");
    }

    /**
     * JSON Data
     */
    function jsonData() {
        header('Content-Type: application/json; charset=utf-8');
        echo \json_encode(self::$sample_array);
    }

    /**
     * msgpack
     */
    function msgpackData() {
        header('Content-Type: application/msgpack');
        echo \msgpack_pack(self::$sample_array);
    }

    /**
     * msgpack + zstd
     */
    function msgpackDataZstd() {
        header('Content-Type: application/msgpack');
        header('Content-Encoding: zstd');
        echo \zstd_compress(msgpack_pack(self::$sample_array));
    }

    /**
     * igbinary
     */
    function igbinaryData() {
        header('Content-Type: application/igbinary');
        echo igbinary_serialize(self::$sample_array);
    }

    /**
     * igbinary
     */
    function igbinaryDataZstd() {
        header('Content-Type: application/igbinary');
        header('Content-Encoding: zstd');
        echo zstd_compress(igbinary_serialize(self::$sample_array));
    }

    /**
     * JSON Data + GZip
     */
    function jsonDataGzip() {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Encoding: gzip');
        echo gzencode(json_encode(self::$sample_array));
    }

    /**
     * JSON Data + ZSTD
     */
    function jsonDataZstd() {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Encoding: zstd');
        echo zstd_compress(json_encode(self::$sample_array));
    }


    /**
     * parse JSONPOST, echo it back as JSON
     */
    function jsonPost() {
        $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        ksort($data);
        $data += ['server-added-node' => [3,1,4]];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_PRETTY_PRINT);
    }
    
    /**
     * return some text back
     */
    function jsonPost2() {
        $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        $first_field = key($data);
        echo "your JSONPOST has ".count($data)." fields in query; first field: $first_field";
    }

    /**
     * parse JSONPOST, echo it back as JSON
     */
    function jsonPostZstd() {
        $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        ksort($data);
        $data += ['server-added-node' => [3,1,4]];
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Encoding: zstd');
        echo zstd_compress(json_encode($data));
    }


    # for xquery tests
    function html() {
	    echo "<html>
		    <head><title>page title</title></head>
		    <body>
                       <h1>header #1 in body</h1>
		       <h1>second h1</h1>
                       <a href=\"http://google.com\">external-link</a>
                       <a href=/some-page>external-link2</a>
                    </body>
                 </html>";
    }

    function some_page() {
        echo "this is some-page, ur welcome";
    }

}
