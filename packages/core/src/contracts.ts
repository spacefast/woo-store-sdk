/**
 * Public API contracts for @woo/storefront-*.
 *
 * This file is the source of truth all packages implement or consume.
 * Workers: extend entity types freely (they mirror Store API v1 response
 * schemas) but do NOT change the shape of the machinery interfaces
 * (SessionStore, Transport, WooQueryOptions, WooMutationOptions,
 * StorefrontClient) without updating every consumer.
 */

// ---------------------------------------------------------------------------
// Cache profiles — one taxonomy, two layers (spec §6)
// ---------------------------------------------------------------------------

export type CacheProfile = 'catalog' | 'settings' | 'session'

export interface CacheProfileConfig {
  /** TanStack Query staleTime, ms */
  staleTime: number
  /** TanStack Query gcTime, ms */
  gcTime: number
  /** Server cache tags for this query. `cacheId` is the per-visitor id. */
  serverTags: (ctx: { cacheId: string; params?: unknown }) => string[]
  /** Server cache lifetime hint for adapters. */
  serverLife: 'minutes' | 'hours' | 'days' | 'none'
  /**
   * The no-store invariant: when true and a customerToken is present in the
   * session, adapters MUST bypass the server cache entirely.
   */
  noStoreWithCustomerToken: boolean
}

export declare const CACHE_PROFILES: Record<CacheProfile, CacheProfileConfig>

// ---------------------------------------------------------------------------
// Session (spec §5)
// ---------------------------------------------------------------------------

export interface SessionData {
  /** Store API Cart-Token JWT (core Woo issues this). */
  cartToken?: string
  /** Plugin-issued customer JWT. Never leaves the server. */
  customerToken?: string
  /** Stable per-visitor id used to scope server cache tags. */
  cacheId: string
}

/**
 * Pluggable session persistence. Adapters back this with a signed httpOnly
 * cookie (`woo_session`); tests use an in-memory implementation.
 * Implementations must be request-scoped on the server.
 */
export interface SessionStore {
  read(): Promise<SessionData>
  write(data: SessionData): Promise<void>
  clear(): Promise<void>
}

/** Seal/unseal helpers for the signed cookie envelope (HMAC-SHA256). */
export interface SessionCodec {
  seal(data: SessionData, secret: string): Promise<string>
  unseal(cookieValue: string, secret: string): Promise<SessionData | null>
}

// ---------------------------------------------------------------------------
// Transport
// ---------------------------------------------------------------------------

export interface TransportRequest {
  method: 'GET' | 'POST' | 'PUT' | 'DELETE'
  /** Path relative to the Woo origin, e.g. `/wp-json/wc/store/v1/cart` */
  path: string
  query?: Record<string, string | number | boolean | undefined>
  body?: unknown
  /** Cache profile of the calling query; adapters translate server-side. */
  profile: CacheProfile
}

export interface TransportResponse<T> {
  data: T
  status: number
  /** Rotated Cart-Token from the response header, if any. */
  cartToken?: string
  /** Total item/page counts from X-WP-Total / X-WP-TotalPages, if present. */
  total?: number
  totalPages?: number
}

/**
 * Server-side HTTP layer. Attaches Cart-Token + customer JWT from the
 * session, captures Cart-Token rotation, maps errors to typed errors.
 */
export interface Transport {
  request<T>(req: TransportRequest, session: SessionStore): Promise<TransportResponse<T>>
}

// ---------------------------------------------------------------------------
// Query/mutation factory shapes (TanStack Query-compatible)
// ---------------------------------------------------------------------------

export interface WooQueryOptions<TData> {
  queryKey: readonly unknown[]
  queryFn: () => Promise<TData>
  /** Drives client staleTime/gcTime and server tags/life. */
  profile: CacheProfile
}

/** Query-key taxonomy: ['woo', <resource>, <operation>, params?] */
export type WooQueryKey = readonly ['woo', string, string, ...unknown[]]

