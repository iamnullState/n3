(() => {
  'use strict';

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  migrateLegacyStorage();
  const state = {
    appName: 'n3', spaces: [], pages: [], currentSpaceId: null, currentPageId: null,
    currentPage: null, mode: 'edit', saveTimer: null, savePending: false, tagTimer: null, draftBaseRevision: null, searching: 0, searchItems: [], searchIndex: 0, csrfToken: '', username: '', profile: null, isAdmin: false, isOnline: navigator.onLine, users: [], plugins: [], pluginInventory: [], diagnostics: null, settings: null, sharedWithMe: [], shares: [], historyItems: [], selectedRevision: null,
    expanded: new Set(JSON.parse(localStorage.getItem('n3.expanded') || '[]')),
    expandedSpaces: new Set(JSON.parse(localStorage.getItem('n3.expandedSpaces') || '[]')),
    recent: JSON.parse(localStorage.getItem('n3.recent') || '[]'), selectedMedia: null, mediaInsertRange: null, formattingRange: null,
  };

  const els = {};
  const mobileSidebar = window.matchMedia('(max-width: 900px)');
  const SIDEBAR_MIN_WIDTH = 224;
  const SIDEBAR_MAX_WIDTH = 420;
  const SIDEBAR_DEFAULT_WIDTH = 286;
  const SIDEBAR_KEY_STEP = 16;
  let sidebarReturnFocus = null;
  let desktopSidebarCollapsed = false;
  let sidebarResizePointerId = null;
  const ids = ['appShell','sidebar','sidebarResizeHandle','sidebarScrim','sidebarCollapse','sidebarClose','menuButton','workspaceMark','workspaceIcon','appName','workspaceButton','searchButton','homeButton','newSpaceButton','favoritesSection','favoritesList','recentSection','recentList','spaceName','spaceDot','spaceMenuButton','spaceSettings','newRootFolder','newRootPage','pageTree','themeButton','themeValue','appearanceAdminButton','trashButton','pluginAdminButton','diagnosticsButton','accountButton','collaborationButton','logoutButton','main','connectionBanner','connectionRetry','appState','appStateSpinner','appStateTitle','appStateMessage','appStateRetry','breadcrumbs','pageActions','formatbar','blockType','textColor','mediaSize','documentView','homeView','brandBanner','homeNewPage','recentCards','viewAllPages','sharedSection','sharedCards','pluginSection','pluginCards','pageIcon','pageTitle','byline','editor','mediaInput','featureImage','featureImageControls','featureImageAdd','featureImageAddLabel','featureImageOpacityField','featureImageOpacity','featureImageRemove','featureImageInput','saveState','saveConflict','saveConflictReload','wordCount','favoriteButton','publishButton','pageTags','collaborateButton','shareButton','tocButton','tocPanel','tocList','tocClose','moreButton','moreMenu','pageDiscovery','referenceList','referenceForm','addReferenceButton','cancelReferenceButton','similarList','pageInformation','pageInformationAuthor','pageInformationWords','pageInformationCreated','pageInformationPublishedField','pageInformationPublished','pageInformationUpdated','pageInformationPluginRows','searchModal','searchInput','searchResults','spaceModal','spaceForm','newSpaceMode','deleteSpace','spaceSubmit','trashModal','trashList','accountModal','profileForm','profileAvatarInput','profileAvatarImage','profileAvatarFallback','profileAvatarUpload','profileAvatarRemove','profilePreviewAvatar','profilePreviewFallback','profilePreviewHeading','profilePreviewUsername','profilePreviewBiography','profilePreviewVisibility','profilePreviewUrl','accountForm','invalidateSessions','appearanceModal','appearanceForm','lightThemeTokens','darkThemeTokens','brandIconInput','brandIconUpload','brandBannerInput','brandBannerUpload','pluginAdminModal','pluginAdminList','pluginUploadForm','pluginZip','diagnosticsModal','diagnosticsContent','diagnosticsRefresh','historyModal','historyList','historyMeta','historyPreview','historyRestore','shareModal','shareForm','shareList','collaboratorForm','mediaLightbox','mediaLightboxClose','mediaLightboxContent','toastRegion'];

  document.addEventListener('DOMContentLoaded', init);

  function migrateLegacyStorage() {
    const keys = ['expanded', 'expandedSpaces', 'recent', 'space', 'theme']
      .map(name => [`folio.${name}`, `n3.${name}`]);
    try {
      for (let index = 0; index < localStorage.length; index++) {
        const key = localStorage.key(index);
        if (/^folio\.draft\.\d+$/.test(key || '')) keys.push([key, `n3.${key.slice(6)}`]);
      }
    } catch { /* Storage can be unavailable in private browsing modes. */ }
    keys.forEach(([legacyKey, currentKey]) => {
      try {
        const legacyValue = localStorage.getItem(legacyKey);
        if (legacyValue === null) return;
        if (localStorage.getItem(currentKey) === null) localStorage.setItem(currentKey, legacyValue);
        if (localStorage.getItem(currentKey) !== null) localStorage.removeItem(legacyKey);
      } catch { /* Retain the legacy value when a copy cannot be completed. */ }
    });
  }

  async function init() {
    ids.forEach(id => els[id] = document.getElementById(id));
    els.pluginNavigation = document.getElementById('pluginNavigation');
    applyStoredSidebarWidth();
    readStoredSidebarCollapsed();
    setSidebarOpen(false);
    bindEvents();
    setTocOpen(false);
    setMoreMenuOpen(false);
    setConnectionState(navigator.onLine);
    applyTheme(localStorage.getItem('n3.theme') || 'system');
    try {
      const data = await api('/api/bootstrap');
      state.appName = data.appName;
      state.csrfToken = data.csrfToken;
      state.username = data.username;
      state.isAdmin = Boolean(data.isAdmin);
      els.pluginAdminButton.classList.toggle('hidden', !state.isAdmin);
      els.diagnosticsButton.classList.toggle('hidden', !state.isAdmin);
      els.appearanceAdminButton.classList.toggle('hidden', !state.isAdmin);
      state.settings = data.settings || null;
      state.users = data.users || [];
      state.plugins = data.plugins || [];
      state.sharedWithMe = data.sharedWithMe || [];
      state.spaces = data.spaces;
      state.pages = data.pages.map(normalizePage);
      state.currentSpaceId = Number(localStorage.getItem('n3.space')) || Number(state.spaces[0]?.id);
      if (!state.spaces.some(s => Number(s.id) === state.currentSpaceId)) state.currentSpaceId = Number(state.spaces[0]?.id);
      document.title = state.appName;
      els.appName.textContent = state.appName;
      applyBrandSettings();
      loadPluginAssets();
      renderAllNavigation();
      const initial = pageFromLocation();
      if (initial) {
        const opened = await openPage(initial.id, false);
        if (opened) history.replaceState(null, '', editorPageUrl(initial));
        else showHome();
      } else showHome(location.pathname !== '/dashboard' || Boolean(location.hash));
      els.appState.classList.add('hidden');
      els.main.setAttribute('aria-busy', 'false');
    } catch (error) {
      showFatal(error);
    }
  }

  function normalizePage(page) {
    return {...page, kind: page.kind === 'folder' ? 'folder' : 'page', id: Number(page.id), space_id: Number(page.space_id), parent_id: page.parent_id === null ? null : Number(page.parent_id), position: Number(page.position), is_favorite: Number(page.is_favorite), is_public: Number(page.is_public), content_revision: Number(page.content_revision || 1), feature_image: typeof page.feature_image === 'string' && page.feature_image ? page.feature_image : null, feature_image_opacity: clampFeatureOpacity(page.feature_image_opacity), can_edit: Boolean(page.can_edit), can_manage: Boolean(page.can_manage), tags: Array.isArray(page.tags) ? page.tags : [], references: Array.isArray(page.references) ? page.references : [], related: Array.isArray(page.related) ? page.related : []};
  }

  function editorPageUrl(page) {
    return `/page/${encodeURIComponent(page.slug || page.id)}`;
  }

  function pageFromLocation() {
    const pathMatch = location.pathname.match(/^\/page\/([a-z0-9-]+)$/i);
    if (pathMatch) return state.pages.find(page => page.kind === 'page' && page.slug === decodeURIComponent(pathMatch[1]));
    const hashId = Number(new URLSearchParams(location.hash.slice(1)).get('page'));
    return state.pages.find(page => page.kind === 'page' && page.id === hashId);
  }

  async function navigateFromLocation() {
    const target = pageFromLocation();
    if (target) {
      if (target.id !== state.currentPageId) await openPage(target.id, false);
      return;
    }
    if (location.pathname === '/dashboard') showHome(false);
  }

  function bindEvents() {
    els.menuButton.addEventListener('click', openSidebarFromMenu);
    els.sidebarCollapse.addEventListener('click', () => setDesktopSidebarCollapsed(true, true, true));
    [els.sidebarClose, els.sidebarScrim].forEach(el => el.addEventListener('click', () => closeSidebar(true)));
    els.sidebarResizeHandle.addEventListener('pointerdown', beginSidebarResize);
    els.sidebarResizeHandle.addEventListener('pointermove', resizeSidebarFromPointer);
    els.sidebarResizeHandle.addEventListener('pointerup', finishSidebarResize);
    els.sidebarResizeHandle.addEventListener('pointercancel', finishSidebarResize);
    els.sidebarResizeHandle.addEventListener('lostpointercapture', finishSidebarResize);
    els.sidebarResizeHandle.addEventListener('keydown', resizeSidebarFromKeyboard);
    els.searchButton.addEventListener('click', openSearch);
    els.homeButton.addEventListener('click', showHome);
    els.newSpaceButton.addEventListener('click', openNewSpace);
    els.homeNewPage.addEventListener('click', () => createPage(null));
    els.viewAllPages.addEventListener('click', openSearch);
    els.newRootPage.addEventListener('click', () => createPage(null));
    els.newRootFolder.addEventListener('click', () => createFolder(null));
    els.themeButton.addEventListener('click', cycleTheme);
    els.appearanceAdminButton.addEventListener('click', openAppearanceSettings);
    els.spaceSettings.addEventListener('click', openSpaceSettings);
    els.spaceMenuButton.addEventListener('click', toggleSpaceDirectory);
    els.workspaceButton.addEventListener('click', () => toast(`${state.appName} is private and ready.`));
    els.trashButton.addEventListener('click', openTrash);
    els.pluginAdminButton.addEventListener('click', openPluginAdmin);
    els.diagnosticsButton.addEventListener('click', openDiagnostics);
    els.accountButton.addEventListener('click', openAccount);
    els.collaborationButton.addEventListener('click', openCollaboration);
    els.logoutButton.addEventListener('click', logout);
    els.pageTitle.addEventListener('input', scheduleSave);
    els.editor.addEventListener('input', () => { scheduleSave(); updateWordCount(); buildToc(); });
    els.editor.addEventListener('click', onEditorLinkClick);
    els.editor.addEventListener('click', selectMedia);
    els.editor.addEventListener('dblclick', event => { const media = event.target.closest('img,video'); if (media) openMediaLightbox(media); });
    els.editor.addEventListener('blur', flushSave);
    els.favoriteButton.addEventListener('click', toggleFavorite);
    els.publishButton.addEventListener('click', togglePublished);
    els.collaborateButton.addEventListener('click', openCollaboration);
    els.pageTags.addEventListener('input', scheduleTagSave);
    els.shareButton.addEventListener('click', () => exportPage('html'));
    els.tocButton.addEventListener('click', () => setTocOpen(!els.tocPanel.classList.contains('open')));
    els.tocClose.addEventListener('click', () => setTocOpen(false, true));
    els.moreButton.addEventListener('click', event => toggleMoreMenu(event.currentTarget));
    els.moreMenu.addEventListener('click', onMoreAction);
    els.moreMenu.addEventListener('keydown', onMoreMenuKeydown);
    els.pageTree.addEventListener('click', onTreeClick);
    els.pageTree.addEventListener('dblclick', onFolderRename);
    els.pageTree.addEventListener('dragstart', onTreeDragStart);
    els.pageTree.addEventListener('dragover', onTreeDragOver);
    els.pageTree.addEventListener('dragleave', onTreeDragLeave);
    els.pageTree.addEventListener('drop', onTreeDrop);
    els.pageTree.addEventListener('dragend', clearTreeDragState);
    [els.favoritesList, els.recentList, els.recentCards].forEach(el => el.addEventListener('click', event => {
      const target = event.target.closest('[data-page-id]');
      if (target) openPage(Number(target.dataset.pageId));
    }));
    $$('.mode-button').forEach(button => button.addEventListener('click', () => setMode(button.dataset.mode)));
    els.blockType.addEventListener('change', () => { document.execCommand('formatBlock', false, els.blockType.value); els.editor.focus(); scheduleSave(); });
    els.textColor.addEventListener('pointerdown', rememberFormattingRange);
    els.textColor.addEventListener('change', applySelectedTextColor);
    els.mediaSize.addEventListener('change', resizeSelectedMedia);
    els.formatbar.addEventListener('mousedown', event => { if (event.target.closest('button')) event.preventDefault(); });
    els.formatbar.addEventListener('click', onToolbarClick);
    els.mediaInput.addEventListener('change', uploadMedia);
    els.featureImageAdd.addEventListener('click', () => els.featureImageInput.click());
    els.featureImageInput.addEventListener('change', uploadFeatureImage);
    els.featureImageRemove.addEventListener('click', removeFeatureImage);
    els.featureImageOpacity.addEventListener('change', saveFeatureImageOpacity);
    els.searchInput.addEventListener('input', debounce(runSearch, 180));
    els.searchInput.addEventListener('keydown', onSearchKeydown);
    els.searchResults.addEventListener('click', event => {
      const target = event.target.closest('[data-search-id]');
      if (target) { els.searchModal.close(); openPage(Number(target.dataset.searchId)); }
    });
    els.spaceForm.addEventListener('submit', saveSpaceSettings);
    els.newSpaceMode.addEventListener('click', beginNewSpace);
    els.deleteSpace.addEventListener('click', deleteCurrentSpace);
    els.profileForm.addEventListener('submit', saveProfile);
    els.profileForm.addEventListener('input', renderProfilePreview);
    els.profileAvatarUpload.addEventListener('click', () => els.profileAvatarInput.click());
    els.profileAvatarInput.addEventListener('change', uploadProfileAvatar);
    els.profileAvatarRemove.addEventListener('click', removeProfileAvatar);
    els.accountForm.addEventListener('submit', saveAccount);
    els.invalidateSessions.addEventListener('click', invalidateSessions);
    els.pluginAdminList.addEventListener('click', event => {
      const button = event.target.closest('[data-plugin-toggle]');
      if (button) updatePluginEnablement(button);
    });
    els.pluginUploadForm.addEventListener('submit', uploadPlugin);
    els.appearanceForm.addEventListener('submit', saveAppearanceSettings);
    els.brandIconUpload.addEventListener('click', () => uploadBrandAsset('icon'));
    els.brandBannerUpload.addEventListener('click', () => uploadBrandAsset('banner'));
    els.diagnosticsRefresh.addEventListener('click', loadDiagnostics);
    els.connectionRetry.addEventListener('click', retryConnection);
    els.appStateRetry.addEventListener('click', () => location.reload());
    els.saveConflictReload.addEventListener('click', () => location.reload());
    $$('.dialog-close').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    els.trashList.addEventListener('click', event => {
      const button = event.target.closest('[data-restore]');
      if (button) restorePage(Number(button.dataset.restore));
      const purge = event.target.closest('[data-purge]');
      if (purge) purgePage(Number(purge.dataset.purge), purge.dataset.title);
    });
    els.historyList.addEventListener('click', event => {
      const button = event.target.closest('[data-revision]');
      if (button) showRevision(Number(button.dataset.revision));
    });
    els.historyRestore.addEventListener('click', restoreSelectedRevision);
    els.addReferenceButton.addEventListener('click', () => {
      els.referenceForm.classList.remove('hidden');
      els.addReferenceButton.setAttribute('aria-expanded', 'true');
      els.referenceForm.elements.label.focus();
    });
    els.cancelReferenceButton.addEventListener('click', () => {
      els.referenceForm.reset();
      els.referenceForm.classList.add('hidden');
      els.addReferenceButton.setAttribute('aria-expanded', 'false');
      els.addReferenceButton.focus();
    });
    els.referenceForm.addEventListener('submit', addReference);
    els.referenceList.addEventListener('click', event => { const button = event.target.closest('[data-reference-remove]'); if (button) removeReference(Number(button.dataset.referenceRemove)); });
    els.similarList.addEventListener('click', event => { const button = event.target.closest('[data-related-page]'); if (button) openPage(Number(button.dataset.relatedPage)); });
    els.shareForm.addEventListener('submit', grantShare);
    els.shareForm.elements.resource_type.addEventListener('change', loadShares);
    els.shareList.addEventListener('click', event => { const button = event.target.closest('[data-share-remove]'); if (button) revokeShare(Number(button.dataset.shareRemove)); });
    els.collaboratorForm.addEventListener('submit', createCollaborator);
    els.mediaLightboxClose.addEventListener('click', () => els.mediaLightbox.close());
    els.mediaLightbox.addEventListener('click', event => { if (event.target === els.mediaLightbox) els.mediaLightbox.close(); });
    els.main.addEventListener('scroll', () => els.main.classList.toggle('scrolled', els.main.scrollTop > 5), {passive: true});
    document.addEventListener('click', event => {
      if (!event.target.closest('#moreMenu') && !event.target.closest('#moreButton')) setMoreMenuOpen(false);
    });
    document.addEventListener('keydown', onGlobalKeydown);
    window.addEventListener('popstate', navigateFromLocation);
    window.addEventListener('hashchange', navigateFromLocation);
    window.addEventListener('beforeunload', () => { if (state.saveTimer) flushSave(); });
    window.addEventListener('offline', () => setConnectionState(false));
    window.addEventListener('online', retryConnection);
    mobileSidebar.addEventListener('change', () => setSidebarOpen(false));
  }

  async function api(url, options = {}) {
    const method = String(options.method || 'GET').toUpperCase();
    const csrf = method === 'GET' || method === 'HEAD' ? {} : {'X-CSRF-Token': state.csrfToken};
    const jsonBody = options.body && !(options.body instanceof FormData);
    const config = {...options, headers: {...(jsonBody ? {'Content-Type': 'application/json'} : {}), ...csrf, ...(options.headers || {})}};
    let response;
    try {
      response = await fetch(url, config);
      setConnectionState(true);
    } catch {
      setConnectionState(false);
      const error = new Error('n3 is unreachable. Check your connection and try again.');
      error.offline = true;
      throw error;
    }
    if (response.status === 401) { location.assign('/login'); throw new Error('Authentication required.'); }
    let data;
    try { data = await response.json(); } catch { data = null; }
    if (!response.ok) {
      const error = new Error(data?.error || `Request failed (${response.status})`);
      error.status = response.status;
      throw error;
    }
    return data;
  }

  function renderAllNavigation() {
    const space = currentSpace();
    if (space) {
      els.spaceName.textContent = 'Directory';
      els.spaceDot.style.background = 'var(--accent)';
      localStorage.setItem('n3.space', String(space.id));
    }
    els.newRootPage.disabled = !space?.can_edit;
    els.newRootFolder.disabled = !space?.can_edit;
    els.homeNewPage.disabled = !space?.can_edit;
    els.spaceSettings.disabled = !space?.can_manage;
    renderPageTree();
    renderShortcuts();
    renderPluginNavigation();
    renderHomeCards();
    renderDashboardExtensions();
  }

  function renderPluginNavigation() {
    const items = state.plugins.flatMap(plugin => (plugin.navigation || []).map(item => ({...item, plugin: plugin.name})));
    els.pluginNavigation.classList.toggle('hidden', !items.length);
    els.pluginNavigation.innerHTML = items.map(item => `<a class="nav-item" href="${escapeHtml(item.url)}" title="${escapeHtml(item.plugin)}"><span class="shortcut-icon" aria-hidden="true">${escapeHtml(item.icon || '◆')}</span><span>${escapeHtml(item.label)}</span></a>`).join('');
  }

  function loadPluginAssets() {
    state.plugins.forEach(plugin => {
      (plugin.css || []).forEach(url => {
        if ($(`link[data-plugin-asset="${CSS.escape(url)}"]`)) return;
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        link.dataset.pluginAsset = url;
        document.head.append(link);
      });
      (plugin.js || []).forEach(url => {
        if ($(`script[data-plugin-asset="${CSS.escape(url)}"]`)) return;
        const script = document.createElement('script');
        script.src = url;
        script.defer = true;
        script.dataset.pluginAsset = url;
        document.head.append(script);
      });
    });
  }

  async function logout() {
    await flushSave();
    try {
      await api('/logout', {method: 'POST'});
      location.assign('/login');
    } catch (error) {
      toast(error.message, true);
    }
  }

  async function openAccount() {
    els.accountModal.showModal();
    els.accountModal.setAttribute('aria-busy', 'true');
    closeSidebar();
    els.profileForm.reset();
    els.accountForm.reset();
    try {
      state.profile = await api('/api/profile');
      state.username = state.profile.username;
      els.profileForm.elements.display_name.value = state.profile.display_name || '';
      els.profileForm.elements.username.value = state.profile.username;
      els.profileForm.elements.biography.value = state.profile.biography || '';
      els.profileForm.elements.profile_visibility.value = state.profile.profile_visibility;
      renderProfilePreview();
      setProfileAvatar(state.profile.has_avatar ? state.profile.avatar_url : null);
      els.profileForm.elements.display_name.focus();
    } catch (error) {
      toast(error.message, true);
      els.accountModal.close();
    } finally {
      els.accountModal.removeAttribute('aria-busy');
    }
  }

  function renderProfilePreview() {
    if (!state.profile) return;
    const displayName = els.profileForm.elements.display_name.value.trim() || els.profileForm.elements.username.value.trim() || state.username;
    const username = els.profileForm.elements.username.value.trim() || state.username;
    const biography = els.profileForm.elements.biography.value.trim();
    const visibility = els.profileForm.elements.profile_visibility.value;
    const visibilityLabels = {private: 'Private', members: 'Members', public: 'Public'};
    els.profilePreviewHeading.textContent = displayName;
    els.profilePreviewUsername.textContent = `@${username}`;
    els.profilePreviewBiography.textContent = biography || 'Your biography will appear here.';
    els.profilePreviewVisibility.textContent = visibilityLabels[visibility] || 'Private';
    els.profilePreviewUrl.textContent = state.profile.profile_url;
    const initial = [...displayName][0]?.toUpperCase() || '?';
    els.profileAvatarFallback.textContent = initial;
    els.profilePreviewFallback.textContent = initial;
  }

  function setProfileAvatar(url) {
    const frames = [els.profileAvatarImage.parentElement, els.profilePreviewAvatar.parentElement];
    const images = [els.profileAvatarImage, els.profilePreviewAvatar];
    frames.forEach(frame => frame.classList.toggle('has-avatar', Boolean(url)));
    images.forEach(image => {
      image.onerror = () => image.parentElement.classList.remove('has-avatar');
      if (url) image.src = `${url}?v=${Date.now()}`;
      else image.removeAttribute('src');
    });
    els.profileAvatarRemove.disabled = !url;
  }

  async function saveProfile(event) {
    event.preventDefault();
    const payload = Object.fromEntries(new FormData(els.profileForm));
    try {
      const result = await api('/api/profile', {method: 'PUT', body: JSON.stringify(payload)});
      if (result.csrfToken) state.csrfToken = result.csrfToken;
      state.profile = result;
      state.username = result.username;
      els.profileForm.elements.current_password.value = '';
      renderProfilePreview();
      toast('Profile settings saved');
    } catch (error) { toast(error.message, true); }
  }

  async function uploadProfileAvatar() {
    const file = els.profileAvatarInput.files?.[0];
    els.profileAvatarInput.value = '';
    if (!file) return;
    const form = new FormData();
    form.append('avatar', file);
    try {
      const result = await api('/api/profile/avatar', {method: 'POST', body: form});
      state.profile = {...state.profile, has_avatar: true, avatar_url: result.avatar_url};
      renderProfilePreview();
      setProfileAvatar(result.avatar_url);
      toast('Profile photo updated');
    } catch (error) { toast(error.message, true); }
  }

  async function removeProfileAvatar() {
    if (!state.profile?.has_avatar) return;
    try {
      await api('/api/profile/avatar', {method: 'DELETE'});
      state.profile = {...state.profile, has_avatar: false, avatar_url: null};
      renderProfilePreview();
      setProfileAvatar(null);
      toast('Profile photo removed');
    } catch (error) { toast(error.message, true); }
  }

  async function saveAccount(event) {
    event.preventDefault();
    const currentPassword = els.accountForm.elements.current_password.value;
    const newPassword = els.accountForm.elements.new_password.value;
    if (!newPassword) { toast('Enter a new password first.', true); return; }
    try {
      const result = await api('/api/account', {method: 'PUT', body: JSON.stringify({username: state.username, current_password: currentPassword, new_password: newPassword})});
      state.username = result.username;
      state.csrfToken = result.csrfToken;
      els.accountForm.reset();
      toast('Password updated; other sessions were signed out');
    } catch (error) { toast(error.message, true); }
  }

  async function invalidateSessions() {
    const currentPassword = els.accountForm.elements.current_password.value;
    if (!currentPassword) { toast('Enter your current password first.', true); return; }
    try {
      const result = await api('/api/account/invalidate-sessions', {method: 'POST', body: JSON.stringify({current_password: currentPassword})});
      state.csrfToken = result.csrfToken;
      els.accountForm.elements.current_password.value = '';
      toast('Other sessions signed out');
    } catch (error) { toast(error.message, true); }
  }

  async function openPluginAdmin() {
    if (!state.isAdmin) return;
    els.pluginAdminList.innerHTML = '<div class="plugin-admin-empty">Loading plugin inventory…</div>';
    els.pluginAdminModal.showModal();
    closeSidebar();
    try {
      const result = await api('/api/plugins');
      state.pluginInventory = Array.isArray(result.plugins) ? result.plugins : [];
      renderPluginAdmin();
    } catch (error) {
      els.pluginAdminList.innerHTML = `<div class="plugin-admin-empty plugin-admin-error">${escapeHtml(error.message)}</div>`;
    }
  }

  function renderPluginAdmin() {
    if (!state.pluginInventory.length) {
      els.pluginAdminList.innerHTML = '<div class="plugin-admin-empty">No plugins are installed.</div>';
      return;
    }
    els.pluginAdminList.innerHTML = state.pluginInventory.map(plugin => {
      const status = ['enabled', 'disabled', 'invalid', 'loaded', 'failed'].includes(plugin.status) ? plugin.status : 'invalid';
      const capabilities = plugin.capabilities || {};
      const capabilityLabels = [];
      if (capabilities.php_bootstrap) capabilityLabels.push('PHP bootstrap');
      if (capabilities.public_routes) capabilityLabels.push('Public routes');
      if (Number(capabilities.migrations)) capabilityLabels.push(`${Number(capabilities.migrations)} ${Number(capabilities.migrations) === 1 ? 'migration' : 'migrations'}`);
      if (Number(capabilities.dashboard_widgets)) capabilityLabels.push(`${Number(capabilities.dashboard_widgets)} dashboard ${Number(capabilities.dashboard_widgets) === 1 ? 'widget' : 'widgets'}`);
      if (Number(capabilities.navigation_items)) capabilityLabels.push(`${Number(capabilities.navigation_items)} navigation ${Number(capabilities.navigation_items) === 1 ? 'item' : 'items'}`);
      if (Number(capabilities.css_assets)) capabilityLabels.push(`${Number(capabilities.css_assets)} CSS ${Number(capabilities.css_assets) === 1 ? 'asset' : 'assets'}`);
      if (Number(capabilities.js_assets)) capabilityLabels.push(`${Number(capabilities.js_assets)} JavaScript ${Number(capabilities.js_assets) === 1 ? 'asset' : 'assets'}`);
      if (capabilities.profile_tools) capabilityLabels.push('Profile tools');
      if (capabilities.profile_cards) capabilityLabels.push('Profile cards');
      if (capabilities.page_information) capabilityLabels.push('Page information');
      const override = plugin.override_enabled === null ? 'Manifest default' : plugin.override_enabled ? 'Enabled' : 'Disabled';
      const action = plugin.effective_enabled ? 'Disable' : 'Enable';
      const disabled = status === 'invalid';
      return `<article class="plugin-admin-item" data-plugin-status="${status}">
        <div class="plugin-admin-heading"><div><span class="plugin-status plugin-status-${status}">${escapeHtml(status)}</span><h3>${escapeHtml(plugin.name)}</h3><code>${escapeHtml(plugin.id)}</code></div><span class="plugin-version">v${escapeHtml(plugin.version)}</span></div>
        <dl class="plugin-state-grid"><div><dt>Manifest</dt><dd>${plugin.manifest_enabled ? 'Enabled' : 'Disabled'}</dd></div><div><dt>Override</dt><dd>${override}</dd></div><div><dt>Effective</dt><dd>${plugin.effective_enabled ? 'Enabled' : 'Disabled'}</dd></div></dl>
        <div class="plugin-capabilities">${(capabilityLabels.length ? capabilityLabels : ['Manifest only']).map(label => `<span>${escapeHtml(label)}</span>`).join('')}</div>
        ${plugin.diagnostic ? `<div class="plugin-diagnostic" role="status">${escapeHtml(plugin.diagnostic)}</div>` : ''}
        <div class="plugin-admin-actions"><small>${disabled ? 'Fix the manifest before changing this plugin.' : 'A full application reload applies this change.'}</small><button class="${plugin.effective_enabled ? 'secondary-button' : 'primary-button'}" data-plugin-toggle="${escapeHtml(plugin.id)}" data-plugin-enabled="${plugin.effective_enabled ? 'true' : 'false'}" type="button" ${disabled ? 'disabled' : ''}>${action}</button></div>
      </article>`;
    }).join('');
  }

  async function updatePluginEnablement(button) {
    if (!state.isAdmin || button.disabled) return;
    const pluginId = button.dataset.pluginToggle;
    const enabled = button.dataset.pluginEnabled !== 'true';
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.textContent = 'Applying…';
    try {
      await flushSave();
      const result = await api(`/api/plugins/${encodeURIComponent(pluginId)}`, {method: 'PUT', body: JSON.stringify({enabled})});
      if (!result.reload_required) throw new Error('The plugin state changed without a required reload marker.');
      location.reload();
    } catch (error) {
      button.disabled = false;
      button.removeAttribute('aria-busy');
      button.textContent = enabled ? 'Enable' : 'Disable';
      toast(error.message, true);
    }
  }

  async function uploadPlugin(event) {
    event.preventDefault();
    const file = els.pluginZip.files?.[0];
    if (!file) return;
    const button = els.pluginUploadForm.querySelector('button');
    const form = new FormData();
    form.append('plugin', file);
    button.disabled = true;
    button.textContent = 'Installing…';
    try {
      await flushSave();
      const result = await api('/api/plugins/upload', {method: 'POST', body: form});
      if (!result.reload_required) throw new Error('Plugin installed without a reload marker.');
      location.reload();
    } catch (error) {
      button.disabled = false;
      button.textContent = 'Install plugin';
      toast(error.message, true);
    }
  }

  function openAppearanceSettings() {
    if (!state.isAdmin || !state.settings) return;
    const settings = state.settings;
    els.appearanceForm.elements.brandName.value = settings.brandName || 'n3';
    els.appearanceForm.elements.appUrl.value = settings.appUrl || location.origin;
    els.appearanceForm.elements.tailscaleIp.value = settings.tailscaleIp || '';
    els.appearanceForm.elements.port.value = settings.port || 8786;
    renderThemeTokenInputs('light', settings.themes?.light || {});
    renderThemeTokenInputs('dark', settings.themes?.dark || {});
    els.appearanceModal.showModal();
    closeSidebar();
  }

  function renderThemeTokenInputs(mode, values) {
    const target = mode === 'light' ? els.lightThemeTokens : els.darkThemeTokens;
    const labels = {'bg': 'Page', 'surface': 'Surface', 'sidebar': 'Sidebar', 'raised': 'Raised', 'text': 'Text', 'muted': 'Muted text', 'line': 'Lines', 'accent': 'Accent', 'accent-soft': 'Soft accent', 'accent-strong': 'Strong accent'};
    target.innerHTML = Object.entries(labels).map(([token, label]) => `<label>${label}<span class="token-color-row"><input type="color" value="${escapeHtml(values[token] || '#000000')}" data-theme-mode="${mode}" data-theme-token="${token}"><code>${escapeHtml(token)}</code></span></label>`).join('');
  }

  async function saveAppearanceSettings(event) {
    event.preventDefault();
    const themes = {light: {}, dark: {}};
    $$('[data-theme-token]', els.appearanceForm).forEach(input => { themes[input.dataset.themeMode][input.dataset.themeToken] = input.value; });
    const payload = {
      brandName: els.appearanceForm.elements.brandName.value,
      appUrl: els.appearanceForm.elements.appUrl.value,
      tailscaleIp: els.appearanceForm.elements.tailscaleIp.value,
      port: Number(els.appearanceForm.elements.port.value),
      themes,
    };
    try {
      const result = await api('/api/settings', {method: 'PUT', body: JSON.stringify(payload)});
      state.settings = result.settings;
      state.appName = result.settings.brandName;
      els.appName.textContent = state.appName;
      document.title = state.appName;
      applyBrandSettings();
      els.appearanceModal.close();
      toast('Brand and theme saved');
    } catch (error) { toast(error.message, true); }
  }

  async function uploadBrandAsset(kind) {
    const input = kind === 'icon' ? els.brandIconInput : els.brandBannerInput;
    const file = input.files?.[0];
    if (!file) return toast(`Choose a brand ${kind} first.`, true);
    const form = new FormData();
    form.append('image', file);
    try {
      const result = await api(`/api/settings/brand/${kind}`, {method: 'POST', body: form});
      state.settings = result.settings;
      input.value = '';
      applyBrandSettings();
      toast(`Brand ${kind} uploaded`);
    } catch (error) { toast(error.message, true); }
  }

  function applyBrandSettings() {
    const settings = state.settings;
    if (!settings) return;
    const cacheBust = `?v=${Date.now()}`;
    els.workspaceIcon.classList.toggle('hidden', !settings.iconUrl);
    els.workspaceMark.querySelector('span').classList.toggle('hidden', Boolean(settings.iconUrl));
    if (settings.iconUrl) els.workspaceIcon.src = settings.iconUrl + cacheBust;
    els.brandBanner.classList.toggle('hidden', !settings.bannerUrl);
    if (settings.bannerUrl) els.brandBanner.src = settings.bannerUrl + cacheBust;
    applyThemeTokens();
  }

  function applyThemeTokens() {
    if (!state.settings?.themes) return;
    const selected = document.documentElement.dataset.theme || 'system';
    const mode = selected === 'system' ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : selected;
    Object.entries(state.settings.themes[mode] || {}).forEach(([token, value]) => document.documentElement.style.setProperty(`--${token}`, value));
  }

  async function openDiagnostics() {
    if (!state.isAdmin) return;
    els.diagnosticsModal.showModal();
    closeSidebar();
    await loadDiagnostics();
  }

  async function loadDiagnostics() {
    if (!state.isAdmin) return;
    els.diagnosticsContent.innerHTML = '<div class="diagnostics-empty">Running storage and database checks…</div>';
    els.diagnosticsRefresh.disabled = true;
    els.diagnosticsRefresh.setAttribute('aria-busy', 'true');
    try {
      const result = await api('/api/diagnostics');
      state.diagnostics = result.diagnostics || null;
      renderDiagnostics();
    } catch (error) {
      els.diagnosticsContent.innerHTML = `<div class="diagnostics-empty diagnostics-error">${escapeHtml(error.message)}</div>`;
    } finally {
      els.diagnosticsRefresh.disabled = false;
      els.diagnosticsRefresh.removeAttribute('aria-busy');
    }
  }

  function renderDiagnostics() {
    const diagnostics = state.diagnostics;
    if (!diagnostics) {
      els.diagnosticsContent.innerHTML = '<div class="diagnostics-empty diagnostics-error">Diagnostics are unavailable.</div>';
      return;
    }
    const storage = diagnostics.storage || {};
    const database = diagnostics.database || {};
    const backup = diagnostics.backup || {};
    const backupDetail = backup.status === 'available'
      ? `${formatBytes(backup.size_bytes)} · ${formatAge(backup.age_seconds)} ago`
      : backup.status === 'missing' ? 'No backup archive found' : 'Backup directory unavailable';
    els.diagnosticsContent.innerHTML = `<div class="diagnostics-grid">
      <article class="diagnostics-card" data-diagnostic-status="ok"><div class="diagnostics-heading"><h3>Application</h3>${diagnosticBadge('ok')}</div><dl><div><dt>Version</dt><dd>${escapeHtml(diagnostics.version || 'unknown')}</dd></div><div><dt>Checked</dt><dd>${escapeHtml(formatTimestamp(diagnostics.checked_at))}</dd></div></dl></article>
      <article class="diagnostics-card" data-diagnostic-status="${escapeHtml(storage.status || 'error')}"><div class="diagnostics-heading"><h3>Storage</h3>${diagnosticBadge(storage.status)}</div><dl><div><dt>Data directory</dt><dd>${storage.data_writable ? 'Writable' : 'Not writable'}</dd></div><div><dt>Database file</dt><dd>${storage.database_writable ? 'Writable' : 'Not writable'}</dd></div><div><dt>Capacity</dt><dd>${formatBytes(storage.free_bytes)} free of ${formatBytes(storage.total_bytes)}</dd></div><div><dt>Database size</dt><dd>${formatBytes(storage.database_bytes)}</dd></div></dl></article>
      <article class="diagnostics-card" data-diagnostic-status="${escapeHtml(database.status || 'error')}"><div class="diagnostics-heading"><h3>Database</h3>${diagnosticBadge(database.status)}</div><dl><div><dt>Integrity</dt><dd>${diagnosticResult(database.integrity)}</dd></div><div><dt>Foreign keys</dt><dd>${diagnosticResult(database.foreign_keys)}</dd></div><div><dt>Schema version</dt><dd>${database.schema_version === null || database.schema_version === undefined ? 'Unavailable' : escapeHtml(String(database.schema_version))}</dd></div></dl></article>
      <article class="diagnostics-card" data-diagnostic-status="${escapeHtml(backup.status || 'unavailable')}"><div class="diagnostics-heading"><h3>Backup</h3>${diagnosticBadge(backup.status)}</div><dl><div><dt>Latest archive</dt><dd>${escapeHtml(backupDetail)}</dd></div><div><dt>Created</dt><dd>${escapeHtml(backup.latest_at ? formatTimestamp(backup.latest_at) : 'Unavailable')}</dd></div></dl></article>
    </div>`;
  }

  function diagnosticBadge(status) {
    const normalized = ['ok', 'warning', 'error', 'available', 'missing', 'unavailable'].includes(status) ? status : 'error';
    const labels = {ok: 'Healthy', warning: 'Warning', error: 'Needs attention', available: 'Available', missing: 'Missing', unavailable: 'Unavailable'};
    return `<span class="diagnostics-status diagnostics-status-${normalized}">${labels[normalized]}</span>`;
  }

  function diagnosticResult(status) {
    return status === 'ok' ? 'Passed' : status === 'error' ? 'Failed' : 'Unavailable';
  }

  function formatBytes(value) {
    const bytes = Number(value);
    if (!Number.isFinite(bytes) || bytes < 0) return 'Unavailable';
    if (bytes < 1024) return `${bytes} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let amount = bytes / 1024;
    let unit = 0;
    while (amount >= 1024 && unit < units.length - 1) { amount /= 1024; unit++; }
    return `${amount >= 10 ? amount.toFixed(0) : amount.toFixed(1)} ${units[unit]}`;
  }

  function formatAge(value) {
    const seconds = Number(value);
    if (!Number.isFinite(seconds) || seconds < 0) return 'an unknown time';
    if (seconds < 60) return 'less than a minute';
    if (seconds < 3600) return `${Math.floor(seconds / 60)} min`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)} hr`;
    return `${Math.floor(seconds / 86400)} day${Math.floor(seconds / 86400) === 1 ? '' : 's'}`;
  }

  function formatTimestamp(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Unavailable' : date.toLocaleString();
  }

  function currentSpace() { return state.spaces.find(space => Number(space.id) === Number(state.currentSpaceId)); }
  function pageById(id) { return state.pages.find(page => page.id === Number(id)); }

  function renderPageTree() {
    const branch = (page, byParent, seen, depth = 0) => {
      if (seen.has(page.id) || depth > 20) return '';
      seen.add(page.id);
      const children = byParent.get(page.id) || [];
      const expanded = state.expanded.has(page.id);
      const folder = page.kind === 'folder';
      return `<div class="tree-branch ${expanded ? 'expanded' : ''}" data-branch="${page.id}" data-kind="${page.kind}" draggable="${page.can_edit ? 'true' : 'false'}">
        <div class="tree-row ${page.id === state.currentPageId ? 'active' : ''}">
          <button class="tree-toggle ${children.length ? '' : 'empty'}" data-tree-toggle="${page.id}" type="button" ${children.length ? `aria-label="${expanded ? 'Collapse' : 'Expand'} ${escapeHtml(page.title)}" aria-expanded="${expanded}"` : 'aria-hidden="true" tabindex="-1" disabled'}><svg><use href="#chevron-right"></use></svg></button>
          <button class="tree-page" data-${folder ? 'folder' : 'page'}-id="${page.id}" type="button"><span class="tree-icon">${folder ? '▰' : page.is_favorite ? '★' : '◇'}</span><span>${escapeHtml(page.title)}</span></button>
          <span class="tree-create-actions">${page.can_edit ? `${folder ? `<button class="mini-button tree-add" data-rename-folder="${page.id}" type="button" aria-label="Rename folder"><svg><use href="#edit"></use></svg></button><button class="mini-button tree-add" data-delete-folder="${page.id}" type="button" aria-label="Delete folder"><svg><use href="#trash"></use></svg></button>` : ''}<button class="mini-button tree-add" data-add-folder="${page.id}" type="button" aria-label="Add folder"><svg><use href="#folder"></use></svg></button><button class="mini-button tree-add" data-add-child="${page.id}" type="button" aria-label="Add page"><svg><use href="#file-plus"></use></svg></button>` : ''}</span>
        </div>
        ${children.length ? `<div class="tree-children ${expanded ? '' : 'hidden'}">${children.map(child => branch(child, byParent, seen, depth + 1)).join('')}</div>` : ''}
      </div>`;
    };
    els.pageTree.innerHTML = state.spaces.map(space => {
      const spaceId = Number(space.id);
      const visible = state.pages.filter(page => page.space_id === spaceId);
      const byParent = new Map();
      visible.forEach(page => {
        const key = page.parent_id && visible.some(candidate => candidate.id === page.parent_id) ? page.parent_id : 0;
        if (!byParent.has(key)) byParent.set(key, []);
        byParent.get(key).push(page);
      });
      byParent.forEach(items => items.sort((a, b) => a.position - b.position || a.title.localeCompare(b.title)));
      const expanded = state.expandedSpaces.has(spaceId) || spaceId === state.currentSpaceId;
      if (spaceId === state.currentSpaceId) state.expandedSpaces.add(spaceId);
      const roots = (byParent.get(0) || []).map(page => branch(page, byParent, new Set())).join('');
      return `<section class="space-tree ${expanded ? 'expanded' : ''}" data-space-root="${spaceId}">
        <div class="space-tree-row ${spaceId === state.currentSpaceId ? 'active' : ''}">
          <button class="tree-toggle" data-space-toggle="${spaceId}" type="button" aria-label="${expanded ? 'Collapse' : 'Expand'} ${escapeHtml(space.name)}" aria-expanded="${expanded}"><svg><use href="#chevron-right"></use></svg></button>
          <button class="space-tree-name" data-space-select="${spaceId}" type="button"><span class="space-dot" style="background:${escapeHtml(space.color)}"></span><span>${escapeHtml(space.name)}</span></button>
          <span class="tree-create-actions">${space.can_edit ? `<button class="mini-button tree-add" data-space-folder="${spaceId}" type="button" aria-label="Add folder"><svg><use href="#folder"></use></svg></button><button class="mini-button tree-add" data-space-page="${spaceId}" type="button" aria-label="Add page"><svg><use href="#file-plus"></use></svg></button>` : ''}</span>
        </div>
        <div class="space-tree-children ${expanded ? '' : 'hidden'}">${roots || '<div class="tree-empty">Empty space</div>'}</div>
      </section>`;
    }).join('') || '<div class="search-empty">No spaces yet.</div>';
    localStorage.setItem('n3.expanded', JSON.stringify([...state.expanded]));
    localStorage.setItem('n3.expandedSpaces', JSON.stringify([...state.expandedSpaces]));
  }

  function renderShortcuts() {
    const favorites = state.pages.filter(page => page.kind === 'page' && page.is_favorite).slice(0, 5);
    els.favoritesSection.classList.toggle('hidden', !favorites.length);
    els.favoritesList.innerHTML = favorites.map(page => shortcutHtml(page, '★')).join('');
    const recentPages = state.recent.map(pageById).filter(Boolean).slice(0, 4);
    els.recentSection.classList.toggle('hidden', !recentPages.length);
    els.recentList.innerHTML = recentPages.map(page => shortcutHtml(page, '◇')).join('');
  }

  function shortcutHtml(page, icon) {
    return `<button class="shortcut-item ${page.id === state.currentPageId ? 'active' : ''}" data-page-id="${page.id}" type="button"><span class="shortcut-icon">${icon}</span><span>${escapeHtml(page.title)}</span></button>`;
  }

  function renderHomeCards() {
    const pages = state.recent.map(pageById).filter(Boolean);
    const shown = (pages.length ? pages : state.pages.filter(page => page.kind === 'page')).slice(0, 6);
    els.recentCards.innerHTML = shown.length
      ? shown.map(page => `<button class="page-card" data-page-id="${page.id}" type="button"><span class="page-card-icon">${page.is_favorite ? '★' : '✦'}</span><strong>${escapeHtml(page.title)}</strong><small>${relativeTime(page.updated_at)}</small></button>`).join('')
      : '<div class="dashboard-empty"><strong>No pages available yet.</strong><span>Create a page when you have edit access, or ask an owner to share one with you.</span></div>';
  }

  function renderDashboardExtensions() {
    els.sharedSection.classList.toggle('hidden', !state.sharedWithMe.length);
    els.sharedCards.innerHTML = state.sharedWithMe.map(item => {
      const page = item.resource_type === 'page' ? pageById(item.resource_id) : null;
      const action = page ? `data-page-id="${page.id}"` : item.resource_type === 'space' ? `data-shared-space="${Number(item.resource_id)}"` : '';
      return `<button class="dashboard-card" ${action} type="button"><span class="eyebrow">${escapeHtml(item.role)} · ${escapeHtml(item.owner_name || 'another user')}</span><strong>${escapeHtml(item.title || 'Shared resource')}</strong><small>${item.resource_type === 'space' ? 'Shared space' : 'Shared page subtree'}</small></button>`;
    }).join('');
    $$('[data-page-id]', els.sharedCards).forEach(button => button.addEventListener('click', () => openPage(Number(button.dataset.pageId))));
    $$('[data-shared-space]', els.sharedCards).forEach(button => button.addEventListener('click', () => {
      state.currentSpaceId = Number(button.dataset.sharedSpace);
      state.expandedSpaces.add(state.currentSpaceId);
      renderAllNavigation();
      showHome();
    }));

    const widgets = state.plugins.flatMap(plugin => (plugin.dashboard || []).map(widget => ({...widget, plugin: plugin.name})));
    els.pluginSection.classList.toggle('hidden', !widgets.length);
    els.pluginCards.innerHTML = widgets.map(widget => {
      const content = `<span class="eyebrow">${escapeHtml(widget.plugin)}</span><strong>${escapeHtml(widget.title)}</strong><small>${escapeHtml(widget.body)}</small>`;
      return widget.url ? `<a class="dashboard-card" href="${escapeHtml(widget.url)}">${content}</a>` : `<div class="dashboard-card">${content}</div>`;
    }).join('');
  }

  async function openPage(id, pushHash = true) {
    if (state.tagTimer) await saveTags();
    if (state.currentPageId !== Number(id)) await flushSave();
    els.main.setAttribute('aria-busy', 'true');
    try {
      const page = normalizePage(await api(`/api/pages/${id}`));
      state.currentPage = page;
      state.currentPageId = page.id;
      state.currentSpaceId = page.space_id;
      state.draftBaseRevision = null;
      state.recent = [page.id, ...state.recent.filter(item => Number(item) !== page.id)].slice(0, 12);
      localStorage.setItem('n3.recent', JSON.stringify(state.recent));
      els.pageTitle.value = page.title;
      els.editor.innerHTML = page.content || '<p></p>';
      els.pageIcon.textContent = page.is_favorite ? '★' : '✦';
      els.favoriteButton.classList.toggle('active', Boolean(page.is_favorite));
      els.favoriteButton.setAttribute('aria-label', page.is_favorite ? 'Remove favorite' : 'Add favorite');
      els.favoriteButton.setAttribute('aria-pressed', String(Boolean(page.is_favorite)));
      els.publishButton.querySelector('span').textContent = page.is_public ? 'Public' : 'Private';
      els.publishButton.classList.toggle('active', Boolean(page.is_public));
      els.publishButton.setAttribute('aria-label', page.is_public ? 'Make page private' : 'Publish page');
      els.publishButton.setAttribute('aria-pressed', String(Boolean(page.is_public)));
      els.favoriteButton.disabled = !page.can_edit;
      els.publishButton.disabled = !page.can_edit;
      els.collaborateButton.classList.toggle('hidden', !page.can_manage);
      els.pageTags.value = page.tags.join(', ');
      els.pageTags.readOnly = !page.can_edit;
      els.byline.innerHTML = `Last edited <strong>${relativeTime(page.updated_at)}</strong>`;
      recoverDraft(page);
      els.documentView.classList.remove('hidden');
      els.homeView.classList.add('hidden');
      els.pageActions.classList.remove('hidden');
      els.main.scrollTo({top: 0});
      renderBreadcrumbs(page);
      renderAllNavigation();
      updateWordCount();
      buildToc();
      renderPageDiscovery();
      renderPageInformation();
      renderFeatureImage();
      setSaveState('saved');
      if (!page.can_edit) state.mode = 'read';
      setMode(state.mode);
      if (pushHash) history.pushState(null, '', editorPageUrl(page));
      closeSidebar();
      return true;
    } catch (error) {
      toast(error.message, true);
      return false;
    } finally {
      els.main.setAttribute('aria-busy', 'false');
    }
  }

  function showHome(pushHistory = true) {
    if (state.tagTimer) saveTags();
    flushSave();
    state.currentPageId = null;
    state.currentPage = null;
    renderFeatureImage();
    els.documentView.classList.add('hidden');
    els.homeView.classList.remove('hidden');
    els.appState.classList.add('hidden');
    els.pageActions.classList.add('hidden');
    els.formatbar.classList.remove('visible');
    els.breadcrumbs.innerHTML = '<span>Home</span>';
    renderPageTree();
    renderHomeCards();
    if (pushHistory && (location.pathname !== '/dashboard' || location.hash)) history.pushState(null, '', '/dashboard');
    closeSidebar();
  }

  function renderBreadcrumbs(page) {
    const parents = [];
    let cursor = page;
    const guarded = new Set();
    while (cursor?.parent_id && !guarded.has(cursor.parent_id)) {
      guarded.add(cursor.parent_id);
      cursor = pageById(cursor.parent_id);
      if (cursor) parents.unshift(cursor);
    }
    const parts = [escapeHtml(currentSpace()?.name || ''), ...parents.map(p => escapeHtml(p.title)), escapeHtml(page.title)];
    els.breadcrumbs.innerHTML = parts.map((part, index) => `${index ? '<i>/</i>' : ''}<span>${part}</span>`).join('');
  }

  async function createPage(parentId, spaceId = state.currentSpaceId) {
    if (!spaceId) return;
    try {
      state.currentSpaceId = Number(spaceId);
      state.expandedSpaces.add(Number(spaceId));
      const result = await api('/api/pages', {method: 'POST', body: JSON.stringify({space_id: spaceId, parent_id: parentId, kind: 'page', title: 'Untitled'})});
      if (parentId) state.expanded.add(Number(parentId));
      await reloadBootstrap();
      await openPage(result.id);
      els.pageTitle.focus();
      els.pageTitle.select();
    } catch (error) { toast(error.message, true); }
  }

  async function createFolder(parentId, spaceId = state.currentSpaceId) {
    if (!spaceId) return;
    try {
      state.currentSpaceId = Number(spaceId);
      state.expandedSpaces.add(Number(spaceId));
      const result = await api('/api/pages', {method: 'POST', body: JSON.stringify({space_id: spaceId, parent_id: parentId, kind: 'folder', title: 'New folder'})});
      if (parentId) state.expanded.add(Number(parentId));
      state.expanded.add(Number(result.id));
      await reloadBootstrap();
      renderPageTree();
      toast('Folder created');
    } catch (error) { toast(error.message, true); }
  }

  async function reloadBootstrap() {
    const data = await api('/api/bootstrap');
    state.spaces = data.spaces;
    state.pages = data.pages.map(normalizePage);
    state.users = data.users || [];
    state.plugins = data.plugins || [];
    state.sharedWithMe = data.sharedWithMe || [];
    loadPluginAssets();
    renderAllNavigation();
  }

  function onTreeClick(event) {
    const spaceToggle = event.target.closest('[data-space-toggle]');
    if (spaceToggle) {
      const id = Number(spaceToggle.dataset.spaceToggle);
      state.expandedSpaces.has(id) ? state.expandedSpaces.delete(id) : state.expandedSpaces.add(id);
      renderPageTree();
      return;
    }
    const spaceSelect = event.target.closest('[data-space-select]');
    if (spaceSelect) {
      state.currentSpaceId = Number(spaceSelect.dataset.spaceSelect);
      state.expandedSpaces.add(state.currentSpaceId);
      renderAllNavigation();
      showHome();
      return;
    }
    const spacePage = event.target.closest('[data-space-page]');
    if (spacePage) { createPage(null, Number(spacePage.dataset.spacePage)); return; }
    const spaceFolder = event.target.closest('[data-space-folder]');
    if (spaceFolder) { createFolder(null, Number(spaceFolder.dataset.spaceFolder)); return; }
    const toggle = event.target.closest('[data-tree-toggle]');
    if (toggle) {
      const id = Number(toggle.dataset.treeToggle);
      state.expanded.has(id) ? state.expanded.delete(id) : state.expanded.add(id);
      renderPageTree();
      return;
    }
    const add = event.target.closest('[data-add-child]');
    if (add) { createPage(Number(add.dataset.addChild)); return; }
    const addFolder = event.target.closest('[data-add-folder]');
    if (addFolder) { createFolder(Number(addFolder.dataset.addFolder)); return; }
    const deleteFolder = event.target.closest('[data-delete-folder]');
    if (deleteFolder) { removeFolder(Number(deleteFolder.dataset.deleteFolder)); return; }
    const renameFolder = event.target.closest('[data-rename-folder]');
    if (renameFolder) { renameFolderById(Number(renameFolder.dataset.renameFolder)); return; }
    const folder = event.target.closest('[data-folder-id]');
    if (folder) {
      const id = Number(folder.dataset.folderId);
      state.expanded.has(id) ? state.expanded.delete(id) : state.expanded.add(id);
      renderPageTree();
      return;
    }
    const page = event.target.closest('[data-page-id]');
    if (page) openPage(Number(page.dataset.pageId));
  }

  async function onFolderRename(event) {
    const button = event.target.closest('[data-folder-id]');
    if (!button) return;
    await renameFolderById(Number(button.dataset.folderId));
  }

  async function renameFolderById(id) {
    const folder = pageById(id);
    const title = prompt('Folder name:', folder?.title || 'New folder')?.trim();
    if (!folder || !title || title === folder.title) return;
    try {
      await api(`/api/pages/${folder.id}`, {method: 'PUT', body: JSON.stringify({title})});
      await reloadBootstrap();
      toast('Folder renamed');
    } catch (error) { toast(error.message, true); }
  }

  async function removeFolder(id) {
    const folder = pageById(id);
    if (!folder || !confirm(`Move “${folder.title}” and everything inside it to trash?`)) return;
    try {
      await api(`/api/pages/${id}`, {method: 'DELETE'});
      await reloadBootstrap();
      toast('Folder moved to trash');
    } catch (error) { toast(error.message, true); }
  }

  function onTreeDragStart(event) {
    const branch = event.target.closest('.tree-branch');
    if (!branch) return;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', branch.dataset.branch);
    branch.classList.add('dragging');
  }

  function onTreeDragOver(event) {
    const branch = event.target.closest('.tree-branch');
    const spaceRoot = event.target.closest('.space-tree');
    if (!branch && spaceRoot) {
      event.preventDefault();
      clearTreeDropTargets();
      spaceRoot.classList.add('drop-inside');
      return;
    }
    if (!branch || branch.classList.contains('dragging')) return;
    event.preventDefault();
    clearTreeDropTargets();
    const row = branch.querySelector(':scope > .tree-row');
    const ratio = (event.clientY - row.getBoundingClientRect().top) / row.getBoundingClientRect().height;
    const zone = branch.dataset.kind === 'folder' && ratio > .25 && ratio < .75 ? 'inside' : ratio < .5 ? 'before' : 'after';
    branch.classList.add(`drop-${zone}`);
  }

  function onTreeDragLeave(event) {
    if (!event.currentTarget.contains(event.relatedTarget)) clearTreeDropTargets();
  }

  async function onTreeDrop(event) {
    event.preventDefault();
    const targetBranch = event.target.closest('.tree-branch');
    const targetSpaceRoot = event.target.closest('.space-tree');
    const sourceId = Number(event.dataTransfer.getData('text/plain'));
    const source = pageById(sourceId);
    if (!source) return clearTreeDragState();
    if (!targetBranch && targetSpaceRoot) {
      const targetSpaceId = Number(targetSpaceRoot.dataset.spaceRoot);
      const siblings = state.pages.filter(item => item.space_id === targetSpaceId && item.parent_id === null && item.id !== source.id).sort((a, b) => a.position - b.position);
      siblings.push(source);
      try {
        await reorderTree(source.id, targetSpaceId, null, siblings);
        state.currentSpaceId = targetSpaceId;
        state.expandedSpaces.add(targetSpaceId);
        await reloadBootstrap();
        toast('Moved into space');
      } catch (error) { toast(error.message, true); }
      return clearTreeDragState();
    }
    const targetId = Number(targetBranch?.dataset.branch);
    const target = pageById(targetId);
    if (!target || source.id === target.id) return clearTreeDragState();
    const zone = targetBranch.classList.contains('drop-inside') ? 'inside' : targetBranch.classList.contains('drop-before') ? 'before' : 'after';
    const parentId = zone === 'inside' ? target.id : target.parent_id;
    if (parentId === source.id || isDescendant(parentId, source.id)) {
      clearTreeDragState();
      return toast('A folder cannot be moved inside itself.', true);
    }
    const siblings = state.pages.filter(item => item.space_id === target.space_id && item.parent_id === parentId && item.id !== source.id).sort((a, b) => a.position - b.position);
    const targetIndex = zone === 'inside' ? siblings.length : Math.max(0, siblings.findIndex(item => item.id === target.id) + (zone === 'after' ? 1 : 0));
    siblings.splice(targetIndex, 0, source);
    try {
      await reorderTree(source.id, target.space_id, parentId, siblings);
      if (parentId) state.expanded.add(parentId);
      await reloadBootstrap();
      toast('Tree arranged');
    } catch (error) { toast(error.message, true); }
    clearTreeDragState();
  }

  function reorderTree(sourceId, spaceId, parentId, orderedItems) {
    return api('/api/tree/reorder', {method: 'PUT', body: JSON.stringify({
      source_id: sourceId,
      space_id: spaceId,
      parent_id: parentId,
      ordered_ids: orderedItems.map(item => item.id),
    })});
  }

  function isDescendant(candidateId, ancestorId) {
    let cursor = pageById(candidateId);
    const seen = new Set();
    while (cursor && !seen.has(cursor.id)) {
      if (cursor.id === ancestorId) return true;
      seen.add(cursor.id);
      cursor = pageById(cursor.parent_id);
    }
    return false;
  }

  function clearTreeDropTargets() {
    $$('.drop-before,.drop-after,.drop-inside', els.pageTree).forEach(item => item.classList.remove('drop-before', 'drop-after', 'drop-inside'));
  }

  function clearTreeDragState() {
    clearTreeDropTargets();
    $$('.dragging', els.pageTree).forEach(branch => branch.classList.remove('dragging'));
  }

  function scheduleSave() {
    if (!state.currentPageId || state.mode === 'read' || !state.currentPage?.can_edit) return;
    persistDraft();
    state.savePending = true;
    clearTimeout(state.saveTimer);
    if (!state.isOnline) { state.saveTimer = null; setSaveState('offline'); return; }
    setSaveState('saving');
    state.saveTimer = setTimeout(flushSave, 750);
  }

  async function flushSave() {
    if ((!state.saveTimer && !state.savePending) || !state.currentPageId || !state.currentPage) return;
    clearTimeout(state.saveTimer);
    state.saveTimer = null;
    const pageId = state.currentPageId;
    const title = els.pageTitle.value.trim() || 'Untitled';
    const content = els.editor.innerHTML;
    const baseRevision = state.draftBaseRevision ?? state.currentPage.content_revision;
    if (!state.isOnline) { state.savePending = true; persistDraft(); setSaveState('offline'); return; }
    try {
      const result = await api(`/api/pages/${pageId}`, {method: 'PUT', body: JSON.stringify({title, content, base_revision: baseRevision})});
      if (state.currentPageId !== pageId) return;
      state.currentPage.title = title;
      state.currentPage.content = content;
      state.currentPage.updated_at = result.updated_at;
      state.currentPage.content_revision = Number(result.content_revision);
      state.draftBaseRevision = null;
      state.savePending = false;
      const meta = pageById(pageId);
      if (meta) { meta.title = title; meta.updated_at = result.updated_at; meta.content_revision = Number(result.content_revision); }
      localStorage.removeItem(draftKey(pageId));
      els.byline.innerHTML = 'Last edited <strong>just now</strong>';
      if (state.currentPage.page_information) state.currentPage.page_information.updated_at = result.updated_at;
      renderPageInformation();
      renderBreadcrumbs(state.currentPage);
      renderAllNavigation();
      els.saveConflict.classList.add('hidden');
      setSaveState('saved');
    } catch (error) {
      state.savePending = true;
      persistDraft();
      if (error.status === 409) {
        setSaveState('conflict');
        els.saveConflict.classList.remove('hidden');
        toast('A newer version exists. Your local draft is safe.', true);
      } else {
        setSaveState(error.offline ? 'offline' : 'error');
        toast(error.offline ? 'Offline. Your local draft is safe and will retry when connected.' : `Could not save: ${error.message}`, true);
      }
    }
  }

  function draftKey(pageId) { return `n3.draft.${pageId}`; }

  function persistDraft() {
    if (!state.currentPageId || !state.currentPage) return;
    try {
      localStorage.setItem(draftKey(state.currentPageId), JSON.stringify({
        title: els.pageTitle.value,
        content: els.editor.innerHTML,
        baseRevision: state.draftBaseRevision ?? state.currentPage.content_revision,
        savedAt: new Date().toISOString(),
      }));
    } catch { /* Storage can be unavailable in private browsing modes. */ }
  }

  function recoverDraft(page) {
    let draft;
    try { draft = JSON.parse(localStorage.getItem(draftKey(page.id)) || 'null'); } catch { draft = null; }
    if (!draft || (draft.title === page.title && draft.content === page.content)) {
      if (draft) localStorage.removeItem(draftKey(page.id));
      return;
    }
    if (confirm(`A local draft from ${relativeTime(draft.savedAt)} was not saved. Restore it?`)) {
      const draftRevision = Number(draft.baseRevision);
      state.draftBaseRevision = Number.isInteger(draftRevision) && draftRevision > 0 ? draftRevision : page.content_revision;
      els.pageTitle.value = draft.title || page.title;
      els.editor.innerHTML = draft.content || '<p></p>';
      scheduleSave();
      toast('Local draft restored');
    } else {
      localStorage.removeItem(draftKey(page.id));
    }
  }

  function setSaveState(kind) {
    const states = {saving: 'Saving…', offline: 'Offline · draft saved', conflict: 'Conflict · draft safe', error: 'Save failed', saved: 'Saved'};
    els.saveState.className = `save-state ${kind === 'saved' ? '' : kind}`;
    $('span:last-child', els.saveState).textContent = states[kind] || states.saved;
  }

  function setMode(mode) {
    const editing = mode === 'edit' && state.currentPage?.can_edit !== false;
    state.mode = editing ? 'edit' : 'read';
    $$('.mode-button').forEach(button => {
      button.classList.toggle('active', button.dataset.mode === state.mode);
      button.setAttribute('aria-pressed', String(button.dataset.mode === state.mode));
      if (button.dataset.mode === 'edit') button.disabled = state.currentPage?.can_edit === false;
    });
    els.editor.contentEditable = String(editing);
    els.pageTitle.readOnly = !editing;
    document.body.classList.toggle('read-mode', !editing);
    if (editing) els.formatbar.classList.add('visible'); else els.formatbar.classList.remove('visible');
    renderFeatureImage();
  }

  function onToolbarClick(event) {
    const button = event.target.closest('button');
    if (!button) return;
    const command = button.dataset.command;
    if (command) document.execCommand(command, false, null);
    const action = button.dataset.action;
    if (action === 'internal-link') insertInternalLink();
    if (action === 'link') {
      const url = prompt('Paste a link URL:');
      if (url && /^(https?:\/\/|mailto:|\/|#)/i.test(url)) document.execCommand('createLink', false, url);
      else if (url) toast('Use an http(s), mailto, /, or # link.', true);
    }
    if (action === 'code') document.execCommand('insertHTML', false, `<code>${escapeHtml(window.getSelection()?.toString() || 'code')}</code>&nbsp;`);
    if (action === 'callout') document.execCommand('insertHTML', false, '<div class="callout callout-purple"><span class="callout-icon">✦</span><div><strong>Worth noting</strong><p>Type something helpful here.</p></div></div><p><br></p>');
    if (action === 'table') document.execCommand('insertHTML', false, '<table><thead><tr><th>Heading</th><th>Heading</th><th>Heading</th></tr></thead><tbody><tr><td>Cell</td><td>Cell</td><td>Cell</td></tr><tr><td>Cell</td><td>Cell</td><td>Cell</td></tr></tbody></table><p><br></p>');
    if (action === 'divider') document.execCommand('insertHorizontalRule', false);
    if (action === 'media') {
      const selection = window.getSelection();
      state.mediaInsertRange = selection?.rangeCount && els.editor.contains(selection.anchorNode) ? selection.getRangeAt(0).cloneRange() : null;
      els.mediaInput.click();
      return;
    }
    if (action?.startsWith('media-')) {
      alignSelectedMedia(action.slice(6));
      return;
    }
    els.editor.focus();
    scheduleSave();
  }

  function rememberFormattingRange() {
    const selection = window.getSelection();
    state.formattingRange = selection?.rangeCount && els.editor.contains(selection.anchorNode) ? selection.getRangeAt(0).cloneRange() : null;
  }

  function applySelectedTextColor() {
    if (!state.formattingRange || state.formattingRange.collapsed) return toast('Select text before choosing a color.', true);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(state.formattingRange);
    document.execCommand('foreColor', false, els.textColor.value);
    state.formattingRange = null;
    els.editor.focus();
    scheduleSave();
  }

  function selectMedia(event) {
    const media = event.target.closest('img,video');
    if (media && state.mode === 'read') {
      openMediaLightbox(media);
      return;
    }
    $$('.media-selected', els.editor).forEach(item => item.classList.remove('media-selected'));
    state.selectedMedia = media || null;
    if (media) media.classList.add('media-selected');
  }

  function alignSelectedMedia(alignment) {
    const media = state.selectedMedia;
    if (!media || !els.editor.contains(media)) return toast('Select a photo or video first.', true);
    media.classList.remove('media-float-left', 'media-float-right', 'media-column', 'media-center', 'media-selected');
    if (alignment === 'left') media.classList.add('media-float-left');
    else if (alignment === 'right') media.classList.add('media-float-right', 'media-column');
    else media.classList.add('media-center');
    state.selectedMedia = null;
    scheduleSave();
  }

  function resizeSelectedMedia() {
    const media = state.selectedMedia;
    const size = els.mediaSize.value;
    els.mediaSize.value = '';
    if (!media || !els.editor.contains(media)) return toast('Select a photo or video first.', true);
    media.classList.remove('media-size-25', 'media-size-50', 'media-size-75', 'media-size-100');
    if (['25', '50', '75', '100'].includes(size)) media.classList.add(`media-size-${size}`);
    scheduleSave();
    toast(`Media resized to ${size}%`);
  }

  async function uploadMedia() {
    const file = els.mediaInput.files?.[0];
    els.mediaInput.value = '';
    if (!file) return;
    if (file.size > 50 * 1024 * 1024) return toast('Media must be 50 MB or smaller.', true);
    const form = new FormData();
    form.append('media', file);
    toast('Uploading media…');
    try {
      const uploaded = await api('/api/media', {method: 'POST', body: form});
      els.editor.focus();
      if (state.mediaInsertRange) {
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(state.mediaInsertRange);
      }
      const markup = uploaded.kind === 'video'
        ? `<video class="media-column media-float-right" src="${escapeHtml(uploaded.url)}" controls preload="metadata"></video><p><br></p>`
        : `<img class="media-column media-float-right" src="${escapeHtml(uploaded.url)}" alt="${escapeHtml(file.name.replace(/\.[^.]+$/, ''))}"><p><br></p>`;
      document.execCommand('insertHTML', false, markup);
      state.mediaInsertRange = null;
      scheduleSave();
      toast(uploaded.kind === 'video' ? 'Video uploaded' : 'Photo uploaded');
    } catch (error) {
      toast(error.message, true);
    }
  }

  function clampFeatureOpacity(value) {
    const opacity = Math.round(Number(value));
    if (!Number.isFinite(opacity)) return 50;
    return Math.min(60, Math.max(40, opacity));
  }

  function renderFeatureImage() {
    const page = state.currentPage;
    const url = page && page.kind === 'page' ? page.feature_image : null;
    const opacity = clampFeatureOpacity(page?.feature_image_opacity);
    els.featureImage.style.setProperty('--feature-image', url ? `url("${encodeURI(url)}")` : '');
    els.featureImage.style.setProperty('--feature-image-opacity', String(opacity / 100));
    els.documentView.classList.toggle('has-feature-image', Boolean(url));
    els.featureImageControls.classList.toggle('hidden', !page || page.kind !== 'page' || !page.can_edit || state.mode === 'read');
    els.featureImageAddLabel.textContent = url ? 'Replace feature image' : 'Add feature image';
    els.featureImageOpacityField.classList.toggle('hidden', !url);
    els.featureImageRemove.classList.toggle('hidden', !url);
    els.featureImageOpacity.value = String(opacity);
  }

  async function saveFeatureImage(changes, message) {
    const page = state.currentPage;
    if (!page || !page.can_edit) return;
    const previous = {feature_image: page.feature_image, feature_image_opacity: page.feature_image_opacity};
    Object.assign(page, changes);
    renderFeatureImage();
    try {
      await api(`/api/pages/${page.id}`, {method: 'PUT', body: JSON.stringify(changes)});
      if (message) toast(message);
    } catch (error) {
      Object.assign(page, previous);
      renderFeatureImage();
      toast(error.message, true);
    }
  }

  async function uploadFeatureImage() {
    const file = els.featureImageInput.files?.[0];
    els.featureImageInput.value = '';
    if (!file || !state.currentPage) return;
    if (file.size > 50 * 1024 * 1024) return toast('A feature image must be 50 MB or smaller.', true);
    toast('Uploading feature image…');
    try {
      const uploaded = await api('/api/media', {method: 'POST', body: (() => { const form = new FormData(); form.append('media', file); return form; })()});
      if (uploaded.kind !== 'image') return toast('A feature image must be a photo, not a video.', true);
      await saveFeatureImage({feature_image: uploaded.url}, 'Feature image added');
    } catch (error) {
      toast(error.message, true);
    }
  }

  function removeFeatureImage() {
    return saveFeatureImage({feature_image: null}, 'Feature image removed');
  }

  function saveFeatureImageOpacity() {
    return saveFeatureImage({feature_image_opacity: clampFeatureOpacity(els.featureImageOpacity.value)});
  }

  function openMediaLightbox(media) {
    els.mediaLightboxContent.replaceChildren();
    const expanded = media.cloneNode(true);
    expanded.className = '';
    expanded.removeAttribute('contenteditable');
    if (expanded instanceof HTMLVideoElement) expanded.controls = true;
    els.mediaLightboxContent.append(expanded);
    els.mediaLightbox.showModal();
  }

  function insertInternalLink() {
    const selection = window.getSelection();
    const range = selection?.rangeCount && els.editor.contains(selection.anchorNode) ? selection.getRangeAt(0).cloneRange() : null;
    const selectedText = range?.toString().trim() || '';
    const query = prompt('Link to which n3 page?', selectedText);
    if (!query) return;
    const needle = query.trim().toLocaleLowerCase();
    const candidates = state.pages.filter(page => page.kind === 'page' && page.id !== state.currentPageId && page.title.toLocaleLowerCase().includes(needle));
    candidates.sort((a, b) => Number(b.title.toLocaleLowerCase() === needle) - Number(a.title.toLocaleLowerCase() === needle) || a.title.localeCompare(b.title));
    if (!candidates.length) { toast(`No page matches “${query.trim()}”.`, true); return; }
    let target = candidates[0];
    if (candidates.length > 1 && target.title.toLocaleLowerCase() !== needle) {
      const choices = candidates.slice(0, 9).map((page, index) => `${index + 1}. ${page.title}`).join('\n');
      const choice = Number(prompt(`Several pages match. Enter a number:\n\n${choices}`, '1'));
      if (!Number.isInteger(choice) || !candidates[choice - 1]) return;
      target = candidates[choice - 1];
    }
    if (range) {
      selection.removeAllRanges();
      selection.addRange(range);
    }
    const targetUrl = editorPageUrl(target);
    if (range && !range.collapsed) document.execCommand('createLink', false, targetUrl);
    else document.execCommand('insertHTML', false, `<a href="${targetUrl}">${escapeHtml(target.title)}</a>`);
    toast(`Linked to ${target.title}`);
  }

  function onEditorLinkClick(event) {
    const link = event.target.closest('a[href^="/page/"]');
    if (!link || (state.mode === 'edit' && !event.metaKey && !event.ctrlKey)) return;
    const match = link.getAttribute('href').match(/^\/page\/([a-z0-9-]+)$/i);
    if (!match) return;
    event.preventDefault();
    const target = state.pages.find(page => page.kind === 'page' && (page.slug === match[1] || (Number.isInteger(Number(match[1])) && page.id === Number(match[1]))));
    if (target) openPage(target.id); else toast('That linked page is unavailable.', true);
  }

  async function toggleFavorite() {
    if (!state.currentPage?.can_edit) return;
    const value = state.currentPage.is_favorite ? 0 : 1;
    try {
      await api(`/api/pages/${state.currentPageId}`, {method: 'PUT', body: JSON.stringify({is_favorite: value})});
      state.currentPage.is_favorite = value;
      const meta = pageById(state.currentPageId);
      if (meta) meta.is_favorite = value;
      els.favoriteButton.classList.toggle('active', Boolean(value));
      els.favoriteButton.setAttribute('aria-label', value ? 'Remove favorite' : 'Add favorite');
      els.favoriteButton.setAttribute('aria-pressed', String(Boolean(value)));
      els.pageIcon.textContent = value ? '★' : '✦';
      renderAllNavigation();
      toast(value ? 'Added to favorites' : 'Removed from favorites');
    } catch (error) { toast(error.message, true); }
  }

  async function togglePublished() {
    if (!state.currentPage?.can_edit) return;
    const value = state.currentPage.is_public ? 0 : 1;
    try {
      await api(`/api/pages/${state.currentPageId}`, {method: 'PUT', body: JSON.stringify({is_public: value})});
      state.currentPage.is_public = value;
      const meta = pageById(state.currentPageId);
      if (meta) meta.is_public = value;
      els.publishButton.querySelector('span').textContent = value ? 'Public' : 'Private';
      els.publishButton.classList.toggle('active', Boolean(value));
      els.publishButton.setAttribute('aria-label', value ? 'Make page private' : 'Publish page');
      els.publishButton.setAttribute('aria-pressed', String(Boolean(value)));
      await refreshPageInformation();
      toast(value ? 'Page is now public' : 'Page is now private');
    } catch (error) { toast(error.message, true); }
  }

  function scheduleTagSave() {
    if (!state.currentPage?.can_edit) return;
    clearTimeout(state.tagTimer);
    state.tagTimer = setTimeout(saveTags, 500);
  }

  async function saveTags() {
    if (!state.currentPageId) return;
    clearTimeout(state.tagTimer);
    state.tagTimer = null;
    const tags = [...new Set(els.pageTags.value.split(',').map(tag => tag.trim().toLowerCase()).filter(Boolean))].slice(0, 20);
    try {
      await api(`/api/pages/${state.currentPageId}`, {method: 'PUT', body: JSON.stringify({tags})});
      state.currentPage.tags = tags;
      const meta = pageById(state.currentPageId);
      if (meta) meta.tags = tags;
      const refreshed = normalizePage(await api(`/api/pages/${state.currentPageId}`));
      state.currentPage.related = refreshed.related;
      state.currentPage.updated_at = refreshed.updated_at;
      state.currentPage.page_information = refreshed.page_information;
      renderPageDiscovery();
      renderPageInformation();
    } catch (error) { toast(`Could not save tags: ${error.message}`, true); }
  }

  function renderPageDiscovery() {
    if (!state.currentPage) return;
    const references = state.currentPage.references || [];
    els.addReferenceButton.classList.toggle('hidden', !state.currentPage.can_edit);
    els.referenceForm.classList.add('hidden');
    els.addReferenceButton.setAttribute('aria-expanded', 'false');
    els.referenceList.innerHTML = references.length ? references.map((reference, index) => {
      const external = /^https?:\/\//i.test(reference.url);
      return `<li><span><a href="${escapeHtml(reference.url)}" ${external ? 'target="_blank" rel="noopener"' : ''}>${escapeHtml(reference.label)}</a><small>${escapeHtml(reference.url)}</small></span>${state.currentPage.can_edit ? `<button class="icon-button" data-reference-remove="${index}" type="button" aria-label="Remove reference"><svg><use href="#x"></use></svg></button>` : ''}</li>`;
    }).join('') : '<li class="discovery-empty">No references yet.</li>';
    const related = state.currentPage.related || [];
    els.similarList.innerHTML = related.length ? related.map(page => `<li><button data-related-page="${page.id}" type="button"><strong>${escapeHtml(page.title)}</strong><small>${Number(page.shared_tags)} shared ${Number(page.shared_tags) === 1 ? 'tag' : 'tags'}</small></button></li>`).join('') : '<li class="discovery-empty">Add shared tags to connect related pages.</li>';
  }

  function renderPageInformation() {
    const information = state.currentPage?.page_information || {};
    const author = information.author || {name: 'Unknown author', state: 'unknown'};
    const name = String(author.name || 'Unknown author');
    const initial = [...name.trim()][0]?.toUpperCase() || '?';
    const avatar = `<span class="page-info-avatar${author.avatar_url ? ' has-avatar' : ''}">${author.avatar_url ? `<img src="${escapeHtml(author.avatar_url)}" alt="${escapeHtml(name)} avatar">` : ''}<span aria-hidden="true">${escapeHtml(initial)}</span></span>`;
    const authorBody = `${avatar}<strong>${escapeHtml(name)}</strong>`;
    els.pageInformationAuthor.innerHTML = author.profile_url
      ? `<a class="page-info-author" href="${escapeHtml(author.profile_url)}">${authorBody}</a>`
      : `<span class="page-info-author">${authorBody}</span>`;
    els.pageInformationWords.textContent = Number(information.word_count || 0).toLocaleString();
    renderPageInformationDate(els.pageInformationCreated, information.created_at);
    const published = Boolean(information.first_published_at);
    els.pageInformationPublishedField.classList.toggle('hidden', !published);
    if (published) renderPageInformationDate(els.pageInformationPublished, information.first_published_at);
    renderPageInformationDate(els.pageInformationUpdated, information.updated_at);
    const pluginRows = Array.isArray(information.plugin_rows) ? information.plugin_rows : [];
    $$('[data-plugin-information-row]', els.pageInformationPluginRows.parentElement).forEach(row => row.remove());
    pluginRows.forEach(row => {
      const group = document.createElement('div');
      group.dataset.pluginInformationRow = '';
      group.innerHTML = `<dt>${escapeHtml(row.label)}<small>${escapeHtml(row.plugin_name || 'Plugin')}</small></dt><dd>${escapeHtml(row.value)}</dd>`;
      els.pageInformationPluginRows.before(group);
    });
  }

  function renderPageInformationDate(element, value) {
    if (!value) { element.textContent = 'Unavailable'; return; }
    const normalized = /(?:Z|[+-]\d\d:\d\d)$/i.test(value) ? value : `${String(value).replace(' ', 'T')}Z`;
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) { element.textContent = 'Unavailable'; return; }
    const time = document.createElement('time');
    time.dateTime = String(value);
    time.textContent = new Intl.DateTimeFormat(undefined, {year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC'}).format(date);
    element.replaceChildren(time);
  }

  async function refreshPageInformation() {
    if (!state.currentPageId) return;
    const refreshed = normalizePage(await api(`/api/pages/${state.currentPageId}`));
    if (state.currentPageId !== refreshed.id) return;
    state.currentPage.updated_at = refreshed.updated_at;
    state.currentPage.page_information = refreshed.page_information;
    renderPageInformation();
  }

  async function addReference(event) {
    event.preventDefault();
    if (!state.currentPage?.can_edit) return;
    const value = Object.fromEntries(new FormData(els.referenceForm));
    const url = String(value.url || '').trim();
    if (!/^(?:https?:\/\/[^\s]+|\/page\/[a-z0-9-]+)$/i.test(url)) return toast('Use an http(s) URL or an internal /page/page-slug link.', true);
    const references = [...(state.currentPage.references || []), {label: String(value.label || '').trim(), url}].slice(0, 40);
    await saveReferences(references);
    els.referenceForm.reset();
  }

  async function removeReference(index) {
    if (!state.currentPage?.can_edit) return;
    const references = (state.currentPage.references || []).filter((_, itemIndex) => itemIndex !== index);
    await saveReferences(references);
  }

  async function saveReferences(references) {
    try {
      await api(`/api/pages/${state.currentPageId}`, {method: 'PUT', body: JSON.stringify({references})});
      state.currentPage.references = references;
      await refreshPageInformation();
      renderPageDiscovery();
      toast('References updated');
    } catch (error) { toast(`Could not save references: ${error.message}`, true); }
  }

  function shareTarget() {
    const type = els.shareForm.elements.resource_type.value;
    if (type === 'space') return {type, id: Number(state.currentSpaceId), manageable: Boolean(currentSpace()?.can_manage)};
    return {type: 'page', id: Number(state.currentPageId), manageable: Boolean(state.currentPage?.can_manage)};
  }

  async function openCollaboration() {
    const space = currentSpace();
    if (!state.currentPage?.can_manage && !space?.can_manage) return toast('Only a space owner can manage collaboration here.', true);
    const pageOption = els.shareForm.elements.resource_type.querySelector('option[value="page"]');
    pageOption.disabled = !state.currentPage?.can_manage;
    els.shareForm.elements.resource_type.value = state.currentPage?.can_manage ? 'page' : 'space';
    renderCollaboratorOptions();
    els.collaboratorForm.classList.toggle('hidden', !state.isAdmin);
    els.shareModal.showModal();
    await loadShares();
    closeSidebar();
  }

  function renderCollaboratorOptions() {
    els.shareForm.elements.user_id.innerHTML = state.users.map(user => `<option value="${Number(user.id)}">${escapeHtml(user.username)}</option>`).join('');
    els.shareForm.querySelector('button[type="submit"]').disabled = !state.users.length;
  }

  async function loadShares() {
    if (!els.shareModal.open) return;
    const target = shareTarget();
    if (!target.id || !target.manageable) {
      els.shareList.innerHTML = '<div class="discovery-empty">You do not own this resource.</div>';
      return;
    }
    try {
      state.shares = await api(`/api/shares?resource_type=${target.type}&resource_id=${target.id}`);
      els.shareList.innerHTML = state.shares.length ? state.shares.map(share => `<div class="share-item"><span><strong>${escapeHtml(share.username)}</strong><small>${escapeHtml(share.role)}</small></span><button class="icon-button" data-share-remove="${share.id}" type="button" aria-label="Remove access"><svg><use href="#x"></use></svg></button></div>`).join('') : '<div class="discovery-empty">No collaborators have direct access yet.</div>';
    } catch (error) { els.shareList.innerHTML = `<div class="discovery-empty">${escapeHtml(error.message)}</div>`; }
  }

  async function grantShare(event) {
    event.preventDefault();
    const target = shareTarget();
    if (!target.manageable) return toast('You do not own this resource.', true);
    const form = Object.fromEntries(new FormData(els.shareForm));
    try {
      await api('/api/shares', {method: 'POST', body: JSON.stringify({resource_type: target.type, resource_id: target.id, user_id: Number(form.user_id), role: form.role})});
      await loadShares();
      toast('Access granted');
    } catch (error) { toast(error.message, true); }
  }

  async function revokeShare(id) {
    try {
      await api(`/api/shares/${id}`, {method: 'DELETE'});
      await loadShares();
      toast('Access removed');
    } catch (error) { toast(error.message, true); }
  }

  async function createCollaborator(event) {
    event.preventDefault();
    try {
      await api('/api/collaboration/users', {method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(els.collaboratorForm)))});
      els.collaboratorForm.reset();
      await reloadBootstrap();
      renderCollaboratorOptions();
      toast('Collaborator account created');
    } catch (error) { toast(error.message, true); }
  }

  function toggleMoreMenu(anchor) {
    $$('[data-more="duplicate"], [data-more="delete"]', els.moreMenu).forEach(button => button.classList.toggle('hidden', !state.currentPage?.can_edit));
    const rect = anchor.getBoundingClientRect();
    els.moreMenu.style.top = `${rect.bottom + 7}px`;
    els.moreMenu.style.left = `${Math.max(8, rect.right - 210)}px`;
    const opening = els.moreMenu.classList.contains('hidden');
    setMoreMenuOpen(opening);
    if (opening) $('[role="menuitem"]:not(.hidden)', els.moreMenu)?.focus();
  }

  function setMoreMenuOpen(open, restoreFocus = false) {
    els.moreMenu.classList.toggle('hidden', !open);
    els.moreMenu.setAttribute('aria-hidden', String(!open));
    els.moreButton.setAttribute('aria-expanded', String(open));
    if (!open && restoreFocus) els.moreButton.focus();
  }

  function onMoreMenuKeydown(event) {
    const items = $$('[role="menuitem"]:not(.hidden):not([disabled])', els.moreMenu);
    if (!items.length) return;
    const current = Math.max(0, items.indexOf(document.activeElement));
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const direction = event.key === 'ArrowDown' ? 1 : -1;
      items[(current + direction + items.length) % items.length].focus();
    }
    if (event.key === 'Home' || event.key === 'End') {
      event.preventDefault();
      items[event.key === 'Home' ? 0 : items.length - 1].focus();
    }
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      setMoreMenuOpen(false, true);
    }
  }

  async function onMoreAction(event) {
    const button = event.target.closest('[data-more]');
    if (!button || !state.currentPage) return;
    setMoreMenuOpen(false);
    const action = button.dataset.more;
    if (action === 'copy') {
      try { await navigator.clipboard.writeText(location.href); toast('Page link copied'); }
      catch { toast('Copy the link from your address bar.'); }
    }
    if (action === 'markdown' || action === 'html') exportPage(action);
    if (action === 'history') openHistory();
    if (action === 'preview') window.open(`/preview/${state.currentPageId}`, '_blank', 'noopener');
    if (action === 'duplicate') {
      try {
        const result = await api(`/api/pages/${state.currentPageId}/duplicate`, {method: 'POST'});
        await reloadBootstrap();
        await openPage(result.id);
        toast('Page duplicated');
      } catch (error) { toast(error.message, true); }
    }
    if (action === 'delete' && confirm(`Move “${state.currentPage.title}” to trash?`)) {
      try {
        await api(`/api/pages/${state.currentPageId}`, {method: 'DELETE'});
        const deletedId = state.currentPageId;
        state.recent = state.recent.filter(id => Number(id) !== deletedId);
        await reloadBootstrap();
        const next = state.pages.find(page => page.kind === 'page' && page.space_id === state.currentSpaceId) || state.pages.find(page => page.kind === 'page');
        if (next) await openPage(next.id); else showHome();
        toast('Page moved to trash');
      } catch (error) { toast(error.message, true); }
    }
  }

  async function exportPage(format) {
    if (!state.currentPageId) return;
    await flushSave();
    window.location.href = `/api/export/${state.currentPageId}?format=${format}`;
  }

  async function openHistory() {
    if (!state.currentPageId) return;
    await flushSave();
    els.historyList.innerHTML = '<div class="search-empty">Loading revision history…</div>';
    els.historyMeta.textContent = 'Loading comparison…';
    els.historyPreview.innerHTML = '';
    els.historyRestore.classList.add('hidden');
    els.historyModal.showModal();
    try {
      state.historyItems = await api(`/api/pages/${state.currentPageId}/revisions`);
      state.selectedRevision = null;
      els.historyList.innerHTML = state.historyItems.map(item => `<button class="history-item" data-revision="${item.revision}" type="button" aria-current="false"><strong>Revision ${item.revision} · ${escapeHtml(item.title)}</strong><span>${escapeHtml(historySource(item.source))} · ${relativeTime(item.created_at)}</span></button>`).join('') || '<div class="search-empty">No revisions yet.</div>';
      els.historyMeta.textContent = 'Choose a revision to compare.';
      els.historyPreview.innerHTML = '';
      els.historyRestore.classList.add('hidden');
      if (state.historyItems[0]) await showRevision(Number(state.historyItems[0].revision));
    } catch (error) {
      els.historyList.innerHTML = `<div class="search-empty state-error">${escapeHtml(error.message)}</div>`;
      els.historyMeta.textContent = 'Revision history could not be loaded.';
    }
  }

  async function showRevision(revision) {
    if (!state.currentPageId) return;
    try {
      const selected = await api(`/api/pages/${state.currentPageId}/revisions/${revision}`);
      const olderMeta = state.historyItems.find(item => Number(item.revision) < revision);
      const older = olderMeta ? await api(`/api/pages/${state.currentPageId}/revisions/${olderMeta.revision}`) : {title: '', content: ''};
      state.selectedRevision = revision;
      $$('.history-item', els.historyList).forEach(button => {
        const active = Number(button.dataset.revision) === revision;
        button.classList.toggle('active', active);
        button.setAttribute('aria-current', String(active));
      });
      els.historyMeta.textContent = `Revision ${revision} · ${historySource(selected.source)} · ${relativeTime(selected.created_at)}`;
      els.historyPreview.innerHTML = renderLineDiff(revisionText(older), revisionText(selected));
      els.historyRestore.classList.toggle('hidden', revision === state.currentPage?.content_revision);
    } catch (error) { toast(error.message, true); }
  }

  async function restoreSelectedRevision() {
    if (!state.currentPageId || !state.selectedRevision || !state.currentPage) return;
    if (!confirm(`Restore revision ${state.selectedRevision}? Your current version will remain in history.`)) return;
    try {
      await api(`/api/pages/${state.currentPageId}/revisions/${state.selectedRevision}/restore`, {method: 'POST', body: JSON.stringify({base_revision: state.currentPage.content_revision})});
      localStorage.removeItem(draftKey(state.currentPageId));
      els.historyModal.close();
      await reloadBootstrap();
      await openPage(state.currentPageId, false);
      toast(`Restored revision ${state.selectedRevision}`);
    } catch (error) { toast(error.message, true); }
  }

  function historySource(source) {
    return source === 'restore' ? 'Restored version' : source === 'initial' ? 'Initial version' : source === 'duplicate' ? 'Duplicated page' : 'Saved edit';
  }

  function revisionText(revision) {
    const container = document.createElement('div');
    container.innerHTML = revision.content || '';
    return [`# ${revision.title || 'Untitled'}`, ...container.innerText.split(/\n+/)].map(line => line.trim()).filter(Boolean).slice(0, 400);
  }

  function renderLineDiff(before, after) {
    const rows = before.length + 1;
    const columns = after.length + 1;
    const table = Array.from({length: rows}, () => new Uint16Array(columns));
    for (let i = before.length - 1; i >= 0; i--) {
      for (let j = after.length - 1; j >= 0; j--) table[i][j] = before[i] === after[j] ? table[i + 1][j + 1] + 1 : Math.max(table[i + 1][j], table[i][j + 1]);
    }
    const output = [];
    let i = 0;
    let j = 0;
    while (i < before.length || j < after.length) {
      if (i < before.length && j < after.length && before[i] === after[j]) { output.push(`<span class="same">  ${escapeHtml(before[i])}</span>`); i++; j++; }
      else if (j < after.length && (i === before.length || table[i][j + 1] >= table[i + 1][j])) { output.push(`<span class="added">+ ${escapeHtml(after[j++])}</span>`); }
      else { output.push(`<span class="removed">- ${escapeHtml(before[i++])}</span>`); }
    }
    return output.join('') || '<span class="same">No textual changes.</span>';
  }

  function buildToc() {
    const headings = $$('h1, h2, h3', els.editor);
    headings.forEach((heading, index) => heading.id = `section-${index + 1}`);
    els.tocList.innerHTML = headings.length ? headings.map(heading => `<button class="toc-${heading.tagName.toLowerCase()}" data-heading="${heading.id}" type="button">${escapeHtml(heading.textContent)}</button>`).join('') : '<div class="search-empty">Add headings to build a table of contents.</div>';
    $$('[data-heading]', els.tocList).forEach(button => button.addEventListener('click', () => {
      const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
      document.getElementById(button.dataset.heading)?.scrollIntoView({behavior, block: 'start'});
      if (innerWidth < 900) setTocOpen(false, true);
    }));
  }

  function openSearch() {
    els.searchModal.showModal();
    els.searchInput.value = '';
    state.searchItems = [];
    els.searchInput.removeAttribute('aria-activedescendant');
    els.searchInput.setAttribute('aria-expanded', 'false');
    els.searchResults.setAttribute('role', 'status');
    els.searchResults.innerHTML = '<div class="search-empty">Type a word or phrase to search every page.</div>';
    setTimeout(() => els.searchInput.focus(), 20);
    closeSidebar();
  }

  async function runSearch() {
    const query = els.searchInput.value.trim();
    if (!query) {
      state.searchItems = [];
      els.searchInput.removeAttribute('aria-activedescendant');
      els.searchInput.setAttribute('aria-expanded', 'false');
      els.searchResults.setAttribute('role', 'status');
      els.searchResults.innerHTML = '<div class="search-empty">Type a word or phrase to search every page.</div>';
      return;
    }
    const token = ++state.searching;
    els.searchInput.setAttribute('aria-expanded', 'false');
    els.searchResults.setAttribute('role', 'status');
    els.searchResults.innerHTML = '<div class="search-empty">Searching…</div>';
    try {
      const results = await api(`/api/search?q=${encodeURIComponent(query)}`);
      if (token !== state.searching) return;
      state.searchItems = results;
      state.searchIndex = 0;
      els.searchInput.setAttribute('aria-expanded', String(results.length > 0));
      els.searchResults.setAttribute('role', results.length ? 'listbox' : 'status');
      els.searchResults.innerHTML = results.length ? results.map((result, index) => `<button class="search-result ${index === 0 ? 'selected' : ''}" id="searchResult-${result.id}" data-search-id="${result.id}" type="button" role="option" aria-selected="${index === 0}"><span class="result-icon" aria-hidden="true">⌕</span><span class="result-copy"><strong>${highlight(result.title, query)}</strong><span>${highlight(result.excerpt || 'No preview available', query)}</span></span></button>`).join('') : '<div class="search-empty">No pages matched that search.</div>';
      if (results[0]) els.searchInput.setAttribute('aria-activedescendant', `searchResult-${results[0].id}`); else els.searchInput.removeAttribute('aria-activedescendant');
    } catch (error) {
      state.searchItems = [];
      els.searchInput.removeAttribute('aria-activedescendant');
      els.searchInput.setAttribute('aria-expanded', 'false');
      els.searchResults.setAttribute('role', 'status');
      els.searchResults.innerHTML = `<div class="search-empty state-error">${escapeHtml(error.message)}</div>`;
    }
  }

  function onSearchKeydown(event) {
    if (!state.searchItems.length) return;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const direction = event.key === 'ArrowDown' ? 1 : -1;
      state.searchIndex = (state.searchIndex + direction + state.searchItems.length) % state.searchItems.length;
      $$('.search-result', els.searchResults).forEach((row, index) => {
        const selected = index === state.searchIndex;
        row.classList.toggle('selected', selected);
        row.setAttribute('aria-selected', String(selected));
      });
      const active = $$('.search-result', els.searchResults)[state.searchIndex];
      active?.scrollIntoView({block: 'nearest'});
      if (active) els.searchInput.setAttribute('aria-activedescendant', active.id);
    }
    if (event.key === 'Enter') {
      event.preventDefault();
      const item = state.searchItems[state.searchIndex];
      if (item) { els.searchModal.close(); openPage(Number(item.id)); }
    }
  }

  function onGlobalKeydown(event) {
    if (event.key === 'Tab' && els.sidebar.classList.contains('open')) {
      const items = $$('a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])', els.sidebar).filter(element => element.getClientRects().length > 0);
      const first = items[0];
      const last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); openSearch(); }
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') { event.preventDefault(); flushSave(); }
    if (event.key === 'Escape') {
      if (!els.moreMenu.classList.contains('hidden')) setMoreMenuOpen(false, true);
      if (els.tocPanel.classList.contains('open')) setTocOpen(false, true);
      closeSidebar(true);
    }
  }

  function cycleTheme() {
    const themes = ['system', 'light', 'dark'];
    const current = document.documentElement.dataset.theme || 'system';
    applyTheme(themes[(themes.indexOf(current) + 1) % themes.length]);
  }

  function applyTheme(theme) {
    document.documentElement.dataset.theme = theme;
    localStorage.setItem('n3.theme', theme);
    if (els.themeValue) els.themeValue.textContent = theme[0].toUpperCase() + theme.slice(1);
    if (els.themeButton) els.themeButton.setAttribute('aria-label', `Appearance: ${theme}. Activate to change theme.`);
    applyThemeTokens();
  }

  function setTocOpen(open, restoreFocus = false) {
    els.tocPanel.classList.toggle('open', open);
    els.tocPanel.setAttribute('aria-hidden', String(!open));
    els.tocPanel.inert = !open;
    els.tocButton.setAttribute('aria-expanded', String(open));
    if (open) els.tocClose.focus();
    else if (restoreFocus) els.tocButton.focus();
  }

  function toggleSpaceDirectory() {
    if (matchMedia('(max-width: 900px)').matches) {
      const collapsed = els.pageTree.classList.toggle('mobile-collapsed');
      els.spaceMenuButton.classList.toggle('mobile-collapsed', collapsed);
      els.spaceMenuButton.setAttribute('aria-expanded', String(!collapsed));
      return;
    }
    const allExpanded = state.spaces.every(space => state.expandedSpaces.has(Number(space.id)));
    state.expandedSpaces = new Set(allExpanded ? [] : state.spaces.map(space => Number(space.id)));
    renderPageTree();
  }

  function openSpaceSettings() {
    const space = currentSpace();
    if (!space) return;
    els.spaceForm.elements.name.value = space.name;
    els.spaceForm.elements.description.value = space.description;
    els.spaceForm.elements.color.value = space.color;
    els.spaceForm.dataset.mode = 'edit';
    els.spaceSubmit.textContent = 'Save changes';
    els.deleteSpace.hidden = state.spaces.length <= 1;
    els.spaceModal.showModal();
  }

  function beginNewSpace() {
    els.spaceForm.reset();
    els.spaceForm.elements.color.value = '#415a77';
    els.spaceForm.dataset.mode = 'create';
    els.spaceSubmit.textContent = 'Create space';
    els.deleteSpace.hidden = true;
    els.spaceForm.elements.name.focus();
  }

  function openNewSpace() {
    if (!els.spaceModal.open) els.spaceModal.showModal();
    beginNewSpace();
    closeSidebar();
  }

  async function saveSpaceSettings(event) {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(els.spaceForm));
    const creating = els.spaceForm.dataset.mode === 'create';
    try {
      if (creating) {
        const result = await api('/api/spaces', {method: 'POST', body: JSON.stringify(data)});
        state.currentSpaceId = Number(result.id);
      } else {
        await api(`/api/spaces/${state.currentSpaceId}`, {method: 'PUT', body: JSON.stringify(data)});
      }
      await reloadBootstrap();
      els.spaceModal.close();
      if (creating) showHome();
      toast(creating ? 'Space created' : 'Space updated');
    } catch (error) { toast(error.message, true); }
  }

  async function deleteCurrentSpace() {
    const space = currentSpace();
    if (!space || !confirm(`Delete “${space.name}” and every page, folder, tag link, and revision inside it?`)) return;
    try {
      await api(`/api/spaces/${space.id}`, {method: 'DELETE'});
      state.currentSpaceId = null;
      await reloadBootstrap();
      els.spaceModal.close();
      showHome();
      toast('Space deleted');
    } catch (error) { toast(error.message, true); }
  }

  async function openTrash() {
    els.trashList.innerHTML = '<div class="search-empty">Loading trash…</div>';
    els.trashModal.showModal();
    closeSidebar();
    try {
      const pages = await api('/api/trash');
      els.trashList.innerHTML = pages.map(page => `<div class="trash-item"><span class="result-icon">◇</span><div><strong>${escapeHtml(page.title)}</strong><small>Deleted ${relativeTime(page.updated_at)}</small></div><button class="secondary-button" data-restore="${page.id}" type="button">Restore</button><button class="icon-button" data-purge="${page.id}" data-title="${escapeHtml(page.title)}" type="button" aria-label="Delete permanently"><svg><use href="#trash"></use></svg></button></div>`).join('');
    } catch (error) {
      els.trashList.innerHTML = `<div class="search-empty state-error">${escapeHtml(error.message)}</div>`;
    }
  }

  async function restorePage(id) {
    try {
      await api(`/api/pages/${id}/restore`, {method: 'POST'});
      await reloadBootstrap();
      els.trashModal.close();
      await openPage(id);
      toast('Page restored');
    } catch (error) { toast(error.message, true); }
  }

  async function purgePage(id, title) {
    if (!confirm(`Permanently delete “${title}”? This cannot be undone.`)) return;
    try {
      await api(`/api/trash/${id}`, {method: 'DELETE'});
      els.trashList.querySelector(`[data-purge="${id}"]`)?.closest('.trash-item')?.remove();
      toast('Page permanently deleted');
    } catch (error) { toast(error.message, true); }
  }

  function updateWordCount() {
    const words = (els.editor.innerText.trim().match(/\S+/g) || []).length;
    els.wordCount.textContent = `${words} ${words === 1 ? 'word' : 'words'}`;
    if (state.currentPage?.page_information) {
      state.currentPage.page_information.word_count = words;
      els.pageInformationWords.textContent = words.toLocaleString();
    }
  }

  function clampSidebarWidth(width) {
    const numericWidth = Number(width);
    if (!Number.isFinite(numericWidth)) return SIDEBAR_DEFAULT_WIDTH;
    return Math.min(SIDEBAR_MAX_WIDTH, Math.max(SIDEBAR_MIN_WIDTH, Math.round(numericWidth)));
  }

  function applyStoredSidebarWidth() {
    let storedWidth = SIDEBAR_DEFAULT_WIDTH;
    try { storedWidth = localStorage.getItem('n3.sidebarWidth') ?? SIDEBAR_DEFAULT_WIDTH; } catch { /* Use the default when storage is unavailable. */ }
    setSidebarWidth(storedWidth, true);
  }

  function setSidebarWidth(width, persist = false) {
    const clampedWidth = clampSidebarWidth(width);
    document.documentElement.style.setProperty('--sidebar-width', `${clampedWidth}px`);
    els.sidebarResizeHandle.setAttribute('aria-valuenow', String(clampedWidth));
    els.sidebarResizeHandle.setAttribute('aria-valuetext', `${clampedWidth} pixels`);
    if (persist) {
      try { localStorage.setItem('n3.sidebarWidth', String(clampedWidth)); } catch { /* Resizing still works when storage is unavailable. */ }
    }
    return clampedWidth;
  }

  function beginSidebarResize(event) {
    if (mobileSidebar.matches || (event.pointerType === 'mouse' && event.button !== 0)) return;
    event.preventDefault();
    sidebarResizePointerId = event.pointerId;
    els.sidebarResizeHandle.setPointerCapture(event.pointerId);
    document.body.classList.add('sidebar-resizing');
    setSidebarWidth(event.clientX);
  }

  function resizeSidebarFromPointer(event) {
    if (event.pointerId !== sidebarResizePointerId) return;
    setSidebarWidth(event.clientX);
  }

  function finishSidebarResize(event) {
    if (event.pointerId !== sidebarResizePointerId) return;
    sidebarResizePointerId = null;
    document.body.classList.remove('sidebar-resizing');
    setSidebarWidth(els.sidebarResizeHandle.getAttribute('aria-valuenow'), true);
  }

  function resizeSidebarFromKeyboard(event) {
    if (mobileSidebar.matches) return;
    const currentWidth = Number(els.sidebarResizeHandle.getAttribute('aria-valuenow')) || SIDEBAR_DEFAULT_WIDTH;
    let nextWidth = null;
    if (event.key === 'ArrowLeft') nextWidth = currentWidth - SIDEBAR_KEY_STEP;
    if (event.key === 'ArrowRight') nextWidth = currentWidth + SIDEBAR_KEY_STEP;
    if (event.key === 'Home') nextWidth = SIDEBAR_MIN_WIDTH;
    if (event.key === 'End') nextWidth = SIDEBAR_MAX_WIDTH;
    if (nextWidth === null) return;
    event.preventDefault();
    setSidebarWidth(nextWidth, true);
  }

  function readStoredSidebarCollapsed() {
    try { desktopSidebarCollapsed = localStorage.getItem('n3.sidebarCollapsed') === 'true'; } catch { desktopSidebarCollapsed = false; }
  }

  function renderDesktopSidebarState() {
    const expanded = !desktopSidebarCollapsed;
    els.appShell.classList.toggle('sidebar-collapsed', desktopSidebarCollapsed);
    els.sidebarCollapse.setAttribute('aria-expanded', String(expanded));
    els.menuButton.setAttribute('aria-expanded', String(expanded));
    els.sidebar.setAttribute('aria-hidden', String(!expanded));
    els.sidebar.inert = !expanded;
  }

  function setDesktopSidebarCollapsed(collapsed, persist = false, restoreFocus = false) {
    if (mobileSidebar.matches) return;
    desktopSidebarCollapsed = Boolean(collapsed);
    renderDesktopSidebarState();
    if (persist) {
      try { localStorage.setItem('n3.sidebarCollapsed', String(desktopSidebarCollapsed)); } catch { /* Collapse still works when storage is unavailable. */ }
    }
    if (restoreFocus) (desktopSidebarCollapsed ? els.menuButton : els.sidebarCollapse).focus();
  }

  function openSidebarFromMenu() {
    if (mobileSidebar.matches) setSidebarOpen(true);
    else setDesktopSidebarCollapsed(false, true, true);
  }

  function setSidebarOpen(open, restoreFocus = false) {
    if (!mobileSidebar.matches) {
      els.sidebar.classList.remove('open');
      renderDesktopSidebarState();
      return;
    }
    const isOpen = mobileSidebar.matches && open;
    if (isOpen) sidebarReturnFocus = document.activeElement;
    els.sidebar.classList.toggle('open', isOpen);
    els.menuButton.setAttribute('aria-expanded', String(isOpen));
    els.sidebar.setAttribute('aria-hidden', mobileSidebar.matches ? String(!isOpen) : 'false');
    els.sidebar.inert = mobileSidebar.matches && !isOpen;
    if (isOpen) els.sidebarClose.focus();
    else if (restoreFocus && sidebarReturnFocus instanceof HTMLElement) sidebarReturnFocus.focus();
  }

  function closeSidebar(restoreFocus = false) { setSidebarOpen(false, restoreFocus); }
  function toast(message, isError = false) {
    const item = document.createElement('div');
    item.className = `toast ${isError ? 'error' : ''}`;
    item.textContent = message;
    els.toastRegion.append(item);
    setTimeout(() => item.remove(), 3200);
  }

  function showFatal(error) {
    els.documentView.classList.add('hidden');
    els.homeView.classList.add('hidden');
    els.appStateSpinner.classList.add('hidden');
    els.appStateTitle.textContent = 'n3 could not start';
    els.appStateMessage.textContent = error.offline
      ? 'The server is unreachable. Your browser data is unchanged; reconnect and try again.'
      : 'The workspace could not be loaded. Check system diagnostics or the container logs, then try again.';
    els.appStateRetry.classList.remove('hidden');
    els.appState.classList.remove('hidden');
    els.main.setAttribute('aria-busy', 'false');
  }

  function setConnectionState(online) {
    state.isOnline = Boolean(online);
    els.connectionBanner.classList.toggle('hidden', state.isOnline);
    if (!state.isOnline && state.savePending) setSaveState('offline');
  }

  async function retryConnection() {
    els.connectionRetry.disabled = true;
    els.connectionRetry.setAttribute('aria-busy', 'true');
    try {
      await api('/api/health');
      if (state.savePending) await flushSave();
      if (state.isOnline) toast('Connection restored');
    } catch (error) {
      toast(error.message, true);
    } finally {
      els.connectionRetry.disabled = false;
      els.connectionRetry.removeAttribute('aria-busy');
    }
  }

  function relativeTime(value) {
    if (!value) return 'recently';
    const date = new Date(value.includes('T') ? value : `${value.replace(' ', 'T')}Z`);
    const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`;
    return date.toLocaleDateString(undefined, {month: 'short', day: 'numeric', year: date.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined});
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
  }

  function highlight(text, query) {
    const safe = escapeHtml(text);
    const escapedQuery = escapeHtml(query).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return safe.replace(new RegExp(`(${escapedQuery})`, 'ig'), '<mark>$1</mark>');
  }

  function debounce(fn, wait) {
    let timer;
    return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), wait); };
  }
})();
