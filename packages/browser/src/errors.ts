import { NotFoundError, StoreApiError } from '@woo/storefront-core'

/** The browser adapter does not implement this operation. See the package README for the supported set. */
export class UnsupportedOperationError extends StoreApiError {
  readonly operation: string

  constructor(operation: string) {
    super(
      `"${operation}" is not supported by the browser adapter. Use a server adapter (@woo/storefront-next or -start) for it.`,
      501,
      'unsupported_operation',
    )
    this.name = 'UnsupportedOperationError'
    this.operation = operation
  }
}

/**
 * The page is served from a preview host: products come from the build's
 * preview catalog and every cart operation is refused. Components render this
 * as "Checkout is off in previews".
 */
export class CheckoutOffInPreviewError extends StoreApiError {
  constructor() {
    super('Checkout is off in previews.', 409, 'checkout_off_in_preview')
    this.name = 'CheckoutOffInPreviewError'
  }
}

export function isCheckoutOffInPreview(error: unknown): error is CheckoutOffInPreviewError {
  return error instanceof CheckoutOffInPreviewError
}

/**
 * TanStack Query `retry` for browser-adapter queries: retries transient
 * failures, never answers that cannot change (preview, unsupported, missing).
 */
export function retryStoreQuery(failureCount: number, error: unknown): boolean {
  if (
    error instanceof CheckoutOffInPreviewError ||
    error instanceof UnsupportedOperationError ||
    error instanceof NotFoundError
  ) {
    return false
  }
  return failureCount < 3
}