export type InvalidationTarget =
  | { type: 'resource'; resource: 'cart' | 'customer' | 'orders' | 'products' | 'session-all' }
  | { type: 'key'; key: WooQueryKey }

export interface WooMutationOptions<TData, TVariables> {
  mutationKey: readonly unknown[]
  mutationFn: (variables: TVariables) => Promise<TData>
  /** Blast radius: applied by TQ client-side, revalidateTag server-side. */
  invalidates: InvalidationTarget[]
  /** Optimistic cart update applied before the server responds. */
  optimisticUpdate?: (variables: TVariables, current: Cart | undefined) => Cart | undefined
}

// ---------------------------------------------------------------------------
// Errors (spec §10)
// ---------------------------------------------------------------------------

export declare class StoreApiError extends Error {
  readonly status: number
  readonly code: string
  constructor(message: string, status: number, code: string)
}
export declare class NotFoundError extends StoreApiError {}
export declare class AuthExpiredError extends StoreApiError {}
export declare class RateLimitedError extends StoreApiError {
  readonly retryAfterSeconds?: number
}
/** 409 with refreshed cart payload (e.g. expected_total mismatch). */
export declare class CartConflictError extends StoreApiError {
  readonly refreshedCart: Cart
}

// ---------------------------------------------------------------------------
// Entities — mirror Store API v1 response schemas (extend as needed)
// ---------------------------------------------------------------------------

export interface Money {
  /** Minor-unit string as Store API returns, e.g. "1999" */
  amount: string
  currencyCode: string
  currencyMinorUnit: number
}

export interface ProductImage {
  id: number
  src: string
  thumbnail: string
  alt: string
}

export interface Product {
  id: number
  name: string
  slug: string
  permalink: string
  description: string
  shortDescription: string
  sku: string
  prices: {
    price: string
    regularPrice: string
    salePrice: string | null
    currencyCode: string
    currencyMinorUnit: number
  }
  images: ProductImage[]
  categories: { id: number; name: string; slug: string }[]
  isInStock: boolean
  isOnSale: boolean
  averageRating: string
  reviewCount: number
  /** Raw Store API payload for fields not yet mapped. */
  raw?: unknown
}

export interface ProductListParams {
  page?: number
  perPage?: number
  search?: string
  category?: string | number
  orderby?: 'date' | 'price' | 'rating' | 'popularity' | 'title'
  order?: 'asc' | 'desc'
  onSale?: boolean
  minPrice?: string
  maxPrice?: string
}

export interface Category {
  id: number
  name: string
  slug: string
  description: string
  parent: number
  count: number
  image: ProductImage | null
}

export interface CartItem {
  key: string
  id: number
  name: string
  quantity: number
  images: ProductImage[]
  prices: { price: string; currencyCode: string; currencyMinorUnit: number }
  totals: { lineTotal: string; lineSubtotal: string }
  variation: { attribute: string; value: string }[]
}

export interface CartTotals {
  totalItems: string
  totalPrice: string
  totalShipping: string | null
  totalDiscount: string
  totalTax: string
  currencyCode: string
  currencyMinorUnit: number
}

export interface Cart {
  items: CartItem[]
  itemsCount: number
  totals: CartTotals
  coupons: { code: string; totals: { totalDiscount: string } }[]
  needsShipping: boolean
  /** Plugin-provided hosted checkout URL (absent without the feature plugin). */
  checkoutUrl?: string
  raw?: unknown
}

export interface Address {
  firstName: string
  lastName: string
  company?: string
  address1: string
  address2?: string
  city: string
  state: string
  postcode: string
  country: string
  email?: string
  phone?: string
}

export interface Customer {
  id: number
  email: string
  firstName: string
  lastName: string
  billingAddress?: Address
  shippingAddress?: Address
}

export interface OrderSummary {
  id: number
  status: string
  dateCreated: string
  total: string
  currencyCode: string
  itemsCount: number
}

