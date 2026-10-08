#!/usr/bin/env node
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const plugin = process.argv[2];
if (!['woo-storefront', 'spacefast-commerce'].includes(plugin)) {
  throw new Error('Usage: node scripts/package-plugin.mjs <woo-storefront|spacefast-commerce>');
}
const git = (args) => execFileSync('git', args, { cwd: root, encoding: 'utf8' }).trim();
const tree = `plugin/${plugin}`;
if (git(['status', '--porcelain', '--', tree])) {
  throw new Error('Commit plugin changes before packaging an immutable release.');
}
const entry = git(['show', `HEAD:${tree}/${plugin}.php`]);
const version = /^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)$/m.exec(entry)?.[1];
if (!version) throw new Error('Plugin entry point must declare a release version.');
const directory = path.join(root, 'dist', 'plugin-releases');
mkdirSync(directory, { recursive: true });
const filename = `${plugin}-${version}.zip`;
const destination = path.join(directory, filename);
// Archive the committed subtree with the plugin at ZIP root: directly installable
// by WordPress, with no Git clone, Node or Composer needed on a customer site.
execFileSync('git', ['archive', '--format=zip', `--prefix=${plugin}/`, '-o', destination, `HEAD:${tree}`], { cwd: root });
const bytes = readFileSync(destination);
const release = {
  id: plugin,
  version,
  archiveUrl: `https://github.com/spacefast/woo-store-sdk/releases/download/${plugin}%2Fv${version}/${filename}`,
  archiveBytes: bytes.length,
  archiveSha256: `sha256:${createHash('sha256').update(bytes).digest('hex')}`,
  sourceRevision: git(['rev-parse', 'HEAD']),
};
writeFileSync(`${destination}.json`, JSON.stringify(release, null, 2) + '\n');
process.stdout.write(JSON.stringify(release, null, 2) + '\n');
