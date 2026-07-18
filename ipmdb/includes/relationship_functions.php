<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/includes/relationship_functions.php
|--------------------------------------------------------------------------
| IPMdb Relationship Display + Utility Helpers
| Ideas 2 Assets
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/relationship_types.php';
require_once __DIR__ . '/relationship_queries.php';

function ipmdb_rel_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ipmdb_rel_trim_words(?string $text, int $limit = 160): string
{
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));

    if ($text === '') {
        return '';
    }

    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    return mb_substr($text, 0, max(0, $limit - 1)) . '…';
}

function ipmdb_rel_asset_url(string $assetId): string
{
    $assetId = trim($assetId);

    if ($assetId === '') {
        return '/ipmdb/ledger.php';
    }

    return '/ipmdb/viewer.php?asset_id=' . rawurlencode($assetId);
}

function ipmdb_rel_edit_url(string $assetId): string
{
    $assetId = trim($assetId);

    if ($assetId === '') {
        return '/ipmdb/ledger.php';
    }

    return '/ipmdb/edit.php?asset_id=' . rawurlencode($assetId);
}

function ipmdb_rel_history_url(string $assetId): string
{
    $assetId = trim($assetId);

    if ($assetId === '') {
        return '/ipmdb/history.php';
    }

    return '/ipmdb/history.php?asset_id=' . rawurlencode($assetId);
}

function ipmdb_rel_add_url(string $assetId): string
{
    $assetId = trim($assetId);

    if ($assetId === '') {
        return '/ipmdb/relationship_add.php';
    }

    return '/ipmdb/relationship_add.php?asset_id=' . rawurlencode($assetId);
}

function ipmdb_rel_explorer_url(?string $assetId = null): string
{
    $assetId = trim((string)$assetId);

    if ($assetId === '') {
        return '/ipmdb/relationship_explorer.php';
    }

    return '/ipmdb/relationship_explorer.php?asset_id=' . rawurlencode($assetId);
}

function ipmdb_rel_status_label(?string $status): string
{
    $status = trim((string)$status);

    if ($status === '') {
        return 'Draft';
    }

    return ucwords(strtolower(str_replace(['_', '-'], ' ', $status)));
}

function ipmdb_rel_category_label(?string $category): string
{
    $category = trim((string)$category);

    if ($category === '') {
        return 'Uncategorized';
    }

    return ucwords(strtolower(str_replace(['_', '-'], ' ', $category)));
}

function ipmdb_rel_asset_badge(array $asset): string
{
    $assetId = (string)($asset['asset_id'] ?? '');
    $status = ipmdb_rel_status_label((string)($asset['status'] ?? 'Draft'));
    $category = ipmdb_rel_category_label((string)($asset['category'] ?? 'Uncategorized'));

    return '<div class="rel-badges">'
        . '<span class="rel-badge">' . ipmdb_rel_h($assetId) . '</span>'
        . '<span class="rel-badge">' . ipmdb_rel_h($status) . '</span>'
        . '<span class="rel-badge">' . ipmdb_rel_h($category) . '</span>'
        . '</div>';
}

function ipmdb_rel_render_asset_card(array $asset, ?string $focusAssetId = null): string
{
    $assetId = (string)($asset['asset_id'] ?? '');
    $title = trim((string)($asset['title'] ?? $assetId));
    $idea = ipmdb_rel_trim_words((string)($asset['idea'] ?? ''), 220);
    $category = ipmdb_rel_category_label((string)($asset['category'] ?? 'Uncategorized'));
    $status = ipmdb_rel_status_label((string)($asset['status'] ?? 'Draft'));
    $version = trim((string)($asset['version'] ?? '1.0'));

    $isFocus = $focusAssetId !== null && $focusAssetId !== '' && $focusAssetId === $assetId;
    $class = $isFocus ? 'rel-asset-card is-focus' : 'rel-asset-card';

    $html = '<article class="' . $class . '" data-asset-id="' . ipmdb_rel_h($assetId) . '">';
    $html .= '<div class="rel-card-top">';
    $html .= '<div>';
    $html .= '<h3><a href="' . ipmdb_rel_h(ipmdb_rel_asset_url($assetId)) . '">' . ipmdb_rel_h($title) . '</a></h3>';
    $html .= '<p class="rel-asset-id">' . ipmdb_rel_h($assetId) . '</p>';
    $html .= '</div>';
    $html .= '<span class="rel-version">v' . ipmdb_rel_h($version) . '</span>';
    $html .= '</div>';

    $html .= '<div class="rel-badges">';
    $html .= '<span class="rel-badge">' . ipmdb_rel_h($status) . '</span>';
    $html .= '<span class="rel-badge">' . ipmdb_rel_h($category) . '</span>';
    $html .= '</div>';

    if ($idea !== '') {
        $html .= '<p class="rel-card-idea">' . ipmdb_rel_h($idea) . '</p>';
    } else {
        $html .= '<p class="rel-card-idea muted">No idea text recorded.</p>';
    }

    $html .= '<div class="rel-card-actions">';
    $html .= '<a href="' . ipmdb_rel_h(ipmdb_rel_asset_url($assetId)) . '">View</a>';
    $html .= '<a href="' . ipmdb_rel_h(ipmdb_rel_edit_url($assetId)) . '">Edit</a>';
    $html .= '<a href="' . ipmdb_rel_h(ipmdb_rel_history_url($assetId)) . '">History</a>';
    $html .= '<a href="' . ipmdb_rel_h(ipmdb_rel_add_url($assetId)) . '">Relate</a>';
    $html .= '</div>';

    $html .= '</article>';

    return $html;
}

