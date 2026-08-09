import { productListMarkdown } from "@/lib/markdown";
import { getCollection } from "@/lib/woo/operations/collections";
import { getCollectionProducts } from "@/lib/woo/operations/products";

export async function GET(_: Request, { params }: { params: Promise<{ handle: string }> }) {
  const { handle } = await params;
  const [collection, result] = await Promise.all([
    getCollection({ handle }),
    getCollectionProducts({ collection: handle, limit: 100 }),
  ]);
  if (!collection) return new Response("Not found", { status: 404 });
  return new Response(productListMarkdown(collection.title, result.products), {
    headers: { "content-type": "text/markdown; charset=utf-8" },
  });
}
