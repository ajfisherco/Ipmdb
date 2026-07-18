<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/GraphImportService.php
|--------------------------------------------------------------------------
| IPMdb Graph Import Service
|--------------------------------------------------------------------------
|
| Converts external graph payloads into canonical IPMdb entity and
| relationship records without persisting them automatically.
|
| Responsibilities:
| - Import native IPMdb graph arrays.
| - Import JSON strings and JSON files.
| - Import CSV entity and relationship datasets.
| - Import node-edge payloads.
| - Normalize identifiers, types, statuses, metadata, and timestamps.
| - Detect malformed, duplicate, and orphaned records.
| - Preserve source provenance and import diagnostics.
| - Support dry-run inspection before records enter the working graph.
|
| Import reads.
| Validation checks.
| Repository persists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/ValidationService.php';
require_once __DIR__ . '/RelationshipService.php';
require_once __DIR__ . '/ConsistencyService.php';
require_once __DIR__ . '/SimilarityService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class GraphImportService extends Service
{
    use GraphUtilities;

    private ValidationService $validation;

    private RelationshipService $relationships;

    private ConsistencyService $consistency;

    private SimilarityService $similarity;

    /**
     * Supported import formats.
     *
     * @var array<int,string>
     */
    private array $formats = [
        'ipmdb',
        'json',
        'csv_entities',
        'csv_relationships',
        'node_edge',
    ];

    /**
     * Fields commonly used as entity identifiers.
     *
     * @var array<int,string>
     */
    private array $entityIdentifierFields = [
        'entity_id',
        'asset_id',
        'translation_id',
        'document_id',
        'program_id',
        'decision_id',
        'mission_id',
        'organization_id',
        'person_id',
        'url_id',
        'id',
        'uuid',
        'identifier',
    ];

    /**
     * Fields commonly used as relationship identifiers.
     *
     * @var array<int,string>
     */
    private array $relationshipIdentifierFields = [
        'relationship_id',
        'edge_id',
        'id',
        'uuid',
        'identifier',
    ];

    /**
     * Canonical default values.
     *
     * @var array<string,mixed>
     */
    private array $entityDefaults = [
        'entity_type' => 'entity',
        'status' => 'draft',
        'version' => '1.0',
        'language' => 'en',
        'metadata' => [],
        'tags' => [],
    ];

    /**
     * Canonical relationship defaults.
     *
     * @var array<string,mixed>
     */
    private array $relationshipDefaults = [
        'source_type' => 'entity',
        'target_type' => 'entity',
        'relationship_type' => 'related_to',
        'status' => 'proposed',
        'confidence' => 100.0,
        'weight' => 1.0,
        'strength' => 1.0,
        'directional' => true,
        'metadata' => [],
        'tags' => [],
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?ValidationService $validation = null,
        ?RelationshipService $relationships = null,
        ?ConsistencyService $consistency = null,
        ?SimilarityService $similarity = null
    ) {
        parent::__construct($config, $context);

        $this->validation = $validation
            ?? new ValidationService();

        $this->relationships = $relationships
            ?? new RelationshipService();

        $this->consistency = $consistency
            ?? new ConsistencyService();

        $this->similarity = $similarity
            ?? new SimilarityService();

        if (
            isset($config['entity_defaults'])
            && is_array($config['entity_defaults'])
        ) {
            $this->entityDefaults = array_replace(
                $this->entityDefaults,
                $config['entity_defaults']
            );
        }

        if (
            isset($config['relationship_defaults'])
            && is_array($config['relationship_defaults'])
        ) {
            $this->relationshipDefaults = array_replace(
                $this->relationshipDefaults,
                $config['relationship_defaults']
            );
        }
    }

    /**
     * Import one payload.
     *
     * @return array<string,mixed>
     */
    public function import(
        mixed $payload,
        string $format = 'ipmdb',
        array $options = []
    ): array {
        $this->reset();

        $format = $this->normalizeFormat($format);

        $source = trim(
            (string)(
                $options['source']
                ?? 'manual_import'
            )
        );

        $startedAt = microtime(true);

        $parsed = match ($format) {
            'ipmdb' => $this->parseNativePayload(
                $payload
            ),

            'json' => $this->parseJsonPayload(
                $payload
            ),

            'csv_entities' => [
                'entities' => $this->parseCsv(
                    $payload,
                    $options
                ),
                'relationships' => [],
            ],

            'csv_relationships' => [
                'entities' => [],
                'relationships' => $this->parseCsv(
                    $payload,
                    $options
                ),
            ],

            'node_edge' => $this->parseNodeEdgePayload(
                $payload
            ),
        };

        $rawEntities = is_array(
            $parsed['entities']
            ?? null
        )
            ? $parsed['entities']
            : [];

        $rawRelationships = is_array(
            $parsed['relationships']
            ?? null
        )
            ? $parsed['relationships']
            : [];

        $entityImport = $this->normalizeEntities(
            $rawEntities,
            array_merge(
                $options,
                [
                    'source' => $source,
                ]
            )
        );

        $relationshipImport =
            $this->normalizeRelationships(
                $rawRelationships,
                array_merge(
                    $options,
                    [
                        'source' => $source,
                    ]
                )
            );

        $entities = $entityImport['records'];

        $relationships =
            $relationshipImport['records'];

        $duplicateResult =
            $this->deduplicateEntities(
                $entities,
                $options
            );

        $entities =
            $duplicateResult['records'];

        $relationshipDuplicateResult =
            $this->deduplicateRelationships(
                $relationships
            );

        $relationships =
            $relationshipDuplicateResult['records'];

        $orphanResult = $this->inspectOrphans(
            $entities,
            $relationships,
            (bool)(
                $options['allow_external_nodes']
                ?? false
            )
        );

        if (
            (bool)(
                $options['drop_orphan_relationships']
                ?? false
            )
        ) {
            $relationships =
                $orphanResult[
                    'retained_relationships'
                ];
        }

        $consistencyResult =
            $this->consistency->inspect(
                $entities,
                $relationships,
                [
                    'check_cycles' =>
                        (bool)(
                            $options[
                                'check_cycles'
                            ] ?? true
                        ),

                    'check_references' => true,

                    'maximum_cycles' =>
                        (int)(
                            $options[
                                'maximum_cycles'
                            ] ?? 100
                        ),
                ]
            );

        $duration = round(
            microtime(true) - $startedAt,
            6
        );

        $result = [
            'import_id' =>
                $this->generateImportId(),

            'format' => $format,

            'source' => $source,

            'imported_at' => gmdate('c'),

            'duration_seconds' => $duration,

            'dry_run' => (bool)(
                $options['dry_run']
                ?? true
            ),

            'raw_counts' => [
                'entities' =>
                    count($rawEntities),

                'relationships' =>
                    count($rawRelationships),
            ],

            'imported_counts' => [
                'entities' =>
                    count($entities),

                'relationships' =>
                    count($relationships),
            ],

            'skipped_counts' => [
                'entities' =>
                    count(
                        $entityImport['skipped']
                    ),

                'relationships' =>
                    count(
                        $relationshipImport[
                            'skipped'
                        ]
                    ),
            ],

            'duplicate_counts' => [
                'entities' =>
                    count(
                        $duplicateResult[
                            'duplicates'
                        ]
                    ),

                'relationships' =>
                    count(
                        $relationshipDuplicateResult[
                            'duplicates'
                        ]
                    ),
            ],

            'orphan_relationship_count' =>
                count(
                    $orphanResult['orphans']
                ),

            'consistent' =>
                $consistencyResult[
                    'consistent'
                ] ?? false,

            'entities' => $entities,

            'relationships' =>
                $relationships,

            'skipped' => [
                'entities' =>
                    $entityImport['skipped'],

                'relationships' =>
                    $relationshipImport[
                        'skipped'
                    ],
            ],

            'duplicates' => [
                'entities' =>
                    $duplicateResult[
                        'duplicates'
                    ],

                'relationships' =>
                    $relationshipDuplicateResult[
                        'duplicates'
                    ],
            ],

            'orphans' =>
                $orphanResult['orphans'],

            'consistency' =>
                $consistencyResult,

            'summary' => [
                'ready_for_review' =>
                    count(
                        $entityImport['skipped']
                    ) === 0
                    && count(
                        $relationshipImport[
                            'skipped'
                        ]
                    ) === 0,

                'ready_for_persistence' =>
                    (
                        $consistencyResult[
                            'summary'
                        ]['blocking_count']
                        ?? 0
                    ) === 0
                    && (
                        count(
                            $orphanResult[
                                'orphans'
                            ]
                        ) === 0
                        || (
                            $options[
                                'allow_external_nodes'
                            ] ?? false
                        ) === true
                    ),
            ],
        ];

        $this->addMessage(
            'Graph import completed.',
            [
                'format' => $format,

                'entity_count' =>
                    count($entities),

                'relationship_count' =>
                    count($relationships),

                'duration_seconds' =>
                    $duration,
            ]
        );

        return $result;
    }

    /**
     * Import a JSON file from a local path.
     *
     * @return array<string,mixed>
     */
    public function importJsonFile(
        string $path,
        array $options = []
    ): array {
        $path = trim($path);

        if (
            $path === ''
            || !is_file($path)
        ) {
            throw new InvalidArgumentException(
                'JSON import file does not exist.'
            );
        }

        if (!is_readable($path)) {
            throw new RuntimeException(
                'JSON import file is not readable.'
            );
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(
                'Unable to read JSON import file.'
            );
        }

        return $this->import(
            $contents,
            'json',
            array_merge(
                [
                    'source' => $path,
                ],
                $options
            )
        );
    }

    /**
     * Import a CSV file.
     *
     * @return array<string,mixed>
     */
    public function importCsvFile(
        string $path,
        string $recordType = 'entities',
        array $options = []
    ): array {
        $path = trim($path);

        if (
            $path === ''
            || !is_file($path)
        ) {
            throw new InvalidArgumentException(
                'CSV import file does not exist.'
            );
        }

        if (!is_readable($path)) {
            throw new RuntimeException(
                'CSV import file is not readable.'
            );
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(
                'Unable to read CSV import file.'
            );
        }

        $recordType = $this->normalizeMachineKey(
            $recordType
        );

        $format = $recordType === 'relationships'
            ? 'csv_relationships'
            : 'csv_entities';

        return $this->import(
            $contents,
            $format,
            array_merge(
                [
                    'source' => $path,
                ],
                $options
            )
        );
    }

    /**
     * Normalize entity records.
     *
     * @param array<int,mixed> $records
     *
     * @return array<string,mixed>
     */
    public function normalizeEntities(
        array $records,
        array $options = []
    ): array {
        $normalized = [];
        $skipped = [];

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                $skipped[] = [
                    'index' => $index,
                    'reason' =>
                        'entity_record_not_array',
                    'record' => $record,
                ];

                continue;
            }

            try {
                $entity =
                    $this->normalizeEntity(
                        $record,
                        $options
                    );

                $normalized[] = $entity;
            } catch (Throwable $exception) {
                $skipped[] = [
                    'index' => $index,

                    'reason' =>
                        $exception->getMessage(),

                    'record' => $record,
                ];
            }
        }

        return [
            'records' => $normalized,

            'skipped' => $skipped,
        ];
    }

    /**
     * Normalize one entity.
     *
     * @param array<string,mixed> $record
     *
     * @return array<string,mixed>
     */
    public function normalizeEntity(
        array $record,
        array $options = []
    ): array {
        $record = $this->decodeJsonFields(
            $record,
            [
                'metadata',
                'tags',
                'keywords',
                'categories',
                'classifications',
            ]
        );

        $identifier =
            $this->resolveIdentifier(
                $record,
                $this->entityIdentifierFields
            );

        if ($identifier === '') {
            if (
                (bool)(
                    $options[
                        'generate_missing_identifiers'
                    ] ?? true
                )
            ) {
                $identifier =
                    $this->generateEntityId(
                        $record
                    );
            } else {
                throw new InvalidArgumentException(
                    'Entity identifier is missing.'
                );
            }
        }

        $entityType =
            $this->normalizeMachineKey(
                (string)(
                    $record['entity_type']
                    ?? $record['type']
                    ?? $options[
                        'default_entity_type'
                    ]
                    ?? $this->entityDefaults[
                        'entity_type'
                    ]
                )
            );

        if ($entityType === '') {
            $entityType = 'entity';
        }

        $createdAt = $this->normalizeDate(
            $record['created_at']
                ?? null
        );

        $updatedAt = $this->normalizeDate(
            $record['updated_at']
                ?? null
        );

        $metadata = is_array(
            $record['metadata']
                ?? null
        )
            ? $record['metadata']
            : [];

        $metadata['import'] = array_merge(
            is_array(
                $metadata['import']
                ?? null
            )
                ? $metadata['import']
                : [],
            [
                'source' =>
                    $options['source']
                    ?? 'manual_import',

                'imported_at' =>
                    gmdate('c'),

                'original_identifier' =>
                    $this->resolveIdentifier(
                        $record,
                        $this->entityIdentifierFields
                    ),
            ]
        );

        $entity = array_replace(
            $this->entityDefaults,
            $record,
            [
                'entity_id' => $identifier,

                'entity_type' =>
                    $entityType,

                'status' =>
                    $this->normalizeMachineKey(
                        (string)(
                            $record['status']
                            ?? $this->entityDefaults[
                                'status'
                            ]
                        )
                    ),

                'version' => trim(
                    (string)(
                        $record['version']
                        ?? $this->entityDefaults[
                            'version'
                        ]
                    )
                ),

                'language' =>
                    $this->normalizeLanguage(
                        (string)(
                            $record['language']
                            ?? $this->entityDefaults[
                                'language'
                            ]
                        )
                    ),

                'tags' =>
                    $this->normalizeStringList(
                        $record['tags']
                            ?? []
                    ),

                'keywords' =>
                    $this->normalizeStringList(
                        $record['keywords']
                            ?? []
                    ),

                'metadata' => $metadata,

                'created_at' =>
                    $createdAt
                    ?? gmdate('c'),

                'updated_at' =>
                    $updatedAt
                    ?? $createdAt
                    ?? gmdate('c'),
            ]
        );

        $entity['checksum'] =
            $this->recordChecksum($entity);

        return $entity;
    }

    /**
     * Normalize relationship records.
     *
     * @param array<int,mixed> $records
     *
     * @return array<string,mixed>
     */
    public function normalizeRelationships(
        array $records,
        array $options = []
    ): array {
        $normalized = [];
        $skipped = [];

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                $skipped[] = [
                    'index' => $index,

                    'reason' =>
                        'relationship_record_not_array',

                    'record' => $record,
                ];

                continue;
            }

            try {
                $relationship =
                    $this->normalizeRelationship(
                        $record,
                        $options
                    );

                $normalized[] =
                    $relationship;
            } catch (Throwable $exception) {
                $skipped[] = [
                    'index' => $index,

                    'reason' =>
                        $exception->getMessage(),

                    'record' => $record,
                ];
            }
        }

        return [
            'records' => $normalized,

            'skipped' => $skipped,
        ];
    }

    /**
     * Normalize one relationship.
     *
     * @param array<string,mixed> $record
     *
     * @return array<string,mixed>
     */
    public function normalizeRelationship(
        array $record,
        array $options = []
    ): array {
        $record = $this->decodeJsonFields(
            $record,
            [
                'metadata',
                'tags',
            ]
        );

        $sourceId = trim(
            (string)(
                $record['source_id']
                ?? $record['from']
                ?? $record['source']
                ?? ''
            )
        );

        $targetId = trim(
            (string)(
                $record['target_id']
                ?? $record['to']
                ?? $record['target']
                ?? ''
            )
        );

        if ($sourceId === '' || $targetId === '') {
            throw new InvalidArgumentException(
                'Relationship source and target identifiers are required.'
            );
        }

        $sourceType =
            $this->normalizeMachineKey(
                (string)(
                    $record['source_type']
                    ?? $record[
                        'from_type'
                    ]
                    ?? $this->relationshipDefaults[
                        'source_type'
                    ]
                )
            );

        $targetType =
            $this->normalizeMachineKey(
                (string)(
                    $record['target_type']
                    ?? $record[
                        'to_type'
                    ]
                    ?? $this->relationshipDefaults[
                        'target_type'
                    ]
                )
            );

        $relationshipType =
            $this->normalizeMachineKey(
                (string)(
                    $record[
                        'relationship_type'
                    ]
                    ?? $record['edge_type']
                    ?? $record['predicate']
                    ?? $record['relation']
                    ?? $this->relationshipDefaults[
                        'relationship_type'
                    ]
                )
            );

        $relationshipId =
            $this->resolveIdentifier(
                $record,
                $this->relationshipIdentifierFields
            );

        if ($relationshipId === '') {
            $relationshipId =
                $this->generateRelationshipImportId(
                    $sourceType,
                    $sourceId,
                    $relationshipType,
                    $targetType,
                    $targetId
                );
        }

        $metadata = is_array(
            $record['metadata']
                ?? null
        )
            ? $record['metadata']
            : [];

        $metadata['import'] = array_merge(
            is_array(
                $metadata['import']
                ?? null
            )
                ? $metadata['import']
                : [],
            [
                'source' =>
                    $options['source']
                    ?? 'manual_import',

                'imported_at' =>
                    gmdate('c'),
            ]
        );

        $input = array_replace(
            $this->relationshipDefaults,
            $record,
            [
                'relationship_id' =>
                    $relationshipId,

                'source_id' => $sourceId,

                'source_type' =>
                    $sourceType !== ''
                        ? $sourceType
                        : 'entity',

                'target_id' => $targetId,

                'target_type' =>
                    $targetType !== ''
                        ? $targetType
                        : 'entity',

                'relationship_type' =>
                    $relationshipType !== ''
                        ? $relationshipType
                        : 'related_to',

                'status' =>
                    $this->normalizeMachineKey(
                        (string)(
                            $record['status']
                            ?? $this->relationshipDefaults[
                                'status'
                            ]
                        )
                    ),

                'confidence' =>
                    $this->clamp(
                        (float)(
                            $record['confidence']
                            ?? $this->relationshipDefaults[
                                'confidence'
                            ]
                        ),
                        0.0,
                        100.0
                    ),

                'weight' =>
                    $this->clamp(
                        (float)(
                            $record['weight']
                            ?? $this->relationshipDefaults[
                                'weight'
                            ]
                        ),
                        0.0,
                        1.0
                    ),

                'strength' =>
                    $this->clamp(
                        (float)(
                            $record['strength']
                            ?? $this->relationshipDefaults[
                                'strength'
                            ]
                        ),
                        0.0,
                        1.0
                    ),

                'directional' =>
                    $this->normalizeBoolean(
                        $record['directional']
                            ?? true
                    ),

                'tags' =>
                    $this->normalizeStringList(
                        $record['tags']
                            ?? []
                    ),

                'metadata' => $metadata,

                'created_by' => trim(
                    (string)(
                        $record['created_by']
                        ?? $options[
                            'created_by'
                        ]
                        ?? 'import'
                    )
                ),

                'created_at' =>
                    $this->normalizeDate(
                        $record['created_at']
                            ?? null
                    )
                    ?? gmdate('c'),

                'updated_at' =>
                    $this->normalizeDate(
                        $record['updated_at']
                            ?? null
                    )
                    ?? gmdate('c'),
            ]
        );

        $input['checksum'] =
            $this->recordChecksum($input);

        return $input;
    }

    /**
     * Deduplicate entities.
     *
     * @param array<int,array<string,mixed>> $entities
     *
     * @return array<string,mixed>
     */
    public function deduplicateEntities(
        array $entities,
        array $options = []
    ): array {
        $records = [];
        $duplicates = [];
        $identityIndex = [];
        $checksumIndex = [];

        foreach ($entities as $index => $entity) {
            $key = $this->entityKey($entity);

            if ($key === '') {
                continue;
            }

            $checksum = trim(
                (string)(
                    $entity['checksum']
                    ?? ''
                )
            );

            if (isset($identityIndex[$key])) {
                $existingIndex =
                    $identityIndex[$key];

                $existing =
                    $records[$existingIndex];

                $winner = $this->selectPreferredRecord(
                    $existing,
                    $entity,
                    $options
                );

                $records[$existingIndex] =
                    $winner;

                $duplicates[] = [
                    'type' =>
                        'duplicate_entity_identifier',

                    'entity_key' => $key,

                    'first_index' =>
                        $existingIndex,

                    'duplicate_index' =>
                        $index,

                    'selected_checksum' =>
                        $winner['checksum']
                        ?? null,
                ];

                continue;
            }

            if (
                $checksum !== ''
                && isset(
                    $checksumIndex[$checksum]
                )
            ) {
                $duplicates[] = [
                    'type' =>
                        'duplicate_entity_checksum',

                    'entity_key' => $key,

                    'checksum' => $checksum,

                    'first_index' =>
                        $checksumIndex[
                            $checksum
                        ],

                    'duplicate_index' =>
                        $index,
                ];

                if (
                    (bool)(
                        $options[
                            'drop_checksum_duplicates'
                        ] ?? true
                    )
                ) {
                    continue;
                }
            }

            $identityIndex[$key] =
                count($records);

            if ($checksum !== '') {
                $checksumIndex[$checksum] =
                    count($records);
            }

            $records[] = $entity;
        }

        return [
            'records' =>
                array_values($records),

            'duplicates' =>
                $duplicates,
        ];
    }

    /**
     * Deduplicate relationships.
     *
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    public function deduplicateRelationships(
        array $relationships
    ): array {
        $records = [];
        $duplicates = [];
        $identifierIndex = [];
        $edgeIndex = [];

        foreach (
            $relationships
            as $index => $relationship
        ) {
            $relationshipId = trim(
                (string)(
                    $relationship[
                        'relationship_id'
                    ]
                    ?? ''
                )
            );

            $edgeKey = $this->edgeKey(
                $relationship
            );

            if (
                $relationshipId !== ''
                && isset(
                    $identifierIndex[
                        $relationshipId
                    ]
                )
            ) {
                $duplicates[] = [
                    'type' =>
                        'duplicate_relationship_identifier',

                    'relationship_id' =>
                        $relationshipId,

                    'first_index' =>
                        $identifierIndex[
                            $relationshipId
                        ],

                    'duplicate_index' =>
                        $index,
                ];

                continue;
            }

            if (
                $edgeKey !== ''
                && isset($edgeIndex[$edgeKey])
            ) {
                $duplicates[] = [
                    'type' =>
                        'duplicate_relationship_edge',

                    'edge_key' => $edgeKey,

                    'first_index' =>
                        $edgeIndex[$edgeKey],

                    'duplicate_index' =>
                        $index,
                ];

                continue;
            }

            if ($relationshipId !== '') {
                $identifierIndex[
                    $relationshipId
                ] = count($records);
            }

            if ($edgeKey !== '') {
                $edgeIndex[$edgeKey] =
                    count($records);
            }

            $records[] = $relationship;
        }

        return [
            'records' =>
                array_values($records),

            'duplicates' =>
                $duplicates,
        ];
    }

    /**
     * Inspect relationships referencing missing entities.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    public function inspectOrphans(
        array $entities,
        array $relationships,
        bool $allowExternalNodes = false
    ): array {
        $entityKeys = [];

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $key = $this->entityKey($entity);

            if ($key !== '') {
                $entityKeys[$key] = true;
            }
        }

        $orphans = [];
        $retained = [];

        foreach (
            $relationships
            as $index => $relationship
        ) {
            if (!is_array($relationship)) {
                continue;
            }

            $sourceKey = $this->graphNodeKey(
                (string)(
                    $relationship[
                        'source_type'
                    ] ?? 'entity'
                ),
                (string)(
                    $relationship[
                        'source_id'
                    ] ?? ''
                )
            );

            $targetKey = $this->graphNodeKey(
                (string)(
                    $relationship[
                        'target_type'
                    ] ?? 'entity'
                ),
                (string)(
                    $relationship[
                        'target_id'
                    ] ?? ''
                )
            );

            $missingSource =
                !isset($entityKeys[$sourceKey]);

            $missingTarget =
                !isset($entityKeys[$targetKey]);

            if (
                $missingSource
                || $missingTarget
            ) {
                $orphans[] = [
                    'index' => $index,

                    'relationship_id' =>
                        $relationship[
                            'relationship_id'
                        ] ?? null,

                    'source_key' =>
                        $sourceKey,

                    'target_key' =>
                        $targetKey,

                    'missing_source' =>
                        $missingSource,

                    'missing_target' =>
                        $missingTarget,

                    'allowed' =>
                        $allowExternalNodes,
                ];

                if (!$allowExternalNodes) {
                    continue;
                }
            }

            $retained[] = $relationship;
        }

        return [
            'orphans' => $orphans,

            'retained_relationships' =>
                $retained,
        ];
    }

    /**
     * Return service diagnostics.
     *
     * @return array<string,mixed>
     */
    public function diagnostics(): array
    {
        return array_merge(
            parent::diagnostics(),
            [
                'formats' =>
                    $this->formats,

                'entity_identifier_fields' =>
                    $this->entityIdentifierFields,

                'relationship_identifier_fields' =>
                    $this->relationshipIdentifierFields,

                'entity_defaults' =>
                    $this->entityDefaults,

                'relationship_defaults' =>
                    $this->relationshipDefaults,

                'automatic_persistence' =>
                    false,

                'dry_run_supported' =>
                    true,

                'duplicate_detection' =>
                    true,

                'orphan_detection' =>
                    true,

                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Parse native graph payload.
     *
     * @return array<string,array<int,mixed>>
     */
    private function parseNativePayload(
        mixed $payload
    ): array {
        if (!is_array($payload)) {
            throw new InvalidArgumentException(
                'Native graph payload must be an array.'
            );
        }

        if (
            array_is_list($payload)
        ) {
            return [
                'entities' => $payload,
                'relationships' => [],
            ];
        }

        return [
            'entities' => is_array(
                $payload['entities']
                ?? null
            )
                ? $payload['entities']
                : [],

            'relationships' => is_array(
                $payload['relationships']
                ?? null
            )
                ? $payload['relationships']
                : [],
        ];
    }

    /**
     * Parse JSON payload.
     *
     * @return array<string,array<int,mixed>>
     */
    private function parseJsonPayload(
        mixed $payload
    ): array {
        if (is_array($payload)) {
            return $this->parseNativePayload(
                $payload
            );
        }

        if (!is_string($payload)) {
            throw new InvalidArgumentException(
                'JSON payload must be a JSON string or array.'
            );
        }

        try {
            $decoded = json_decode(
                $payload,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Invalid JSON payload: '
                . $exception->getMessage()
            );
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException(
                'Decoded JSON graph must be an array.'
            );
        }

        if (
            isset($decoded['nodes'])
            || isset($decoded['edges'])
        ) {
            return $this->parseNodeEdgePayload(
                $decoded
            );
        }

        return $this->parseNativePayload(
            $decoded
        );
    }

    /**
     * Parse node-edge payload.
     *
     * @return array<string,array<int,mixed>>
     */
    private function parseNodeEdgePayload(
        mixed $payload
    ): array {
        if (!is_array($payload)) {
            throw new InvalidArgumentException(
                'Node-edge payload must be an array.'
            );
        }

        $nodes = is_array(
            $payload['nodes']
            ?? null
        )
            ? $payload['nodes']
            : [];

        $edges = is_array(
            $payload['edges']
            ?? null
        )
            ? $payload['edges']
            : [];

        $entities = [];

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $properties = is_array(
                $node['properties']
                ?? null
            )
                ? $node['properties']
                : [];

            $entities[] = array_merge(
                $properties,
                [
                    'entity_id' =>
                        $node['id']
                        ?? $node[
                            'entity_id'
                        ] ?? null,

                    'entity_type' =>
                        $node['type']
                        ?? $node['label']
                        ?? $node[
                            'entity_type'
                        ] ?? 'entity',
                ]
            );
        }

        $relationships = [];

        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }

            $properties = is_array(
                $edge['properties']
                ?? null
            )
                ? $edge['properties']
                : [];

            $relationships[] = array_merge(
                $properties,
                [
                    'relationship_id' =>
                        $edge['id']
                        ?? $edge[
                            'relationship_id'
                        ] ?? null,

                    'source_id' =>
                        $edge['source']
                        ?? $edge['from']
                        ?? $edge[
                            'source_id'
                        ] ?? null,

                    'source_type' =>
                        $edge[
                            'source_type'
                        ] ?? 'entity',

                    'target_id' =>
                        $edge['target']
                        ?? $edge['to']
                        ?? $edge[
                            'target_id'
                        ] ?? null,

                    'target_type' =>
                        $edge[
                            'target_type'
                        ] ?? 'entity',

                    'relationship_type' =>
                        $edge['type']
                        ?? $edge['label']
                        ?? $edge[
                            'relationship_type'
                        ] ?? 'related_to',
                ]
            );
        }

        return [
            'entities' => $entities,

            'relationships' =>
                $relationships,
        ];
    }

    /**
     * Parse CSV text.
     *
     * @return array<int,array<string,mixed>>
     */
    private function parseCsv(
        mixed $payload,
        array $options
    ): array {
        if (!is_string($payload)) {
            throw new InvalidArgumentException(
                'CSV payload must be a string.'
            );
        }

        $delimiter = (string)(
            $options['delimiter']
            ?? ','
        );

        $enclosure = (string)(
            $options['enclosure']
            ?? '"'
        );

        $escape = (string)(
            $options['escape']
            ?? '\\'
        );

        $stream = fopen(
            'php://temp',
            'r+'
        );

        if ($stream === false) {
            throw new RuntimeException(
                'Unable to create CSV parsing stream.'
            );
        }

        fwrite($stream, $payload);
        rewind($stream);

        $headers = fgetcsv(
            $stream,
            0,
            $delimiter,
            $enclosure,
            $escape
        );

        if (
            $headers === false
            || $headers === [null]
        ) {
            fclose($stream);

            return [];
        }

        $headers = array_map(
            fn (mixed $header): string =>
                $this->normalizeMachineKey(
                    (string)$header
                ),
            $headers
        );

        $records = [];

        while (
            (
                $row = fgetcsv(
                    $stream,
                    0,
                    $delimiter,
                    $enclosure,
                    $escape
                )
            ) !== false
        ) {
            if (
                $row === [null]
                || $row === []
            ) {
                continue;
            }

            $record = [];

            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }

                $record[$header] =
                    $row[$index]
                    ?? null;
            }

            $records[] = $record;
        }

        fclose($stream);

        return $records;
    }

    /**
     * Decode selected JSON-looking fields.
     *
     * @param array<string,mixed> $record
     * @param array<int,string> $fields
     *
     * @return array<string,mixed>
     */
    private function decodeJsonFields(
        array $record,
        array $fields
    ): array {
        foreach ($fields as $field) {
            $value = $record[$field]
                ?? null;

            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);

            if (
                $trimmed === ''
                || (
                    !str_starts_with(
                        $trimmed,
                        '['
                    )
                    && !str_starts_with(
                        $trimmed,
                        '{'
                    )
                )
            ) {
                continue;
            }

            try {
                $decoded = json_decode(
                    $trimmed,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                $record[$field] = $decoded;
            } catch (JsonException) {
                continue;
            }
        }

        return $record;
    }

    /**
     * Resolve first populated identifier.
     *
     * @param array<string,mixed> $record
     * @param array<int,string> $fields
     */
    private function resolveIdentifier(
        array $record,
        array $fields
    ): string {
        foreach ($fields as $field) {
            $value = trim(
                (string)(
                    $record[$field]
                    ?? ''
                )
            );

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Build entity graph key.
     *
     * @param array<string,mixed> $entity
     */
    private function entityKey(
        array $entity
    ): string {
        return $this->graphNodeKey(
            (string)(
                $entity['entity_type']
                ?? 'entity'
            ),
            (string)(
                $entity['entity_id']
                ?? ''
            )
        );
    }

    /**
     * Build relationship edge key.
     *
     * @param array<string,mixed> $relationship
     */
    private function edgeKey(
        array $relationship
    ): string {
        return implode(
            '|',
            [
                $this->normalizeMachineKey(
                    (string)(
                        $relationship[
                            'source_type'
                        ] ?? 'entity'
                    )
                ),

                trim(
                    (string)(
                        $relationship[
                            'source_id'
                        ] ?? ''
                    )
                ),

                $this->normalizeMachineKey(
                    (string)(
                        $relationship[
                            'relationship_type'
                        ] ?? 'related_to'
                    )
                ),

                $this->normalizeMachineKey(
                    (string)(
                        $relationship[
                            'target_type'
                        ] ?? 'entity'
                    )
                ),

                trim(
                    (string)(
                        $relationship[
                            'target_id'
                        ] ?? ''
                    )
                ),
            ]
        );
    }

    /**
     * Select preferred duplicate record.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     *
     * @return array<string,mixed>
     */
    private function selectPreferredRecord(
        array $left,
        array $right,
        array $options
    ): array {
        $strategy = $this->normalizeMachineKey(
            (string)(
                $options[
                    'duplicate_strategy'
                ] ?? 'most_complete'
            )
        );

        if ($strategy === 'latest') {
            $leftTime = strtotime(
                (string)(
                    $left['updated_at']
                    ?? $left['created_at']
                    ?? ''
                )
            ) ?: 0;

            $rightTime = strtotime(
                (string)(
                    $right['updated_at']
                    ?? $right['created_at']
                    ?? ''
                )
            ) ?: 0;

            return $rightTime > $leftTime
                ? $right
                : $left;
        }

        if ($strategy === 'first') {
            return $left;
        }

        if ($strategy === 'last') {
            return $right;
        }

        return $this->recordCompleteness(
            $right
        ) > $this->recordCompleteness(
            $left
        )
            ? $right
            : $left;
    }

    /**
     * Calculate record completeness.
     *
     * @param array<string,mixed> $record
     */
    private function recordCompleteness(
        array $record
    ): float {
        if ($record === []) {
            return 0.0;
        }

        $populated = 0;

        foreach ($record as $value) {
            if (!$this->valueIsEmpty($value)) {
                $populated++;
            }
        }

        return $populated / count($record);
    }

    /**
     * Normalize import format.
     */
    private function normalizeFormat(
        string $format
    ): string {
        $format = $this->normalizeMachineKey(
            $format
        );

        if (
            !in_array(
                $format,
                $this->formats,
                true
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported graph import format "%s".',
                    $format
                )
            );
        }

        return $format;
    }

    /**
     * Normalize date.
     */
    private function normalizeDate(
        mixed $value
    ): ?string {
        if (
            $value === null
            || trim((string)$value) === ''
        ) {
            return null;
        }

        $timestamp = strtotime(
            (string)$value
        );

        if ($timestamp === false) {
            return null;
        }

        return gmdate('c', $timestamp);
    }

    /**
     * Normalize language code.
     */
    private function normalizeLanguage(
        string $language
    ): string {
        $language = strtolower(
            trim($language)
        );

        $language = str_replace(
            '_',
            '-',
            $language
        );

        return preg_replace(
            '/[^a-z0-9-]+/',
            '',
            $language
        ) ?: 'en';
    }

    /**
     * Normalize boolean value.
     */
    private function normalizeBoolean(
        mixed $value
    ): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value !== 0;
        }

        return in_array(
            strtolower(
                trim((string)$value)
            ),
            [
                '1',
                'true',
                'yes',
                'on',
                'y',
            ],
            true
        );
    }

    /**
     * Normalize string list.
     *
     * @return array<int,string>
     */
    private function normalizeStringList(
        mixed $values
    ): array {
        if (is_string($values)) {
            $trimmed = trim($values);

            if (
                str_starts_with(
                    $trimmed,
                    '['
                )
            ) {
                try {
                    $decoded = json_decode(
                        $trimmed,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );

                    $values = is_array($decoded)
                        ? $decoded
                        : [];
                } catch (JsonException) {
                    $values = preg_split(
                        '/[\r\n,;]+/',
                        $values
                    ) ?: [];
                }
            } else {
                $values = preg_split(
                    '/[\r\n,;]+/',
                    $values
                ) ?: [];
            }
        }

        if (!is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            $value = trim(
                (string)$value
            );

            if ($value !== '') {
                $normalized[$value] =
                    $value;
            }
        }

        return array_values($normalized);
    }

    /**
     * Normalize machine key.
     */
    private function normalizeMachineKey(
        string $value
    ): string {
        $value = strtolower(
            trim($value)
        );

        return trim(
            preg_replace(
                '/[^a-z0-9_]+/',
                '_',
                $value
            ) ?? '',
            '_'
        );
    }

    /**
     * Calculate canonical record checksum.
     *
     * @param array<string,mixed> $record
     */
    private function recordChecksum(
        array $record
    ): string {
        $copy = $record;

        unset($copy['checksum']);

        $json = json_encode(
            $this->normalizeForHash($copy),
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
        );

        if ($json === false) {
            throw new RuntimeException(
                'Unable to calculate imported record checksum.'
            );
        }

        return hash('sha256', $json);
    }

    /**
     * Generate entity ID.
     *
     * @param array<string,mixed> $record
     */
    private function generateEntityId(
        array $record
    ): string {
        $title = trim(
            (string)(
                $record['title']
                ?? $record['name']
                ?? $record['label']
                ?? $record['idea']
                ?? ''
            )
        );

        $hash = strtoupper(
            substr(
                hash(
                    'sha256',
                    json_encode(
                        $this->normalizeForHash(
                            $record
                        ),
                        JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                    ) ?: uniqid('', true)
                ),
                0,
                12
            )
        );

        $prefix = $title !== ''
            ? strtoupper(
                substr(
                    preg_replace(
                        '/[^A-Za-z0-9]+/',
                        '',
                        $title
                    ) ?: 'ENT',
                    0,
                    3
                )
            )
            : 'ENT';

        return $prefix
            . '-'
            . gmdate('Ymd')
            . '-'
            . $hash;
    }

    /**
     * Generate relationship import ID.
     */
    private function generateRelationshipImportId(
        string $sourceType,
        string $sourceId,
        string $relationshipType,
        string $targetType,
        string $targetId
    ): string {
        $hash = strtoupper(
            substr(
                hash(
                    'sha256',
                    implode(
                        '|',
                        [
                            $sourceType,
                            $sourceId,
                            $relationshipType,
                            $targetType,
                            $targetId,
                        ]
                    )
                ),
                0,
                16
            )
        );

        return 'REL-IMP-' . $hash;
    }

    /**
     * Generate import identifier.
     */
    private function generateImportId(): string
    {
        return 'GIM-'
            . gmdate('Ymd-His')
            . '-'
            . $this->randomToken(6);
    }

    /**
     * Generate random token.
     */
    private function randomToken(
        int $bytes
    ): string {
        try {
            return strtoupper(
                bin2hex(
                    random_bytes($bytes)
                )
            );
        } catch (Throwable) {
            return strtoupper(
                substr(
                    hash(
                        'sha256',
                        uniqid('', true)
                        . microtime(true)
                    ),
                    0,
                    $bytes * 2
                )
            );
        }
    }

    /**
     * Determine operational emptiness.
     */
    private function valueIsEmpty(
        mixed $value
    ): bool {
        return $value === null
            || $value === ''
            || (
                is_array($value)
                && $value === []
            );
    }

    /**
     * Clamp numeric value.
     */
    private function clamp(
        float $value,
        float $minimum,
        float $maximum
    ): float {
        return max(
            $minimum,
            min(
                $maximum,
                $value
            )
        );
    }
}