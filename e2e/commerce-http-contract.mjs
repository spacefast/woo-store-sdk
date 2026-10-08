import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';

const fixture = JSON.parse(readFileSync(process.argv[2], 'utf8'));
if (process.argv[3] === 'refunded') {
  const response = await fetch(fixture.original_url, { redirect: 'manual' });
  assert.equal(response.status, 404, 'Woo retained a fully refunded grant');
  const body = new Uint8Array(await response.arrayBuffer());
  assert.notEqual(createHash('sha256').update(body).digest('hex'), fixture.original_sha256);
  console.log('PASS: native HTTP download denies the fully refunded order');
  process.exit(0);
}

for (const [name, expectedHash] of [
  ['original_url', fixture.original_sha256],
  ['replacement_url', fixture.replacement_sha256],
]) {
  const response = await fetch(fixture[name], { redirect: 'manual' });
  assert.equal(response.status, 200, `${name} did not deliver through Woo`);
  const body = new Uint8Array(await response.arrayBuffer());
  assert.equal(createHash('sha256').update(body).digest('hex'), expectedHash);
  assert.match(response.headers.get('cache-control') ?? '', /no-store/);
  assert.match(response.headers.get('content-disposition') ?? '', /attachment/);
}
const denied = new URL(fixture.original_url);
denied.searchParams.set('order', 'invalid-order-key');
const rejection = await fetch(denied, { redirect: 'manual' });
assert.ok(rejection.status >= 400, 'An invalid Woo order key delivered a file');
assert.notEqual(createHash('sha256').update(new Uint8Array(await rejection.arrayBuffer())).digest('hex'), fixture.original_sha256);
const direct = new URL(`/commerce-private/${fixture.original_sha256}/purchased.pdf`, fixture.original_url);
const directResponse = await fetch(direct);
assert.equal(directResponse.status, 404, 'Private bytes have an anonymous web URL');

const mailpit = process.env.COMMERCE_MAILPIT_URL ?? 'http://127.0.0.1:28982';
const query = new URL('/api/v1/search', mailpit);
query.searchParams.set('query', `to:${fixture.original_email}`);
const messages = await (await fetch(query)).json();
assert.ok(messages.messages.length > 0, 'Native Woo email was not captured');
let deliveredLink = false;
for (const message of messages.messages) {
  const detail = await (await fetch(new URL(`/api/v1/message/${message.ID}`, mailpit))).json();
  const body = `${detail.Text}\n${detail.HTML}`.replaceAll('&amp;', '&');
  if (body.includes(fixture.original_url)) deliveredLink = true;
}
assert.equal(deliveredLink, true, 'Woo email omitted the native purchased-file link');
console.log('PASS: native Woo protected HTTP delivery, purchased-version retention, new-version delivery, cache denial, invalid-grant denial, public-file denial, native delivery email');
