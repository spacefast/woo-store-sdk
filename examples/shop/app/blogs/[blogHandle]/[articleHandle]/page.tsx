import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { ArticlePage } from "@/components/blog/article-page";
import { getLocale } from "@/lib/params";
import { getBlogArticle } from "@/lib/woo/operations/content";

export const instant = false;

export async function generateMetadata({
  params,
}: PageProps<"/blogs/[blogHandle]/[articleHandle]">): Promise<Metadata> {
  const { blogHandle, articleHandle } = await params;
  const article = await getBlogArticle(blogHandle, articleHandle);
  if (!article) notFound();
  return { description: article.seo.description, title: article.seo.title };
}

export default async function BlogArticlePage({
  params,
}: PageProps<"/blogs/[blogHandle]/[articleHandle]">) {
  const [{ blogHandle, articleHandle }, locale] = await Promise.all([params, getLocale()]);
  const article = await getBlogArticle(blogHandle, articleHandle);
  if (!article) notFound();
  return <ArticlePage article={article} locale={locale} />;
}
