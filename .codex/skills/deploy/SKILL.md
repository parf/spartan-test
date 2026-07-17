---
name: deploy
description: "Prepare, publish, and deploy a Spartan Test release: update docs and CHANGELOG, bump version/build date, run tests, review changes, commit/tag/push, verify Packagist, update named SSH hosts through php-tools, and verify active versions. Use for requests such as bump/test/publish, deploy to p4, update stest on stage, or ship Spartan Test."
---

# Release And Deploy Spartan Test

Run from the Spartan Test repository root.

## Choose The Workflow

- For an already-published release, skip directly to **Deploy Hosts**.
- For a bump, publish, or release request, complete **Prepare Release** and **Publish**
  before deploying any shared host.

## Prepare Release

1. Inspect `git status`, recent releases, current `VERSION`, `DATE_BUILD`, and the exact
   version assertion in `src/test/exit-status.stest`. Preserve unrelated user changes.
2. Review the full release scope and update all affected user/developer docs and runnable
   examples. Update `CHANGELOG` with one dated release entry describing behavior, not
   implementation trivia.
3. Set `VERSION` and `DATE_BUILD` in `src/STest.php`. Update the exact `stest --version`
   assertion in `src/test/exit-status.stest`.
4. Run release checks:

   ```bash
   php -l src/Helpers.inc.php
   php -l src/STest.php
   bash -n bin/stest-all
   shellcheck bin/stest-all
   composer validate --no-check-publish
   bin/stest-all -q src/test
   git diff --check
   ```

5. Run focused examples/tests for the changed behavior. For parser changes, verify
   `stest --cat` round trips without rewriting the fixture.
6. Review the complete diff for regressions, stale version strings, unsupported claims,
   accidental generated output, file modes, and unexpected files. Do not publish with a
   failing check or unexplained dirty state.

## Publish

1. Commit intentionally, normally as `Release VERSION`.
2. Create annotated tag `VERSION` with a concise release description.
3. Push `main`, then push the tag.
4. Verify remote branch/tag refs resolve to the release commit.
5. Verify Packagist metadata contains `VERSION` and the same source commit. Wait and
   retry briefly if its webhook has not completed.
6. Confirm the working tree is clean and `bin/stest --version` is exact.

Never bump, commit, tag, push, or publish unless the user requested that operation.

## Deploy Hosts

1. Confirm the requested target hosts. Never infer production targets that the user did
   not name.
2. Confirm the release is committed, tagged with the version from
   `src/STest.php`, pushed, and published before deploying shared hosts.
3. Run:

   ```bash
   .codex/skills/deploy/scripts/deploy.sh HOST...
   ```

4. Add `--test` when the user asks to test the deployment or when deploying to a
   non-production validation host:

   ```bash
   .codex/skills/deploy/scripts/deploy.sh --test p4
   ```

5. Report each host, resolved `stest` path, version, update result, and suite result.
   Treat a version mismatch or unreachable host as a failed deployment.

## Operational Rules

- Resolve `php-tools` before invoking sudo. Do not assume sudo preserves the user's
  PATH; `sudo php-tools ...` fails on some standard hosts.
- Support both updater generations: current `php-tools update spartan-test` and legacy
  `/usr/local/src/php-tools/update spartan-test`.
- Legacy checkouts may have divergent local commits. Set repository-local
  `pull.rebase=false` before the legacy update so Git preserves both histories with a
  merge; never reset the checkout.
- Execute remote commands through Bash because interactive remote shells may be Fish.
- Use `php-tools update spartan-test`; do not reset, replace, or clean a remote checkout.
- Do not silently ignore dirty checkout, pull, test, or version failures.
- The script deploys the release represented by the current repository. Use
  `--expected=VERSION` only when deliberately verifying a specific published release.
