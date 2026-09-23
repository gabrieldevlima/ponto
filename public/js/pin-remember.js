(function (window, document) {
  'use strict';

  const STORAGE_KEY = 'deedo.pin.remembered';
  const PIN_REGEX = /^\d{6}$/;
  const TTL_MS = 1000 * 60 * 60 * 24 * 90; // 90 dias
  const pairs = new Set();

  const storageAvailable = (() => {
    try {
      const testKey = '__pin_test__';
      window.localStorage.setItem(testKey, '1');
      window.localStorage.removeItem(testKey);
      return true;
    } catch (err) {
      console.warn('[PinRemember] localStorage não disponível:', err?.message || err);
      return false;
    }
  })();

  function load() {
    if (!storageAvailable) return null;
    try {
      const raw = window.localStorage.getItem(STORAGE_KEY);
      if (!raw) return null;
      const data = JSON.parse(raw);
      if (!data || !PIN_REGEX.test(data.pin || '')) {
        return null;
      }
      if (data.expires && Date.now() > data.expires) {
        window.localStorage.removeItem(STORAGE_KEY);
        return null;
      }
      return data.pin;
    } catch (err) {
      console.warn('[PinRemember] Falha ao ler PIN salvo:', err?.message || err);
      return null;
    }
  }

  function save(pin) {
    if (!storageAvailable || !PIN_REGEX.test(pin || '')) return;
    const payload = {
      pin,
      savedAt: Date.now(),
      expires: Date.now() + TTL_MS
    };
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
    } catch (err) {
      console.warn('[PinRemember] Falha ao salvar PIN:', err?.message || err);
    }
  }

  function clear() {
    if (!storageAvailable) return;
    try {
      window.localStorage.removeItem(STORAGE_KEY);
    } catch (err) {
      console.warn('[PinRemember] Falha ao limpar PIN salvo:', err?.message || err);
    }
  }

  function syncAll() {
    if (!storageAvailable) return;
    const stored = load();
    pairs.forEach((pair) => {
      const { input, checkbox } = pair;
      if (!input?.isConnected) {
        pairs.delete(pair);
        return;
      }
      if (stored) {
        if (checkbox && !checkbox.checked) checkbox.checked = true;
        if (input.value !== stored) input.value = stored;
      } else {
        if (checkbox && checkbox.checked) checkbox.checked = false;
      }
    });
  }

  function register(input, checkbox) {
    if (!storageAvailable || !input) return;
    if (input.dataset.pinRememberBound === '1') {
      syncAll();
      return;
    }

    input.dataset.pinRememberBound = '1';
    pairs.add({ input, checkbox });

    const initial = load();
    if (initial && !input.value) {
      input.value = initial;
      if (checkbox) checkbox.checked = true;
    } else if (!initial && checkbox) {
      checkbox.checked = false;
    }

    const form = input.form;

    const persistIfValid = () => {
      if (!checkbox || !checkbox.checked) return;
      const value = (input.value || '').trim();
      if (PIN_REGEX.test(value)) {
        save(value);
        syncAll();
      }
    };

    checkbox?.addEventListener('change', () => {
      if (!checkbox.checked) {
        clear();
        syncAll();
      } else {
        persistIfValid();
      }
    });

    input.addEventListener('input', () => {
      persistIfValid();
    });

    input.addEventListener('blur', () => {
      persistIfValid();
    });

    form?.addEventListener('submit', () => {
      if (checkbox && checkbox.checked) {
        persistIfValid();
      } else {
        clear();
        syncAll();
      }
    });
  }

  function apply(options) {
    if (!storageAvailable) return;
    const root = options?.root || document;
    if (!root) return;

    root.querySelectorAll('[data-remember-pin="input"]').forEach((input) => {
      const form = input.form || root;
      const checkbox = form
        ? form.querySelector('[data-remember-pin="checkbox"]')
        : root.querySelector('[data-remember-pin="checkbox"]');

      register(input, checkbox);
    });

    syncAll();
  }

  const api = {
    apply,
    load,
    save,
    clear,
    storageAvailable
  };

  window.PinRemember = api;

  if (storageAvailable) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => apply());
    } else {
      apply();
    }
  }
})(window, document);

