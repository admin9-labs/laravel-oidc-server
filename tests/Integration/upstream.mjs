// Disposable real upstream OP. oidc-provider owns sessions and authentication timestamps.
import { createServer } from 'node:http';
import { generateKeyPairSync, randomBytes, scryptSync, timingSafeEqual } from 'node:crypto';
import { appendFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

const runtime = process.env.OIDC_TEST_RUNTIME;
if (!runtime) throw new Error('Set OIDC_TEST_RUNTIME.');
const { Provider } = await import(pathToFileURL(`${runtime}/rp/node_modules/oidc-provider/lib/index.js`).href);
const issuer = 'http://127.0.0.1:18995';
const passwordHash = scryptSync('upstream-password', 'disposable-fixture-salt', 64);
const forms = new Map();
const signingKey = generateKeyPairSync('rsa', { modulusLength: 2048 }).privateKey.export({ format: 'jwk' });
const provider = new Provider(issuer, {
  clients: [{ client_id: 'fixture-host', redirect_uris: ['http://127.0.0.1:18991/sso/callback'],
    response_types: ['code'], grant_types: ['authorization_code'], token_endpoint_auth_method: 'none', require_auth_time: true }],
  jwks: { keys: [{ ...signingKey, kid: 'fixture-upstream', alg: 'RS256', use: 'sig' }] },
  features: { devInteractions: { enabled: false } },
  cookies: { keys: [randomBytes(32).toString('hex')] },
  pkce: { required: () => true },
  interactions: { url: (_ctx, interaction) => `/interaction/${interaction.uid}` },
  findAccount: async (_ctx, id) => id === 'upstream-member'
    ? { accountId: id, claims: async () => ({ sub: id }) } : undefined,
});
const callback = provider.callback();
const escape = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
createServer(async (request, response) => {
  if (!request.url.startsWith('/interaction/')) return callback(request, response);
  response.setHeader('Cache-Control', 'no-store');
  response.setHeader('Referrer-Policy', 'no-referrer');
  try {
    const { uid, prompt, params, session, grantId } = await provider.interactionDetails(request, response);
    if (!forms.has(uid)) forms.set(uid, randomBytes(24).toString('hex'));
    if (request.method === 'GET') {
      response.setHeader('Content-Type', 'text/html; charset=utf-8');
      response.end(`<!doctype html><html lang="en"><title>Upstream SSO</title><h1>Upstream ${escape(prompt.name)}</h1>
        <form method="post"><input type="hidden" name="csrf" value="${forms.get(uid)}">
        ${prompt.name === 'login' ? '<label>Email <input name="email" type="email"></label><label>Password <input name="password" type="password"></label>' : '<p>Allow the local Laravel host to sign you in?</p>'}
        <button>${prompt.name === 'login' ? 'Sign in' : 'Allow'}</button></form></html>`);
      return;
    }
    let body = '';
    for await (const chunk of request) {
      body += chunk;
      if (body.length > 4096) throw new Error('Body too large');
    }
    const form = new URLSearchParams(body);
    if (request.method !== 'POST' || form.get('csrf') !== forms.get(uid)) throw new Error('Invalid form');
    if (prompt.name === 'login') {
      if (form.get('email') !== 'member@upstream.test'
          || !timingSafeEqual(scryptSync(form.get('password') ?? '', 'disposable-fixture-salt', 64), passwordHash)) {
        response.writeHead(401).end('Invalid upstream credentials');
        return;
      }
      // Do not supply login.ts or auth_time: the provider records the real event itself.
      await appendFile(`${runtime}/upstream-events.jsonl`, JSON.stringify({ event: 'password_verified', observed_at: Date.now() }) + '\n');
      forms.delete(uid);
      await provider.interactionFinished(request, response, { login: { accountId: 'upstream-member' } }, { mergeWithLastSubmission: false });
    } else if (prompt.name === 'consent') {
      const grant = grantId ? await provider.Grant.find(grantId)
        : new provider.Grant({ accountId: session.accountId, clientId: params.client_id });
      if (prompt.details.missingOIDCScope) grant.addOIDCScope(prompt.details.missingOIDCScope.join(' '));
      if (prompt.details.missingOIDCClaims) grant.addOIDCClaims(prompt.details.missingOIDCClaims);
      forms.delete(uid);
      await provider.interactionFinished(request, response, { consent: { grantId: await grant.save() } }, { mergeWithLastSubmission: true });
    } else throw new Error('Unsupported interaction');
  } catch (error) {
    if (!response.headersSent) response.writeHead(400);
    response.end('Upstream interaction failed');
    console.error(error.message);
  }
}).listen(18995, '127.0.0.1', () => console.log(`Upstream OP listening at ${issuer}`));
