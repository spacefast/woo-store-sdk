import { mkdtemp, mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

import { afterEach, describe, expect, it } from 'vitest'

import { detectFramework, initStorefront, runCli } from '../src/index.js'

const tempDirectories: string[] = []

afterEach(async () => {
  await Promise.all(
    tempDirectories.splice(0).map((directory) => rm(directory, { recursive: true, force: true })),
  )
})

async function createApp(
  dependencies: Record<string, string>,
  options: { src?: boolean; packageManager?: string } = {},
): Promise<string> {
  const directory = await mkdtemp(join(tmpdir(), 'woo-storefront-cli-'))
  tempDirectories.push(directory)

  await writeFile(
    join(directory, 'package.json'),
    JSON.stringify({
      private: true,
      dependencies,
      ...(options.packageManager ? { packageManager: options.packageManager } : {}),
    }),
  )

  if (options.src) await mkdir(join(directory, 'src'))
  return directory
}

describe('detectFramework', () => {
  it('detects Next.js and TanStack Start dependencies', () => {
    expect(detectFramework({ dependencies: { next: '^16.0.0' } })).toBe('next')
    expect(
      detectFramework({ devDependencies: { '@tanstack/react-start': '^1.0.0' } }),
    ).toBe('start')
  })

  it('errors clearly when neither supported framework is declared', () => {
    expect(() => detectFramework({ dependencies: { react: '^19.0.0' } })).toThrow(
      'must declare either "next" or "@tanstack/react-start"',
    )
  })

  it('errors rather than guessing when both frameworks are declared', () => {
    expect(() =>
      detectFramework({
        dependencies: {
          next: '^16.0.0',
          '@tanstack/react-start': '^1.0.0',
        },
      }),
    ).toThrow('declares both')
  })
})

describe('initStorefront', () => {
  it('generates the Next.js adapter, handlers, revalidation route, and env example', async () => {
    const directory = await createApp({ next: '^16.0.0' }, { packageManager: 'pnpm@9.12.0' })

    const result = await initStorefront({ dir: directory })

    expect(result.framework).toBe('next')
    expect(result.installCommand).toBe('pnpm add @woo/storefront-next')
    await expect(readFile(join(directory, 'lib/woo.ts'), 'utf8')).resolves.toContain(
      "from '@woo/storefront-next'",
    )
    await expect(
      readFile(join(directory, 'app/api/store/[...path]/route.ts'), 'utf8'),
    ).resolves.toContain('export const { GET, POST } = woo.handlers')
    await expect(
      readFile(join(directory, 'app/api/store/revalidate/route.ts'), 'utf8'),
    ).resolves.toContain('export const { POST } = woo.revalidateHandlers')
    await expect(readFile(join(directory, '.env.example'), 'utf8')).resolves.toBe(
      'WOO_URL=\nWOO_SESSION_SECRET=\n',
    )
  })

  it('generates TanStack Start file routes using the adapter server routes', async () => {
    const directory = await createApp({ '@tanstack/react-start': '^1.0.0' })

    const result = await initStorefront({ dir: directory })

    expect(result.framework).toBe('start')
    await expect(readFile(join(directory, 'lib/woo.ts'), 'utf8')).resolves.toContain(
      "from '@woo/storefront-start'",
    )
    await expect(readFile(join(directory, 'routes/api/store/$.ts'), 'utf8')).resolves.toContain(
      'server: woo.serverRoute',
    )
    await expect(
      readFile(join(directory, 'routes/api/store/revalidate.ts'), 'utf8'),
    ).resolves.toContain('server: woo.revalidateServerRoute')
  })

  it('places generated source files under src when the app uses a src layout', async () => {
    const directory = await createApp({ next: '^16.0.0' }, { src: true })

    await initStorefront({ dir: directory })

    await expect(readFile(join(directory, 'src/lib/woo.ts'), 'utf8')).resolves.toContain(
      'createStorefront',
    )
    await expect(
      readFile(join(directory, 'src/app/api/store/[...path]/route.ts'), 'utf8'),
    ).resolves.toContain('woo.handlers')
    await expect(readdir(directory)).resolves.not.toContain('lib')
  })

  it('never overwrites generated files and only appends missing env variables', async () => {
    const directory = await createApp({ next: '^16.0.0' })
    const wooPath = join(directory, 'lib/woo.ts')
    await mkdir(join(directory, 'lib'))
    await writeFile(wooPath, 'keep this file\n')
    await writeFile(join(directory, '.env.example'), 'EXISTING=value\nWOO_URL=https://shop.test\n')

    const result = await initStorefront({ dir: directory })

    await expect(readFile(wooPath, 'utf8')).resolves.toBe('keep this file\n')
    const env = await readFile(join(directory, '.env.example'), 'utf8')
    expect(env).toBe(
      'EXISTING=value\nWOO_URL=https://shop.test\nWOO_SESSION_SECRET=\n',
    )
    expect(env.match(/^WOO_URL=/gm)).toHaveLength(1)
    expect(result.files).toContainEqual({ path: 'lib/woo.ts', action: 'skipped' })
  })

  it('supports dry-run without writing any generated files', async () => {
    const directory = await createApp({ '@tanstack/react-start': '^1.0.0' })
    const output: string[] = []

    const exitCode = await runCli(['--dir', directory, '--dry-run'], {
      log: (message) => output.push(message),
      error: (message) => output.push(message),
    })

    expect(exitCode).toBe(0)
    expect(output.join('\n')).toContain('Dry run: would configure')
    expect(await readdir(directory)).toEqual(['package.json'])
  })
})
