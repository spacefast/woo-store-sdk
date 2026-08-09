import type { PredictiveSearchResult } from "@/lib/types";
import { woo } from "@/lib/woo/store";
import { majorUnits, toProductCard } from "@/lib/woo/transforms";

export async function predictiveSearch({
  limit = 3,
  query,
}: {
  limit?: number;
  locale?: string;
  query: string;
}): Promise<PredictiveSearchResult> {
  const [products, categories] = await Promise.all([
    woo.products.list({ perPage: limit, search: query }),
    woo.categories.list(),
  ]);
  return {
    collections: categories
      .filter((category) => category.name.toLowerCase().includes(query.toLowerCase()))
      .slice(0, limit)
      .map((category) => ({ handle: category.slug, title: category.name })),
    products: products.items.map((product) => {
      const card = toProductCard(product);
      return {
        availableForSale: card.availableForSale,
        ...(card.compareAtPrice ? { compareAtPrice: card.compareAtPrice } : {}),
        featuredImage: card.featuredImage,
        handle: card.handle,
        id: card.id,
        price: {
          amount: majorUnits(product.prices.price, product.prices.currencyMinorUnit),
          currencyCode: product.prices.currencyCode,
        },
        title: card.title,
      };
    }),
    queries: [],
  };
}
