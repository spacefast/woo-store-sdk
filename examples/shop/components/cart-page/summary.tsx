"use client";

import Link from "next/link";

import { useCart } from "@/components/cart/context";
import { useCartRender } from "@/components/cart/context";
import { DiscountForm } from "@/components/cart/discount-form";
import { cartDiscountAmount } from "@/lib/cart";
import { cn, formatPrice } from "@/lib/utils";

function CheckoutLink({
  isUpdatingCart,
  updatingText,
  checkoutText,
}: {
  isUpdatingCart: boolean;
  updatingText: string;
  checkoutText: string;
}) {
  const baseClassName =
    "flex items-center justify-center w-full h-12 rounded-lg text-sm font-medium bg-primary text-primary-foreground transition-colors";

  if (isUpdatingCart) {
    return (
      <span className={cn(baseClassName, "opacity-50 cursor-not-allowed")} aria-disabled="true">
        <span className="flex items-center gap-2">
          <span>{updatingText}</span>
        </span>
      </span>
    );
  }

  return (
    <Link href="/checkout" className={cn(baseClassName, "hover:bg-primary/90 cursor-pointer")}>
      <span>{checkoutText}</span>
    </Link>
  );
}

interface SummaryProps {
  completeCheckoutLabel: string;
  estimatedTotalLabel: string;
  locale: string;
  taxesAndShippingNote: string;
  updatingCartLabel: string;
}

export function Summary({
  completeCheckoutLabel,
  estimatedTotalLabel,
  locale,
  taxesAndShippingNote,
  updatingCartLabel,
}: SummaryProps) {
  const { isUpdatingCart } = useCart();
  const cart = useCartRender();

  if (!cart) return null;

  const lineSubtotal = cart.lines.reduce(
    (sum, line) => sum + parseFloat(line.cost.totalAmount.amount),
    0,
  );
  const estimatedTotal = Math.max(0, lineSubtotal - cartDiscountAmount(cart));
  const currencyCode = cart.cost.subtotalAmount.currencyCode;

  return (
    <div className="space-y-5">
      <DiscountForm cart={cart} />
      <div>
        <div className="flex items-baseline justify-between">
          <span className="text-base text-muted-foreground">{estimatedTotalLabel}</span>
          <span className="text-xl font-medium text-foreground">
            {formatPrice(estimatedTotal, currencyCode, locale)}
          </span>
        </div>
        <p className="text-xs text-muted-foreground mt-1">{taxesAndShippingNote}</p>
      </div>

      <CheckoutLink
        isUpdatingCart={isUpdatingCart}
        updatingText={updatingCartLabel}
        checkoutText={completeCheckoutLabel}
      />
    </div>
  );
}
