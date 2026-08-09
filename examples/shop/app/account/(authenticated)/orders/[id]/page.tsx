import { notFound } from "next/navigation";

import { formatPrice } from "@/lib/utils";
import { woo } from "@/lib/woo/store";

export default async function OrderPage({ params }: PageProps<"/account/orders/[id]">) {
  const id = Number((await params).id);
  if (!Number.isInteger(id)) notFound();
  const order = await woo.customer.order(id);
  return (
    <section>
      <h1 className="text-3xl font-semibold">Order #{order.id}</h1>
      <p className="mt-2 capitalize text-muted-foreground">{order.status}</p>
      <ul className="mt-8 divide-y rounded-lg border">
        {order.items.map((item) => (
          <li className="flex justify-between p-4" key={item.key}>
            <span>{item.name} x {item.quantity}</span>
            <span>{formatPrice(Number(item.totals.lineTotal) / 10 ** item.prices.currencyMinorUnit, item.prices.currencyCode, "en")}</span>
          </li>
        ))}
      </ul>
      <p className="mt-5 text-right text-lg font-medium">Total: {formatPrice(Number(order.total), order.currencyCode, "en")}</p>
    </section>
  );
}
