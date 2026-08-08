import type { Product, ProductListParams, SessionStore, StorefrontClient, Transport } from '../contracts'
import { NotFoundError } from '../errors'
import { compactQuery, queryKey, STORE_API } from './shared'

function productQuery(params: ProductListParams): Record<string, string | number | boolean | undefined> {
  return compactQuery({
    page: params.page,
    per_page: params.perPage,
    search: params.search,
    category: params.category,
    orderby: params.orderby,
    order: params.order,
    on_sale: params.onSale,
    min_price: params.minPrice,
    max_price: params.maxPrice,
  })
}

export function createProductsResource(
  transport: Transport,
  session: SessionStore,
): StorefrontClient['products'] {
  return {
    list(params?: ProductListParams) {
      return {
        queryKey: queryKey('products', 'list', params),
        profile: 'catalog',
        queryFn: async () => {
          const response = await transport.request<Product[]>({
            method: 'GET',
            path: `${STORE_API}/products`,
            ...(params === undefined ? {} : { query: productQuery(params) }),
            profile: 'catalog',
          }, session)
          return {
            items: response.data,
            total: response.total ?? response.data.length,
            totalPages: response.totalPages ?? (response.data.length === 0 ? 0 : 1),
          }
        },
      }
    },

    bySlug(slug: string) {
      return {
        queryKey: queryKey('products', 'bySlug', { slug }),
        profile: 'catalog',
        queryFn: async () => {
          const response = await transport.request<Product[]>({
            method: 'GET',
            path: `${STORE_API}/products`,
            query: { slug },
            profile: 'catalog',
          }, session)
          const product = response.data[0]
          if (!product) {
            throw new NotFoundError(`Product with slug "${slug}" was not found`, 404, 'product_not_found')
          }
          return product
        },
      }
    },

    byId(id: number) {
      return {
        queryKey: queryKey('products', 'byId', { id }),
        profile: 'catalog',
        queryFn: async () => {
          const response = await transport.request<Product>({
            method: 'GET',
            path: `${STORE_API}/products/${id}`,
            profile: 'catalog',
          }, session)
          return response.data
        },
      }
    },
  }
}
