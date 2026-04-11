import { defineConfig } from '@playwright/test';
import path from 'path';

const authFile = path.join(__dirname, '.auth', 'admin.json');

export default defineConfig({
  testDir: '.',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: 'http://localhost:8889',
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'setup',
      testMatch: /global-setup\.ts/,
    },
    {
      name: 'e2e',
      testMatch: /\.spec\.ts/,
      dependencies: ['setup'],
      use: {
        storageState: authFile,
      },
    },
  ],
});
