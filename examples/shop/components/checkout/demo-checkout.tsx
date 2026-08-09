"use client";

import Link from "next/link";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import type { Cart } from "@/lib/types";
import { formatPrice } from "@/lib/utils";

const testCards = [
  { brand: "Visa", number: "4242 4242 4242 4242" },
  { brand: "Mastercard", number: "5555 5555 5555 4444" },
  { brand: "American Express", number: "3782 822463 10005" },
  { brand: "Discover", number: "6011 1111 1111 1117" },
] as const;

export function DemoCheckout({ cart, locale }: { cart: Cart; locale: string }) {
  const [selectedCard, setSelectedCard] = useState<string>(testCards[0].number);
  const [complete, setComplete] = useState(false);

  if (complete) {
    return (
      <section className="mx-auto flex max-w-xl flex-1 flex-col items-center justify-center px-6 py-24 text-center">
        <span className="rounded-full bg-emerald-100 px-3 py-1 text-sm font-medium text-emerald-800">
          Simulation successful
        </span>
        <h1 className="mt-6 text-4xl font-semibold">Demo order complete</h1>
        <p className="mt-4 text-lg text-muted-foreground">
          Nothing was charged. Nothing will ship. This order only existed in your browser.
        </p>
        <Button asChild className="mt-8">
          <Link href="/">Keep exploring</Link>
        </Button>
      </section>
    );
  }

  return (
    <div className="mx-auto grid w-full max-w-6xl gap-12 px-6 py-12 lg:grid-cols-[1fr_22rem]">
      <form
        className="space-y-10"
        onSubmit={(event) => {
          event.preventDefault();
          setComplete(true);
        }}
      >
        <header>
          <p className="text-sm font-medium text-amber-700">Safe public demo</p>
          <h1 className="mt-2 text-4xl font-semibold">Checkout without buying anything</h1>
          <p className="mt-4 max-w-2xl text-muted-foreground">
            No request is sent to Stripe, no card is saved, no payment is charged, and no product
            will be shipped. Pick one of Stripe&apos;s published example cards below.
          </p>
        </header>
        <fieldset className="grid gap-4 sm:grid-cols-2">
          <legend className="mb-4 text-xl font-medium">Contact and shipping</legend>
          <label className="grid gap-2 text-sm">
            Email
            <input
              className="h-11 rounded-md border bg-background px-3"
              defaultValue="shopper@example.com"
              required
              type="email"
            />
          </label>
          <label className="grid gap-2 text-sm">
            Full name
            <input
              className="h-11 rounded-md border bg-background px-3"
              defaultValue="Demo Shopper"
              required
            />
          </label>
          <label className="grid gap-2 text-sm sm:col-span-2">
            Address
            <input
              className="h-11 rounded-md border bg-background px-3"
              defaultValue="510 Townsend Street"
              required
            />
          </label>
          <label className="grid gap-2 text-sm">
            City
            <input
              className="h-11 rounded-md border bg-background px-3"
              defaultValue="San Francisco"
              required
            />
          </label>
          <label className="grid gap-2 text-sm">
            Postal code
            <input
              className="h-11 rounded-md border bg-background px-3"
              defaultValue="94103"
              required
            />
          </label>
        </fieldset>
        <fieldset id="test-cards" className="space-y-4 scroll-mt-24">
          <legend className="text-xl font-medium">Stripe example card</legend>
          <div className="grid gap-3 sm:grid-cols-2">
            {testCards.map((card) => (
              <label
                className="flex cursor-pointer items-center gap-3 rounded-lg border p-4 has-checked:border-foreground"
                key={card.number}
              >
                <input
                  checked={selectedCard === card.number}
                  name="test-card"
                  onChange={() => setSelectedCard(card.number)}
                  type="radio"
                />
                <span>
                  <span className="block font-medium">{card.brand}</span>
                  <span className="font-mono text-sm text-muted-foreground">{card.number}</span>
                </span>
              </label>
            ))}
          </div>
          <div className="grid gap-4 sm:grid-cols-[1fr_8rem_8rem]">
            <label className="grid gap-2 text-sm">
              Card number
              <input
                className="h-11 rounded-md border bg-muted px-3 font-mono"
                readOnly
                value={selectedCard}
              />
            </label>
            <label className="grid gap-2 text-sm">
              Expiry
              <input
                className="h-11 rounded-md border bg-muted px-3 font-mono"
                readOnly
                value="12/34"
              />
            </label>
            <label className="grid gap-2 text-sm">
              CVC
              <input
                className="h-11 rounded-md border bg-muted px-3 font-mono"
                readOnly
                value="123"
              />
            </label>
          </div>
        </fieldset>
        <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
          By completing this demo you confirm that this is not a real purchase. Nothing is charged,
          fulfilled, or shipped.
        </div>
        <Button className="h-12 w-full text-base" type="submit">
          Complete fake order - no charge
        </Button>
      </form>
      <aside className="h-fit rounded-xl border bg-muted/30 p-6">
        <h2 className="text-xl font-medium">Order summary</h2>
        <ul className="mt-5 space-y-4">
          {cart.lines.map((line) => (
            <li className="flex justify-between gap-4 text-sm" key={line.id}>
              <span>
                {line.merchandise.product.title} x {line.quantity}
              </span>
              <span>
                {formatPrice(
                  Number(line.cost.totalAmount.amount),
                  line.cost.totalAmount.currencyCode,
                  locale,
                )}
              </span>
            </li>
          ))}
        </ul>
        <div className="mt-6 flex justify-between border-t pt-5 font-medium">
          <span>Demo total</span>
          <span>
            {formatPrice(
              Number(cart.cost.totalAmount.amount),
              cart.cost.totalAmount.currencyCode,
              locale,
            )}
          </span>
        </div>
      </aside>
    </div>
  );
}
