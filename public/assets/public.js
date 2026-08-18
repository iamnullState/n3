(() => {
  const themes = ['system', 'light', 'dark'];
  const themeButton = document.querySelector('.public-theme-toggle');
  let brandSettings = null;
  const savedTheme = localStorage.getItem('n3.publicTheme');
  applyTheme(themes.includes(savedTheme) ? savedTheme : 'system');
  themeButton?.addEventListener('click', () => {
    const current = document.documentElement.dataset.theme || 'system';
    applyTheme(themes[(themes.indexOf(current) + 1) % themes.length]);
  });
  fetch('/brand/settings', {headers: {'Accept': 'application/json'}}).then(response => response.ok ? response.json() : null).then(settings => {
    if (!settings) return;
    brandSettings = settings;
    document.querySelectorAll('.public-brand').forEach(brand => {
      if (settings.iconUrl) brand.innerHTML = `<img src="${settings.iconUrl}" alt=""><span>${escapeText(settings.brandName)}</span>`;
      else brand.querySelector('span').textContent = settings.brandName;
    });
    const hero = document.querySelector('.public-main .public-hero');
    if (hero && settings.bannerUrl) {
      const banner = document.createElement('img');
      banner.className = 'public-brand-banner';
      banner.src = settings.bannerUrl;
      banner.alt = '';
      hero.prepend(banner);
    }
    applyTheme(document.documentElement.dataset.theme || 'system');
  }).catch(() => {});

  function applyTheme(theme) {
    document.documentElement.dataset.theme = theme;
    localStorage.setItem('n3.publicTheme', theme);
    const label = theme[0].toUpperCase() + theme.slice(1);
    const labelElement = themeButton?.querySelector('.public-theme-label');
    if (labelElement) labelElement.textContent = label;
    if (themeButton) {
      themeButton.title = `Theme: ${label}`;
      themeButton.setAttribute('aria-label', `Theme: ${label}. Activate to change theme.`);
    }
    if (brandSettings?.themes) {
      const mode = theme === 'system' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : theme;
      Object.entries(brandSettings.themes[mode] || {}).forEach(([token, value]) => document.documentElement.style.setProperty(`--${token}`, value));
    }
  }

  function escapeText(value) {
    const span = document.createElement('span');
    span.textContent = String(value || 'n3');
    return span.innerHTML;
  }

  const mediaDialog = document.createElement('dialog');
  mediaDialog.className = 'media-lightbox';
  mediaDialog.setAttribute('aria-label', 'Expanded media');
  mediaDialog.innerHTML = '<button class="media-lightbox-close" type="button" aria-label="Close expanded media">×</button><div></div>';
  document.body.append(mediaDialog);
  const openMedia = media => {
    const expanded = media.cloneNode(true);
    expanded.className = '';
    if (expanded instanceof HTMLVideoElement) expanded.controls = true;
    mediaDialog.querySelector('div').replaceChildren(expanded);
    mediaDialog.showModal();
  };
  document.querySelectorAll('.prose img').forEach(image => {
    image.tabIndex = 0;
    image.setAttribute('role', 'button');
    image.setAttribute('aria-label', image.alt ? `Expand ${image.alt}` : 'Expand image');
  });
  document.addEventListener('click', event => {
    const media = event.target.closest('.prose img, .prose video');
    if (!media) return;
    event.preventDefault();
    openMedia(media);
  });
  document.addEventListener('keydown', event => {
    const image = event.target.closest('.prose img[role="button"]');
    if (!image || !['Enter', ' '].includes(event.key)) return;
    event.preventDefault();
    openMedia(image);
  });
  mediaDialog.querySelector('button').addEventListener('click', () => mediaDialog.close());
  mediaDialog.addEventListener('click', event => { if (event.target === mediaDialog) mediaDialog.close(); });

  const toggle = document.querySelector('.public-directory-toggle');
  const directory = document.getElementById('publicDirectory');
  const closeButton = document.querySelector('.public-directory-close');
  const scrim = document.querySelector('.public-directory-scrim');
  if (!toggle || !directory || !closeButton || !scrim) return;

  const mobile = window.matchMedia('(max-width: 900px)');
  let returnFocus = null;

  function focusableItems() {
    return [...directory.querySelectorAll('a[href], button:not([disabled]), summary, [tabindex]:not([tabindex="-1"])')]
      .filter(element => !element.hidden && element.getClientRects().length > 0);
  }

  function setOpen(open, restoreFocus = false) {
    const isOpen = mobile.matches && open;
    document.body.classList.toggle('public-directory-open', isOpen);
    toggle.setAttribute('aria-expanded', String(isOpen));
    directory.setAttribute('aria-hidden', mobile.matches ? String(!isOpen) : 'false');
    directory.inert = mobile.matches && !isOpen;
    if (isOpen) {
      returnFocus = document.activeElement;
      closeButton.focus();
    } else if (restoreFocus && returnFocus instanceof HTMLElement) {
      returnFocus.focus();
    }
  }

  toggle.addEventListener('click', () => setOpen(true));
  closeButton.addEventListener('click', () => setOpen(false, true));
  scrim.addEventListener('click', () => setOpen(false, true));
  directory.addEventListener('click', event => {
    if (event.target.closest('a')) setOpen(false);
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && document.body.classList.contains('public-directory-open')) {
      setOpen(false, true);
      return;
    }
    if (event.key === 'Tab' && document.body.classList.contains('public-directory-open')) {
      const items = focusableItems();
      if (!items.length) return;
      const first = items[0];
      const last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });
  mobile.addEventListener('change', () => setOpen(false));
  setOpen(false);
})();
