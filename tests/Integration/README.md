# Local HTTP acceptance fixture

This is an isolated test host, not a production authentication implementation. Fault tests require ext-redis, ext-posix, ext-pcntl and a dedicated redis-server process. It uses a real Laravel HTTP kernel, Redis sessions/locks/freshness state, SQLite, password verification and a TOTP factor. Its independent RP runs `openid-client` with ID Token signature verification enabled. No production database or credential is used.

Start a dedicated Redis process on a private Unix socket. Choose new runtime directories and unused loopback ports (the fixture currently uses host `18991`, RP `18992`):

```sh
mkdir -m 700 /tmp/oidc-acceptance-redis
redis-server --port 0 --unixsocket /tmp/oidc-acceptance-redis/redis.sock \
  --save '' --appendonly no --dir /tmp/oidc-acceptance-redis
```

In another terminal, initialize a **new** fixture directory:

```sh
php tests/Integration/setup.php /tmp/oidc-acceptance-host /tmp/oidc-acceptance-redis/redis.sock
export OIDC_TEST_RUNTIME=/tmp/oidc-acceptance-host
mkdir "$OIDC_TEST_RUNTIME/rp"
cp tests/Integration/node/package{,-lock}.json "$OIDC_TEST_RUNTIME/rp/"
(cd "$OIDC_TEST_RUNTIME/rp" && npm ci --no-audit --no-fund)
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:18991 tests/Integration/server.php
```

Start the RP in another terminal with the same environment variable:

```sh
OIDC_TEST_RUNTIME=/tmp/oidc-acceptance-host node tests/Integration/rp.mjs
```

The local test accounts are `admin@fixture.test` and `member@fixture.test`, both with password `fixture-password`. The generated TOTP secret is only in the private runtime settings. Generate the current code locally without printing that secret:

```sh
php -r 'require "vendor/autoload.php"; $s=json_decode(file_get_contents(getenv("OIDC_TEST_RUNTIME")."/settings.json"),true); echo Admin9\OidcServer\Tests\Integration\Totp::code($s["totp_secret"]), PHP_EOL;'
```

Use the default browser skill for interactive acceptance:

1. Sign in to Admin on the host, then open the RP at `http://127.0.0.1:18992/`.
2. Start `max_age=0` authorization. A correct password with an incorrect factor must fail before creating code/token records. Then complete both factors and consent.
3. The RP verifies discovery, PKCE S256, state, nonce, issuer, audience and JWT signature. Inspect its success page and refresh; refresh must preserve authentication time and omit nonce.
4. Revisit the host and confirm both Admin and Member remain signed in.
5. Verify `prompt=none` yields `consent_required` for a known record and `prompt=none&max_age=0` yields `login_required`, without host interaction. Verify positive `max_age` causes reauthentication when expired.

The RP records claims and validation outcomes in `rp-results.jsonl`, without access/refresh tokens. `GET /last-result` is a local test observation endpoint, not a production API.

`openid-client` applies its own authentication-age check at code exchange when `maxAge` is supplied. Leaving `max_age=0` consent open past the RP's tolerance causes RP rejection even though the OP correctly does not repeat an already-satisfied zero-age challenge. Complete that case continuously; do not change the OP's recorded time to satisfy the RP. Separately verify that OP code/refresh endpoints do not reapply the original max_age.

## Full HTTP concurrency and fault injection

Start a second HTTP process group with the same runtime on port 18993, then add `"peer_origin": "http://127.0.0.1:18993"` to the private runtime settings. Both groups use the same issuer, keys, Redis and SQLite database. Use fresh groups with at least four workers before each run: the test deliberately kills three handling workers with SIGKILL.

```sh
OIDC_TEST_RUNTIME=/tmp/oidc-acceptance-host PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:18993 tests/Integration/server.php
OIDC_TEST_RUNTIME=/tmp/oidc-acceptance-host php tests/Integration/concurrency.php
```

The script writes `concurrency-results.json` and fails with a nonzero exit status on a failed assertion. It covers challenge/approval/code/refresh competition, a real ten-second session-lock lease expiring during a twelve-second request, a pre-rotation request saving its old session late, missing positive state, and worker death after three consumption points. Requests after crashes are retried on the peer. Killed worker PIDs are checked; ending a PHP request alone is insufficient.

For storage outage, pass the exact PID printed by the **dedicated** Redis startup. The script refuses a different PID or a socket outside `/tmp/oidc-*`, stops that Redis, and verifies no new code/token or refresh revocation:

```sh
OIDC_TEST_RUNTIME=/tmp/oidc-acceptance-host php tests/Integration/storage-outage.php "$OIDC_FIXTURE_REDIS_PID"
```

Restart the dedicated Redis afterward. Its new run_id invalidates old freshness state even if a stale Redis snapshot is restored. The unit process test exercises that snapshot rollback explicitly. The nonpersistent settings above are for a disposable fixture; production availability and supported topology still need host validation.

## Independent RP legacy recovery

Create a valid native/legacy refresh fixture, then use the RP's “Recover from legacy refresh” button. The RP expects invalid_grant and starts a new authorization.

```sh
OIDC_TEST_RUNTIME=/tmp/oidc-acceptance-host php tests/Integration/legacy-token.php
```

## Actual baseline rollback and forward recovery

