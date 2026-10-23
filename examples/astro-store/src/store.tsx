import { useEffect, useState } from "react";
import {
  createBrowserTransport,
  isCheckoutOffInPreview,
  retryStoreQuery,
  type CommerceSource,
  type OrderConfirmation,
} from "@woo/storefront-browser";
import {
  createWooQueryClient,
  StoreProvider,
  useAddToCart,
  useCart,
  useCheckoutUrl,
  useProducts,
  useRemoveCartItem,
  useUpdateCartItem,
} from "@woo/storefront-react";
import type { Product } from "@woo/storefront-core";

type Page = "catalog" | "cart" | "confirmation";
const transport = createBrowserTransport();
const keys = new Set(["field-guide-ebook", "field-guide-print"]);

function money(value: string, currency: string, units: number) {
  return new Intl.NumberFormat("en", { style: "currency", currency }).format(
    Number(value) / 10 ** units,
  );
}
function message(error: unknown) {
  return error instanceof Error
    ? error.message
    : "The store could not complete that request. Please try again.";
}
function Notice({ error }: { error: unknown }) {
  return error ? (
    <p role="alert" className="notice">
      {isCheckoutOffInPreview(error) ? "Checkout is off in previews." : message(error)}
    </p>
  ) : null;
}

export default function Store({ page }: { page: Page }) {
  const [queryClient] = useState(() =>
    createWooQueryClient({ defaultOptions: { queries: { retry: retryStoreQuery } } }),
  );
  const [source, setSource] = useState<CommerceSource | null>(null);
  useEffect(() => {
    void transport.source().then(setSource);
  }, []);
  return (
    <StoreProvider transport={transport} queryClient={queryClient}>
      {source === "preview" ? (
        <p className="notice" role="status">
          Preview · Checkout is off in previews.
        </p>
      ) : null}
      {!source ? (
        <p role="status">Opening the store…</p>
      ) : page === "catalog" ? (
        <Catalog preview={source === "preview"} />
      ) : source === "preview" ? (
        <p>
          <a href="/">Keep browsing the bookshop →</a>
        </p>
      ) : page === "cart" ? (
        <Bag />
      ) : (
        <Confirmation />
      )}
    </StoreProvider>
  );
}

function Catalog({ preview }: { preview: boolean }) {
  const products = useProducts({ perPage: 100 });
  const add = useAddToCart();
  const [added, setAdded] = useState("");
  if (products.isPending) return <p role="status">Finding your next read…</p>;
  if (products.error) return <Notice error={products.error} />;
  const books = products.data.items.filter((product) => keys.has(product.sku));
  return (
    <section className="catalog" aria-label="Books">
      {books.map((product: Product) => (
        <article className="product" key={product.id}>
          <div
            className={`book-scene ${product.sku.endsWith("ebook") ? "book-scene-ebook" : "book-scene-print"}`}
            aria-hidden="true"
          >
            <div className="book-cover">
              <span>Quiet Press</span>
              <strong>
                The
                <br />
                Field
                <br />
                Guide
              </strong>
              <span>
                A companion for
                <br />
                the slower path.
              </span>
            </div>
            <span className="edition">
              {product.sku.endsWith("ebook")
                ? "A book that goes everywhere"
                : "Made for the margins"}
            </span>
          </div>
          <div className="product-heading">
            <h2>{product.name}</h2>
            <p>
              {money(
                product.prices.price,
                product.prices.currencyCode,
                product.prices.currencyMinorUnit,
              )}
            </p>
          </div>
          <p>
            {product.sku.endsWith("ebook")
              ? "A downloadable text edition for wherever you find yourself."
              : "Recycled paper, room for notes, and a place on your shelf."}
          </p>
          <button
            disabled={preview || add.isPending || !product.isInStock}
            onClick={() =>
              add.mutate(
                { id: product.id, quantity: 1 },
                { onSuccess: () => setAdded(product.name) },
              )
            }
          >
            {preview
              ? "Checkout is off in previews"
              : !product.isInStock
                ? "Sold out"
                : add.isPending
                  ? "Adding…"
                  : "Add to bag"}
          </button>
        </article>
      ))}
      {!books.length ? <p>The books are not available yet. Please come back soon.</p> : null}
      <div className="catalog-feedback">
        <Notice error={add.error} />
        {added ? (
          <p role="status">
            {added} is in your bag. <a href="/cart/">View bag →</a>
          </p>
        ) : null}
      </div>
    </section>
  );
}

