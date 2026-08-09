import type { ProductListParams } from "@woo/storefront-core";

import type {
  PageInfo,
  PriceRange,
  ProductCard,
  ProductDetails,
  ProductVariant,
} from "@/lib/types";
import { woo } from "@/lib/woo/store";
import { toProductCard, toProductDetails } from "@/lib/woo/transforms";
import type { ProductFilter } from "@/lib/woo/types";

export type ActiveFilters = Record<string, string | string[] | undefined>;

export interface SearchIndexProductsParams {
  collection?: string;
  cursor?: string;
  filters?: ProductFilter[];
  limit?: number;
  locale?: string;
  query?: string;
  sortKey?: string;
}

export interface SearchIndexProductsResult {
  pageInfo: PageInfo;
  products: ProductCard[];
  total: number;
}

export interface CollectionProductsParams extends SearchIndexProductsParams {
  activeFilters?: ActiveFilters;
  collection: string;
}

export interface CollectionProductsResult {
  filters: import("@/lib/types").Filter[];
  pageInfo: PageInfo;
  priceRange?: PriceRange;
  products: ProductCard[];
}

function one(value: string | string[] | undefined): string | undefined {
  return Array.isArray(value) ? value[0] : value;
}

function sortParams(
  sortKey: string | undefined,
): Partial<Pick<ProductListParams, "order" | "orderby">> {
  switch (sortKey) {
    case "best-selling":
      return { order: "desc", orderby: "popularity" };
    case "date-new-to-old":
      return { order: "desc", orderby: "date" };
    case "date-old-to-new":
      return { order: "asc", orderby: "date" };
    case "price-high-to-low":
      return { order: "desc", orderby: "price" };
    case "price-low-to-high":
      return { order: "asc", orderby: "price" };
    case "product-name-descending":
      return { order: "desc", orderby: "title" };
    case "product-name-ascending":
      return { order: "asc", orderby: "title" };
    default:
      return {};
  }
}

function paramsFor(input: SearchIndexProductsParams): ProductListParams {
  const filter = input.filters?.[0];
  const page = input.cursor ? Number.parseInt(input.cursor, 10) : 1;
  return {
    ...sortParams(input.sortKey),
    ...(input.collection && input.collection !== "all" ? { category: input.collection } : {}),
    ...(filter?.category !== undefined ? { category: filter.category } : {}),
    ...(filter?.maxPrice ? { maxPrice: filter.maxPrice } : {}),
    ...(filter?.minPrice ? { minPrice: filter.minPrice } : {}),
    ...(filter?.onSale !== undefined ? { onSale: filter.onSale } : {}),
    ...(input.query ? { search: input.query } : {}),
    page: Number.isFinite(page) && page > 0 ? page : 1,
    perPage: input.limit ?? 24,
  };
}

function pageInfo(page: number, totalPages: number): PageInfo {
  return {
    endCursor: page < totalPages ? String(page + 1) : null,
    hasNextPage: page < totalPages,
    hasPreviousPage: page > 1,
    startCursor: page > 1 ? String(page - 1) : null,
  };
}

export function buildProductFiltersFromParams(active: ActiveFilters): ProductFilter[] {
  const min = one(active["filter.v.price.gte"]);
  const max = one(active["filter.v.price.lte"]);
  const onSale = one(active["filter.p.on_sale"]);
  if (!min && !max && !onSale) return [];
  return [
    {
      ...(min ? { minPrice: min } : {}),
      ...(max ? { maxPrice: max } : {}),
      ...(onSale ? { onSale: onSale === "1" } : {}),
    },
  ];
}

export async function fetchSearchIndexProducts(
  input: SearchIndexProductsParams,
): Promise<SearchIndexProductsResult> {
  const params = paramsFor(input);
  const result = await woo.products.list(params);
  return {
    pageInfo: pageInfo(params.page ?? 1, result.totalPages),
    products: result.items.map(toProductCard),
    total: result.total,
  };
}

export const searchIndexProducts = fetchSearchIndexProducts;

