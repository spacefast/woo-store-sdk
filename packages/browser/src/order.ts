import type { Address, CartItem, CartTotals } from '@woo/storefront-core'

/** A placed order as the Store API's guest order endpoint returns it. */
export interface OrderConfirmation {
  id: number
  status: string
  items: CartItem[]
  totals: CartTotals
  needsShipping: boolean
  needsPayment: boolean
  billingAddress: Address
  shippingAddress: Address
  raw: unknown
}

/**
 * What the confirmation page has after Woo's checkout redirects back:
 * `?order_id=…&order_key=…`. Guest orders also need the billing email —
 * Woo's own authorization rule for the Store API order endpoint.
 */
export interface OrderLookup {
  id: number
  key: string
  billingEmail?: string
}

type Json = Record<string, unknown>

function record(value: unknown): Json {
  return typeof value === 'object' && value !== null && !Array.isArray(value) ? value as Json : {}
}

function string(value: unknown): string {
  return typeof value === 'string' ? value : ''
}

function number(value: unknown): number {
  const parsed = typeof value === 'number' ? value : Number(value)
  return Number.isFinite(parsed) ? parsed : 0
}

function list(value: unknown): unknown[] {
  return Array.isArray(value) ? value : []
}

function address(value: unknown): Address {
  const source = record(value)
  return {
    firstName: string(source.firstName),
    lastName: string(source.lastName),
    ...(source.company ? { company: string(source.company) } : {}),
    address1: string(source.address1),
    ...(source.address2 ? { address2: string(source.address2) } : {}),
    city: string(source.city),
    state: string(source.state),
    postcode: string(source.postcode),
    country: string(source.country),
    ...(source.email ? { email: string(source.email) } : {}),
    ...(source.phone ? { phone: string(source.phone) } : {}),
  }
}

/** Maps the camel-cased payload `FetchTransport` returns for `/wc/store/v1/order/{id}`. */
export function mapOrderConfirmation(payload: unknown): OrderConfirmation {
  const source = record(payload)
  const totals = record(source.totals)
  return {
    id: number(source.id),
    status: string(source.status),
    items: list(source.items).map((value) => {
      const item = record(value)
      const prices = record(item.prices)
      const lineTotals = record(item.totals)
      return {
        key: string(item.key),
        id: number(item.id),
        name: string(item.name),
        quantity: number(item.quantity),
        images: list(item.images).map((image) => {
          const img = record(image)
          return { id: number(img.id), src: string(img.src), thumbnail: string(img.thumbnail), alt: string(img.alt) }
        }),
        prices: {
          price: string(prices.price),
          currencyCode: string(prices.currencyCode),
          currencyMinorUnit: number(prices.currencyMinorUnit),
        },
        totals: { lineTotal: string(lineTotals.lineTotal), lineSubtotal: string(lineTotals.lineSubtotal) },
        variation: list(item.variation).map((entry) => {
          const variation = record(entry)
          return { attribute: string(variation.attribute), value: string(variation.value) }
        }),
      }
    }),
    totals: {
      totalItems: string(totals.totalItems),
      totalPrice: string(totals.totalPrice),
      totalShipping: totals.totalShipping === null ? null : string(totals.totalShipping),
      totalDiscount: string(totals.totalDiscount),
      totalTax: string(totals.totalTax),
      currencyCode: string(totals.currencyCode),
      currencyMinorUnit: number(totals.currencyMinorUnit),
    },
    needsShipping: source.needsShipping === true,
    needsPayment: source.needsPayment === true,
    billingAddress: address(source.billingAddress),
    shippingAddress: address(source.shippingAddress),
    raw: payload,
  }
}
