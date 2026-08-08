import {
  CACHE_PROFILES,
  CartConflictError,
  RPC_BASE_PATH,
  StoreApiError,
  createStorefrontClient,
} from '@woo/storefront-core'
import type {
  InvalidationTarget,
  RpcRequest,
  SessionData,
  SessionStore,
  StorefrontAdapter,
  StorefrontClient,
  StorefrontConfig,
  WooMutationOptions,
  WooQueryOptions,
} from '@woo/storefront-core'

import { StartCookieSessionStore } from './session-store'
import { TaggedCache } from './tagged-cache'

export {
  SESSION_COOKIE_NAME,
  SESSION_MAX_AGE_SECONDS,
  StartCookieSessionStore,
} from './session-store'
export { SERVER_LIFE_MS, TaggedCache } from './tagged-cache'
export type { ServerLife } from './tagged-cache'

type MaybePromise<T> = T | Promise<T>

export interface StartRouteContext {
  request: Request
}

export type StartRouteHandler = (context: StartRouteContext) => MaybePromise<Response>

export interface StartServerRoute {
  handlers: {
    GET: StartRouteHandler
    POST: StartRouteHandler
  }
}

export interface ServerQueryOptions<TData> extends WooQueryOptions<TData>, PromiseLike<TData> {}

type ServerQueryFactory<T> = T extends (...args: infer TArgs) => WooQueryOptions<infer TData>
  ? (...args: TArgs) => ServerQueryOptions<TData>
  : T

type ServerResource<T> = { [K in keyof T]: ServerQueryFactory<T[K]> }

export interface StartStorefront extends StorefrontAdapter {
  serverRoute: StartServerRoute
  products: ServerResource<StorefrontClient['products']>
  categories: ServerResource<StorefrontClient['categories']>
  cart: ServerResource<StorefrontClient['cart']>
  checkout: ServerResource<StorefrontClient['checkout']>
  auth: ServerResource<StorefrontClient['auth']>
  customer: ServerResource<StorefrontClient['customer']>
  revalidateRoute: StartRouteHandler
}

export interface StartStorefrontConfig extends StorefrontConfig {
  /** Optional cache injection, primarily for tests and custom runtimes. */
  cache?: TaggedCache
  /** Shared secret expected in the Woo cache webhook header. */
  revalidateSecret?: string
  /** Defaults to `x-woo-webhook-secret`, matching the Next adapter. */
  revalidateSecretHeader?: string
}

export const DEFAULT_REVALIDATE_SECRET_HEADER = 'x-woo-webhook-secret'

function stableSerialize(value: unknown): string {
  if (value === undefined) return 'undefined'
  if (value === null || typeof value !== 'object') return JSON.stringify(value) ?? 'undefined'
  if (Array.isArray(value)) return `[${value.map(stableSerialize).join(',')}]`

  const record = value as Record<string, unknown>
  return `{${Object.keys(record)
    .sort()
    .map((key) => `${JSON.stringify(key)}:${stableSerialize(record[key])}`)
    .join(',')}}`
}

function queryCacheKey(queryKey: readonly unknown[]): string {
  return stableSerialize(queryKey)
}

function asServerQuery<TData>(
  options: WooQueryOptions<TData>,
  params: unknown,
  session: SessionStore,
  cache: TaggedCache,
): ServerQueryOptions<TData> {
  const execute = async (): Promise<TData> => {
    const sessionData = await session.read()
    const policy = CACHE_PROFILES[options.profile]

    // Global invariant from the design: authenticated requests never enter a
    // shared server cache, irrespective of the resource being queried.
    if (sessionData.customerToken || policy.serverLife === 'none') {
      return options.queryFn()
    }

    return cache.getOrSet(
      queryCacheKey(options.queryKey),
      policy.serverLife,
      policy.serverTags({ cacheId: sessionData.cacheId, params }),
      options.queryFn,
    )
  }

  return {
    ...options,
    queryFn: execute,
    then: (onfulfilled, onrejected) => execute().then(onfulfilled, onrejected),
  }
}

async function invalidateTarget(
  target: InvalidationTarget,
  session: SessionData,
  cache: TaggedCache,
): Promise<void> {
  if (target.type === 'key') {
    cache.delete(queryCacheKey(target.key))
    const resource = target.key[1]
    const tags = resource === 'products' || resource === 'categories'
      ? CACHE_PROFILES.catalog.serverTags({ cacheId: session.cacheId, params: target.key[3] })
      : [`${resource}-${session.cacheId}`]
    for (const tag of tags) cache.invalidate(tag)
    return
  }

  const tags = (() => {
    switch (target.resource) {
      case 'cart':
        return [`cart-${session.cacheId}`]
      case 'customer':
        return [`customer-${session.cacheId}`]
      case 'orders':
        return [`orders-${session.cacheId}`]
      case 'products':
        return ['products']
      case 'session-all':
        return [`cart-${session.cacheId}`, `customer-${session.cacheId}`, `orders-${session.cacheId}`]
    }
  })()
  for (const tag of tags) {
    cache.invalidate(tag)
  }
}

