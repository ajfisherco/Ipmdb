<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/schema/entity_schema.php
|--------------------------------------------------------------------------
| IPMdb Core Entity Schema
|--------------------------------------------------------------------------
|
| Defines the common structure shared by every IPMdb entity.
|
| This file performs no database operations.
| It provides the canonical vocabulary for identity, provenance,
| lifecycle, relationships, governance, knowledge, and attribution.
|
| Humanity gives knowledge purpose.
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

return [

    'schema_version' => '1.0.0',

    'platform' => 'IPMdb.ai',

    'entity_type' => 'entity',

    'display_name' => 'Entity',

    'description' =>
        'The common foundation for identifiable, attributable, '
        . 'versioned, related, and discoverable knowledge.',

    'table' => null,

    'primary_key' => 'id',

    'public_key' => 'entity_id',

    'fields' => [

        'id' => [
            'type' => 'integer',
            'required' => false,
            'generated' => true,
            'editable' => false,
        ],

        'entity_id' => [
            'type' => 'string',
            'length' => 64,
            'required' => true,
            'unique' => true,
            'editable' => false,
            'label' => 'Entity ID',
        ],

        'entity_type' => [
            'type' => 'string',
            'length' => 64,
            'required' => true,
            'indexed' => true,
            'label' => 'Entity Type',
        ],

        'title' => [
            'type' => 'string',
            'length' => 255,
            'required' => true,
            'searchable' => true,
            'label' => 'Title',
        ],

        'summary' => [
            'type' => 'text',
            'required' => false,
            'searchable' => true,
            'label' => 'Summary',
        ],

        'content' => [
            'type' => 'text',
            'required' => false,
            'searchable' => true,
            'label' => 'Content',
        ],

        'status' => [
            'type' => 'enum',
            'values' => [
                'draft',
                'proposed',
                'active',
                'verified',
                'implemented',
                'archived',
                'disputed',
            ],
            'default' => 'draft',
            'required' => true,
            'indexed' => true,
            'label' => 'Status',
        ],

        'version' => [
            'type' => 'string',
            'length' => 32,
            'default' => '1.0',
            'required' => true,
            'label' => 'Version',
        ],

        'originator_id' => [
            'type' => 'string',
            'length' => 64,
            'required' => false,
            'indexed' => true,
            'label' => 'Originator',
        ],

        'source_type' => [
            'type' => 'string',
            'length' => 64,
            'required' => false,
            'indexed' => true,
            'label' => 'Source Type',
        ],

        'source_reference' => [
            'type' => 'string',
            'length' => 2048,
            'required' => false,
            'searchable' => true,
            'label' => 'Source Reference',
        ],

        'license' => [
            'type' => 'string',
            'length' => 128,
            'required' => false,
            'indexed' => true,
            'label' => 'License',
        ],

        'visibility' => [
            'type' => 'enum',
            'values' => [
                'public',
                'unlisted',
                'restricted',
                'private',
            ],
            'default' => 'public',
            'required' => true,
            'indexed' => true,
            'label' => 'Visibility',
        ],

        'confidence' => [
            'type' => 'decimal',
            'precision' => '5,2',
            'required' => false,
            'minimum' => 0,
            'maximum' => 100,
            'label' => 'Confidence',
        ],

        'locked' => [
            'type' => 'boolean',
            'default' => false,
            'required' => true,
            'indexed' => true,
            'label' => 'Locked',
        ],

        'created_at' => [
            'type' => 'datetime',
            'required' => true,
            'generated' => true,
            'editable' => false,
            'label' => 'Created',
        ],

        'updated_at' => [
            'type' => 'datetime',
            'required' => true,
            'generated' => true,
            'label' => 'Updated',
        ],

        'verified_at' => [
            'type' => 'datetime',
            'required' => false,
            'label' => 'Verified',
        ],

        'archived_at' => [
            'type' => 'datetime',
            'required' => false,
            'label' => 'Archived',
        ],
    ],

    'identity' => [
        'entity_id',
        'entity_type',
        'title',
        'status',
        'version',
    ],

    'provenance' => [
        'originator_id',
        'source_type',
        'source_reference',
        'license',
        'created_at',
        'updated_at',
    ],

    'lifecycle' => [
        'status',
        'version',
        'created_at',
        'updated_at',
        'verified_at',
        'archived_at',
    ],

    'governance' => [
        'visibility',
        'confidence',
        'locked',
    ],

    'knowledge' => [
        'summary',
        'content',
    ],

    'relationship_rules' => [

        'allow_self_relationships' => false,

        'allow_duplicate_relationships' => false,

        'require_relationship_type' => true,

        'require_provenance' => true,

        'directional_by_default' => true,
    ],

    'capabilities' => [
        'identify',
        'attribute',
        'version',
        'relate',
        'search',
        'verify',
        'archive',
        'export',
    ],

    'principles' => [
        'Every idea has value.',
        'Every entity has identity.',
        'Every asset has provenance.',
        'Every relationship has context.',
        'Every revision has history.',
        'Every contributor has attribution.',
        'Every decision has consequences.',
        'Every discovery has a place.',
        'Humanity gives knowledge purpose.',
        'SQ assists.',
        'The Doer decides.',
    ],
];