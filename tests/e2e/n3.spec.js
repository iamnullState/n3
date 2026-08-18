const {test, expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

async function openFirstPage(page) {
  await page.goto('/dashboard');
  await page.locator('.tree-page[data-page-id]').first().click();
  await expect(page.locator('#documentView')).toBeVisible();
}

test.describe.serial('n3 browser workflows', () => {
  test('keeps the editor unavailable until the initial page is loaded', async ({page}) => {
    let releaseBootstrap;
    const bootstrapHeld = new Promise(resolve => { releaseBootstrap = resolve; });
    await page.route('**/api/bootstrap', async route => {
      await bootstrapHeld;
      await route.continue();
    });

    await page.goto('/dashboard');
    await expect(page.locator('#appState')).toBeVisible();
    await expect(page.locator('#appState')).toContainText('Loading n3');
    await expect(page.locator('#documentView')).toBeHidden();
    releaseBootstrap();
    await expect(page.locator('#homeView')).toBeVisible();
    await expect(page.locator('#documentView')).toBeHidden();
    await page.unroute('**/api/bootstrap');
  });

  test('shows a persistent recoverable state when workspace startup fails', async ({page}) => {
    await page.route('**/api/bootstrap', route => route.fulfill({status: 503, json: {error: 'Workspace temporarily unavailable.'}}));
    await page.goto('/dashboard');
    await expect(page.locator('#appState')).toBeVisible();
    await expect(page.locator('#appState')).toContainText('n3 could not start');
    await expect(page.locator('#appStateRetry')).toBeVisible();
    await expect(page.locator('#homeView')).toBeHidden();
    await expect(page.locator('#documentView')).toBeHidden();
    await page.unroute('**/api/bootstrap');
    await page.locator('#appStateRetry').click();
    await expect(page.locator('#homeView')).toBeVisible();
    await expect(page.locator('#appState')).toBeHidden();
  });

  test('autosaves page content and survives reload', async ({page}) => {
    await openFirstPage(page);
    await page.locator('#pageTitle').fill('Browser autosave page');
    await page.locator('#editor').fill('Autosave survives a real browser reload.');
    await expect(page.locator('#saveState')).toContainText('Saved');
    await page.reload();
    await expect(page.locator('#pageTitle')).toHaveValue('Browser autosave page');
    await expect(page.locator('#editor')).toContainText('Autosave survives a real browser reload.');
  });

  test('shows privacy-safe page information in editor, preview, and public views', async ({page, browser}) => {
    await openFirstPage(page);
    const pageId = Number(await page.locator('.tree-row.active').first().evaluate(row => row.closest('.tree-branch').dataset.branch));
    await expect(page.locator('#pageInformation')).toBeVisible();
    await expect(page.locator('#pageInformationAuthor a[href^="/u/"]')).toBeVisible();
    await expect(page.locator('#pageInformationWords')).not.toHaveText('0');
    await expect(page.locator('#pageInformationCreated time')).toBeVisible();
    await expect(page.locator('#pageInformationUpdated time')).toBeVisible();

    const fixture = await page.evaluate(async id => {
      const bootstrap = await fetch('/api/bootstrap').then(response => response.json());
      const current = await fetch(`/api/pages/${id}`).then(response => response.json());
      if (current.page_information.author.profile_url === null || 'username' in current.page_information.author) {
        throw new Error('Authenticated page information did not use its safe author projection.');
      }
      const response = await fetch(`/api/pages/${id}`, {
        method: 'PUT',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': bootstrap.csrfToken},
        body: JSON.stringify({is_public: true}),
      });
      if (!response.ok) throw new Error(`Could not publish page-information fixture (${response.status})`);
      return {id, slug: current.slug, csrfToken: bootstrap.csrfToken};
    }, pageId);

    await page.goto(`/preview/${pageId}`);
    await expect(page.getByRole('heading', {name: 'Page information'})).toBeVisible();
    await expect(page.locator('.page-info-author[href^="/u/"]')).toBeVisible();
    await expect(page.getByText('First published', {exact: true})).toBeVisible();

    const anonymousContext = await browser.newContext({storageState: {cookies: [], origins: []}});
    const anonymous = await anonymousContext.newPage();
    try {
      await anonymous.goto(`${new URL(page.url()).origin}/p/${fixture.slug}`);
      await expect(anonymous.getByRole('heading', {name: 'Page information'})).toBeVisible();
      await expect(anonymous.locator('.page-info-author')).toContainText('Private author');
      await expect(anonymous.locator('.page-info-author[href]')).toHaveCount(0);
      await expect(anonymous.locator('.page-info-avatar img')).toHaveCount(0);
      await expect(anonymous.getByText('First published', {exact: true})).toBeVisible();
    } finally {
      await anonymousContext.close();
      await page.goto('/dashboard');
      await page.evaluate(async saved => {
        await fetch(`/api/pages/${saved.id}`, {
          method: 'PUT',
          headers: {'Content-Type': 'application/json', 'X-CSRF-Token': saved.csrfToken},
          body: JSON.stringify({is_public: false}),
        });
      }, fixture);
    }
  });

  test('uses stable slug routes with legacy redirects and browser history', async ({page}) => {
    await openFirstPage(page);
    const pageId = Number(await page.locator('.tree-row.active').first().evaluate(row => row.closest('.tree-branch').dataset.branch));
    const canonicalUrl = page.url();
    expect(new URL(canonicalUrl).pathname).toMatch(/^\/page\/[a-z0-9-]+-\d+$/);

    await page.locator('#pageTitle').fill('Renamed without breaking its URL');
    await expect(page.locator('#saveState')).toContainText('Saved');
    await expect(page).toHaveURL(canonicalUrl);

    await page.goto(`/page/${pageId}`);
    await expect(page).toHaveURL(canonicalUrl);
    await expect(page.locator('#pageTitle')).toHaveValue('Renamed without breaking its URL');

    await page.locator('#homeButton').click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goBack();
    await expect(page).toHaveURL(canonicalUrl);
    await expect(page.locator('#documentView')).toBeVisible();
  });

  test('keeps an offline edit locally and retries it after reconnecting', async ({page, context}) => {
    await openFirstPage(page);
    const pageId = Number(await page.locator('.tree-row.active').first().evaluate(row => row.closest('.tree-branch').dataset.branch));
    await context.setOffline(true);
    await expect(page.locator('#connectionBanner')).toBeVisible();
    await page.locator('#editor').fill('This edit was written while the browser was offline.');
    await expect(page.locator('#saveState')).toContainText('Offline · draft saved');
    const draft = await page.evaluate(id => JSON.parse(localStorage.getItem(`n3.draft.${id}`)), pageId);
    expect(draft.content).toContain('browser was offline');

    const saved = page.waitForResponse(response => response.url().endsWith(`/api/pages/${pageId}`) && response.request().method() === 'PUT' && response.ok());
    await context.setOffline(false);
    await saved;
    await expect(page.locator('#connectionBanner')).toBeHidden();
    await expect(page.locator('#saveState')).toContainText('Saved');
    await page.reload();
    await expect(page.locator('#editor')).toContainText('This edit was written while the browser was offline.');
  });

  test('migrates legacy preferences and recovers a legacy local draft once', async ({page}) => {
    await openFirstPage(page);
    const pageId = Number(await page.locator('.tree-row.active').first().evaluate(row => row.closest('.tree-branch').dataset.branch));
    const serverPage = await page.evaluate(id => fetch(`/api/pages/${id}`).then(response => response.json()), pageId);
    await page.evaluate(({id, spaceId, revision}) => {
      localStorage.setItem('n3.theme', 'light');
      localStorage.setItem('folio.theme', 'dark');
      localStorage.setItem('folio.space', String(spaceId));
      localStorage.setItem('folio.expanded', JSON.stringify([id]));
      localStorage.setItem('folio.expandedSpaces', JSON.stringify([spaceId]));
      localStorage.setItem('folio.recent', JSON.stringify([id]));
      localStorage.setItem(`folio.draft.${id}`, JSON.stringify({
        title: 'Migrated legacy draft',
        content: '<p>Unsaved content from the legacy storage key.</p>',
        baseRevision: revision,
        savedAt: new Date().toISOString(),
      }));
    }, {id: pageId, spaceId: Number(serverPage.space_id), revision: Number(serverPage.content_revision)});

    page.once('dialog', dialog => dialog.accept());
    const recoveredSave = page.waitForResponse(response => response.url().endsWith(`/api/pages/${pageId}`) && response.request().method() === 'PUT' && response.ok());
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await expect(page.locator('#pageTitle')).toHaveValue('Migrated legacy draft');
    await expect(page.locator('#editor')).toContainText('Unsaved content from the legacy storage key.');
    await recoveredSave;

    const storage = await page.evaluate(id => ({
      legacy: Object.keys(localStorage).filter(key => key === 'folio.theme' || key === 'folio.space' || key === 'folio.expanded' || key === 'folio.expandedSpaces' || key === 'folio.recent' || key === `folio.draft.${id}`),
      theme: localStorage.getItem('n3.theme'),
      space: localStorage.getItem('n3.space'),
      expanded: localStorage.getItem('n3.expanded'),
      expandedSpaces: localStorage.getItem('n3.expandedSpaces'),
      recent: localStorage.getItem('n3.recent'),
    }), pageId);
    expect(storage.legacy).toEqual([]);
    expect(storage.theme).toBe('light');
    expect(storage.space).not.toBeNull();
    expect(storage.expanded).not.toBeNull();
    expect(storage.expandedSpaces).not.toBeNull();
    expect(storage.recent).not.toBeNull();
  });

  test('recovers a local draft after an interrupted save', async ({page}) => {
    await openFirstPage(page);
    const pageId = Number(await page.locator('.tree-row.active').first().evaluate(row => row.closest('.tree-branch').dataset.branch));
    const baseRevision = await page.evaluate(async id => Number((await fetch(`/api/pages/${id}`).then(response => response.json())).content_revision), pageId);
    await page.evaluate(({id, revision}) => localStorage.setItem(`n3.draft.${id}`, JSON.stringify({
      title: 'Recovered browser draft',
      content: '<p>Recovered content from local storage.</p>',
      baseRevision: revision,
      savedAt: new Date().toISOString(),
    })), {id: pageId, revision: baseRevision});
    page.once('dialog', dialog => dialog.accept());
    const recoveredSave = page.waitForResponse(response => response.url().endsWith(`/api/pages/${pageId}`) && response.request().method() === 'PUT' && response.ok());
    await page.reload();
    await expect(page.locator('#pageTitle')).toHaveValue('Recovered browser draft');
    await expect(page.locator('#editor')).toContainText('Recovered content from local storage.');
    await recoveredSave;
    await expect(page.locator('#saveState')).toContainText('Saved');
  });

  test('keeps a stale recovered draft without overwriting newer content', async ({page}) => {
    await openFirstPage(page);
    const pageId = Number(await page.locator('.tree-row.active').first().evaluate(row => row.closest('.tree-branch').dataset.branch));
    const serverPage = await page.evaluate(id => fetch(`/api/pages/${id}`).then(response => response.json()), pageId);
    expect(Number(serverPage.content_revision)).toBeGreaterThan(1);
    await page.evaluate(({id, revision}) => localStorage.setItem(`n3.draft.${id}`, JSON.stringify({
      title: 'Stale recovered browser draft',
      content: '<p>This stale draft must not overwrite the server.</p>',
      baseRevision: revision - 1,
      savedAt: new Date().toISOString(),
    })), {id: pageId, revision: Number(serverPage.content_revision)});

    page.once('dialog', dialog => dialog.accept());
    const rejectedSave = page.waitForResponse(response => response.url().endsWith(`/api/pages/${pageId}`) && response.request().method() === 'PUT' && response.status() === 409);
    await page.reload();
    await expect(page.locator('#pageTitle')).toHaveValue('Stale recovered browser draft');
    await rejectedSave;
    await expect(page.locator('#saveState')).toContainText('Conflict · draft safe');
    await expect(page.locator('#saveConflict')).toBeVisible();
    await expect(page.locator('#saveConflictReload')).toBeVisible();

    const result = await page.evaluate(async id => ({
      server: await fetch(`/api/pages/${id}`).then(response => response.json()),
      draft: JSON.parse(localStorage.getItem(`n3.draft.${id}`)),
    }), pageId);
    expect(result.server.title).toBe(serverPage.title);
    expect(result.draft.title).toBe('Stale recovered browser draft');
    expect(Number(result.draft.baseRevision)).toBe(Number(serverPage.content_revision) - 1);

    await page.evaluate(id => localStorage.removeItem(`n3.draft.${id}`), pageId);
    await page.reload();
  });

  test('shows revision differences and restores an earlier version', async ({page}) => {
    await openFirstPage(page);
    await page.locator('#moreButton').click();
    await page.locator('[data-more="history"]').click();
    await expect(page.locator('#historyModal')).toHaveAttribute('open', '');
    expect(await page.locator('.history-item').count()).toBeGreaterThanOrEqual(3);
    await expect(page.locator('#historyPreview .added').first()).toBeVisible();
    await page.locator('.history-item').last().click();
    await expect(page.locator('#historyRestore')).toBeVisible();
    page.once('dialog', dialog => dialog.accept());
    await page.locator('#historyRestore').click();
    await expect(page.locator('#historyModal')).not.toHaveAttribute('open', '');
    await expect(page.locator('#pageTitle')).not.toHaveValue('Recovered browser draft');
  });

  test('opens dialogs and changes theme', async ({page}) => {
    await openFirstPage(page);
    await page.locator('#searchButton').click();
    await expect(page.locator('#searchModal')).toHaveAttribute('open', '');
    await page.keyboard.press('Escape');
    await page.locator('#trashButton').click();
    await expect(page.locator('#trashModal')).toHaveAttribute('open', '');
    await page.locator('#trashModal .dialog-close').click();
    const before = await page.locator('html').getAttribute('data-theme');
    await page.locator('#themeButton').click();
    await expect(page.locator('html')).not.toHaveAttribute('data-theme', before);
  });

  test('keeps whitelabel and collaboration settings inside properly sized dialogs', async ({page}) => {
    await page.setViewportSize({width: 1024, height: 768});
    await openFirstPage(page);
    await page.locator('#appearanceAdminButton').click();
    await expect(page.locator('#appearanceModal')).toHaveAttribute('open', '');
    await expect(page.locator('[data-theme-token]')).toHaveCount(20);
    expect(await page.locator('#appearanceModal .modal-shell').evaluate(shell => shell.scrollWidth <= shell.clientWidth)).toBe(true);
    const pixel = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
    await page.locator('#brandIconInput').setInputFiles({name: 'brand-icon.png', mimeType: 'image/png', buffer: pixel});
    await Promise.all([
      page.waitForResponse(response => response.url().endsWith('/api/settings/brand/icon') && response.ok()),
      page.locator('#brandIconUpload').click(),
    ]);
    await page.locator('#brandBannerInput').setInputFiles({name: 'brand-banner.png', mimeType: 'image/png', buffer: pixel});
    await Promise.all([
      page.waitForResponse(response => response.url().endsWith('/api/settings/brand/banner') && response.ok()),
      page.locator('#brandBannerUpload').click(),
    ]);
    await page.locator('#appearanceForm [name="brandName"]').fill('Browser Brand');
    await Promise.all([
      page.waitForResponse(response => response.url().endsWith('/api/settings') && response.request().method() === 'PUT' && response.ok()),
      page.getByRole('button', {name: 'Save appearance'}).click(),
    ]);
    await expect(page.locator('#appName')).toHaveText('Browser Brand');
    await expect(page.locator('#workspaceIcon')).toBeVisible();
    await expect(page.locator('#brandBanner')).toHaveAttribute('src', /\/brand\/banner/);
    await page.locator('#homeButton').click();
    await expect(page.locator('#brandBanner')).toBeVisible();
    await page.locator('#appearanceAdminButton').click();
    await page.locator('#appearanceForm [name="brandName"]').fill('n3');
    await page.getByRole('button', {name: 'Save appearance'}).click();

    await page.locator('.tree-page[data-page-id]').first().click();
    await expect(page.locator('#documentView')).toBeVisible();
    await page.locator('#collaborateButton').click();
    await expect(page.locator('#shareModal')).toHaveAttribute('open', '');
    expect(await page.locator('#shareModal .share-shell').evaluate(shell => shell.scrollWidth <= shell.clientWidth)).toBe(true);
    const formControlsFit = await page.locator('#shareModal input, #shareModal select, #shareModal button').evaluateAll(controls => controls.every(control => {
      const bounds = control.getBoundingClientRect();
      return bounds.left >= 0 && bounds.right <= innerWidth;
    }));
    expect(formControlsFit).toBe(true);
    await page.locator('#shareModal .dialog-close').click();
  });

  test('uses full-width content and saves text colors and horizontal dividers', async ({page}) => {
    await page.setViewportSize({width: 1280, height: 800});
    await openFirstPage(page);
    const widthRatio = await page.evaluate(() => document.getElementById('documentView').getBoundingClientRect().width / document.getElementById('main').getBoundingClientRect().width);
    expect(widthRatio).toBeGreaterThan(0.95);
    await page.locator('#editor').evaluate(editor => {
      editor.innerHTML = '<p id="colorProbe">Color me</p>';
      editor.dispatchEvent(new InputEvent('input', {bubbles: true, inputType: 'insertText'}));
      const range = document.createRange();
      range.selectNodeContents(document.getElementById('colorProbe'));
      const selection = getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
    });
    await page.locator('#textColor').dispatchEvent('pointerdown');
    await page.locator('#textColor').evaluate(input => { input.value = '#123456'; input.dispatchEvent(new Event('change', {bubbles: true})); });
    await expect(page.locator('#editor font[color="#123456"]')).toContainText('Color me');
    await page.locator('#editor').click();
    await page.keyboard.press('End');
    await page.locator('[data-action="divider"]').click();
    await expect(page.locator('#editor hr')).toHaveCount(1);
    await expect(page.locator('#saveState')).toContainText('Saved');
  });

  test('updates profile settings with a live preview and avatar lifecycle', async ({page}) => {
    await page.goto('/dashboard');
    const loaded = page.waitForResponse(response => response.url().endsWith('/api/profile') && response.request().method() === 'GET' && response.ok());
    await page.locator('#accountButton').click();
    await loaded;
    await expect(page.locator('#accountModal')).toHaveAttribute('open', '');
    await page.locator('#profileForm [name="display_name"]').fill('Browser Profile');
    await page.locator('#profileForm [name="biography"]').fill('A profile edited through the browser workflow.');
    await page.locator('#profileForm [name="profile_visibility"]').selectOption('members');
    await expect(page.locator('#profilePreviewHeading')).toHaveText('Browser Profile');
    await expect(page.locator('#profilePreviewBiography')).toContainText('browser workflow');
    await expect(page.locator('#profilePreviewVisibility')).toHaveText('Members');

    const saved = page.waitForResponse(response => response.url().endsWith('/api/profile') && response.request().method() === 'PUT' && response.ok());
    await page.locator('#profileForm button[type="submit"]').click();
    await saved;
    await expect(page.locator('#toastRegion')).toContainText('Profile settings saved');

    const uploaded = page.waitForResponse(response => response.url().endsWith('/api/profile/avatar') && response.request().method() === 'POST' && response.status() === 201);
    await page.locator('#profileAvatarInput').setInputFiles({
      name: 'browser-avatar.png',
      mimeType: 'image/png',
      buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64'),
    });
    await uploaded;
    await expect(page.locator('.profile-avatar-frame')).toHaveClass(/has-avatar/);
    await expect(page.locator('.profile-preview-avatar')).toHaveClass(/has-avatar/);

    const removed = page.waitForResponse(response => response.url().endsWith('/api/profile/avatar') && response.request().method() === 'DELETE' && response.ok());
    await page.locator('#profileAvatarRemove').click();
    await removed;
    await expect(page.locator('.profile-avatar-frame')).not.toHaveClass(/has-avatar/);
    await page.locator('#accountModal .dialog-close').click();
    await expect(page.locator('#accountModal')).not.toHaveAttribute('open', '');

    await page.locator('#accountButton').click();
    await expect(page.locator('#profileForm [name="display_name"]')).toHaveValue('Browser Profile');
    await expect(page.locator('#profileForm [name="profile_visibility"]')).toHaveValue('members');
    await page.locator('#accountModal .dialog-close').click();
  });

  test('changes a collaborator username with password confirmation while preserving its profile URL', async ({page, browser}) => {
    await page.goto('/dashboard');
    const collaborator = await page.evaluate(async () => {
      const bootstrap = await fetch('/api/bootstrap').then(response => response.json());
      const username = `rename-reader-${Date.now()}`;
      const password = 'browser rename password';
      const response = await fetch('/api/collaboration/users', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': bootstrap.csrfToken},
        body: JSON.stringify({username, password}),
      });
      if (!response.ok) throw new Error(`Could not create rename collaborator (${response.status})`);
      return {username, password};
    });

    const collaboratorContext = await browser.newContext({storageState: {cookies: [], origins: []}});
    const collaboratorPage = await collaboratorContext.newPage();
    try {
      await collaboratorPage.goto(`${new URL(page.url()).origin}/login`);
      await collaboratorPage.getByLabel('Username').fill(collaborator.username);
      await collaboratorPage.getByLabel('Password', {exact: true}).fill(collaborator.password);
      await collaboratorPage.getByRole('button', {name: 'Sign in'}).click();
      await collaboratorPage.waitForURL(/\/dashboard$/);
      await collaboratorPage.locator('#accountButton').click();
      await expect(collaboratorPage.locator('#accountModal')).toHaveAttribute('open', '');
      const stableProfileUrl = await collaboratorPage.locator('#profilePreviewUrl').textContent();
      const renamedUsername = `${collaborator.username}-renamed`;
      await collaboratorPage.locator('#profileForm [name="username"]').fill(renamedUsername);
      await collaboratorPage.locator('#profileForm [name="current_password"]').fill(collaborator.password);
      const renamed = collaboratorPage.waitForResponse(response => response.url().endsWith('/api/profile') && response.request().method() === 'PUT' && response.ok());
      await collaboratorPage.locator('#profileForm button[type="submit"]').click();
      const renamedPayload = await (await renamed).json();
      expect(renamedPayload.csrfToken).toMatch(/^[a-f0-9]{64}$/);
      await expect(collaboratorPage.locator('#profilePreviewUsername')).toHaveText(`@${renamedUsername}`);
      await expect(collaboratorPage.locator('#profilePreviewUrl')).toHaveText(stableProfileUrl);
    } finally {
      await collaboratorContext.close();
    }
  });

  test('renders viewer-filtered self and anonymous profile pages responsively', async ({page, browser}) => {
    await page.goto('/dashboard');
    const fixture = await page.evaluate(async () => {
      const bootstrap = await fetch('/api/bootstrap').then(response => response.json());
      const profile = await fetch('/api/profile').then(response => response.json());
      const spaceId = Number(bootstrap.spaces[0].id);
      const headers = {'Content-Type': 'application/json', 'X-CSRF-Token': bootstrap.csrfToken};
      const createdResponse = await fetch('/api/pages', {
        method: 'POST',
        headers,
        body: JSON.stringify({space_id: spaceId, kind: 'page', title: 'Browser profile article'}),
      });
      if (!createdResponse.ok) throw new Error(`Could not create profile fixture (${createdResponse.status})`);
      const created = await createdResponse.json();
      const createdPage = await fetch(`/api/pages/${created.id}`).then(response => response.json());
      const publishResponse = await fetch(`/api/pages/${created.id}`, {
        method: 'PUT',
        headers,
        body: JSON.stringify({is_public: true}),
      });
      if (!publishResponse.ok) throw new Error(`Could not publish profile fixture (${publishResponse.status})`);
      const profileResponse = await fetch('/api/profile', {
        method: 'PUT',
        headers,
        body: JSON.stringify({...profile, profile_visibility: 'public'}),
      });
      if (!profileResponse.ok) throw new Error(`Could not publish browser profile (${profileResponse.status})`);
      return {
        csrfToken: bootstrap.csrfToken,
        pageId: Number(created.id),
        pageSlug: createdPage.slug,
        profile: await profileResponse.json(),
      };
    });

    const profilePath = `/u/${fixture.profile.profile_slug}`;
    const selfResponse = await page.goto(profilePath);
    expect(selfResponse.status()).toBe(200);
    expect(selfResponse.headers()['cache-control']).toBe('no-store');
    expect(selfResponse.headers()['x-robots-tag']).toContain('noindex');
    await expect(page.getByRole('heading', {name: 'Owned pages'})).toBeVisible();
    await expect(page.getByRole('heading', {name: 'Published by me'})).toBeVisible();
    await expect(page.locator(`a.profile-page-card[href="/page/${fixture.pageSlug}"]`)).toHaveCount(2);

    const anonymousContext = await browser.newContext({storageState: {cookies: [], origins: []}});
    const anonymous = await anonymousContext.newPage();
    try {
      const publicResponse = await anonymous.goto(`${new URL(page.url()).origin}${profilePath}`);
      expect(publicResponse.status()).toBe(200);
      expect(publicResponse.headers()['cache-control']).toBe('no-cache');
      expect(publicResponse.headers()['x-robots-tag']).toBeUndefined();
      await expect(anonymous.getByRole('heading', {name: 'Browser Profile', exact: true})).toBeVisible();
      await expect(anonymous.getByRole('heading', {name: 'Published pages'})).toBeVisible();
      await expect(anonymous.locator(`a.profile-page-card[href="/p/${fixture.pageSlug}"]`)).toHaveCount(1);
      await expect(anonymous.locator('link[rel="canonical"]')).toHaveAttribute('href', new RegExp(`${profilePath}$`));
      const accessibility = await new AxeBuilder({page: anonymous})
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
      const accessibilitySummary = accessibility.violations.map(violation =>
        `${violation.id}: ${violation.nodes.map(node => node.target.join(' ')).join(', ')}`
      ).join('\n');
      expect(accessibility.violations, `public profile accessibility violations:\n${accessibilitySummary}`).toEqual([]);

      await anonymous.setViewportSize({width: 390, height: 844});
      await anonymous.reload();
      await expect(anonymous.locator('.profile-hero')).toBeVisible();
      expect(await anonymous.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    } finally {
      await anonymousContext.close();
      await page.goto('/dashboard');
      await page.evaluate(async saved => {
        const headers = {'Content-Type': 'application/json', 'X-CSRF-Token': saved.csrfToken};
        await fetch('/api/profile', {
          method: 'PUT',
          headers,
          body: JSON.stringify({...saved.profile, profile_visibility: 'members'}),
        });
        await fetch(`/api/pages/${saved.pageId}`, {method: 'DELETE', headers});
      }, fixture);
    }
  });

  test('filters signed-in profile pages through the viewer shared-page boundary', async ({page, browser}) => {
    await page.goto('/dashboard');
    const fixture = await page.evaluate(async () => {
      const bootstrap = await fetch('/api/bootstrap').then(response => response.json());
      const profile = await fetch('/api/profile').then(response => response.json());
      const headers = {'Content-Type': 'application/json', 'X-CSRF-Token': bootstrap.csrfToken};
      const readerUsername = `profile-reader-${Date.now()}`;
      const readerPassword = 'browser reader password';
      const readerResponse = await fetch('/api/collaboration/users', {
        method: 'POST', headers, body: JSON.stringify({username: readerUsername, password: readerPassword}),
      });
      if (!readerResponse.ok) throw new Error(`Could not create profile reader (${readerResponse.status})`);
      const reader = await readerResponse.json();
      const createPage = async title => {
        const response = await fetch('/api/pages', {
          method: 'POST', headers, body: JSON.stringify({space_id: Number(bootstrap.spaces[0].id), kind: 'page', title}),
        });
        if (!response.ok) throw new Error(`Could not create ${title} (${response.status})`);
        return response.json();
      };
      const shared = await createPage('Visible shared profile page');
      const hidden = await createPage('Hidden unshared profile page');
      const shareResponse = await fetch('/api/shares', {
        method: 'POST', headers,
        body: JSON.stringify({resource_type: 'page', resource_id: Number(shared.id), user_id: Number(reader.id), role: 'viewer'}),
      });
      if (!shareResponse.ok) throw new Error(`Could not share profile page (${shareResponse.status})`);
      const profileResponse = await fetch('/api/profile', {
        method: 'PUT', headers, body: JSON.stringify({...profile, profile_visibility: 'members'}),
      });
      if (!profileResponse.ok) throw new Error(`Could not set member profile visibility (${profileResponse.status})`);
      return {
        csrfToken: bootstrap.csrfToken,
        originalProfile: profile,
        profileSlug: profile.profile_slug,
        readerUsername,
        readerPassword,
        sharedId: Number(shared.id),
        hiddenId: Number(hidden.id),
      };
    });

    const readerContext = await browser.newContext({storageState: {cookies: [], origins: []}});
    const readerPage = await readerContext.newPage();
    try {
      await readerPage.goto(`${new URL(page.url()).origin}/login`);
      await readerPage.getByLabel('Username').fill(fixture.readerUsername);
      await readerPage.getByLabel('Password', {exact: true}).fill(fixture.readerPassword);
      await readerPage.getByRole('button', {name: 'Sign in'}).click();
      await readerPage.waitForURL(/\/dashboard$/);
      const response = await readerPage.goto(`${new URL(page.url()).origin}/u/${fixture.profileSlug}`);
      expect(response.status()).toBe(200);
      await expect(readerPage.getByRole('heading', {name: 'Pages you can view'})).toBeVisible();
      await expect(readerPage.getByText('Visible shared profile page', {exact: true})).toBeVisible();
      await expect(readerPage.getByText('Hidden unshared profile page', {exact: true})).toHaveCount(0);
      await expect(readerPage.locator('.profile-group-heading [aria-label="1 pages"]')).toBeVisible();
    } finally {
      await readerContext.close();
      await page.goto('/dashboard');
      await page.evaluate(async saved => {
        const headers = {'Content-Type': 'application/json', 'X-CSRF-Token': saved.csrfToken};
        await fetch('/api/profile', {
          method: 'PUT', headers, body: JSON.stringify(saved.originalProfile),
        });
        await fetch(`/api/pages/${saved.sharedId}`, {method: 'DELETE', headers});
        await fetch(`/api/pages/${saved.hiddenId}`, {method: 'DELETE', headers});
      }, fixture);
    }
  });

  test('keeps the workspace top bar and dashboard hero flat in every theme', async ({page}) => {
    await page.goto('/dashboard');
    for (const theme of ['light', 'dark', 'system']) {
      await page.evaluate(value => localStorage.setItem('n3.theme', value), theme);
      await page.reload();
      await expect(page.locator('#homeView')).toBeVisible();
      const surfaces = await page.evaluate(() => {
        const styles = selector => getComputedStyle(document.querySelector(selector));
        const main = styles('#main');
        const topbar = styles('.topbar');
        const hero = styles('.home-hero');
        return {
          main: main.backgroundColor,
          topbar: topbar.backgroundColor,
          topbarImage: topbar.backgroundImage,
          topbarFilter: topbar.backdropFilter,
          hero: hero.backgroundColor,
          heroImage: hero.backgroundImage,
        };
      });
      expect(surfaces.topbar).toBe(surfaces.main);
      expect(surfaces.hero).toBe(surfaces.main);
      expect(surfaces.topbarImage).toBe('none');
      expect(surfaces.heroImage).toBe('none');
      expect(surfaces.topbarFilter).toBe('none');
    }
  });

  test('resizes and persists the bounded desktop workspace navigation', async ({page}) => {
    await page.goto('/dashboard');
    await page.evaluate(() => localStorage.removeItem('n3.sidebarWidth'));
    await page.reload();

    const handle = page.locator('#sidebarResizeHandle');
    await expect(handle).toBeVisible();
    await expect(handle).toHaveAttribute('role', 'separator');
    await expect(handle).toHaveAttribute('aria-valuemin', '224');
    await expect(handle).toHaveAttribute('aria-valuemax', '420');

    const box = await handle.boundingBox();
    await page.mouse.move(box.x + (box.width / 2), box.y + 80);
    await page.mouse.down();
    await page.mouse.move(700, box.y + 80);
    await page.mouse.up();
    await expect(handle).toHaveAttribute('aria-valuenow', '420');
    await expect(page.locator('#sidebar')).toHaveCSS('width', '420px');
    expect(await page.evaluate(() => localStorage.getItem('n3.sidebarWidth'))).toBe('420');

    await handle.focus();
    await page.keyboard.press('Home');
    await expect(handle).toHaveAttribute('aria-valuenow', '224');
    await page.keyboard.press('ArrowRight');
    await expect(handle).toHaveAttribute('aria-valuenow', '240');
    await page.reload();
    await expect(page.locator('#sidebarResizeHandle')).toHaveAttribute('aria-valuenow', '240');
    await expect(page.locator('#sidebar')).toHaveCSS('width', '240px');
  });

  test('collapses and restores the persistent desktop workspace navigation', async ({page}) => {
    await page.goto('/dashboard');
    await page.evaluate(() => localStorage.removeItem('n3.sidebarCollapsed'));
    await page.reload();

    const shell = page.locator('#appShell');
    const sidebar = page.locator('#sidebar');
    const collapse = page.locator('#sidebarCollapse');
    const reveal = page.locator('#menuButton');
    await expect(sidebar).toBeVisible();
    await expect(collapse).toBeVisible();
    await expect(collapse).toHaveAttribute('aria-expanded', 'true');
    await expect(reveal).toBeHidden();

    await collapse.click();
    await expect(shell).toHaveClass(/sidebar-collapsed/);
    await expect(sidebar).toBeHidden();
    await expect(sidebar).toHaveAttribute('aria-hidden', 'true');
    await expect(reveal).toBeVisible();
    await expect(reveal).toHaveAttribute('aria-expanded', 'false');
    await expect(reveal).toBeFocused();
    expect(await page.evaluate(() => localStorage.getItem('n3.sidebarCollapsed'))).toBe('true');

    await page.reload();
    await expect(page.locator('#appShell')).toHaveClass(/sidebar-collapsed/);
    await expect(page.locator('#menuButton')).toBeVisible();
    await page.locator('#menuButton').click();
    await expect(page.locator('#sidebar')).toBeVisible();
    await expect(page.locator('#sidebar')).toHaveAttribute('aria-hidden', 'false');
    await expect(page.locator('#sidebarCollapse')).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('#sidebarCollapse')).toBeFocused();
    expect(await page.evaluate(() => localStorage.getItem('n3.sidebarCollapsed'))).toBe('false');
  });

  test('clamps saved sidebar widths and contains long tree content', async ({page}) => {
    await page.goto('/dashboard');
    await page.evaluate(() => localStorage.setItem('n3.sidebarWidth', '9999'));
    await page.reload();
    const handle = page.locator('#sidebarResizeHandle');
    await expect(handle).toHaveAttribute('aria-valuenow', '420');
    expect(await page.evaluate(() => localStorage.getItem('n3.sidebarWidth'))).toBe('420');

    await page.evaluate(() => localStorage.setItem('n3.sidebarWidth', '-50'));
    await page.reload();
    await expect(handle).toHaveAttribute('aria-valuenow', '224');
    expect(await page.evaluate(() => localStorage.getItem('n3.sidebarWidth'))).toBe('224');
    await handle.focus();
    await page.keyboard.press('ArrowLeft');
    await expect(handle).toHaveAttribute('aria-valuenow', '224');
    await page.keyboard.press('End');
    await page.keyboard.press('ArrowRight');
    await expect(handle).toHaveAttribute('aria-valuenow', '420');
    await page.keyboard.press('Home');

    const treeLayout = await page.locator('.tree-row').first().evaluate(row => {
      const label = row.querySelector('.tree-page span:last-child');
      const actions = row.querySelector('.tree-create-actions');
      label.textContent = 'A deliberately very long navigation title that must remain inside the resized workspace directory without stretching its controls';
      const sidebarBox = document.getElementById('sidebar').getBoundingClientRect();
      const rowBox = row.getBoundingClientRect();
      const mainBox = document.getElementById('main').getBoundingClientRect();
      return {
        labelClientWidth: label.clientWidth,
        labelScrollWidth: label.scrollWidth,
        labelOverflow: getComputedStyle(label).overflow,
        labelEllipsis: getComputedStyle(label).textOverflow,
        actionsShrink: getComputedStyle(actions).flexShrink,
        rowRight: rowBox.right,
        sidebarRight: sidebarBox.right,
        mainLeft: mainBox.left,
        viewportWidth: innerWidth,
        mainRight: mainBox.right,
      };
    });
    expect(treeLayout.labelScrollWidth).toBeGreaterThan(treeLayout.labelClientWidth);
    expect(treeLayout.labelOverflow).toBe('hidden');
    expect(treeLayout.labelEllipsis).toBe('ellipsis');
    expect(treeLayout.actionsShrink).toBe('0');
    expect(treeLayout.rowRight).toBeLessThanOrEqual(treeLayout.sidebarRight);
    expect(treeLayout.mainLeft).toBe(224);
    expect(treeLayout.mainRight).toBe(treeLayout.viewportWidth);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  });

  test('keeps desktop collapse preferences separate across sidebar breakpoints', async ({page}) => {
    await page.setViewportSize({width: 1200, height: 800});
    await page.goto('/dashboard');
    await page.evaluate(() => localStorage.setItem('n3.sidebarCollapsed', 'true'));
    await page.reload();
    await expect(page.locator('#sidebar')).toBeHidden();
    await expect(page.locator('#menuButton')).toBeVisible();

    await page.setViewportSize({width: 820, height: 900});
    await expect(page.locator('#sidebarCollapse')).toBeHidden();
    await expect(page.locator('#sidebarResizeHandle')).toBeHidden();
    await expect(page.locator('#sidebar')).not.toHaveClass(/open/);
    await expect(page.locator('#sidebar')).toHaveAttribute('aria-hidden', 'true');
    await expect(page.locator('#sidebar')).toHaveJSProperty('inert', true);
    await page.locator('#menuButton').click();
    await expect(page.locator('#sidebar')).toHaveClass(/open/);
    await expect(page.locator('#sidebarClose')).toBeFocused();

    await page.setViewportSize({width: 1200, height: 800});
    await expect(page.locator('#sidebar')).not.toHaveClass(/open/);
    await expect(page.locator('#sidebar')).toBeHidden();
    await expect(page.locator('#menuButton')).toBeVisible();
    expect(await page.evaluate(() => localStorage.getItem('n3.sidebarCollapsed'))).toBe('true');

    await page.locator('#menuButton').click();
    await expect(page.locator('#sidebar')).toBeVisible();
    await expect(page.locator('#sidebarCollapse')).toBeFocused();
    await page.setViewportSize({width: 820, height: 900});
    await expect(page.locator('#sidebar')).not.toHaveClass(/open/);
    await expect(page.locator('#sidebar')).toHaveAttribute('aria-hidden', 'true');
    await page.setViewportSize({width: 1200, height: 800});
    await expect(page.locator('#sidebar')).toBeVisible();
    await expect(page.locator('#menuButton')).toBeHidden();
    expect(await page.evaluate(() => localStorage.getItem('n3.sidebarCollapsed'))).toBe('false');
  });

  test('exposes keyboard state and restores focus for workspace controls', async ({page}) => {
    await openFirstPage(page);
    await expect(page.locator('[data-mode="edit"]')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('.tree-toggle:disabled').first()).toHaveAttribute('tabindex', '-1');
    await expect(page.locator('.tree-toggle:not(:disabled)').first()).toHaveAttribute('aria-expanded', /true|false/);

    await page.locator('#moreButton').click();
    await expect(page.locator('#moreButton')).toHaveAttribute('aria-expanded', 'true');
    const firstMenuItem = page.locator('#moreMenu [role="menuitem"]:not(.hidden)').first();
    await expect(firstMenuItem).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(firstMenuItem).not.toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.locator('#moreButton')).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('#moreButton')).toBeFocused();

    await page.locator('#tocButton').click();
    await expect(page.locator('#tocButton')).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('#tocClose')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.locator('#tocButton')).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('#tocButton')).toBeFocused();
  });

  test('places uploaded media in the side rail and expands it', async ({page}) => {
    await openFirstPage(page);
    const uploadFinished = page.waitForResponse(response => response.url().endsWith('/api/media') && response.status() === 201);
    await page.locator('#mediaInput').setInputFiles({
      name: 'browser-photo.png',
      mimeType: 'image/png',
      buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64'),
    });
    await uploadFinished;
    const photo = page.locator('#editor img[src^="/media/"]').last();
    await expect(photo).toBeVisible();
    await expect(photo).toHaveClass(/media-column/);
    await photo.click();
    await page.locator('[data-action="media-right"]').click();
    await expect(photo).toHaveClass(/media-float-right/);
    await photo.click();
    await page.locator('#mediaSize').selectOption('50');
    await expect(photo).toHaveClass(/media-size-50/);
    await expect(page.locator('#saveState')).toContainText('Saved');
    await page.reload();
    const savedPhoto = page.locator('#editor img.media-column.media-size-50[src^="/media/"]');
    await expect(savedPhoto).toBeVisible();
    await page.locator('[data-mode="read"]').click();
    await savedPhoto.click();
    await expect(page.locator('#mediaLightbox')).toHaveAttribute('open', '');
    await page.locator('#mediaLightboxClose').click();
  });

  test('adds ordered references below page content', async ({page}) => {
    await openFirstPage(page);
    await page.locator('#addReferenceButton').click();
    await expect(page.locator('#addReferenceButton')).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('#referenceForm [name="label"]')).toBeFocused();
    await page.locator('#referenceForm [name="label"]').fill('Browser source');
    await page.locator('#referenceForm [name="url"]').fill('https://example.com/browser-source');
    await page.locator('#referenceForm button[type="submit"]').click();
    await expect(page.locator('#referenceList')).toContainText('Browser source');
    await page.reload();
    await expect(page.locator('#referenceList a', {hasText: 'Browser source'})).toHaveAttribute('href', 'https://example.com/browser-source');
  });

  test('persists drag and drop into a folder atomically', async ({page}) => {
    await openFirstPage(page);
    const ids = await page.evaluate(async () => {
      const bootstrap = await fetch('/api/bootstrap').then(response => response.json());
      const create = body => fetch('/api/pages', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': bootstrap.csrfToken}, body: JSON.stringify(body)}).then(response => response.json());
      const spaceId = Number(bootstrap.spaces[0].id);
      const folder = await create({space_id: spaceId, kind: 'folder', title: 'Browser drop folder'});
      const child = await create({space_id: spaceId, kind: 'page', title: 'Browser dragged page'});
      return {folderId: Number(folder.id), childId: Number(child.id)};
    });
    await page.reload();
    page.once('dialog', dialog => dialog.accept('Renamed browser folder'));
    await page.locator(`[data-rename-folder="${ids.folderId}"]`).click();
    await expect(page.locator(`[data-folder-id="${ids.folderId}"]`)).toContainText('Renamed browser folder');
    await expect(page.locator('.toast', {hasText: 'Folder renamed'})).toBeVisible();
    const source = page.locator(`[data-branch="${ids.childId}"]`).first();
    const target = page.locator(`[data-branch="${ids.folderId}"]`).first();
    await source.dragTo(target);
    await expect(page.locator('.toast', {hasText: 'Tree arranged'})).toBeVisible();
    const parentId = await page.evaluate(async childId => {
      const bootstrap = await fetch('/api/bootstrap').then(response => response.json());
      return Number(bootstrap.pages.find(item => Number(item.id) === childId).parent_id);
    }, ids.childId);
    expect(parentId).toBe(ids.folderId);
  });

  test('opens the directory on a mobile viewport', async ({page}) => {
    await page.setViewportSize({width: 390, height: 844});
    await page.goto('/dashboard');
    await page.locator('#menuButton').click();
    await expect(page.locator('#sidebar')).toHaveClass(/open/);
    await expect(page.locator('#sidebar')).toHaveAttribute('aria-hidden', 'false');
    await expect(page.locator('#menuButton')).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('#sidebarClose')).toBeFocused();
    await expect(page.locator('.space-tree')).toBeVisible();
    await page.locator('#spaceMenuButton').click();
    await expect(page.locator('#pageTree')).toBeHidden();
    await expect(page.locator('#spaceMenuButton')).toHaveAttribute('aria-expanded', 'false');
    await page.locator('#spaceMenuButton').click();
    await expect(page.locator('.space-tree')).toBeVisible();
  });

  test('uses an off-canvas public directory only on smaller screens', async ({page}) => {
    await page.setViewportSize({width: 1200, height: 800});
    await page.goto('/public');
    await expect(page.getByRole('searchbox', {name: 'Search published pages'})).toBeVisible();
    await expect(page.locator('.public-directory')).toBeVisible();
    await expect(page.locator('.public-directory-toggle')).toBeHidden();
    await expect(page.locator('.public-header')).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
    await expect(page.locator('.public-theme-toggle')).toBeVisible();
    await page.locator('.public-theme-toggle').click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');

    await page.setViewportSize({width: 390, height: 844});
    await expect(page.locator('.public-directory')).toBeHidden();
    await expect(page.locator('.public-directory')).toHaveJSProperty('inert', true);
    await expect(page.locator('.public-directory-toggle')).toBeVisible();
    await page.locator('.public-directory-toggle').click();
    await expect(page.locator('body')).toHaveClass(/public-directory-open/);
    await expect(page.locator('.public-directory')).toBeVisible();
    await expect(page.locator('.public-directory')).toHaveJSProperty('inert', false);
    await expect(page.locator('.public-directory-toggle')).toHaveAttribute('aria-expanded', 'true');
    await page.keyboard.press('Escape');
    await expect(page.locator('.public-directory')).toBeHidden();
    await expect(page.locator('.public-directory')).toHaveJSProperty('inert', true);
    await expect(page.locator('.public-directory-toggle')).toBeFocused();
  });

  test('keeps tablet and mobile controls and connection states inside their viewports', async ({page, context}) => {
    await page.setViewportSize({width: 820, height: 1180});
    await page.goto('/dashboard');
    await expect(page.locator('#menuButton')).toBeVisible();
    await expect(page.locator('#sidebar')).not.toHaveClass(/open/);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);

    await page.setViewportSize({width: 390, height: 844});
    await page.locator('#menuButton').click();
    await page.locator('#accountButton').click();
    await expect(page.locator('#accountModal')).toHaveAttribute('open', '');
    const controlsFit = await page.locator('#accountModal .modal-shell').evaluate(element => {
      const box = element.getBoundingClientRect();
      return box.left >= 0 && box.right <= innerWidth && box.top >= 0 && box.bottom <= innerHeight;
    });
    expect(controlsFit).toBe(true);
    await page.locator('#accountModal .dialog-close').first().click();
    await context.setOffline(true);
    await expect(page.locator('#connectionBanner')).toBeVisible();
    const bannerFits = await page.locator('#connectionBanner').evaluate(element => {
      const box = element.getBoundingClientRect();
      return box.left >= 0 && box.right <= innerWidth && box.top >= 0 && box.bottom <= innerHeight;
    });
    expect(bannerFits).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await context.setOffline(false);
    await expect(page.locator('#connectionBanner')).toBeHidden();
  });

  test('shows administrator-only system diagnostics', async ({page}) => {
    await page.goto('/dashboard');
    await expect(page.locator('#diagnosticsButton')).toBeVisible();
    const response = page.waitForResponse(result => result.url().endsWith('/api/diagnostics') && result.ok());
    await page.locator('#diagnosticsButton').click();
    const payload = await (await response).json();
    await expect(page.locator('#diagnosticsModal')).toHaveAttribute('open', '');
    await expect(page.locator('#diagnosticsContent')).toContainText('Application');
    await expect(page.locator('#diagnosticsContent')).toContainText('Storage');
    await expect(page.locator('#diagnosticsContent')).toContainText('Database');
    await expect(page.locator('#diagnosticsContent')).toContainText('Backup');
    await expect(page.locator('#diagnosticsContent')).toContainText('Passed');
    expect(payload.diagnostics.version).toMatch(/^\d+\.\d+\.\d+/);
    expect(payload.diagnostics.database.integrity).toBe('ok');
    expect(JSON.stringify(payload)).not.toContain('/var/www');
    await page.locator('#diagnosticsModal .dialog-close').first().click();
    await expect(page.locator('#diagnosticsModal')).not.toHaveAttribute('open', '');
  });

  test('manages plugins with a mandatory full-page reload', async ({page}) => {
    await page.goto('/dashboard');
    await page.route('**/api/plugins**', async route => {
      if (route.request().method() === 'GET') {
        await route.fulfill({json: {plugins: [{
          id: 'browser-probe', name: 'Browser probe', version: '1.2.3', enabled: true,
          manifest_enabled: true, override_enabled: null, effective_enabled: true,
          status: 'loaded', diagnostic: null,
          capabilities: {
            php_bootstrap: true, public_routes: true, migrations: 1, dashboard_widgets: 1, navigation_items: 0, css_assets: 1, js_assets: 1,
            profile_tools: true, profile_cards: true, page_information: true,
          },
        }]}});
        return;
      }
      await route.fulfill({json: {
        plugin: {id: 'browser-probe', effective_enabled: false, status: 'disabled'},
        reload_required: true,
      }});
    });

    await expect(page.locator('#pluginAdminButton')).toBeVisible();
    await page.locator('#pluginAdminButton').click();
    await expect(page.locator('#pluginAdminModal')).toHaveAttribute('open', '');
    await expect(page.locator('#pluginAdminList')).toContainText('Browser probe');
    await expect(page.locator('#pluginAdminList')).toContainText('PHP bootstrap');
    await expect(page.locator('#pluginAdminList')).toContainText('Public routes');
    await expect(page.locator('#pluginAdminList')).toContainText('1 migration');
    await expect(page.locator('#pluginAdminList')).toContainText('Profile tools');
    await expect(page.locator('#pluginAdminList')).toContainText('Profile cards');
    await expect(page.locator('#pluginAdminList')).toContainText('Page information');
    await expect(page.locator('#pluginAdminList')).toContainText('Manifest default');
    await Promise.all([
      page.waitForNavigation(),
      page.getByRole('button', {name: 'Disable', exact: true}).click(),
    ]);
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.locator('#pluginAdminModal')).not.toHaveAttribute('open', '');
    await page.unroute('**/api/plugins**');

    await page.locator('#pluginAdminButton').click();
    await page.locator('#pluginZip').setInputFiles({
      name: 'reference-plugin.zip',
      mimeType: 'application/zip',
      buffer: Buffer.from('UEsDBBQAAAAIAB2n+VyHQD+y6wAAAKoBAAALABwAcGx1Z2luLmpzb25VVAkAA3l3ZWoGDm1qdXgLAAEE6AMAAAToAwAAdY/JagMxEETv8xWNzvaMQ26BEPILuQZjeqSWLaNRCy1OgvG/R4sXCOTSh1elUtV5ABAOFxIvID5IUyAnCbzNe+PEqqonCtGwq4ancTNuOiWHsyVVqEYbqTHJLgUz51TssSifwgfWxtIuMdsoVnAHEoPqAPe0M05zWLC+E9seFXtAuFUaK+na8a90vCkK42HmEl0NBQCc2y1SMsn+P7JZZlY/1fEOjt26NFVZ1kpA37h4S8Aa0oEgZu85JFLgnq8h0KajTOMjLgdb0yb0ZuquON07rzuZTKIlTtcP3k6Gvl4fI1rSpdztcBl+AVBLAwQUAAAACADpK/lcMpPA0FoAAABiAAAADQAcAHJlZmVyZW5jZS5jc3NVVAkAA4aeZGoGDm1qdXgLAAEE6AMAAAToAwAAJcgxCoAwDADAva8oTjrE7oovEYU0ja0gjaTqIv5dweWGawOW5AU1AH2MSXmZh8rhvrp9O+Oai/uKlTMx/OOqyd7GWi8aWIFkE+3shVoDIBHnA8qhkmPTm8e8UEsDBBQAAAAIAOkr+Vx4mw9HNAAAADwAAAAMABwAcmVmZXJlbmNlLmpzVVQJAAOGnmRqBg5tanV4CwABBOgDAAAE6AMAAEvJTy7NTc0r0UuBMlxzUiH8xJLE4tQSvaLUtNSi1Lzk1ICc0vTMPAVbBfWi1MSUSnVrLgBQSwMEFAAAAAgAHaf5XEk/4h3HAgAAUggAAA0AHABib290c3RyYXAucGhwVVQJAAN6d2VqBg5tanV4CwABBOgDAAAE6AMAAK1US08bMRC+51f4gORdiRCq9hQacqhQHwdAgUOlEEXO7iQx3dhbPwIp5b93/NjNJixVKfiy3vHMeL5vvvHHYbksOzlkBVOQaKN4ZqZmU4IevEtPOh2rgZy/v/liTHkzgp8WtDnZM+pSCg219bKwCy7iZwQLjkk3mEmBsUoQbZjhGZlbkRkuBUl2HcmBiru0T9aS5+ShQ3DV5u5pqeScF3AtZZFU2UTClGIYnUlh4N5gcDAMTsnYJ3CLFmwGBXVGelGCIDEVUVBKZejh1tOq6NdjJe+VvkbdUzAHBSKDbrD0uIGV7sU0wzWHuwElR0SxO0yAjjKHxLMqFmlV25gym3OXhU7ScOUEqW5H+Ymp/KUoDTcFhOovI0BtVyumNk2EM5lvvJNPMUWPZFthvJ1OcMsWMM2kFUZjvQiOEocTVJdZs5SK/4KcOCeNmTyVCjRgnpxwQcyS64rmo7cheBdLG3VYzFcxl2rFnMRG8u4VOhlVFXnlQhPCmhU28NwgDu92rGVMTCHnhk7IkNAz3LGZa0rfZWQ5kaL4CwIlrYFke9Pns+vmxf9C2YP7THn+2Ah8Mntxot3VfnMY2TjAWVbITTXccQirdeD6QAZ1WPcUP2qTUGenh4RWLYrIqhWfgCprv3+rpUjGOz4eYEASGrCPr4Gn9o9YQy/qogKL9WHaEugLdlFcT8OYJh5cil0LKPtbNC3xRrEM9q5dYn9BJfR7t9ZO99r7tVWwAhyi/cqDMWnzd52poCZcmNT3akzRNNl1nzTYfwxH7kX3DXxeapcXV/+vtR6swb0Tr5Cc//v9nPBKtikkjk9Te15Ee0rjc5JsnQcDImxRpM8JkIJSUgW5nd2XkLnHi5FvVxfnRM5u8f8IySUfjo/bBd2iYPnDpzPKwlvq1fO7L9iIcxxP8cUZRrxD8vQQBW3LHJ+yvE3QL5BXm7oeTzp/AFBLAQIeAxQAAAAIAB2n+VyHQD+y6wAAAKoBAAALABgAAAAAAAEAAAC0gQAAAABwbHVnaW4uanNvblVUBQADeXdlanV4CwABBOgDAAAE6AMAAFBLAQIeAxQAAAAIAOkr+Vwyk8DQWgAAAGIAAAANABgAAAAAAAEAAAC0gTABAAByZWZlcmVuY2UuY3NzVVQFAAOGnmRqdXgLAAEE6AMAAAToAwAAUEsBAh4DFAAAAAgA6Sv5XHibD0c0AAAAPAAAAAwAGAAAAAAAAQAAALSB0QEAAHJlZmVyZW5jZS5qc1VUBQADhp5kanV4CwABBOgDAAAE6AMAAFBLAQIeAxQAAAAIAB2n+VxJP+IdxwIAAFIIAAANABgAAAAAAAEAAAC0gUsCAABib290c3RyYXAucGhwVVQFAAN6d2VqdXgLAAEE6AMAAAToAwAAUEsFBgAAAAAEAAQASQEAAFkFAAAAAA==', 'base64'),
    });
    await Promise.all([
      page.waitForNavigation(),
      page.getByRole('button', {name: 'Install plugin'}).click(),
    ]);
    await page.locator('#pluginAdminButton').click();
    await expect(page.locator('#pluginAdminList')).toContainText('Reference plugin');
    await expect(page.locator('#pluginAdminList')).toContainText('disabled');
  });

});
