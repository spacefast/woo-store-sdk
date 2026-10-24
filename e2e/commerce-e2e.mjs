import assert from 'node:assert/strict';
import { execFileSync, spawn, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { once } from 'node:events';
import { request as httpRequest } from 'node:http';
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
  if (storage === 'no') {
    const cartEndpoint = new URL('/wp-json/wc/store/v1/cart', context.origin);
    const fresh = await request(cartEndpoint);
    assert.equal(fresh.status, 200);
    const token = fresh.headers.get('cart-token');
    assert.ok(token, 'Native Store API did not issue a guest cart token');
    const cartRequest = async (suffix = '', options = {}) => {
      const response = await request(new URL(cartEndpoint.pathname + suffix, context.origin), {
        ...options, headers: { 'Cart-Token': token, 'Content-Type': 'application/json', ...options.headers },
      });
      assert.equal(response.status, suffix === '/add-item' ? 201 : 200, await response.clone().text());
      return response;
    };
    const cart = await (await cartRequest('/add-item', { method: 'POST', body: JSON.stringify({ id: context.bookId, quantity: 1 }) })).json();
    const returnPath = '/order-confirmation/';
    const canonicalReturn = new URL(returnPath, status.canonicalOrigin).href;
    // Model the local TLS proxy without depending on public DNS or bypassing WP canonical URLs.
    // Node fetch ignores an explicit Host header. Use HTTP directly to exercise WP's real host checks.
    const browserRequest = (url, options = {}) => new Promise((resolve, reject) => {
      const request = httpRequest(localUrl(url, context.origin), {
        ...options, headers: { Host: new URL(status.canonicalOrigin).host, 'X-Forwarded-Proto': 'https', ...options.headers },
        signal: AbortSignal.timeout(30_000),
      }, (response) => {
        const chunks = [];
        response.on('data', (chunk) => chunks.push(chunk));
        response.on('error', reject);
        response.on('end', () => {
          const headers = new Headers();
          for (let index = 0; index < response.rawHeaders.length; index += 2) {
            headers.append(response.rawHeaders[index], response.rawHeaders[index + 1]);
          }
          resolve(new Response(Buffer.concat(chunks), { status: response.statusCode, headers }));
        });
      });
      request.on('error', reject);
      request.end(options.body);
    });
    const safeCart = await (await cartRequest(`?return_url=${encodeURIComponent(canonicalReturn)}`)).json();
    assert.equal(new URL(safeCart.checkout_url).searchParams.get('return_url'), canonicalReturn);
    for (const unsafe of ['https://evil.example/collect', 'https://store.example.test:444/collect', 'https://user@store.example.test/collect', 'http://store.example.test/collect']) {
      const refused = await (await cartRequest(`?return_url=${encodeURIComponent(unsafe)}`)).json();
      assert.equal(new URL(refused.checkout_url).searchParams.has('return_url'), false, `Signed unsafe return: ${unsafe}`);
    }
    const entry = await browserRequest(safeCart.checkout_url);
    assert.equal(entry.status, 302);
    const checkout = new URL(entry.headers.get('location'));
    assert.equal(checkout.searchParams.get('session'), token);
    const cloned = await browserRequest(checkout);
    assert.equal(cloned.status, 200, await cloned.clone().text());
    let cookies = cloned.headers.getSetCookie().map((cookie) => cookie.split(';')[0]).join('; ');
    assert.match(cookies, /wp_woocommerce_session_/);
    const browserCart = await browserRequest(cartEndpoint, { headers: { cookie: cookies } });
    assert.equal(browserCart.status, 200);
    assert.deepEqual((await browserCart.json()).items.map((item) => item.id), cart.items.map((item) => item.id));
    const browserToken = browserCart.headers.get('cart-token');
    assert.ok(browserToken);
    assert.notEqual(browserToken.split('.')[1], token.split('.')[1], 'Checkout reused the source cart session');
    const address = await request(new URL(cartEndpoint.pathname + '/update-customer', context.origin), {
      method: 'POST', headers: { 'Cart-Token': browserToken, 'Content-Type': 'application/json' },
      body: JSON.stringify({ billing_address: { email: 'private-checkout@example.test', first_name: 'Private buyer' } }),
    });
    assert.equal(address.status, 200, await address.clone().text());
    assert.equal((await address.json()).billing_address.email, 'private-checkout@example.test');
    const original = await (await cartRequest()).json();
    assert.notEqual(original.billing_address.email, 'private-checkout@example.test', 'Source Cart-Token exposed checkout PII');
    const wrongOrderKey = localUrl(context.orderReceivedUrl, context.origin);
    wrongOrderKey.searchParams.set('key', 'not-this-buyers-order-key');
    const unauthorizedOrder = await browserRequest(wrongOrderKey, { headers: { cookie: cookies } });
    assert.equal(unauthorizedOrder.headers.get('location'), null, 'Order key escaped before Woo validated the order-received request');
    // Restore the signed return after the invalid order request consumed it.
    assert.equal((await browserRequest(checkout, { headers: { cookie: cookies } })).status, 200);
    const received = await browserRequest(context.orderReceivedUrl, { headers: { cookie: cookies } });
    assert.equal(received.status, 302);
    const returned = new URL(received.headers.get('location'));
    assert.equal(returned.origin + returned.pathname, canonicalReturn);
    assert.equal(returned.searchParams.get('order_key'), new URL(context.orderReceivedUrl).searchParams.get('key'));
    // Reusing the link in another browser clones the source, never the first buyer's session.
    const replay = await browserRequest(checkout);
    const replayCookies = replay.headers.getSetCookie().map((cookie) => cookie.split(';')[0]).join('; ');
    const replayCart = await browserRequest(cartEndpoint, { headers: { cookie: replayCookies } });
    assert.equal(replayCart.status, 200);
    assert.notEqual((await replayCart.json()).billing_address.email, 'private-checkout@example.test');
    console.log('PASS: same-origin signed checkout return and native private guest-session clone');

    const currentBrowserCart = () => browserRequest(cartEndpoint, { headers: { cookie: cookies } });
    const receiveCookies = (current, response) => {
      const jar = new Map(current.split('; ').filter(Boolean).map((cookie) => {
        const index = cookie.indexOf('=');
        return [cookie.slice(0, index), cookie.slice(index + 1)];
      }));
      for (const header of response.headers.getSetCookie()) {
        const cookie = header.split(';')[0];
        const index = cookie.indexOf('=');
        const name = cookie.slice(0, index);
        const value = cookie.slice(index + 1);
        if (value) jar.set(name, value); else jar.delete(name);
      }
      return [...jar].map(([name, value]) => `${name}=${value}`).join('; ');
    };
    const handoffAgain = async () => {
      const current = await (await cartRequest()).json();
      const entry = await browserRequest(current.checkout_url, { headers: { cookie: cookies } });
      assert.equal(entry.status, 302);
      const reset = receiveCookies(cookies, entry);
      const checkout = await browserRequest(entry.headers.get('location'), { headers: { cookie: reset } });
      assert.equal(checkout.status, 200, await checkout.clone().text());
      cookies = receiveCookies(reset, checkout);
      const cart = await (await currentBrowserCart()).json();
      assert.deepEqual(cart.items.map(({ id, quantity }) => ({ id, quantity })), current.items.map(({ id, quantity }) => ({ id, quantity })), 'Repeated handoff reused a stale checkout cart');
    };
    const pay = async () => {
      const cart = await currentBrowserCart();
      const nonce = cart.headers.get('Nonce');
      assert.ok(nonce, 'Checkout session did not issue a Store API nonce');
      const address = { first_name: 'Private', last_name: 'Buyer', address_1: '1 Main Street', city: 'San Francisco', postcode: '94016', country: 'US', state: 'CA' };
      const payment = await browserRequest(new URL('/wp-json/wc/store/v1/checkout', context.origin), {
        method: 'POST', headers: { cookie: cookies, Nonce: nonce, 'Content-Type': 'application/json' },
        body: JSON.stringify({ billing_address: { ...address, email: 'checkout-settlement@example.test' }, shipping_address: address, payment_method: 'dummy' }),
      });
      assert.equal(payment.status, 200, await payment.clone().text());
      const order = await payment.json();
      assert.equal(order.status, 'processing');
      context.testOrders.push(order.order_id);
      context.orderCount += 1;
      return order.order_id;
    };

    const changed = await (await cartRequest('/add-item', { method: 'POST', body: JSON.stringify({ id: context.physicalId, quantity: 2 }) })).json();
    await handoffAgain();
    const paper = changed.items.find((item) => item.id === context.physicalId);
    const newer = await (await cartRequest('/update-item', { method: 'POST', body: JSON.stringify({ key: paper.key, quantity: 1 }) })).json();
    const paid = await pay();
    assert.deepEqual((await (await cartRequest()).json()).items.map(({ id, quantity }) => ({ id, quantity })), newer.items.map(({ id, quantity }) => ({ id, quantity })), 'Payment erased a newer source cart');
    // An old payment notification cannot erase that later cart if it returns to the original contents.
    await cartRequest('/update-item', { method: 'POST', body: JSON.stringify({ key: paper.key, quantity: 2 }) });
    wp('eval', `do_action('woocommerce_payment_complete', ${paid});`);
    assert.equal((await (await cartRequest()).json()).items.find((item) => item.id === context.physicalId).quantity, 2);
    await handoffAgain();
    await pay();
    assert.equal((await (await cartRequest()).json()).items_count, 0, 'Paid clone left the consumed source cart populated');
    await cartRequest('/add-item', { method: 'POST', body: JSON.stringify({ id: context.bookId, quantity: 1 }) });
    await handoffAgain();
    // A merchant testing the shop while signed into wp-admin uses the same handoff contract.
    cookies = context.adminCookie;
    await handoffAgain();
    await cartRequest('/add-item', { method: 'POST', body: JSON.stringify({ id: context.physicalId, quantity: 1 }) });
    await handoffAgain();
    // Keep the existing cleanup/order-count owner aware of the additional real test purchases.
    execFileSync('docker', ['exec', '-i', container, 'tee', '/tmp/commerce-m1-context.json'], { input: JSON.stringify(context), stdio: ['pipe', 'ignore', 'inherit'] });
    console.log('PASS: repeated checkout uses current items; payment clears only its unchanged source cart');
  }
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
// Real, fresh-process native fencing: delayed commands cannot undo test mode
// or a newer canonical origin. Restore only the dedicated fixture's own fence.
const beforeFence = receipt(['status']);
const fenceOptions = ['spacefast_commerce_mode_generation', 'spacefast_commerce_command_generation'];
for (const option of fenceOptions) {
  const missing = spawnSync('docker', [...wpArgs, 'eval', `echo json_encode(get_option('${option}', null));`], { encoding: 'utf8', timeout: 30_000 });
  assert.equal(missing.status, 0, missing.stderr);
  assert.equal(JSON.parse(missing.stdout), null, 'Use a disposable fixture without a control-plane mode fence.');
}
try {
  receipt(['mode', 'test', '--generation=2', '--command-generation=2', '--canonical-origin=https://current.example.test']);
  const originOnly = receipt(['origin', 'https://renamed.example.test', '--generation=2', '--command-generation=3']);
  assert.equal(originOnly.canonicalOrigin, 'https://renamed.example.test');
  const afterOrigin = receipt(['status']);
  assert.equal(afterOrigin.mode, 'test');
  assert.equal(afterOrigin.orderCount, beforeFence.orderCount);
  assert.equal(afterOrigin.counter, beforeFence.counter);
  receipt(['origin', 'https://stale.example.test', '--generation=1', '--command-generation=4'], 'mode-superseded');
  receipt(['origin', 'https://stale.example.test', '--generation=2', '--command-generation=2'], 'mode-superseded');
  assert.equal(receipt(['status']).canonicalOrigin, 'https://renamed.example.test');
  receipt(['mode', 'live', '--generation=1', '--command-generation=4', '--canonical-origin=https://stale.example.test'], 'mode-superseded');
  receipt(['mode', 'test', '--generation=2', '--command-generation=1', '--canonical-origin=https://stale.example.test'], 'mode-superseded');
  receipt(['mode', 'live'], 'mode-superseded');
  receipt(['apply', '/tmp/does-not-matter', '--generation=1', '--command-generation=3'], 'mode-superseded');
  receipt(['disable', '--generation=2', '--command-generation=1'], 'mode-superseded');
  assert.equal(receipt(['status']).counter, beforeFence.counter);
  const current = receipt(['status']);
  assert.equal(current.mode, 'test');
  assert.equal(current.canonicalOrigin, 'https://renamed.example.test');
  receipt(['mode', 'test', '--generation=2', '--command-generation=3', '--canonical-origin=https://renamed.example.test']);
  receipt(['mode', beforeFence.mode, '--generation=3', '--command-generation=4', `--canonical-origin=${beforeFence.canonicalOrigin}`]);
} finally {
  for (const option of fenceOptions) wp('option', 'delete', option);
}
console.log('PASS: fresh-process native mode and canonical-origin generations refuse late SSH commands');
if (process.env.COMMERCE_ENGINE_ROOT) {
  docker('cp', `${path.resolve(process.env.COMMERCE_ENGINE_ROOT)}/.`, `${container}:/tmp/commerce-engine`);
  docker('cp', 'e2e/commerce-engine-contract.php', `${container}:/tmp/commerce-engine-contract.php`);
  wp('eval-file', '/tmp/commerce-engine-contract.php');
}
console.log('PASS: M1 companion native contract');
