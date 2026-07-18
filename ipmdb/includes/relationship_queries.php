<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/includes/relationship_queries.php
|--------------------------------------------------------------------------
| IPMdb Relationship Query Helpers
| Ideas 2 Assets
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/relationship_types.php';

function ipmdb_relationship_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $safeTable = str_replace('`', '', $table);
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$safeTable}`");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $cache[$table] = array_map('strval', $columns ?: []);
    } catch (Throwable $e) {
        $cache[$table] = [];
    }

    return $cache[$table];
}

function ipmdb_relationship_column_exists(PDO $pdo, string $table, string $column): bool
{
    return in_array($column, ipmdb_relationship_table_columns($pdo, $table), true);
}

function ipmdb_asset_title_expr(PDO $pdo, string $alias = 'a'): string
{
    return ipmdb_relationship_column_exists($pdo, 'ipmdb_assets', 'title')
        ? "COALESCE(NULLIF({$alias}.title, ''), {$alias}.asset_id)"
        : "{$alias}.asset_id";
}

function ipmdb_asset_idea_expr(PDO $pdo, string $alias = 'a'): string
{
    return ipmdb_relationship_column_exists($pdo, 'ipmdb_assets', 'idea')
        ? "COALESCE({$alias}.idea, '')"
        : "''";
}

function ipmdb_asset_category_expr(PDO $pdo, string $alias = 'a'): string
{
    return ipmdb_relationship_column_exists($pdo, 'ipmdb_assets', 'category')
        ? "COALESCE(NULLIF({$alias}.category, ''), 'Uncategorized')"
        : "'Uncategorized'";
}

function ipmdb_asset_status_expr(PDO $pdo, string $alias = 'a'): string
{
    return ipmdb_relationship_column_exists($pdo, 'ipmdb_assets', 'status')
        ? "COALESCE(NULLIF({$alias}.status, ''), 'Draft')"
        : "'Draft'";
}

function ipmdb_asset_version_expr(PDO $pdo, string $alias = 'a'): string
{
    return ipmdb_relationship_column_exists($pdo, 'ipmdb_assets', 'version')
        ? "COALESCE(NULLIF({$alias}.version, ''), '1.0')"
        : "'1.0'";
}

function ipmdb_asset_email_expr(PDO $pdo, string $alias = 'a'): string
{
    return ipmdb_relationship_column_exists($pdo, 'ipmdb_assets', 'email')
        ? "COALESCE({$alias}.email, '')"
        : "''";
}

function ipmdb_asset_created_expr(PDO $pdo, string $alias = 'a'): string
{
    return ipmdb_relationship_column_exists($pdo, 'ipmdb_assets', 'created_at')
        ? "COALESCE({$alias}.created_at, '')"
        : "''";
}

function ipmdb_asset_updated_expr(PDO $pdo, string $alias = 'a'): string
{
    return ipmdb_relationship_column_exists($pdo, 'ipmdb_assets', 'updated_at')
        ? "COALESCE({$alias}.updated_at, '')"
        : "''";
}

function ipmdb_get_asset(PDO $pdo, string $assetId): ?array
{
    $assetId = trim($assetId);

    if ($assetId === '') {
        return null;
    }

    $title = ipmdb_asset_title_expr($pdo, 'a');
    $idea = ipmdb_asset_idea_expr($pdo, 'a');
    $category = ipmdb_asset_category_expr($pdo, 'a');
    $status = ipmdb_asset_status_expr($pdo, 'a');
    $version = ipmdb_asset_version_expr($pdo, 'a');
    $created = ipmdb_asset_created_expr($pdo, 'a');
    $updated = ipmdb_asset_updated_expr($pdo, 'a');

    $stmt = $pdo->prepare("
        SELECT
            a.asset_id,
            {$title} AS title,
            {$idea} AS idea,
            {$category} AS category,
            {$status} AS status,
            {$version} AS version,
            {$created} AS created_at,
            {$updated} AS updated_at
        FROM ipmdb_assets a
        WHERE a.asset_id = :asset_id
        LIMIT 1
    ");

    $stmt->execute([
        'asset_id' => $assetId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function ipmdb_recent_assets(PDO $pdo, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));

    $title = ipmdb_asset_title_expr($pdo, 'a');
    $idea = ipmdb_asset_idea_expr($pdo, 'a');
    $category = ipmdb_asset_category_expr($pdo, 'a');
    $status = ipmdb_asset_status_expr($pdo, 'a');
    $version = ipmdb_asset_version_expr($pdo, 'a');
    $created = ipmdb_asset_created_expr($pdo, 'a');
    $updated = ipmdb_asset_updated_expr($pdo, 'a');

    $orderColumn = ipmdb_relationship_column_exists($pdo, 'ipmdb_assets', 'created_at')
        ? 'a.created_at'
        : 'a.asset_id';

    $stmt = $pdo->query("
        SELECT
            a.asset_id,
            {$title} AS title,
            {$idea} AS idea,
            {$category} AS category,
            {$status} AS status,
            {$version} AS version,
            {$created} AS created_at,
            {$updated} AS updated_at
        FROM ipmdb_assets a
        ORDER BY {$orderColumn} DESC
        LIMIT {$limit}
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ipmdb_search_assets(PDO $pdo, string $query = '', int $limit = 30): array
{
    $query = trim($query);
    $limit = max(1, min(100, $limit));

    if ($query === '') {
        return ipmdb_recent_assets($pdo, $limit);
    }

    $title = ipmdb_asset_title_expr($pdo, 'a');
    $idea = ipmdb_asset_idea_expr($pdo, 'a');
    $category = ipmdb_asset_category_expr($pdo, 'a');
    $status = ipmdb_asset_status_expr($pdo, 'a');
    $version = ipmdb_asset_version_expr($pdo, 'a');
    $created = ipmdb_asset_created_expr($pdo, 'a');
    $updated = ipmdb_asset_updated_expr($pdo, 'a');

    $stmt = $pdo->prepare("
        SELECT
            a.asset_id,
            {$title} AS title,
            {$idea} AS idea,
            {$category} AS category,
            {$status} AS status,
            {$version} AS version,
            {$created} AS created_at,
            {$updated} AS updated_at
        FROM ipmdb_assets a
        WHERE
            a.asset_id LIKE :q_asset_id
            OR {$title} LIKE :q_title
            OR {$idea} LIKE :q_idea
            OR {$category} LIKE :q_category
            OR {$status} LIKE :q_status
        ORDER BY a.asset_id DESC
        LIMIT {$limit}
    ");

    $needle = '%' . $query . '%';

    $stmt->execute([
        'q_asset_id' => $needle,
        'q_title' => $needle,
        'q_idea' => $needle,
        'q_category' => $needle,
        'q_status' => $needle,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ipmdb_relationship_source_column(PDO $pdo): string
{
    $columns = ipmdb_relationship_table_columns($pdo, 'ipmdb_relationships');

    foreach (['source_asset_id', 'from_asset_id', 'parent_asset_id', 'asset_id'] as $column) {
        if (in_array($column, $columns, true)) {
            return $column;
        }
    }

    return 'source_asset_id';
}

function ipmdb_relationship_target_column(PDO $pdo): string
{
    $columns = ipmdb_relationship_table_columns($pdo, 'ipmdb_relationships');

    foreach (['target_asset_id', 'to_asset_id', 'child_asset_id', 'related_asset_id'] as $column) {
        if (in_array($column, $columns, true)) {
            return $column;
        }
    }

    return 'target_asset_id';
}

function ipmdb_relationship_type_column(PDO $pdo): ?string
{
    $columns = ipmdb_relationship_table_columns($pdo, 'ipmdb_relationships');

    foreach (['relationship_type', 'type', 'relation_type'] as $column) {
        if (in_array($column, $columns, true)) {
            return $column;
        }
    }

    return null;
}

function ipmdb_relationship_note_column(PDO $pdo): ?string
{
    $columns = ipmdb_relationship_table_columns($pdo, 'ipmdb_relationships');

    foreach (['note', 'notes', 'description'] as $column) {
        if (in_array($column, $columns, true)) {
            return $column;
        }
    }

    return null;
}

function ipmdb_relationship_created_column(PDO $pdo): ?string
{
    $columns = ipmdb_relationship_table_columns($pdo, 'ipmdb_relationships');

    foreach (['created_at', 'saved_at', 'updated_at'] as $column) {
        if (in_array($column, $columns, true)) {
            return $column;
        }
    }

    return null;
}

function ipmdb_get_relationships(PDO $pdo, ?string $assetId = null): array
{
    $source = ipmdb_relationship_source_column($pdo);
    $target = ipmdb_relationship_target_column($pdo);
    $typeColumn = ipmdb_relationship_type_column($pdo);
    $noteColumn = ipmdb_relationship_note_column($pdo);
    $createdColumn = ipmdb_relationship_created_column($pdo);

    $typeExpr = $typeColumn
        ? "COALESCE(NULLIF(r.`{$typeColumn}`, ''), 'relates_to')"
        : "'relates_to'";

    $noteExpr = $noteColumn
        ? "COALESCE(r.`{$noteColumn}`, '')"
        : "''";

    $createdExpr = $createdColumn
        ? "COALESCE(r.`{$createdColumn}`, '')"
        : "''";

    $sql = "
        SELECT
            r.id,
            r.`{$source}` AS source_asset_id,
            r.`{$target}` AS target_asset_id,
            {$typeExpr} AS relationship_type,
            {$noteExpr} AS note,
            {$createdExpr} AS created_at
        FROM ipmdb_relationships r
    ";

    $params = [];

    if ($assetId !== null && trim($assetId) !== '') {
        $assetId = trim($assetId);

        $sql .= "
            WHERE r.`{$source}` = :source_asset_id
               OR r.`{$target}` = :target_asset_id
        ";

        $params = [
            'source_asset_id' => $assetId,
            'target_asset_id' => $assetId,
        ];
    }

    $sql .= " ORDER BY r.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['relationship_type'] = ipmdb_normalize_relationship_type((string)($row['relationship_type'] ?? 'relates_to'));
        $row['type_label'] = ipmdb_relationship_type_label($row['relationship_type']);
        $row['type_class'] = ipmdb_relationship_type_class($row['relationship_type']);
        $row['type_symbol'] = ipmdb_relationship_type_symbol($row['relationship_type']);
    }

    unset($row);

    return $rows;
}

function ipmdb_relationship_graph_data(PDO $pdo, ?string $focusAssetId = null, int $limit = 80): array
{
    $limit = max(1, min(200, $limit));
    $focusAssetId = trim((string)$focusAssetId);

    $assets = [];
    $relationships = [];

    if ($focusAssetId !== '') {
        $focus = ipmdb_get_asset($pdo, $focusAssetId);

        if ($focus) {
            $assets[(string)$focus['asset_id']] = $focus;
        }

        $relationships = ipmdb_get_relationships($pdo, $focusAssetId);

        foreach ($relationships as $rel) {
            foreach (['source_asset_id', 'target_asset_id'] as $key) {
                $id = trim((string)($rel[$key] ?? ''));

                if ($id !== '' && !isset($assets[$id])) {
                    $asset = ipmdb_get_asset($pdo, $id);

                    if ($asset) {
                        $assets[$id] = $asset;
                    }
                }
            }
        }
    } else {
        $recent = ipmdb_recent_assets($pdo, $limit);

        foreach ($recent as $asset) {
            $id = (string)($asset['asset_id'] ?? '');

            if ($id !== '') {
                $assets[$id] = $asset;
            }
        }

        $relationships = ipmdb_get_relationships($pdo, null);

        $relationships = array_values(array_filter($relationships, static function (array $rel) use ($assets): bool {
            $source = (string)($rel['source_asset_id'] ?? '');
            $target = (string)($rel['target_asset_id'] ?? '');

            return isset($assets[$source]) || isset($assets[$target]);
        }));

        foreach ($relationships as $rel) {
            foreach (['source_asset_id', 'target_asset_id'] as $key) {
                $id = trim((string)($rel[$key] ?? ''));

                if ($id !== '' && !isset($assets[$id])) {
                    $asset = ipmdb_get_asset($pdo, $id);

                    if ($asset) {
                        $assets[$id] = $asset;
                    }
                }
            }
        }
    }

    return [
        'focus_asset_id' => $focusAssetId,
        'assets' => array_values($assets),
        'relationships' => $relationships,
        'counts' => [
            'assets' => count($assets),
            'relationships' => count($relationships),
        ],
    ];
}

function ipmdb_relationship_counts_by_type(array $relationships): array
{
    $counts = [];

    foreach (ipmdb_relationship_types() as $key => $type) {
        $counts[$key] = [
            'label' => $type['label'],
            'count' => 0,
            'class' => $type['class'],
        ];
    }

    foreach ($relationships as $rel) {
        $type = ipmdb_normalize_relationship_type((string)($rel['relationship_type'] ?? 'relates_to'));

        if (!isset($counts[$type])) {
            $counts[$type] = [
                'label' => ipmdb_relationship_type_label($type),
                'count' => 0,
                'class' => ipmdb_relationship_type_class($type),
            ];
        }

        $counts[$type]['count']++;
    }

    return $counts;
}
