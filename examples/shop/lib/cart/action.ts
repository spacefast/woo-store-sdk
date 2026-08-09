"use server";

import { woo } from "@/lib/woo/store";

export async function buyNowAction(
  merchandiseId: string,
  quantity: number = 1,
): Promise<{ checkoutUrl: string | null; error?: string }> {
  const id = Number(merchandiseId);
  if (!Number.isInteger(id) || id <= 0) return { checkoutUrl: null, error: "Invalid product ID" };
  if (!Number.isInteger(quantity) || quantity < 1 || quantity > 99) {
    return { checkoutUrl: null, error: "Quantity must be between 1 and 99" };
  }
  await woo.cart.addItem.mutationFn({ id, quantity });
  return { checkoutUrl: "/checkout" };
}

export async function prepareCheckoutAction(): Promise<{ checkoutUrl: string | null }> {
  return { checkoutUrl: await woo.cart.checkoutUrl() };
}
