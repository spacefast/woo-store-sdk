import { createStorefront } from "@woo/storefront-next";

function required(name: "WOO_SESSION_SECRET" | "WOO_STORE_URL"): string {
  const value = process.env[name];
  if (!value) throw new Error(`Missing ${name}. See examples/shop/.env.example.`);
  return value;
}

export const woo = createStorefront({
  sessionSecret: required("WOO_SESSION_SECRET"),
  url: required("WOO_STORE_URL"),
});