export async function fetchCollectionProducts(
  input: CollectionProductsParams,
): Promise<CollectionProductsResult> {
  const result = await fetchSearchIndexProducts(input);
  return { filters: [], pageInfo: result.pageInfo, products: result.products };
}

export const getCollectionProducts = fetchCollectionProducts;

export async function fetchSearchFacets(
  input: SearchIndexProductsParams & { activeFilters?: ActiveFilters },
) {
  const result = await woo.products.list({
    ...paramsFor({ ...input, limit: 100 }),
    page: 1,
    perPage: 100,
  });
  const prices = result.items.map(
    (product) => Number(product.prices.price) / 10 ** product.prices.currencyMinorUnit,
  );
  const currencyCode = result.items[0]?.prices.currencyCode;
  const priceRange =
    prices.length > 0
      ? {
          ...(currencyCode ? { currencyCode } : {}),
          max: Math.max(...prices),
          min: Math.min(...prices),
        }
      : undefined;
  return { filters: [], priceRange, total: result.total };
}

export const getSearchFacets = fetchSearchFacets;

export async function getProduct({
  handle,
}: {
  handle: string;
  locale?: string;
}): Promise<ProductDetails | null> {
  try {
    return toProductDetails(await woo.products.bySlug(handle));
  } catch (error) {
    if (error instanceof Error && error.name === "NotFoundError") return null;
    throw error;
  }
}

export async function getProductWithVariants({
  handle,
}: {
  handle: string;
  locale?: string;
}): Promise<ProductDetails | null> {
  return getProduct({ handle });
}

export async function getProductVariant({
  handle,
  selectedOptions,
}: {
  handle: string;
  locale?: string;
  selectedOptions?: unknown;
}): Promise<ProductVariant | undefined> {
  const product = await getProduct({ handle });
  if (!product) return undefined;
  if (!selectedOptions || typeof selectedOptions !== "object" || Array.isArray(selectedOptions))
    return product.defaultVariant;
  const entries = Object.entries(selectedOptions as Record<string, unknown>).filter(
    (entry): entry is [string, string] => typeof entry[1] === "string",
  );
  if (entries.length === 0) return product.defaultVariant;
  return product.variants?.find((variant) =>
    entries.every(([name, value]) =>
      variant.selectedOptions.some(
        (option) =>
          option.name.toLowerCase() === name.toLowerCase() &&
          option.value.toLowerCase() === value.toLowerCase(),
      ),
    ),
  );
}

export async function getProductOptionValues({
  handle,
}: {
  handle: string;
  ids?: string[];
  locale?: string;
}) {
  const product = await getProduct({ handle });
  const values = new Map<string, Map<string, Set<string>>>();
  for (const option of product?.options ?? []) {
    values.set(option.name, new Map(option.values.map((value) => [value.name, new Set<string>()])));
  }
  return values;
}

export async function getCatalogProducts(input: SearchIndexProductsParams) {
  return fetchSearchIndexProducts(input);
}

export async function getFilteredCatalogProducts(input: SearchIndexProductsParams) {
  return fetchSearchIndexProducts(input);
}

export async function getRelatedProducts({ handle, locale }: { handle: string; locale?: string }) {
  const product = await getProduct({ handle, locale });
  const collection = product?.collectionHandles[0];
  const result = await fetchSearchIndexProducts({ collection, limit: 8, locale });
  return result.products.filter((candidate) => candidate.handle !== handle);
}

export const getComplementaryProducts = getRelatedProducts;

export async function getProductById({ id }: { id: string; locale?: string }) {
  return toProductDetails(await woo.products.byId(Number(id)));
}

export async function getProductsByIds({ ids }: { ids: string[]; locale?: string }) {
  return Promise.all(ids.map((id) => getProductById({ id })));
}

export async function getProductsByHandles({ handles }: { handles: string[]; locale?: string }) {
  const products = await Promise.all(handles.map((handle) => getProduct({ handle })));
  return products.filter((product): product is ProductDetails => product !== null);
}
