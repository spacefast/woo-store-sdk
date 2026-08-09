/**
 * End-to-end verification of @woo/storefront-core + the woo-storefront
 * feature plugin against a real WordPress/WooCommerce (wp-env) instance.
 *
 * Usage: node e2e/store-e2e.mjs [base-url]   (default http://localhost:8888)
 */
import {
  createStorefrontClient,
  InMemorySessionStore,
  StoreApiError,
  NotFoundError,
} from '../packages/core/dist/index.js'

const BASE = process.argv[2] ?? 'http://localhost:8888'
let pass = 0
let fail = 0
const failures = []

async function check(name, fn) {
  try {
    await fn()
    pass++
    console.log(`  ok  ${name}`)
  } catch (err) {
    fail++
    failures.push({ name, err })
    console.log(`FAIL  ${name}: ${err?.message ?? err}`)
  }
}

function assert(cond, msg) {
  if (!cond) throw new Error(msg)
}

const session = new InMemorySessionStore({ cacheId: 'e2e-visitor' })
const woo = createStorefrontClient({ url: BASE }, session)

console.log(`\n== Catalog (Store API) against ${BASE} ==`)
let firstProduct
await check('products.list returns seeded products', async () => {
  const { items, total } = await woo.products.list({ perPage: 10 }).queryFn()
  assert(items.length > 0, `no products returned (total=${total})`)
  assert(typeof items[0].prices.price === 'string', 'price missing/untyped')
  assert(items[0].slug, 'slug missing')
  firstProduct = items[0]
})
await check('products.bySlug round-trips', async () => {
  const p = await woo.products.bySlug(firstProduct.slug).queryFn()
  assert(p.id === firstProduct.id, `id mismatch ${p.id} != ${firstProduct.id}`)
})
await check('products.list search filters', async () => {
  const { items } = await woo.products.list({ search: firstProduct.name.split(' ')[0] }).queryFn()
  assert(items.some((p) => p.id === firstProduct.id), 'search did not find seeded product')
})
await check('categories.list works', async () => {
  const cats = await woo.categories.list().queryFn()
  assert(Array.isArray(cats), 'categories not an array')
})
await check('unknown product maps to NotFoundError', async () => {
  try {
    await woo.products.byId(999999).queryFn()
    throw new Error('expected NotFoundError')
  } catch (err) {
    assert(err instanceof NotFoundError, `got ${err?.constructor?.name}: ${err?.message}`)
  }
})

console.log('\n== Cart + Cart-Token session ==')
await check('cart.addItem creates cart and rotates Cart-Token into session', async () => {
  const cart = await woo.cart.addItem.mutationFn({ id: firstProduct.id, quantity: 2 })
  assert(cart.itemsCount === 2, `itemsCount ${cart.itemsCount} != 2`)
  const s = await session.read()
  assert(s.cartToken, 'Cart-Token was not captured into the session')
})
await check('cart.get sees the same cart via Cart-Token', async () => {
  const cart = await woo.cart.get().queryFn()
  assert(cart.itemsCount === 2, `itemsCount ${cart.itemsCount} != 2 on re-read`)
  assert(cart.items[0].id === firstProduct.id, 'item id mismatch')
})
await check('cart.updateItem changes quantity', async () => {
  const current = await woo.cart.get().queryFn()
  const cart = await woo.cart.updateItem.mutationFn({ key: current.items[0].key, quantity: 3 })
  assert(cart.itemsCount === 3, `itemsCount ${cart.itemsCount} != 3`)
})
await check('cart.removeItem empties the cart', async () => {
  const current = await woo.cart.get().queryFn()
  const cart = await woo.cart.removeItem.mutationFn({ key: current.items[0].key })
  assert(cart.itemsCount === 0, `itemsCount ${cart.itemsCount} != 0`)
})

console.log('\n== Feature plugin: checkout_url ==')
await check('cart response carries plugin checkout_url', async () => {
  await woo.cart.addItem.mutationFn({ id: firstProduct.id, quantity: 1 })
  const url = await woo.cart.checkoutUrl()
  assert(url.includes('/checkout/c/'), `unexpected checkout_url: ${url}`)
  globalThis.__checkoutUrl = url
})
await check('checkout entry adopts session (redirect into checkout, not an error page)', async () => {
  const res = await fetch(globalThis.__checkoutUrl, { redirect: 'manual' })
  assert(
    res.status === 302 || res.status === 301 || res.status === 200,
    `checkout entry returned ${res.status}`,
  )
  if (res.status >= 300 && res.status < 400) {
    const loc = res.headers.get('location') ?? ''
    assert(!loc.includes('/cart'), `bounced to cart (empty session?): ${loc}`)
  }
})

