import { vi } from 'vitest'

export const cookieState = {
  values: new Map<string, string>(),
  setCalls: [] as Array<{
    name: string
    value: string
    options: unknown
  }>,
  deleteCalls: [] as Array<{
    name: string
    options: unknown
  }>,
}

vi.mock('../src/start-server', () => ({
  getCookie: vi.fn((name: string) => cookieState.values.get(name)),
  setCookie: vi.fn((name: string, value: string, options: unknown) => {
    cookieState.values.set(name, value)
    cookieState.setCalls.push({ name, value, options })
  }),
  deleteCookie: vi.fn((name: string, options: unknown) => {
    cookieState.values.delete(name)
    cookieState.deleteCalls.push({ name, options })
  }),
}))

export function resetCookieState(): void {
  cookieState.values.clear()
  cookieState.setCalls.length = 0
  cookieState.deleteCalls.length = 0
}
