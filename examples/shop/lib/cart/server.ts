import "server-only";
import { woo } from "@/lib/woo/store";
import { toCart } from "@/lib/woo/transforms";

export async function seedCartData() {
  try {
    return toCart(await woo.cart.get());
  } catch {
    return null;
  }
}
