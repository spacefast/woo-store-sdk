import {
  CACHE_PROFILES,
  type Cart,
  type Category,
  type Customer,
  type OrderSummary,
  type Product,
  type ProductListParams,
  type StoreApiError,
  type WooMutationOptions,
  type WooQueryOptions,
} from '@woo/storefront-core'
import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationResult,
  type UseQueryResult,
} from '@tanstack/react-query'

import {
  applyOptimisticCartUpdate,
  invalidateWooQueries,
  rollbackOptimisticCartUpdate,
  type OptimisticCartContext,
} from './cache'
import { useStoreContext } from './provider'

function useRpcQuery<TData>(
  options: WooQueryOptions<TData>,
  operation: string,
  args?: unknown,
): UseQueryResult<TData, StoreApiError> {
  const { transport } = useStoreContext()
  const profile = CACHE_PROFILES[options.profile]
  return useQuery<TData, StoreApiError>({
    queryKey: options.queryKey,
    queryFn: () => transport.query<TData>(operation, args),
    staleTime: profile.staleTime,
    gcTime: profile.gcTime,
  })
}

function useRpcMutation<TData, TVariables>(
  options: WooMutationOptions<TData, TVariables>,
  operation: string,
): UseMutationResult<TData, StoreApiError, TVariables, OptimisticCartContext | undefined> {
  const { transport } = useStoreContext()
  const queryClient = useQueryClient()

  return useMutation<TData, StoreApiError, TVariables, OptimisticCartContext | undefined>({
    mutationKey: options.mutationKey,
    mutationFn: (variables: TVariables) => transport.mutate<TData>(operation, variables),
    onMutate: options.optimisticUpdate
      ? (variables: TVariables) => applyOptimisticCartUpdate(queryClient, options.optimisticUpdate!, variables)
      : undefined,
    onError: (
      _error: StoreApiError,
      _variables: TVariables,
      context: OptimisticCartContext | undefined,
    ) => {
      rollbackOptimisticCartUpdate(queryClient, context)
    },
    onSettled: async () => {
      await invalidateWooQueries(queryClient, options.invalidates)
    },
  })
}

export function useProducts(
  params?: ProductListParams,
): UseQueryResult<{ items: Product[]; total: number; totalPages: number }, StoreApiError> {
  const { factories } = useStoreContext()
  return useRpcQuery(factories.products.list(params), 'products.list', params)
}

export type ProductIdentifier = number | string | { id: number } | { slug: string }

export function useProduct(identifier: ProductIdentifier): UseQueryResult<Product, StoreApiError> {
  const { factories } = useStoreContext()
  const id = typeof identifier === 'number'
    ? identifier
    : typeof identifier === 'object' && 'id' in identifier
      ? identifier.id
      : undefined
  const slug = typeof identifier === 'string'
    ? identifier
    : typeof identifier === 'object' && 'slug' in identifier
      ? identifier.slug
      : undefined
  const options = id === undefined
    ? factories.products.bySlug(slug!)
    : factories.products.byId(id)

  return useRpcQuery(options, id === undefined ? 'products.bySlug' : 'products.byId', id ?? slug)
}

export function useCategories(): UseQueryResult<Category[], StoreApiError> {
  const { factories } = useStoreContext()
  return useRpcQuery(factories.categories.list(), 'categories.list')
}

export function useCart(): UseQueryResult<Cart, StoreApiError> {
  const { factories } = useStoreContext()
  return useRpcQuery(factories.cart.get(), 'cart.get')
}

export function useAddToCart(): UseMutationResult<
  Cart,
  StoreApiError,
  { id: number; quantity?: number; variation?: Record<string, string> },
  OptimisticCartContext | undefined
> {
  const { factories } = useStoreContext()
  return useRpcMutation(factories.cart.addItem, 'cart.addItem')
}

export function useUpdateCartItem(): UseMutationResult<
  Cart,
  StoreApiError,
  { key: string; quantity: number },
  OptimisticCartContext | undefined
> {
  const { factories } = useStoreContext()
  return useRpcMutation(factories.cart.updateItem, 'cart.updateItem')
}

export function useRemoveCartItem(): UseMutationResult<
  Cart,
  StoreApiError,
  { key: string },
  OptimisticCartContext | undefined
> {
  const { factories } = useStoreContext()
  return useRpcMutation(factories.cart.removeItem, 'cart.removeItem')
}

export function useApplyCoupon(): UseMutationResult<
  Cart,
  StoreApiError,
  { code: string },
  OptimisticCartContext | undefined
> {
  const { factories } = useStoreContext()
  return useRpcMutation(factories.cart.applyCoupon, 'cart.applyCoupon')
}

export function useRemoveCoupon(): UseMutationResult<
  Cart,
  StoreApiError,
  { code: string },
  OptimisticCartContext | undefined
> {
  const { factories } = useStoreContext()
  return useRpcMutation(factories.cart.removeCoupon, 'cart.removeCoupon')
}

export function useLogin(): UseMutationResult<
  Customer,
  StoreApiError,
  { email: string; password: string },
  OptimisticCartContext | undefined
> {
  const { factories } = useStoreContext()
  return useRpcMutation(factories.auth.login, 'auth.login')
}

export function useLogout(): UseMutationResult<
  void,
  StoreApiError,
  void,
  OptimisticCartContext | undefined
> {
  const { factories } = useStoreContext()
  return useRpcMutation(factories.auth.logout, 'auth.logout')
}

export function useRegister(): UseMutationResult<
  Customer,
  StoreApiError,
  { email: string; password: string; firstName?: string; lastName?: string },
  OptimisticCartContext | undefined
> {
  const { factories } = useStoreContext()
  return useRpcMutation(factories.auth.register, 'auth.register')
}

export function useCustomer(): UseQueryResult<Customer, StoreApiError> {
  const { factories } = useStoreContext()
  return useRpcQuery(factories.customer.get(), 'customer.get')
}

export function useOrders(
  params?: { page?: number; perPage?: number },
): UseQueryResult<{ items: OrderSummary[]; total: number }, StoreApiError> {
  const { factories } = useStoreContext()
  return useRpcQuery(factories.customer.orders(params), 'customer.orders', params)
}

export function useCheckoutUrl(): UseMutationResult<
  string,
  StoreApiError,
  void,
  OptimisticCartContext | undefined
> {
  return useRpcMutation({
    mutationKey: ['woo', 'cart', 'checkoutUrl'],
    mutationFn: async () => {
      throw new Error('The core executor is replaced by useRpcMutation.')
    },
    invalidates: [],
  }, 'cart.checkoutUrl')
}
