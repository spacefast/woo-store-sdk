import Image from "next/image";
import Link from "next/link";

import { Container } from "@/components/ui/container";
import { Page } from "@/components/ui/page";
import { Prose } from "@/components/ui/prose";
import { Sections } from "@/components/ui/sections";
import type { BlogArticle } from "@/lib/types";

export function ArticlePage({ article, locale }: { article: BlogArticle; locale: string }) {
  const publishedAt = new Intl.DateTimeFormat(locale, { dateStyle: "long" }).format(
    new Date(article.publishedAt),
  );
  return (
    <Page>
      <Container className="max-w-4xl">
        <Sections className="gap-5">
          <header className="grid gap-4 text-center">
            <Link className="justify-self-center text-sm text-muted-foreground" href={`/blogs/${article.blogHandle}`}>
              {article.blogTitle}
            </Link>
            <h1 className="text-3xl tracking-tight sm:text-5xl">{article.title}</h1>
            <time className="text-sm text-muted-foreground" dateTime={article.publishedAt}>
              {publishedAt}
            </time>
          </header>
          {article.image ? (
            <div className="relative aspect-3/2 overflow-hidden rounded-xl">
              <Image alt={article.image.altText} className="object-cover" fill priority src={article.image.url} />
            </div>
          ) : null}
          <Prose className="mx-auto w-full max-w-2xl">
            <div
              // oxlint-disable-next-line react/no-danger -- WordPress filters published post content.
              dangerouslySetInnerHTML={{ __html: article.body ?? "" }}
            />
          </Prose>
        </Sections>
      </Container>
    </Page>
  );
}
