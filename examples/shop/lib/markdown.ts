import type { ProductCard, ProductDetails } from "@/lib/types";

function clean(value: string): string {
  return value.replace(/<[^>]*>/gu, " ").replace(/\s+/gu, " ").trim();
}

export function productMarkdown(product: ProductDetails): string {
  const price = product.priceRange.minVariantPrice;
  return [
    `# ${product.title}`,
    "",
    clean(product.description),
    "",
    `- Price: ${price.amount} ${price.currencyCode}`,
    `- Available: ${product.availableForSale ? "yes" : "no"}`,
    `- Product URL: /products/${product.handle}`,
  ].join("\n");
}

export function productListMarkdown(title: string, products: ProductCard[]): string {
  return [
    `# ${title}`,
    "",
    ...products.flatMap((product) => [
      `## [${product.title}](/products/${product.handle})`,
      `Price: ${product.price.amount} ${product.price.currencyCode}`,
      "",
    ]),
  ].join("\n");
}
