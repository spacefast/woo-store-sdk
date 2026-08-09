import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { ArticleCard } from "@/components/blog/article-card";
import { Container } from "@/components/ui/container";
import { Page } from "@/components/ui/page";
import { getLocale } from "@/lib/params";
import { getBlog } from "@/lib/woo/operations/content";

export const instant = false;

export async function generateMetadata({ params }: PageProps<"/blogs/[blogHandle]">): Promise<Metadata> {
  const blog = await getBlog((await params).blogHandle);
  if (!blog) notFound();
  return { description: blog.seo.description, title: blog.seo.title };
}

export default async function BlogPage({ params }: PageProps<"/blogs/[blogHandle]">) {
  const [blog, locale] = await Promise.all([getBlog((await params).blogHandle), getLocale()]);
  if (!blog) notFound();
  return (
    <Page>
      <Container>
        <h1 className="mb-8 text-4xl">{blog.title}</h1>
        <div className="grid gap-10 md:grid-cols-2 lg:grid-cols-3">
          {blog.articles.map((article) => (
            <ArticleCard article={article} key={article.handle} locale={locale} />
          ))}
        </div>
      </Container>
    </Page>
  );
}
