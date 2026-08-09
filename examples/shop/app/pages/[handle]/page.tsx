import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { RichTextPage } from "@/components/content/rich-text-page";
import { getContentPage } from "@/lib/woo/operations/content";

export const instant = false;

export async function generateMetadata({ params }: PageProps<"/pages/[handle]">): Promise<Metadata> {
  const { handle } = await params;
  const page = await getContentPage(handle);
  if (!page) notFound();
  return { description: page.seo.description, title: page.seo.title };
}

export default async function ContentPage({ params }: PageProps<"/pages/[handle]">) {
  const page = await getContentPage((await params).handle);
  if (!page) notFound();
  return <RichTextPage body={page.body} title={page.title} />;
}
