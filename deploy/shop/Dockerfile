FROM node:24-bookworm-slim

RUN corepack enable && corepack prepare pnpm@11.16.0 --activate
WORKDIR /app

COPY . .
RUN pnpm install --frozen-lockfile
ARG WOO_STORE_URL
RUN WOO_STORE_URL="$WOO_STORE_URL" WOO_SESSION_SECRET="build-only-session-secret-not-used-at-runtime" pnpm build \
    && WOO_STORE_URL="$WOO_STORE_URL" WOO_SESSION_SECRET="build-only-session-secret-not-used-at-runtime" pnpm build:shop

ENV NODE_ENV=production
EXPOSE 3000
CMD ["pnpm", "--filter", "woo-store-shop", "start", "--hostname", "0.0.0.0", "--port", "3000"]
