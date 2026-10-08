import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const project = process.env.COMMERCE_FIXTURE_PROJECT ?? 'woo-commerce-contract';
const composeArgs = ['compose', '-p', project, '-f', 'e2e/fixtures/commerce-compose.yaml'];
const compose = (...args) => execFileSync('docker', [...composeArgs, ...args], { cwd: root, stdio: 'inherit' });
if (process.argv[2] === 'down') {
  // This command removes only this named disposable fixture and its native test orders.
  compose('down', '--volumes');
  process.exit(0);
}
const directory = mkdtempSync(path.join(os.tmpdir(), 'commerce-fixture-'));
async function artifact(url, hash, filename) {
  const response = await fetch(url);
  assert.equal(response.status, 200, `Could not fetch ${filename}`);
  const bytes = new Uint8Array(await response.arrayBuffer());
  assert.equal(createHash('sha256').update(bytes).digest('hex'), hash, `${filename} integrity mismatch`);
  const file = path.join(directory, filename);
  writeFileSync(file, bytes);
  return file;
}
try {
  const wpCli = await artifact(
    'https://raw.githubusercontent.com/wp-cli/builds/05989a431e982caf17cd94508ff9b4c5fb12ae44/phar/wp-cli.phar',
    'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c', 'wp-cli.phar',
  );
  const woo = await artifact(
    'https://downloads.wordpress.org/plugin/woocommerce.11.2.0.zip',
    '66e91b45b057b64c73d4521111cea5bdc78c4c89de8fa2ca4b18b9422706d722', 'woocommerce.zip',
  );
  compose('up', '-d', '--wait', '--wait-timeout', '180');
  const container = execFileSync('docker', [...composeArgs, 'ps', '-q', 'wordpress'], { cwd: root, encoding: 'utf8' }).trim();
  const docker = (...args) => execFileSync('docker', args, { cwd: root, stdio: 'inherit' });
  const wp = (...args) => docker('exec', '--user', 'www-data', container, 'php', '/usr/local/bin/wp-cli.phar', ...args);
  docker('cp', wpCli, `${container}:/usr/local/bin/wp-cli.phar`);
  docker('cp', woo, `${container}:/tmp/woocommerce.zip`);
  docker('exec', container, 'sh', '-eu', '-c', `
    mkdir -p /var/www/commerce-private /var/www/html/wp-content/mu-plugins /var/www/html/wp-content/uploads
    chown www-data:www-data /var/www/commerce-private
    chmod 700 /var/www/commerce-private
    ln -sfn spacefast-dev/woo-storefront /var/www/html/wp-content/plugins/woo-storefront
    ln -sfn spacefast-dev/spacefast-commerce /var/www/html/wp-content/plugins/spacefast-commerce
    chown -R www-data:www-data /var/www/html/wp-content/uploads
  `);
  // The official image writes wp-config on its first HTTP request.
  const origin = `http://127.0.0.1:${process.env.COMMERCE_HTTP_PORT ?? '28983'}`;
  await fetch(origin);
  const installed = spawnSync('docker', ['exec', '--user', 'www-data', container, 'php', '/usr/local/bin/wp-cli.phar', 'core', 'is-installed'], { stdio: 'pipe' });
  if (installed.status === 1) {
    wp('core', 'install', `--url=${origin}`, '--title=Disposable Commerce Contract', '--admin_user=contract',
      '--admin_password=disposable-commerce-admin', '--admin_email=contract@example.test', '--skip-email');
  } else {
    assert.equal(installed.status, 0, 'WordPress availability check failed');
    wp('option', 'update', 'home', origin);
    wp('option', 'update', 'siteurl', origin);
  }
  wp('plugin', 'install', '/tmp/woocommerce.zip', '--activate', '--force');
  wp('plugin', 'activate', 'woo-storefront', 'spacefast-commerce');
  wp('rewrite', 'structure', '/%postname%/');
  wp('rewrite', 'flush', '--hard');
  docker('cp', 'e2e/fixtures/commerce-mail.php', `${container}:/var/www/html/wp-content/mu-plugins/commerce-test-mail.php`);
  execFileSync(process.execPath, ['e2e/commerce-e2e.mjs'], {
    cwd: root, stdio: 'inherit', env: { ...process.env, COMMERCE_WP_CONTAINER: container,
      COMMERCE_MAILPIT_URL: `http://127.0.0.1:${process.env.COMMERCE_MAIL_PORT ?? '28984'}` },
  });
  console.log('Fixture retained for inspection. Remove with pnpm test:e2e:commerce:down.');
} finally {
  rmSync(directory, { recursive: true, force: true });
}
