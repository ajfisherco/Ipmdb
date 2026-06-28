window.Asset = (() => {
  let lastAssetId = null;
  function setLastAssetId(assetId) { lastAssetId = assetId; }
  function getLastAssetId() { return lastAssetId; }
  return { setLastAssetId, getLastAssetId };
})();
