"use server";

import { redirect } from "next/navigation";

import type { Address } from "@woo/storefront-core";

import { woo } from "@/lib/woo/store";

function value(data: FormData, key: string): string {
  return String(data.get(key) ?? "").trim();
}

export async function loginAction(data: FormData) {
  await woo.auth.login.mutationFn({ email: value(data, "email"), password: value(data, "password") });
  redirect("/account");
}

export async function registerAction(data: FormData) {
  await woo.auth.register.mutationFn({
    email: value(data, "email"),
    password: value(data, "password"),
    firstName: value(data, "firstName"),
    lastName: value(data, "lastName"),
  });
  redirect("/account");
}

export async function logoutAction() {
  await woo.auth.logout.mutationFn();
  redirect("/");
}

export async function updateProfileAction(data: FormData) {
  await woo.customer.updateProfile.mutationFn({
    firstName: value(data, "firstName"),
    lastName: value(data, "lastName"),
  });
  redirect("/account/profile?saved=1");
}

export async function updateAddressAction(data: FormData) {
  const type = value(data, "type") === "billing" ? "billing" : "shipping";
  const address: Address = {
    firstName: value(data, "firstName"),
    lastName: value(data, "lastName"),
    company: value(data, "company"),
    address1: value(data, "address1"),
    address2: value(data, "address2"),
    city: value(data, "city"),
    state: value(data, "state"),
    postcode: value(data, "postcode"),
    country: value(data, "country"),
    email: value(data, "email"),
    phone: value(data, "phone"),
  };
  await woo.customer.updateAddress.mutationFn({ type, address });
  redirect("/account/addresses?saved=1");
}
