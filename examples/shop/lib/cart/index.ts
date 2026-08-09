import type { Cart } from "@/lib/types";

export function cartDiscountAmount(cart: Cart): number {
  // Woo coupons are represented separately, so this remains zero until allocation data is available.
  return (cart.discountAllocations ?? []).reduce(
    (sum, a) => sum + parseFloat(a.discountedAmount.amount),
    0,
  );
}
