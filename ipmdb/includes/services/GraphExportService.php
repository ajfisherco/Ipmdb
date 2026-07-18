<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/GraphExportService.php
|--------------------------------------------------------------------------
| IPMdb Graph Export Service
|--------------------------------------------------------------------------
|
| Serializes IPMdb entities, relationships, graph subsets, intelligence
| results, recommendations, inferences, and supporting metadata.
|
| Responsibilities:
| - Export complete graphs and filtered subgraphs.
| - Export JSON, NDJSON, CSV, edge-list, adjacency-list, GraphML, GEXF,
|   Cytoscape, D3, Neo4j, Mermaid, and PlantUML representations.
| - Preserve provenance, versions, translations, and attribution.
| - Apply public, private, minimal, archival, and diagnostic profiles.
| - Support deterministic ordering and field selection.
| - Produce export manifests, checksums, and statistics.
| - Return compression-ready content without performing compression.
|
| Repository stores.
| Import normalizes.
| KnowledgeGraph coordinates.
| Export serializes.
|
| This service performs no database operations.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class GraphExportService extends Service
{
    use GraphUtilities;

    /**
     * Supported export formats.
     *
     * @var array<int,string>
     */
    private array $formats = [
        'ipmdb',
        'json',
        'ndjson',
        'csv_entities',
        'csv_relationships',
        'csv_bundle',
        'edge_list',
        'adjacency_list',
        'graphml',
        'gexf',
        'cytoscape',
        'd3',
        'neo4j',
        'mermaid',
        'plantuml',
    ];

    /**
     * Supported export profiles.
     *
     * @var array<int,string>
     */
    private array $profiles = [
        'full',
        'public',
        'private',
        'minimal',
        'archival',
        'diagnostic',
    ];

    /**
     * Fields removed from public exports by default.
     *
     * @var array<int,string>
     */
    private array $publicExcludedFields = [
        'email',
        'originator_email',
        'contributor_email',
        'translator_email',
        'owner_email',
        'created_ip',
        'updated_ip',
        'ip_address',
        'session_id',
        'user_agent',
        'access_token',
        'refresh_token',
        'api_key',
        'secret',
        'password',
        'password_hash',
        'private_notes',
        'internal_notes',
        'moderation_notes',
        'admin_notes',
        'billing_reference',
        'payment_reference',
    ];

    /**
     * Fields included by the minimal profile.
     *
     * @var array<int,string>
     */
    private array $minimalEntityFields = [
        'entity_id',
        'entity_type',
        'title',
        'name',
        'label',
        'status',
        'version',
        'language',
        'created_at',
        'updated_at',
    ];

    /**
     * Relationship fields included by the minimal profile.
     *
     * @var array<int,string>
     */
    private array $minimalRelationshipFields = [
        'relationship_id',
        'source_id',
        'source_type',
        'target_id',
        'target_type',
        'relationship_type',
        'status',
        'confidence',
        'weight',
        'strength',
        'created_at',
        'updated_at',
    ];

    /**
     * Fields commonly excluded unless diagnostics are requested.
     *
     * @var array<int,string>
     */
    private array $diagnosticFields = [
        'checksum',
        'content_hash',
        'source_hash',
        'file_hash',
        'validation_errors',
        'validation_warnings',
        'processing_flags',
        'analytics_pending',
        'search_vector',
        'embedding',
        'embedding_model',
        'internal_id',
        'database_id',
        'row_id',
    ];

    /**
     * Default export options.
     *
     * @var array<string,mixed>
     */
    private array $defaults = [
        'format' => 'json',
        'profile' => 'full',
        'pretty' => true,
        'deterministic' => true,
        'include_entities' => true,
        'include_relationships' => true,
        'include_manifest' => true,
        'include_statistics' => true,
        'include_checksum' => true,
        'include_metadata' => true,
        'include_provenance' => true,
        'include_versions' => true,
        'include_translations' => true,
        'include_empty_values' => false,
        'include_null_values' => false,
        'include_private_fields' => false,
        'include_diagnostics' => false,
        'include_orphan_relationships' => true,
        'include_expired_relationships' => true,
        'normalize_dates' => true,
        'sort_keys' => true,
        'line_ending' => "\n",
        'csv_delimiter' => ',',
        'csv_enclosure' => '"',
        'csv_escape' => '\\',
        'xml_indent' => '  ',
        'maximum_records' => 1000000,
    ];

    /**
     * @var array<string,mixed>
     */
    private array $lastManifest = [];

    public function __construct(
        array $config = [],
        array $context = []
    ) {
        parent::__construct($config, $context);

        if (
            isset($config['defaults'])
            && is_array($config['defaults'])
        ) {
            $this->defaults = array_replace(
                $this->defaults,
                $config['defaults']
            );
        }

        if (
            isset($config['public_excluded_fields'])
            && is_array(
                $config['public_excluded_fields']
            )
        ) {
            $this->publicExcludedFields =
                $this->normalizeStringList(
                    array_merge(
                        $this->publicExcludedFields,
                        $config[
                            'public_excluded_fields'
                        ]
                    )
                );
        }
    }

    /**
     * Export a graph or graph-derived payload.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    public function export(
        array $entities = [],
        array $relationships = [],
        array $options = []
    ): array {
        $this->reset();

        $startedAt = microtime(true);

        $options = $this->normalizeOptions(
            $options
        );

        $format = $options['format'];
        $profile = $options['profile'];

        $filtered = $this->prepareGraph(
            $entities,
            $relationships,
            $options
        );

        $preparedEntities =
            $filtered['entities'];

        $preparedRelationships =
            $filtered['relationships'];

        $statistics = $this->buildStatistics(
            $preparedEntities,
            $preparedRelationships
        );

        $manifest = $this->buildManifest(
            $preparedEntities,
            $preparedRelationships,
            $format,
            $profile,
            $options,
            $statistics
        );

        $serialized = $this->serialize(
            $preparedEntities,
            $preparedRelationships,
            $format,
            $options,
            $manifest,
            $statistics
        );

        $content = (string)(
            $serialized['content']
            ?? ''
        );

        $checksum = $options[
            'include_checksum'
        ]
            ? hash('sha256', $content)
            : null;

        $duration = round(
            microtime(true) - $startedAt,
            6
        );

        $manifest['content_checksum'] =
            $checksum;

        $manifest['content_bytes'] =
            strlen($content);

        $manifest['duration_seconds'] =
            $duration;

        $this->lastManifest = $manifest;

        $result = [
            'export_id' =>
                $manifest['export_id'],

            'generated_at' =>
                $manifest['generated_at'],

            'format' => $format,

            'profile' => $profile,

            'mime_type' =>
                $serialized['mime_type']
                ?? $this->mimeType($format),

            'extension' =>
                $serialized['extension']
                ?? $this->extension($format),

            'filename' =>
                $this->buildFilename(
                    $format,
                    $options
                ),

            'content' => $content,

            'content_bytes' =>
                strlen($content),

            'checksum_algorithm' =>
                $checksum !== null
                    ? 'sha256'
                    : null,

            'checksum' => $checksum,

            'manifest' =>
                $options['include_manifest']
                    ? $manifest
                    : null,

            'statistics' =>
                $options[
                    'include_statistics'
                ]
                    ? $statistics
                    : null,

            'entity_count' =>
                count($preparedEntities),

            'relationship_count' =>
                count(
                    $preparedRelationships
                ),

            'compression_ready' => true,

            'compressed' => false,

            'duration_seconds' =>
                $duration,
        ];

        $this->addMessage(
            'Graph export completed.',
            [
                'format' => $format,

                'profile' => $profile,

                'entity_count' =>
                    count($preparedEntities),

                'relationship_count' =>
                    count(
                        $preparedRelationships
                    ),

                'content_bytes' =>
                    strlen($content),

                'duration_seconds' =>
                    $duration,
            ]
        );

        return $result;
    }

    /**
     * Export an arbitrary result payload.
     *
     * Useful for search, inference, recommendation, analytics, consistency,
     * and other service responses.
     *
     * @return array<string,mixed>
     */
    public function exportPayload(
        array $payload,
        string $format = 'json',
        array $options = []
    ): array {
        $this->reset();

        $format = $this->normalizeFormat(
            $format
        );

        $options = $this->normalizeOptions(
            array_merge(
                $options,
                [
                    'format' => $format,
                ]
            )
        );

        $profiledPayload =
            $this->applyProfileToValue(
                $payload,
                $options['profile'],
                $options
            );

        if (
            $options['deterministic']
            && is_array($profiledPayload)
        ) {
            $profiledPayload =
                $this->sortRecursive(
                    $profiledPayload
                );
        }

        $content = match ($format) {
            'json',
            'ipmdb' =>
                $this->encodeJson(
                    $profiledPayload,
                    $options
                ),

            'ndjson' =>
                $this->encodePayloadNdjson(
                    $profiledPayload,
                    $options
                ),

            default =>
                throw new InvalidArgumentException(
                    sprintf(
                        'Payload export does not support format "%s".',
                        $format
                    )
                ),
        };

        $checksum = $options[
            'include_checksum'
        ]
            ? hash('sha256', $content)
            : null;

        $exportId =
            $this->generateExportId();

        return [
            'export_id' => $exportId,

            'generated_at' => gmdate('c'),

            'format' => $format,

            'profile' =>
                $options['profile'],

            'mime_type' =>
                $this->mimeType($format),

            'extension' =>
                $this->extension($format),

            'filename' =>
                $this->buildFilename(
                    $format,
                    $options
                ),

            'content' => $content,

            'content_bytes' =>
                strlen($content),

            'checksum_algorithm' =>
                $checksum !== null
                    ? 'sha256'
                    : null,

            'checksum' => $checksum,

            'compression_ready' => true,

            'compressed' => false,
        ];
    }

    /**
     * Export a graph subset around one or more entity keys.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     * @param array<int,string> $entityKeys
     *
     * @return array<string,mixed>
     */
    public function exportSubgraph(
        array $entities,
        array $relationships,
        array $entityKeys,
        array $options = []
    ): array {
        $entityKeys = $this->normalizeStringList(
            $entityKeys
        );

        $includeConnected = (bool)(
            $options['include_connected']
            ?? true
        );

        $selectedEntities = [];
        $selectedRelationships = [];
        $selectedKeyMap = [];

        foreach ($entityKeys as $key) {
            $selectedKeyMap[$key] = true;
        }

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $key = $this->entityKey($entity);

            if (
                $key !== ''
                && isset($selectedKeyMap[$key])
            ) {
                $selectedEntities[] = $entity;
            }
        }

        if ($includeConnected) {
            foreach ($relationships as $relationship) {
                if (!is_array($relationship)) {
                    continue;
                }

                $sourceKey =
                    $this->relationshipSourceKey(
                        $relationship
                    );

                $targetKey =
                    $this->relationshipTargetKey(
                        $relationship
                    );

                if (
                    isset($selectedKeyMap[$sourceKey])
                    || isset($selectedKeyMap[$targetKey])
                ) {
                    $selectedRelationships[] =
                        $relationship;

                    $selectedKeyMap[$sourceKey] =
                        true;

                    $selectedKeyMap[$targetKey] =
                        true;
                }
            }

            foreach ($entities as $entity) {
                if (!is_array($entity)) {
                    continue;
                }

                $key = $this->entityKey($entity);

                if (
                    $key !== ''
                    && isset(
                        $selectedKeyMap[$key]
                    )
                    && !$this->entityArrayContains(
                        $selectedEntities,
                        $key
                    )
                ) {
                    $selectedEntities[] =
                        $entity;
                }
            }
        } else {
            foreach ($relationships as $relationship) {
                if (!is_array($relationship)) {
                    continue;
                }

                $sourceKey =
                    $this->relationshipSourceKey(
                        $relationship
                    );

                $targetKey =
                    $this->relationshipTargetKey(
                        $relationship
                    );

                if (
                    isset($selectedKeyMap[$sourceKey])
                    && isset($selectedKeyMap[$targetKey])
                ) {
                    $selectedRelationships[] =
                        $relationship;
                }
            }
        }

        return $this->export(
            $selectedEntities,
            $selectedRelationships,
            $options
        );
    }

    /**
     * Return the most recent export manifest.
     *
     * @return array<string,mixed>
     */
    public function lastManifest(): array
    {
        return $this->lastManifest;
    }

    /**
     * Return supported formats.
     *
     * @return array<int,string>
     */
    public function formats(): array
    {
        return $this->formats;
    }

    /**
     * Return supported profiles.
     *
     * @return array<int,string>
     */
    public function profiles(): array
    {
        return $this->profiles;
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

                'profiles' =>
                    $this->profiles,

                'defaults' =>
                    $this->defaults,

                'public_excluded_fields' =>
                    $this->publicExcludedFields,

                'minimal_entity_fields' =>
                    $this->minimalEntityFields,

                'minimal_relationship_fields' =>
                    $this->minimalRelationshipFields,

                'deterministic_export_supported' =>
                    true,

                'manifest_supported' =>
                    true,

                'checksums_supported' =>
                    true,

                'compression_ready' =>
                    true,

                'automatic_compression' =>
                    false,

                'database_operations' =>
                    false,

                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Prepare graph records for serialization.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function prepareGraph(
        array $entities,
        array $relationships,
        array $options
    ): array {
        $maximumRecords = max(
            1,
            min(
                10000000,
                (int)(
                    $options['maximum_records']
                    ?? 1000000
                )
            )
        );

        $preparedEntities = [];
        $preparedRelationships = [];

        if ($options['include_entities']) {
            foreach ($entities as $entity) {
                if (!is_array($entity)) {
                    continue;
                }

                if (
                    !$this->entityPassesFilters(
                        $entity,
                        $options
                    )
                ) {
                    continue;
                }

                $preparedEntities[] =
                    $this->prepareEntity(
                        $entity,
                        $options
                    );

                if (
                    count($preparedEntities)
                    >= $maximumRecords
                ) {
                    break;
                }
            }
        }

        $entityKeyMap = [];

        foreach ($preparedEntities as $entity) {
            $key = $this->entityKey($entity);

            if ($key !== '') {
                $entityKeyMap[$key] = true;
            }
        }

        if ($options['include_relationships']) {
            foreach ($relationships as $relationship) {
                if (!is_array($relationship)) {
                    continue;
                }

                if (
                    !$this->relationshipPassesFilters(
                        $relationship,
                        $options
                    )
                ) {
                    continue;
                }

                if (
                    !$options[
                        'include_orphan_relationships'
                    ]
                    && $entityKeyMap !== []
                ) {
                    $sourceKey =
                        $this->relationshipSourceKey(
                            $relationship
                        );

                    $targetKey =
                        $this->relationshipTargetKey(
                            $relationship
                        );

                    if (
                        !isset(
                            $entityKeyMap[$sourceKey]
                        )
                        || !isset(
                            $entityKeyMap[$targetKey]
                        )
                    ) {
                        continue;
                    }
                }

                $preparedRelationships[] =
                    $this->prepareRelationship(
                        $relationship,
                        $options
                    );

                if (
                    count(
                        $preparedRelationships
                    ) >= $maximumRecords
                ) {
                    break;
                }
            }
        }

        if ($options['deterministic']) {
            usort(
                $preparedEntities,
                fn (
                    array $left,
                    array $right
                ): int =>
                    strcmp(
                        $this->entityKey($left),
                        $this->entityKey($right)
                    )
            );

            usort(
                $preparedRelationships,
                static fn (
                    array $left,
                    array $right
                ): int =>
                    strcmp(
                        (string)(
                            $left[
                                'relationship_id'
                            ] ?? ''
                        ),
                        (string)(
                            $right[
                                'relationship_id'
                            ] ?? ''
                        )
                    )
            );
        }

        return [
            'entities' =>
                $preparedEntities,

            'relationships' =>
                $preparedRelationships,
        ];
    }

    /**
     * Normalize export options.
     *
     * @return array<string,mixed>
     */
    private function normalizeOptions(
        array $options
    ): array {
        $options = array_replace(
            $this->defaults,
            $options
        );

        $options['format'] =
            $this->normalizeFormat(
                (string)$options['format']
            );

        $options['profile'] =
            $this->normalizeProfile(
                (string)$options['profile']
            );

        foreach (
            [
                'include_entities',
                'include_relationships',
                'include_manifest',
                'include_statistics',
                'include_checksum',
                'include_metadata',
                'include_provenance',
                'include_versions',
                'include_translations',
                'include_empty_values',
                'include_null_values',
                'include_private_fields',
                'include_diagnostics',
                'include_orphan_relationships',
                'include_expired_relationships',
                'normalize_dates',
                'sort_keys',
                'pretty',
                'deterministic',
            ]
            as $booleanField
        ) {
            $options[$booleanField] =
                (bool)$options[$booleanField];
        }

        $options['entity_types'] =
            $this->normalizeStringList(
                $options['entity_types']
                ?? []
            );

        $options['relationship_types'] =
            $this->normalizeStringList(
                $options[
                    'relationship_types'
                ] ?? []
            );

        $options['statuses'] =
            $this->normalizeStringList(
                $options['statuses']
                ?? []
            );

        $options['entity_fields'] =
            $this->normalizeStringList(
                $options['entity_fields']
                ?? []
            );

        $options['relationship_fields'] =
            $this->normalizeStringList(
                $options[
                    'relationship_fields'
                ] ?? []
            );

        $options['exclude_fields'] =
            $this->normalizeStringList(
                $options['exclude_fields']
                ?? []
            );

        $options['maximum_records'] = max(
            1,
            (int)$options['maximum_records']
        );

        $lineEnding = (string)(
            $options['line_ending']
            ?? "\n"
        );

        $options['line_ending'] =
            $lineEnding !== ''
                ? $lineEnding
                : "\n";

        return $options;
    }

    /**
     * Normalize export format.
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
                    'Unsupported graph export format "%s".',
                    $format
                )
            );
        }

        return $format;
    }

    /**
     * Normalize export profile.
     */
    private function normalizeProfile(
        string $profile
    ): string {
        $profile = $this->normalizeMachineKey(
            $profile
        );

        if (
            !in_array(
                $profile,
                $this->profiles,
                true
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported graph export profile "%s".',
                    $profile
                )
            );
        }

        return $profile;
    }

    /**
     * Route serialization to the requested format.
     *
     * The concrete serializers are implemented in Parts 2–4.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,string>
     */
    private function serialize(
        array $entities,
        array $relationships,
        string $format,
        array $options,
        array $manifest,
        array $statistics
    ): array {
        return match ($format) {
            'ipmdb',
            'json' =>
                $this->serializeJsonGraph(
                    $entities,
                    $relationships,
                    $options,
                    $manifest,
                    $statistics
                ),

            'ndjson' =>
                $this->serializeNdjsonGraph(
                    $entities,
                    $relationships,
                    $options,
                    $manifest
                ),

            'csv_entities' =>
                $this->serializeCsvEntities(
                    $entities,
                    $options
                ),

            'csv_relationships' =>
                $this->serializeCsvRelationships(
                    $relationships,
                    $options
                ),

            'csv_bundle' =>
                $this->serializeCsvBundle(
                    $entities,
                    $relationships,
                    $options,
                    $manifest
                ),

            'edge_list' =>
                $this->serializeEdgeList(
                    $relationships,
                    $options
                ),

            'adjacency_list' =>
                $this->serializeAdjacencyList(
                    $entities,
                    $relationships,
                    $options
                ),

            'graphml' =>
                $this->serializeGraphMl(
                    $entities,
                    $relationships,
                    $options,
                    $manifest
                ),

            'gexf' =>
                $this->serializeGexf(
                    $entities,
                    $relationships,
                    $options,
                    $manifest
                ),

            'cytoscape' =>
                $this->serializeCytoscape(
                    $entities,
                    $relationships,
                    $options,
                    $manifest
                ),

            'd3' =>
                $this->serializeD3(
                    $entities,
                    $relationships,
                    $options,
                    $manifest
                ),

            'neo4j' =>
                $this->serializeNeo4j(
                    $entities,
                    $relationships,
                    $options,
                    $manifest
                ),

            'mermaid' =>
                $this->serializeMermaid(
                    $entities,
                    $relationships,
                    $options
                ),

            'plantuml' =>
                $this->serializePlantUml(
                    $entities,
                    $relationships,
                    $options
                ),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | SERIALIZER METHODS CONTINUE IN PART 2
    |--------------------------------------------------------------------------
    |
    | Do not close the class yet.
    |
    */    /**
     * Serialize native IPMdb or JSON graph payload.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,string>
     */
    private function serializeJsonGraph(
        array $entities,
        array $relationships,
        array $options,
        array $manifest,
        array $statistics
    ): array {
        $payload = [
            'format' => 'ipmdb_graph',

            'version' => '1.0',

            'generated_at' =>
                gmdate('c'),

            'entities' =>
                $entities,

            'relationships' =>
                $relationships,
        ];

        if ($options['include_manifest']) {
            $payload['manifest'] =
                $manifest;
        }

        if ($options['include_statistics']) {
            $payload['statistics'] =
                $statistics;
        }

        if ($options['deterministic']) {
            $payload = $this->sortRecursive(
                $payload
            );
        }

        return [
            'content' =>
                $this->encodeJson(
                    $payload,
                    $options
                ),

            'mime_type' =>
                'application/json',

            'extension' => 'json',
        ];
    }

    /**
     * Serialize graph as newline-delimited JSON.
     *
     * Each line contains one independent record.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,string>
     */
    private function serializeNdjsonGraph(
        array $entities,
        array $relationships,
        array $options,
        array $manifest
    ): array {
        $lines = [];
        $lineEnding =
            $options['line_ending'];

        if ($options['include_manifest']) {
            $lines[] = $this->encodeJson(
                [
                    'record_type' =>
                        'manifest',

                    'data' => $manifest,
                ],
                array_merge(
                    $options,
                    [
                        'pretty' => false,
                    ]
                )
            );
        }

        foreach ($entities as $entity) {
            $lines[] = $this->encodeJson(
                [
                    'record_type' =>
                        'entity',

                    'entity_id' =>
                        $this->resolveEntityId(
                            $entity
                        ),

                    'entity_type' =>
                        $this->resolveEntityType(
                            $entity
                        ),

                    'data' => $entity,
                ],
                array_merge(
                    $options,
                    [
                        'pretty' => false,
                    ]
                )
            );
        }

        foreach ($relationships as $relationship) {
            $lines[] = $this->encodeJson(
                [
                    'record_type' =>
                        'relationship',

                    'relationship_id' =>
                        $relationship[
                            'relationship_id'
                        ] ?? null,

                    'relationship_type' =>
                        $relationship[
                            'relationship_type'
                        ] ?? null,

                    'data' =>
                        $relationship,
                ],
                array_merge(
                    $options,
                    [
                        'pretty' => false,
                    ]
                )
            );
        }

        return [
            'content' =>
                implode(
                    $lineEnding,
                    $lines
                )
                . (
                    $lines !== []
                        ? $lineEnding
                        : ''
                ),

            'mime_type' =>
                'application/x-ndjson',

            'extension' => 'ndjson',
        ];
    }

    /**
     * Serialize an arbitrary payload as NDJSON.
     */
    private function encodePayloadNdjson(
        mixed $payload,
        array $options
    ): string {
        $lineEnding =
            $options['line_ending'];

        if (!is_array($payload)) {
            return $this->encodeJson(
                $payload,
                array_merge(
                    $options,
                    [
                        'pretty' => false,
                    ]
                )
            ) . $lineEnding;
        }

        $lines = [];

        if (array_is_list($payload)) {
            foreach ($payload as $item) {
                $lines[] = $this->encodeJson(
                    $item,
                    array_merge(
                        $options,
                        [
                            'pretty' => false,
                        ]
                    )
                );
            }
        } else {
            foreach ($payload as $key => $item) {
                $lines[] = $this->encodeJson(
                    [
                        'key' => $key,
                        'value' => $item,
                    ],
                    array_merge(
                        $options,
                        [
                            'pretty' => false,
                        ]
                    )
                );
            }
        }

        return implode(
            $lineEnding,
            $lines
        )
            . (
                $lines !== []
                    ? $lineEnding
                    : ''
            );
    }

    /**
     * Serialize entities as CSV.
     *
     * @param array<int,array<string,mixed>> $entities
     *
     * @return array<string,string>
     */
    private function serializeCsvEntities(
        array $entities,
        array $options
    ): array {
        return [
            'content' =>
                $this->recordsToCsv(
                    $entities,
                    $options,
                    $options['entity_fields']
                ),

            'mime_type' =>
                'text/csv',

            'extension' => 'csv',
        ];
    }

    /**
     * Serialize relationships as CSV.
     *
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,string>
     */
    private function serializeCsvRelationships(
        array $relationships,
        array $options
    ): array {
        return [
            'content' =>
                $this->recordsToCsv(
                    $relationships,
                    $options,
                    $options[
                        'relationship_fields'
                    ]
                ),

            'mime_type' =>
                'text/csv',

            'extension' => 'csv',
        ];
    }

    /**
     * Serialize entities and relationships into one JSON-wrapped CSV bundle.
     *
     * The bundle remains one transport-safe string while preserving
     * separate CSV datasets.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,string>
     */
    private function serializeCsvBundle(
        array $entities,
        array $relationships,
        array $options,
        array $manifest
    ): array {
        $bundle = [
            'manifest' =>
                $options['include_manifest']
                    ? $manifest
                    : null,

            'entities_csv' =>
                $this->recordsToCsv(
                    $entities,
                    $options,
                    $options['entity_fields']
                ),

            'relationships_csv' =>
                $this->recordsToCsv(
                    $relationships,
                    $options,
                    $options[
                        'relationship_fields'
                    ]
                ),
        ];

        return [
            'content' =>
                $this->encodeJson(
                    $bundle,
                    $options
                ),

            'mime_type' =>
                'application/json',

            'extension' => 'json',
        ];
    }

    /**
     * Convert records to CSV.
     *
     * @param array<int,array<string,mixed>> $records
     * @param array<int,string> $requestedFields
     */
    private function recordsToCsv(
        array $records,
        array $options,
        array $requestedFields = []
    ): string {
        $delimiter = (string)(
            $options['csv_delimiter']
            ?? ','
        );

        $enclosure = (string)(
            $options['csv_enclosure']
            ?? '"'
        );

        $escape = (string)(
            $options['csv_escape']
            ?? '\\'
        );

        $lineEnding =
            $options['line_ending'];

        $headers = $requestedFields !== []
            ? $requestedFields
            : $this->collectRecordFields(
                $records
            );

        if ($headers === []) {
            return '';
        }

        if ($options['deterministic']) {
            sort($headers);
        }

        $stream = fopen(
            'php://temp',
            'r+'
        );

        if ($stream === false) {
            throw new RuntimeException(
                'Unable to create CSV export stream.'
            );
        }

        $this->writeCsvRow(
            $stream,
            $headers,
            $delimiter,
            $enclosure,
            $escape,
            $lineEnding
        );

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $row = [];

            foreach ($headers as $field) {
                $row[] =
                    $this->csvValue(
                        $record[$field]
                            ?? null
                    );
            }

            $this->writeCsvRow(
                $stream,
                $row,
                $delimiter,
                $enclosure,
                $escape,
                $lineEnding
            );
        }

        rewind($stream);

        $content = stream_get_contents(
            $stream
        );

        fclose($stream);

        if ($content === false) {
            throw new RuntimeException(
                'Unable to read generated CSV content.'
            );
        }

        return $content;
    }

    /**
     * Write one CSV row with configured line endings.
     *
     * @param resource $stream
     * @param array<int,mixed> $values
     */
    private function writeCsvRow(
        mixed $stream,
        array $values,
        string $delimiter,
        string $enclosure,
        string $escape,
        string $lineEnding
    ): void {
        $temporary = fopen(
            'php://temp',
            'r+'
        );

        if ($temporary === false) {
            throw new RuntimeException(
                'Unable to create CSV row stream.'
            );
        }

        $written = fputcsv(
            $temporary,
            $values,
            $delimiter,
            $enclosure,
            $escape
        );

        if ($written === false) {
            fclose($temporary);

            throw new RuntimeException(
                'Unable to generate CSV row.'
            );
        }

        rewind($temporary);

        $row = stream_get_contents(
            $temporary
        );

        fclose($temporary);

        if ($row === false) {
            throw new RuntimeException(
                'Unable to read generated CSV row.'
            );
        }

        $row = rtrim(
            $row,
            "\r\n"
        ) . $lineEnding;

        fwrite($stream, $row);
    }

    /**
     * Convert a field value into CSV-safe scalar text.
     */
    private function csvValue(
        mixed $value
    ): string {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value
                ? 'true'
                : 'false';
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        $json = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
        );

        return $json !== false
            ? $json
            : '';
    }

    /**
     * Collect all fields appearing in a record collection.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,string>
     */
    private function collectRecordFields(
        array $records
    ): array {
        $fields = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            foreach (array_keys($record) as $field) {
                $field = trim(
                    (string)$field
                );

                if ($field !== '') {
                    $fields[$field] =
                        $field;
                }
            }
        }

        return array_values($fields);
    }

    /**
     * Serialize relationships as an edge list.
     *
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,string>
     */
    private function serializeEdgeList(
        array $relationships,
        array $options
    ): array {
        $lineEnding =
            $options['line_ending'];

        $delimiter = (string)(
            $options['edge_delimiter']
            ?? "\t"
        );

        $includeHeader = (bool)(
            $options['include_header']
            ?? true
        );

        $lines = [];

        if ($includeHeader) {
            $lines[] = implode(
                $delimiter,
                [
                    'source_key',
                    'relationship_type',
                    'target_key',
                    'relationship_id',
                    'status',
                    'confidence',
                    'weight',
                    'strength',
                ]
            );
        }

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $lines[] = implode(
                $delimiter,
                array_map(
                    fn (mixed $value): string =>
                        $this->escapeFlatValue(
                            $value,
                            $delimiter
                        ),
                    [
                        $this->relationshipSourceKey(
                            $relationship
                        ),

                        $relationship[
                            'relationship_type'
                        ] ?? 'related_to',

                        $this->relationshipTargetKey(
                            $relationship
                        ),

                        $relationship[
                            'relationship_id'
                        ] ?? '',

                        $relationship['status']
                            ?? '',

                        $relationship['confidence']
                            ?? '',

                        $relationship['weight']
                            ?? '',

                        $relationship['strength']
                            ?? '',
                    ]
                )
            );
        }

        return [
            'content' =>
                implode(
                    $lineEnding,
                    $lines
                )
                . (
                    $lines !== []
                        ? $lineEnding
                        : ''
                ),

            'mime_type' =>
                'text/plain',

            'extension' => 'txt',
        ];
    }

    /**
     * Serialize graph as adjacency list.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,string>
     */
    private function serializeAdjacencyList(
        array $entities,
        array $relationships,
        array $options
    ): array {
        $direction = $this->normalizeMachineKey(
            (string)(
                $options[
                    'adjacency_direction'
                ] ?? 'outgoing'
            )
        );

        if (
            !in_array(
                $direction,
                [
                    'outgoing',
                    'incoming',
                    'both',
                ],
                true
            )
        ) {
            $direction = 'outgoing';
        }

        $adjacency = [];

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $key = $this->entityKey(
                $entity
            );

            if ($key !== '') {
                $adjacency[$key] = [];
            }
        }

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $sourceKey =
                $this->relationshipSourceKey(
                    $relationship
                );

            $targetKey =
                $this->relationshipTargetKey(
                    $relationship
                );

            $edge = [
                'relationship_id' =>
                    $relationship[
                        'relationship_id'
                    ] ?? null,

                'relationship_type' =>
                    $relationship[
                        'relationship_type'
                    ] ?? 'related_to',

                'status' =>
                    $relationship['status']
                    ?? null,

                'confidence' =>
                    $relationship['confidence']
                    ?? null,

                'weight' =>
                    $relationship['weight']
                    ?? null,

                'strength' =>
                    $relationship['strength']
                    ?? null,
            ];

            if (
                $direction === 'outgoing'
                || $direction === 'both'
            ) {
                $adjacency[$sourceKey][] =
                    array_merge(
                        $edge,
                        [
                            'direction' =>
                                'outgoing',

                            'node_key' =>
                                $targetKey,
                        ]
                    );
            }

            if (
                $direction === 'incoming'
                || $direction === 'both'
            ) {
                $adjacency[$targetKey][] =
                    array_merge(
                        $edge,
                        [
                            'direction' =>
                                'incoming',

                            'node_key' =>
                                $sourceKey,
                        ]
                    );
            }
        }

        if ($options['deterministic']) {
            ksort($adjacency);

            foreach ($adjacency as &$connections) {
                usort(
                    $connections,
                    static function (
                        array $left,
                        array $right
                    ): int {
                        $nodeComparison =
                            strcmp(
                                (string)(
                                    $left['node_key']
                                    ?? ''
                                ),
                                (string)(
                                    $right['node_key']
                                    ?? ''
                                )
                            );

                        if (
                            $nodeComparison !== 0
                        ) {
                            return $nodeComparison;
                        }

                        return strcmp(
                            (string)(
                                $left[
                                    'relationship_id'
                                ] ?? ''
                            ),
                            (string)(
                                $right[
                                    'relationship_id'
                                ] ?? ''
                            )
                        );
                    }
                );
            }

            unset($connections);
        }

        $mode = $this->normalizeMachineKey(
            (string)(
                $options[
                    'adjacency_output'
                ] ?? 'json'
            )
        );

        if ($mode === 'text') {
            $content = $this->adjacencyToText(
                $adjacency,
                $options
            );

            return [
                'content' => $content,

                'mime_type' =>
                    'text/plain',

                'extension' => 'txt',
            ];
        }

        return [
            'content' =>
                $this->encodeJson(
                    [
                        'direction' =>
                            $direction,

                        'adjacency' =>
                            $adjacency,
                    ],
                    $options
                ),

            'mime_type' =>
                'application/json',

            'extension' => 'json',
        ];
    }

    /**
     * Convert adjacency data into readable text.
     *
     * @param array<string,array<int,array<string,mixed>>> $adjacency
     */
    private function adjacencyToText(
        array $adjacency,
        array $options
    ): string {
        $lineEnding =
            $options['line_ending'];

        $lines = [];

        foreach ($adjacency as $nodeKey => $connections) {
            if ($connections === []) {
                $lines[] = $nodeKey . ':';
                continue;
            }

            $parts = [];

            foreach ($connections as $connection) {
                $parts[] = sprintf(
                    '%s[%s:%s]',
                    (string)(
                        $connection['node_key']
                        ?? ''
                    ),
                    (string)(
                        $connection[
                            'relationship_type'
                        ] ?? 'related_to'
                    ),
                    (string)(
                        $connection['direction']
                        ?? 'outgoing'
                    )
                );
            }

            $lines[] = $nodeKey
                . ': '
                . implode(', ', $parts);
        }

        return implode(
            $lineEnding,
            $lines
        )
            . (
                $lines !== []
                    ? $lineEnding
                    : ''
            );
    }

    /**
     * Escape a flat text-export value.
     */
    private function escapeFlatValue(
        mixed $value,
        string $delimiter
    ): string {
        $value = $this->csvValue($value);

        $value = str_replace(
            [
                "\r",
                "\n",
                $delimiter,
            ],
            [
                ' ',
                ' ',
                ' ',
            ],
            $value
        );

        return trim($value);
    }

    /**
     * Encode JSON consistently.
     */
    private function encodeJson(
        mixed $value,
        array $options
    ): string {
        $flags =
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR;

        if (
            (bool)(
                $options['pretty']
                ?? false
            )
        ) {
            $flags |= JSON_PRETTY_PRINT;
        }

        try {
            return json_encode(
                $value,
                $flags
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Unable to encode export JSON: '
                . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | XML AND GRAPH-APPLICATION SERIALIZERS CONTINUE IN PART 3
    |--------------------------------------------------------------------------
    |
    | Do not close the class yet.
    |
    */    /**
     * Serialize graph as GraphML.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,string>
     */
    private function serializeGraphMl(
        array $entities,
        array $relationships,
        array $options,
        array $manifest
    ): array {
        $indent = (string)(
            $options['xml_indent']
            ?? '  '
        );

        $lineEnding =
            $options['line_ending'];

        $nodeFields = $this->collectRecordFields(
            $entities
        );

        $edgeFields = $this->collectRecordFields(
            $relationships
        );

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',

            '<graphml xmlns="http://graphml.graphdrawing.org/xmlns"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xsi:schemaLocation="http://graphml.graphdrawing.org/xmlns'
            . ' http://graphml.graphdrawing.org/xmlns/1.0/graphml.xsd">',
        ];

        foreach ($nodeFields as $field) {
            $lines[] = $indent
                . '<key id="node_'
                . $this->xmlAttribute(
                    $this->normalizeMachineKey($field)
                )
                . '" for="node" attr.name="'
                . $this->xmlAttribute($field)
                . '" attr.type="string"/>';
        }

        foreach ($edgeFields as $field) {
            $lines[] = $indent
                . '<key id="edge_'
                . $this->xmlAttribute(
                    $this->normalizeMachineKey($field)
                )
                . '" for="edge" attr.name="'
                . $this->xmlAttribute($field)
                . '" attr.type="string"/>';
        }

        $lines[] = $indent
            . '<graph id="'
            . $this->xmlAttribute(
                (string)(
                    $manifest['export_id']
                    ?? 'ipmdb_graph'
                )
            )
            . '" edgedefault="directed">';

        foreach ($entities as $entity) {
            $nodeId = $this->entityKey($entity);

            if ($nodeId === '') {
                continue;
            }

            $lines[] = $indent
                . $indent
                . '<node id="'
                . $this->xmlAttribute($nodeId)
                . '">';

            foreach ($nodeFields as $field) {
                if (!array_key_exists($field, $entity)) {
                    continue;
                }

                $value = $this->xmlScalar(
                    $entity[$field]
                );

                if (
                    $value === ''
                    && !$options[
                        'include_empty_values'
                    ]
                ) {
                    continue;
                }

                $lines[] = $indent
                    . $indent
                    . $indent
                    . '<data key="node_'
                    . $this->xmlAttribute(
                        $this->normalizeMachineKey(
                            $field
                        )
                    )
                    . '">'
                    . $this->xmlText($value)
                    . '</data>';
            }

            $lines[] = $indent
                . $indent
                . '</node>';
        }

        foreach ($relationships as $relationship) {
            $sourceKey =
                $this->relationshipSourceKey(
                    $relationship
                );

            $targetKey =
                $this->relationshipTargetKey(
                    $relationship
                );

            if (
                $sourceKey === ''
                || $targetKey === ''
            ) {
                continue;
            }

            $edgeId = trim(
                (string)(
                    $relationship[
                        'relationship_id'
                    ] ?? ''
                )
            );

            if ($edgeId === '') {
                $edgeId = 'edge_'
                    . strtoupper(
                        substr(
                            hash(
                                'sha256',
                                $sourceKey
                                . '|'
                                . $targetKey
                                . '|'
                                . (
                                    $relationship[
                                        'relationship_type'
                                    ] ?? 'related_to'
                                )
                            ),
                            0,
                            16
                        )
                    );
            }

            $directed = (
                $relationship['directional']
                ?? true
            ) !== false;

            $lines[] = $indent
                . $indent
                . '<edge id="'
                . $this->xmlAttribute($edgeId)
                . '" source="'
                . $this->xmlAttribute($sourceKey)
                . '" target="'
                . $this->xmlAttribute($targetKey)
                . '" directed="'
                . (
                    $directed
                        ? 'true'
                        : 'false'
                )
                . '">';

            foreach ($edgeFields as $field) {
                if (
                    !array_key_exists(
                        $field,
                        $relationship
                    )
                ) {
                    continue;
                }

                $value = $this->xmlScalar(
                    $relationship[$field]
                );

                if (
                    $value === ''
                    && !$options[
                        'include_empty_values'
                    ]
                ) {
                    continue;
                }

                $lines[] = $indent
                    . $indent
                    . $indent
                    . '<data key="edge_'
                    . $this->xmlAttribute(
                        $this->normalizeMachineKey(
                            $field
                        )
                    )
                    . '">'
                    . $this->xmlText($value)
                    . '</data>';
            }

            $lines[] = $indent
                . $indent
                . '</edge>';
        }

        $lines[] = $indent . '</graph>';
        $lines[] = '</graphml>';

        return [
            'content' =>
                implode(
                    $lineEnding,
                    $lines
                )
                . $lineEnding,

            'mime_type' =>
                'application/graphml+xml',

            'extension' => 'graphml',
        ];
    }

    /**
     * Serialize graph as GEXF.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,string>
     */
    private function serializeGexf(
        array $entities,
        array $relationships,
        array $options,
        array $manifest
    ): array {
        $indent = (string)(
            $options['xml_indent']
            ?? '  '
        );

        $lineEnding =
            $options['line_ending'];

        $nodeFields = $this->collectRecordFields(
            $entities
        );

        $edgeFields = $this->collectRecordFields(
            $relationships
        );

        $nodeAttributeIds = [];
        $edgeAttributeIds = [];

        foreach ($nodeFields as $index => $field) {
            $nodeAttributeIds[$field] =
                'n' . $index;
        }

        foreach ($edgeFields as $index => $field) {
            $edgeAttributeIds[$field] =
                'e' . $index;
        }

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',

            '<gexf xmlns="http://gexf.net/1.3"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xsi:schemaLocation="http://gexf.net/1.3'
            . ' http://gexf.net/1.3/gexf.xsd"'
            . ' version="1.3">',

            $indent
            . '<meta lastmodifieddate="'
            . $this->xmlAttribute(
                gmdate('Y-m-d')
            )
            . '">',

            $indent
            . $indent
            . '<creator>IPMdb GraphExportService</creator>',

            $indent
            . $indent
            . '<description>'
            . $this->xmlText(
                'IPMdb graph export '
                . (
                    $manifest['export_id']
                    ?? ''
                )
            )
            . '</description>',

            $indent . '</meta>',

            $indent
            . '<graph mode="static" defaultedgetype="directed">',
        ];

        if ($nodeFields !== []) {
            $lines[] = $indent
                . $indent
                . '<attributes class="node">';

            foreach ($nodeFields as $field) {
                $lines[] = $indent
                    . $indent
                    . $indent
                    . '<attribute id="'
                    . $this->xmlAttribute(
                        $nodeAttributeIds[$field]
                    )
                    . '" title="'
                    . $this->xmlAttribute($field)
                    . '" type="string"/>';
            }

            $lines[] = $indent
                . $indent
                . '</attributes>';
        }

        if ($edgeFields !== []) {
            $lines[] = $indent
                . $indent
                . '<attributes class="edge">';

            foreach ($edgeFields as $field) {
                $lines[] = $indent
                    . $indent
                    . $indent
                    . '<attribute id="'
                    . $this->xmlAttribute(
                        $edgeAttributeIds[$field]
                    )
                    . '" title="'
                    . $this->xmlAttribute($field)
                    . '" type="string"/>';
            }

            $lines[] = $indent
                . $indent
                . '</attributes>';
        }

        $lines[] = $indent
            . $indent
            . '<nodes>';

        foreach ($entities as $entity) {
            $nodeId = $this->entityKey($entity);

            if ($nodeId === '') {
                continue;
            }

            $label = $this->recordLabel(
                $entity
            );

            $lines[] = $indent
                . $indent
                . $indent
                . '<node id="'
                . $this->xmlAttribute($nodeId)
                . '" label="'
                . $this->xmlAttribute($label)
                . '">';

            $attributeLines = [];

            foreach ($nodeFields as $field) {
                if (!array_key_exists($field, $entity)) {
                    continue;
                }

                $value = $this->xmlScalar(
                    $entity[$field]
                );

                if (
                    $value === ''
                    && !$options[
                        'include_empty_values'
                    ]
                ) {
                    continue;
                }

                $attributeLines[] = $indent
                    . $indent
                    . $indent
                    . $indent
                    . $indent
                    . '<attvalue for="'
                    . $this->xmlAttribute(
                        $nodeAttributeIds[$field]
                    )
                    . '" value="'
                    . $this->xmlAttribute($value)
                    . '"/>';
            }

            if ($attributeLines !== []) {
                $lines[] = $indent
                    . $indent
                    . $indent
                    . $indent
                    . '<attvalues>';

                foreach ($attributeLines as $line) {
                    $lines[] = $line;
                }

                $lines[] = $indent
                    . $indent
                    . $indent
                    . $indent
                    . '</attvalues>';
            }

            $lines[] = $indent
                . $indent
                . $indent
                . '</node>';
        }

        $lines[] = $indent
            . $indent
            . '</nodes>';

        $lines[] = $indent
            . $indent
            . '<edges>';

        foreach ($relationships as $index => $relationship) {
            $sourceKey =
                $this->relationshipSourceKey(
                    $relationship
                );

            $targetKey =
                $this->relationshipTargetKey(
                    $relationship
                );

            if (
                $sourceKey === ''
                || $targetKey === ''
            ) {
                continue;
            }

            $edgeId = trim(
                (string)(
                    $relationship[
                        'relationship_id'
                    ] ?? ''
                )
            );

            if ($edgeId === '') {
                $edgeId = 'edge_' . $index;
            }

            $edgeType = (
                $relationship['directional']
                ?? true
            ) === false
                ? 'undirected'
                : 'directed';

            $label = (string)(
                $relationship[
                    'relationship_type'
                ] ?? 'related_to'
            );

            $weight = (float)(
                $relationship['weight']
                ?? 1
            );

            $lines[] = $indent
                . $indent
                . $indent
                . '<edge id="'
                . $this->xmlAttribute($edgeId)
                . '" source="'
                . $this->xmlAttribute($sourceKey)
                . '" target="'
                . $this->xmlAttribute($targetKey)
                . '" type="'
                . $edgeType
                . '" label="'
                . $this->xmlAttribute($label)
                . '" weight="'
                . $this->xmlAttribute(
                    (string)$weight
                )
                . '">';

            $attributeLines = [];

            foreach ($edgeFields as $field) {
                if (
                    !array_key_exists(
                        $field,
                        $relationship
                    )
                ) {
                    continue;
                }

                $value = $this->xmlScalar(
                    $relationship[$field]
                );

                if (
                    $value === ''
                    && !$options[
                        'include_empty_values'
                    ]
                ) {
                    continue;
                }

                $attributeLines[] = $indent
                    . $indent
                    . $indent
                    . $indent
                    . $indent
                    . '<attvalue for="'
                    . $this->xmlAttribute(
                        $edgeAttributeIds[$field]
                    )
                    . '" value="'
                    . $this->xmlAttribute($value)
                    . '"/>';
            }

            if ($attributeLines !== []) {
                $lines[] = $indent
                    . $indent
                    . $indent
                    . $indent
                    . '<attvalues>';

                foreach ($attributeLines as $line) {
                    $lines[] = $line;
                }

                $lines[] = $indent
                    . $indent
                    . $indent
                    . $indent
                    . '</attvalues>';
            }

            $lines[] = $indent
                . $indent
                . $indent
                . '</edge>';
        }

        $lines[] = $indent
            . $indent
            . '</edges>';

        $lines[] = $indent . '</graph>';
        $lines[] = '</gexf>';

        return [
            'content' =>
                implode(
                    $lineEnding,
                    $lines
                )
                . $lineEnding,

            'mime_type' =>
                'application/gexf+xml',

            'extension' => 'gexf',
        ];
    }

    /**
     * Serialize graph for Cytoscape.js.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,string>
     */
    private function serializeCytoscape(
        array $entities,
        array $relationships,
        array $options,
        array $manifest
    ): array {
        $nodes = [];
        $edges = [];

        foreach ($entities as $entity) {
            $nodeId = $this->entityKey($entity);

            if ($nodeId === '') {
                continue;
            }

            $data = $entity;
            $data['id'] = $nodeId;

            if (
                !isset($data['label'])
                || trim(
                    (string)$data['label']
                ) === ''
            ) {
                $data['label'] =
                    $this->recordLabel($entity);
            }

            $nodes[] = [
                'data' => $data,
            ];
        }

        foreach ($relationships as $index => $relationship) {
            $sourceKey =
                $this->relationshipSourceKey(
                    $relationship
                );

            $targetKey =
                $this->relationshipTargetKey(
                    $relationship
                );

            if (
                $sourceKey === ''
                || $targetKey === ''
            ) {
                continue;
            }

            $edgeId = trim(
                (string)(
                    $relationship[
                        'relationship_id'
                    ] ?? ''
                )
            );

            if ($edgeId === '') {
                $edgeId = 'edge_' . $index;
            }

            $data = $relationship;

            $data['id'] = $edgeId;
            $data['source'] = $sourceKey;
            $data['target'] = $targetKey;

            if (
                !isset($data['label'])
                || trim(
                    (string)$data['label']
                ) === ''
            ) {
                $data['label'] =
                    $relationship[
                        'relationship_type'
                    ] ?? 'related_to';
            }

            $edges[] = [
                'data' => $data,
            ];
        }

        $payload = [
            'format' => 'cytoscape',

            'generated_at' => gmdate('c'),

            'manifest' =>
                $options['include_manifest']
                    ? $manifest
                    : null,

            'elements' => [
                'nodes' => $nodes,
                'edges' => $edges,
            ],
        ];

        if ($options['deterministic']) {
            $payload = $this->sortRecursive(
                $payload
            );
        }

        return [
            'content' =>
                $this->encodeJson(
                    $payload,
                    $options
                ),

            'mime_type' =>
                'application/json',

            'extension' => 'json',
        ];
    }

    /**
     * Serialize graph for D3.js node-link use.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,string>
     */
    private function serializeD3(
        array $entities,
        array $relationships,
        array $options,
        array $manifest
    ): array {
        $nodes = [];
        $links = [];

        foreach ($entities as $entity) {
            $nodeId = $this->entityKey($entity);

            if ($nodeId === '') {
                continue;
            }

            $node = $entity;

            $node['id'] = $nodeId;

            if (
                !isset($node['label'])
                || trim(
                    (string)$node['label']
                ) === ''
            ) {
                $node['label'] =
                    $this->recordLabel($entity);
            }

            $node['group'] =
                $this->resolveEntityType(
                    $entity
                );

            $nodes[] = $node;
        }

        foreach ($relationships as $index => $relationship) {
            $sourceKey =
                $this->relationshipSourceKey(
                    $relationship
                );

            $targetKey =
                $this->relationshipTargetKey(
                    $relationship
                );

            if (
                $sourceKey === ''
                || $targetKey === ''
            ) {
                continue;
            }

            $link = $relationship;

            $link['id'] = trim(
                (string)(
                    $relationship[
                        'relationship_id'
                    ] ?? ''
                )
            );

            if ($link['id'] === '') {
                $link['id'] =
                    'link_' . $index;
            }

            $link['source'] = $sourceKey;
            $link['target'] = $targetKey;

            $link['type'] =
                $relationship[
                    'relationship_type'
                ] ?? 'related_to';

            $link['value'] = (float)(
                $relationship['weight']
                ?? $relationship['strength']
                ?? 1
            );

            $links[] = $link;
        }

        $payload = [
            'format' => 'd3_node_link',

            'generated_at' => gmdate('c'),

            'manifest' =>
                $options['include_manifest']
                    ? $manifest
                    : null,

            'nodes' => $nodes,

            'links' => $links,
        ];

        if ($options['deterministic']) {
            $payload = $this->sortRecursive(
                $payload
            );
        }

        return [
            'content' =>
                $this->encodeJson(
                    $payload,
                    $options
                ),

            'mime_type' =>
                'application/json',

            'extension' => 'json',
        ];
    }

    /**
     * Serialize graph into Neo4j-oriented node and relationship rows.
     *
     * The result is a JSON bundle containing two CSV datasets and
     * suggested LOAD CSV statements.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,string>
     */
    private function serializeNeo4j(
        array $entities,
        array $relationships,
        array $options,
        array $manifest
    ): array {
        $nodeRows = [];
        $relationshipRows = [];

        foreach ($entities as $entity) {
            $nodeId = $this->entityKey($entity);

            if ($nodeId === '') {
                continue;
            }

            $properties = $entity;

            unset(
                $properties['entity_id'],
                $properties['entity_type']
            );

            $nodeRows[] = [
                ':ID' => $nodeId,

                ':LABEL' =>
                    $this->neo4jLabel(
                        $this->resolveEntityType(
                            $entity
                        )
                    ),

                'entity_id' =>
                    $this->resolveEntityId(
                        $entity
                    ),

                'entity_type' =>
                    $this->resolveEntityType(
                        $entity
                    ),

                'title' =>
                    $this->recordLabel($entity),

                'properties_json' =>
                    $this->encodeJson(
                        $properties,
                        array_merge(
                            $options,
                            [
                                'pretty' => false,
                            ]
                        )
                    ),
            ];
        }

        foreach ($relationships as $relationship) {
            $sourceKey =
                $this->relationshipSourceKey(
                    $relationship
                );

            $targetKey =
                $this->relationshipTargetKey(
                    $relationship
                );

            if (
                $sourceKey === ''
                || $targetKey === ''
            ) {
                continue;
            }

            $properties = $relationship;

            unset(
                $properties['source_id'],
                $properties['source_type'],
                $properties['target_id'],
                $properties['target_type'],
                $properties[
                    'relationship_type'
                ]
            );

            $relationshipRows[] = [
                ':START_ID' => $sourceKey,

                ':END_ID' => $targetKey,

                ':TYPE' =>
                    $this->neo4jRelationshipType(
                        (string)(
                            $relationship[
                                'relationship_type'
                            ] ?? 'related_to'
                        )
                    ),

                'relationship_id' =>
                    $relationship[
                        'relationship_id'
                    ] ?? '',

                'status' =>
                    $relationship['status']
                    ?? '',

                'confidence:float' =>
                    $relationship['confidence']
                    ?? '',

                'weight:float' =>
                    $relationship['weight']
                    ?? '',

                'strength:float' =>
                    $relationship['strength']
                    ?? '',

                'properties_json' =>
                    $this->encodeJson(
                        $properties,
                        array_merge(
                            $options,
                            [
                                'pretty' => false,
                            ]
                        )
                    ),
            ];
        }

        $nodeCsv = $this->recordsToCsv(
            $nodeRows,
            array_merge(
                $options,
                [
                    'entity_fields' => [],
                ]
            )
        );

        $relationshipCsv =
            $this->recordsToCsv(
                $relationshipRows,
                array_merge(
                    $options,
                    [
                        'relationship_fields' =>
                            [],
                    ]
                )
            );

        $payload = [
            'format' =>
                'neo4j_csv_bundle',

            'generated_at' =>
                gmdate('c'),

            'manifest' =>
                $options['include_manifest']
                    ? $manifest
                    : null,

            'nodes_csv' => $nodeCsv,

            'relationships_csv' =>
                $relationshipCsv,

            'import_notes' => [
                'nodes_filename' =>
                    'nodes.csv',

                'relationships_filename' =>
                    'relationships.csv',

                'admin_import_command' =>
                    'neo4j-admin database import full'
                    . ' --nodes=nodes.csv'
                    . ' --relationships=relationships.csv',

                'load_csv_node_example' =>
                    'LOAD CSV WITH HEADERS FROM'
                    . " 'file:///nodes.csv' AS row"
                    . ' CREATE (n {ipmdb_id: row.`:ID`})'
                    . ' SET n.entity_id = row.entity_id,'
                    . ' n.entity_type = row.entity_type,'
                    . ' n.title = row.title;',

                'load_csv_relationship_example' =>
                    'LOAD CSV WITH HEADERS FROM'
                    . " 'file:///relationships.csv' AS row"
                    . ' MATCH (s {ipmdb_id: row.`:START_ID`}),'
                    . ' (t {ipmdb_id: row.`:END_ID`})'
                    . ' CREATE (s)-[r:RELATED_TO]->(t)'
                    . ' SET r.relationship_id ='
                    . ' row.relationship_id;',
            ],
        ];

        return [
            'content' =>
                $this->encodeJson(
                    $payload,
                    $options
                ),

            'mime_type' =>
                'application/json',

            'extension' => 'json',
        ];
    }

    /**
     * Convert arbitrary value into XML-safe scalar content.
     */
    private function xmlScalar(
        mixed $value
    ): string {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value
                ? 'true'
                : 'false';
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        $json = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
        );

        return $json !== false
            ? $json
            : '';
    }

    /**
     * Escape XML text.
     */
    private function xmlText(
        string $value
    ): string {
        return htmlspecialchars(
            $value,
            ENT_XML1
            | ENT_QUOTES
            | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    /**
     * Escape XML attribute value.
     */
    private function xmlAttribute(
        string $value
    ): string {
        return htmlspecialchars(
            $value,
            ENT_XML1
            | ENT_QUOTES
            | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    /**
     * Resolve a readable record label.
     *
     * @param array<string,mixed> $record
     */
    private function recordLabel(
        array $record
    ): string {
        foreach (
            [
                'title',
                'name',
                'label',
                'idea',
                'summary',
                'entity_id',
                'asset_id',
                'relationship_id',
                'id',
            ]
            as $field
        ) {
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

        return 'Untitled record';
    }

    /**
     * Normalize one Neo4j label.
     */
    private function neo4jLabel(
        string $value
    ): string {
        $value = $this->normalizeMachineKey(
            $value
        );

        if ($value === '') {
            return 'Entity';
        }

        return str_replace(
            ' ',
            '',
            ucwords(
                str_replace(
                    '_',
                    ' ',
                    $value
                )
            )
        );
    }

    /**
     * Normalize one Neo4j relationship type.
     */
    private function neo4jRelationshipType(
        string $value
    ): string {
        $value = strtoupper(
            $this->normalizeMachineKey(
                $value
            )
        );

        return $value !== ''
            ? $value
            : 'RELATED_TO';
    }

    /*
    |--------------------------------------------------------------------------
    | DIAGRAM SERIALIZERS, PROFILES, MANIFESTS, AND HELPERS CONTINUE IN PART 4
    |--------------------------------------------------------------------------
    |
    | Do not close the class yet.
    |
    */    /**
     * Serialize graph as Mermaid flowchart syntax.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,string>
     */
    private function serializeMermaid(
        array $entities,
        array $relationships,
        array $options
    ): array {
        $lineEnding =
            $options['line_ending'];

        $direction = strtoupper(
            trim(
                (string)(
                    $options[
                        'mermaid_direction'
                    ] ?? 'LR'
                )
            )
        );

        if (
            !in_array(
                $direction,
                [
                    'TB',
                    'TD',
                    'BT',
                    'RL',
                    'LR',
                ],
                true
            )
        ) {
            $direction = 'LR';
        }

        $lines = [
            'flowchart ' . $direction,
        ];

        $nodeIds = [];

        foreach ($entities as $index => $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $nodeKey = $this->entityKey(
                $entity
            );

            if ($nodeKey === '') {
                continue;
            }

            $nodeId = $this->diagramIdentifier(
                $nodeKey,
                'N'
            );

            $nodeIds[$nodeKey] = $nodeId;

            $label = $this->escapeMermaidLabel(
                $this->recordLabel($entity)
            );

            $entityType =
                $this->escapeMermaidLabel(
                    $this->resolveEntityType(
                        $entity
                    )
                );

            $lines[] = sprintf(
                '    %s["%s<br/><small>%s</small>"]',
                $nodeId,
                $label,
                $entityType
            );
        }

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $sourceKey =
                $this->relationshipSourceKey(
                    $relationship
                );

            $targetKey =
                $this->relationshipTargetKey(
                    $relationship
                );

            if (
                $sourceKey === ''
                || $targetKey === ''
            ) {
                continue;
            }

            $sourceId = $nodeIds[$sourceKey]
                ?? $this->diagramIdentifier(
                    $sourceKey,
                    'N'
                );

            $targetId = $nodeIds[$targetKey]
                ?? $this->diagramIdentifier(
                    $targetKey,
                    'N'
                );

            if (!isset($nodeIds[$sourceKey])) {
                $nodeIds[$sourceKey] = $sourceId;

                $lines[] = sprintf(
                    '    %s["%s"]',
                    $sourceId,
                    $this->escapeMermaidLabel(
                        $sourceKey
                    )
                );
            }

            if (!isset($nodeIds[$targetKey])) {
                $nodeIds[$targetKey] = $targetId;

                $lines[] = sprintf(
                    '    %s["%s"]',
                    $targetId,
                    $this->escapeMermaidLabel(
                        $targetKey
                    )
                );
            }

            $relationshipType =
                $this->escapeMermaidLabel(
                    (string)(
                        $relationship[
                            'relationship_type'
                        ] ?? 'related_to'
                    )
                );

            $directional = (
                $relationship['directional']
                ?? true
            ) !== false;

            $connector = $directional
                ? '-->'
                : '---';

            $lines[] = sprintf(
                '    %s %s|%s| %s',
                $sourceId,
                $connector,
                $relationshipType,
                $targetId
            );
        }

        return [
            'content' =>
                implode(
                    $lineEnding,
                    $lines
                )
                . $lineEnding,

            'mime_type' =>
                'text/plain',

            'extension' => 'mmd',
        ];
    }

    /**
     * Serialize graph as PlantUML syntax.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,string>
     */
    private function serializePlantUml(
        array $entities,
        array $relationships,
        array $options
    ): array {
        $lineEnding =
            $options['line_ending'];

        $lines = [
            '@startuml',
            'left to right direction',
            'skinparam shadowing false',
            'skinparam linetype ortho',
        ];

        $nodeIds = [];

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $nodeKey = $this->entityKey(
                $entity
            );

            if ($nodeKey === '') {
                continue;
            }

            $nodeId = $this->diagramIdentifier(
                $nodeKey,
                'N'
            );

            $nodeIds[$nodeKey] = $nodeId;

            $label = $this->escapePlantUmlLabel(
                $this->recordLabel($entity)
            );

            $type = $this->escapePlantUmlLabel(
                $this->resolveEntityType($entity)
            );

            $lines[] = sprintf(
                'rectangle "%s\n[%s]" as %s',
                $label,
                $type,
                $nodeId
            );
        }

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $sourceKey =
                $this->relationshipSourceKey(
                    $relationship
                );

            $targetKey =
                $this->relationshipTargetKey(
                    $relationship
                );

            if (
                $sourceKey === ''
                || $targetKey === ''
            ) {
                continue;
            }

            $sourceId = $nodeIds[$sourceKey]
                ?? $this->diagramIdentifier(
                    $sourceKey,
                    'N'
                );

            $targetId = $nodeIds[$targetKey]
                ?? $this->diagramIdentifier(
                    $targetKey,
                    'N'
                );

            if (!isset($nodeIds[$sourceKey])) {
                $nodeIds[$sourceKey] =
                    $sourceId;

                $lines[] = sprintf(
                    'rectangle "%s" as %s',
                    $this->escapePlantUmlLabel(
                        $sourceKey
                    ),
                    $sourceId
                );
            }

            if (!isset($nodeIds[$targetKey])) {
                $nodeIds[$targetKey] =
                    $targetId;

                $lines[] = sprintf(
                    'rectangle "%s" as %s',
                    $this->escapePlantUmlLabel(
                        $targetKey
                    ),
                    $targetId
                );
            }

            $relationshipType =
                $this->escapePlantUmlLabel(
                    (string)(
                        $relationship[
                            'relationship_type'
                        ] ?? 'related_to'
                    )
                );

            $connector = (
                $relationship['directional']
                ?? true
            ) === false
                ? '--'
                : '-->';

            $lines[] = sprintf(
                '%s %s %s : %s',
                $sourceId,
                $connector,
                $targetId,
                $relationshipType
            );
        }

        $lines[] = '@enduml';

        return [
            'content' =>
                implode(
                    $lineEnding,
                    $lines
                )
                . $lineEnding,

            'mime_type' =>
                'text/plain',

            'extension' => 'puml',
        ];
    }

    /**
     * Prepare one entity according to the active export profile.
     *
     * @param array<string,mixed> $entity
     * @return array<string,mixed>
     */
    private function prepareEntity(
        array $entity,
        array $options
    ): array {
        $profile = $options['profile'];

        $prepared = $this->applyProfileToRecord(
            $entity,
            $profile,
            'entity',
            $options
        );

        if (
            $options['entity_fields']
            !== []
        ) {
            $prepared =
                $this->selectFields(
                    $prepared,
                    $options['entity_fields']
                );
        }

        $prepared = $this->excludeFields(
            $prepared,
            $options['exclude_fields']
        );

        if (
            !$options['include_metadata']
        ) {
            unset($prepared['metadata']);
        }

        if (
            !$options['include_provenance']
        ) {
            $prepared =
                $this->removeProvenanceFields(
                    $prepared
                );
        }

        if (
            !$options['include_versions']
        ) {
            $prepared =
                $this->removeVersionFields(
                    $prepared
                );
        }

        if (
            !$options['include_translations']
        ) {
            $prepared =
                $this->removeTranslationFields(
                    $prepared
                );
        }

        if ($options['normalize_dates']) {
            $prepared =
                $this->normalizeRecordDates(
                    $prepared
                );
        }

        $prepared = $this->removeEmptyValues(
            $prepared,
            $options
        );

        if ($options['sort_keys']) {
            $prepared = $this->sortRecursive(
                $prepared
            );
        }

        return $prepared;
    }

    /**
     * Prepare one relationship according to the active export profile.
     *
     * @param array<string,mixed> $relationship
     * @return array<string,mixed>
     */
    private function prepareRelationship(
        array $relationship,
        array $options
    ): array {
        $profile = $options['profile'];

        $prepared = $this->applyProfileToRecord(
            $relationship,
            $profile,
            'relationship',
            $options
        );

        if (
            $options['relationship_fields']
            !== []
        ) {
            $prepared =
                $this->selectFields(
                    $prepared,
                    $options[
                        'relationship_fields'
                    ]
                );
        }

        $prepared = $this->excludeFields(
            $prepared,
            $options['exclude_fields']
        );

        if (
            !$options['include_metadata']
        ) {
            unset($prepared['metadata']);
        }

        if (
            !$options['include_provenance']
        ) {
            $prepared =
                $this->removeProvenanceFields(
                    $prepared
                );
        }

        if (
            !$options['include_versions']
        ) {
            $prepared =
                $this->removeVersionFields(
                    $prepared
                );
        }

        if (
            !$options['include_translations']
        ) {
            $prepared =
                $this->removeTranslationFields(
                    $prepared
                );
        }

        if ($options['normalize_dates']) {
            $prepared =
                $this->normalizeRecordDates(
                    $prepared
                );
        }

        $prepared = $this->removeEmptyValues(
            $prepared,
            $options
        );

        if ($options['sort_keys']) {
            $prepared = $this->sortRecursive(
                $prepared
            );
        }

        return $prepared;
    }

    /**
     * Apply one export profile to an arbitrary value.
     */
    private function applyProfileToValue(
        mixed $value,
        string $profile,
        array $options
    ): mixed {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $result = [];

            foreach ($value as $item) {
                $result[] =
                    $this->applyProfileToValue(
                        $item,
                        $profile,
                        $options
                    );
            }

            return $result;
        }

        $recordType = isset(
            $value['relationship_id']
        )
            || (
                isset($value['source_id'])
                && isset($value['target_id'])
            )
                ? 'relationship'
                : 'entity';

        $result = $this->applyProfileToRecord(
            $value,
            $profile,
            $recordType,
            $options
        );

        foreach ($result as $key => $item) {
            if (is_array($item)) {
                $result[$key] =
                    $this->applyProfileToValue(
                        $item,
                        $profile,
                        $options
                    );
            }
        }

        return $this->removeEmptyValues(
            $result,
            $options
        );
    }

    /**
     * Apply one profile to a record.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function applyProfileToRecord(
        array $record,
        string $profile,
        string $recordType,
        array $options
    ): array {
        $prepared = $record;

        switch ($profile) {
            case 'public':
                if (
                    !$options[
                        'include_private_fields'
                    ]
                ) {
                    $prepared =
                        $this->excludeFields(
                            $prepared,
                            $this->publicExcludedFields
                        );
                }

                if (
                    !$options[
                        'include_diagnostics'
                    ]
                ) {
                    $prepared =
                        $this->excludeFields(
                            $prepared,
                            $this->diagnosticFields
                        );
                }
                break;

            case 'private':
                if (
                    !$options[
                        'include_diagnostics'
                    ]
                ) {
                    $prepared =
                        $this->excludeFields(
                            $prepared,
                            $this->diagnosticFields
                        );
                }
                break;

            case 'minimal':
                $fields = $recordType
                    === 'relationship'
                        ? $this
                            ->minimalRelationshipFields
                        : $this
                            ->minimalEntityFields;

                $prepared =
                    $this->selectFields(
                        $prepared,
                        $fields
                    );
                break;

            case 'archival':
                $prepared[
                    'export_archival_context'
                ] = [
                    'exported_at' =>
                        gmdate('c'),

                    'record_checksum' =>
                        $this->recordChecksum(
                            $prepared
                        ),
                ];
                break;

            case 'diagnostic':
                $prepared[
                    'export_diagnostic_context'
                ] = [
                    'record_type' =>
                        $recordType,

                    'record_checksum' =>
                        $this->recordChecksum(
                            $prepared
                        ),

                    'field_count' =>
                        count($prepared),

                    'exported_at' =>
                        gmdate('c'),
                ];
                break;

            case 'full':
            default:
                if (
                    !$options[
                        'include_diagnostics'
                    ]
                ) {
                    $prepared =
                        $this->excludeFields(
                            $prepared,
                            $this->diagnosticFields
                        );
                }
                break;
        }

        return $prepared;
    }

    /**
     * Determine whether one entity passes export filters.
     *
     * @param array<string,mixed> $entity
     */
    private function entityPassesFilters(
        array $entity,
        array $options
    ): bool {
        $entityTypes =
            $options['entity_types'];

        if ($entityTypes !== []) {
            $entityType =
                $this->resolveEntityType(
                    $entity
                );

            if (
                !in_array(
                    $entityType,
                    $entityTypes,
                    true
                )
            ) {
                return false;
            }
        }

        $statuses = $options['statuses'];

        if ($statuses !== []) {
            $status =
                $this->normalizeMachineKey(
                    (string)(
                        $entity['status']
                        ?? ''
                    )
                );

            if (
                !in_array(
                    $status,
                    $statuses,
                    true
                )
            ) {
                return false;
            }
        }

        if (
            isset($options['entity_filter'])
            && is_callable(
                $options['entity_filter']
            )
        ) {
            return (
                $options['entity_filter']
            )($entity) === true;
        }

        return true;
    }

    /**
     * Determine whether one relationship passes export filters.
     *
     * @param array<string,mixed> $relationship
     */
    private function relationshipPassesFilters(
        array $relationship,
        array $options
    ): bool {
        $types =
            $options['relationship_types'];

        if ($types !== []) {
            $type =
                $this->normalizeMachineKey(
                    (string)(
                        $relationship[
                            'relationship_type'
                        ] ?? ''
                    )
                );

            if (
                !in_array(
                    $type,
                    $types,
                    true
                )
            ) {
                return false;
            }
        }

        $statuses = $options['statuses'];

        if ($statuses !== []) {
            $status =
                $this->normalizeMachineKey(
                    (string)(
                        $relationship['status']
                        ?? ''
                    )
                );

            if (
                !in_array(
                    $status,
                    $statuses,
                    true
                )
            ) {
                return false;
            }
        }

        if (
            !$options[
                'include_expired_relationships'
            ]
            && !$this->relationshipIsActive(
                $relationship
            )
        ) {
            return false;
        }

        if (
            isset(
                $options[
                    'relationship_filter'
                ]
            )
            && is_callable(
                $options[
                    'relationship_filter'
                ]
            )
        ) {
            return (
                $options[
                    'relationship_filter'
                ]
            )($relationship) === true;
        }

        return true;
    }

    /**
     * Build export statistics.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    private function buildStatistics(
        array $entities,
        array $relationships
    ): array {
        $entityTypes = [];
        $relationshipTypes = [];
        $statuses = [];
        $languages = [];

        $entityFieldCount = 0;
        $relationshipFieldCount = 0;

        $provenanceCount = 0;
        $versionCount = 0;
        $translationCount = 0;

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $entityType =
                $this->resolveEntityType(
                    $entity
                );

            $entityTypes[$entityType] =
                ($entityTypes[$entityType] ?? 0)
                + 1;

            $status =
                $this->normalizeMachineKey(
                    (string)(
                        $entity['status']
                        ?? ''
                    )
                );

            if ($status !== '') {
                $statuses[$status] =
                    ($statuses[$status] ?? 0)
                    + 1;
            }

            $language =
                $this->normalizeMachineKey(
                    (string)(
                        $entity['language']
                        ?? ''
                    )
                );

            if ($language !== '') {
                $languages[$language] =
                    ($languages[$language] ?? 0)
                    + 1;
            }

            $entityFieldCount +=
                count($entity);

            if (
                $this->recordHasProvenance(
                    $entity
                )
            ) {
                $provenanceCount++;
            }

            if (
                isset($entity['version'])
                && trim(
                    (string)$entity['version']
                ) !== ''
            ) {
                $versionCount++;
            }

            if (
                isset(
                    $entity[
                        'source_language'
                    ]
                )
                || isset(
                    $entity[
                        'target_language'
                    ]
                )
                || isset(
                    $entity[
                        'translation_id'
                    ]
                )
            ) {
                $translationCount++;
            }
        }

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $type =
                $this->normalizeMachineKey(
                    (string)(
                        $relationship[
                            'relationship_type'
                        ] ?? 'related_to'
                    )
                );

            $relationshipTypes[$type] =
                (
                    $relationshipTypes[$type]
                    ?? 0
                ) + 1;

            $status =
                $this->normalizeMachineKey(
                    (string)(
                        $relationship['status']
                        ?? ''
                    )
                );

            if ($status !== '') {
                $statuses[$status] =
                    ($statuses[$status] ?? 0)
                    + 1;
            }

            $relationshipFieldCount +=
                count($relationship);

            if (
                $this->recordHasProvenance(
                    $relationship
                )
            ) {
                $provenanceCount++;
            }

            if (
                isset($relationship['version'])
                && trim(
                    (string)(
                        $relationship['version']
                    )
                ) !== ''
            ) {
                $versionCount++;
            }
        }

        arsort($entityTypes);
        arsort($relationshipTypes);
        arsort($statuses);
        arsort($languages);

        $entityCount = count($entities);
        $relationshipCount =
            count($relationships);

        return [
            'entity_count' =>
                $entityCount,

            'relationship_count' =>
                $relationshipCount,

            'record_count' =>
                $entityCount
                + $relationshipCount,

            'entity_types' =>
                $entityTypes,

            'relationship_types' =>
                $relationshipTypes,

            'statuses' => $statuses,

            'languages' => $languages,

            'average_entity_field_count' =>
                $entityCount > 0
                    ? round(
                        $entityFieldCount
                        / $entityCount,
                        2
                    )
                    : 0.0,

            'average_relationship_field_count' =>
                $relationshipCount > 0
                    ? round(
                        $relationshipFieldCount
                        / $relationshipCount,
                        2
                    )
                    : 0.0,

            'records_with_provenance' =>
                $provenanceCount,

            'records_with_versions' =>
                $versionCount,

            'translation_record_count' =>
                $translationCount,

            'density_estimate' =>
                $entityCount > 1
                    ? round(
                        $relationshipCount
                        / (
                            $entityCount
                            * (
                                $entityCount - 1
                            )
                        ),
                        8
                    )
                    : 0.0,
        ];
    }

    /**
     * Build export manifest.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    private function buildManifest(
        array $entities,
        array $relationships,
        string $format,
        string $profile,
        array $options,
        array $statistics
    ): array {
        $entityChecksum =
            $this->collectionChecksum(
                $entities
            );

        $relationshipChecksum =
            $this->collectionChecksum(
                $relationships
            );

        return [
            'export_id' =>
                $this->generateExportId(),

            'schema' =>
                'ipmdb_graph_export_manifest',

            'schema_version' =>
                '1.0',

            'generated_at' =>
                gmdate('c'),

            'generator' =>
                static::class,

            'format' => $format,

            'profile' => $profile,

            'entity_count' =>
                count($entities),

            'relationship_count' =>
                count($relationships),

            'entity_checksum' =>
                $entityChecksum,

            'relationship_checksum' =>
                $relationshipChecksum,

            'graph_checksum' => hash(
                'sha256',
                $entityChecksum
                . '|'
                . $relationshipChecksum
            ),

            'checksum_algorithm' =>
                'sha256',

            'statistics' =>
                $options[
                    'include_statistics'
                ]
                    ? $statistics
                    : null,

            'options' => [
                'deterministic' =>
                    $options['deterministic'],

                'include_metadata' =>
                    $options[
                        'include_metadata'
                    ],

                'include_provenance' =>
                    $options[
                        'include_provenance'
                    ],

                'include_versions' =>
                    $options[
                        'include_versions'
                    ],

                'include_translations' =>
                    $options[
                        'include_translations'
                    ],

                'include_private_fields' =>
                    $options[
                        'include_private_fields'
                    ],

                'include_diagnostics' =>
                    $options[
                        'include_diagnostics'
                    ],
            ],
        ];
    }

    /**
     * Build export filename.
     */
    private function buildFilename(
        string $format,
        array $options
    ): string {
        $baseName = trim(
            (string)(
                $options['filename']
                ?? $options['base_filename']
                ?? 'ipmdb-graph'
            )
        );

        if ($baseName === '') {
            $baseName = 'ipmdb-graph';
        }

        $baseName = preg_replace(
            '/[^A-Za-z0-9._-]+/',
            '-',
            $baseName
        ) ?? 'ipmdb-graph';

        $baseName = trim(
            $baseName,
            '.-_'
        );

        if ($baseName === '') {
            $baseName = 'ipmdb-graph';
        }

        $includeTimestamp = (bool)(
            $options['filename_timestamp']
            ?? true
        );

        if ($includeTimestamp) {
            $baseName .= '-'
                . gmdate('Ymd-His');
        }

        $extension =
            $this->extension($format);

        if (
            !str_ends_with(
                strtolower($baseName),
                '.'
                . strtolower($extension)
            )
        ) {
            $baseName .= '.'
                . $extension;
        }

        return $baseName;
    }

    /**
     * Return MIME type for one format.
     */
    private function mimeType(
        string $format
    ): string {
        return match ($format) {
            'ipmdb',
            'json',
            'csv_bundle',
            'cytoscape',
            'd3',
            'neo4j',
            'adjacency_list' =>
                'application/json',

            'ndjson' =>
                'application/x-ndjson',

            'csv_entities',
            'csv_relationships' =>
                'text/csv',

            'graphml' =>
                'application/graphml+xml',

            'gexf' =>
                'application/gexf+xml',

            'edge_list',
            'mermaid',
            'plantuml' =>
                'text/plain',

            default =>
                'application/octet-stream',
        };
    }

    /**
     * Return file extension for one format.
     */
    private function extension(
        string $format
    ): string {
        return match ($format) {
            'ipmdb',
            'json',
            'csv_bundle',
            'cytoscape',
            'd3',
            'neo4j' =>
                'json',

            'ndjson' =>
                'ndjson',

            'csv_entities',
            'csv_relationships' =>
                'csv',

            'edge_list' =>
                'txt',

            'adjacency_list' =>
                'json',

            'graphml' =>
                'graphml',

            'gexf' =>
                'gexf',

            'mermaid' =>
                'mmd',

            'plantuml' =>
                'puml',

            default =>
                'dat',
        };
    }

    /**
     * Recursively sort associative keys while preserving list order.
     */
    private function sortRecursive(
        mixed $value
    ): mixed {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed =>
                    $this->sortRecursive(
                        $item
                    ),
                $value
            );
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] =
                $this->sortRecursive(
                    $item
                );
        }

        return $value;
    }

    /**
     * Select named fields from one record.
     *
     * @param array<string,mixed> $record
     * @param array<int,string> $fields
     * @return array<string,mixed>
     */
    private function selectFields(
        array $record,
        array $fields
    ): array {
        $selected = [];

        foreach ($fields as $field) {
            if (
                array_key_exists(
                    $field,
                    $record
                )
            ) {
                $selected[$field] =
                    $record[$field];
            }
        }

        return $selected;
    }

    /**
     * Remove named fields recursively by field name.
     *
     * @param array<string,mixed> $record
     * @param array<int,string> $fields
     * @return array<string,mixed>
     */
    private function excludeFields(
        array $record,
        array $fields
    ): array {
        if ($fields === []) {
            return $record;
        }

        $fieldMap = array_fill_keys(
            array_map(
                fn (string $field): string =>
                    $this->normalizeMachineKey(
                        $field
                    ),
                $fields
            ),
            true
        );

        foreach ($record as $key => $value) {
            $normalizedKey =
                $this->normalizeMachineKey(
                    (string)$key
                );

            if (isset($fieldMap[$normalizedKey])) {
                unset($record[$key]);
                continue;
            }

            if (is_array($value)) {
                if (array_is_list($value)) {
                    foreach (
                        $value
                        as $index => $item
                    ) {
                        if (is_array($item)) {
                            $value[$index] =
                                $this->excludeFields(
                                    $item,
                                    $fields
                                );
                        }
                    }

                    $record[$key] = $value;
                } else {
                    $record[$key] =
                        $this->excludeFields(
                            $value,
                            $fields
                        );
                }
            }
        }

        return $record;
    }

    /**
     * Remove provenance-related fields.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function removeProvenanceFields(
        array $record
    ): array {
        return $this->excludeFields(
            $record,
            [
                'provenance_id',
                'source_reference',
                'source_url',
                'source_entity_id',
                'source_hash',
                'source_document_id',
                'captured_at',
                'evidence',
                'citations',
            ]
        );
    }

    /**
     * Remove version-related fields.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function removeVersionFields(
        array $record
    ): array {
        return $this->excludeFields(
            $record,
            [
                'version',
                'version_id',
                'version_of',
                'previous_version_id',
                'next_version_id',
                'revision',
                'revision_id',
                'supersedes_id',
            ]
        );
    }

    /**
     * Remove translation-related fields.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function removeTranslationFields(
        array $record
    ): array {
        return $this->excludeFields(
            $record,
            [
                'translation_id',
                'source_language',
                'target_language',
                'translator_id',
                'translator_email',
                'translated_at',
                'translation_status',
                'translation_notes',
            ]
        );
    }

    /**
     * Normalize date-like fields recursively.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function normalizeRecordDates(
        array $record
    ): array {
        foreach ($record as $key => $value) {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    foreach (
                        $value
                        as $index => $item
                    ) {
                        if (is_array($item)) {
                            $value[$index] =
                                $this
                                    ->normalizeRecordDates(
                                        $item
                                    );
                        }
                    }

                    $record[$key] = $value;
                } else {
                    $record[$key] =
                        $this->normalizeRecordDates(
                            $value
                        );
                }

                continue;
            }

            if (
                !is_string($value)
                || !$this->fieldLooksLikeDate(
                    (string)$key
                )
                || trim($value) === ''
            ) {
                continue;
            }

            $timestamp = strtotime($value);

            if ($timestamp !== false) {
                $record[$key] =
                    gmdate('c', $timestamp);
            }
        }

        return $record;
    }

    /**
     * Determine whether a field name represents a date.
     */
    private function fieldLooksLikeDate(
        string $field
    ): bool {
        $field = $this->normalizeMachineKey(
            $field
        );

        return str_ends_with(
            $field,
            '_at'
        )
            || str_ends_with(
                $field,
                '_date'
            )
            || in_array(
                $field,
                [
                    'date',
                    'valid_from',
                    'valid_to',
                    'effective_from',
                    'effective_to',
                    'published',
                    'created',
                    'updated',
                ],
                true
            );
    }

    /**
     * Remove empty and null values according to export options.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function removeEmptyValues(
        array $record,
        array $options
    ): array {
        foreach ($record as $key => $value) {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    $cleanedList = [];

                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $item =
                                $this->removeEmptyValues(
                                    $item,
                                    $options
                                );
                        }

                        if (
                            !$options[
                                'include_empty_values'
                            ]
                            && $item === []
                        ) {
                            continue;
                        }

                        if (
                            !$options[
                                'include_null_values'
                            ]
                            && $item === null
                        ) {
                            continue;
                        }

                        $cleanedList[] = $item;
                    }

                    $value = $cleanedList;
                } else {
                    $value =
                        $this->removeEmptyValues(
                            $value,
                            $options
                        );
                }

                if (
                    !$options[
                        'include_empty_values'
                    ]
                    && $value === []
                ) {
                    unset($record[$key]);
                    continue;
                }

                $record[$key] = $value;
                continue;
            }

            if (
                $value === null
                && !$options[
                    'include_null_values'
                ]
            ) {
                unset($record[$key]);
                continue;
            }

            if (
                !$options[
                    'include_empty_values'
                ]
                && (
                    $value === ''
                    || (
                        is_string($value)
                        && trim($value) === ''
                    )
                )
            ) {
                unset($record[$key]);
            }
        }

        return $record;
    }

    /**
     * Determine whether a relationship is temporally active.
     *
     * @param array<string,mixed> $relationship
     */
    private function relationshipIsActive(
        array $relationship
    ): bool {
        $now = time();

        $validFrom = trim(
            (string)(
                $relationship['valid_from']
                ?? ''
            )
        );

        if ($validFrom !== '') {
            $timestamp = strtotime(
                $validFrom
            );

            if (
                $timestamp !== false
                && $timestamp > $now
            ) {
                return false;
            }
        }

        $validTo = trim(
            (string)(
                $relationship['valid_to']
                ?? ''
            )
        );

        if ($validTo !== '') {
            $timestamp = strtotime(
                $validTo
            );

            if (
                $timestamp !== false
                && $timestamp < $now
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine whether an entity collection already contains a key.
     *
     * @param array<int,array<string,mixed>> $entities
     */
    private function entityArrayContains(
        array $entities,
        string $entityKey
    ): bool {
        foreach ($entities as $entity) {
            if (
                is_array($entity)
                && $this->entityKey($entity)
                    === $entityKey
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve entity identifier.
     *
     * @param array<string,mixed> $entity
     */
    private function resolveEntityId(
        array $entity
    ): string {
        foreach (
            [
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
            ]
            as $field
        ) {
            $value = trim(
                (string)(
                    $entity[$field]
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
     * Resolve entity type.
     *
     * @param array<string,mixed> $entity
     */
    private function resolveEntityType(
        array $entity
    ): string {
        $type = $this->normalizeMachineKey(
            (string)(
                $entity['entity_type']
                ?? $entity['type']
                ?? 'entity'
            )
        );

        return $type !== ''
            ? $type
            : 'entity';
    }

    /**
     * Build canonical entity key.
     *
     * @param array<string,mixed> $entity
     */
    private function entityKey(
        array $entity
    ): string {
        $entityId =
            $this->resolveEntityId(
                $entity
            );

        if ($entityId === '') {
            return '';
        }

        return $this->graphNodeKey(
            $this->resolveEntityType(
                $entity
            ),
            $entityId
        );
    }

    /**
     * Build relationship source key.
     *
     * @param array<string,mixed> $relationship
     */
    private function relationshipSourceKey(
        array $relationship
    ): string {
        $sourceId = trim(
            (string)(
                $relationship['source_id']
                ?? ''
            )
        );

        if ($sourceId === '') {
            return '';
        }

        return $this->graphNodeKey(
            $this->normalizeMachineKey(
                (string)(
                    $relationship[
                        'source_type'
                    ] ?? 'entity'
                )
            ),
            $sourceId
        );
    }

    /**
     * Build relationship target key.
     *
     * @param array<string,mixed> $relationship
     */
    private function relationshipTargetKey(
        array $relationship
    ): string {
        $targetId = trim(
            (string)(
                $relationship['target_id']
                ?? ''
            )
        );

        if ($targetId === '') {
            return '';
        }

        return $this->graphNodeKey(
            $this->normalizeMachineKey(
                (string)(
                    $relationship[
                        'target_type'
                    ] ?? 'entity'
                )
            ),
            $targetId
        );
    }

    /**
     * Determine provenance presence.
     *
     * @param array<string,mixed> $record
     */
    private function recordHasProvenance(
        array $record
    ): bool {
        foreach (
            [
                'provenance_id',
                'source_reference',
                'source_url',
                'source_entity_id',
                'source_document_id',
            ]
            as $field
        ) {
            if (
                trim(
                    (string)(
                        $record[$field]
                        ?? ''
                    )
                ) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate one collection checksum.
     *
     * @param array<int,array<string,mixed>> $records
     */
    private function collectionChecksum(
        array $records
    ): string {
        $prepared = $this->sortRecursive(
            $records
        );

        $json = json_encode(
            $prepared,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
        );

        if ($json === false) {
            throw new RuntimeException(
                'Unable to calculate collection checksum.'
            );
        }

        return hash('sha256', $json);
    }

    /**
     * Calculate one record checksum.
     *
     * @param array<string,mixed> $record
     */
    private function recordChecksum(
        array $record
    ): string {
        $copy = $record;

        unset($copy['checksum']);

        $json = json_encode(
            $this->sortRecursive($copy),
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
        );

        if ($json === false) {
            return '';
        }

        return hash('sha256', $json);
    }

    /**
     * Create a stable diagram identifier.
     */
    private function diagramIdentifier(
        string $value,
        string $prefix = 'N'
    ): string {
        return $prefix
            . strtoupper(
                substr(
                    hash(
                        'sha256',
                        $value
                    ),
                    0,
                    12
                )
            );
    }

    /**
     * Escape Mermaid label text.
     */
    private function escapeMermaidLabel(
        string $value
    ): string {
        $value = str_replace(
            [
                '"',
                "\r",
                "\n",
                '[',
                ']',
            ],
            [
                '&quot;',
                ' ',
                ' ',
                '(',
                ')',
            ],
            $value
        );

        return trim($value);
    }

    /**
     * Escape PlantUML label text.
     */
    private function escapePlantUmlLabel(
        string $value
    ): string {
        return trim(
            str_replace(
                [
                    '\\',
                    '"',
                    "\r",
                    "\n",
                ],
                [
                    '\\\\',
                    '\\"',
                    ' ',
                    '\\n',
                ],
                $value
            )
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
            $values = preg_split(
                '/[\r\n,;]+/',
                $values
            ) ?: [];
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
     * Normalize machine-readable key.
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
     * Generate export identifier.
     */
    private function generateExportId(): string
    {
        return 'GEX-'
            . gmdate('Ymd-His')
            . '-'
            . $this->randomToken(6);
    }

    /**
     * Generate random uppercase token.
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
}