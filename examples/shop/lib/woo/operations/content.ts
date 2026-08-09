import "server-only";

import { cache } from "react";

import type { Blog, BlogArticle, ContentPage, Image } from "@/lib/types";

interface Rendered {
  rendered: string;
}

interface WpMedia {
  alt_text?: string;
  media_details?: { height?: number; width?: number };
  source_url?: string;
}

interface WpCategory {
  id: number;
  name: string;
  slug: string;
}

interface WpContent {
  _embedded?: {
    author?: Array<{ name?: string }>;
    "wp:featuredmedia"?: WpMedia[];
  };
  content: Rendered;
  date: string;
  excerpt: Rendered;
  id: number;
  modified: string;
  slug: string;
  title: Rendered;
}

function storeUrl(): string {
  const value = process.env.WOO_STORE_URL;
  if (!value) throw new Error("WOO_STORE_URL is required");
  return value.replace(/\/+$/, "");
}

async function wpFetch<T>(path: string): Promise<T> {
  const response = await fetch(`${storeUrl()}/wp-json/wp/v2/${path}`, {
    next: { revalidate: 300 },
  });
  if (!response.ok) throw new Error(`WordPress content request failed with ${response.status}`);
  return response.json() as Promise<T>;
}

function text(html: string): string {
  return html.replace(/<[^>]*>/gu, " ").replace(/\s+/gu, " ").trim();
}

function image(post: WpContent): Image | null {
  const media = post._embedded?.["wp:featuredmedia"]?.[0];
  if (!media?.source_url) return null;
  return {
    altText: media.alt_text ?? "",
    height: media.media_details?.height ?? 1200,
    url: media.source_url,
    width: media.media_details?.width ?? 1200,
  };
}

function article(post: WpContent, category: WpCategory): BlogArticle {
  return {
    author: post._embedded?.author?.[0]?.name,
    blogHandle: category.slug,
    blogTitle: category.name,
    body: post.content.rendered,
    excerpt: text(post.excerpt.rendered),
    handle: post.slug,
    image: image(post),
    publishedAt: post.date,
    seo: { description: text(post.excerpt.rendered), title: text(post.title.rendered) },
    tags: [],
    title: text(post.title.rendered),
  };
}

export const getContentPage = cache(async (handle: string): Promise<ContentPage | null> => {
  const pages = await wpFetch<WpContent[]>(`pages?slug=${encodeURIComponent(handle)}&_embed=1`);
  const page = pages[0];
  if (!page) return null;
  return {
    body: page.content.rendered,
    handle: page.slug,
    seo: { description: text(page.excerpt.rendered), title: text(page.title.rendered) },
    title: text(page.title.rendered),
    updatedAt: page.modified,
  };
});

export const getBlog = cache(async (handle: string): Promise<Blog | null> => {
  const categories = await wpFetch<WpCategory[]>(`categories?slug=${encodeURIComponent(handle)}`);
  const category = categories[0];
  if (!category) return null;
  const posts = await wpFetch<WpContent[]>(
    `posts?categories=${category.id}&per_page=24&_embed=1`,
  );
  return {
    articles: posts.map((post) => article(post, category)),
    handle: category.slug,
    seo: { description: `${category.name} articles`, title: category.name },
    title: category.name,
  };
});

export const getBlogArticle = cache(
  async (blogHandle: string, articleHandle: string): Promise<BlogArticle | null> => {
    const blog = await getBlog(blogHandle);
    return blog?.articles.find((candidate) => candidate.handle === articleHandle) ?? null;
  },
);

export async function listContentHandles() {
  const [pages, categories] = await Promise.all([
    wpFetch<WpContent[]>("pages?per_page=100"),
    wpFetch<WpCategory[]>("categories?per_page=100"),
  ]);
  return {
    blogs: categories.map(({ slug }) => slug),
    pages: pages.map(({ slug }) => slug),
  };
}
