import {
  type Cart,
  type InvalidationTarget,
} from '@woo/storefront-core'
import {
  type QueryClient,
  type QueryKey,
} from '@tanstack/react-query'

const sessionQueryKeys = [
  ['woo', 'cart'],
  ['woo', 'checkout'],
  ['woo', 'customer'],
  ['woo', 'orders'],
] as const satisfies readonly QueryKey[]

/** Converts core invalidation targets to TanStack Query key prefixes. */
export function queryKeysForInvalidationTarget(target: InvalidationTarget): readonly QueryKey[] {
  if (target.type === 'key') {
    return [target.key]
  }
  if (target.resource === 'session-all') {
    return sessionQueryKeys
  }
  return [['woo', target.resource]]
}

export async function invalidateWooQueries(
  queryClient: QueryClient,
  targets: readonly InvalidationTarget[],
): Promise<void> {
  await Promise.all(targets.flatMap((target) =>
    queryKeysForInvalidationTarget(target).map((queryKey) =>
      queryClient.invalidateQueries({ queryKey })),
  ))
}

export interface OptimisticCartContext {
  snapshots: ReadonlyArray<readonly [QueryKey, Cart | undefined]>
}

/** Applies a core cart reducer to every cached cart query and captures rollback state. */
export async function applyOptimisticCartUpdate<TVariables>(
  queryClient: QueryClient,
  optimisticUpdate: (variables: TVariables, current: Cart | undefined) => Cart | undefined,
  variables: TVariables,
): Promise<OptimisticCartContext> {
  const queryKey = ['woo', 'cart'] as const
  await queryClient.cancelQueries({ queryKey })
  const snapshots = queryClient.getQueriesData<Cart>({ queryKey })

  for (const [cachedKey, current] of snapshots) {
    queryClient.setQueryData<Cart | undefined>(cachedKey, optimisticUpdate(variables, current))
  }

  return { snapshots }
}

export function rollbackOptimisticCartUpdate(
  queryClient: QueryClient,
  context: OptimisticCartContext | undefined,
): void {
  for (const [queryKey, cart] of context?.snapshots ?? []) {
    queryClient.setQueryData(queryKey, cart)
  }
}
