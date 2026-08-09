"use client";

import { Loader2, MinusIcon, PlusIcon } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState, useTransition } from "react";

import { useCart } from "@/components/cart/context";
import { Button } from "@/components/ui/button";
import { buyNowAction } from "@/lib/cart/action";
import { variantToOptimisticInfo } from "@/lib/product";
import type { Image, Money, SelectedOption } from "@/lib/types";

export interface BuyButtonVariant {
  availableForSale: boolean;
  id: string;
  image: Image | null;
  price: Money;
  requiresBundleConfiguration: boolean;
  selectedOptions: SelectedOption[];
  title: string;
}

export function BuyButtons({
  buyNow = true,
  featuredImage,
  handle,
  quantityPicker = true,
  selectedVariant,
  title,
}: {
  availableForSale?: boolean;
  buyNow?: boolean;
  featuredImage: Image | null;
  handle: string;
  quantityPicker?: boolean;
  selectedVariant: BuyButtonVariant | undefined;
  title: string;
}) {
  const [quantity, setQuantity] = useState(1);
  const [isBuyingNow, startBuyNowTransition] = useTransition();
  const t = useTranslations("product");
  const tCart = useTranslations("cart");
  const { addToCartOptimistic, isAddingToCart, pendingQuantity } = useCart();

  if (!selectedVariant) return null;

  const isOutOfStock = !selectedVariant.availableForSale;
  const requiresBundleConfiguration = selectedVariant.requiresBundleConfiguration;
  const buttonText =
    pendingQuantity > 0
      ? t("addingQuantity", { quantity: String(pendingQuantity) })
      : isAddingToCart
        ? t("addingToCart")
        : requiresBundleConfiguration
          ? t("bundleConfigurationRequired")
          : isOutOfStock
            ? t("outOfStock")
            : t("addToCart");

  return (
    <div className="grid gap-2.5">
      <div className="flex gap-2.5">
        {quantityPicker ? (
        <div
          aria-label={tCart("itemQuantity")}
          className="grid h-12 w-32 shrink-0 grid-cols-[3rem_2rem_3rem] rounded-lg bg-background ring-1 ring-border ring-inset"
          role="group"
        >
          <button
            aria-label={tCart("decreaseQuantity")}
            className="flex size-12 cursor-pointer items-center justify-center disabled:cursor-not-allowed disabled:opacity-50"
            disabled={quantity === 1}
            onClick={() => setQuantity((current) => Math.max(1, current - 1))}
            type="button"
          >
            <MinusIcon className="size-4" />
          </button>
          <span
            aria-live="polite"
            className="flex h-12 w-8 items-center justify-center text-sm font-medium tabular-nums"
          >
            {quantity}
          </span>
          <button
            aria-label={tCart("increaseQuantity")}
            className="flex size-12 cursor-pointer items-center justify-center disabled:cursor-not-allowed disabled:opacity-50"
            disabled={quantity === 99}
            onClick={() => setQuantity((current) => Math.min(99, current + 1))}
            type="button"
          >
            <PlusIcon className="size-4" />
          </button>
        </div>
        ) : null}
        <Button
        className="h-12 min-w-0 flex-1 justify-center"
        disabled={isOutOfStock || requiresBundleConfiguration}
        onClick={() =>
          addToCartOptimistic(
            selectedVariant.id,
            quantity,
            variantToOptimisticInfo(selectedVariant, { featuredImage, handle, title }),
          )
        }
        type="button"
      >
        {buttonText}
        </Button>
      </div>
      {buyNow ? (
        <Button
          className="h-12 w-full justify-center"
          disabled={isOutOfStock || isBuyingNow || requiresBundleConfiguration}
          onClick={() =>
            startBuyNowTransition(async () => {
              const result = await buyNowAction(selectedVariant.id, quantity);
              if (result.checkoutUrl) window.location.href = result.checkoutUrl;
            })
          }
          type="button"
          variant="outline"
        >
          {isBuyingNow ? <Loader2 className="size-4 animate-spin" /> : t("buyNow")}
        </Button>
      ) : null}
    </div>
  );
}
