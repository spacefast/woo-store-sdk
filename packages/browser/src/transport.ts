import {
  createStorefrontClient,
  FetchTransport,
  NotFoundError,
  StoreApiError,
  type Cart,
  type Product,
  type ProductListParams,
  type SessionStore,
  type StorefrontClient,
} from '@woo/storefront-core'

import { CheckoutOffInPreviewError, UnsupportedOperationError } from './errors'
import { mapOrderConfirmation, type OrderConfirmation, type OrderLookup } from './order'
import {
  detectCommerceSource,
  loadPreviewCatalog,
  PreviewCatalogReader,
  type CommerceSource,
} from './preview'
import { SessionStorageSessionStore } from './session'

const STORE_API = '/wp-json/wc/store/v1'

/** Every operation the browser adapter answers. Anything else throws `UnsupportedOperationError`. */
export const SUPPORTED_OPERATIONS = [
  'products.list',
  'products.bySlug',
  'products.byId',
  'products.byKey',
  'cart.get',
  'cart.addItem',
  'cart.updateItem',
  'cart.removeItem',
  'cart.checkoutUrl',
  'order.get',
] as const

export type SupportedOperation = (typeof SUPPORTED_OPERATIONS)[number]

export interface BrowserTransportOptions {
  /** WooCommerce origin. Defaults to the page's own origin (one-origin storefronts). */
  url?: string
  fetch?: typeof fetch
  /** Cart-Token persistence. Defaults to `sessionStorage`. */
  session?: SessionStore
  /**
   * `'detect'` (default) asks the host via `commerceConfigPath`. Pin `'store'`
   * on hosts without that endpoint to skip the request.
   */
  source?: CommerceSource | 'detect'
  /** Engine endpoint naming the source. Default `/__spacefast/commerce.json`. */
  commerceConfigPath?: string
  /** Build-time preview catalog. Default `/_spacefast/catalog.json`. */
  catalogPath?: string
  /** Storefront page Woo returns to after payment. Default `/order-confirmation/`. */
  confirmationPath?: string
}

function single<T>(args: unknown): T {
  return (Array.isArray(args) ? args[0] : args) as T
}

/**
 * The browser adapter for `@woo/storefront-react`. Pass it as the provider's
 * `transport`; the hooks call it exactly as they call `RpcTransport`, but each
 * operation runs the core client in the page against the Store API with a
 * `Cart-Token` session — no storefront server.
 */
export class BrowserTransport {
  private readonly options: BrowserTransportOptions
  private readonly fetchImpl: typeof fetch
  private sourcePromise: Promise<CommerceSource> | null = null
  private catalogPromise: Promise<PreviewCatalogReader> | null = null
  private store: { client: StorefrontClient; transport: FetchTransport; session: SessionStore } | null = null

  constructor(options: BrowserTransportOptions = {}) {
    this.options = options
    this.fetchImpl = options.fetch ?? ((input, init) => globalThis.fetch(input, init))
  }

  /** Which source this page reads. Resolved once, on first use. */
  source(): Promise<CommerceSource> {
    if (this.options.source && this.options.source !== 'detect') {
      return Promise.resolve(this.options.source)
    }
    this.sourcePromise ??= detectCommerceSource(
      this.fetchImpl,
      this.resolve(this.options.commerceConfigPath ?? '/__spacefast/commerce.json'),
    )
    this.sourcePromise.catch(() => { this.sourcePromise = null })
    return this.sourcePromise
  }

  async query<T>(operation: string, args?: unknown): Promise<T> {
    return this.run(operation, args) as Promise<T>
  }

  async mutate<T>(operation: string, args?: unknown): Promise<T> {
    return this.run(operation, args) as Promise<T>
  }

  /** Product resolution by declared key (the Woo SKU). */
  productByKey(key: string): Promise<Product> {
    return this.query('products.byKey', key)
  }

  /** Signed `/checkout/c/{token}` handoff for the current cart, returning to `confirmationPath`. */
  checkoutUrl(): Promise<string> {
    return this.mutate('cart.checkoutUrl')
  }

  /** The placed order, for the storefront's confirmation page. */
  order(lookup: OrderLookup): Promise<OrderConfirmation> {
    return this.query('order.get', lookup)
  }

  private async run(operation: string, args: unknown): Promise<unknown> {
    if (!(SUPPORTED_OPERATIONS as readonly string[]).includes(operation)) {
      throw new UnsupportedOperationError(operation)
    }
    const op = operation as SupportedOperation

    if (await this.source() === 'preview') {
      return this.runPreview(op, args)
    }
    return this.runStore(op, args)
  }

