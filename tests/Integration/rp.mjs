import { createServer } from 'node:http';
import { randomBytes, timingSafeEqual } from 'node:crypto';
import { readFile, appendFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

const runtime = process.env.OIDC_TEST_RUNTIME;
if (!runtime) throw new Error('Set OIDC_TEST_RUNTIME to the initialized fixture directory.');
const settings = JSON.parse(await readFile(`${runtime}/settings.json`, 'utf8'));
const client = await import(pathToFileURL(`${runtime}/rp/node_modules/openid-client/build/index.js`).href);
const config = await client.discovery(new URL(settings.issuer), settings.client_id,
  { token_endpoint_auth_method: 'none' }, client.None(),
  { execute: [client.allowInsecureRequests, client.enableNonRepudiationChecks] });
const sessions = new Map();
const random = () => randomBytes(24).toString('hex');
const escape = (value) => String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const page = (title, body) => `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>${escape(title)}</title></head><body><main><h1>${escape(title)}</h1>${body}</main></body></html>`;
let lastResult = null;
async function record(result) {
  lastResult = result;
  await appendFile(`${runtime}/rp-results.jsonl`, `${JSON.stringify(result)}\n`);
}

createServer(async (request, response) => {
  response.setHeader('Cache-Control', 'no-store');
  response.setHeader('Referrer-Policy', 'no-referrer');
  const url = new URL(request.url, 'http://127.0.0.1:18992');
  let sid = request.headers.cookie?.split(';').map((x) => x.trim()).find((x) => x.startsWith('oidc_rp='))?.slice(8);
  if (!sid || !sessions.has(sid)) {
    sid = random();
    sessions.set(sid, { csrf: random() });
    response.setHeader('Set-Cookie', `oidc_rp=${sid}; HttpOnly; SameSite=Lax; Path=/`);
  }
  const session = sessions.get(sid);
  try {
    if (url.pathname === '/start') {
      const verifier = client.randomPKCECodeVerifier();
      const state = random();
      const nonce = random();
      const parameters = { redirect_uri: settings.rp_callback, scope: 'openid profile email',
        code_challenge: await client.calculatePKCECodeChallenge(verifier), code_challenge_method: 'S256', state, nonce };
      for (const name of ['max_age', 'prompt']) {
        if (url.searchParams.has(name)) parameters[name] = url.searchParams.get(name);
      }
      session.pending = { verifier, state, nonce, parameters, started: Date.now() };
      response.writeHead(302, { Location: client.buildAuthorizationUrl(config, parameters).href });
      response.end();
    } else if (url.pathname === '/callback') {
      const pending = session.pending;
      delete session.pending;
      if (!pending || Date.now() - pending.started > 600_000) throw new Error('Missing or expired RP transaction.');
      const checks = { pkceCodeVerifier: pending.verifier, expectedState: pending.state, expectedNonce: pending.nonce };
      if (pending.parameters.max_age !== undefined) checks.maxAge = Number(pending.parameters.max_age);
      session.tokens = await client.authorizationCodeGrant(config, url, checks);
      const claims = session.tokens.claims();
      if (!Number.isInteger(claims.auth_time)) throw new Error('Missing real authentication time.');
      session.authTime = claims.auth_time;
      await record({ kind: 'authorization', result: 'passed', claims, parameters: pending.parameters });
      response.end(page('OIDC authorization verified', `<pre>${escape(JSON.stringify(claims, null, 2))}</pre>
        <form method="post" action="/refresh"><input type="hidden" name="csrf" value="${session.csrf}"><button>Refresh tokens</button></form>
        <p><a href="/">RP home</a></p>`));
    } else if (url.pathname === '/refresh' && request.method === 'POST') {
      let body = '';
      for await (const chunk of request) body += chunk;
      const csrf = new URLSearchParams(body).get('csrf') ?? '';
      if (csrf.length !== session.csrf.length || !timingSafeEqual(Buffer.from(csrf), Buffer.from(session.csrf))) throw new Error('Invalid RP CSRF.');
      const oldIssuedAt = session.tokens.claims().iat;
      session.tokens = await client.refreshTokenGrant(config, session.tokens.refresh_token);
      const claims = session.tokens.claims();
      if (claims.auth_time !== session.authTime || claims.nonce !== undefined || claims.iat < oldIssuedAt) throw new Error('Refresh authentication claims changed.');
      await record({ kind: 'refresh', result: 'passed', claims });
      response.end(page('OIDC refresh verified', `<pre>${escape(JSON.stringify(claims, null, 2))}</pre><p><a href="/">RP home</a></p>`));
    } else if (url.pathname === '/legacy-refresh' && request.method === 'POST') {
      let body = '';
      for await (const chunk of request) body += chunk;
      if (new URLSearchParams(body).get('csrf') !== session.csrf) throw new Error('Invalid RP CSRF.');
      const legacy = JSON.parse(await readFile(`${runtime}/legacy-refresh.json`, 'utf8'));
      try {
        await client.refreshTokenGrant(config, legacy.refresh_token);
        throw new Error('A legacy refresh token was accepted.');
      } catch (error) {
        if (error.error !== 'invalid_grant') throw error;
        await record({ kind: 'legacy-refresh', result: 'invalid_grant', recovery: 'start new authorization' });
        response.writeHead(302, { Location: '/start' });
        response.end();
      }
    } else if (url.pathname === '/last-result') {
      response.setHeader('Content-Type', 'application/json');
      response.end(JSON.stringify(lastResult));
    } else {
      response.end(page('Independent OIDC RP', `<p>openid-client verifies discovery, S256, state, nonce, issuer, audience and JWT signatures.</p>
        <ul><li><a href="/start">Authorize</a></li><li><a href="/start?max_age=0">Require new authentication</a></li>
        <li><a href="/start?max_age=60">Authentication within 60 seconds</a></li>
        <li><a href="/start?prompt=none">Silent authorization</a></li>
        <li><a href="/start?prompt=none&max_age=0">Silent new authentication</a></li></ul>
        <form method="post" action="/legacy-refresh"><input type="hidden" name="csrf" value="${session.csrf}"><button>Recover from legacy refresh</button></form>`));
    }
  } catch (error) {
    const result = { kind: 'error', result: error.error ?? error.code ?? 'failed', message: error.message };
    await record(result);
    response.statusCode = 400;
    response.end(page('OIDC request rejected', `<pre>${escape(JSON.stringify(result, null, 2))}</pre><a href="/">RP home</a>`));
  }
}).listen(18992, '127.0.0.1', () => console.log('Independent RP listening on http://127.0.0.1:18992'));
