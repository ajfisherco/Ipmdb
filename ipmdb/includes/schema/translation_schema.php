<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/schema/translation_schema.php
|--------------------------------------------------------------------------
| IPMdb Translation Entity Schema
|--------------------------------------------------------------------------
|
| Defines translations as independent, attributable, versioned entities.
|
| A translation never overwrites its source.
| It remains linked to the original entity through provenance.
| Human and machine translations use the same accountable structure.
|
| Meaning is preserved.
| Differences remain visible.
| Humanity determines context.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

return [

    'schema_version' => '1.0.0',

    'platform' => 'IPMdb.ai',

    'entity_type' => 'translation',

    'display_name' => 'Translation',

    'description' =>
        'An attributable language rendering of a source entity, '
        . 'preserved as a distinct versioned knowledge entity.',

    'table' => 'ipmdb_translations',

    'primary_key' => 'id',

    'public_key' => 'translation_id',

    'fields' => [

        'id' => [
            'type' => 'integer',
            'required' => false,
            'generated' => true,
            'editable' => false,
            'label' => 'Internal ID',
        ],

        'translation_id' => [
            'type' => 'string',
            'length' => 64,
            'required' => true,
            'unique' => true,
            'indexed' => true,
            'editable' => false,
            'label' => 'Translation ID',
        ],

        'entity_type' => [
            'type' => 'string',
            'length' => 64,
            'default' => 'translation',
            'required' => true,
            'indexed' => true,
            'editable' => false,
            'label' => 'Entity Type',
        ],

        'source_entity_id' => [
            'type' => 'string',
            'length' => 64,
            'required' => true,
            'indexed' => true,
            'label' => 'Source Entity',
        ],

        'source_entity_type' => [
            'type' => 'string',
            'length' => 64,
            'required' => true,
            'indexed' => true,
            'label' => 'Source Entity Type',
        ],

        'source_version' => [
            'type' => 'string',
            'length' => 32,
            'required' => false,
            'label' => 'Source Version',
        ],

        'source_language' => [
            'type' => 'string',
            'length' => 35,
            'required' => true,
            'indexed' => true,
            'label' => 'Source Language',
            'description' =>
                'BCP 47 language tag, such as en, en-CA, fr, or zh-Hant.',
        ],

        'target_language' => [
            'type' => 'string',
            'length' => 35,
            'required' => true,
            'indexed' => true,
            'label' => 'Target Language',
            'description' =>
                'BCP 47 language tag identifying the translated language.',
        ],

        'title' => [
            'type' => 'string',
            'length' => 255,
            'required' => true,
            'searchable' => true,
            'label' => 'Translated Title',
        ],

        'summary' => [
            'type' => 'text',
            'required' => false,
            'searchable' => true,
            'label' => 'Translated Summary',
        ],

        'content' => [
            'type' => 'text',
            'required' => true,
            'searchable' => true,
            'label' => 'Translated Content',
        ],

        'translation_method' => [
            'type' => 'enum',
            'values' => [
                'human',
                'ai',
                'hybrid',
                'community',
                'imported',
            ],
            'default' => 'human',
            'required' => true,
            'indexed' => true,
            'label' => 'Translation Method',
        ],

        'translator_id' => [
            'type' => 'string',
            'length' => 64,
            'required' => true,
            'indexed' => true,
            'label' => 'Translator',
        ],

        'translator_type' => [
            'type' => 'enum',
            'values' => [
                'person',
                'organization',
                'ai',
                'community',
                'unknown',
            ],
            'default' => 'person',
            'required' => true,
            'indexed' => true,
            'label' => 'Translator Type',
        ],

        'translation_provider' => [
            'type' => 'string',
            'length' => 255,
            'required' => false,
            'indexed' => true,
            'label' => 'Translation Provider',
        ],

        'translation_model' => [
            'type' => 'string',
            'length' => 255,
            'required' => false,
            'indexed' => true,
            'label' => 'Translation Model',
        ],

        'translation_prompt_reference' => [
            'type' => 'string',
            'length' => 2048,
            'required' => false,
            'label' => 'Prompt Reference',
        ],

        'confidence' => [
            'type' => 'decimal',
            'precision' => '5,2',
            'required' => false,
            'minimum' => 0,
            'maximum' => 100,
            'label' => 'Translation Confidence',
        ],

        'review_status' => [
            'type' => 'enum',
            'values' => [
                'unreviewed',
                'machine_checked',
                'human_reviewed',
                'community_reviewed',
                'approved',
                'disputed',
                'rejected',
            ],
            'default' => 'unreviewed',
            'required' => true,
            'indexed' => true,
            'label' => 'Review Status',
        ],

        'reviewer_id' => [
            'type' => 'string',
            'length' => 64,
            'required' => false,
            'indexed' => true,
            'label' => 'Reviewer',
        ],

        'review_notes' => [
            'type' => 'text',
            'required' => false,
            'label' => 'Review Notes',
        ],

        'meaning_notes' => [
            'type' => 'text',
            'required' => false,
            'searchable' => true,
            'label' => 'Meaning Notes',
            'description' =>
                'Context, idioms, cultural meaning, ambiguity, or choices '
                . 'that cannot be represented through literal translation.',
        ],

        'terminology_notes' => [
            'type' => 'text',
            'required' => false,
            'searchable' => true,
            'label' => 'Terminology Notes',
        ],

        'regional_context' => [
            'type' => 'string',
            'length' => 255,
            'required' => false,
            'indexed' => true,
            'label' => 'Regional Context',
        ],

        'status' => [
            'type' => 'enum',
            'values' => [
                'draft',
                'proposed',
                'active',
                'verified',
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

        'license' => [
            'type' => 'string',
            'length' => 128,
            'required' => false,
            'indexed' => true,
            'label' => 'License',
        ],

        'source_reference' => [
            'type' => 'string',
            'length' => 2048,
            'required' => false,
            'searchable' => true,
            'label' => 'Source Reference',
        ],

        'checksum' => [
            'type' => 'string',
            'length' => 128,
            'required' => false,
            'indexed' => true,
            'editable' => false,
            'label' => 'Content Checksum',
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

        'reviewed_at' => [
            'type' => 'datetime',
            'required' => false,
            'label' => 'Reviewed',
        ],

        'approved_at' => [
            'type' => 'datetime',
            'required' => false,
            'label' => 'Approved',
        ],

        'archived_at' => [
            'type' => 'datetime',
            'required' => false,
            'label' => 'Archived',
        ],
    ],

    'identity' => [
        'translation_id',
        'entity_type',
        'source_entity_id',
        'source_language',
        'target_language',
        'title',
        'status',
        'version',
    ],

    'provenance' => [
        'source_entity_id',
        'source_entity_type',
        'source_version',
        'source_language',
        'target_language',
        'translator_id',
        'translator_type',
        'translation_method',
        'translation_provider',
        'translation_model',
        'translation_prompt_reference',
        'source_reference',
        'license',
        'checksum',
        'created_at',
        'updated_at',
    ],

    'lifecycle' => [
        'status',
        'review_status',
        'version',
        'created_at',
        'updated_at',
        'reviewed_at',
        'approved_at',
        'archived_at',
    ],

    'governance' => [
        'visibility',
        'confidence',
        'review_status',
        'reviewer_id',
        'locked',
    ],

    'knowledge' => [
        'title',
        'summary',
        'content',
        'meaning_notes',
        'terminology_notes',
        'regional_context',
        'review_notes',
    ],

    'relationship_rules' => [

        'required_relationship' => 'translated_from',

        'inverse_relationship' => 'translated_as',

        'allow_self_relationships' => false,

        'allow_same_language_translation' => false,

        'allow_multiple_translations_per_language' => true,

        'require_source_entity' => true,

        'require_translator_attribution' => true,

        'preserve_source_content' => true,

        'overwrite_source' => false,
    ],

    'validation' => [

        'language_tag_standard' => 'BCP 47',

        'source_and_target_must_differ' => true,

        'content_required' => true,

        'translator_required' => true,

        'ai_model_required_when_method_is_ai' => true,

        'reviewer_required_when_approved' => true,
    ],

    'capabilities' => [
        'identify',
        'attribute',
        'translate',
        'compare',
        'review',
        'approve',
        'dispute',
        'version',
        'relate',
        'search',
        'verify',
        'archive',
        'export',
    ],

    'reporting' => [
        'translations_by_language',
        'translations_by_method',
        'translations_by_status',
        'translations_by_review_status',
        'human_review_rate',
        'translation_confidence',
        'disputed_meanings',
    ],

    'principles' => [
        'A translation never replaces its source.',
        'Every translation has provenance.',
        'Every translator has attribution.',
        'Meaning includes cultural and contextual knowledge.',
        'Machine confidence is evidence, not authority.',
        'Disagreement is preserved rather than erased.',
        'Human review remains visible.',
        'Language expands access to knowledge.',
        'Humanity gives knowledge purpose.',
        'SQ assists.',
        'The Doer decides.',
    ],
];