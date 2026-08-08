import type { Address, WooQueryKey } from '../contracts'

export const STORE_API = '/wp-json/wc/store/v1'
export const PLUGIN_API = '/wp-json/woo-storefront/v1'

export function queryKey(resource: string, operation: string, params?: unknown): WooQueryKey {
  return params === undefined
    ? (['woo', resource, operation] as const)
    : (['woo', resource, operation, params] as const)
}

export function compactQuery(
  query: Record<string, string | number | boolean | undefined>,
): Record<string, string | number | boolean | undefined> {
  return Object.fromEntries(Object.entries(query).filter(([, value]) => value !== undefined))
}

export function addressPayload(address: Address): Record<string, string | undefined> {
  return {
    first_name: address.firstName,
    last_name: address.lastName,
    company: address.company,
    address_1: address.address1,
    address_2: address.address2,
    city: address.city,
    state: address.state,
    postcode: address.postcode,
    country: address.country,
    email: address.email,
    phone: address.phone,
  }
}
