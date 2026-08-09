import { notFound } from "next/navigation";

import { registerAction } from "@/app/account/actions";
import { Button } from "@/components/ui/button";
import { Container } from "@/components/ui/container";
import { shopConfig } from "@/lib/config";

export default function RegisterPage() {
  if (!shopConfig.auth.isEnabled) notFound();
  return (
    <Container className="w-full max-w-md py-16">
      <h1 className="text-3xl font-semibold">Create account</h1>
      <form action={registerAction} className="mt-8 grid gap-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="grid gap-2 text-sm">First name<input className="h-11 rounded-md border px-3" name="firstName" /></label>
          <label className="grid gap-2 text-sm">Last name<input className="h-11 rounded-md border px-3" name="lastName" /></label>
        </div>
        <label className="grid gap-2 text-sm">Email<input className="h-11 rounded-md border px-3" name="email" required type="email" /></label>
        <label className="grid gap-2 text-sm">Password<input className="h-11 rounded-md border px-3" minLength={8} name="password" required type="password" /></label>
        <Button className="h-11" type="submit">Create account</Button>
      </form>
    </Container>
  );
}
