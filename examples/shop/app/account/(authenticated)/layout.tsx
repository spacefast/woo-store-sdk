import Link from "next/link";
import { notFound, redirect } from "next/navigation";

import { logoutAction } from "@/app/account/actions";
import { Button } from "@/components/ui/button";
import { Container } from "@/components/ui/container";
import { shopConfig } from "@/lib/config";
import { woo } from "@/lib/woo/store";

export const instant = false;

export default async function AccountLayout({ children }: { children: React.ReactNode }) {
  if (!shopConfig.auth.isEnabled) notFound();
  let customer;
  try {
    customer = await woo.customer.get();
  } catch {
    redirect("/account/login");
  }
  return (
    <Container className="grid w-full gap-8 py-12 md:grid-cols-[13rem_1fr]">
      <aside>
        <p className="font-medium">{customer.firstName || customer.email}</p>
        <p className="mb-5 text-xs text-muted-foreground">{customer.email}</p>
        <nav className="grid gap-2 text-sm">
          <Link href="/account/profile">Profile</Link>
          <Link href="/account/addresses">Addresses</Link>
          <Link href="/account/orders">Orders</Link>
        </nav>
        <form action={logoutAction} className="mt-6"><Button size="sm" type="submit" variant="outline">Sign out</Button></form>
      </aside>
      <main className="min-w-0">{children}</main>
    </Container>
  );
}
