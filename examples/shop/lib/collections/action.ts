"use server";

import type { PageInfo, ProductCard } from "@/lib/types";
import { fetchCollectionProducts } from "@/lib/woo/operations/products";
import type { ProductFilter } from "@/lib/woo/types";

export async function loadMoreCollectionProducts(params: {
  collection: string;
  cursor: string;
  sortKey?: string;
  filters?: ProductFilter[];
  locale: string;
}): Promise<{ products: ProductCard[]; pageInfo: PageInfo }> {
  const result = await fetchCollectionProducts({
    collection: params.collection,
    cursor: params.cursor,
    sortKey: params.sortKey,
    filters: params.filters,
    locale: params.locale,
  });

  return {
    products: result.products,
    pageInfo: result.pageInfo,
  };
}