  private async runPreview(op: SupportedOperation, args: unknown): Promise<unknown> {
    if (op.startsWith('cart.') || op === 'order.get') throw new CheckoutOffInPreviewError()

    const catalog = await this.catalog()
    switch (op) {
      case 'products.list': return catalog.list(single<ProductListParams | undefined>(args))
      case 'products.byKey': return catalog.byKey(this.key(args))
      case 'products.bySlug': return catalog.bySlug(single<string>(args))
      case 'products.byId': return catalog.byId(Number(single(args)))
      default: throw new UnsupportedOperationError(op)
    }
  }

  private async runStore(op: SupportedOperation, args: unknown): Promise<unknown> {
    const { client, transport, session } = this.storeClient()
    switch (op) {
      case 'products.list': return client.products.list(single<ProductListParams | undefined>(args)).queryFn()
      case 'products.bySlug': return client.products.bySlug(single<string>(args)).queryFn()
      case 'products.byId': return client.products.byId(Number(single(args))).queryFn()
      case 'products.byKey': {
        const key = this.key(args)
        const { data } = await transport.request<Product[]>({
          method: 'GET',
          path: `${STORE_API}/products`,
          query: { sku: key },
          profile: 'catalog',
        }, session)
        // The `sku` filter takes a comma list; only an exact match is this key.
        const product = data.find((candidate) => candidate.sku === key)
        if (!product) throw new NotFoundError(`Product with key "${key}" was not found`, 404, 'product_not_found')
        return product
      }
      case 'cart.get': return client.cart.get().queryFn()
      case 'cart.addItem': return client.cart.addItem.mutationFn(single(args))
      case 'cart.updateItem': return client.cart.updateItem.mutationFn(single(args))
      case 'cart.removeItem': return client.cart.removeItem.mutationFn(single(args))
      case 'cart.checkoutUrl': {
        // woo-storefront signs `checkout_url` into every cart response and
        // signs `return_url` alongside it, so the browser needs no secret.
        const { data: cart } = await transport.request<Cart>({
          method: 'GET',
          path: `${STORE_API}/cart`,
          query: { return_url: this.returnUrl() },
          profile: 'session',
        }, session)
        if (cart.itemsCount === 0) throw new StoreApiError('The cart is empty.', 400, 'cart_empty')
        if (!cart.checkoutUrl) {
          throw new StoreApiError(
            'The store did not return a checkout URL. Activate the Woo Storefront plugin.',
            501,
            'checkout_handoff_unavailable',
          )
        }
        return cart.checkoutUrl
      }
      case 'order.get': {
        const lookup = single<OrderLookup>(args)
        const { data } = await transport.request<unknown>({
          method: 'GET',
          path: `${STORE_API}/order/${Number(lookup.id)}`,
          query: { key: lookup.key, billing_email: lookup.billingEmail },
          profile: 'session',
        }, session)
        return mapOrderConfirmation(data)
      }
    }
  }

  private key(args: unknown): string {
    const value = single<string | { key: string }>(args)
    return typeof value === 'string' ? value : value.key
  }

  private catalog(): Promise<PreviewCatalogReader> {
    this.catalogPromise ??= loadPreviewCatalog(
      this.fetchImpl,
      this.resolve(this.options.catalogPath ?? '/_spacefast/catalog.json'),
    ).then((catalog) => new PreviewCatalogReader(catalog))
    // A failed load is retried on the next call rather than cached.
    this.catalogPromise.catch(() => { this.catalogPromise = null })
    return this.catalogPromise
  }

  private storeClient() {
    if (!this.store) {
      const config = { url: this.wooOrigin(), fetch: this.fetchImpl }
      const session = this.options.session ?? new SessionStorageSessionStore(`woo-storefront:session:${config.url}`)
      const transport = new FetchTransport(config)
      this.store = { client: createStorefrontClient(config, session, transport), transport, session }
    }
    return this.store
  }

  private pageOrigin(): string {
    const origin = globalThis.location?.origin
    if (!origin || origin === 'null') {
      throw new Error('The browser adapter needs a page origin; pass `url` outside a browser.')
    }
    return origin
  }

  private wooOrigin(): string {
    return this.options.url ?? this.pageOrigin()
  }

  /**
   * The storefront's own files (`commerce.json`, the preview catalog, the
   * confirmation page) live on the page origin; the Woo origin stands in only
   * outside a browser.
   */
  private resolve(path: string): string {
    const page = globalThis.location?.origin
    return new URL(path, page && page !== 'null' ? page : this.wooOrigin()).toString()
  }

  private returnUrl(): string {
    return this.resolve(this.options.confirmationPath ?? '/order-confirmation/')
  }
}

export function createBrowserTransport(options?: BrowserTransportOptions): BrowserTransport {
  return new BrowserTransport(options)
}
