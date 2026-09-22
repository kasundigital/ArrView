(() => {
  const root = document.documentElement;
  const storageKey = 'arrview-theme';
  const choices = ['dark','light','system'];

  function resolvedTheme(pref) {
    return pref === 'system'
      ? (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
      : pref;
  }

  function applyTheme(pref, persist = true) {
    if (!choices.includes(pref)) pref = 'system';
    const resolved = resolvedTheme(pref);
    root.dataset.themePreference = pref;
    root.dataset.theme = resolved;
    if (persist) {
      try { localStorage.setItem(storageKey, pref); } catch (_) {}
    }
    const meta = document.querySelector('#theme-color-meta');
    if (meta) meta.setAttribute('content', resolved === 'light' ? '#ffffff' : '#0b111c');
    document.querySelectorAll('.theme-toggle').forEach(btn => {
      const label = btn.querySelector('.theme-label');
      const icon = btn.querySelector('.theme-icon');
      if (label) label.textContent = pref === 'system' ? 'System' : (pref === 'light' ? 'Light' : 'Dark');
      if (icon) icon.textContent = pref === 'light' ? '☀' : (pref === 'dark' ? '☾' : '◐');
      btn.title = 'Theme: ' + (pref.charAt(0).toUpperCase() + pref.slice(1));
    });
  }

  let preference = root.dataset.themePreference || 'system';
  applyTheme(preference, false);

  document.addEventListener('click', e => {
    const themeButton = e.target.closest('.theme-toggle');
    if (themeButton) {
      preference = root.dataset.themePreference || 'system';
      const next = choices[(choices.indexOf(preference) + 1) % choices.length];
      applyTheme(next, true);
      return;
    }

    const navToggle = e.target.closest('.nav-toggle');
    if (navToggle) {
      const open = document.body.classList.toggle('nav-open');
      navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      navToggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
      return;
    }

    if (window.innerWidth <= 720 && e.target.closest('.topbar nav a')) {
      document.body.classList.remove('nav-open');
      document.querySelector('.nav-toggle')?.setAttribute('aria-expanded','false');
    }
  });

  window.matchMedia('(prefers-color-scheme: light)').addEventListener?.('change', () => {
    if ((root.dataset.themePreference || 'system') === 'system') applyTheme('system', false);
  });

  // Convert server success/error notices into compact dismissible toasts.
  const notices = [...document.querySelectorAll('.notice.success,.notice.error')];
  if (notices.length) {
    const stack = document.createElement('div');
    stack.className = 'toast-stack';
    stack.setAttribute('aria-live','polite');
    document.body.appendChild(stack);

    notices.forEach(notice => {
      const close = document.createElement('button');
      close.type = 'button';
      close.className = 'toast-close';
      close.setAttribute('aria-label','Dismiss notification');
      close.textContent = '×';
      close.addEventListener('click', () => dismiss(notice));
      notice.appendChild(close);
      stack.appendChild(notice);
      if (notice.classList.contains('success')) setTimeout(() => dismiss(notice), 4500);
    });

    function dismiss(notice) {
      if (!notice?.isConnected) return;
      notice.classList.add('toast-leave');
      setTimeout(() => notice.remove(), 240);
    }
  }

  // Standard submit feedback, excluding background sync controls.
  document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', () => {
      const button = form.querySelector('button[type="submit"],button:not([type])');
      if (!button || button.classList.contains('sync-btn') || button.dataset.noLoading === 'true') return;
      if (button.disabled) return;
      button.classList.add('is-loading');
      button.disabled = true;
      button.setAttribute('aria-busy','true');
    });
  });
})();
