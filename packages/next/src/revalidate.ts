import { revalidateTag } from 'next/cache'

export const DEFAULT_REVALIDATE_SECRET_HEADER = 'x-woo-webhook-secret'
export const WOO_STOREFRONT_SIGNATURE_HEADER = 'x-woo-storefront-signature'

export interface RevalidateHandlerOptions {
  secret?: string
  headerName?: string
}

interface RevalidationPayload {
  productIds: number[]
  scopes: string[]
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

async function hmacSignature(body: string, secret: string): Promise<string> {
  const encoder = new TextEncoder()
  const key = await crypto.subtle.importKey(
    'raw',
    encoder.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  )
  const signature = await crypto.subtle.sign('HMAC', key, encoder.encode(body))
  return `sha256=${[...new Uint8Array(signature)].map((byte) => byte.toString(16).padStart(2, '0')).join('')}`
}

function asPositiveInteger(value: unknown): number | null {
  const number = typeof value === 'string' && value !== '' ? Number(value) : value
  return typeof number === 'number' && Number.isInteger(number) && number > 0 ? number : null
}

function strings(value: unknown): string[] {
  if (typeof value === 'string') return [value]
  if (!Array.isArray(value)) return []
  return value.filter((item): item is string => typeof item === 'string')
}

function parsePayload(value: unknown): RevalidationPayload {
  if (typeof value !== 'object' || value === null) {
    throw new TypeError('Webhook payload must be a JSON object')
  }

  const payload = value as Record<string, unknown>
  const ids = [
    ...(Array.isArray(payload.productIds) ? payload.productIds : []),
    ...(Array.isArray(payload.product_ids) ? payload.product_ids : []),
    payload.productId,
    payload.product_id,
  ]
    .map(asPositiveInteger)
    .filter((id): id is number => id !== null)

  return {
    productIds: [...new Set(ids)],
    scopes: [...new Set([...strings(payload.scopes), ...strings(payload.scope)].map((scope) => scope.toLowerCase()))],
  }
}

function tagsForPayload(payload: RevalidationPayload): string[] {
  const tags = new Set<string>()
  const productScopes = new Set(['catalog', 'product', 'products', 'price', 'stock'])

  if (payload.productIds.length > 0 || payload.scopes.some((scope) => productScopes.has(scope))) {
    tags.add('products')
  }
  for (const id of payload.productIds) tags.add(`product-${id}`)
  if (payload.scopes.includes('settings')) tags.add('settings')

  return [...tags]
}

export type RevalidateRouteHandler = (request: Request) => Promise<Response>

export function createRevalidateHandler(
  options: RevalidateHandlerOptions | string = {},
): RevalidateRouteHandler {
  const normalizedOptions = typeof options === 'string' ? { secret: options } : options
  const secret = normalizedOptions.secret ?? environmentValue('WOO_REVALIDATE_SECRET')
  const headerName = normalizedOptions.headerName ?? DEFAULT_REVALIDATE_SECRET_HEADER

  return async (request) => {
    if (!secret) {
      return Response.json(
        { error: { code: 'missing_revalidate_secret', status: 500, message: 'Revalidation secret is not configured' } },
        { status: 500 },
      )
    }

    const body = await request.text()
    const suppliedSecret = request.headers.get(headerName)
    const suppliedSignature = request.headers.get(WOO_STOREFRONT_SIGNATURE_HEADER)
    const hasRawSecret = suppliedSecret !== null && timingSafeEqual(suppliedSecret, secret)
    const hasValidSignature = suppliedSignature !== null
      && timingSafeEqual(suppliedSignature, await hmacSignature(body, secret))
    if (!hasRawSecret && !hasValidSignature) {
      return Response.json(
        { error: { code: 'unauthorized', status: 401, message: 'Invalid webhook secret' } },
        { status: 401 },
      )
    }

    let payload: RevalidationPayload
    try {
      payload = parsePayload(JSON.parse(body) as unknown)
    } catch (error) {
      return Response.json(
        {
          error: {
            code: 'invalid_webhook_payload',
            status: 400,
            message: error instanceof Error ? error.message : 'Invalid webhook payload',
          },
        },
        { status: 400 },
      )
    }

    const tags = tagsForPayload(payload)
    await Promise.all(tags.map(async (tag) => revalidateTag(tag, 'max')))
    return Response.json({ revalidated: true, tags })
  }
}
