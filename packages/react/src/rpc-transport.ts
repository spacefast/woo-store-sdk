import {
  AuthExpiredError,
  CartConflictError,
  NotFoundError,
  RateLimitedError,
  RPC_BASE_PATH,
  StoreApiError,
  type Cart,
  type RpcRequest,
} from '@woo/storefront-core'

export interface RpcTransportOptions {
  basePath?: string
  fetch?: typeof fetch
}

interface RpcErrorPayload {
  code?: unknown
  status?: unknown
  message?: unknown
  refreshedCart?: unknown
  retryAfterSeconds?: unknown
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function defineReadonly<T extends object, K extends PropertyKey>(
  target: T,
  key: K,
  value: unknown,
): void {
  Object.defineProperty(target, key, {
    configurable: true,
    enumerable: true,
    value,
  })
}

function asRetryAfter(value: unknown, headerValue?: string | null): number | undefined {
  const candidate = typeof value === 'number'
    ? value
    : headerValue === undefined || headerValue === null || headerValue.trim() === ''
      ? Number.NaN
      : Number(headerValue)
  return Number.isFinite(candidate) && candidate >= 0 ? candidate : undefined
}

function typedError<TError extends StoreApiError>(
  prototype: object,
  name: string,
  message: string,
  status: number,
  code: string,
): TError {
  const error = new StoreApiError(message, status, code)
  Object.setPrototypeOf(error, prototype)
  error.name = name
  return error as TError
}

/** Reconstructs the public core error hierarchy from the JSON RPC envelope. */
export function deserializeRpcResponse<T>(
  payload: unknown,
  httpStatus = 200,
  retryAfterHeader?: string | null,
): T {
  if (!isRecord(payload)) {
    throw new StoreApiError('The storefront RPC returned an invalid response.', httpStatus, 'invalid_rpc_response')
  }

  if (Object.prototype.hasOwnProperty.call(payload, 'error')) {
    const rawError = isRecord(payload.error) ? payload.error as RpcErrorPayload : {}
    const status = typeof rawError.status === 'number' ? rawError.status : httpStatus
    const code = typeof rawError.code === 'string' ? rawError.code : 'store_api_error'
    const message = typeof rawError.message === 'string'
      ? rawError.message
      : `The storefront RPC failed with status ${status}.`

    if (status === 409 && rawError.refreshedCart !== undefined) {
      const error = typedError<CartConflictError>(
        CartConflictError.prototype,
        'CartConflictError',
        message,
        status,
        code,
      )
      defineReadonly(error, 'refreshedCart', rawError.refreshedCart as Cart)
      throw error
    }

    if (status === 404) {
      throw typedError<NotFoundError>(NotFoundError.prototype, 'NotFoundError', message, status, code)
    }

    if (status === 401 || status === 403) {
      throw typedError<AuthExpiredError>(AuthExpiredError.prototype, 'AuthExpiredError', message, status, code)
    }

    if (status === 429) {
      const error = typedError<RateLimitedError>(
        RateLimitedError.prototype,
        'RateLimitedError',
        message,
        status,
        code,
      )
      const retryAfterSeconds = asRetryAfter(rawError.retryAfterSeconds, retryAfterHeader)
      if (retryAfterSeconds !== undefined) {
        defineReadonly(error, 'retryAfterSeconds', retryAfterSeconds)
      }
      throw error
    }

    throw new StoreApiError(message, status, code)
  }

  if (!Object.prototype.hasOwnProperty.call(payload, 'data')) {
    throw new StoreApiError('The storefront RPC returned an invalid response.', httpStatus, 'invalid_rpc_response')
  }

  return payload.data as T
}

function normalizeRequest(requestOrOperation: RpcRequest | string, args?: unknown): RpcRequest {
  return typeof requestOrOperation === 'string'
    ? { op: requestOrOperation, ...(args === undefined ? {} : { args }) }
    : requestOrOperation
}

function normalizeBasePath(basePath: string): string {
  return basePath.endsWith('/') ? basePath.slice(0, -1) : basePath
}

/** Browser transport for the adapter-owned `/api/store` RPC protocol. */
export class RpcTransport {
  readonly basePath: string
  private readonly fetchImpl: typeof fetch

  constructor(options?: RpcTransportOptions | string, fetchImpl?: typeof fetch) {
    const resolvedOptions = typeof options === 'string'
      ? { basePath: options, fetch: fetchImpl }
      : options ?? {}

    this.basePath = normalizeBasePath(resolvedOptions.basePath ?? RPC_BASE_PATH)
    this.fetchImpl = resolvedOptions.fetch ?? globalThis.fetch
  }

  query<T>(request: RpcRequest): Promise<T>
  query<T>(operation: string, args?: unknown): Promise<T>
  async query<T>(requestOrOperation: RpcRequest | string, args?: unknown): Promise<T> {
    const request = normalizeRequest(requestOrOperation, args)
    const search = new URLSearchParams({ op: request.op })
    if (request.args !== undefined) {
      search.set('args', JSON.stringify(request.args))
    }

    return this.send<T>(`${this.basePath}/query?${search.toString()}`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
  }

  mutate<T>(request: RpcRequest): Promise<T>
  mutate<T>(operation: string, args?: unknown): Promise<T>
  async mutate<T>(requestOrOperation: RpcRequest | string, args?: unknown): Promise<T> {
    const request = normalizeRequest(requestOrOperation, args)
    return this.send<T>(`${this.basePath}/rpc`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(request),
    })
  }

  /** Alias for consumers that prefer the protocol term over `mutate`. */
  mutation<T>(request: RpcRequest): Promise<T>
  mutation<T>(operation: string, args?: unknown): Promise<T>
  mutation<T>(requestOrOperation: RpcRequest | string, args?: unknown): Promise<T> {
    if (typeof requestOrOperation === 'string') {
      return this.mutate<T>(requestOrOperation, args)
    }
    return this.mutate<T>(requestOrOperation)
  }

  private async send<T>(input: string, init: RequestInit): Promise<T> {
    const response = await this.fetchImpl(input, init)
    let payload: unknown

    try {
      payload = await response.json()
    } catch {
      throw new StoreApiError(
        'The storefront RPC returned a non-JSON response.',
        response.status,
        'invalid_rpc_response',
      )
    }

    return deserializeRpcResponse<T>(payload, response.status, response.headers.get('Retry-After'))
  }
}
