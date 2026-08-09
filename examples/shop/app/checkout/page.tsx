import type { Metadata } from "next";
import Link from "next/link";
import { Suspense } from "react";

import { DemoCheckout } from "@/components/checkout/demo-checkout";
import { seedCartData } from "@/lib/cart/server";
import { getLocale } from "@/lib/params";

export const metadata: Metadata = {
  title: "Demo checkout",
  robots: { index: false, follow: false },
};

async function CheckoutContent() {
  const [cart, locale] = await Promise.all([seedCartData(), getLocale()]);
  if (!cart || cart.lines.length === 0) {
    return (
      <section className="mx-auto flex max-w-xl flex-1 flex-col items-center justify-center px-6 py-24 text-center">
        <h1 className="text-3xl font-semibold">Your cart is empty</h1>
        <p className="mt-3 text-muted-foreground">Add something before trying the demo checkout.</p>
        <Link className="mt-8 rounded-lg bg-primary px-5 py-3 text-primary-foreground" href="/">
          Continue shopping
        </Link>
      </section>
    );
  }
  return <DemoCheckout cart={cart} locale={locale} />;
}

export default function CheckoutPage() {
  return (
    <Suspense fallback={<div className="mx-auto max-w-6xl px-6 py-16">Loading checkout...</div>}>
      <CheckoutContent />
    </Suspense>
  );
}
