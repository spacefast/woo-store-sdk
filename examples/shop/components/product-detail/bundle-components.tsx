import Image from "next/image";
import Link from "next/link";

import { ImagePlaceholder } from "@/components/ui/image-placeholder";
import type { ProductVariantComponent, ProductVariantReference } from "@/lib/types";

interface BundleListItem {
  href: string;
  image: ProductVariantReference["image"];
  key: string;
  quantity?: number;
  title: string;
}

export function BundleComponents({
  components,
  title,
}: {
  components: ProductVariantComponent[];
  title: string;
}) {
  return (
    <BundleProductList
      items={components.map(({ quantity, variant }) => ({
        href: `/products/${variant.product.handle}`,
        image: variant.image ?? variant.product.featuredImage,
        key: variant.id,
        quantity,
        title: variant.product.title,
      }))}
      title={title}
    />
  );
}

export function BundleParents({
  title,
  variants,
}: {
  title: string;
  variants: ProductVariantReference[];
}) {
  const byProduct = new Map(variants.map((variant) => [variant.product.handle, variant]));
  return (
    <BundleProductList
      items={[...byProduct.values()].map((variant) => ({
        href: `/products/${variant.product.handle}`,
        image: variant.product.featuredImage ?? variant.image,
        key: variant.product.handle,
        title: variant.product.title,
      }))}
      title={title}
    />
  );
}

function BundleProductList({ items, title }: { items: BundleListItem[]; title: string }) {
  if (items.length === 0) return null;
  return (
    <div className="grid gap-2.5" data-slot="bundle-components">
      <h2 className="text-sm font-medium text-foreground/70">{title}</h2>
      <ul className="grid gap-2.5">
        {items.map((item) => (
          <li key={item.key}>
            <Link
              className="flex items-center gap-2.5 rounded-lg border p-2.5 transition-colors hover:border-foreground/30"
              href={item.href}
            >
              {item.image ? (
                <Image
                  alt={item.image.altText || item.title}
                  className="size-12 rounded-md object-cover"
                  height={48}
                  src={item.image.url}
                  width={48}
                />
              ) : (
                <ImagePlaceholder className="size-12 shrink-0 rounded-md" />
              )}
              <span className="min-w-0 flex-1 truncate text-sm font-medium">{item.title}</span>
              {item.quantity && item.quantity > 1 ? (
                <span className="text-sm text-foreground/50">x{item.quantity}</span>
              ) : null}
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}