type ClientFactory = (session: SessionStore) => StorefrontClient

function withInvalidation<TData, TVariables>(
  template: WooMutationOptions<TData, TVariables>,
  createSession: () => SessionStore,
  createClient: ClientFactory,
  select: (client: StorefrontClient) => WooMutationOptions<TData, TVariables>,
  cache: TaggedCache,
): WooMutationOptions<TData, TVariables> {
  return {
    ...template,
    mutationFn: async (variables) => {
      const session = createSession()
      const sessionBeforeMutation = await session.read()
      const data = await select(createClient(session)).mutationFn(variables)
      await Promise.all(template.invalidates.map((target) => invalidateTarget(target, sessionBeforeMutation, cache)))
      return data
    },
  }
}

function bindClient(
  template: StorefrontClient,
  createSession: () => SessionStore,
  createClient: ClientFactory,
  cache: TaggedCache,
): Omit<StartStorefront, 'serverRoute' | 'revalidateRoute'> {
  const query = <TData>(
    select: (client: StorefrontClient) => WooQueryOptions<TData>,
  ): ServerQueryOptions<TData> => {
    const session = createSession()
    const options = select(createClient(session))
    const params = options.queryKey[3]
    return asServerQuery(options, params, session, cache)
  }

  return {
    products: {
      list: (params) => query((client) => client.products.list(params)),
      bySlug: (slug) => query((client) => client.products.bySlug(slug)),
      byId: (id) => query((client) => client.products.byId(id)),
    },
    categories: {
      list: () => query((client) => client.categories.list()),
      bySlug: (slug) => query((client) => client.categories.bySlug(slug)),
    },
    cart: {
      get: () => query((client) => client.cart.get()),
      addItem: withInvalidation(template.cart.addItem, createSession, createClient, (client) => client.cart.addItem, cache),
      updateItem: withInvalidation(template.cart.updateItem, createSession, createClient, (client) => client.cart.updateItem, cache),
      removeItem: withInvalidation(template.cart.removeItem, createSession, createClient, (client) => client.cart.removeItem, cache),
      applyCoupon: withInvalidation(template.cart.applyCoupon, createSession, createClient, (client) => client.cart.applyCoupon, cache),
      removeCoupon: withInvalidation(template.cart.removeCoupon, createSession, createClient, (client) => client.cart.removeCoupon, cache),
      checkoutUrl: () => createClient(createSession()).cart.checkoutUrl(),
    },
    checkout: {
      get: () => query((client) => client.checkout.get()),
      submit: withInvalidation(template.checkout.submit, createSession, createClient, (client) => client.checkout.submit, cache),
    },
    auth: {
      login: withInvalidation(template.auth.login, createSession, createClient, (client) => client.auth.login, cache),
      logout: withInvalidation(template.auth.logout, createSession, createClient, (client) => client.auth.logout, cache),
      register: withInvalidation(template.auth.register, createSession, createClient, (client) => client.auth.register, cache),
    },
    customer: {
      get: () => query((client) => client.customer.get()),
      orders: (params) => query((client) => client.customer.orders(params)),
      order: (id) => query((client) => client.customer.order(id)),
      updateAddress: withInvalidation(
        template.customer.updateAddress,
        createSession,
        createClient,
        (client) => client.customer.updateAddress,
        cache,
      ),
    },
  }
}

function isRpcRequest(value: unknown): value is RpcRequest {
  return (
    value !== null &&
    typeof value === 'object' &&
    !Array.isArray(value) &&
    typeof (value as { op?: unknown }).op === 'string'
  )
}

const MUTATION_OPERATIONS = new Set<string>([
  'cart.addItem',
  'cart.updateItem',
  'cart.removeItem',
  'cart.applyCoupon',
  'cart.removeCoupon',
  'checkout.submit',
  'auth.login',
  'auth.logout',
  'auth.register',
  'customer.updateAddress',
])

function rpcArgument(args: unknown): unknown {
  return Array.isArray(args) ? args[0] : args
}

