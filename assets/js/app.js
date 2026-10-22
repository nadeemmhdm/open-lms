(() => {
  const root = document.documentElement;
  const saved = localStorage.getItem('open-lms-theme');
  const systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  root.dataset.theme = saved || (systemDark ? 'dark' : 'light');

  document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
      root.dataset.theme = next;
      localStorage.setItem('open-lms-theme', next);
    });
  });

  const body = document.body;
  const menuButton = document.querySelector('[data-menu-toggle]');
  const desktopSidebar = window.matchMedia('(min-width: 721px)');
  const savedHidden = localStorage.getItem('open-lms-sidebar-hidden') === '1';
  if (savedHidden && desktopSidebar.matches) body.classList.add('sidebar-hidden');

  const syncMenuA11y = () => {
    if (!menuButton) return;
    const expanded = desktopSidebar.matches ? !body.classList.contains('sidebar-hidden') : body.classList.contains('menu-open');
    menuButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  };
  const closeMobileMenu = () => { body.classList.remove('menu-open'); syncMenuA11y(); };

  menuButton?.addEventListener('click', () => {
    if (desktopSidebar.matches) {
      body.classList.toggle('sidebar-hidden');
      localStorage.setItem('open-lms-sidebar-hidden', body.classList.contains('sidebar-hidden') ? '1' : '0');
    } else {
      body.classList.toggle('menu-open');
    }
    syncMenuA11y();
  });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeMobileMenu(); });
  desktopSidebar.addEventListener?.('change', () => {
    body.classList.remove('menu-open');
    if (!desktopSidebar.matches) body.classList.remove('sidebar-hidden');
    else if (localStorage.getItem('open-lms-sidebar-hidden') === '1') body.classList.add('sidebar-hidden');
    syncMenuA11y();
  });
  document.querySelectorAll('.sidebar .nav-link').forEach((link) => link.addEventListener('click', () => { if (!desktopSidebar.matches) closeMobileMenu(); }));
  syncMenuA11y();

  // Universal profile entry without duplicating server-side role navigation logic.
  const sidebarNav = document.querySelector('.sidebar nav');
  if (sidebarNav && !sidebarNav.querySelector('[href*="r=profile"]')) {
    const profile = document.createElement('a');
    profile.className = 'nav-link' + (new URLSearchParams(location.search).get('r') === 'profile' ? ' active' : '');
    profile.href = '?r=profile';
    profile.textContent = 'My profile';
    const securityLink = Array.from(sidebarNav.querySelectorAll('.nav-link')).find((link) => link.getAttribute('href')?.includes('r=mfa-setup'));
    sidebarNav.insertBefore(profile, securityLink || null);
  }
  const userChip = document.querySelector('.user-chip');
  if (userChip) {
    userChip.setAttribute('role', 'link');
    userChip.setAttribute('tabindex', '0');
    userChip.setAttribute('title', 'Open my profile');
    const openProfile = () => { window.location.href = '?r=profile'; };
    userChip.addEventListener('click', openProfile);
    userChip.addEventListener('keydown', (event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openProfile(); } });
  }

  document.querySelectorAll('[data-copy-button]').forEach((button) => {
    button.addEventListener('click', async () => {
      const field = button.closest('.copy-field')?.querySelector('[data-copy-source]');
      if (!field) return;
      const value = field.value || field.textContent || '';
      try {
        await navigator.clipboard.writeText(value);
        const previous = button.textContent;
        button.textContent = 'Copied';
        setTimeout(() => { button.textContent = previous; }, 1600);
      } catch (_) {
        field.focus();
        field.select?.();
      }
    });
  });

  const onboardingYear = document.querySelector('[data-onboarding-year]');
  const onboardingBatch = document.querySelector('[data-onboarding-batch]');
  if (onboardingYear && onboardingBatch) {
    const syncBatches = () => {
      const year = onboardingYear.value;
      let firstVisible = null;
      Array.from(onboardingBatch.options).forEach((option, index) => {
        if (index === 0) return;
        const visible = !year || option.dataset.year === year;
        option.hidden = !visible;
        option.disabled = !visible;
        if (visible && !firstVisible) firstVisible = option;
      });
      const selected = onboardingBatch.selectedOptions[0];
      if (!selected || selected.disabled || selected.hidden) onboardingBatch.value = firstVisible?.value || '';
    };
    onboardingYear.addEventListener('change', syncBatches);
    syncBatches();
  }

  const selectAllCourses = document.querySelector('[data-course-select-all]');
  if (selectAllCourses) {
    const courseChecks = Array.from(document.querySelectorAll('input[name="course_ids[]"]'));
    selectAllCourses.addEventListener('change', () => courseChecks.forEach((box) => { box.checked = selectAllCourses.checked; }));
    courseChecks.forEach((box) => box.addEventListener('change', () => {
      selectAllCourses.checked = courseChecks.length > 0 && courseChecks.every((item) => item.checked);
      selectAllCourses.indeterminate = courseChecks.some((item) => item.checked) && !selectAllCourses.checked;
    }));
  }

  const driver = document.getElementById('db-driver');
  const mysql = document.getElementById('mysql-fields');
  if (driver && mysql) {
    const sync = () => mysql.hidden = driver.value !== 'mysql';
    driver.addEventListener('change', sync);
    sync();
  }

  document.querySelectorAll('[data-rich-editor]').forEach((editor) => {
    const form = editor.closest('form');
    const input = form?.querySelector('[data-rich-input]');
    const sync = () => { if (input) input.value = editor.innerHTML; };
    editor.addEventListener('input', sync);
    form?.addEventListener('submit', sync);

    const toolbar = editor.previousElementSibling;
    toolbar?.querySelectorAll('[data-editor-command]').forEach((button) => {
      button.addEventListener('click', () => {
        editor.focus();
        document.execCommand(button.dataset.editorCommand, false);
        sync();
      });
    });
    toolbar?.querySelectorAll('[data-editor-block]').forEach((button) => {
      button.addEventListener('click', () => {
        editor.focus();
        document.execCommand('formatBlock', false, button.dataset.editorBlock);
        sync();
      });
    });
  });
})();

