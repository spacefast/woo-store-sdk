#!/usr/bin/env node

import { access, appendFile, mkdir, readFile, stat, writeFile } from 'node:fs/promises'
import { dirname, join, resolve } from 'node:path'
import { pathToFileURL } from 'node:url'
import { parseArgs } from 'node:util'

export type SupportedFramework = 'next' | 'start'

export interface InitOptions {
  dir?: string
  dryRun?: boolean
  cwd?: string
}

export type FileAction =
  | 'created'
  | 'updated'
  | 'skipped'
  | 'would-create'
  | 'would-update'

export interface InitFileResult {
  path: string
  action: FileAction
}

export interface InitResult {
  framework: SupportedFramework
  targetDir: string
  dryRun: boolean
  files: InitFileResult[]
  installCommand: string
}

export interface CliIo {
  log(message: string): void
  error(message: string): void
}

export interface PackageJson {
  packageManager?: unknown
  dependencies?: unknown
  devDependencies?: unknown
  optionalDependencies?: unknown
  peerDependencies?: unknown
}

interface TemplateFile {
  path: string
  contents: string
}

const DEPENDENCY_FIELDS = [
  'dependencies',
  'devDependencies',
  'optionalDependencies',
  'peerDependencies',
] as const

const ENV_VARIABLES = ['WOO_URL', 'WOO_SESSION_SECRET'] as const

const NEXT_TEMPLATES: readonly TemplateFile[] = [
  {
    path: 'lib/woo.ts',
    contents: `import { createStorefront } from '@woo/storefront-next'

export const woo = createStorefront({
  url: process.env.WOO_URL!,
  sessionSecret: process.env.WOO_SESSION_SECRET!,
})
`,
  },
  {
    path: 'app/api/store/[...path]/route.ts',
    contents: `import { woo } from '../../../../lib/woo'

export const { GET, POST } = woo.handlers
`,
  },
  {
    path: 'app/api/store/revalidate/route.ts',
    contents: `import { woo } from '../../../../lib/woo'

export const POST = woo.revalidateHandler()
`,
  },
]

const START_TEMPLATES: readonly TemplateFile[] = [
  {
    path: 'lib/woo.ts',
    contents: `import { createStorefront } from '@woo/storefront-start'

export const woo = createStorefront({
  url: process.env.WOO_URL!,
  sessionSecret: process.env.WOO_SESSION_SECRET!,
})
`,
  },
  {
    path: 'routes/api/store/$.ts',
    contents: `import { createFileRoute } from '@tanstack/react-router'

import { woo } from '../../../lib/woo'

export const Route = createFileRoute('/api/store/$')({
  server: woo.serverRoute,
})
`,
  },
  {
    path: 'routes/api/store/revalidate.ts',
    contents: `import { createFileRoute } from '@tanstack/react-router'

import { woo } from '../../../lib/woo'

export const Route = createFileRoute('/api/store/revalidate')({
  server: { handlers: { POST: woo.revalidateRoute } },
})
`,
  },
]

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function errorCode(error: unknown): string | undefined {
  if (!isRecord(error)) return undefined
  return typeof error.code === 'string' ? error.code : undefined
}

function hasDependency(packageJson: PackageJson, dependency: string): boolean {
  return DEPENDENCY_FIELDS.some((field) => {
    const dependencies = packageJson[field]
    return isRecord(dependencies) && typeof dependencies[dependency] === 'string'
  })
}

/** Detect the target framework from the app's declared dependencies. */
export function detectFramework(packageJson: PackageJson): SupportedFramework {
  const hasNext = hasDependency(packageJson, 'next')
  const hasStart = hasDependency(packageJson, '@tanstack/react-start')

  if (hasNext && hasStart) {
    throw new Error(
      'Could not choose a framework: package.json declares both "next" and "@tanstack/react-start".',
    )
  }

  if (hasNext) return 'next'
  if (hasStart) return 'start'

  throw new Error(
    'Unsupported app: package.json must declare either "next" or "@tanstack/react-start" as a dependency.',
  )
}

