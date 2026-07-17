#!/usr/bin/env bash

set -uo pipefail

usage() {
  echo "Usage: $0 [--test] [--expected=VERSION] HOST..." >&2
}

script_dir=$(cd "$(dirname "$0")" && pwd)
repo=$(cd "$script_dir/../../../.." && pwd)
run_tests=0
expected=
hosts=()

while (($#)); do
  case $1 in
    --test) run_tests=1 ;;
    --expected=*) expected=${1#--expected=} ;;
    --help|-h) usage; exit 0 ;;
    --) shift; hosts+=("$@"); break ;;
    -*) echo "Unknown option: $1" >&2; usage; exit 2 ;;
    *) hosts+=("$1") ;;
  esac
  shift
done

if ((${#hosts[@]} == 0)); then
  usage
  exit 2
fi

if [[ -z $expected ]]; then
  expected=$("$repo/bin/stest" --version) || exit 1
  expected=${expected%% *}
fi

status=0
for host in "${hosts[@]}"; do
  echo "==> $host"
  if ! ssh "$host" bash -s -- "$expected" "$run_tests" <<'REMOTE'
set -euo pipefail

expected=$1
run_tests=$2
php_tools=$(command -v php-tools 2>/dev/null || true)
legacy_update=

if [[ -z $php_tools ]]; then
  for candidate in /usr/local/bin/php-tools /usr/local/src/php-tools/php-tools /hbfr/homebase/php-tools/php-tools; do
    if [[ -x $candidate ]]; then
      php_tools=$candidate
      break
    fi
  done
fi

if [[ -z $php_tools ]]; then
  if [[ -x /usr/local/src/php-tools/update ]]; then
    legacy_update=/usr/local/src/php-tools/update
  else
    echo "php-tools updater not found" >&2
    exit 1
  fi
fi

stest_repo=/usr/local/src/php-tools/tools/spartan-test
if [[ -d $stest_repo/.git ]]; then
  repo_owner=$(stat -c '%U' "$stest_repo")
  sudo -u "$repo_owner" git -C "$stest_repo" config pull.rebase false
fi

if [[ -n $legacy_update ]]; then
  sudo env TERM="${TERM:-xterm}" "$legacy_update" spartan-test
else
  php_tools=$(readlink -f "$php_tools")
  sudo env TERM="${TERM:-xterm}" "$php_tools" update spartan-test
fi

stest_bin=$(command -v stest 2>/dev/null || true)
if [[ -z $stest_bin ]]; then
  echo "stest not found after update" >&2
  exit 1
fi

resolved=$(readlink -f "$stest_bin")
version=$($stest_bin --version)
printf 'path=%s\nresolved=%s\nversion=%s\n' "$stest_bin" "$resolved" "$version"

if [[ ${version%% *} != "$expected" ]]; then
  echo "version mismatch: expected '$expected', got '$version'" >&2
  exit 1
fi

if [[ $run_tests == 1 ]]; then
  root=$(cd "$(dirname "$resolved")/.." && pwd)
  "$root/bin/stest-all" -q "$root/src/test"
  echo "suite=pass"
fi
REMOTE
  then
    status=1
  fi
done

exit "$status"
