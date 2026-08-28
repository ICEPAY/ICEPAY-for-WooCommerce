import { defineConfig, devices } from '@playwright/test';
import { execSync } from 'node:child_process';

// When BASE_URL is not provided, derive it from WordPress via WP-CLI so a local run needs no
// configuration: `npm run test:e2e` just works. WP_CLI is the command used to reach WP-CLI
// (default: the DDEV web container); override it for another stack, e.g.
// WP_CLI="docker compose exec -T app wp". CI always sets BASE_URL explicitly (the tunnel URL),
// so this fallback never runs there.
const resolveBaseUrlFromWpCli = (): string | undefined => {
  const wpCli = process.env.WP_CLI ?? 'ddev wp';
  try {
    const output = execSync(`${wpCli} option get home`, {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
    });
    return output
      .split('\n')
      .map((line) => line.trim())
      .reverse()
      .find((line) => /^https?:\/\//.test(line));
  } catch {
    return undefined;
  }
};

if (process.env.BASE_URL === undefined || process.env.BASE_URL === '') {
  const derived = resolveBaseUrlFromWpCli();
  if (derived !== undefined && derived !== '') {
    process.env.BASE_URL = derived;
  }
}

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  maxFailures: 2,
  reporter: process.env.CI
    ? [['github'], ['list', { printSteps: true }], ['html', { open: 'never' }]]
    : [['list', { printSteps: true }], ['html', { open: 'never' }]],
  timeout: 60000,
  use: {
    baseURL: process.env.BASE_URL || 'https://icepay.test',
    ignoreHTTPSErrors: true,
    trace: 'on-first-retry',
  },
  // The suite runs as a guest (real customers are guests). Logging in as admin made
  // WooCommerce show a saved-address summary in the Blocks checkout (hiding the fields)
  // and added admin-bar/Heartbeat noise, so no storageState/auth setup is used. Order
  // status is verified via the test-helper endpoint, not wp-admin.
  projects: [
    {
      name: 'blocks',
      testDir: './tests/e2e/blocks',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'classic',
      testDir: './tests/e2e/classic',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
