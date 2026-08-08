import type {
  Address,
  Cart,
  CartItem,
  Category,
  Customer,
  Order,
  OrderSummary,
  Product,
  ProductImage,
  SessionStore,
  StorefrontConfig,
  Transport,
  TransportRequest,
  TransportResponse,
} from './contracts'
import {
  AuthExpiredError,
  CartConflictError,
  NotFoundError,
  RateLimitedError,
  StoreApiError,
} from './errors'

type JsonRecord = Record<string, unknown>

function isRecord(value: unknown): value is JsonRecord {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function record(value: unknown): JsonRecord {
  return isRecord(value) ? value : {}
}

function string(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback
}

function number(value: unknown, fallback = 0): number {
  if (typeof value === 'number' && Number.isFinite(value)) return value
  if (typeof value === 'string' && value.trim() !== '') {
    const parsed = Number(value)
    if (Number.isFinite(parsed)) return parsed
  }
  return fallback
}

function boolean(value: unknown, fallback = false): boolean {
  return typeof value === 'boolean' ? value : fallback
}

function array(value: unknown): unknown[] {
  return Array.isArray(value) ? value : []
}

function camelKey(key: string): string {
  return key.replace(/_([a-z0-9])/g, (_match, character: string) => character.toUpperCase())
}

function camelCasePayload(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(camelCasePayload)
  if (!isRecord(value)) return value

  const mapped: JsonRecord = {}
  for (const [key, entry] of Object.entries(value)) {
    mapped[camelKey(key)] = camelCasePayload(entry)
  }
  return mapped
}

function mapImage(value: unknown): ProductImage {
  const source = record(value)
  return {
    id: number(source.id),
    src: string(source.src),
    thumbnail: string(source.thumbnail),
    alt: string(source.alt),
  }
}

export function mapProduct(value: unknown): Product {
  const source = record(value)
  const prices = record(source.prices)
  return {
    id: number(source.id),
    name: string(source.name),
    slug: string(source.slug),
    permalink: string(source.permalink),
    description: string(source.description),
    shortDescription: string(source.short_description),
    sku: string(source.sku),
    prices: {
      price: string(prices.price),
      regularPrice: string(prices.regular_price),
      salePrice: prices.sale_price === null ? null : string(prices.sale_price),
      currencyCode: string(prices.currency_code),
      currencyMinorUnit: number(prices.currency_minor_unit),
    },
    images: array(source.images).map(mapImage),
    categories: array(source.categories).map((category) => {
      const categoryRecord = record(category)
      return {
        id: number(categoryRecord.id),
        name: string(categoryRecord.name),
        slug: string(categoryRecord.slug),
      }
    }),
    isInStock: boolean(source.is_in_stock),
    isOnSale: boolean(source.is_on_sale),
    averageRating: string(source.average_rating),
    reviewCount: number(source.review_count),
    raw: value,
  }
}

export function mapCategory(value: unknown): Category {
  const source = record(value)
  return Object.assign(
    {
      id: number(source.id),
      name: string(source.name),
      slug: string(source.slug),
      description: string(source.description),
      parent: number(source.parent),
      count: number(source.count),
      image: source.image === null || source.image === undefined ? null : mapImage(source.image),
    },
    { raw: value },
  )
}

function mapCartItem(value: unknown): CartItem {
  const source = record(value)
  const prices = record(source.prices)
  const totals = record(source.totals)
  return Object.assign(
    {
      key: string(source.key),
      id: number(source.id),
      name: string(source.name),
      quantity: number(source.quantity),
      images: array(source.images).map(mapImage),
      prices: {
        price: string(prices.price),
        currencyCode: string(prices.currency_code),
        currencyMinorUnit: number(prices.currency_minor_unit),
      },
      totals: {
        lineTotal: string(totals.line_total),
        lineSubtotal: string(totals.line_subtotal),
      },
      variation: array(source.variation).map((variation) => {
        const variationRecord = record(variation)
        return {
          attribute: string(variationRecord.attribute),
          value: string(variationRecord.value),
        }
      }),
    },
    { raw: value },
  )
}

export function mapCart(value: unknown): Cart {
  const source = record(value)
  const totals = record(source.totals)
  return {
    items: array(source.items).map(mapCartItem),
    itemsCount: number(source.items_count),
    totals: {
      totalItems: string(totals.total_items),
      totalPrice: string(totals.total_price),
      totalShipping: totals.total_shipping === null ? null : string(totals.total_shipping),
      totalDiscount: string(totals.total_discount),
      totalTax: string(totals.total_tax),
      currencyCode: string(totals.currency_code),
      currencyMinorUnit: number(totals.currency_minor_unit),
    },
    coupons: array(source.coupons).map((coupon) => {
      const couponRecord = record(coupon)
      const couponTotals = record(couponRecord.totals)
      return {
        code: string(couponRecord.code),
        totals: { totalDiscount: string(couponTotals.total_discount) },
      }
    }),
    needsShipping: boolean(source.needs_shipping),
    ...(typeof source.checkout_url === 'string' ? { checkoutUrl: source.checkout_url } : {}),
    raw: value,
  }
}

function mapAddress(value: unknown): Address {
  const source = record(value)
  return {
    firstName: string(source.first_name ?? source.firstName),
    lastName: string(source.last_name ?? source.lastName),
    ...(source.company === undefined ? {} : { company: string(source.company) }),
    address1: string(source.address_1 ?? source.address1),
    ...(source.address_2 === undefined && source.address2 === undefined
      ? {}
      : { address2: string(source.address_2 ?? source.address2) }),
    city: string(source.city),
    state: string(source.state),
    postcode: string(source.postcode),
    country: string(source.country),
    ...(source.email === undefined ? {} : { email: string(source.email) }),
    ...(source.phone === undefined ? {} : { phone: string(source.phone) }),
  }
}

export function mapCustomer(value: unknown): Customer {
  const source = record(value)
  return Object.assign(
    {
      id: number(source.id),
      email: string(source.email),
      firstName: string(source.first_name ?? source.firstName),
      lastName: string(source.last_name ?? source.lastName),
      ...(source.billing_address === undefined && source.billingAddress === undefined
        ? {}
        : { billingAddress: mapAddress(source.billing_address ?? source.billingAddress) }),
      ...(source.shipping_address === undefined && source.shippingAddress === undefined
        ? {}
        : { shippingAddress: mapAddress(source.shipping_address ?? source.shippingAddress) }),
    },
    { raw: value },
  )
}

function mapOrderSummary(value: unknown): OrderSummary {
  const source = record(value)
  return Object.assign(
    {
      id: number(source.id),
      status: string(source.status),
      dateCreated: string(source.date_created ?? source.dateCreated),
      total: string(source.total),
      currencyCode: string(source.currency_code ?? source.currencyCode),
      itemsCount: number(source.items_count ?? source.itemsCount),
    },
    { raw: value },
  )
}

function mapOrder(value: unknown): Order {
  const source = record(value)
  return Object.assign(mapOrderSummary(value), {
    items: array(source.items).map(mapCartItem),
    billingAddress: mapAddress(source.billing_address ?? source.billingAddress),
    shippingAddress: mapAddress(source.shipping_address ?? source.shippingAddress),
    raw: value,
  })
}

function mapResponsePayload(path: string, payload: unknown): unknown {
  const pathname = path.split('?')[0] ?? path

  if (/\/wc\/store\/v1\/products\/categories(?:\/\d+)?\/?$/u.test(pathname)) {
    return Array.isArray(payload) ? payload.map(mapCategory) : mapCategory(payload)
  }
  if (/\/wc\/store\/v1\/products(?:\/\d+)?\/?$/u.test(pathname)) {
    return Array.isArray(payload) ? payload.map(mapProduct) : mapProduct(payload)
  }
  if (/\/wc\/store\/v1\/cart(?:\/[^/]+)?\/?$/u.test(pathname)) return mapCart(payload)
  if (/\/woo-storefront\/v1\/customer\/orders\/\d+\/?$/u.test(pathname)) return mapOrder(payload)
  if (/\/woo-storefront\/v1\/customer\/orders\/?$/u.test(pathname)) {
    if (Array.isArray(payload)) return payload.map(mapOrderSummary)
    return array(record(payload).orders).map(mapOrderSummary)
  }
  if (/\/woo-storefront\/v1\/customer\/profile\/?$/u.test(pathname)) {
    const source = record(payload)
    return mapCustomer(source.customer ?? payload)
  }
  if (/\/woo-storefront\/v1\/customer\/addresses(?:\/[^/]+)?\/?$/u.test(pathname)) {
    const source = record(payload)
    return mapCustomer(source.customer ?? payload)
  }
  if (/\/woo-storefront\/v1\/auth\/(?:login|register)\/?$/u.test(pathname)) {
    const source = record(payload)
    const mapped = record(camelCasePayload(payload))
    if (source.customer !== undefined) mapped.customer = mapCustomer(source.customer)
    else if (source.id !== undefined) Object.assign(mapped, mapCustomer(source))
    return mapped
  }
  return camelCasePayload(payload)
}

function headerNumber(headers: Headers, name: string): number | undefined {
  const value = headers.get(name)
  if (value === null) return undefined
  const parsed = Number.parseInt(value, 10)
  return Number.isFinite(parsed) ? parsed : undefined
}

function retryAfterSeconds(headers: Headers): number | undefined {
  const value = headers.get('Retry-After')
  if (value === null) return undefined
  const seconds = Number.parseInt(value, 10)
  if (Number.isFinite(seconds)) return Math.max(0, seconds)
  const date = Date.parse(value)
  return Number.isNaN(date) ? undefined : Math.max(0, Math.ceil((date - Date.now()) / 1_000))
}

async function responsePayload(response: Response): Promise<unknown> {
  if (response.status === 204) return undefined
  const text = await response.text()
  if (!text) return undefined
  try {
    return JSON.parse(text) as unknown
  } catch {
    return text
  }
}

function errorDetails(payload: unknown, status: number): { code: string; message: string } {
  const source = record(payload)
  return {
    code: string(source.code, `http_${status}`),
    message: string(source.message, `WooCommerce request failed with status ${status}`),
  }
}

function conflictCart(payload: unknown): Cart {
  const source = record(payload)
  const data = record(source.data)
  const candidate =
    source.cart ??
    source.refreshed_cart ??
    data.cart ??
    data.refreshed_cart ??
    (Array.isArray(data.items) ? data : undefined) ??
    (Array.isArray(source.items) ? source : undefined)
  return mapCart(candidate)
}

function httpError(response: Response, payload: unknown): StoreApiError {
  const { code, message } = errorDetails(payload, response.status)
  switch (response.status) {
    case 401:
    case 403:
      return new AuthExpiredError(message, response.status, code)
    case 404:
      return new NotFoundError(message, response.status, code)
    case 409:
      return new CartConflictError(message, response.status, code, conflictCart(payload))
    case 429:
      return new RateLimitedError(message, response.status, code, retryAfterSeconds(response.headers))
    default:
      return new StoreApiError(message, response.status, code)
  }
}

export class FetchTransport implements Transport {
  private readonly origin: string
  private readonly fetchImplementation: typeof fetch

  constructor(config: Pick<StorefrontConfig, 'url' | 'fetch'>) {
    this.origin = config.url.endsWith('/') ? config.url : `${config.url}/`
    this.fetchImplementation = config.fetch ?? globalThis.fetch
    if (!this.fetchImplementation) throw new Error('FetchTransport requires a fetch implementation')
  }

  async request<T>(req: TransportRequest, session: SessionStore): Promise<TransportResponse<T>> {
    const sessionData = await session.read()
    const url = new URL(req.path.replace(/^\//u, ''), this.origin)
    for (const [key, value] of Object.entries(req.query ?? {})) {
      if (value !== undefined) url.searchParams.set(key, String(value))
    }

    const headers = new Headers({ Accept: 'application/json' })
    if (sessionData.cartToken) headers.set('Cart-Token', sessionData.cartToken)
    if (sessionData.customerToken) headers.set('Authorization', `Bearer ${sessionData.customerToken}`)
    if (req.body !== undefined) headers.set('Content-Type', 'application/json')

    const response = await this.fetchImplementation(url, {
      method: req.method,
      headers,
      ...(req.body === undefined ? {} : { body: JSON.stringify(req.body) }),
      // A customer JWT makes any request personalized, regardless of resource.
      ...(sessionData.customerToken ? { cache: 'no-store' as const } : {}),
    })

    const rotatedCartToken = response.headers.get('Cart-Token') ?? undefined
    if (rotatedCartToken && rotatedCartToken !== sessionData.cartToken) {
      await session.write({ ...sessionData, cartToken: rotatedCartToken })
    }

    const payload = await responsePayload(response)
    if (!response.ok) throw httpError(response, payload)

    const payloadRecord = record(payload)
    const bodyTotal = payloadRecord.total === undefined ? undefined : number(payloadRecord.total)
    const bodyTotalPages =
      payloadRecord.total_pages === undefined ? undefined : number(payloadRecord.total_pages)
    const total = headerNumber(response.headers, 'X-WP-Total') ?? bodyTotal
    const totalPages = headerNumber(response.headers, 'X-WP-TotalPages') ?? bodyTotalPages
    return {
      data: mapResponsePayload(req.path, payload) as T,
      status: response.status,
      ...(rotatedCartToken ? { cartToken: rotatedCartToken } : {}),
      ...(total === undefined ? {} : { total }),
      ...(totalPages === undefined ? {} : { totalPages }),
    }
  }
}
