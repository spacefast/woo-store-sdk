/**
 * Black-box verification of the production Next storefront and its Woo RPC
 * bridge. Start the built app first, then run this script.
 *
 * Usage: node e2e/shop-e2e.mjs [shop-url]
 */
import { createHmac } from 'node:crypto'

const base = process.argv[2] ?? 'http://127.0.0.1:3000'
const revalidateSecret = process.argv[3] ?? 'e2e-webhook-secret'
let cookie = ''
let passed = 0

function assert(condition, message) {
  if (!condition) throw new Error(message)
}

function captureCookie(response) {
  const values = typeof response.headers.getSetCookie === 'function'
    ? response.headers.getSetCookie()
    : [response.headers.get('set-cookie')].filter(Boolean)
  const session = values
    .map((value) => value.split(';', 1)[0])
    .find((value) => value.startsWith('woo_session='))
  if (session) cookie = session
}

async function request(path, init = {}) {
  const headers = new Headers(init.headers)
  if (cookie) headers.set('cookie', cookie)
  const response = await fetch(new URL(path, base), { ...init, headers })
  captureCookie(response)
  return response
}

async function check(name, run) {
  await run()
  passed += 1
  console.log(`  ok  ${name}`)
}

console.log(`\n== Production shop against ${base} ==`)

let product
await check('catalog RPC returns a product', async () => {
  const response = await request('/api/store/query?op=products.list&args=%7B%22perPage%22%3A1%7D')
  const payload = await response.json()
  assert(response.status === 200, `catalog RPC returned ${response.status}`)
  product = payload.data?.items?.[0]
  assert(product?.id && product?.slug && product?.name, 'catalog RPC returned no usable product')
})

await check('partially prerendered homepage streams live Woo products', async () => {
  const response = await request('/')
  const html = await response.text()
  assert(response.status === 200, `homepage returned ${response.status}`)
  assert(html.includes(product.name), `homepage did not contain ${product.name}`)
})

await check('product detail renders the Woo product', async () => {
  const response = await request(`/products/${encodeURIComponent(product.slug)}`)
  const html = await response.text()
  assert(response.status === 200, `product page returned ${response.status}`)
  assert(html.includes(product.name), `product page did not contain ${product.name}`)
})

await check('browser RPC adds an item and persists its signed session', async () => {
  const response = await request('/api/store/rpc', {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ op: 'cart.addItem', args: { id: product.id, quantity: 2 } }),
  })
  const payload = await response.json()
  assert(response.status === 200, `cart.addItem returned ${response.status}`)
  assert(payload.data?.items_count === 2 || payload.data?.itemsCount === 2, 'cart count was not 2')
  assert(cookie.startsWith('woo_session='), 'Woo session cookie was not persisted')

  const reread = await request('/api/store/query?op=cart.get')
  const rereadPayload = await reread.json()
  assert(rereadPayload.data?.itemsCount === 2, 'cart session did not survive a second request')
})

await check('demo checkout is explicit and exposes only Stripe example cards', async () => {
  const response = await request('/checkout')
  const html = await response.text()
  assert(response.status === 200, 'demo checkout returned ' + response.status)
  assert(html.includes('No request is sent to Stripe'), 'missing no-charge checkout disclaimer')
  assert(html.includes('4242 4242 4242 4242'), 'Visa example card missing')
  assert(html.includes('5555 5555 5555 4444'), 'Mastercard example card missing')
  assert(!html.includes('sk_live_'), 'live Stripe key leaked into checkout')
})

await check('hosted checkout URL crosses back into WooCommerce', async () => {
  const response = await request('/api/store/query?op=cart.checkoutUrl')
  const payload = await response.json()
  assert(response.status === 200, `cart.checkoutUrl returned ${response.status}`)
  assert(typeof payload.data === 'string' && payload.data.includes('/checkout/c/'), 'checkout URL missing')

  const checkout = await fetch(payload.data, { redirect: 'manual' })
  assert([200, 301, 302].includes(checkout.status), `checkout entry returned ${checkout.status}`)
})

await check('plugin-signed webhook revalidates the product cache', async () => {
  const body = JSON.stringify({ event: 'stock.updated', productIds: [product.id] })
  const signature = `sha256=${createHmac('sha256', revalidateSecret).update(body).digest('hex')}`
  const response = await request('/api/store/revalidate', {
    method: 'POST',
    headers: {
      'content-type': 'application/json',
      'x-woo-storefront-event': 'stock.updated',
      'x-woo-storefront-signature': signature,
    },
    body,
  })
  const payload = await response.json()
  assert(response.status === 200, `revalidation returned ${response.status}`)
  assert(payload.tags?.includes(`product-${product.id}`), 'product cache tag was not revalidated')
})

console.log(`\n${passed} passed, 0 failed`)