async function readPackageJson(targetDir: string): Promise<PackageJson> {
  const packagePath = join(targetDir, 'package.json')
  let source: string

  try {
    source = await readFile(packagePath, 'utf8')
  } catch (error) {
    if (errorCode(error) === 'ENOENT') {
      throw new Error(`No package.json found in ${targetDir}. Use --dir to select an app directory.`)
    }
    throw error
  }

  try {
    const parsed: unknown = JSON.parse(source)
    if (!isRecord(parsed)) throw new Error('package.json must contain a JSON object')
    return {
      packageManager: parsed.packageManager,
      dependencies: parsed.dependencies,
      devDependencies: parsed.devDependencies,
      optionalDependencies: parsed.optionalDependencies,
      peerDependencies: parsed.peerDependencies,
    }
  } catch (error) {
    const detail = error instanceof Error ? error.message : String(error)
    throw new Error(`Could not parse ${packagePath}: ${detail}`)
  }
}

async function directoryExists(path: string): Promise<boolean> {
  try {
    return (await stat(path)).isDirectory()
  } catch (error) {
    if (errorCode(error) === 'ENOENT') return false
    throw error
  }
}

async function fileExists(path: string): Promise<boolean> {
  try {
    await access(path)
    return true
  } catch (error) {
    if (errorCode(error) === 'ENOENT') return false
    throw error
  }
}

async function createWithoutOverwrite(
  absolutePath: string,
  relativePath: string,
  contents: string,
  dryRun: boolean,
): Promise<InitFileResult> {
  if (await fileExists(absolutePath)) return { path: relativePath, action: 'skipped' }
  if (dryRun) return { path: relativePath, action: 'would-create' }

  await mkdir(dirname(absolutePath), { recursive: true })

  try {
    await writeFile(absolutePath, contents, { encoding: 'utf8', flag: 'wx' })
    return { path: relativePath, action: 'created' }
  } catch (error) {
    if (errorCode(error) === 'EEXIST') return { path: relativePath, action: 'skipped' }
    throw error
  }
}

function missingEnvVariables(source: string): readonly string[] {
  return ENV_VARIABLES.filter((name) => {
    const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    return !new RegExp(`^\\s*(?:export\\s+)?${escapedName}\\s*=`, 'm').test(source)
  })
}

async function updateEnvExample(targetDir: string, dryRun: boolean): Promise<InitFileResult> {
  const relativePath = '.env.example'
  const absolutePath = join(targetDir, relativePath)
  let source = ''
  let exists = true

  try {
    source = await readFile(absolutePath, 'utf8')
  } catch (error) {
    if (errorCode(error) === 'ENOENT') {
      exists = false
    } else {
      throw error
    }
  }

  const missing = missingEnvVariables(source)
  if (missing.length === 0) return { path: relativePath, action: 'skipped' }

  const separator = source.length > 0 && !source.endsWith('\n') ? '\n' : ''
  const addition = `${separator}${missing.map((name) => `${name}=\n`).join('')}`

  if (dryRun) {
    return { path: relativePath, action: exists ? 'would-update' : 'would-create' }
  }

  if (exists) {
    await appendFile(absolutePath, addition, 'utf8')
    return { path: relativePath, action: 'updated' }
  }

  try {
    await writeFile(absolutePath, addition, { encoding: 'utf8', flag: 'wx' })
    return { path: relativePath, action: 'created' }
  } catch (error) {
    if (errorCode(error) !== 'EEXIST') throw error
    return updateEnvExample(targetDir, false)
  }
}

