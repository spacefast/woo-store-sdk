import type { Category, SessionStore, StorefrontClient, Transport } from '../contracts'
import { NotFoundError } from '../errors'
import { queryKey, STORE_API } from './shared'

export function createCategoriesResource(
  transport: Transport,
  session: SessionStore,
): StorefrontClient['categories'] {
  return {
    list() {
      return {
        queryKey: queryKey('categories', 'list'),
        profile: 'catalog',
        queryFn: async () => {
          const response = await transport.request<Category[]>({
            method: 'GET',
            path: `${STORE_API}/products/categories`,
            profile: 'catalog',
          }, session)
          return response.data
        },
      }
    },

    bySlug(slug: string) {
      return {
        queryKey: queryKey('categories', 'bySlug', { slug }),
        profile: 'catalog',
        queryFn: async () => {
          const response = await transport.request<Category[]>({
            method: 'GET',
            path: `${STORE_API}/products/categories`,
            query: { slug },
            profile: 'catalog',
          }, session)
          const category = response.data[0]
          if (!category) {
            throw new NotFoundError(
              `Category with slug "${slug}" was not found`,
              404,
              'category_not_found',
            )
          }
          return category
        },
      }
    },
  }
}
