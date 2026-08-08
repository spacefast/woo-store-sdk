import {
  CACHE_PROFILES,
  type WooQueryOptions,
} from '@woo/storefront-core'
import {
  HydrationBoundary,
  type QueryClient,
} from '@tanstack/react-query'

export { HydrationBoundary }

/** Prefetches a core query factory with its declared browser cache profile. */
export async function prefetchWooQuery<TData>(
  queryClient: QueryClient,
  options: WooQueryOptions<TData>,
): Promise<void> {
  const profile = CACHE_PROFILES[options.profile]
  await queryClient.prefetchQuery({
    queryKey: options.queryKey,
    queryFn: options.queryFn,
    staleTime: profile.staleTime,
    gcTime: profile.gcTime,
  })
}

export const prefetch = prefetchWooQuery
