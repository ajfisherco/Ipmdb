(function () {
  'use strict';

  const listeners = new Set();
  let current = null;

  async function load(assetId) {
    const response = await fetch(`./api/publication.php?asset_id=${encodeURIComponent(assetId)}`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    const result = await response.json();
    if (!response.ok || !result.ok) {
      throw new Error(result.error || 'Publication could not be loaded.');
    }

    current = result;
    document.documentElement.lang = result.served_language;
    listeners.forEach((listener) => listener(result));
    return result;
  }

  async function save(language, fallback = 'en', autoPublish = true) {
    const response = await fetch('./api/doer-language.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ language, fallback, auto_publish: autoPublish })
    });
    const result = await response.json();
    if (!response.ok || !result.ok) {
      throw new Error(result.error || 'Language setting could not be saved.');
    }
    return result.preference;
  }

  function subscribe(listener) {
    listeners.add(listener);
    if (current) listener(current);
    return () => listeners.delete(listener);
  }

  window.IPMdbLanguage = { load, save, subscribe, get current() { return current; } };
})();
