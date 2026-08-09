import { getTranslations } from "next-intl/server";

import { Container } from "@/components/ui/container";
import { shopConfig } from "@/lib/config";

export async function Footer() {
  const t = await getTranslations("footer");

  return (
    <footer>
      <Container className="pt-20 pb-10">
        <p className="text-center text-sm text-muted-foreground sm:text-left">
          {t("copyright", { name: shopConfig.site.name })}
        </p>
      </Container>
    </footer>
  );
}
