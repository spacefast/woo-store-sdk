export {
  applyOptimisticCartUpdate,
  invalidateWooQueries,
  queryKeysForInvalidationTarget,
  rollbackOptimisticCartUpdate,
  type OptimisticCartContext,
} from './cache'
export { HydrationBoundary, prefetch, prefetchWooQuery } from './hydration'
export {
  useAddToCart,
  useApplyCoupon,
  useCart,
  useCategories,
  useCheckoutUrl,
  useCustomer,
  useLogin,
  useLogout,
  useOrders,
  useProduct,
  useProducts,
  useRegister,
  useRemoveCartItem,
  useRemoveCoupon,
  useUpdateCartItem,
  type ProductIdentifier,
} from './hooks'
export {
  applyCacheProfileDefaults,
  createWooQueryClient,
  StoreProvider,
  type StoreProviderProps,
} from './provider'
export {
  deserializeRpcResponse,
  RpcTransport,
  type RpcTransportOptions,
} from './rpc-transport'
