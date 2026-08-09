import { getTranslations } from "next-intl/server";
import Link from "next/link";

import { Container } from "@/components/ui/container";
import { shopConfig } from "@/lib/config";

export async function Footer() {
  const t = await getTranslations("footer");

  return (
    <footer>
      <Container className="pt-20 pb-10">
        <nav aria-label="Footer" className="mb-6 flex flex-wrap justify-center gap-x-5 gap-y-2 text-sm sm:justify-start">
          <Link href="/pages/about">About</Link>
          <Link href="/blogs/journal">Journal</Link>
          <Link href="/policies/privacy-policy">Privacy</Link>
          <Link href="/policies/refund-policy">Refunds</Link>
          <Link href="/policies/shipping-policy">Shipping</Link>
          <Link href="/policies/terms">Terms</Link>
        </nav>
        <p className="text-center text-sm text-muted-foreground sm:text-left">
          {t("copyright", { name: shopConfig.site.name })}
        </p>
      </Container>
    </footer>
  );
}
