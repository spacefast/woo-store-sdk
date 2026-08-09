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
let registeredEmail = ''

function assert(condition, message) {
  if (!condition) throw new Error(message)
}

function captureCookie(response) {
  const values = typeof response.headers.getSetCookie === 'function'
    ? response.headers.getSetCookie()
    : [response.headers.get('set-cookie')].filter(Boolean)
  const session = values
    .flatMap((value) => value.match(/woo_session=[^;,\s]+/gu) ?? [])
    .at(-1)
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

async function rpc(op, args, { retryOnRateLimit = false } = {}) {
  for (let attempt = 0; attempt < 2; attempt += 1) {
    const response = await request('/api/store/rpc', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ op, ...(args === undefined ? {} : { args }) }),
    })
    const payload = await response.json()
    if (response.status !== 429 || !retryOnRateLimit || attempt > 0) {
      return { payload, response }
    }

    const retryAfterSeconds = Number(
      response.headers.get('retry-after') ?? payload.error?.retryAfterSeconds,
    )
    assert(Number.isFinite(retryAfterSeconds), `${op} was rate limited without Retry-After`)
    await new Promise((resolve) => setTimeout(resolve, retryAfterSeconds * 1_000))
  }
  throw new Error(`${op} exhausted its bounded rate-limit retry`)
}

async function waitForCartCount(expected, timeoutMs = 10_000) {
  const deadline = Date.now() + timeoutMs
  let actual
  do {
    const response = await request(`/api/store/query?op=cart.get&_=${Date.now()}`)
    const payload = await response.json()
    actual = payload.data?.itemsCount
    if (response.status === 200 && actual === expected) return actual
    await new Promise((resolve) => setTimeout(resolve, 100))
  } while (Date.now() < deadline)
  return actual
}

console.log(`\n== Production shop against ${base} ==`)

let product
let relatedProduct
await check('catalog RPC returns a product', async () => {
  const response = await request('/api/store/query?op=products.list&args=%7B%22perPage%22%3A2%7D')
  const payload = await response.json()
  assert(response.status === 200, `catalog RPC returned ${response.status}`)
  product = payload.data?.items?.[0]
  relatedProduct = payload.data?.items?.[1]
  assert(product?.id && product?.slug && product?.name, 'catalog RPC returned no usable product')
  assert(relatedProduct?.name, 'catalog RPC returned no recommendation candidate')
})

await check('Woo customer registration, logout, and login rotate the authenticated session', async () => {
  registeredEmail = `shop-e2e-${Date.now()}@example.com`
  const register = await rpc('auth.register', {
    email: registeredEmail,
    password: 'correct-horse-demo-42',
    firstName: 'Demo',
    lastName: 'Shopper',
  })
  assert(register.response.status === 200, `auth.register returned ${register.response.status}`)

  const logout = await rpc('auth.logout', undefined, { retryOnRateLimit: true })
  assert(logout.response.status === 200, `auth.logout returned ${logout.response.status}`)

  const signedOut = await request('/api/store/query?op=customer.get')
  const signedOutPayload = await signedOut.json()
  assert(
    signedOut.status === 401,
    `signed-out customer.get returned ${signedOut.status}: ${JSON.stringify(signedOutPayload)}`,
  )

  const login = await rpc(
    'auth.login',
    {
      email: registeredEmail,
      password: 'correct-horse-demo-42',
    },
    { retryOnRateLimit: true },
  )
  assert(login.response.status === 200, `auth.login returned ${login.response.status}`)
  assert(login.payload.data?.email === registeredEmail, 'login returned the wrong customer')
})

await check('partially prerendered homepage streams live Woo products', async () => {
  const response = await request('/')
  const html = await response.text()
  assert(response.status === 200, `homepage returned ${response.status}`)
  assert(html.includes(product.name), `homepage did not contain ${product.name}`)
})

await check('search and collection routes are backed by Woo catalog queries', async () => {
  const searchTerm = product.name.split(' ')[0]
  const search = await request(`/search?q=${encodeURIComponent(searchTerm)}`)
  const searchHtml = await search.text()
  assert(search.status === 200, `search returned ${search.status}`)
  assert(searchHtml.includes(product.name), `search did not contain ${product.name}`)

  const categories = await request('/api/store/query?op=categories.list')
  const categoriesPayload = await categories.json()
  const category = product.categories?.[0]
  assert(categories.status === 200 && categoriesPayload.data?.length > 0, 'Woo categories returned no collection')
  assert(category?.slug, `${product.name} has no Woo collection`)

  for (const [path, expected] of [
    ['/collections', category.name],
    ['/collections/all', product.name],
    [`/collections/${encodeURIComponent(category.slug)}`, product.name],
  ]) {
    const response = await request(path)
    const html = await response.text()
    assert(response.status === 200, `${path} returned ${response.status}`)
    assert(html.includes(expected), `${path} did not contain ${expected}`)
  }
})

await check('product detail renders the Woo product', async () => {
  const response = await request(`/products/${encodeURIComponent(product.slug)}`)
  const html = await response.text()
  assert(response.status === 200, `product page returned ${response.status}`)
  assert(html.includes(product.name), `product page did not contain ${product.name}`)
  assert(html.includes('Buy now'), 'product page is missing accelerated purchase')
  assert(html.includes('Decrease quantity'), 'product page is missing the quantity picker')
  assert(html.includes('Increase quantity'), 'product page is missing the quantity picker')
  assert(html.includes('Complete your order'), 'product page is missing complementary products')
  assert(html.includes(relatedProduct.name), 'product page is missing complementary products')
})

