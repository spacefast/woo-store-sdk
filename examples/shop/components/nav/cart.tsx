import { HandbagIcon } from "lucide-react";
import { getTranslations } from "next-intl/server";

import { withFallback } from "@/lib/woo/errors";
import { getCart } from "@/lib/woo/operations/cart";

import { CartIconClient } from "./cart-client";

export async function CartIcon() {
  const [cart, t] = await Promise.all([withFallback(getCart(), undefined), getTranslations("nav")]);

  return <CartIconClient cartLabel={t("cart")} initialCart={cart ?? null} />;
}

export function CartIconFallback() {
  return (
    <span className="flex items-center justify-center gap-1.5 text-foreground">
      <HandbagIcon className="size-5" />
      <span className="sr-only">Cart</span>
    </span>
  );
}
