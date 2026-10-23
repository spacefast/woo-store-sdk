/**
 * The browser adapter against a real WooCommerce + woo-storefront: key
 * resolution, the sessionStorage Cart-Token, the signed /checkout/c/{token}
 * handoff carrying the same lines into Woo's checkout session, payment inside
 * that session, the signed return to the storefront's confirmation page, and
 * the guest order read through the Store API.
 *
 * The storefront and Woo share one origin here, as on Spacefast; Woo answers
 * /__spacefast/commerce.json with a 404, which must mean "store".
 *
 * Prerequisites: a deploy/woo container seeded with e2e/seed-proof-store.sh,
 * and `pnpm build`.
 *
 * Usage: node e2e/browser-adapter-e2e.mjs [woo-origin]   (default http://localhost:8890)
 */
import { BrowserTransport } from '../packages/browser/dist/index.js'

const BASE = process.argv[2] ?? 'http://localhost:8890'
const EMAIL = `shopper-${Date.now()}@example.com`
let passed = 0

// One origin: the page is served from the store's host.
globalThis.location = { origin: new URL(BASE).origin }

class TabStorage {
  #entries = new Map()
  getItem(key) { return this.#entries.get(key) ?? null }
  setItem(key, value) { this.#entries.set(key, String(value)) }
  removeItem(key) { this.#entries.delete(key) }
}

const tab = new TabStorage()
globalThis.sessionStorage = tab
/** Every page load builds a fresh adapter with defaults; the tab's sessionStorage carries the cart. */
const pageLoad = () => new BrowserTransport()

function assert(condition, message) {
  if (!condition) throw new Error(message)
}

async function check(name, run) {
  await run()
  passed += 1
  console.log(`  ok  ${name}`)
}

/** A cookie jar for the shopper's browser once it leaves the storefront for Woo's checkout. */
const jar = new Map()
async function browse(url, init = {}) {
  const headers = new Headers(init.headers)
  if (jar.size) headers.set('Cookie', [...jar].map(([name, value]) => `${name}=${value}`).join('; '))
  const response = await fetch(url, { ...init, headers, redirect: 'manual' })
  for (const cookie of response.headers.getSetCookie()) {
    const [pair] = cookie.split(';')
    const index = pair.indexOf('=')
    jar.set(pair.slice(0, index), pair.slice(index + 1))
  }
  return response
}

const lines = (cart) => cart.items.map((item) => `${item.name} x${item.quantity}`).sort()

console.log(`\n== Browser adapter against ${BASE} ==`)
let ebook
let print
let handoffUrl
let confirmation

await check('no commerce.json on a plain Woo host means the store source', async () => {
  assert(await pageLoad().source() === 'store', 'expected store')
})

await check('products resolve by declared key through the Store API sku filter', async () => {
  const page = pageLoad()
  ebook = await page.productByKey('field-guide-ebook')
  print = await page.productByKey('field-guide-print')
  assert(ebook.sku === 'field-guide-ebook' && print.sku === 'field-guide-print', 'wrong products')
  assert(print.prices.currencyCode === 'EUR' && print.prices.price === '2900', `price ${JSON.stringify(print.prices)}`)
  const missing = await page.productByKey('field-guide').catch((error) => error)
  assert(missing?.name === 'NotFoundError', `prefix must not match: ${missing?.name}`)
})

await check('the cart survives page loads through the sessionStorage Cart-Token', async () => {
  await pageLoad().mutate('cart.addItem', { id: print.id, quantity: 2 })
  await pageLoad().mutate('cart.addItem', { id: ebook.id, quantity: 1 })
  const cart = await pageLoad().query('cart.get')
  assert(
    JSON.stringify(lines(cart)) === JSON.stringify(['The Field Guide (ebook) x1', 'The Field Guide (paperback) x2']),
    `cart ${lines(cart)}`,
  )
  assert(cart.needsShipping, 'paperback needs shipping')
  assert(tab.getItem(`woo-storefront:session:${BASE}`)?.includes('cartToken'), 'Cart-Token not in sessionStorage')
})

await check('the browser gets a signed handoff URL without any secret', async () => {
  handoffUrl = new URL(await pageLoad().checkoutUrl())
  assert(handoffUrl.pathname.startsWith('/checkout/c/'), `handoff ${handoffUrl.pathname}`)
  assert(handoffUrl.searchParams.get('return_url') === `${BASE}/order-confirmation/`, 'return_url not signed in')
})

await check('the handoff adopts the same lines into Woo checkout', async () => {
  const entry = await browse(handoffUrl)
  assert(entry.status === 302, `entry ${entry.status}`)
  const checkout = new URL(entry.headers.get('location'), BASE)
  assert(checkout.pathname === '/checkout/', `entry redirected to ${checkout.pathname}`)
  const shell = await browse(checkout)
  // An empty or unadopted session redirects /checkout/ to the cart.
  assert(shell.status === 200, `checkout shell ${shell.status} → ${shell.headers.get('location')}`)
  const cart = await (await browse(new URL('/wp-json/wc/store/v1/cart', BASE))).json()
  assert(
    JSON.stringify(lines(cart)) === JSON.stringify(['The Field Guide (ebook) x1', 'The Field Guide (paperback) x2']),
    `adopted cart ${lines(cart)}`,
  )
})

await check('paying in the checkout session returns to the storefront confirmation page', async () => {
  // What the Checkout block does: cookie session + Store API nonce.
  const nonce = (await browse(new URL('/wp-json/wc/store/v1/cart', BASE))).headers.get('Nonce')
  assert(nonce, 'no Store API nonce for the checkout session')
  const address = {
    first_name: 'Ada', last_name: 'Reader', address_1: 'Ul. Prosta 1', city: 'Warszawa',
    postcode: '00-001', country: 'PL',
  }
  const placed = await browse(new URL('/wp-json/wc/store/v1/checkout', BASE), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Nonce: nonce },
    body: JSON.stringify({
      billing_address: { ...address, email: EMAIL },
      shipping_address: address,
      payment_method: 'cod',
    }),
  })
  const result = await placed.json()
  assert(placed.ok, `checkout ${placed.status}: ${JSON.stringify(result).slice(0, 200)}`)
  const received = await browse(result.payment_result.redirect_url)
  assert(received.status === 302, `order received ${received.status}`)
  confirmation = new URL(received.headers.get('location'))
  assert(`${confirmation.origin}${confirmation.pathname}` === `${BASE}/order-confirmation/`, `returned to ${confirmation}`)
  assert(confirmation.searchParams.get('order_id') === String(result.order_id), 'order_id missing')
  assert(confirmation.searchParams.get('order_key'), 'order_key missing')
})

await check('the confirmation page reads the order through the Store API', async () => {
  const lookup = {
    id: Number(confirmation.searchParams.get('order_id')),
    key: confirmation.searchParams.get('order_key'),
  }
  const withoutEmail = await pageLoad().order(lookup).catch((error) => error)
  assert(withoutEmail?.code === 'woocommerce_rest_invalid_billing_email', `guest read without email: ${withoutEmail?.code}`)
  const order = await pageLoad().order({ ...lookup, billingEmail: EMAIL })
  assert(order.id === lookup.id && order.status === 'processing', `order ${order.id} ${order.status}`)
  assert(
    JSON.stringify(lines(order)) === JSON.stringify(['The Field Guide (ebook) x1', 'The Field Guide (paperback) x2']),
    `order lines ${lines(order)}`,
  )
  assert(order.totals.totalShipping === '1500' && order.totals.totalPrice === '8500', JSON.stringify(order.totals))
})

await check('the storefront cart is empty after the order', async () => {
  const cart = await pageLoad().query('cart.get')
  assert(cart.itemsCount === 0, `cart still has ${cart.itemsCount}`)
})

console.log(`\n${passed} passed`)
