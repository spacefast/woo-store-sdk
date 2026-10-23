import {
  NotFoundError,
  StoreApiError,
  type Product,
  type ProductListParams,
} from '@woo/storefront-core'

/** Where the adapter reads products from. Decided once per page by the host. */
export type CommerceSource = 'store' | 'preview'

/** `/_spacefast/catalog.json`, emitted by the Spacefast build (schemaVersion 1). */
export interface PreviewCatalog {
  schemaVersion: 1
  currency: string
  products: PreviewCatalogProduct[]
}

export interface PreviewCatalogProduct {
  key: string
  name: string
  summary: string | null
  description: string | null
  price: { amountMinor: number; currency: string }
  image: string | null
  page: string | null
  kind: 'digital' | 'physical'
  active: boolean
}

/**
 * Reads the engine's `/__spacefast/commerce.json`. Anything but an explicit
 * `{ "source": "preview" }` (a 404 on a plain WordPress host, a network error,
 * a malformed body) means the live store.
 */
export async function detectCommerceSource(fetchImpl: typeof fetch, url: string): Promise<CommerceSource> {
  try {
    const response = await fetchImpl(url, {
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
    if (!response.ok) return 'store'
    const body: unknown = await response.json()
    return typeof body === 'object' && body !== null && (body as { source?: unknown }).source === 'preview'
      ? 'preview'
      : 'store'
  } catch {
    return 'store'
  }
}

function isCatalog(value: unknown): value is PreviewCatalog {
  if (typeof value !== 'object' || value === null) return false
  const record = value as Record<string, unknown>
  return record.schemaVersion === 1 && typeof record.currency === 'string' && Array.isArray(record.products)
}

export async function loadPreviewCatalog(fetchImpl: typeof fetch, url: string): Promise<PreviewCatalog> {
  const response = await fetchImpl(url, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })
  if (!response.ok) {
    throw new StoreApiError(
      `The preview catalog at ${url} returned ${response.status}.`,
      response.status,
      'preview_catalog_unavailable',
    )
  }
  const body: unknown = await response.json().catch(() => null)
  if (!isCatalog(body)) {
    throw new StoreApiError(
      `The preview catalog at ${url} is not a schemaVersion 1 catalog.`,
      502,
      'preview_catalog_invalid',
    )
  }
  return body
}

function minorUnits(currency: string): number {
  try {
    return new Intl.NumberFormat('en', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2
  } catch {
    return 2
  }
}

function escapeHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

/** Plain text → the paragraph HTML Woo stores, so components render both sources the same way. */
function paragraphs(text: string | null): string {
  if (!text) return ''
  return text
    .split(/\n\s*\n/u)
    .map((paragraph) => paragraph.trim())
    .filter(Boolean)
    .map((paragraph) => `<p>${escapeHtml(paragraph)}</p>`)
    .join('\n')
}

/**
 * The catalog has no Woo IDs, so the 1-based declaration position stands in.
 * Stable for a given build; only ever used inside the preview.
 */
function toProduct(entry: PreviewCatalogProduct, index: number): Product {
  const currency = entry.price.currency.toUpperCase()
  const image = entry.image
    ? [{ id: 0, src: entry.image, thumbnail: entry.image, alt: entry.name }]
    : []
  const price = String(entry.price.amountMinor)
  return {
    id: index + 1,
    name: entry.name,
    slug: entry.key,
    permalink: entry.page ?? '',
    description: paragraphs(entry.description),
    shortDescription: paragraphs(entry.summary),
    sku: entry.key,
    prices: {
      price,
      regularPrice: price,
      salePrice: null,
      currencyCode: currency,
      currencyMinorUnit: minorUnits(currency),
    },
    images: image,
    categories: [],
    isInStock: entry.active,
    isOnSale: false,
    averageRating: '0',
    reviewCount: 0,
    raw: entry,
  }
}

/** Store API reads answered from the preview catalog. Inactive products are hidden, as in the store. */
export class PreviewCatalogReader {
  private readonly products: Product[]

  constructor(catalog: PreviewCatalog) {
    this.products = catalog.products
      .map(toProduct)
      .filter((product) => (product.raw as PreviewCatalogProduct).active)
  }

  list(params: ProductListParams = {}): { items: Product[]; total: number; totalPages: number } {
    const search = params.search?.trim().toLowerCase()
    const matches = search
      ? this.products.filter((product) =>
          `${product.name} ${product.shortDescription}`.toLowerCase().includes(search))
      : this.products
    const perPage = Math.max(1, params.perPage ?? 10)
    const page = Math.max(1, params.page ?? 1)
    return {
      items: matches.slice((page - 1) * perPage, page * perPage),
      total: matches.length,
      totalPages: Math.ceil(matches.length / perPage),
    }
  }

  byKey(key: string): Product {
    return this.find((product) => product.sku === key, `key "${key}"`)
  }

  bySlug(slug: string): Product {
    return this.find((product) => product.slug === slug, `slug "${slug}"`)
  }

  byId(id: number): Product {
    return this.find((product) => product.id === id, `ID ${id}`)
  }

  private find(predicate: (product: Product) => boolean, label: string): Product {
    const product = this.products.find(predicate)
    if (!product) {
      throw new NotFoundError(`Product with ${label} was not found`, 404, 'product_not_found')
    }
    return product
  }
}
