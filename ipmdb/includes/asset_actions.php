<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/includes/asset_actions.php
|--------------------------------------------------------------------------
| Shared Asset Action Buttons
|--------------------------------------------------------------------------
*/

if (!isset($assetId)) {
    $assetId = '';
}

$id = urlencode((string)$assetId);
?>

<div class="actions">

  <a class="btn primary"
     href="/ipmdb/viewer.php?asset_id=<?= $id ?>">
     View Asset
  </a>

  <a class="btn"
     href="/ipmdb/edit.php?asset_id=<?= $id ?>">
     Edit
  </a>

  <a class="btn"
     href="/ipmdb/history.php?asset_id=<?= $id ?>">
     History
  </a>

  <a class="btn"
     href="/ipmdb/relationships.php?asset_id=<?= $id ?>">
     Relationships
  </a>

  <a class="btn"
     href="/ipmdb/relationship_add.php?asset_id=<?= $id ?>">
     Add Relationship
  </a>

  <a class="btn"
     href="/ipmdb/ai_relationships.php?asset_id=<?= $id ?>">
     AI Map · GPT-5.6
  </a>

  <a class="btn"
     href="/ipmdb/search.php">
     Search
  </a>

  <a class="btn"
     href="/ipmdb/ledger.php">
     Ledger
  </a>

</div>
