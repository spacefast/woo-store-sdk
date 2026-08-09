import { UserRoundCheckIcon, UserRoundIcon } from "lucide-react";
import Link from "next/link";

import { woo } from "@/lib/woo/store";

export async function NavAccount() {
  try {
    await woo.customer.get();
    return (
      <Link aria-label="Account" href="/account">
        <UserRoundCheckIcon className="size-5" />
      </Link>
    );
  } catch {
    return (
      <Link aria-label="Sign in" href="/account/login">
        <UserRoundIcon className="size-5" />
      </Link>
    );
  }
}

export function NavAccountFallback() {
  return <UserRoundIcon className="size-5" />;
}
