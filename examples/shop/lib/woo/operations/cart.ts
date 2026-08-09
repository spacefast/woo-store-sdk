import type { Cart as WooCart } from "@woo/storefront-core";

import type { Cart, CartWarning } from "@/lib/types";
import { woo } from "@/lib/woo/store";
import { toCart } from "@/lib/woo/transforms";

export interface CartLineInput {
  attributes?: { key: string; value: string }[];
  merchandiseId: string;
  quantity: number;
}

export type CartMutationResult = { cart: Cart; warnings: CartWarning[] };

function result(cart: WooCart): CartMutationResult {
  return { cart: toCart(cart), warnings: [] };
}

export async function getCart(): Promise<Cart | undefined> {
  return toCart(await woo.cart.get());
}

export async function getCartById(): Promise<Cart | undefined> {
  return getCart();
}

export async function createCart(): Promise<CartMutationResult> {
  return result(await woo.cart.get());
}

export async function createCartWithoutCookie(): Promise<CartMutationResult> {
  return createCart();
}

export async function addToCart(lines: CartLineInput[]): Promise<CartMutationResult> {
  let cart = await woo.cart.get();
  for (const line of lines) {
    cart = await woo.cart.addItem.mutationFn({
      id: Number(line.merchandiseId),
      quantity: line.quantity,
      ...(line.attributes
        ? { variation: Object.fromEntries(line.attributes.map(({ key, value }) => [key, value])) }
        : {}),
    });
  }
  return result(cart);
}

export async function updateCart(
  lines: { id: string; quantity: number }[],
): Promise<CartMutationResult> {
  let cart = await woo.cart.get();
  for (const line of lines) {
    cart =
      line.quantity === 0
        ? await woo.cart.removeItem.mutationFn({ key: line.id })
        : await woo.cart.updateItem.mutationFn({ key: line.id, quantity: line.quantity });
  }
  return result(cart);
}

export async function removeFromCart(lineIds: string[]): Promise<CartMutationResult> {
  let cart = await woo.cart.get();
  for (const key of lineIds) cart = await woo.cart.removeItem.mutationFn({ key });
  return result(cart);
}

export async function updateCartNote(): Promise<CartMutationResult> {
  return result(await woo.cart.get());
}
