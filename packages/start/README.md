# `@woo/storefront-start`

TanStack Start v1 adapter for the Woo Headless SDK. It provides the browser
RPC server route, a signed request-context cookie session, direct server data
access, mutation invalidation, and the Woo cache-webhook handler.

```ts
// src/lib/woo.ts
import { createStorefront } from '@woo/storefront-start'

export const woo = createStorefront({
  url: process.env.WOO_URL!,
  sessionSecret: process.env.WOO_SESSION_SECRET!,
  revalidateSecret: process.env.WOO_REVALIDATE_SECRET!,
})
```

Mount the RPC surface on a Start splat server route so the React package can
continue to call `/api/store/query` and `/api/store/rpc`:

```ts
// src/routes/api/store/$.ts
import { createFileRoute } from '@tanstack/react-router'
import { woo } from '../../../lib/woo'

export const Route = createFileRoute('/api/store/$')({
  server: woo.serverRoute,
})
```

Mount the webhook separately:

```ts
// src/routes/api/store/revalidate.ts
import { createFileRoute } from '@tanstack/react-router'
import { woo } from '../../../lib/woo'

export const Route = createFileRoute('/api/store/revalidate')({
  server: { handlers: { POST: woo.revalidateRoute } },
})
```

Loaders and server functions can execute the bound query directly:

```ts
const product = await woo.products.byId(42)
// Equivalent when query metadata is also needed:
const query = woo.products.byId(42)
const sameProduct = await query.queryFn()
```

## Server cache scope

TanStack Start has no framework data-cache equivalent to Next's tagged data
cache. This adapter therefore uses a small in-memory `TaggedCache` that honors
core's `serverLife` and `serverTags` policies. It is process-local: entries and
webhook invalidations are not shared across server instances and disappear on
restart or deployment. On horizontally scaled deployments, each process can
serve an entry until its TTL unless the webhook is delivered to every process.

Session-profile queries are never stored. Any query executed with a customer
token also bypasses both this cache and the runtime HTTP cache.
