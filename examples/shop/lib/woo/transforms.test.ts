import type { Cart, Product } from "@woo/storefront-core";
import { describe, expect, it } from "vitest";

import { majorUnits, toCart, toProductDetails } from "./transforms";

const product: Product = {
  averageRating: "4.5",
  categories: [{ id: 4, name: "Clothing", slug: "clothing" }],
  description: "<p>A durable shirt.</p>",
  id: 12,
  images: [{ alt: "Blue shirt", id: 1, src: "https://store.test/shirt.jpg", thumbnail: "" }],
  isInStock: true,
  isOnSale: true,
  name: "SDK Shirt",
  permalink: "https://store.test/product/sdk-shirt/",
  prices: {
    currencyCode: "USD",
    currencyMinorUnit: 2,
    price: "1999",
    regularPrice: "2499",
    salePrice: "1999",
  },
  raw: {
    attributes: [
      {
        has_variations: true,
        id: 8,
        name: "Color",
        terms: [
          { id: 81, name: "Blue", slug: "blue" },
          { id: 82, name: "Red", slug: "red" },
        ],
      },
    ],
    variations: [
      { attributes: [{ name: "Color", value: "Blue" }], id: 121, is_in_stock: true },
      { attributes: [{ name: "Color", value: "Red" }], id: 122, is_in_stock: false },
    ],
  },
  reviewCount: 2,
  shortDescription: "A shirt.",
  sku: "SDK-SHIRT",
  slug: "sdk-shirt",
};

describe("Woo storefront transforms", () => {
  it("converts minor units without losing trailing decimals", () => {
    expect(majorUnits("1999", 2)).toBe("19.99");
    expect(majorUnits("250", 0)).toBe("250");
  });

  it("maps Woo options and variation IDs into purchasable product variants", () => {
    const result = toProductDetails(product);

    expect(result.defaultVariantId).toBe("121");
    expect(result.variantsCount).toBe(2);
    expect(result.options[0]?.values.map(({ name }) => name)).toEqual(["Blue", "Red"]);
    expect(
      result.variants?.map(({ availableForSale, id, selectedOptions }) => ({
        availableForSale,
        id,
        selectedOptions,
      })),
    ).toEqual([
      { availableForSale: true, id: "121", selectedOptions: [{ name: "Color", value: "Blue" }] },
      { availableForSale: false, id: "122", selectedOptions: [{ name: "Color", value: "Red" }] },
    ]);
  });

  it("maps a Woo cart and accepts relative product permalinks", () => {
    const cart: Cart = {
      checkoutUrl: "https://store.test/checkout/c/session",
      coupons: [{ code: "SAVE10", totals: { totalDiscount: "200" } }],
      items: [
        {
          id: 121,
          images: product.images,
          key: "line-key",
          name: product.name,
          prices: { currencyCode: "USD", currencyMinorUnit: 2, price: "1999" },
          quantity: 2,
          totals: { lineSubtotal: "3998", lineTotal: "3798" },
          variation: [{ attribute: "Color", value: "Blue" }],
          raw: { permalink: "/product/sdk-shirt/" },
        } as Cart["items"][number] & { raw: unknown },
      ],
      itemsCount: 2,
      needsShipping: true,
      totals: {
        currencyCode: "USD",
        currencyMinorUnit: 2,
        totalDiscount: "200",
        totalItems: "3798",
        totalPrice: "3798",
        totalShipping: "0",
        totalTax: "0",
      },
    };

    const result = toCart(cart);

    expect(result.checkoutUrl).toBe(cart.checkoutUrl);
    expect(result.lines[0]?.merchandise.product.handle).toBe("sdk-shirt");
    expect(result.cost.totalAmount).toEqual({ amount: "37.98", currencyCode: "USD" });
    expect(result.discountCodes).toEqual([{ applicable: true, code: "SAVE10" }]);
  });
});
