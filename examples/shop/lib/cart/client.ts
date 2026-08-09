"use client";

import type { Cart as WooCart } from "@woo/storefront-core";
import { RpcTransport } from "@woo/storefront-react";

const transport = new RpcTransport();
export const CART_RESOLVED_EVENT = "woo:cart:resolved";

function publish(cart: WooCart): void {
  document.dispatchEvent(new CustomEvent(CART_RESOLVED_EVENT, { detail: cart }));
}

export interface DiscountResolution {
  cart: WooCart | null;
  error: string | null;
}

export async function applyDiscount(code: string): Promise<DiscountResolution> {
  try {
    const cart = await transport.mutate<WooCart>("cart.applyCoupon", { code });
    publish(cart);
    return { cart, error: null };
  } catch (error) {
    return { cart: null, error: error instanceof Error ? error.message : "Network error" };
  }
}

export async function removeDiscount(code: string): Promise<DiscountResolution> {
  try {
    const cart = await transport.mutate<WooCart>("cart.removeCoupon", { code });
    publish(cart);
    return { cart, error: null };
  } catch (error) {
    return { cart: null, error: error instanceof Error ? error.message : "Network error" };
  }
}
