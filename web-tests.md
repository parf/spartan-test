# Web Tests

allows you to simulate activity on sites, then test apply tests on results

1. start your test with `; STest::domain("example.com");`
2. `/path` or `/path?arg=value` or `/path ['arg' => 'value', ...]` will fetch page from url
3. `POST /path` will do post 

All cookies preserved, so you can do logins, and test registered users areas.

see more examples / details in `examples/web-test`
