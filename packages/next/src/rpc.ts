import {
  CartConflictError,
  StoreApiError,
  type RpcRequest,
  type SessionStore,
  type StorefrontClient,
  type WooMutationOptions,
  type WooQueryOptions,
} from '@woo/storefront-core'

import { invalidateTargets } from './cache'

const ALLOWED_OPERATIONS = new Set([
  'products.list',
  'products.bySlug',
  'products.byId',
  'categories.list',
  'categories.bySlug',
  'cart.get',
  'cart.addItem',
  'cart.updateItem',
  'cart.removeItem',
  'cart.applyCoupon',
  'cart.removeCoupon',
  'cart.checkoutUrl',
  'checkout.get',
  'checkout.submit',
  'auth.login',
  'auth.logout',
  'auth.register',
  'customer.get',
  'customer.orders',
  'customer.order',
  'customer.updateAddress',
])

type RpcHandler = (request: Request) => Promise<Response>

class RpcProtocolError extends Error {
  readonly code = 'invalid_rpc_request'
  readonly status = 400
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function isQueryOptions(value: unknown): value is WooQueryOptions<unknown> {
  return (
    isRecord(value) &&
    Array.isArray(value.queryKey) &&
    typeof value.queryFn === 'function' &&
    typeof value.profile === 'string'
  )
}

function isMutationOptions(value: unknown): value is WooMutationOptions<unknown, unknown> {
  return (
    isRecord(value) &&
    Array.isArray(value.mutationKey) &&
    typeof value.mutationFn === 'function' &&
    Array.isArray(value.invalidates)
  )
}

async function readRpcRequest(request: Request, method: 'GET' | 'POST'): Promise<RpcRequest> {
  if (method === 'GET') {
    const url = new URL(request.url)
    const op = url.searchParams.get('op')
    if (!op) throw new RpcProtocolError('Missing RPC operation')

    const encodedArgs = url.searchParams.get('args')
    if (encodedArgs === null) return { op }

    try {
      return { op, args: JSON.parse(encodedArgs) as unknown }
    } catch {
      throw new RpcProtocolError('RPC args must be valid JSON')
    }
  }

  let body: unknown
  try {
    body = await request.json()
  } catch {
    throw new RpcProtocolError('RPC body must be valid JSON')
  }

  if (!isRecord(body) || typeof body.op !== 'string') {
    throw new RpcProtocolError('RPC body must contain an operation')
  }

  return { op: body.op, ...(Object.hasOwn(body, 'args') ? { args: body.args } : {}) }
}

function operationValue(client: StorefrontClient, op: string): unknown {
  if (!ALLOWED_OPERATIONS.has(op)) {
    throw new RpcProtocolError(`Unknown RPC operation: ${op}`)
  }

  const [resource, operation] = op.split('.')
  const surface = client[resource as keyof StorefrontClient] as unknown
  if (!isRecord(surface) || !operation || !Object.hasOwn(surface, operation)) {
    throw new RpcProtocolError(`Unknown RPC operation: ${op}`)
  }

  return surface[operation]
}

async function dispatch(
  client: StorefrontClient,
  request: RpcRequest,
  session: SessionStore,
  method: 'GET' | 'POST',
): Promise<unknown> {
  const value = operationValue(client, request.op)

  if (isMutationOptions(value)) {
    if (method !== 'POST') {
      throw new RpcProtocolError('Mutations must use POST')
    }
    const sessionBeforeMutation = await session.read()
    const result = await value.mutationFn(request.args)
    await invalidateTargets(value.invalidates, sessionBeforeMutation)
    return result
  }

  if (typeof value !== 'function') {
    throw new RpcProtocolError(`RPC operation is not executable: ${request.op}`)
  }

  const result = Array.isArray(request.args)
    ? (value as (...args: unknown[]) => unknown)(...request.args)
    : request.args === undefined
      ? (value as () => unknown)()
      : (value as (args: unknown) => unknown)(request.args)

  if (isQueryOptions(result)) return result.queryFn()
  return result
}

function errorBody(error: unknown): {
  error: { code: string; status: number; message: string; refreshedCart?: unknown }
} {
  if (error instanceof CartConflictError) {
    return {
      error: {
        code: error.code,
        status: error.status,
        message: error.message,
        refreshedCart: error.refreshedCart,
      },
    }
  }

  if (error instanceof StoreApiError || error instanceof RpcProtocolError) {
    return { error: { code: error.code, status: error.status, message: error.message } }
  }

  return {
    error: {
      code: 'internal_error',
      status: 500,
      message: error instanceof Error ? error.message : 'Unknown server error',
    },
  }
}

function errorStatus(error: unknown): number {
  return error instanceof StoreApiError || error instanceof RpcProtocolError ? error.status : 500
}

export function createRpcHandlers(
  createClient: (session: SessionStore) => StorefrontClient,
  createSession: () => SessionStore,
): { GET: RpcHandler; POST: RpcHandler } {
  const handle = (method: 'GET' | 'POST'): RpcHandler => async (request) => {
    try {
      const rpcRequest = await readRpcRequest(request, method)
      const session = createSession()
      const client = createClient(session)
      const data = await dispatch(client, rpcRequest, session, method)
      return Response.json({ data })
    } catch (error) {
      return Response.json(errorBody(error), { status: errorStatus(error) })
    }
  }

  return { GET: handle('GET'), POST: handle('POST') }
}
