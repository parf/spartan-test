---
name: sync-versions
description: Update localhost and the standard Spartan Test server fleet to the latest published release, then compare active stest versions. Use when asked to "update all to latest", synchronize versions, check version drift, or show versions across localhost, active realmo-* developer hosts, p5, t5cre-stage, t5cre-rc, t5cre-prod, and t5cre-test.
---

# Sync Spartan Test Versions

Run from the Spartan Test repository root.

## Standard Fleet

`localhost`, `p5`, every active `realmo-*` developer host from the colo inventory,
`t5cre-stage`, `t5cre-rc`, `t5cre-prod`, and `t5cre-test`.

The script reads `/hbfr/boston-colo/ve.list` (override with `STEST_VE_LIST`) and excludes
the service VEs `realmo-parse-raw` and `realmo-parse-reso`. The current deployment inventory
is `realmo-amironov`, `realmo-anar`, `realmo-dstep`,
`realmo-etruhanov`, `realmo-iaroslav`, `realmo-kkriazhev`, `realmo-nkamensky`,
`realmo-parf`, `realmo-sryzhkov`, `realmo-vbatrak`, and `realmo-ykononov`.

## Workflow

1. Verify the current repository release is committed, tagged, pushed, and published.
2. To update and verify the standard fleet, run:

   ```bash
   .codex/skills/sync-versions/scripts/sync_versions.sh
   ```

3. To check without updating, use `--check`.
4. Add `--test` only when the user requests remote test suites; version synchronization
   itself always verifies `stest --version`.
5. Use `--expected=VERSION` when the working tree is ahead of the latest published
   release and the fleet must remain on that published version.
6. Hosts supplied after options replace the standard remote host list:

   ```bash
   .codex/skills/sync-versions/scripts/sync_versions.sh p5 t5cre-test
   ```

7. Report a compact version table. Call out unreachable hosts and any version mismatch.

## Localhost Rules

- Check both the active `stest` from PATH and `/usr/local/bin/stest` when present.
- Update `/hbfr/homebase/php-tools` when installed because `$HOME/bin/stest` may shadow
  `/usr/local/bin/stest`.
- Do not discard local changes in tool checkouts. Stop and report the dirty checkout;
  only remove a temporary change when it was made by the current task and is now safely
  present in the published release.
- Use the bundled deploy script for remote updates so PATH, sudo, Bash, and exact-version
  checks remain consistent.
