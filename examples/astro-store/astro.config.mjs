import { readFileSync } from "node:fs";
import { defineConfig } from "astro/config";
import react from "@astrojs/react";

// Local development only. On Spacefast, static pages and Woo share the origin.
const origin = process.env.WOO_ORIGIN;
const proxy = origin
  ? Object.fromEntries(
      [
        "/wp-json",
        "/checkout",
        "/wp-content",
        "/wp-includes",
        "/my-account",
        "/wp-admin",
        "/wp-login.php",
        "^/\?.*download_file=",
      ].map((path) => [path, { target: origin, changeOrigin: false }]),
    )
  : undefined;

// A local preview projection. Spacefast's compiler emits the production catalog.
const catalog = JSON.parse(readFileSync(new URL("./sell/products.json", import.meta.url), "utf8"));
const preview = {
  schemaVersion: 1,
  currency: "eur",
  products: catalog.products.map(({ key, name, summary, description, price, kind, active }) => ({
    key,
    name,
    summary: summary ?? null,
    description: description ?? null,
    price,
    image: null,
    page: null,
    kind,
    active,
  })),
};
function previewSource(server) {
  if (origin) return;
  server.middlewares.use((request, response, next) => {
    const path = request.url?.split("?")[0];
    const body =
      path === "/__spacefast/commerce.json"
        ? { source: "preview" }
        : path === "/_spacefast/catalog.json"
          ? preview
          : null;
    if (!body) return next();
    response.setHeader("content-type", "application/json");
    response.setHeader("cache-control", "no-store");
    response.end(JSON.stringify(body));
  });
}

export default defineConfig({
  output: "static",
  integrations: [react()],
  vite: {
    plugins: [
      {
        name: "local-store-preview",
        configureServer: previewSource,
      },
    ],
    server: { proxy },
  },
});
