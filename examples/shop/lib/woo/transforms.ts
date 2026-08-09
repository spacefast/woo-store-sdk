import type {
  Cart as WooCart,
  CartItem as WooCartItem,
  Category as WooCategory,
  Product as WooProduct,
  ProductImage as WooProductImage,
} from "@woo/storefront-core";

import type {
  Cart,
  CartLine,
  Collection,
  Image,
  Money,
  ProductCard,
  ProductDetails,
  ProductOption,
  ProductVariant,
  SelectedOption,
} from "@/lib/types";

type JsonRecord = Record<string, unknown>;

function record(value: unknown): JsonRecord {
  return value !== null && typeof value === "object" && !Array.isArray(value)
    ? (value as JsonRecord)
    : {};
}

function records(value: unknown): JsonRecord[] {
  return Array.isArray(value) ? value.map(record) : [];
}

function string(value: unknown, fallback = ""): string {
  return typeof value === "string" ? value : fallback;
}

function number(value: unknown, fallback = 0): number {
  const parsed = typeof value === "number" ? value : Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

export function majorUnits(value: string, minorUnit: number): string {
  return (number(value) / 10 ** minorUnit).toFixed(minorUnit);
}

function money(value: string, currencyCode: string, minorUnit: number): Money {
  return { amount: majorUnits(value, minorUnit), currencyCode };
}

function image(source: WooProductImage | undefined, title: string): Image | null {
  if (!source?.src) return null;
  return {
    altText: source.alt || title,
    height: 1200,
    url: source.src,
    width: 1200,
  };
}

function productOptions(product: WooProduct): ProductOption[] {
  const raw = record(product.raw);
  return records(raw.attributes)
    .filter((attribute) => attribute.has_variations === true || attribute.hasVariations === true)
    .map((attribute) => ({
      id: String(attribute.id ?? attribute.name ?? "option"),
      name: string(attribute.name, "Option"),
      values: records(attribute.terms).map((term) => ({
        id: String(term.id ?? term.slug ?? term.name ?? "value"),
        name: string(term.name ?? term.slug),
      })),
    }))
    .filter((option) => option.values.length > 0);
}

function selectedOptions(options: ProductOption[]): SelectedOption[] {
  return options.flatMap((option) => {
    const value = option.values[0];
    return value ? [{ name: option.name, value: value.name }] : [];
  });
}

function productVariant(
  product: WooProduct,
  options: ProductOption[],
  source?: JsonRecord,
): ProductVariant {
  const featuredImage = image(product.images[0], product.name);
  const attributes = records(source?.attributes);
  const selections =
    attributes.length > 0
      ? attributes
          .map((attribute) => ({
            name: string(attribute.name ?? attribute.attribute),
            value: string(attribute.value),
          }))
          .filter((selection) => selection.name && selection.value)
      : selectedOptions(options);
  return {
    availableForSale:
      typeof source?.is_in_stock === "boolean" ? source.is_in_stock : product.isInStock,
    bundleParents: [],
    components: [],
    id: String(source?.id ?? product.id),
    image: featuredImage,
    price: money(
      product.prices.price,
      product.prices.currencyCode,
      product.prices.currencyMinorUnit,
    ),
    requiresComponents: false,
    selectedOptions: selections,
    title:
      selections.length === 0 ? "Default" : selections.map((option) => option.value).join(" / "),
  };
}

function productVariants(product: WooProduct, options: ProductOption[]): ProductVariant[] {
  const variations = records(record(product.raw).variations);
  return variations.length > 0
    ? variations.map((variation) => productVariant(product, options, variation))
    : [productVariant(product, options)];
}

export function toProductCard(product: WooProduct): ProductCard {
  const price = money(
    product.prices.price,
    product.prices.currencyCode,
    product.prices.currencyMinorUnit,
  );
  const regularPrice = money(
    product.prices.regularPrice,
    product.prices.currencyCode,
    product.prices.currencyMinorUnit,
  );
  return {
    availableForSale: product.isInStock,
    ...(product.isOnSale ? { compareAtPrice: regularPrice } : {}),
    defaultVariantId: String(product.id),
    featuredImage: image(product.images[0], product.name),
    handle: product.slug,
    id: String(product.id),
    isGiftCard: false,
    maxPrice: price,
    price,
    title: product.name,
  };
}

export function toProductDetails(product: WooProduct): ProductDetails {
  const card = toProductCard(product);
  const options = productOptions(product);
  const variants = productVariants(product, options);
  const variant = variants.find((candidate) => candidate.availableForSale) ?? variants[0];
  const images = product.images
    .map((source) => image(source, product.name))
    .filter((value): value is Image => value !== null);
  return {
    ...card,
    allVariantsInStock: product.isInStock,
    category: product.categories[0]
      ? { ancestors: [], id: String(product.categories[0].id), name: product.categories[0].name }
      : null,
    categoryId: product.categories[0] ? String(product.categories[0].id) : undefined,
    collectionHandles: product.categories.map((category) => category.slug),
    compareAtPriceRange: card.compareAtPrice
      ? { maxVariantPrice: card.compareAtPrice, minVariantPrice: card.compareAtPrice }
      : undefined,
    currencyCode: product.prices.currencyCode,
    ...(variant
      ? {
          defaultVariant: variant,
          defaultVariantId: variant.id,
          defaultVariantSelectedOptions: variant.selectedOptions,
        }
      : {}),
    description: product.shortDescription || product.description.replace(/<[^>]*>/g, " ").trim(),
    descriptionHtml: product.description,
    hasUniformPricing: true,
    images,
    manufacturerName: "WooCommerce",
    options,
    priceRange: { maxVariantPrice: card.price, minVariantPrice: card.price },
    seo: {
      description: product.shortDescription.replace(/<[^>]*>/g, " ").trim(),
      title: product.name,
    },
    tags: [],
    updatedAt: new Date(0).toISOString(),
    variants,
    variantsCount: variants.length,
    videos: [],
  };
}

export function toCollection(category: WooCategory): Collection {
  return {
    description: category.description,
    handle: category.slug,
    image: image(category.image ?? undefined, category.name),
    path: `/collections/${category.slug}`,
    seo: { description: category.description, title: category.name },
    title: category.name,
    updatedAt: new Date(0).toISOString(),
  };
}

function cartLine(item: WooCartItem): CartLine {
  const raw = record((item as WooCartItem & { raw?: unknown }).raw);
  const permalink = string(raw.permalink);
  const handle = permalink
    ? (new URL(permalink, "https://woo.invalid").pathname.split("/").filter(Boolean).pop() ?? "")
    : "";
  const featuredImage = image(item.images[0], item.name) ?? {
    altText: item.name,
    height: 0,
    url: "",
    width: 0,
  };
  const unitPrice = money(
    item.prices.price,
    item.prices.currencyCode,
    item.prices.currencyMinorUnit,
  );
  const totalAmount = money(
    item.totals.lineTotal,
    item.prices.currencyCode,
    item.prices.currencyMinorUnit,
  );
  return {
    canRemove: true,
    canUpdateQuantity: true,
    components: [],
    cost: { totalAmount },
    discountAllocations: [],
    id: item.key,
    merchandise: {
      id: String(item.id),
      image: featuredImage,
      price: unitPrice,
      product: {
        featuredImage,
        handle,
        id: String(item.id),
        title: item.name,
      },
      selectedOptions: item.variation.map(({ attribute, value }) => ({ name: attribute, value })),
      title: item.name,
    },
    quantity: item.quantity,
  };
}

export function toCart(cart: WooCart): Cart {
  const currencyCode = cart.totals.currencyCode;
  const minorUnit = cart.totals.currencyMinorUnit;
  return {
    appliedGiftCards: [],
    checkoutUrl: cart.checkoutUrl ?? "",
    cost: {
      subtotalAmount: money(cart.totals.totalItems, currencyCode, minorUnit),
      totalAmount: money(cart.totals.totalPrice, currencyCode, minorUnit),
    },
    discountAllocations: [],
    discountCodes: cart.coupons.map(({ code }) => ({ applicable: true, code })),
    id: undefined,
    lines: cart.items.map(cartLine),
    note: null,
    shippingCost: cart.totals.totalShipping
      ? money(cart.totals.totalShipping, currencyCode, minorUnit)
      : null,
    totalQuantity: cart.itemsCount,
  };
}
