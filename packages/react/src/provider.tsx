import {
  CACHE_PROFILES,
  createStorefrontClient,
  type SessionStore,
  type StorefrontClient,
  type Transport,
} from '@woo/storefront-core'
import {
  QueryClient,
  QueryClientProvider,
  type QueryClientConfig,
} from '@tanstack/react-query'
import {
  createContext,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react'

import { RpcTransport, type RpcTransportOptions } from './rpc-transport'

const inertSession: SessionStore = {
  async read() {
    return { cacheId: 'browser-rpc' }
  },
  async write() {},
  async clear() {},
}

const inertTransport: Transport = {
  async request() {
    throw new Error('Core query executors must be replaced by the browser RPC transport.')
  },
}

function createFactoryClient(): StorefrontClient {
  return createStorefrontClient(
    { url: 'https://browser-rpc.invalid' },
    inertSession,
    inertTransport,
  )
}

const resourceProfiles = [
  ['products', 'catalog'],
  ['categories', 'catalog'],
  ['cart', 'session'],
  ['checkout', 'session'],
  ['customer', 'session'],
  ['orders', 'session'],
] as const

/** Applies the shared cache taxonomy to any caller-provided QueryClient. */
export function applyCacheProfileDefaults(queryClient: QueryClient): QueryClient {
  for (const [resource, profileName] of resourceProfiles) {
    const profile = CACHE_PROFILES[profileName]
    queryClient.setQueryDefaults(['woo', resource], {
      gcTime: profile.gcTime,
      staleTime: profile.staleTime,
    })
  }
  return queryClient
}

export function createWooQueryClient(config?: QueryClientConfig): QueryClient {
  return applyCacheProfileDefaults(new QueryClient(config))
}

/**
 * What the hooks call. `RpcTransport` (the server-adapter protocol) and the
 * browser adapter in `@woo/storefront-browser` both implement it; pick one in
 * the provider config.
 */
export interface StoreTransport {
  query<T>(operation: string, args?: unknown): Promise<T>
  mutate<T>(operation: string, args?: unknown): Promise<T>
}

interface StoreContextValue {
  factories: StorefrontClient
  transport: StoreTransport
}

const StoreContext = createContext<StoreContextValue | null>(null)

export interface StoreProviderProps extends RpcTransportOptions {
  children: ReactNode
  queryClient?: QueryClient
  /** Defaults to an `RpcTransport` on `basePath`. */
  transport?: StoreTransport
}

export function StoreProvider({
  children,
  queryClient,
  transport,
  basePath,
  fetch: fetchImpl,
}: StoreProviderProps) {
  const [client] = useState(() => queryClient
    ? applyCacheProfileDefaults(queryClient)
    : createWooQueryClient())
  const [factories] = useState(createFactoryClient)
  const rpcTransport = useMemo(
    () => transport ?? new RpcTransport({ basePath, fetch: fetchImpl }),
    [basePath, fetchImpl, transport],
  )
  const context = useMemo(
    () => ({ factories, transport: rpcTransport }),
    [factories, rpcTransport],
  )

  return (
    <QueryClientProvider client={client}>
      <StoreContext.Provider value={context}>{children}</StoreContext.Provider>
    </QueryClientProvider>
  )
}

export function useStoreContext(): StoreContextValue {
  const context = useContext(StoreContext)
  if (!context) {
    throw new Error('Woo storefront hooks must be rendered inside <StoreProvider>.')
  }
  return context
}
