# Web Tests

allows you to simulate activity on sites, then test apply tests on results
All cookies preserved, so you can do logins, and test registered users areas.

see more in [examples/web-test](https://github.com/parf/spartan-test/blob/main/examples/3-web-tests/)

# Running Web-test on different servers - Testing Realms

Simplest way to run a web-test on a different server is to use `--domain="domain-to-run-test.com"`\
Command line option will override `STest::domain()` setting

Alternative way is to use **REALMS**: `--realm=my-realm`\
By default resulting realm-url will be: `my-realm.example.com`

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
Web test uses `STest::$DOMAIN` as current domain.\
You can still change its value at any time or add any custom logic around it

`STest::domain()` method implement realm/domain magic, then runs `\hb\Curl::test(STest::$DOMAIN , 'stop' | 'fail')`


1. start your test with `; STest::domain("example.com");`
2. `/path` or `/path?arg=value` or `/path ['arg' => 'value', ...]` will fetch page from url
3. `POST /path` will do post 

see [example with docs](https://github.com/parf/spartan-test/blob/main/examples/3-web-tests/)
