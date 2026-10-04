#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."

# Never reuse or stop an existing listener. All processes and data below belong to this run.
php -r 'foreach ([18991,18992,18995] as $port) { $s=@fsockopen("127.0.0.1",$port,$errno,$error,0.2); if ($s) { fwrite(STDERR,"Fixture port $port is already in use.\n"); exit(1); } }'
fixture_root=$(mktemp -d /tmp/oidc-sso.XXXXXX)
export OIDC_TEST_RUNTIME="$fixture_root/host"
fixture_pids=()
cleanup() {
  for fixture_pid in "${fixture_pids[@]}"; do kill "$fixture_pid" 2>/dev/null || true; done
  for fixture_pid in "${fixture_pids[@]}"; do wait "$fixture_pid" 2>/dev/null || true; done
  echo "Private fixture data and results: $OIDC_TEST_RUNTIME"
}
trap cleanup EXIT
mkdir -m 700 "$fixture_root/redis"
redis-server --port 0 --unixsocket "$fixture_root/redis/redis.sock" --save '' --appendonly no \
  --dir "$fixture_root/redis" >"$fixture_root/redis.log" 2>&1 &
fixture_pids+=("$!")
for ((attempt=0; attempt<50; attempt++)); do
  [[ -S "$fixture_root/redis/redis.sock" ]] && break
  sleep 0.1
done
[[ -S "$fixture_root/redis/redis.sock" ]]
php tests/Integration/setup.php "$OIDC_TEST_RUNTIME" "$fixture_root/redis/redis.sock"
# The HTTP host has not started: a real connection failure must fail the acceptance command.
if php tests/Integration/sso.php >"$fixture_root/expected-failure.log" 2>&1; then
  echo 'SSO acceptance incorrectly returned success for a failed request.' >&2
  exit 1
fi
echo 'SSO failure exit status: passed.'
mkdir "$OIDC_TEST_RUNTIME/rp"
cp tests/Integration/node/package{,-lock}.json "$OIDC_TEST_RUNTIME/rp/"
(cd "$OIDC_TEST_RUNTIME/rp" && npm ci --no-audit --no-fund)
unset PHP_CLI_SERVER_WORKERS
php -S 127.0.0.1:18991 tests/Integration/server.php >"$fixture_root/host.log" 2>&1 &
fixture_pids+=("$!")
node tests/Integration/upstream.mjs >"$fixture_root/upstream.log" 2>&1 &
fixture_pids+=("$!")
ready() {
  for ((attempt=0; attempt<100; attempt++)); do
    if curl --fail --silent --output /dev/null "$1"; then return; fi
    sleep 0.1
  done
  echo "Fixture did not become ready: $1" >&2
  return 1
}
ready http://127.0.0.1:18991/.well-known/openid-configuration
ready http://127.0.0.1:18995/.well-known/openid-configuration
node tests/Integration/rp.mjs >"$fixture_root/rp.log" 2>&1 &
fixture_pids+=("$!")
ready http://127.0.0.1:18992/last-result
php tests/Integration/sso.php
