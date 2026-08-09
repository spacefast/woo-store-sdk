import Link from "next/link";

import { formatPrice } from "@/lib/utils";
import { woo } from "@/lib/woo/store";

export default async function OrdersPage() {
  const orders = await woo.customer.orders({ page: 1, perPage: 20 });
  return (
    <section>
      <h1 className="text-3xl font-semibold">Orders</h1>
      {orders.items.length === 0 ? (
        <p className="mt-8 text-muted-foreground">You have no orders yet.</p>
      ) : (
        <ul className="mt-8 grid gap-3">
          {orders.items.map((order) => (
            <li key={order.id}>
              <Link className="flex justify-between rounded-lg border p-4" href={`/account/orders/${order.id}`}>
                <span>Order #{order.id} · {new Date(order.dateCreated).toLocaleDateString()}</span>
                <span>{formatPrice(Number(order.total), order.currencyCode, "en")}</span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
