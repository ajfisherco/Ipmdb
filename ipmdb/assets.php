<?php
declare(strict_types=1);

// Backwards-compatible route for older Asset Ledger links.
header('Location: /ipmdb/ledger.php', true, 302);
exit;
