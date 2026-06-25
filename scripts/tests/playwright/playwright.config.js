import { defineConfig, devices } from '@playwright/test';
import { loadEnv } from './helpers/load-env.js';

const env = loadEnv();

export default defineConfig({
  testDir: './tests',
  timeout: 30_000,
  retries: 0,
  outputDir: 'test-results',
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'test-reports' }]],

  use: {
    baseURL: env.DOLIBARR_URL || 'http://dolibarr.test',
    headless: false,
    video: 'retain-on-failure',
    screenshot: 'only-on-failure',
    viewport: { width: 1280, height: 900 },
    locale: 'en-AU',
  },

  projects: [
    {
      name: 'setup',
      testMatch: /auth\.setup\.js/,
      // No storageState — creates .auth/state.json for the first time
    },
    {
      name: 'payroll',
      testMatch: /payroll-.+\.spec\.js/,
      dependencies: ['setup'],
      use: { storageState: '.auth/state.json' },
    },
  ],
});