/*
|--------------------------------------------------------------------------
| CONTINUE WITH PART 2
|--------------------------------------------------------------------------
*/function ipmdb_rel_render_relationship_row(array $relationship, array $assetIndex = []): string
{
    $sourceId = (string)($relationship['source_asset_id'] ?? '');
    $targetId = (string)($relationship['target_asset_id'] ?? '');
    $type = ipmdb_normalize_relationship_type((string)($relationship['relationship_type'] ?? 'relates_to'));
    $label = ipmdb_relationship_type_label($type);
    $class = ipmdb_relationship_type_class($type);
    $symbol = ipmdb_relationship_type_symbol($type);
    $note = ipmdb_rel_trim_words((string)($relationship['note'] ?? ''), 180);

    $sourceTitle = $assetIndex[$sourceId]['title'] ?? $sourceId;
    $targetTitle = $assetIndex[$targetId]['title'] ?? $targetId;

    $html = '<article class="rel-row ' . ipmdb_rel_h($class) . '">';
    $html .= '<div class="rel-row-line">';
    $html .= '<a href="' . ipmdb_rel_h(ipmdb_rel_asset_url($sourceId)) . '">' . ipmdb_rel_h((string)$sourceTitle) . '</a>';
    $html .= '<span class="rel-symbol">' . ipmdb_rel_h($symbol) . '</span>';
    $html .= '<a href="' . ipmdb_rel_h(ipmdb_rel_asset_url($targetId)) . '">' . ipmdb_rel_h((string)$targetTitle) . '</a>';
    $html .= '</div>';

    $html .= '<div class="rel-row-meta">';
    $html .= '<span>' . ipmdb_rel_h($label) . '</span>';
    $html .= '<span>' . ipmdb_rel_h($sourceId) . ' → ' . ipmdb_rel_h($targetId) . '</span>';
    $html .= '</div>';

    if ($note !== '') {
        $html .= '<p>' . ipmdb_rel_h($note) . '</p>';
    }

    $html .= '</article>';

    return $html;
}

function ipmdb_rel_asset_index(array $assets): array
{
    $index = [];

    foreach ($assets as $asset) {
        $assetId = (string)($asset['asset_id'] ?? '');

        if ($assetId !== '') {
            $index[$assetId] = $asset;
        }
    }

    return $index;
}

function ipmdb_rel_connected_asset_ids(array $relationships, string $assetId): array
{
    $assetId = trim($assetId);
    $ids = [];

    foreach ($relationships as $rel) {
        $source = trim((string)($rel['source_asset_id'] ?? ''));
        $target = trim((string)($rel['target_asset_id'] ?? ''));

        if ($source === $assetId && $target !== '') {
            $ids[$target] = true;
        }

        if ($target === $assetId && $source !== '') {
            $ids[$source] = true;
        }
    }

    return array_keys($ids);
}