async function executeRpc(client: Omit<StartStorefront, 'serverRoute' | 'revalidateRoute'>, rpc: RpcRequest) {
  const args = rpcArgument(rpc.args)
  switch (rpc.op) {
    case 'products.list':
      return client.products.list(args as never).queryFn()
    case 'products.bySlug':
      return client.products.bySlug(args as never).queryFn()
    case 'products.byId':
      return client.products.byId(args as never).queryFn()
    case 'categories.list':
      return client.categories.list().queryFn()
    case 'categories.bySlug':
      return client.categories.bySlug(args as never).queryFn()
    case 'cart.get':
      return client.cart.get().queryFn()
    case 'cart.addItem':
      return client.cart.addItem.mutationFn(args as never)
    case 'cart.updateItem':
      return client.cart.updateItem.mutationFn(args as never)
    case 'cart.removeItem':
      return client.cart.removeItem.mutationFn(args as never)
    case 'cart.applyCoupon':
      return client.cart.applyCoupon.mutationFn(args as never)
    case 'cart.removeCoupon':
      return client.cart.removeCoupon.mutationFn(args as never)
    case 'cart.checkoutUrl':
      return client.cart.checkoutUrl()
    case 'checkout.get':
      return client.checkout.get().queryFn()
    case 'checkout.submit':
      return client.checkout.submit.mutationFn(args as never)
    case 'auth.login':
      return client.auth.login.mutationFn(args as never)
    case 'auth.logout':
      return client.auth.logout.mutationFn(undefined)
    case 'auth.register':
      return client.auth.register.mutationFn(args as never)
    case 'customer.get':
      return client.customer.get().queryFn()
    case 'customer.orders':
      return client.customer.orders(args as never).queryFn()
    case 'customer.order':
      return client.customer.order(args as never).queryFn()
    case 'customer.updateAddress':
      return client.customer.updateAddress.mutationFn(args as never)
    default:
      throw new RpcProtocolError(`Unknown RPC operation: ${rpc.op}`)
  }
}

class RpcProtocolError extends Error {
  readonly status: number
  readonly code: string

  constructor(message: string, status = 400, code = 'invalid_rpc_request') {
    super(message)
    this.status = status
    this.code = code
  }
}

function errorResponse(error: unknown): Response {
  const known = error instanceof StoreApiError || error instanceof RpcProtocolError
  const status = known ? error.status : 500
  const code = known ? error.code : 'internal_error'
  const message = error instanceof Error ? error.message : 'An unexpected error occurred'
  const refreshedCart = error instanceof CartConflictError ? error.refreshedCart : undefined

  return Response.json(
    {
      error: {
        code,
        status,
        message,
        ...(refreshedCart === undefined ? {} : { refreshedCart }),
      },
    },
    { status, headers: { 'Cache-Control': 'no-store' } },
  )
}

function routeKind(request: Request): 'query' | 'rpc' | null {
  const pathname = new URL(request.url).pathname.replace(/\/+$/u, '')
  if (pathname.endsWith(`${RPC_BASE_PATH}/query`)) return 'query'
  if (pathname.endsWith(`${RPC_BASE_PATH}/rpc`)) return 'rpc'
  return null
}

async function readGetRpc(request: Request): Promise<RpcRequest> {
  if (routeKind(request) !== 'query') {
    throw new RpcProtocolError('GET is only supported at the query endpoint', 404, 'not_found')
  }

  const url = new URL(request.url)
  const op = url.searchParams.get('op')
  if (!op) throw new RpcProtocolError('Missing RPC operation')

  const rawArgs = url.searchParams.get('args')
  if (rawArgs === null) return { op }
  try {
    return { op, args: JSON.parse(rawArgs) as unknown }
  } catch {
    throw new RpcProtocolError('RPC args must be valid JSON')
  }
}

async function readPostRpc(request: Request): Promise<RpcRequest> {
  if (routeKind(request) !== 'rpc') {
    throw new RpcProtocolError('POST is only supported at the RPC endpoint', 404, 'not_found')
  }

  let body: unknown
  try {
    body = await request.json()
  } catch {
    throw new RpcProtocolError('RPC body must be valid JSON')
  }
  if (!isRpcRequest(body)) {
    throw new RpcProtocolError('RPC body must contain an operation')
  }
  return body
}

function rpcHandler(
  method: 'GET' | 'POST',
  client: Omit<StartStorefront, 'serverRoute' | 'revalidateRoute'>,
): StartRouteHandler {
  return async ({ request }) => {
    try {
      const rpc = method === 'GET' ? await readGetRpc(request) : await readPostRpc(request)
      if (method === 'GET' && MUTATION_OPERATIONS.has(rpc.op)) {
        throw new RpcProtocolError('Mutations must use POST')
      }
      const data = await executeRpc(client, rpc)
      return Response.json({ data: data ?? null }, { headers: { 'Cache-Control': 'no-store' } })
    } catch (error) {
      return errorResponse(error)
    }
  }
}

