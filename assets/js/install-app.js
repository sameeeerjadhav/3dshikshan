(function () {
  'use strict';

  const STORAGE_KEY = '3dshikshan_install_dismissed';
  const DISMISS_DAYS = 14;

  let deferredPrompt = null;
  let overlayEl = null;

  function isStandalone() {
    return (
      window.matchMedia('(display-mode: standalone)').matches ||
      window.navigator.standalone === true
    );
  }

  function isIOS() {
    return /iphone|ipad|ipod/i.test(navigator.userAgent);
  }

  function isDismissed() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) {
        return false;
      }
      const data = JSON.parse(raw);
      const ms = (data.days || DISMISS_DAYS) * 86400000;
      return Date.now() - data.at < ms;
    } catch {
      return false;
    }
  }

  function saveDismiss() {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({ at: Date.now(), days: DISMISS_DAYS })
    );
  }

  function hideModal() {
    if (!overlayEl) {
      return;
    }
    overlayEl.classList.remove('is-visible');
    document.body.classList.remove('install-app-open');
    setTimeout(() => overlayEl?.remove(), 280);
    overlayEl = null;
  }

  function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
      return;
    }
    const base = document.querySelector('link[rel="manifest"]')?.getAttribute('href') || 'manifest.webmanifest';
    const root = base.replace(/manifest\.webmanifest.*$/, '');
    navigator.serviceWorker.register(root + 'sw.js').catch(() => undefined);
  }

  function isMobile() {
    return (
      isIOS() ||
      /android/i.test(navigator.userAgent) ||
      window.matchMedia('(max-width: 768px)').matches
    );
  }

  function buildModal(mode) {
    const iconSrc = 'assets/icons/app-icon.svg';
    let stepsBlock = '';
    let installLabel = 'Install app';

    if (mode === 'ios') {
      stepsBlock = `<div class="install-app-ios-steps">
          <strong>Add to Home Screen</strong>
          <ol>
            <li>Tap <i class="fa-solid fa-arrow-up-from-bracket"></i> <strong>Share</strong> in Safari</li>
            <li>Choose <strong>Add to Home Screen</strong></li>
            <li>Open the app from your home screen</li>
          </ol>
        </div>`;
      installLabel = 'Got it';
    } else if (mode === 'android-manual') {
      stepsBlock = `<div class="install-app-ios-steps">
          <strong>Install on Android</strong>
          <ol>
            <li>Tap <i class="fa-solid fa-ellipsis-vertical"></i> <strong>Menu</strong> in Chrome</li>
            <li>Select <strong>Install app</strong> or <strong>Add to Home screen</strong></li>
          </ol>
        </div>`;
      installLabel = 'Got it';
    }

    overlayEl = document.createElement('div');
    overlayEl.className = 'install-app-overlay';
    overlayEl.setAttribute('role', 'dialog');
    overlayEl.setAttribute('aria-modal', 'true');
    overlayEl.setAttribute('aria-labelledby', 'install-app-title');
    overlayEl.innerHTML = `
      <div class="install-app-sheet">
        <button type="button" class="install-app-close" id="install-app-close" aria-label="Close">
          <i class="fa-solid fa-xmark"></i>
        </button>
        <div class="install-app-head">
          <img class="install-app-icon" src="${iconSrc}" width="56" height="56" alt="" />
          <div>
            <h3 id="install-app-title">Install 3D Shikshan</h3>
            <p>Get quick access from your home screen — works like an app.</p>
          </div>
        </div>
        ${stepsBlock}
        <div class="install-app-actions">
          <button type="button" class="install-app-btn-primary" id="install-app-primary">${installLabel}</button>
          <button type="button" class="install-app-btn-ghost" id="install-app-later">Not now</button>
        </div>
      </div>
    `;

    document.body.appendChild(overlayEl);

    overlayEl.addEventListener('click', (e) => {
      if (e.target === overlayEl) {
        saveDismiss();
        hideModal();
      }
    });

    document.getElementById('install-app-close')?.addEventListener('click', () => {
      saveDismiss();
      hideModal();
    });

    document.getElementById('install-app-later')?.addEventListener('click', () => {
      saveDismiss();
      hideModal();
    });

    document.getElementById('install-app-primary')?.addEventListener('click', async () => {
      if (mode === 'ios' || mode === 'android-manual') {
        saveDismiss();
        hideModal();
        return;
      }

      if (!deferredPrompt) {
        return;
      }

      deferredPrompt.prompt();
      await deferredPrompt.userChoice.catch(() => null);
      deferredPrompt = null;
      saveDismiss();
      hideModal();
    });

    requestAnimationFrame(() => {
      overlayEl?.classList.add('is-visible');
      document.body.classList.add('install-app-open');
    });
  }

  function getInstallMode() {
    if (isIOS()) {
      return 'ios';
    }
    if (deferredPrompt) {
      return 'prompt';
    }
    if (/android/i.test(navigator.userAgent)) {
      return 'android-manual';
    }
    return isMobile() ? 'android-manual' : null;
  }

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
  });

  window.initInstallAppPopup = function initInstallAppPopup(options) {
    const delay = typeof options?.delay === 'number' ? options.delay : 1200;

    if (isStandalone() || isDismissed()) {
      return;
    }

    registerServiceWorker();

    const tryShow = () => {
      const mode = getInstallMode();
      if (!mode || !isMobile()) {
        return;
      }
      buildModal(mode);
    };

    setTimeout(tryShow, delay);

    window.addEventListener(
      'beforeinstallprompt',
      () => {
        if (!overlayEl && !isDismissed() && !isStandalone()) {
          setTimeout(tryShow, 400);
        }
      },
      { once: true }
    );
  };
})();
