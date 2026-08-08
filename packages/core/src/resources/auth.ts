import type { Customer, SessionData, SessionStore, StorefrontClient, Transport } from '../contracts'
import { StoreApiError } from '../errors'
import { PLUGIN_API, queryKey } from './shared'

interface AuthResponse {
  customer?: Customer
  customerToken?: string
  accessToken?: string
  cartToken?: string
  token?: string
  jwt?: string
  id?: number
  email?: string
  firstName?: string
  lastName?: string
}

function customerFrom(response: AuthResponse): Customer {
  if (response.customer) return response.customer
  if (typeof response.id === 'number' && typeof response.email === 'string') {
    return response as Customer
  }
  throw new StoreApiError('Authentication response did not include a customer', 502, 'invalid_auth_response')
}

async function persistAuthSession(
  response: AuthResponse,
  responseCartToken: string | undefined,
  session: SessionStore,
): Promise<void> {
  const current = await session.read()
  const customerToken = response.customerToken ?? response.accessToken ?? response.token ?? response.jwt
  const cartToken = responseCartToken ?? response.cartToken ?? current.cartToken
  const next: SessionData = {
    ...current,
    ...(cartToken === undefined ? {} : { cartToken }),
    ...(customerToken === undefined ? {} : { customerToken }),
  }
  await session.write(next)
}

export function createAuthResource(transport: Transport, session: SessionStore): StorefrontClient['auth'] {
  return {
    login: {
      mutationKey: queryKey('auth', 'login'),
      invalidates: [{ type: 'resource', resource: 'session-all' }],
      mutationFn: async (variables) => {
        const response = await transport.request<AuthResponse>({
          method: 'POST',
          path: `${PLUGIN_API}/auth/login`,
          body: variables,
          profile: 'session',
        }, session)
        await persistAuthSession(response.data, response.cartToken, session)
        return customerFrom(response.data)
      },
    },

    logout: {
      mutationKey: queryKey('auth', 'logout'),
      invalidates: [{ type: 'resource', resource: 'session-all' }],
      mutationFn: async () => {
        await transport.request<unknown>({
          method: 'POST',
          path: `${PLUGIN_API}/auth/logout`,
          profile: 'session',
        }, session)
        const current = await session.read()
        const { customerToken: _discarded, ...guestSession } = current
        await session.write(guestSession)
      },
    },

    register: {
      mutationKey: queryKey('auth', 'register'),
      invalidates: [{ type: 'resource', resource: 'session-all' }],
      mutationFn: async (variables) => {
        const response = await transport.request<AuthResponse>({
          method: 'POST',
          path: `${PLUGIN_API}/auth/register`,
          body: {
            email: variables.email,
            password: variables.password,
            ...(variables.firstName === undefined ? {} : { first_name: variables.firstName }),
            ...(variables.lastName === undefined ? {} : { last_name: variables.lastName }),
          },
          profile: 'session',
        }, session)
        await persistAuthSession(response.data, response.cartToken, session)
        return customerFrom(response.data)
      },
    },
  }
}
