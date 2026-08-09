import { shopConfig } from "@/lib/config";

export function GET() {
  return new Response(
    [
      `# ${shopConfig.site.name}`,
      "",
      "WooCommerce storefront powered by Woo Store SDK.",
      "",
      "- Product catalog: /collections/all",
      "- Product markdown: /md/products/{handle}",
      "- Collection markdown: /md/collections/{handle}",
      "- Search markdown: /md/search?q={query}",
      "- Human checkout: /checkout (public simulation; no charge or shipment)",
    ].join("\n"),
    { headers: { "content-type": "text/plain; charset=utf-8" } },
  );
}
