const {defineConfig} = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 2 : 0,
  reporter: process.env.CI ? [['line'], ['html', {open: 'never'}]] : 'line',
  globalSetup: require.resolve('./tests/e2e/global-setup'),
  use: {
    baseURL: process.env.N3_URL || 'http://127.0.0.1:8786',
    storageState: '.playwright/auth.json',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{name: 'chromium', use: {
    browserName: 'chromium',
    ...(process.env.PLAYWRIGHT_CHROME_PATH ? {launchOptions: {executablePath: process.env.PLAYWRIGHT_CHROME_PATH}} : {}),
  }}],
});
