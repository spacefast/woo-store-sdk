import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";
import { Suspense } from "react";

import { CollectionCard } from "@/components/collections/collection-card";
import { Container } from "@/components/ui/container";
import { Page } from "@/components/ui/page";
import { Sections } from "@/components/ui/sections";
import { getLocale } from "@/lib/params";
import { buildAlternates, buildOpenGraph } from "@/lib/seo";
import { getCollectionsListing } from "@/lib/woo/operations/collections";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("collections");
  const title = t("title");
  const description = t("description");

  return {
    title,
    description,
    alternates: buildAlternates({
      pathname: "/collections",
    }),
    openGraph: buildOpenGraph({
      title,
      description,
      url: "/collections",
      type: "website",
    }),
    twitter: {
      card: "summary_large_image",
      title,
      description,
      images: ["/og-default.png"],
    },
  };
}

export default async function CollectionsPage() {
  const [locale, t] = await Promise.all([getLocale(), getTranslations("collections")]);

  return (
    <Page className="pt-2.5 md:pt-10">
      <Container>
        <Sections className="gap-5">
          <h1 className="text-3xl sm:text-4xl md:text-5xl">{t("title")}</h1>

          <Suspense fallback={<CollectionsSkeleton />}>
            <CollectionsGrid
              emptyLabel={t("empty")}
              locale={locale}
              viewCollectionLabel={t("viewCollection")}
            />
          </Suspense>
        </Sections>
      </Container>
    </Page>
  );
}

async function CollectionsGrid({
  emptyLabel,
  locale,
  viewCollectionLabel,
}: {
  emptyLabel: string;
  locale: string;
  viewCollectionLabel: string;
}) {
  const collections = await getCollectionsListing({ locale });
  if (collections.length === 0) return <p className="text-muted-foreground">{emptyLabel}</p>;

  return (
    <div className="grid grid-cols-2 gap-5 sm:grid-cols-3 lg:grid-cols-4">
      {collections.map((collection) => (
        <CollectionCard
          key={collection.handle}
          collection={collection}
          viewCollectionLabel={viewCollectionLabel}
        />
      ))}
    </div>
  );
}

function CollectionsSkeleton() {
  return (
    <div className="grid grid-cols-2 gap-5 sm:grid-cols-3 lg:grid-cols-4" aria-hidden>
      {Array.from({ length: 4 }, (_, index) => (
        <div key={index} className="aspect-square animate-pulse rounded-xl bg-muted" />
      ))}
    </div>
  );
}
