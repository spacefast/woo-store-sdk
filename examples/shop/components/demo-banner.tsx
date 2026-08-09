import Link from "next/link";

export function DemoBanner() {
  return (
    <aside className="bg-amber-300 px-4 py-2 text-center text-sm font-medium text-amber-950">
      Demo shop: no payment is charged and nothing will be shipped.{" "}
      <Link className="underline underline-offset-2" href="/checkout#test-cards">
        Use Stripe test cards only.
      </Link>
    </aside>
  );
}
