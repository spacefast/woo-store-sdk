import { notFound } from "next/navigation";

import { loginAction } from "@/app/account/actions";
import { Button } from "@/components/ui/button";
import { Container } from "@/components/ui/container";
import { shopConfig } from "@/lib/config";

export default function LoginPage() {
  if (!shopConfig.auth.isEnabled) notFound();
  return (
    <Container className="w-full max-w-md py-16">
      <h1 className="text-3xl font-semibold">Sign in</h1>
      <form action={loginAction} className="mt-8 grid gap-4">
        <label className="grid gap-2 text-sm">Email<input className="h-11 rounded-md border px-3" name="email" required type="email" /></label>
        <label className="grid gap-2 text-sm">Password<input className="h-11 rounded-md border px-3" minLength={8} name="password" required type="password" /></label>
        <Button className="h-11" type="submit">Sign in</Button>
      </form>
      <p className="mt-5 text-sm text-muted-foreground">
        New customer? <a className="underline" href="/account/register">Create an account</a>
      </p>
    </Container>
  );
}
