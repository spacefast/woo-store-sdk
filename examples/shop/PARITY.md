# Vercel Shop parity

The shop tracks [`vercel/shop`](https://github.com/vercel/shop) at commit
`16f672bba377719073d6a2d848b2e2b5edd63cb9`, which was still upstream `main`
when this matrix was last verified.

## Default storefront surface

| Vercel Shop surface | Woo Store SDK implementation | Black-box proof |
| --- | --- | --- |
| Home catalog | Woo Store API products | Homepage and catalog RPC |
| Search, sort, price, sale filters | Woo product query adapter | Search and catalog RPC |
| Collections and all-products listing | Woo product categories | Collection listing and detail |
| Product detail and variants | Woo products, attributes, and variations | Product detail response |
| Quantity picker and add to cart | Woo cart mutations | Add and update quantity |
| Buy with Shop | Woo-native Buy now | Demo checkout plus hosted Woo checkout URL |
| Complementary and related products | Same-category Woo recommendations | Product detail recommendations |
| Bundle presentation | Woo bundle component model when bundle metadata exists | Component and transform tests |
| Cart overlay and cart page | Signed visitor Cart-Token session | Add, reread, update, and remove |
| Discount codes | Woo coupon mutations | Invalid-code rejection; positive codes use store-configured coupons |
| Pages, policies, and journal | WordPress REST content | Public content routes |
| Sitemap, Markdown, and `llms.txt` | Woo and WordPress content adapters | Public machine-readable routes |
| Customer accounts | Woo Store SDK customer JWT | Register, profile, address, logout, login, and account pages |
| Commerce webhooks | Signed Woo cache revalidation | HMAC revalidation request |

## Provider-specific substitutions

- Shopify Storefront and Customer Account APIs are replaced by Woo Store API
  plus the bundled WordPress feature plugin.
- Shop Pay is replaced by Woo hosted checkout in the SDK. The public demo's
  checkout is intentionally fake and sends no card data.
- Shopify analytics and consent are not loaded. Vercel Analytics and Speed
  Insights retain the upstream opt-in flags.
- The upstream AI agent and BotID paths are disabled by default upstream and in
  this demo. They are not counted as enabled default-storefront behavior.

Run `node e2e/shop-e2e.mjs <shop-url> <revalidation-secret>` against a production
Next build and a real Woo origin for the release-level receipt.
