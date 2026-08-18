const {test, expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

const wcagTags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function expectAccessible(page, surface) {
  const results = await new AxeBuilder({page}).withTags(wcagTags).analyze();
  await test.info().attach(`axe-${surface}.json`, {
    body: JSON.stringify(results.violations, null, 2),
    contentType: 'application/json',
  });
  const summary = results.violations.map(violation => {
    const targets = violation.nodes.map(node => node.target.join(' ')).join(', ');
    return `${violation.id} (${violation.impact || 'unknown'}): ${targets}`;
  }).join('\n');
  expect(results.violations, `${surface} accessibility violations:\n${summary}`).toEqual([]);
}

async function openFirstPage(page) {
  await page.goto('/dashboard');
  await page.locator('.tree-page[data-page-id]').first().click();
  await expect(page.locator('#documentView')).toBeVisible();
}

test.describe('automated accessibility checks', () => {
  test('authentication form meets WCAG A and AA rules', async ({page, context}) => {
    await context.clearCookies();
    await page.goto('/login');
    await expect(page.getByRole('button', {name: 'Sign in'})).toBeVisible();
    await expectAccessible(page, 'authentication');
    await page.emulateMedia({colorScheme: 'dark'});
    await page.reload();
    await expectAccessible(page, 'authentication-dark');
  });

  test('workspace, editor menus, and dialogs meet WCAG A and AA rules', async ({page}) => {
    await page.goto('/dashboard');
    await expect(page.locator('#homeView')).toBeVisible();
    await expectAccessible(page, 'workspace-home');

    await page.locator('.tree-page[data-page-id]').first().click();
    await expect(page.locator('#documentView')).toBeVisible();
    await expectAccessible(page, 'editor');

    await page.locator('#moreButton').click();
    await expect(page.locator('#moreMenu')).toBeVisible();
    await expectAccessible(page, 'editor-more-menu');
    await page.keyboard.press('Escape');

    await page.locator('#searchButton').click();
    await expect(page.locator('#searchModal')).toHaveAttribute('open', '');
    await expectAccessible(page, 'search-dialog');
    await page.keyboard.press('Escape');

    await page.locator('#moreButton').click();
    await page.locator('[data-more="history"]').click();
    await expect(page.locator('#historyModal')).toHaveAttribute('open', '');
    await expectAccessible(page, 'history-dialog');
    await page.locator('.history-item').last().click();
    await expectAccessible(page, 'history-dialog-older-revision');
    await page.locator('#historyModal .dialog-close').click();

    await page.locator('#collaborateButton').click();
    await expect(page.locator('#shareModal')).toHaveAttribute('open', '');
    await expectAccessible(page, 'sharing-dialog');
    await page.locator('#shareModal .dialog-close').click();

    await page.locator('#appearanceAdminButton').click();
    await expect(page.locator('#appearanceModal')).toHaveAttribute('open', '');
    await expectAccessible(page, 'appearance-settings-dialog');
    await page.getByRole('button', {name: 'Close appearance settings'}).click();

    await page.locator('#accountButton').click();
    await expect(page.locator('#accountModal')).toHaveAttribute('open', '');
    await expectAccessible(page, 'profile-settings-dialog');
    await page.locator('#accountModal .dialog-close').click();

    const profileSlug = await page.evaluate(() => fetch('/api/profile').then(response => response.json()).then(profile => profile.profile_slug));
    await page.goto(`/u/${profileSlug}`);
    await expect(page.locator('.profile-page')).toBeVisible();
    await expectAccessible(page, 'signed-in-profile');

    await page.evaluate(() => localStorage.setItem('n3.theme', 'dark'));
    await page.goto('/dashboard');
    await page.locator('.tree-page[data-page-id]').first().click();
    await expectAccessible(page, 'editor-dark');
  });

  test('authenticated mobile directory meets WCAG A and AA rules', async ({page}) => {
    await page.setViewportSize({width: 390, height: 844});
    await page.goto('/dashboard');
    await page.locator('#menuButton').click();
    await expect(page.locator('#sidebar')).toHaveAttribute('aria-hidden', 'false');
    await expectAccessible(page, 'authenticated-mobile-directory');
  });

  test('public home, tag directory, mobile directory, and page meet WCAG A and AA rules', async ({page}) => {
    await page.goto('/public');
    await expectAccessible(page, 'public-home');
    await page.evaluate(() => localStorage.setItem('n3.publicTheme', 'dark'));
    await page.reload();
    await expectAccessible(page, 'public-home-dark');

    await page.goto('/tags');
    await expectAccessible(page, 'public-tags');

    await page.setViewportSize({width: 390, height: 844});
    await page.goto('/public');
    await page.locator('.public-directory-toggle').click();
    await expect(page.locator('.public-directory')).toBeVisible();
    await expectAccessible(page, 'public-mobile-directory');

    await page.setViewportSize({width: 1200, height: 800});
    await openFirstPage(page);
    const published = await page.evaluate(async () => {
      const bootstrap = await fetch('/api/bootstrap').then(response => response.json());
      const id = Number(document.querySelector('.tree-row.active').closest('.tree-branch').dataset.branch);
      const current = await fetch(`/api/pages/${id}`).then(response => response.json());
      const response = await fetch(`/api/pages/${id}`, {
        method: 'PUT',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': bootstrap.csrfToken},
        body: JSON.stringify({is_public: true}),
      });
      if (!response.ok) throw new Error(`Could not publish accessibility fixture (${response.status})`);
      return {id, slug: current.slug, csrfToken: bootstrap.csrfToken};
    });

    try {
      await page.goto(`/p/${published.slug}`);
      await expect(page.locator('.public-article')).toBeVisible();
      await expectAccessible(page, 'public-page');
    } finally {
      await page.evaluate(async fixture => {
        await fetch(`/api/pages/${fixture.id}`, {
          method: 'PUT',
          headers: {'Content-Type': 'application/json', 'X-CSRF-Token': fixture.csrfToken},
          body: JSON.stringify({is_public: false}),
        });
      }, published);
    }
  });
});
