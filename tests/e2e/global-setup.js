const {chromium} = require('@playwright/test');
const fs = require('node:fs/promises');

module.exports = async config => {
  const browser = await chromium.launch(config.projects[0].use.launchOptions || {});
  const page = await browser.newPage();
  const baseURL = config.projects[0].use.baseURL;
  await page.goto(`${baseURL}/setup`);
  await page.getByLabel('Username').fill('browser-owner');
  await page.getByLabel('Password', {exact: true}).fill('browser test password');
  if (new URL(page.url()).pathname === '/setup') {
    await page.getByLabel('Confirm password').fill('browser test password');
    await page.getByRole('button', {name: 'Create account'}).click();
  } else {
    await page.getByRole('button', {name: 'Sign in'}).click();
  }
  await page.waitForURL(`${baseURL}/dashboard`);
  await fs.mkdir('.playwright', {recursive: true});
  await page.context().storageState({path: '.playwright/auth.json'});
  await browser.close();
};
