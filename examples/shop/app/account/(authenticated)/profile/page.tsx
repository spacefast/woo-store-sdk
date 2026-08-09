import { updateProfileAction } from "@/app/account/actions";
import { woo } from "@/lib/woo/store";

export default async function ProfilePage({ searchParams }: PageProps<"/account/profile">) {
  const customer = await woo.customer.get();
  const { saved } = await searchParams;
  return (
    <section>
      <h1 className="text-3xl font-semibold">Profile</h1>
      {saved === "1" ? <p className="mt-4 text-sm text-emerald-700">Profile saved.</p> : null}
      <form action={updateProfileAction} className="mt-8 grid max-w-xl gap-4 rounded-lg border p-5 text-sm">
        <label className="grid gap-1">
          <span>First name</span>
          <input className="rounded-md border px-3 py-2" defaultValue={customer.firstName} name="firstName" />
        </label>
        <label className="grid gap-1">
          <span>Last name</span>
          <input className="rounded-md border px-3 py-2" defaultValue={customer.lastName} name="lastName" />
        </label>
        <label className="grid gap-1 text-muted-foreground">
          <span>Email</span>
          <input className="rounded-md border bg-muted px-3 py-2" disabled value={customer.email} />
        </label>
        <button className="w-fit rounded-md bg-black px-4 py-2 text-white" type="submit">Save profile</button>
      </form>
    </section>
  );
}
