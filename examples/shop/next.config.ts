import type { NextConfig } from "next";
import createNextIntlPlugin from "next-intl/plugin";
import {
  PHASE_DEVELOPMENT_SERVER,
  PHASE_PRODUCTION_BUILD,
  PHASE_PRODUCTION_SERVER,
} from "next/constants";

function assertRequiredEnv() {
  const missing = ["WOO_STORE_URL", "WOO_SESSION_SECRET"].filter((key) => !process.env[key]);
  if (missing.length > 0) {
    throw new Error(
      `Missing required Woo Store environment variables: ${missing.join(", ")}. See .env.example.`,
    );
  }
}

const wooStoreUrl = process.env.WOO_STORE_URL ? new URL(process.env.WOO_STORE_URL) : null;

const nextConfig: NextConfig = {
  cacheComponents: true,
  partialPrefetching: true,
  // TS7's native compiler doesn't expose the programmatic API Next uses for type checking; the CLI path does.
  experimental: { useTypeScriptCli: true },
  images: {
    deviceSizes: [1080],
    imageSizes: [],
    minimumCacheTTL: 31536000,
    remotePatterns: wooStoreUrl
      ? [
          {
            hostname: wooStoreUrl.hostname,
            protocol: wooStoreUrl.protocol.slice(0, -1) as "http" | "https",
          },
        ]
      : [],
    unoptimized: !!process.env.V0_CALLBACK_URL,
  },
  reactCompiler: true,
  turbopack: {
    rules: {
      "*.css": {
        as: "*.css",
        loaders: ["@tailwindcss/turbopack"],
      },
    },
  },
};

const withNextIntl = createNextIntlPlugin({
  experimental: { createMessagesDeclaration: "./lib/i18n/messages/en.json" },
  requestConfig: "./lib/i18n/request.ts",
});

const intlConfig = withNextIntl(nextConfig);

const config = intlConfig;

function getConfig(phase: string): NextConfig {
  const isTypegen = process.argv.includes("typegen");
  const isRuntime =
    phase === PHASE_DEVELOPMENT_SERVER ||
    phase === PHASE_PRODUCTION_BUILD ||
    phase === PHASE_PRODUCTION_SERVER;

  if (isRuntime && !isTypegen) {
    assertRequiredEnv();
  }

  return config;
}

export default getConfig;
