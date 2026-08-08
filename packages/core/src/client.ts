import type { SessionStore, StorefrontClient, StorefrontConfig, Transport } from './contracts'
import { createAuthResource } from './resources/auth'
import { createCartResource } from './resources/cart'
import { createCategoriesResource } from './resources/categories'
import { createCheckoutResource } from './resources/checkout'
import { createCustomerResource } from './resources/customer'
import { createProductsResource } from './resources/products'
import { FetchTransport } from './transport'

export function createStorefrontClient(
  config: StorefrontConfig,
  session: SessionStore,
  transport: Transport = new FetchTransport(config),
): StorefrontClient {
  return {
    products: createProductsResource(transport, session),
    categories: createCategoriesResource(transport, session),
    cart: createCartResource(transport, session),
    checkout: createCheckoutResource(transport, session),
    auth: createAuthResource(transport, session),
    customer: createCustomerResource(transport, session),
  }
}
