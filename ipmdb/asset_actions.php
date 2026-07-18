<?php
declare(strict_types=1);

$currentAssetId = trim((string)($_GET['asset_id'] ?? $_GET['id'] ?? ($assetId ?? '')));

$viewHref = $currentAssetId !== ''
  ? '/ipmdb/viewer.php?asset_id=' . rawurlencode($currentAssetId)
  : '/ipmdb/viewer.php';

$editHref = $currentAssetId !== ''
  ? '/ipmdb/edit.php?asset_id=' . rawurlencode($currentAssetId)
  : '/ipmdb/ledger.php';

$historyHref = $currentAssetId !== ''
  ? '/ipmdb/history.php?asset_id=' . rawurlencode($currentAssetId)
  : '/ipmdb/ledger.php';

$relationshipsHref = $currentAssetId !== ''
  ? '/ipmdb/relationships.php?asset_id=' . rawurlencode($currentAssetId)
  : '/ipmdb/ledger.php';

$addRelationshipHref = $currentAssetId !== ''
  ? '/ipmdb/relationship_add.php?asset_id=' . rawurlencode($currentAssetId)
  : '/ipmdb/ledger.php';
?>

<nav class="ipmdb-asset-actions" aria-label="Asset actions">
  <a href="<?= h($viewHref) ?>">View Asset</a>
  <a href="<?= h($editHref) ?>">Edit</a>
  <a href="<?= h($historyHref) ?>">History</a>
  <a href="<?= h($relationshipsHref) ?>">Relationships</a>
  <a href="<?= h($addRelationshipHref) ?>">Add Relationship</a>
  <a href="/ipmdb/search.php">Search</a>
  <a href="/ipmdb/ledger.php">Ledger</a>
</nav>