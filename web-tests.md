# Web Tests

Let you simulate request-response activity on sites\
You can anazyze results: search for substrings, check http codes, redirects and more\
All cookies preserved - so you can emulate logins, and test registered users areas\
Every time we got results from remote web-server we check them for php-errors blocks (see WebTest::test_PHPError() method)\
and then we apply all user checks specified in your stest

see more in [examples/web-test](https://github.com/parf/spartan-test/blob/main/examples/3-web-tests/)

# Running Web-test on different servers - Testing Realms

Simplest way to run a web-test on a different server is to use `--domain="domain-to-run-test.com"`\
Command line option will override `STest::domain()` setting

Alternative way is to use **REALMS**:
By default resulting realm-url will be: `$realm.yourdomain.com`

You can specify `realm` in (sorted by priority):
1. `--realm=my-realm`
2. SHELL environment variable `$STEST_REALM`
    *  run test as:   `STEST_REALM=my-realm ./filename.stest`
    *  or set realm somewhere in ~/.profile and just run stest
5. provide `"realm"="value"` in stest config files: `stest-config.json` or `stest-config.json.local` 
6. provide `"realmDetectMethod"="Class::method"` in stest config files: `stest-config.json` or `stest-config.json.local` 

### Custom Realm-URLs - provide your own callback
specify `"realmUriMethod"="Class::method"` in stest config files: `stest-config.json` or `stest-config.json.local` 


## Under the hood
Web test uses `STest::$DOMAIN` as a current domain.\
You can change its value at any time or add any custom logic around it

`STest::domain()` method implement realm/domain magic:
- then it assigns calculated domain to STest::$DOMAIN
- then runs `\hb\Curl::test(STest::$DOMAIN, 'stop' | 'fail')`

see [example with docs](https://github.com/parf/spartan-test/blob/main/examples/3-web-tests/)