function Bag() {
  const cart = useCart();
  const update = useUpdateCartItem();
  const remove = useRemoveCartItem();
  const checkout = useCheckoutUrl();
  if (cart.isPending) return <p role="status">Opening your bag…</p>;
  if (cart.error) return <Notice error={cart.error} />;
  const pending = update.isPending || remove.isPending || checkout.isPending;
  return (
    <section className="bag">
      {cart.data.items.length ? (
        <>
          <ul className="bag-items">
            {cart.data.items.map((item) => (
              <li key={item.key}>
                <div>
                  <h2>{item.name}</h2>
                  <p>
                    {money(
                      item.totals.lineTotal,
                      item.prices.currencyCode,
                      item.prices.currencyMinorUnit,
                    )}
                  </p>
                </div>
                <div className="quantity">
                  <button
                    aria-label={`Remove one ${item.name}`}
                    disabled={pending || item.quantity <= 1}
                    onClick={() => update.mutate({ key: item.key, quantity: item.quantity - 1 })}
                  >
                    −
                  </button>
                  <span aria-label="Quantity">{item.quantity}</span>
                  <button
                    aria-label={`Add one ${item.name}`}
                    disabled={pending}
                    onClick={() => update.mutate({ key: item.key, quantity: item.quantity + 1 })}
                  >
                    +
                  </button>
                  <button
                    className="text-button"
                    disabled={pending}
                    onClick={() => remove.mutate({ key: item.key })}
                  >
                    Remove
                  </button>
                </div>
              </li>
            ))}
          </ul>
          <div className="bag-total">
            <h2>Subtotal</h2>
            <p>
              {money(
                cart.data.totals.totalItems,
                cart.data.totals.currencyCode,
                cart.data.totals.currencyMinorUnit,
              )}
            </p>
          </div>
          <p>Shipping and any taxes are calculated at checkout.</p>
          <button
            disabled={pending}
            onClick={() =>
              checkout.mutate(undefined, { onSuccess: (url) => window.location.assign(url) })
            }
          >
            {checkout.isPending ? "Opening checkout…" : "Continue to checkout →"}
          </button>
        </>
      ) : (
        <p>
          Your bag is waiting for a good book. <a href="/">Browse the bookshop →</a>
        </p>
      )}
      <Notice error={update.error || remove.error || checkout.error} />
    </section>
  );
}

function Confirmation() {
  const [email, setEmail] = useState("");
  const [order, setOrder] = useState<OrderConfirmation | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [pending, setPending] = useState(false);
  const params = new URLSearchParams(window.location.search);
  const id = Number(params.get("order_id"));
  const key = params.get("order_key") ?? "";
  async function lookup() {
    setPending(true);
    setError(null);
    try {
      setOrder(await transport.order({ id, key, billingEmail: email }));
    } catch (error) {
      setError(error);
    } finally {
      setPending(false);
    }
  }
  if (!Number.isSafeInteger(id) || id <= 0 || !key)
    return (
      <p>
        Open the confirmation link from checkout to see your order.{" "}
        <a href="/">Back to the bookshop →</a>
      </p>
    );
  return (
    <section className="confirmation">
      {order ? (
        <>
          <h2>Thank you. Order #{order.id}</h2>
          <p>Status: {order.status.replaceAll("-", " ")}.</p>
          <ul className="bag-items">
            {order.items.map((item) => (
              <li key={item.key}>
                {item.name} × {item.quantity}
                <strong>
                  {money(
                    item.totals.lineTotal,
                    item.prices.currencyCode,
                    item.prices.currencyMinorUnit,
                  )}
                </strong>
              </li>
            ))}
          </ul>
          <p className="bag-total">
            Total{" "}
            <strong>
              {money(
                order.totals.totalPrice,
                order.totals.currencyCode,
                order.totals.currencyMinorUnit,
              )}
            </strong>
          </p>
          <p>Your order email includes your receipt and any available downloads.</p>
          <a
            className="button"
            href={`/checkout/order-received/${order.id}/?key=${encodeURIComponent(key)}`}
          >
            View receipt and downloads →
          </a>
        </>
      ) : (
        <form
          onSubmit={(event) => {
            event.preventDefault();
            void lookup();
          }}
        >
          <h2>Let’s find your order.</h2>
          <p>Enter the email you used at checkout to see the order details.</p>
          <label htmlFor="billing-email">Email address</label>
          <input
            id="billing-email"
            type="email"
            autoComplete="email"
            value={email}
            required
            onChange={(event) => setEmail(event.target.value)}
          />
          <button disabled={pending}>{pending ? "Finding your order…" : "View my order"}</button>
        </form>
      )}
      <Notice error={error} />
    </section>
  );
}
