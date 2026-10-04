// No tokens on command lines or stdout. Called by the real host callback over stdin.
import { pathToFileURL } from 'node:url';
const runtime = process.env.OIDC_TEST_RUNTIME;
const client = await import(pathToFileURL(`${runtime}/rp/node_modules/openid-client/build/index.js`).href);
let input = '';
for await (const chunk of process.stdin) input += chunk;
const { callback, verifier, state, nonce } = JSON.parse(input);
try {
  const config = await client.discovery(new URL('http://127.0.0.1:18995'), 'fixture-host',
    { token_endpoint_auth_method: 'none' }, client.None(),
    { execute: [client.allowInsecureRequests, client.enableNonRepudiationChecks] });
  const tokens = await client.authorizationCodeGrant(config, new URL(callback), {
    pkceCodeVerifier: verifier, expectedState: state, expectedNonce: nonce, idTokenExpected: true,
  });
  const claims = tokens.claims();
  if (claims.sub !== 'upstream-member' || !Number.isSafeInteger(claims.auth_time) || claims.auth_time < 0) {
    throw new Error('Missing upstream identity or authentication time');
  }
  process.stdout.write(JSON.stringify({ sub: claims.sub, auth_time: claims.auth_time, iat: claims.iat }));
} catch (error) {
  process.stderr.write(`Upstream assertion verification failed (${error.code ?? error.name})\n`);
  process.exitCode = 1;
}
