import type { Collection, CollectionWithThumbnail } from "@/lib/types";
import { woo } from "@/lib/woo/store";
import { toCollection } from "@/lib/woo/transforms";

export async function getCollections({
  limit = 100,
}: { limit?: number; locale?: string } = {}): Promise<Collection[]> {
  return (await woo.categories.list()).slice(0, limit).map(toCollection);
}

export async function getCollection({
  handle,
}: {
  handle: string;
  locale?: string;
}): Promise<Collection | null> {
  try {
    return toCollection(await woo.categories.bySlug(handle));
  } catch (error) {
    if (error instanceof Error && error.name === "NotFoundError") return null;
    throw error;
  }
}

export async function getCollectionsListing(
  input: { locale?: string } = {},
): Promise<CollectionWithThumbnail[]> {
  return (await getCollections(input)).map((collection) => ({
    ...collection,
    thumbnail: collection.image ?? null,
  }));
}
