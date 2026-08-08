import type { Cart } from './contracts'

export class StoreApiError extends Error {
  readonly status: number
  readonly code: string

  constructor(message: string, status: number, code: string) {
    super(message)
    this.name = 'StoreApiError'
    this.status = status
    this.code = code
  }
}

export class NotFoundError extends StoreApiError {
  constructor(message: string, _status = 404, code = 'not_found') {
    super(message, 404, code)
    this.name = 'NotFoundError'
  }
}

export class AuthExpiredError extends StoreApiError {
  constructor(message: string, status = 401, code = 'auth_expired') {
    super(message, status, code)
    this.name = 'AuthExpiredError'
  }
}

export class RateLimitedError extends StoreApiError {
  readonly retryAfterSeconds?: number

  constructor(message: string, _status = 429, code = 'rate_limited', retryAfterSeconds?: number) {
    super(message, 429, code)
    this.name = 'RateLimitedError'
    this.retryAfterSeconds = retryAfterSeconds
  }
}

export class CartConflictError extends StoreApiError {
  readonly refreshedCart: Cart

  constructor(message: string, _status: number, code: string, refreshedCart: Cart) {
    super(message, 409, code)
    this.name = 'CartConflictError'
    this.refreshedCart = refreshedCart
  }
}