async function detectPackageManager(targetDir: string, packageJson: PackageJson): Promise<string> {
  if (typeof packageJson.packageManager === 'string') {
    const manager = packageJson.packageManager.split('@', 1)[0]
    if (manager === 'npm' || manager === 'pnpm' || manager === 'yarn' || manager === 'bun') {
      return manager
    }
  }

  const lockfiles = [
    ['pnpm-lock.yaml', 'pnpm'],
    ['yarn.lock', 'yarn'],
    ['bun.lock', 'bun'],
    ['bun.lockb', 'bun'],
    ['package-lock.json', 'npm'],
  ] as const

  for (const [lockfile, manager] of lockfiles) {
    if (await fileExists(join(targetDir, lockfile))) return manager
  }

  return 'npm'
}

function installCommand(packageManager: string, packageName: string): string {
  if (packageManager === 'npm') return `npm install ${packageName}`
  return `${packageManager} add ${packageName}`
}

/** Scaffold Woo Storefront into an existing application. */
export async function initStorefront(options: InitOptions = {}): Promise<InitResult> {
  const cwd = resolve(options.cwd ?? process.cwd())
  const targetDir = resolve(cwd, options.dir ?? '.')
  const dryRun = options.dryRun ?? false
  const packageJson = await readPackageJson(targetDir)
  const framework = detectFramework(packageJson)
  const sourcePrefix = (await directoryExists(join(targetDir, 'src'))) ? 'src/' : ''
  const templates = framework === 'next' ? NEXT_TEMPLATES : START_TEMPLATES
  const files: InitFileResult[] = []

  for (const template of templates) {
    const relativePath = `${sourcePrefix}${template.path}`
    files.push(
      await createWithoutOverwrite(
        join(targetDir, relativePath),
        relativePath,
        template.contents,
        dryRun,
      ),
    )
  }

  files.push(await updateEnvExample(targetDir, dryRun))

  const adapterPackage = framework === 'next' ? '@woo/storefront-next' : '@woo/storefront-start'
  const packageManager = await detectPackageManager(targetDir, packageJson)

  return {
    framework,
    targetDir,
    dryRun,
    files,
    installCommand: installCommand(packageManager, adapterPackage),
  }
}

function frameworkLabel(framework: SupportedFramework): string {
  return framework === 'next' ? 'Next.js' : 'TanStack Start'
}

function printResult(result: InitResult, io: CliIo): void {
  io.log(
    `${result.dryRun ? 'Dry run: would configure' : 'Configured'} Woo Storefront for ${frameworkLabel(result.framework)} in ${result.targetDir}`,
  )

  for (const file of result.files) {
    if (file.action === 'skipped') {
      io.log(`  warning  ${file.path} already exists; skipped`)
    } else {
      io.log(`  ${file.action.padEnd(12)} ${file.path}`)
    }
  }

  io.log('\nNext steps:')
  io.log(`  1. Install the adapter: ${result.installCommand}`)
  io.log('  2. Set WOO_URL and a strong WOO_SESSION_SECRET in your local environment.')
  io.log(
    '  3. Install and activate the Woo Storefront feature plugin from plugin/woo-storefront.',
  )
}

export async function runCli(
  args: readonly string[] = process.argv.slice(2),
  io: CliIo = console,
): Promise<number> {
  try {
    const parsed = parseArgs({
      args: [...args],
      allowPositionals: true,
      strict: true,
      options: {
        dir: { type: 'string' },
        'dry-run': { type: 'boolean', default: false },
      },
    })

    const command = parsed.positionals[0] ?? 'init'
    if (command !== 'init' || parsed.positionals.length > 1) {
      throw new Error(
        `Unknown command: ${parsed.positionals.join(' ')}. Usage: woo-storefront [init] [--dir <path>] [--dry-run]`,
      )
    }

    const result = await initStorefront({
      dir: parsed.values.dir,
      dryRun: parsed.values['dry-run'],
    })
    printResult(result, io)
    return 0
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error)
    io.error(`woo-storefront: ${message}`)
    return 1
  }
}

const isDirectExecution =
  process.argv[1] !== undefined && pathToFileURL(resolve(process.argv[1])).href === import.meta.url

if (isDirectExecution) {
  process.exitCode = await runCli()
}
