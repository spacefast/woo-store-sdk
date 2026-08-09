declare module 'next/cache' {
  export function revalidateTag(tag: string, profile: 'max'): void

  export function unstable_cache<TArgs extends unknown[], TResult>(
    callback: (...args: TArgs) => Promise<TResult>,
    keyParts?: string[],
    options?: { tags?: string[]; revalidate?: number | false },
  ): (...args: TArgs) => Promise<TResult>
}

declare module 'next/headers' {
  interface NextCookieStore {
    get(name: string): { value: string } | undefined
    set(
      name: string,
      value: string,
      options: {
        httpOnly: boolean
        secure: boolean
        sameSite: 'lax'
        maxAge: number
        path: string
      },
    ): void
    delete(name: string): void
  }

  export function cookies(): Promise<NextCookieStore>
}