console.log('\n== Feature plugin: auth + accounts ==')
const email = `e2e-${process.pid}@example.test`
const password = 'e2e-Password!42'
await check('auth.register creates a customer', async () => {
  const customer = await woo.auth.register.mutationFn({
    email,
    password,
    firstName: 'E2E',
    lastName: 'Shopper',
  })
  assert(customer.email === email, `email mismatch: ${customer.email}`)
})
await check('register/login stored customer JWT in session', async () => {
  const s = await session.read()
  if (!s.customerToken) {
    const customer = await woo.auth.login.mutationFn({ email, password })
    assert(customer.email === email, 'login returned wrong customer')
  }
  const after = await session.read()
  assert(after.customerToken, 'no customerToken in session after login')
})
await check('guest cart merged into customer cart on login', async () => {
  const cart = await woo.cart.get().queryFn()
  assert(cart.itemsCount >= 1, `cart lost on login (itemsCount=${cart.itemsCount})`)
})
await check('customer.get returns the JWT-scoped customer', async () => {
  const me = await woo.customer.get().queryFn()
  assert(me.email === email, `customer.get returned ${me.email}`)
})
await check('customer.orders returns a paginated list', async () => {
  const { items } = await woo.customer.orders({ perPage: 5 }).queryFn()
  assert(Array.isArray(items), 'orders.items not an array')
})
await check('customer.updateAddress persists billing address', async () => {
  const me = await woo.customer.updateAddress.mutationFn({
    type: 'billing',
    address: {
      firstName: 'E2E',
      lastName: 'Shopper',
      address1: '1 Test Way',
      city: 'Testville',
      state: '',
      postcode: '12345',
      country: 'US',
      email,
    },
  })
  assert(me.billingAddress?.city === 'Testville', 'billing address not persisted')
})
await check('auth.logout drops customerToken but keeps guest session', async () => {
  await woo.auth.logout.mutationFn()
  const s = await session.read()
  assert(!s.customerToken, 'customerToken survived logout')
})
await check('customer.get without JWT is an auth error', async () => {
  try {
    await woo.customer.get().queryFn()
    throw new Error('expected auth error')
  } catch (err) {
    assert(err instanceof StoreApiError && (err.status === 401 || err.status === 403),
      `got ${err?.constructor?.name} status=${err?.status}`)
  }
})

console.log('\n== Order placement (headless POST /checkout, COD) ==')
let placedOrderId
await check('checkout.submit places a COD order for a virtual product', async () => {
  await woo.auth.login.mutationFn({ email, password })
  await woo.cart.addItem.mutationFn({ id: 14, quantity: 1 })
  const result = await woo.checkout.submit.mutationFn({
    billingAddress: {
      firstName: 'E2E',
      lastName: 'Shopper',
      address1: '1 Test Way',
      city: 'Testville',
      state: 'CA',
      postcode: '12345',
      country: 'US',
      email,
    },
    paymentMethod: 'cod',
  })
  const raw = result?.raw ?? result
  const orderId = raw?.order_id ?? raw?.orderId
  const status = raw?.payment_result?.payment_status ?? raw?.paymentResult?.paymentStatus
  assert(orderId, `no order id in checkout response: ${JSON.stringify(raw).slice(0, 200)}`)
  assert(status === 'success', `payment_status=${status}`)
  placedOrderId = orderId
})
await check('customer.orders lists the placed order', async () => {
  const { items } = await woo.customer.orders({ perPage: 10 }).queryFn()
  assert(items.some((o) => o.id === placedOrderId), `order ${placedOrderId} not in ${JSON.stringify(items.map((o) => o.id))}`)
})
await check('customer.order returns full order detail', async () => {
  const order = await woo.customer.order(placedOrderId).queryFn()
  assert(order.items.length === 1, `order items ${order.items.length} != 1`)
})

console.log(`\n${pass} passed, ${fail} failed`)
if (failures.length) {
  console.log('\nFailures:')
  for (const f of failures) console.log(`- ${f.name}\n  ${f.err?.stack?.split('\n').slice(0, 3).join('\n  ')}`)
  process.exit(1)
}
