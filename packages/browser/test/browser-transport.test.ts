/**
 * Adapter behavior the shared hook suite (packages/react/test/hooks.test.tsx)
 * can't reach: Cart-Token persistence, key resolution, the checkout handoff,
 * the guest order read, the preview source, and the unsupported set. The
 * store side runs against captured Store API shapes (see support/fake-woo.ts);
 * e2e/browser-adapter-e2e.mjs runs the same flows against a real Woo.
 */
import { AuthExpiredError, NotFoundError, StoreApiError, type Cart, type Product } from '@woo/storefront-core'
import { describe, expect, it } from 'vitest'

import {
  BrowserTransport,
  CheckoutOffInPreviewError,
  SessionStorageSessionStore,
  UnsupportedOperationError,
  type OrderConfirmation,
} from '../src'
import { createFakeWoo, ORDER, PREVIEW_CATALOG, WOO_ORIGIN, type FakeWooOptions } from './support/fake-woo'
import { MemoryStorage } from './support/memory-storage'

function adapter(woo = createFakeWoo(), storage: Storage = new MemoryStorage()) {
  return new BrowserTransport({
    url: WOO_ORIGIN,
    fetch: woo.fetch,
    session: new SessionStorageSessionStore('woo-storefront:session', storage),
  })
}

function previewAdapter(options: FakeWooOptions = {}) {
  return adapter(createFakeWoo({ commerce: { source: 'preview' }, catalog: PREVIEW_CATALOG, ...options }))
}

describe('store source', () => {
  it('keeps the Cart-Token in sessionStorage so the cart survives a page load', async () => {
    const woo = createFakeWoo()
    const storage = new MemoryStorage()
    const ebook = await adapter(woo, storage).productByKey('field-guide-ebook')
    await adapter(woo, storage).mutate<Cart>('cart.addItem', { id: ebook.id, quantity: 1 })

    // A fresh adapter on the same tab storage is the next page load.
    const cart = await adapter(woo, storage).query<Cart>('cart.get')
    expect(cart.items.map((item) => [item.name, item.quantity])).toEqual([['The Field Guide (ebook)', 1]])

    const freshTab = await adapter(woo, new MemoryStorage()).query<Cart>('cart.get')
    expect(freshTab.itemsCount).toBe(0)
  })

  it('resolves a product by its declared key and nothing else', async () => {
    const store = adapter()
    const print = await store.productByKey('field-guide-print')
    expect(print).toMatchObject({ sku: 'field-guide-print', name: 'The Field Guide (paperback)' })
    expect(print.prices).toMatchObject({ price: '2900', currencyCode: 'EUR', currencyMinorUnit: 2 })

    await expect(store.productByKey('field-guide')).rejects.toBeInstanceOf(NotFoundError)
  })

  it('hands off a non-empty cart to the signed checkout entry, returning to the confirmation page', async () => {
    const store = adapter()
    await expect(store.checkoutUrl()).rejects.toMatchObject({ code: 'cart_empty' })

    const print = await store.productByKey('field-guide-print')
    await store.mutate('cart.addItem', { id: print.id, quantity: 2 })
    const handoff = new URL(await store.checkoutUrl())

    expect(handoff.pathname).toMatch(/^\/checkout\/c\/cart-token-\d+$/u)
    expect(handoff.searchParams.get('return_url')).toBe(`${WOO_ORIGIN}/order-confirmation/`)
  })

  it('reads a guest order only with its key and billing email, as Woo requires', async () => {
    const store = adapter()
    const missingEmail = store.order({ id: ORDER.id, key: ORDER.key })
    await expect(missingEmail).rejects.toBeInstanceOf(AuthExpiredError)
    await expect(missingEmail).rejects.toMatchObject({ code: 'woocommerce_rest_invalid_billing_email' })

    const order: OrderConfirmation = await store.order({
      id: ORDER.id,
      key: ORDER.key,
      billingEmail: ORDER.billingEmail.toUpperCase(),
    })
    expect(order).toMatchObject({ id: ORDER.id, status: 'processing', needsShipping: true })
    expect(order.items.map((item) => [item.name, item.quantity, item.totals.lineTotal])).toEqual([
      ['The Field Guide (paperback)', 2, '5800'],
      ['The Field Guide (ebook)', 1, '1200'],
    ])
    expect(order.totals).toMatchObject({ totalShipping: '1500', totalPrice: '8500', currencyCode: 'EUR' })
    expect(order.shippingAddress).toMatchObject({ address1: 'Ul. Prosta 1', city: 'Warszawa', country: 'PL' })
  })

  it('treats a missing or failing commerce.json as the store', async () => {
    const missing = adapter(createFakeWoo({ commerce: null }))
    await expect(missing.source()).resolves.toBe('store')

    const offline = new BrowserTransport({
      url: WOO_ORIGIN,
      fetch: async () => { throw new TypeError('Failed to fetch') },
    })
    await expect(offline.source()).resolves.toBe('store')
  })
})

describe('preview source', () => {
  it('lists the active declared catalog with Store API product shapes', async () => {
    const preview = previewAdapter()
    const { items, total } = await preview.query<{ items: Product[]; total: number }>('products.list')

    expect(total).toBe(2)
    expect(items.map((product) => product.sku)).toEqual(['field-guide-ebook', 'field-guide-print'])
    expect(items[1]).toMatchObject({
      permalink: '/books/field-guide/',
      description: '<p>A practical guide to reading the land.</p>\n<p>Ships in a recycled sleeve.</p>',
      prices: { price: '2900', currencyCode: 'EUR', currencyMinorUnit: 2 },
      images: [{ src: '/images/paperback.svg' }],
    })
    await expect(preview.productByKey('field-guide-retired')).rejects.toBeInstanceOf(NotFoundError)
  })

  it('answers every cart and order operation with the typed checkout-off state', async () => {
    const preview = previewAdapter()
    for (const call of [
      () => preview.query('cart.get'),
      () => preview.mutate('cart.addItem', { id: 1 }),
      () => preview.checkoutUrl(),
      () => preview.order({ id: 1, key: 'k' }),
    ]) {
      await expect(call()).rejects.toBeInstanceOf(CheckoutOffInPreviewError)
    }
  })

  it('surfaces a missing preview catalog instead of an empty store', async () => {
    const preview = previewAdapter({ catalog: null })
    await expect(preview.query('products.list')).rejects.toMatchObject({
      status: 404,
      code: 'preview_catalog_unavailable',
    })
  })
})

describe('unsupported operations', () => {
  it('refuses an operation outside the published subset explicitly', async () => {
    const error = await adapter().query('customer.get').catch((caught: unknown) => caught)
    expect(error).toBeInstanceOf(UnsupportedOperationError)
    expect(error).toBeInstanceOf(StoreApiError)
    expect(error).toMatchObject({ status: 501, code: 'unsupported_operation', operation: 'customer.get' })
  })
})
