import { updateAddressAction } from "@/app/account/actions";
import { Button } from "@/components/ui/button";
import { woo } from "@/lib/woo/store";

export default async function AddressesPage() {
  const customer = await woo.customer.get();
  return (
    <section>
      <h1 className="text-3xl font-semibold">Addresses</h1>
      <div className="mt-8 grid gap-8 lg:grid-cols-2">
        {(["shipping", "billing"] as const).map((type) => {
          const address = type === "shipping" ? customer.shippingAddress : customer.billingAddress;
          return (
            <form action={updateAddressAction} className="grid gap-3 rounded-lg border p-5" key={type}>
              <h2 className="text-xl font-medium capitalize">{type}</h2>
              <input name="type" type="hidden" value={type} />
              {[
                ["firstName", "First name"],
                ["lastName", "Last name"],
                ["company", "Company"],
                ["address1", "Address"],
                ["address2", "Apartment, suite, etc."],
                ["city", "City"],
                ["state", "State"],
                ["postcode", "Postal code"],
                ["country", "Country code"],
                ["email", "Email"],
                ["phone", "Phone"],
              ].map(([name, label]) => (
                <label className="grid gap-1 text-sm" key={name}>
                  {label}
                  <input className="h-10 rounded-md border px-3" defaultValue={address?.[name as keyof typeof address] ?? ""} name={name} />
                </label>
              ))}
              <Button type="submit">Save {type} address</Button>
            </form>
          );
        })}
      </div>
    </section>
  );
}
