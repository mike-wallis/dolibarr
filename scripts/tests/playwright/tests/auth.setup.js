import { test as setup, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { loadEnv } from '../helpers/load-env.js';

const authFile = path.resolve(import.meta.dirname, '../.auth/state.json');

setup('authenticate as admin', async ({ page }) => {
  const env = loadEnv();
  const baseUrl = env.DOLIBARR_URL || 'http://dolibarr.test';

  await page.goto(`${baseUrl}/index.php`);

  // Fill login form
  await page.fill('input[name="username"]', env.DOLIBARR_ADMIN_USER);
  await page.fill('input[name="password"]', env.DOLIBARR_ADMIN_PASS);
  await page.click('input[type="submit"]');

  // Confirm we're logged in — Dolibarr stays at index.php but the login form disappears
  // and the top navigation renders. Check for the username input being gone.
  await expect(page.locator('input[name="username"]')).not.toBeVisible({ timeout: 10_000 });
  // My Dashboard link appears in the left nav after login
  await expect(page.locator('text=My Dashboard')).toBeVisible({ timeout: 10_000 });

  // Save storage state for all other tests
  fs.mkdirSync(path.dirname(authFile), { recursive: true });
  await page.context().storageState({ path: authFile });
});
