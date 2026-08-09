import { productListMarkdown } from "@/lib/markdown";
import { searchIndexProducts } from "@/lib/woo/operations/products";

export async function GET(request: Request) {
  const query = new URL(request.url).searchParams.get("q")?.trim() ?? "";
  const result = await searchIndexProducts({ limit: 100, query });
  return new Response(productListMarkdown(query ? `Search: ${query}` : "All products", result.products), {
    headers: { "content-type": "text/markdown; charset=utf-8" },
  });
}
