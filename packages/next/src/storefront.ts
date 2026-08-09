import {
  createStorefrontClient,
  type SessionStore,
  type StorefrontAdapter,
  type StorefrontClient,
  type StorefrontConfig,
  type WooMutationOptions,
  type WooQueryOptions,
} from '@woo/storefront-core'

import { eagerQuery, executeServerQuery, invalidateTargets, type EagerWooQueryOptions } from './cache'
import { createRevalidateHandler, type RevalidateHandlerOptions, type RevalidateRouteHandler } from './revalidate'
import { createRpcHandlers } from './rpc'
import { CookieSessionStore } from './session'

type EagerQueryFactory<TFactory> = TFactory extends (...args: infer TArgs) => WooQueryOptions<infer TData>
  ? (...args: TArgs) => EagerWooQueryOptions<TData>
  : never

type EagerQueryOrOriginal<TValue> = TValue extends (...args: infer TArgs) => WooQueryOptions<infer TData>
  ? (...args: TArgs) => EagerWooQueryOptions<TData>
  : TValue

type EagerProductSurface = {
  [TKey in keyof StorefrontClient['products']]: EagerQueryFactory<StorefrontClient['products'][TKey]>
}

type EagerCategorySurface = {
  [TKey in keyof StorefrontClient['categories']]: EagerQueryFactory<StorefrontClient['categories'][TKey]>
}

type EagerCustomerSurface = {
  [TKey in keyof StorefrontClient['customer']]: EagerQueryOrOriginal<StorefrontClient['customer'][TKey]>
}

export interface NextStorefront extends StorefrontAdapter {
  products: EagerProductSurface
  categories: EagerCategorySurface
  cart: Omit<StorefrontClient['cart'], 'get'> & { get: EagerQueryFactory<StorefrontClient['cart']['get']> }
  checkout: Omit<StorefrontClient['checkout'], 'get'> & { get: EagerQueryFactory<StorefrontClient['checkout']['get']> }
  customer: EagerCustomerSurface
  handlers: {
    GET(request: Request): Promise<Response>
    POST(request: Request): Promise<Response>
  }
  revalidateHandler(options?: RevalidateHandlerOptions | string): RevalidateRouteHandler
}

function environmentValue(name: string): string | undefined {
  const processLike = (globalThis as { process?: { env?: Record<string, string | undefined> } }).process
  return processLike?.env?.[name]
}

function sessionSecret(config: StorefrontConfig): string {
  const secret = config.sessionSecret ?? environmentValue('WOO_SESSION_SECRET')
  if (!secret) {
    throw new Error('Set config.sessionSecret or WOO_SESSION_SECRET before creating a storefront')
  }
  return secret
}

type ClientFactory = (session: SessionStore) => StorefrontClient

function wrapQueryFactory<TFactory>(
  origin: string,
  createSession: () => SessionStore,
  createClient: ClientFactory,
  select: (client: StorefrontClient) => TFactory,
): EagerQueryFactory<TFactory> {
  return ((...args: unknown[]) => {
    const session = createSession()
    const factory = select(createClient(session)) as unknown as (...factoryArgs: unknown[]) => WooQueryOptions<unknown>
    const options = factory(...args)
    const execution = session
      .read()
      .then((data) => executeServerQuery(options, data, origin))
    return eagerQuery(options, execution)
  }) as EagerQueryFactory<TFactory>
}

function wrapMutation<TData, TVariables>(
  template: WooMutationOptions<TData, TVariables>,
  createSession: () => SessionStore,
  select: (client: StorefrontClient) => WooMutationOptions<TData, TVariables>,
  createClient: ClientFactory,
): WooMutationOptions<TData, TVariables> {
  return {
    ...template,
    async mutationFn(variables) {
      const session = createSession()
      const sessionBeforeMutation = await session.read()
      const result = await select(createClient(session)).mutationFn(variables)
      await invalidateTargets(template.invalidates, sessionBeforeMutation)
      return result
    },
  }
}

export function createStorefront(config: StorefrontConfig): NextStorefront {
  const secret = sessionSecret(config)
  const createSession = (): SessionStore => new CookieSessionStore(secret)
  const createClient: ClientFactory = (session) => createStorefrontClient(config, session)

  // Only metadata and optimistic reducers are copied from this client. Its
  // request-scoped session is never used to execute transport operations.
  const template = createClient(createSession())

  const products: EagerProductSurface = {
    list: wrapQueryFactory(config.url, createSession, createClient, (client) => client.products.list),
    bySlug: wrapQueryFactory(config.url, createSession, createClient, (client) => client.products.bySlug),
    byId: wrapQueryFactory(config.url, createSession, createClient, (client) => client.products.byId),
  }

  const categories: EagerCategorySurface = {
    list: wrapQueryFactory(config.url, createSession, createClient, (client) => client.categories.list),
    bySlug: wrapQueryFactory(config.url, createSession, createClient, (client) => client.categories.bySlug),
  }

  const cart: NextStorefront['cart'] = {
    get: wrapQueryFactory(config.url, createSession, createClient, (client) => client.cart.get),
    addItem: wrapMutation(template.cart.addItem, createSession, (client) => client.cart.addItem, createClient),
    updateItem: wrapMutation(template.cart.updateItem, createSession, (client) => client.cart.updateItem, createClient),
    removeItem: wrapMutation(template.cart.removeItem, createSession, (client) => client.cart.removeItem, createClient),
    applyCoupon: wrapMutation(template.cart.applyCoupon, createSession, (client) => client.cart.applyCoupon, createClient),
    removeCoupon: wrapMutation(template.cart.removeCoupon, createSession, (client) => client.cart.removeCoupon, createClient),
    async checkoutUrl() {
      return createClient(createSession()).cart.checkoutUrl()
    },
  }

  const checkout: NextStorefront['checkout'] = {
    get: wrapQueryFactory(config.url, createSession, createClient, (client) => client.checkout.get),
    submit: wrapMutation(template.checkout.submit, createSession, (client) => client.checkout.submit, createClient),
  }

  const auth: StorefrontClient['auth'] = {
    login: wrapMutation(template.auth.login, createSession, (client) => client.auth.login, createClient),
    logout: wrapMutation(template.auth.logout, createSession, (client) => client.auth.logout, createClient),
    register: wrapMutation(template.auth.register, createSession, (client) => client.auth.register, createClient),
  }

  const customer: NextStorefront['customer'] = {
    get: wrapQueryFactory(config.url, createSession, createClient, (client) => client.customer.get),
    orders: wrapQueryFactory(config.url, createSession, createClient, (client) => client.customer.orders),
    order: wrapQueryFactory(config.url, createSession, createClient, (client) => client.customer.order),
    updateAddress: wrapMutation(
      template.customer.updateAddress,
      createSession,
      (client) => client.customer.updateAddress,
      createClient,
    ),
  }

  return {
    products,
    categories,
    cart,
    checkout,
    auth,
    customer,
    handlers: createRpcHandlers(createClient, createSession),
    revalidateHandler: createRevalidateHandler,
  }
}
