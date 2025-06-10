# SPARTAN-TEST - Config File Options

Upon start web test search for `stest-config.json` or `stest-config.local.json` in current or parent directories\
Finally it loads [bundled config](https://github.com/parf/spartan-test/blob/main/src/config.json) from its own directory

Typical Config Looks like:
```
{
 "init"    : ["bootstrap/autoload.php", "vendor/autoload.php", "init.php"],  # where to look and in what order for autoload methods
 "reporter" :"stest\\helper\\Reporter",   # CLASS to use for Reporter
 "webtest" :"hb\\WebTest"   # CLASS to use for Web Tests

# Optional variables
 "realm"   : realm to use for Web Tests
 "realmUriMethod":     your "Class::method" to convert realm and domain INTO url
 "realmDetectMethod":  your "Class::method" to detect realm (like from server name or your config)
 "errorCallback": Class::method to enrich every error results. see docs below
}
```

To extend STest - extend standard STest class with your functionality, specify new class in config file

For example: extend Reporter - send Slack messages when stest finished with Alert or Error


{
 "stest"   :"STest",       # CLASS to use for Base methods
}


## errorCallback details

/**
  * @param string $err - error message
  * @param int $line - line number where error occurred
  * @param string $code - code where error occurred
  * @param array $ARG - STEST arguments
  * @return array ['item to add' => 'value', ...]
  */
static function errorCallback(string $err, int $line, string $code, array $ARG): array;
