window.IPMAsset = (() => {
  const key = 'ipmdb_asset_sequence';

  function todayParts(date = new Date()) {
    return {
      y: String(date.getFullYear()),
      m: String(date.getMonth() + 1).padStart(2, '0'),
      d: String(date.getDate()).padStart(2, '0')
    };
  }

  function nextSequence() {
    const current = Number(localStorage.getItem(key) || '0') + 1;
    localStorage.setItem(key, String(current));
    return String(current).padStart(6, '0');
  }

  function createAssetId(date = new Date()) {
    const { y, m, d } = todayParts(date);
    return `IPM-${y}${m}${d}-${nextSequence()}`;
  }

  function timestamp(date = new Date()) {
    return date.toISOString();
  }

  return { createAssetId, timestamp };
})();