function ipmdb_rel_graph_json(array $graphData): string
{
    $nodes = [];
    $edges = [];

    foreach (($graphData['assets'] ?? []) as $asset) {
        $assetId = (string)($asset['asset_id'] ?? '');

        if ($assetId === '') {
            continue;
        }

        $nodes[] = [
            'id' => $assetId,
            'title' => (string)($asset['title'] ?? $assetId),
            'category' => ipmdb_rel_category_label((string)($asset['category'] ?? 'Uncategorized')),
            'status' => ipmdb_rel_status_label((string)($asset['status'] ?? 'Draft')),
            'version' => (string)($asset['version'] ?? '1.0'),
            'idea' => ipmdb_rel_trim_words((string)($asset['idea'] ?? ''), 260),
            'url' => ipmdb_rel_asset_url($assetId),
            'edit_url' => ipmdb_rel_edit_url($assetId),
            'history_url' => ipmdb_rel_history_url($assetId),
        ];
    }

    foreach (($graphData['relationships'] ?? []) as $rel) {
        $sourceId = (string)($rel['source_asset_id'] ?? '');
        $targetId = (string)($rel['target_asset_id'] ?? '');

        if ($sourceId === '' || $targetId === '') {
            continue;
        }

        $type = ipmdb_normalize_relationship_type((string)($rel['relationship_type'] ?? 'relates_to'));

        $edges[] = [
            'id' => (string)($rel['id'] ?? ($sourceId . '-' . $targetId)),
            'source' => $sourceId,
            'target' => $targetId,
            'type' => $type,
            'label' => ipmdb_relationship_type_label($type),
            'symbol' => ipmdb_relationship_type_symbol($type),
            'class' => ipmdb_relationship_type_class($type),
            'note' => ipmdb_rel_trim_words((string)($rel['note'] ?? ''), 220),
        ];
    }

    return json_encode([
        'focus_asset_id' => (string)($graphData['focus_asset_id'] ?? ''),
        'counts' => $graphData['counts'] ?? [
            'assets' => count($nodes),
            'relationships' => count($edges),
        ],
        'nodes' => $nodes,
        'edges' => $edges,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

/*
|--------------------------------------------------------------------------
| CONTINUE WITH PART 3
|--------------------------------------------------------------------------
*/function ipmdb_rel_render_type_filters(?string $selected = null, ?string $focusAssetId = null): string
{
    $selected = trim((string)$selected);
    $focusAssetId = trim((string)$focusAssetId);

    $base = '/ipmdb/relationship_explorer.php';
    $assetParam = $focusAssetId !== '' ? '&asset_id=' . rawurlencode($focusAssetId) : '';

    $html = '<div class="rel-type-filters">';
    $html .= '<a class="' . ($selected === '' ? 'active' : '') . '" href="' . $base . ($focusAssetId !== '' ? '?asset_id=' . rawurlencode($focusAssetId) : '') . '">All</a>';

    foreach (ipmdb_relationship_types() as $key => $type) {
        $class = $selected === $key ? 'active' : '';
        $href = $base . '?type=' . rawurlencode($key) . $assetParam;

        $html .= '<a class="' . ipmdb_rel_h($class) . '" href="' . ipmdb_rel_h($href) . '">';
        $html .= ipmdb_rel_h((string)$type['label']);
        $html .= '</a>';
    }

    $html .= '</div>';

    return $html;
}

function ipmdb_rel_filter_relationships_by_type(array $relationships, ?string $type): array
{
    $type = trim((string)$type);

    if ($type === '') {
        return $relationships;
    }

    $type = ipmdb_normalize_relationship_type($type);

    return array_values(array_filter($relationships, static function (array $rel) use ($type): bool {
        return ipmdb_normalize_relationship_type((string)($rel['relationship_type'] ?? 'relates_to')) === $type;
    }));
}

function ipmdb_rel_filter_assets_for_relationships(array $assets, array $relationships, ?string $focusAssetId = null): array
{
    $keep = [];

    $focusAssetId = trim((string)$focusAssetId);

    if ($focusAssetId !== '') {
        $keep[$focusAssetId] = true;
    }

    foreach ($relationships as $rel) {
        $source = trim((string)($rel['source_asset_id'] ?? ''));
        $target = trim((string)($rel['target_asset_id'] ?? ''));

        if ($source !== '') {
            $keep[$source] = true;
        }

        if ($target !== '') {
            $keep[$target] = true;
        }
    }

    if (!$keep) {
        return $assets;
    }

    return array_values(array_filter($assets, static function (array $asset) use ($keep): bool {
        $assetId = (string)($asset['asset_id'] ?? '');
        return isset($keep[$assetId]);
    }));
}

function ipmdb_rel_render_stats(array $graphData): string
{
    $assetCount = (int)($graphData['counts']['assets'] ?? count($graphData['assets'] ?? []));
    $relationshipCount = (int)($graphData['counts']['relationships'] ?? count($graphData['relationships'] ?? []));

    $countsByType = ipmdb_relationship_counts_by_type($graphData['relationships'] ?? []);

    $html = '<section class="rel-stats">';
    $html .= '<div class="rel-stat"><strong>' . $assetCount . '</strong><span>Assets</span></div>';
    $html .= '<div class="rel-stat"><strong>' . $relationshipCount . '</strong><span>Relationships</span></div>';

    foreach ($countsByType as $type) {
        if ((int)$type['count'] <= 0) {
            continue;
        }

        $html .= '<div class="rel-stat small ' . ipmdb_rel_h((string)$type['class']) . '">';
        $html .= '<strong>' . (int)$type['count'] . '</strong>';
        $html .= '<span>' . ipmdb_rel_h((string)$type['label']) . '</span>';
        $html .= '</div>';
    }

    $html .= '</section>';

    return $html;
}

function ipmdb_rel_render_empty_state(string $title = 'No relationships found', string $message = 'Create relationships to begin mapping the asset domain.'): string
{
    return '<section class="rel-empty">'
        . '<h2>' . ipmdb_rel_h($title) . '</h2>'
        . '<p>' . ipmdb_rel_h($message) . '</p>'
        . '<a href="/ipmdb/relationship_add.php">Add Relationship</a>'
        . '</section>';
}

function ipmdb_rel_page_title(?string $focusAssetId = null): string
{
    $focusAssetId = trim((string)$focusAssetId);

    if ($focusAssetId !== '') {
        return 'Relationship Explorer · ' . $focusAssetId;
    }

    return 'Relationship Explorer';
}

function ipmdb_rel_safe_return_url(?string $url): string
{
    $url = trim((string)$url);

    if ($url === '') {
        return '/ipmdb/relationship_explorer.php';
    }

    if (str_starts_with($url, '/ipmdb/')) {
        return $url;
    }

    return '/ipmdb/relationship_explorer.php';
}

function ipmdb_rel_prepare_graph(PDO $pdo, ?string $focusAssetId = null, ?string $type = null, int $limit = 80): array
{
    $focusAssetId = trim((string)$focusAssetId);
    $type = trim((string)$type);

    $graphData = ipmdb_relationship_graph_data($pdo, $focusAssetId !== '' ? $focusAssetId : null, $limit);

    if ($type !== '') {
        $graphData['relationships'] = ipmdb_rel_filter_relationships_by_type($graphData['relationships'] ?? [], $type);
        $graphData['assets'] = ipmdb_rel_filter_assets_for_relationships(
            $graphData['assets'] ?? [],
            $graphData['relationships'] ?? [],
            $focusAssetId
        );

        $graphData['counts'] = [
            'assets' => count($graphData['assets']),
            'relationships' => count($graphData['relationships']),
        ];
    }

    return $graphData;
}

function ipmdb_rel_render_asset_list(array $assets, ?string $focusAssetId = null): string
{
    if (!$assets) {
        return ipmdb_rel_render_empty_state('No assets found', 'No assets are available for this relationship view.');
    }

    $html = '<div class="rel-asset-list">';

    foreach ($assets as $asset) {
        $html .= ipmdb_rel_render_asset_card($asset, $focusAssetId);
    }

    $html .= '</div>';

    return $html;
}

function ipmdb_rel_render_relationship_list(array $relationships, array $assets = []): string
{
    if (!$relationships) {
        return ipmdb_rel_render_empty_state('No relationships found', 'Add a relationship to begin mapping asset connections.');
    }

    $assetIndex = ipmdb_rel_asset_index($assets);

    $html = '<div class="rel-relationship-list">';

    foreach ($relationships as $relationship) {
        $html .= ipmdb_rel_render_relationship_row($relationship, $assetIndex);
    }

    $html .= '</div>';

    return $html;
}

function ipmdb_rel_current_url(): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/ipmdb/relationship_explorer.php');

    if ($uri === '' || !str_starts_with($uri, '/ipmdb/')) {
        return '/ipmdb/relationship_explorer.php';
    }

    return $uri;
}

function ipmdb_rel_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/*
|--------------------------------------------------------------------------
| END OF FILE
|--------------------------------------------------------------------------
*/