export interface Order extends OrderSummary {
  items: CartItem[]
  billingAddress: Address
  shippingAddress: Address
}

// ---------------------------------------------------------------------------
// Client surface (spec §4) — what createStorefrontClient() returns
// ---------------------------------------------------------------------------

export interface StorefrontConfig {
  /** WooCommerce origin, e.g. https://store.example.com */
  url: string
  /** App secret for sealing the session cookie. */
  sessionSecret?: string
  /** Override built-in fetch (tests, custom agents). */
  fetch?: typeof fetch
}

/**
 * Framework-agnostic client. Query factories return WooQueryOptions usable
 * directly with TanStack Query; direct-call helpers (`.get()`-style) execute
 * the queryFn immediately for server loaders.
 */
export interface StorefrontClient {
  products: {
    list(params?: ProductListParams): WooQueryOptions<{ items: Product[]; total: number; totalPages: number }>
    bySlug(slug: string): WooQueryOptions<Product>
    byId(id: number): WooQueryOptions<Product>
  }
  categories: {
    list(): WooQueryOptions<Category[]>
    bySlug(slug: string): WooQueryOptions<Category>
  }
  cart: {
    get(): WooQueryOptions<Cart>
    addItem: WooMutationOptions<Cart, { id: number; quantity?: number; variation?: Record<string, string> }>
    updateItem: WooMutationOptions<Cart, { key: string; quantity: number }>
    removeItem: WooMutationOptions<Cart, { key: string }>
    applyCoupon: WooMutationOptions<Cart, { code: string }>
    removeCoupon: WooMutationOptions<Cart, { code: string }>
    /** Fresh hosted checkout URL — request at click time (spec §7). */
    checkoutUrl(): Promise<string>
  }
  checkout: {
    get(): WooQueryOptions<unknown>
    /** True headless checkout (v2 per-gateway path). */
    submit: WooMutationOptions<unknown, { billingAddress: Address; shippingAddress?: Address; paymentMethod: string; paymentData?: Record<string, string>; expectedTotal?: string }>
  }
  auth: {
    login: WooMutationOptions<Customer, { email: string; password: string }>
    logout: WooMutationOptions<void, void>
    register: WooMutationOptions<Customer, { email: string; password: string; firstName?: string; lastName?: string }>
  }
  customer: {
    get(): WooQueryOptions<Customer>
    orders(params?: { page?: number; perPage?: number }): WooQueryOptions<{ items: OrderSummary[]; total: number }>
    order(id: number): WooQueryOptions<Order>
    updateAddress: WooMutationOptions<Customer, { type: 'billing' | 'shipping'; address: Address }>
  }
}

export declare function createStorefrontClient(
  config: StorefrontConfig,
  session: SessionStore,
  transport?: Transport,
): StorefrontClient

// ---------------------------------------------------------------------------
// Adapter surface (spec §4) — what @woo/storefront-next / -start implement
// ---------------------------------------------------------------------------

export interface StorefrontAdapter {
  /** Direct server-side data access (loaders/RSC): executes queryFn with the request session. */
  products: StorefrontClient['products']
  categories: StorefrontClient['categories']
  cart: StorefrontClient['cart']
  checkout: StorefrontClient['checkout']
  auth: StorefrontClient['auth']
  customer: StorefrontClient['customer']
}

/**
 * The proxy protocol between @woo/storefront-react (browser) and the mounted
 * server route. Browser POSTs { op, args } to /api/store/rpc; queries GET
 * /api/store/query?op=…&args=…. Ops are dot-paths into StorefrontClient
 * (e.g. "cart.addItem"). The route executes with the request's SessionStore,
 * re-seals the cookie when the Cart-Token rotates, and returns
 * { data } | { error: { code, status, message, refreshedCart? } }.
 */
export interface RpcRequest {
  op: string
  args?: unknown
}

export const RPC_BASE_PATH = '/api/store'
