import { execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const container = process.env.COMMERCE_WP_CONTAINER ?? 'sell-managed-woo-wp';
const directory = mkdtempSync(path.join(os.tmpdir(), 'commerce-contract-'));
const docker = (...args) => execFileSync('docker', args, { cwd: root, stdio: 'inherit' });
const wp = (...args) => docker('exec', '--user', 'www-data', container, 'php', '/usr/local/bin/wp-cli.phar', ...args);
try {
  for (const name of ['commerce-contract.php', 'commerce-refund-contract.php']) {
    docker('cp', `e2e/${name}`, `${container}:/tmp/${name}`);
  }
  wp('option', 'delete', 'woocommerce_feature_fulfillments_enabled');
  wp('eval-file', '/tmp/commerce-contract.php', 'prepare-only');
  for (const storage of ['no', 'yes']) {
    // Use native synchronization before changing the authoritative storage lane.
    wp('wc', 'hpos', 'sync');
    wp('option', 'update', 'woocommerce_custom_orders_table_enabled', storage);
    wp('eval-file', '/tmp/commerce-contract.php');
    const fixture = path.join(directory, 'downloads.json');
    docker('cp', `${container}:/tmp/commerce-download-contract.json`, fixture);
    execFileSync(process.execPath, ['e2e/commerce-http-contract.mjs', fixture], { cwd: root, stdio: 'inherit' });
    wp('eval-file', '/tmp/commerce-refund-contract.php');
    execFileSync(process.execPath, ['e2e/commerce-http-contract.mjs', fixture, 'refunded'], { cwd: root, stdio: 'inherit' });
    console.log(`PASS: native order storage ${storage === 'yes' ? 'HPOS' : 'CPT'}`);
  }
} finally {
  rmSync(directory, { recursive: true, force: true });
}
