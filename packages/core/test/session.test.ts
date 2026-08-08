import { describe, expect, it } from 'vitest'

import { InMemorySessionStore, SessionCodec } from '../src/index'

describe('SessionCodec', () => {
  it('round-trips a signed session using a base64url cookie value', async () => {
    const codec = new SessionCodec()
    const session = { cacheId: 'visitor-a', cartToken: 'cart.jwt', customerToken: 'customer.jwt' }

    const sealed = await codec.seal(session, 'a sufficiently long application secret')

    expect(sealed).toMatch(/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/u)
    await expect(codec.unseal(sealed, 'a sufficiently long application secret')).resolves.toEqual(session)
  })

  it('rejects tampering and the wrong secret', async () => {
    const codec = new SessionCodec()
    const sealed = await codec.seal({ cacheId: 'visitor-b' }, 'right-secret')
    const [payload, mac] = sealed.split('.')
    const changedMac = `${mac?.slice(0, -1)}${mac?.endsWith('A') ? 'B' : 'A'}`

    await expect(codec.unseal(`${payload}.${changedMac}`, 'right-secret')).resolves.toBeNull()
    await expect(codec.unseal(sealed, 'wrong-secret')).resolves.toBeNull()
  })
})

describe('InMemorySessionStore', () => {
  it('returns defensive copies and rotates cacheId when cleared', async () => {
    const store = new InMemorySessionStore({ cacheId: 'original', cartToken: 'cart' })
    const read = await store.read()
    read.cartToken = 'mutated-copy'

    expect((await store.read()).cartToken).toBe('cart')
    await store.clear()
    expect(await store.read()).toEqual({ cacheId: expect.not.stringMatching(/^original$/u) })
  })
})
