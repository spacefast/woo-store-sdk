/**
 * Keep TanStack Start's request-context import isolated in this module. This
 * makes the rest of the adapter testable without booting a Start application.
 */
export { deleteCookie, getCookie, setCookie } from '@tanstack/react-start/server'