// Assessment runtime: server remains authoritative for time, submission and grading.
(() => {
  const form = document.getElementById('assessment-form');
  if (!form) return;

  const attemptId = Number(form.dataset.attemptId || 0);
  const saveUrl = form.dataset.autosaveUrl || '';
  const eventUrl = form.dataset.eventUrl || '';
  const csrf = form.querySelector('input[name="_csrf"]')?.value || '';
  const saveState = document.querySelector('[data-save-state]');
  const timer = document.querySelector('[data-expires-at]');
  const expired = document.querySelector('.exam-expired');
  const restrictCopy = form.dataset.restrictCopy === '1';
  const wantsFullscreen = form.dataset.fullscreen === '1';
  let submitting = false;
  let saving = false;
  let dirty = false;
  let saveTimer = null;
  let wasOffline = !navigator.onLine;

  const logEvent = (eventType, metadata = {}) => {
    if (!eventUrl || !attemptId) return;
    fetch(eventUrl, {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
      credentials: 'same-origin',
      keepalive: true,
      body: JSON.stringify({_csrf: csrf, attempt_id: attemptId, event_type: eventType, metadata})
    }).catch(() => {});
  };

  const autosave = async () => {
    if (saving || submitting || !saveUrl || !attemptId || !dirty) return;
    saving = true;
    if (saveState) saveState.textContent = 'Saving…';
    try {
      const data = new FormData(form);
      const response = await fetch(saveUrl, {method: 'POST', body: data, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
      const result = await response.json().catch(() => ({ok: false}));
      if (!response.ok || !result.ok) throw new Error(result.error || 'Autosave failed');
      dirty = false;
      if (saveState) saveState.textContent = 'Saved ' + new Date().toLocaleTimeString();
    } catch (error) {
      if (saveState) saveState.textContent = navigator.onLine ? 'Save failed — retrying' : 'Offline — changes will save after reconnect';
      logEvent('autosave_error', {online: navigator.onLine});
    } finally {
      saving = false;
    }
  };

  const scheduleSave = () => {
    dirty = true;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(autosave, 1200);
  };
  form.addEventListener('input', scheduleSave);
  form.addEventListener('change', scheduleSave);
  setInterval(autosave, 15000);

  window.addEventListener('offline', () => {
    wasOffline = true;
    if (saveState) saveState.textContent = 'Offline — answers remain in this page';
  });
  window.addEventListener('online', () => {
    if (wasOffline) logEvent('reconnected');
    wasOffline = false;
    dirty = true;
    autosave();
  });

  if (timer) {
    const expiry = Date.parse(timer.dataset.expiresAt || '');
    const tick = () => {
      if (!Number.isFinite(expiry) || submitting) return;
      const left = Math.max(0, expiry - Date.now());
      const seconds = Math.floor(left / 1000);
      const h = Math.floor(seconds / 3600);
      const m = Math.floor((seconds % 3600) / 60);
      const s = seconds % 60;
      timer.textContent = (h ? String(h).padStart(2, '0') + ':' : '') + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
      if (left <= 0) {
        submitting = true;
        if (expired) expired.hidden = false;
        form.submit();
      }
    };
    tick();
    setInterval(tick, 1000);
  }

  document.addEventListener('visibilitychange', () => logEvent(document.hidden ? 'visibility_hidden' : 'visibility_visible'));
  window.addEventListener('blur', () => logEvent('window_blur'));
  window.addEventListener('focus', () => logEvent('window_focus'));
  document.addEventListener('fullscreenchange', () => logEvent(document.fullscreenElement ? 'fullscreen_enter' : 'fullscreen_exit'));

  const blocked = (event, type) => {
    if (!restrictCopy) return;
    event.preventDefault();
    logEvent(type);
  };
  document.addEventListener('copy', (e) => blocked(e, 'copy_attempt'));
  document.addEventListener('paste', (e) => blocked(e, 'paste_attempt'));
  document.addEventListener('contextmenu', (e) => blocked(e, 'contextmenu_attempt'));

  document.querySelector('[data-enter-fullscreen]')?.addEventListener('click', async () => {
    try { await document.documentElement.requestFullscreen?.(); } catch (_) {}
  });
  if (wantsFullscreen && document.fullscreenEnabled && !document.fullscreenElement) {
    // Fullscreen requires a user gesture in modern browsers; the explicit button remains available.
  }

  form.addEventListener('submit', () => { submitting = true; });
})();

(() => {
  document.querySelectorAll('[data-mfa-qr]').forEach((target) => {
    const uri = target.dataset.uri || '';
    if (!uri || !window.OpenLMSQRCode?.svg) return;
    try { target.innerHTML = window.OpenLMSQRCode.svg(uri); }
    catch (_) { target.textContent = 'QR rendering unavailable. Use the manual key.'; }
  });
})();
