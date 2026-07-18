<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/includes/relationship_types.php
|--------------------------------------------------------------------------
| IPMdb Relationship Types
| Ideas 2 Assets
|--------------------------------------------------------------------------
*/

function ipmdb_relationship_types(): array
{
    return [
        'relates_to' => [
            'label' => 'Relates To',
            'description' => 'General meaningful connection between two assets.',
            'class' => 'type-relates',
            'symbol' => '↔',
        ],
        'depends_on' => [
            'label' => 'Depends On',
            'description' => 'This asset depends on another asset.',
            'class' => 'type-depends',
            'symbol' => '→',
        ],
        'part_of' => [
            'label' => 'Part Of',
            'description' => 'This asset belongs inside a larger asset.',
            'class' => 'type-part',
            'symbol' => '⊂',
        ],
        'blocks' => [
            'label' => 'Blocks',
            'description' => 'This asset prevents or constrains another asset.',
            'class' => 'type-blocks',
            'symbol' => '⊘',
        ],
        'implements' => [
            'label' => 'Implements',
            'description' => 'This asset implements another asset.',
            'class' => 'type-implements',
            'symbol' => '✓',
        ],
        'enhances' => [
            'label' => 'Enhances',
            'description' => 'This asset improves or extends another asset.',
            'class' => 'type-enhances',
            'symbol' => '+',
        ],
        'documents' => [
            'label' => 'Documents',
            'description' => 'This asset documents another asset.',
            'class' => 'type-documents',
            'symbol' => '▣',
        ],
        'supersedes' => [
            'label' => 'Supersedes',
            'description' => 'This asset replaces or succeeds another asset.',
            'class' => 'type-supersedes',
            'symbol' => '⇢',
        ],
    ];
}

function ipmdb_relationship_type_keys(): array
{
    return array_keys(ipmdb_relationship_types());
}

function ipmdb_relationship_type_exists(string $type): bool
{
    return array_key_exists($type, ipmdb_relationship_types());
}

function ipmdb_relationship_type_label(?string $type): string
{
    $type = trim((string)$type);
    $types = ipmdb_relationship_types();

    if ($type !== '' && isset($types[$type]['label'])) {
        return (string)$types[$type]['label'];
    }

    return 'Relates To';
}

function ipmdb_relationship_type_class(?string $type): string
{
    $type = trim((string)$type);
    $types = ipmdb_relationship_types();

    if ($type !== '' && isset($types[$type]['class'])) {
        return (string)$types[$type]['class'];
    }

    return 'type-relates';
}

function ipmdb_relationship_type_symbol(?string $type): string
{
    $type = trim((string)$type);
    $types = ipmdb_relationship_types();

    if ($type !== '' && isset($types[$type]['symbol'])) {
        return (string)$types[$type]['symbol'];
    }

    return '↔';
}

function ipmdb_normalize_relationship_type(?string $type): string
{
    $type = strtolower(trim((string)$type));
    $type = str_replace([' ', '-'], '_', $type);

    if (ipmdb_relationship_type_exists($type)) {
        return $type;
    }

    return 'relates_to';
}