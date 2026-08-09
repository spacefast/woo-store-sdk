import { shopConfig } from "@/lib/config";
import { listContentHandles } from "@/lib/woo/operations/content";
import { getCollections } from "@/lib/woo/operations/collections";
import { searchIndexProducts } from "@/lib/woo/operations/products";

function escapeXml(value: string): string {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&apos;");
}

export async function storefrontSitemap(): Promise<string> {
  const [products, collections, content] = await Promise.all([
    searchIndexProducts({ limit: 100 }),
    getCollections({ limit: 100 }),
    listContentHandles().catch(() => ({ blogs: [], pages: [] })),
  ]);
  const paths = [
    "/",
    "/collections",
    "/collections/all",
    ...products.products.map((product) => `/products/${product.handle}`),
    ...collections.map((collection) => `/collections/${collection.handle}`),
    ...content.pages.map((handle) => `/pages/${handle}`),
    ...content.blogs.map((handle) => `/blogs/${handle}`),
  ];
  const urls = [...new Set(paths)].map(
    (path) => `  <url><loc>${escapeXml(new URL(path, shopConfig.site.url).toString())}</loc></url>`,
  );
  return ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', ...urls, "</urlset>"].join("\n");
}
