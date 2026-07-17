#!/usr/bin/env bash

set -uo pipefail

usage() {
  echo "Usage: $0 [--check] [--test] [--expected=VERSION] [HOST...]" >&2
}

script_dir=$(cd "$(dirname "$0")" && pwd)
repo=$(cd "$script_dir/../../../.." && pwd)
deploy="$repo/.codex/skills/deploy/scripts/deploy.sh"
check_only=0
run_tests=0
hosts=()
standard_hosts=(p4 rdvp t4cre-stage t4cre-rc t4cre-prod t4test)
expected=

while (($#)); do
  case $1 in
    --check) check_only=1 ;;
    --test) run_tests=1 ;;
    --expected=*) expected=${1#--expected=} ;;
    --help|-h) usage; exit 0 ;;
    --) shift; hosts+=("$@"); break ;;
    -*) echo "Unknown option: $1" >&2; usage; exit 2 ;;
    *) hosts+=("$1") ;;
  esac
  shift
done

((${#hosts[@]})) || hosts=("${standard_hosts[@]}")
if [[ -z $expected ]]; then
  expected_output=$("$repo/bin/stest" --version) || exit 1
  expected=${expected_output%% *}
fi
status=0

if ((check_only == 0)); then
  if [[ -x /hbfr/homebase/php-tools/php-tools ]]; then
    if ! TERM="${TERM:-xterm}" /hbfr/homebase/php-tools/php-tools update spartan-test; then
      echo "localhost user install update failed" >&2
      status=1
    fi
  fi

  deploy_args=("--expected=$expected")
  ((run_tests == 1)) && deploy_args+=(--test)
  "$deploy" "${deploy_args[@]}" "${hosts[@]}" || status=1
fi

printf '\n%-18s %-50s %s\n' HOST PATH VERSION
printf '%-18s %-50s %s\n' '------------------' '--------------------------------------------------' '------------------------------'

check_local() {
  local label=$1 bin=$2 resolved version
  if [[ ! -x $bin ]]; then
    printf '%-18s %-50s %s\n' "$label" "$bin" MISSING
    status=1
    return
  fi
  resolved=$(readlink -f "$bin")
  version=$($bin --version 2>/dev/null || true)
  printf '%-18s %-50s %s\n' "$label" "$resolved" "$version"
  [[ ${version%% *} == "$expected" ]] || status=1
}

active_local=$(command -v stest 2>/dev/null || true)
check_local localhost "${active_local:-/nonexistent/stest}"
if [[ -x /usr/local/bin/stest ]] && [[ $(readlink -f /usr/local/bin/stest) != $(readlink -f "${active_local:-/nonexistent/stest}") ]]; then
  check_local localhost-system /usr/local/bin/stest
fi

for host in "${hosts[@]}"; do
  result=$(ssh "$host" 'bash -s' <<'REMOTE' 2>/dev/null
set -e
bin=$(command -v stest)
printf '%s\t%s' "$(readlink -f "$bin")" "$($bin --version)"
REMOTE
  ) || result=
  if [[ -z $result ]]; then
    printf '%-18s %-50s %s\n' "$host" - UNREACHABLE
    status=1
    continue
  fi
  path=${result%%$'\t'*}
  version=${result#*$'\t'}
  printf '%-18s %-50s %s\n' "$host" "$path" "$version"
  [[ ${version%% *} == "$expected" ]] || status=1
done

exit "$status"
