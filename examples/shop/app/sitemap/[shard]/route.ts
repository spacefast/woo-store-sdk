import { storefrontSitemap } from "@/lib/sitemap";

export async function GET() {
  return new Response(await storefrontSitemap(), {
    headers: { "content-type": "application/xml; charset=utf-8" },
  });
}
