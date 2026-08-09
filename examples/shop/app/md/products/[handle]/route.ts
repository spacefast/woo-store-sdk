import { getProductWithVariants } from "@/lib/woo/operations/products";
import { productMarkdown } from "@/lib/markdown";

export async function GET(_: Request, { params }: { params: Promise<{ handle: string }> }) {
  const product = await getProductWithVariants({ handle: (await params).handle });
  if (!product) return new Response("Not found", { status: 404 });
  return new Response(productMarkdown(product), {
    headers: { "content-type": "text/markdown; charset=utf-8" },
  });
}
