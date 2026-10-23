import assert from 'node:assert/strict';
import { execFileSync, spawn, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { once } from 'node:events';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const container = process.env.COMMERCE_WP_CONTAINER ?? 'woo-m1-companion-contract-wordpress-1';
const wpArgs = ['exec', '--user', 'www-data', container, 'php', '/usr/local/bin/wp-cli.phar'];
const docker = (...args) => execFileSync('docker', args, { cwd: root, stdio: 'inherit', timeout: 120_000 });
const wp = (...args) => docker(...wpArgs, ...args);
const read = (file) => execFileSync('docker', ['exec', container, 'cat', file], { encoding: 'utf8' });
const receipt = (args, expectedCode) => {
  const result = spawnSync('docker', [...wpArgs, 'spacefast-commerce', ...args], { encoding: 'utf8', timeout: 120_000 });
  assert.equal(result.error, undefined);
  assert.equal(result.status, expectedCode ? 1 : 0, result.stderr || result.stdout);
  const value = JSON.parse(result.stdout.trim());
  assert.equal(value.ok, !expectedCode, result.stdout);
  if (expectedCode) assert.equal(value.error.code, expectedCode);
  return value;
};
const request = (url, options) => fetch(url, {
  ...options, headers: { connection: 'close', ...options?.headers },
  redirect: 'manual', signal: AbortSignal.timeout(30_000),
});
const localUrl = (canonical, origin) => {
  const target = new URL(canonical);
  return new URL(target.pathname + target.search, origin);
};

for (const name of ['commerce-contract.php', 'commerce-planner-contract.php', 'commerce-lock-contract.php']) {
  docker('cp', `e2e/${name}`, `${container}:/tmp/${name}`);
}
docker('cp', 'e2e/fixtures/store-json', `${container}:/tmp/commerce-store-json`);
wp('eval-file', '/tmp/commerce-planner-contract.php');
receipt(['setup']);
const initialStatus = receipt(['status']);
assert.equal(initialStatus.versions.woocommerce, '11.2.0');
assert.equal(initialStatus.versions['woocommerce-gateway-dummy'], '2.0.0');
assert.equal(initialStatus.versions['woocommerce-gateway-stripe'], '11.1.1');
receipt(['apply', '/tmp/does-not-matter', '--baseline='], 'baseline-empty');
receipt(['apply', '/tmp/does-not-matter', '--baseline=book', '--no-live-catalog'], 'baseline-conflict');

// Warm a waiting connection's cache before the lock holder advances the counter.
const startLockProcess = (role) => {
  const child = spawn('docker', ['exec', '-i', '--user', 'www-data', container, 'php', '/usr/local/bin/wp-cli.phar', 'eval-file', '/tmp/commerce-lock-contract.php', role], { stdio: ['pipe', 'pipe', 'pipe'] });
  let output = '';
  let errors = '';
  const waits = [];
  const timer = setTimeout(() => child.kill('SIGTERM'), 35_000);
  child.stdout.setEncoding('utf8').on('data', (chunk) => {
    output += chunk;
    for (const wait of waits) if (output.includes(wait.text)) wait.resolve();
  });
  child.stderr.setEncoding('utf8').on('data', (chunk) => { errors += chunk; });
  const done = once(child, 'exit').then(([code]) => {
    clearTimeout(timer);
    assert.equal(code, 0, errors || output);
    return output;
  });
  return { child, done, wait: (text) => output.includes(text) ? Promise.resolve() : Promise.race([
    new Promise((resolve) => waits.push({ text, resolve })),
    done.then(() => { throw new Error(`Process exited before ${text}: ${output}`); }),
  ]) };
};
const holder = startLockProcess('holder');
await holder.wait('LOCK_HELD');
const waiter = startLockProcess('waiter');
await waiter.wait('COUNTER_READ:');
holder.child.stdin.end('release\n');
const [holderOutput, waiterOutput] = await Promise.all([holder.done, waiter.done]);
const counter = (output, label) => Number(output.match(new RegExp(`${label}:(\\d+)`))[1]);
assert.equal(counter(holderOutput, 'COUNTER_WRITTEN'), counter(waiterOutput, 'COUNTER_READ') + 1);
assert.equal(counter(waiterOutput, 'COUNTER_WRITTEN'), counter(holderOutput, 'COUNTER_WRITTEN') + 1);
assert.equal(receipt(['status']).counter, counter(waiterOutput, 'COUNTER_WRITTEN'));
console.log('PASS: real MySQL lock serializes processes and refreshes primed option caches');

for (const storage of ['no', 'yes']) {
  wp('wc', 'hpos', 'sync');
  wp('option', 'update', 'woocommerce_custom_orders_table_enabled', storage);
  wp('option', 'update', 'woocommerce_stripe_settings', JSON.stringify({ enabled: 'no' }), '--format=json');
  receipt(['mode', 'test']);
  wp('eval-file', '/tmp/commerce-contract.php');
  const context = JSON.parse(read('/tmp/commerce-m1-context.json'));
  const status = receipt(['status']);
  assert.equal(status.orderCount, context.orderCount);
  assert.equal(status.mode, 'test');
  const preview = receipt(['email', 'render', String(context.testOrders[0]), 'customer_completed_order']);
  assert.match(preview.html, /Test order/);
  assert.match(preview.html, /https:\/\/store\.example\.test/);
  receipt(['mode', 'live', '--canonical-origin=https://must-not-be-written.example'], 'stripe-not-enabled');
  const refusedStatus = receipt(['status']);
  assert.equal(refusedStatus.mode, 'test');
  assert.equal(refusedStatus.canonicalOrigin, status.canonicalOrigin);

  const products = await request(new URL(`/wp-json/wc/store/v1/products?sku=${context.bookKey}`, context.origin));
  assert.equal(products.status, 200);
  const productList = await products.json();
  assert.deepEqual(productList.map((product) => product.id), [context.bookId]);
  const shippingEdit = new URLSearchParams({ action: 'woocommerce_shipping_zones_save_changes', wc_shipping_zones_nonce: context.shippingNonce });
  shippingEdit.set(`changes[${context.shippingZoneId}][deleted]`, 'true');
  const refusedEdit = await request(new URL('/wp-admin/admin-ajax.php', context.origin), {
    method: 'POST', headers: { cookie: context.adminCookie }, body: shippingEdit,
  });
  assert.equal(refusedEdit.status, 200);
  const refusedBody = await refusedEdit.json();
  assert.equal(refusedBody.success, false);
  assert.match(refusedBody.data, /managed by your store source/);
  const downloadUrl = localUrl(context.downloadUrl, context.origin);
  const download = await request(downloadUrl);
  assert.equal(download.status, 200, await download.clone().text());
  const body = new Uint8Array(await download.arrayBuffer());
  assert.equal(createHash('sha256').update(body).digest('hex'), context.downloadSha256);
  assert.match(download.headers.get('content-disposition') ?? '', /attachment/);
  assert.match(download.headers.get('cache-control') ?? '', /no-cache|no-store/);
  const invalidUrl = new URL(downloadUrl);
  invalidUrl.searchParams.set('order', 'invalid-order-key');
  assert.equal((await request(invalidUrl)).status, 404);
  const publicAttempt = await request(new URL(`/commerce-private/${context.downloadSha256}/replacement.pdf`, context.origin));
  assert.ok(publicAttempt.status >= 300, 'Private storage was served as an anonymous web path');
  assert.notEqual(createHash('sha256').update(new Uint8Array(await publicAttempt.arrayBuffer())).digest('hex'), context.downloadSha256);

  const mailpit = process.env.COMMERCE_MAILPIT_URL;
  assert.ok(mailpit, 'Set COMMERCE_MAILPIT_URL to the fixture Mailpit origin');
  const mailbox = async () => {
    const url = new URL('/api/v1/search', mailpit);
    url.searchParams.set('query', `to:${context.buyerEmail}`);
    const response = await request(url);
    assert.equal(response.status, 200);
    return (await response.json()).messages;
  };
  const beforeMail = await mailbox();
  assert.ok(beforeMail.length > 0, 'Native order trigger did not deliver email to SMTP');
  const mail = await (await request(new URL(`/api/v1/message/${beforeMail[0].ID}`, mailpit))).json();
  assert.match(mail.HTML, /Test order/);
  assert.match(mail.HTML, /https:\/\/store\.example\.test/);
  wp('option', 'update', 'woocommerce_stripe_settings', JSON.stringify({ enabled: 'yes', testmode: 'yes' }), '--format=json');
  const live = receipt(['mode', 'live']);
  assert.ok(live.cancelledTestOrders >= 1);
  wp('eval-file', '/tmp/commerce-contract.php', 'after-live');
  assert.equal(receipt(['mode', 'live']).cancelledTestOrders, 0);
  wp('eval-file', '/tmp/commerce-contract.php', 'after-live');
  assert.deepEqual((await mailbox()).map((message) => message.ID), beforeMail.map((message) => message.ID), 'Go-live cleanup sent customer mail');
  assert.equal((await request(downloadUrl)).status, 404, 'Cancelled dummy order still downloads through HTTP');
  const disabled = receipt(['disable']);
  assert.equal(disabled.storeHash, null);
  assert.equal(receipt(['status']).storeHash, null);
  assert.equal(receipt(['status']).mode, 'live');
  console.log(`PASS: native ${storage === 'yes' ? 'HPOS' : 'CPT'} HTTP Store API, authorized delivery, SMTP, live cleanup and retry`);
}
if (process.env.COMMERCE_ENGINE_ROOT) {
  docker('cp', `${path.resolve(process.env.COMMERCE_ENGINE_ROOT)}/.`, `${container}:/tmp/commerce-engine`);
  docker('cp', 'e2e/commerce-engine-contract.php', `${container}:/tmp/commerce-engine-contract.php`);
  wp('eval-file', '/tmp/commerce-engine-contract.php');
}
console.log('PASS: M1 companion native contract');
