<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /site1/ipmdb/relationship_filters.php
|--------------------------------------------------------------------------
| Shared filtering helpers for IPMdb relationship pages.
|--------------------------------------------------------------------------
*/

function ipmdb_relationship_filter_value(
    string $key,
    string $default = '',
    int $maxLength = 255
): string {
    $value = $default;

    if (
        array_key_exists($key, $_GET) &&
        !is_array($_GET[$key])
    ) {
        $value = (string)$_GET[$key];
    } elseif (
        array_key_exists($key, $_POST) &&
        !is_array($_POST[$key])
    ) {
        $value = (string)$_POST[$key];
    }

    $value = trim($value);

    if ($value === '') {
        return '';
    }

    return mb_substr($value, 0, $maxLength, 'UTF-8');
}

function ipmdb_relationship_filter_params(): array
{
    return [
        'q' => ipmdb_relationship_filter_value('q', '', 200),
        'type' => ipmdb_relationship_filter_value('type', '', 120),
        'status' => ipmdb_relationship_filter_value('status', '', 40),
        'asset_id' => ipmdb_relationship_filter_value('asset_id', '', 120),
    ];
}

function ipmdb_relationship_filter_where(
    array $filters,
    array &$params
): array {
    $where = [];

    $query = trim((string)($filters['q'] ?? ''));
    $type = trim((string)($filters['type'] ?? ''));
    $status = trim((string)($filters['status'] ?? ''));
    $assetId = trim((string)($filters['asset_id'] ?? ''));

    if ($query !== '') {
        $searchValue = '%' . $query . '%';

        $where[] = "
            (
                source_asset_id LIKE :q_source
                OR target_asset_id LIKE :q_target
                OR relationship_type LIKE :q_type
                OR notes LIKE :q_notes
            )
        ";

        $params[':q_source'] = $searchValue;
        $params[':q_target'] = $searchValue;
        $params[':q_type'] = $searchValue;
        $params[':q_notes'] = $searchValue;
    }

    if ($type !== '') {
        $where[] = 'relationship_type = :filter_type';
        $params[':filter_type'] = $type;
    }

    if ($status !== '') {
        $where[] = 'status = :filter_status';
        $params[':filter_status'] = $status;
    }

    if ($assetId !== '') {
        $where[] = "
            (
                source_asset_id = :asset_source
                OR target_asset_id = :asset_target
            )
        ";

        $params[':asset_source'] = $assetId;
        $params[':asset_target'] = $assetId;
    }

    return $where;
}

function ipmdb_relationship_filter_query(
    array $filters,
    array $overrides = []
): string {
    $values = array_merge(
        [
            'q' => '',
            'type' => '',
            'status' => '',
            'asset_id' => '',
        ],
        $filters,
        $overrides
    );

    $values = array_filter(
        $values,
        static fn(mixed $value): bool =>
            is_scalar($value) && trim((string)$value) !== ''
    );

    return http_build_query($values);
}