1. Export `48b01c5` into a disposable directory using `git archive`.
2. Create an unrevoked legacy sample, touch `$OIDC_TEST_RUNTIME/issuance-gate.closed`, drain test traffic and stop **all** current host workers on 18991/18993.
3. Start the baseline only, using the current fixture router with `OIDC_TEST_BASELINE` pointing to that export:

```sh
OIDC_TEST_RUNTIME=/tmp/oidc-acceptance-host OIDC_TEST_BASELINE=/path/to/disposable-baseline php -S 127.0.0.1:18994 tests/Integration/server.php
OIDC_TEST_RUNTIME=/tmp/oidc-acceptance-host php tests/Integration/rollback.php
```

The rehearsal proves the closed gate blocks authorization and old refresh without database changes. Its controlled open-gate check proves the actual old package accepts the old refresh but rejects max_age; it closes the gate again and saves a rollback-produced refresh privately.

4. Stop the baseline. Start both current 2.0 groups with the gate still closed and no baseline environment variable. Run:

```sh
OIDC_TEST_RUNTIME=/tmp/oidc-acceptance-host php tests/Integration/forward-recovery.php
```

The script checks both nodes' discovery, opens the gate, verifies old-format rejection without revocation, and completes a new password/TOTP authorization with real auth_time. On failure it closes the gate. These are local fixtures, not production operational acceptance.

## Real upstream SSO acceptance

With PHP dependencies installed, Node >=22.12, npm, ext-redis and redis-server available, run the complete isolated SSO check:

```sh
bash tests/Integration/run-sso.sh
```

It requires free loopback ports 18991, 18992 and 18995, installs the committed npm lockfile into a private temporary runtime, and stops only its own processes on exit. Before starting the HTTP host, it verifies that a failed connection returns a nonzero exit status, then runs the successful SSO scenario. Results remain in the printed runtime's `sso-results.json`. Run it without other browser flows against that fixture. It exits nonzero on any failed assertion.

The upstream OP is pinned `oidc-provider 9.12.2`, with a real password check, fresh RSA signing key, its own SSO session and provider-generated authentication time. Its client requires `auth_time`. The host callback uses `openid-client 6.8.8` to exchange the code and verify issuer, audience, state, nonce, PKCE and ID Token signature before calling the recorder. The provider interaction follows its [documented API](https://github.com/panva/node-oidc-provider/blob/v9.12.2/docs/README.md#user-flows); it never supplies `login.ts` or an invented `auth_time`.

The check first rejects a wrong password, then establishes a real upstream session. After wall time advances, a new downstream challenge deliberately uses upstream `prompt=none`: the newly signed assertion retains the previous event time, the package rejects it with 400, and code/token counts stay unchanged. Callback replay and unrelated state also fail. A new downstream challenge using upstream `prompt=login` requires credentials again; the independent RP verifies the resulting ID Token, exact upstream authentication time, and refresh preserving that time without nonce. Admin/Member session isolation is checked too.

### Manual GitHub Actions run

Once the workflow is available on the repository's default branch, open **Actions → Integration SSO → Run workflow** and select the ref to validate. It runs only on `workflow_dispatch`, independently of the seven-combination PHPUnit/Pest matrix. The Ubuntu job uses PHP 8.3, Laravel 13, Passport 13, Testbench 11, Pest 4, PHPUnit 12 and Node 22.x, with Redis, DOM and SQLite support. It has a 15-minute timeout, read-only repository permissions and requires no production credentials.

The job invokes the same `run-sso.sh`, including its connection-failure exit-status control and the committed npm lockfile. A failed assertion or setup failure fails the job. Successful runs retain only the sanitized `sso-results.json` under the `sso-results-<commit SHA>` artifact for seven days; private keys, settings, sessions and token-bearing runtime files are not uploaded. The runner prints its private runtime path for local inspection. Check the run's commit and resolved dependencies when recording acceptance evidence.

This workflow covers real upstream SSO, the independent RP and the scenarios described above. Cross-process fault injection, storage outage, rollback and forward recovery remain separate acceptance runs. A local runner pass does not establish a remote workflow pass; record remote acceptance only after an actual successful run.

For interactive browser acceptance with the earlier manual fixture (which already installs both pinned Node dependencies), also start:

```sh
OIDC_TEST_RUNTIME=/tmp/oidc-acceptance-host node tests/Integration/upstream.mjs
```

In the same browser, visit the host's `/sso/start`; use `member@upstream.test` / `upstream-password`, then allow upstream consent. Visit `/sso/mode/silent`, then the RP's `/start?max_age=0&prompt=login`: expect local rejection without another credential screen. Visit `/sso/mode/fresh`, restart the same RP request, sign in at the upstream OP, approve downstream consent and refresh at the RP. `/sso/mode/password` restores the password/TOTP handler. These mode routes and fixed subject mapping are test-only.

The disposable upstream uses an in-memory adapter and loopback HTTP. It proves actual protocol/session behavior locally, not production IdP deployment. Business-host identity mapping, factors, active-authentication semantics, TLS, shared-state topology and cutover must be accepted separately with that host's provider. In particular, a signature and timestamp alone cannot establish active authentication: the host must verify the provider honored this challenge; second-level equality does not prove a new event.

Stop the RP, PHP workers and dedicated Redis when finished. The runtime directory contains test private keys and temporary sessions; never commit it.