await check('WordPress content, policy, blog, and agent-readable routes work', async () => {
  const routes = [
    ['/pages/about', 'About Woo Store SDK'],
    ['/policies/privacy-policy', 'Privacy policy'],
    ['/blogs/journal', 'Building a Woo storefront'],
    ['/blogs/journal/building-a-woo-storefront', 'deliberately fake checkout'],
    ['/llms.txt', 'WooCommerce storefront powered by Woo Store SDK'],
    [`/md/products/${product.slug}`, `# ${product.name}`],
    ['/sitemap.xml', `/products/${product.slug}`],
  ]
  for (const [path, expected] of routes) {
    const response = await request(path)
    const body = await response.text()
    assert(response.status === 200, `${path} returned ${response.status}`)
    assert(body.includes(expected), `${path} did not contain ${expected}`)
  }
})

await check('browser RPC adds an item and persists its signed session', async () => {
  const { payload, response } = await rpc('cart.addItem', { id: product.id, quantity: 2 })
  assert(response.status === 200, `cart.addItem returned ${response.status}`)
  assert(
    payload.data?.items_count === 2 || payload.data?.itemsCount === 2,
    `cart count was not 2: ${JSON.stringify({ itemsCount: payload.data?.itemsCount, items_count: payload.data?.items_count })}`,
  )
  assert(cookie.startsWith('woo_session='), 'Woo session cookie was not persisted')

  const rereadCount = await waitForCartCount(2)
  assert(
    rereadCount === 2,
    `cart session did not survive a second request: ${JSON.stringify({ itemsCount: rereadCount })}`,
  )
})

await check('cart page, quantity updates, and coupon rejection use the same Woo cart', async () => {
  const before = await request(`/api/store/query?op=cart.get&_=${Date.now()}`)
  const beforePayload = await before.json()
  const line = beforePayload.data?.items?.[0]
  assert(before.status === 200 && line?.key, 'cart reread returned no mutable line')

  const cartPage = await request('/cart')
  const cartHtml = await cartPage.text()
  assert(cartPage.status === 200, `cart page returned ${cartPage.status}`)
  assert(cartHtml.includes(product.name), `cart page did not contain ${product.name}`)
  assert(cartHtml.includes('Discount code'), 'cart page is missing discount-code entry')

  const updated = await rpc('cart.updateItem', { key: line.key, quantity: 3 })
  assert(updated.response.status === 200, `cart.updateItem returned ${updated.response.status}`)
  assert(updated.payload.data?.itemsCount === 3, 'cart quantity did not update to 3')

  const rejected = await rpc('cart.applyCoupon', { code: `NOT-A-COUPON-${Date.now()}` })
  assert(rejected.response.status >= 400, 'invalid Woo coupon was unexpectedly accepted')
  assert(rejected.payload.error?.code, 'coupon rejection did not use the typed error envelope')
})

await check('hosted checkout URL crosses back into WooCommerce', async () => {
  const response = await request('/api/store/query?op=cart.checkoutUrl')
  const payload = await response.json()
  assert(
    response.status === 200,
    `cart.checkoutUrl returned ${response.status}: ${JSON.stringify(payload.error ?? null)}`,
  )
  assert(typeof payload.data === 'string' && payload.data.includes('/checkout/c/'), 'checkout URL missing')

  const checkout = await fetch(payload.data, { redirect: 'manual' })
  assert([200, 301, 302].includes(checkout.status), `checkout entry returned ${checkout.status}`)
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

await check('cart removal empties the persisted Woo session', async () => {
  const current = await request(`/api/store/query?op=cart.get&_=${Date.now()}`)
  const currentPayload = await current.json()
  const line = currentPayload.data?.items?.[0]
  assert(current.status === 200 && line?.key, 'cart had no line to remove')

  const removed = await rpc('cart.removeItem', { key: line.key })
  assert(removed.response.status === 200, `cart.removeItem returned ${removed.response.status}`)
  assert(removed.payload.data?.itemsCount === 0, 'cart was not empty after removal')
  assert(await waitForCartCount(0) === 0, 'empty cart did not persist')
})

await check('Woo customer profile, address, and order surfaces persist account data', async () => {
  const update = await rpc('customer.updateProfile', { firstName: 'Updated', lastName: 'Shopper' })
  assert(update.response.status === 200, `customer.updateProfile returned ${update.response.status}`)
  assert(update.payload.data?.firstName === 'Updated', 'customer profile name was not updated')

  const address = await rpc('customer.updateAddress', {
    type: 'shipping',
    address: {
      firstName: 'Updated',
      lastName: 'Shopper',
      address1: '42 Demo Street',
      city: 'Amsterdam',
      state: 'NH',
      postcode: '1012AB',
      country: 'NL',
      email: registeredEmail,
      phone: '+31201234567',
    },
  })
  assert(address.response.status === 200, `customer.updateAddress returned ${address.response.status}`)
  assert(address.payload.data?.shippingAddress?.city === 'Amsterdam', 'shipping address was not saved')

  for (const path of ['/account/profile', '/account/addresses', '/account/orders']) {
    const response = await request(path)
    assert(response.status === 200, `${path} returned ${response.status}`)
  }
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
