import type { SessionStore, StorefrontClient, Transport } from '../contracts'
import { addressPayload, queryKey, STORE_API } from './shared'

export function createCheckoutResource(
  transport: Transport,
  session: SessionStore,
): StorefrontClient['checkout'] {
  return {
    get() {
      return {
        queryKey: queryKey('checkout', 'get'),
        profile: 'session',
        queryFn: async () => {
          const response = await transport.request<unknown>({
            method: 'GET',
            path: `${STORE_API}/checkout`,
            profile: 'session',
          }, session)
          return response.data
        },
      }
    },

    submit: {
      mutationKey: queryKey('checkout', 'submit'),
      invalidates: [
        { type: 'resource', resource: 'cart' },
        { type: 'resource', resource: 'orders' },
      ],
      mutationFn: async (variables) => {
        // Same bootstrap as cart mutations: checkout needs a Cart-Token,
        // which a fresh headless session only gets from a cart response.
        const { cartToken } = await session.read()
        if (!cartToken) {
          await transport.request<unknown>({ method: 'GET', path: `${STORE_API}/cart`, profile: 'session' }, session)
        }
        const response = await transport.request<unknown>({
          method: 'POST',
          path: `${STORE_API}/checkout`,
          body: {
            billing_address: addressPayload(variables.billingAddress),
            ...(variables.shippingAddress === undefined
              ? {}
              : { shipping_address: addressPayload(variables.shippingAddress) }),
            payment_method: variables.paymentMethod,
            ...(variables.paymentData === undefined
              ? {}
              : {
                  payment_data: Object.entries(variables.paymentData).map(([key, value]) => ({ key, value })),
                }),
            ...(variables.expectedTotal === undefined ? {} : { expected_total: variables.expectedTotal }),
          },
          profile: 'session',
        }, session)
        return response.data
      },
    },
  }
}