function environmentValue(name: string): string | undefined {
  const processLike = (globalThis as { process?: { env?: Record<string, string | undefined> } }).process
  return processLike?.env?.[name]
}

function timingSafeEqual(left: string, right: string): boolean {
  const leftBytes = new TextEncoder().encode(left)
  const rightBytes = new TextEncoder().encode(right)
  let difference = leftBytes.length ^ rightBytes.length
  const length = Math.max(leftBytes.length, rightBytes.length)
  for (let index = 0; index < length; index += 1) {
    difference |= (leftBytes[index] ?? 0) ^ (rightBytes[index] ?? 0)
  }
  return difference === 0
}

function asPositiveInteger(value: unknown): number | null {
  const numeric = typeof value === 'string' && value !== '' ? Number(value) : value
  return typeof numeric === 'number' && Number.isInteger(numeric) && numeric > 0 ? numeric : null
}

function stringList(value: unknown): string[] {
  if (typeof value === 'string') return [value]
  return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : []
}

function webhookTags(value: unknown): string[] {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    throw new TypeError('Webhook payload must be a JSON object')
  }

  const payload = value as Record<string, unknown>
  const productIds = [
    ...(Array.isArray(payload.productIds) ? payload.productIds : []),
    ...(Array.isArray(payload.product_ids) ? payload.product_ids : []),
    payload.productId,
    payload.product_id,
  ]
    .map(asPositiveInteger)
    .filter((id): id is number => id !== null)
  const scopes = [...stringList(payload.scopes), ...stringList(payload.scope)]
    .map((scope) => scope.toLowerCase())
  const productScopes = new Set(['catalog', 'product', 'products', 'price', 'stock'])
  const tags = new Set<string>()

  if (productIds.length > 0 || scopes.some((scope) => productScopes.has(scope))) tags.add('products')
  for (const id of productIds) tags.add(`product-${id}`)
  if (scopes.includes('settings')) tags.add('settings')
  return [...tags]
}

function revalidationHandler(
  cache: TaggedCache,
  secret: string | undefined,
  headerName: string,
): StartRouteHandler {
  return async ({ request }) => {
    if (request.method !== 'POST') {
      return errorResponse(new RpcProtocolError('Method not allowed', 405, 'method_not_allowed'))
    }

    if (!secret) {
      return errorResponse(
        new RpcProtocolError('Revalidation secret is not configured', 500, 'missing_revalidate_secret'),
      )
    }

    const suppliedSecret = request.headers.get(headerName)
    if (!suppliedSecret || !timingSafeEqual(suppliedSecret, secret)) {
      return errorResponse(new RpcProtocolError('Invalid webhook secret', 401, 'unauthorized'))
    }

    let tags: string[]
    try {
      tags = webhookTags(await request.json())
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Invalid webhook payload'
      return errorResponse(new RpcProtocolError(message, 400, 'invalid_webhook_payload'))
    }

    for (const tag of tags) cache.invalidate(tag)
    return Response.json(
      { revalidated: true, tags },
      { headers: { 'Cache-Control': 'no-store' } },
    )
  }
}

/**
 * Creates the TanStack Start adapter. Mount `serverRoute` as the `server`
 * property of a `/api/store/$` file route; mount `revalidateRoute` as the POST
 * handler of the webhook route used by the Woo feature plugin.
 */
export function createStorefront(config: StartStorefrontConfig): StartStorefront {
  const secret = config.sessionSecret ?? environmentValue('WOO_SESSION_SECRET')
  if (!secret) {
    throw new Error('Set config.sessionSecret or WOO_SESSION_SECRET before creating a storefront')
  }

  const cache = config.cache ?? new TaggedCache()
  const createSession = (): SessionStore => new StartCookieSessionStore(secret)
  const createClient: ClientFactory = (session) => createStorefrontClient(config, session)
  const template = createClient(createSession())
  const client = bindClient(template, createSession, createClient, cache)

  const GET = rpcHandler('GET', client)
  const POST = rpcHandler('POST', client)
  return {
    ...client,
    serverRoute: { handlers: { GET, POST } },
    revalidateRoute: revalidationHandler(
      cache,
      config.revalidateSecret ?? environmentValue('WOO_REVALIDATE_SECRET'),
      config.revalidateSecretHeader ?? DEFAULT_REVALIDATE_SECRET_HEADER,
    ),
  }
}
