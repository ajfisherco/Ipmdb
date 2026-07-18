<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/GraphRepairService.php
|--------------------------------------------------------------------------
| IPMdb Graph Repair Service
|--------------------------------------------------------------------------
|
| Inspects relationship graphs and prepares deterministic repair actions.
|
| Responsibilities:
| - Detect malformed relationships.
| - Detect duplicate edges and identifiers.
| - Detect missing inverse relationships.
| - Detect mismatched inverse relationships.
| - Detect invalid lifecycle and temporal data.
| - Detect missing provenance, attribution, and checksums.
| - Repair safe structural defects.
| - Preserve disputed or ambiguous cases for human review.
|
| This service performs no database operations.
| Repairs are returned as records for repositories to persist.
|
| Repair preserves evidence.
| Ambiguity remains visible.
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/RelationshipService.php';
require_once __DIR__ . '/GraphTraversalService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class GraphRepairService extends Service
{
    use GraphUtilities;

    private RelationshipService $relationships;

    private GraphTraversalService $traversal;

    /**
     * @var array<int,string>
     */
    private array $safeRepairTypes = [
        'recalculate_checksum',
        'normalize_status',
        'normalize_relationship_type',
        'normalize_weight',
        'normalize_strength',
        'normalize_confidence',
        'normalize_metadata',
        'normalize_tags',
        'add_inverse',
        'synchronize_inverse',
        'remove_exact_duplicate',
        'set_updated_at',
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?RelationshipService $relationships = null,
        ?GraphTraversalService $traversal = null
    ) {
        parent::__construct($config, $context);

        $this->relationships = $relationships
            ?? new RelationshipService();

        $this->traversal = $traversal
            ?? new GraphTraversalService();
    }

    /**
     * Inspect an entire relationship graph.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<string,mixed>
     */
    public function inspect(
        array $records,
        array $options = []
    ): array {
        $this->reset();

        $issues = [];

        $relationshipIds = [];
        $edgeKeys = [];

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                $issues[] = $this->issue(
                    'invalid_record',
                    'critical',
                    'Relationship record must be an array.',
                    [
                        'index' => $index,
                    ]
                );

                continue;
            }

            $relationshipId = trim(
                (string)(
                    $record['relationship_id']
                    ?? ''
                )
            );

            if ($relationshipId === '') {
                $issues[] = $this->issue(
                    'missing_relationship_id',
                    'error',
                    'Relationship ID is missing.',
                    [
                        'index' => $index,
                    ],
                    false
                );
            } elseif (isset($relationshipIds[$relationshipId])) {
                $issues[] = $this->issue(
                    'duplicate_relationship_id',
                    'error',
                    'Relationship ID appears more than once.',
                    [
                        'relationship_id' => $relationshipId,
                        'first_index' =>
                            $relationshipIds[$relationshipId],
                        'duplicate_index' => $index,
                    ],
                    false
                );
            } else {
                $relationshipIds[$relationshipId] = $index;
            }

            foreach (
                $this->inspectRelationship(
                    $record,
                    $index
                )
                as $issue
            ) {
                $issues[] = $issue;
            }

            $edgeKey = $this->safeEdgeKey($record);

            if ($edgeKey !== '') {
                if (isset($edgeKeys[$edgeKey])) {
                    $issues[] = $this->issue(
                        'duplicate_edge',
                        'warning',
                        'An equivalent relationship edge already exists.',
                        [
                            'edge_key' => $edgeKey,
                            'first_index' =>
                                $edgeKeys[$edgeKey],
                            'duplicate_index' => $index,
                            'relationship_id' =>
                                $relationshipId,
                        ],
                        true,
                        'remove_exact_duplicate'
                    );
                } else {
                    $edgeKeys[$edgeKey] = $index;
                }
            }
        }

        foreach (
            $this->inspectInverseIntegrity($records)
            as $issue
        ) {
            $issues[] = $issue;
        }

        if (
            (bool)(
                $options['check_cycles']
                ?? true
            )
        ) {
            $cycles = $this->traversal->cycles(
                $records,
                [],
                [],
                true,
                (int)(
                    $options['maximum_cycles']
                    ?? 100
                )
            );

            foreach ($cycles as $cycle) {
                $issues[] = $this->issue(
                    'directed_cycle',
                    'notice',
                    'A directed cycle exists in the graph.',
                    [
                        'cycle' => $cycle,
                    ],
                    false
                );
            }
        }

        $summary = $this->summarizeIssues(
            $issues
        );

        $this->addMessage(
            'Graph inspection completed.',
            [
                'relationship_count' =>
                    count($records),
                'issue_count' =>
                    count($issues),
                'repairable_count' =>
                    $summary['repairable_count'],
            ]
        );

        return [
            'generated_at' => gmdate('c'),
            'relationship_count' =>
                count($records),
            'issue_count' => count($issues),
            'summary' => $summary,
            'issues' => $issues,
        ];
    }

    /**
     * Inspect one relationship record.
     *
     * @param array<string,mixed> $record
     * @return array<int,array<string,mixed>>
     */
    public function inspectRelationship(
        array $record,
        ?int $index = null
    ): array {
        $issues = [];

        $context = [
            'index' => $index,
            'relationship_id' =>
                $record['relationship_id']
                ?? null,
        ];

        $required = [
            'relationship_type',
            'source_id',
            'source_type',
            'target_id',
            'target_type',
            'status',
            'created_by',
            'created_at',
            'updated_at',
        ];

        foreach ($required as $field) {
            if ($this->valueIsEmpty(
                $record[$field] ?? null
            )) {
                $issues[] = $this->issue(
                    'missing_required_field',
                    'error',
                    sprintf(
                        'Required field "%s" is missing.',
                        $field
                    ),
                    array_merge(
                        $context,
                        [
                            'field' => $field,
                        ]
                    ),
                    false
                );
            }
        }

        $relationshipType = trim(
            (string)(
                $record['relationship_type']
                ?? ''
            )
        );

        if ($relationshipType !== '') {
            $normalizedType =
                $this->normalizeRelationshipType(
                    $relationshipType
                );

            if (
                $normalizedType
                !== $relationshipType
            ) {
                $issues[] = $this->issue(
                    'unnormalized_relationship_type',
                    'warning',
                    'Relationship type is not normalized.',
                    array_merge(
                        $context,
                        [
                            'current' =>
                                $relationshipType,
                            'recommended' =>
                                $normalizedType,
                        ]
                    ),
                    true,
                    'normalize_relationship_type'
                );
            }
        }

        $sourceId = trim(
            (string)(
                $record['source_id']
                ?? ''
            )
        );

        $targetId = trim(
            (string)(
                $record['target_id']
                ?? ''
            )
        );

        $sourceType = trim(
            (string)(
                $record['source_type']
                ?? ''
            )
        );

        $targetType = trim(
            (string)(
                $record['target_type']
                ?? ''
            )
        );

        if (
            $sourceId !== ''
            && $targetId !== ''
            && $sourceId === $targetId
            && $sourceType === $targetType
            && !$this->relationships
                ->allowsSelfRelationship(
                    $relationshipType
                )
        ) {
            $issues[] = $this->issue(
                'invalid_self_relationship',
                'error',
                'This relationship type cannot connect an entity to itself.',
                $context,
                false
            );
        }

        $status = trim(
            (string)(
                $record['status']
                ?? ''
            )
        );

        if ($status !== '') {
            $normalizedStatus =
                $this->normalizeStatus($status);

            if ($normalizedStatus !== $status) {
                $issues[] = $this->issue(
                    'unnormalized_status',
                    'warning',
                    'Relationship status is not canonical.',
                    array_merge(
                        $context,
                        [
                            'current' => $status,
                            'recommended' =>
                                $normalizedStatus,
                        ]
                    ),
                    true,
                    'normalize_status'
                );
            }
        }

        $issues = array_merge(
            $issues,
            $this->inspectNumericField(
                $record,
                'confidence',
                0,
                100,
                'normalize_confidence',
                $context
            ),
            $this->inspectNumericField(
                $record,
                'weight',
                0,
                1,
                'normalize_weight',
                $context
            ),
            $this->inspectNumericField(
                $record,
                'strength',
                0,
                1,
                'normalize_strength',
                $context
            )
        );

        if (
            isset($record['metadata'])
            && !is_array($record['metadata'])
        ) {
            $issues[] = $this->issue(
                'invalid_metadata',
                'warning',
                'Relationship metadata must be an array.',
                $context,
                true,
                'normalize_metadata'
            );
        }

        if (
            isset($record['tags'])
            && !is_array($record['tags'])
        ) {
            $issues[] = $this->issue(
                'invalid_tags',
                'warning',
                'Relationship tags must be an array.',
                $context,
                true,
                'normalize_tags'
            );
        }

        $createdAt = trim(
            (string)(
                $record['created_at']
                ?? ''
            )
        );

        $updatedAt = trim(
            (string)(
                $record['updated_at']
                ?? ''
            )
        );

        if (
            $createdAt !== ''
            && strtotime($createdAt) === false
        ) {
            $issues[] = $this->issue(
                'invalid_created_at',
                'error',
                'Created timestamp is invalid.',
                $context,
                false
            );
        }

        if (
            $updatedAt !== ''
            && strtotime($updatedAt) === false
        ) {
            $issues[] = $this->issue(
                'invalid_updated_at',
                'error',
                'Updated timestamp is invalid.',
                $context,
                true,
                'set_updated_at'
            );
        }

        $validFrom = trim(
            (string)(
                $record['valid_from']
                ?? ''
            )
        );

        $validTo = trim(
            (string)(
                $record['valid_to']
                ?? ''
            )
        );

        if (
            $validFrom !== ''
            && strtotime($validFrom) === false
        ) {
            $issues[] = $this->issue(
                'invalid_valid_from',
                'error',
                'Valid-from timestamp is invalid.',
                $context,
                false
            );
        }

        if (
            $validTo !== ''
            && strtotime($validTo) === false
        ) {
            $issues[] = $this->issue(
                'invalid_valid_to',
                'error',
                'Valid-to timestamp is invalid.',
                $context,
                false
            );
        }

        if (
            $validFrom !== ''
            && $validTo !== ''
            && strtotime($validFrom) !== false
            && strtotime($validTo) !== false
            && strtotime($validTo)
                < strtotime($validFrom)
        ) {
            $issues[] = $this->issue(
                'inverted_validity_window',
                'error',
                'Valid-to precedes valid-from.',
                $context,
                false
            );
        }

        if (
            trim(
                (string)(
                    $record['created_by']
                    ?? ''
                )
            ) === ''
        ) {
            $issues[] = $this->issue(
                'missing_attribution',
                'error',
                'Relationship creator attribution is missing.',
                $context,
                false
            );
        }

        if (
            trim(
                (string)(
                    $record['provenance_id']
                    ?? ''
                )
            ) === ''
        ) {
            $issues[] = $this->issue(
                'missing_provenance',
                'warning',
                'Relationship provenance is missing.',
                $context,
                false
            );
        }

        $storedChecksum = trim(
            (string)(
                $record['checksum']
                ?? ''
            )
        );

        if ($storedChecksum === '') {
            $issues[] = $this->issue(
                'missing_checksum',
                'warning',
                'Relationship checksum is missing.',
                $context,
                true,
                'recalculate_checksum'
            );
        } elseif (
            !$this->relationshipChecksumMatches(
                $record
            )
        ) {
            $issues[] = $this->issue(
                'checksum_mismatch',
                'error',
                'Relationship checksum does not match its content.',
                $context,
                true,
                'recalculate_checksum'
            );
        }

        if (
            ($record['suggested_by_ai'] ?? false)
            === true
            && (
                $record['accepted_by_human']
                ?? false
            ) === false
            && in_array(
                $status,
                ['active', 'verified'],
                true
            )
        ) {
            $issues[] = $this->issue(
                'unaccepted_ai_relationship',
                'warning',
                'AI-suggested relationship is active without recorded human acceptance.',
                $context,
                false
            );
        }

        return $issues;
    }

    /**
     * Repair all safe defects.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<string,mixed>
     */
    public function repairSafe(
        array $records,
        string $actorId = 'system',
        array $options = []
    ): array {
        $this->reset();

        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Repair actor attribution is required.'
            );
        }

        $inspection = $this->inspect(
            $records,
            array_merge(
                $options,
                [
                    'check_cycles' => false,
                ]
            )
        );

        $repaired = [];
        $actions = [];
        $seenEdges = [];

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                $actions[] = [
                    'action' => 'skip_invalid_record',
                    'index' => $index,
                    'applied' => false,
                ];

                continue;
            }

            $recordActions = [];

            $record = $this->repairRecord(
                $record,
                $actorId,
                $recordActions
            );

            $edgeKey = $this->safeEdgeKey(
                $record
            );

            if (
                $edgeKey !== ''
                && isset($seenEdges[$edgeKey])
            ) {
                $actions[] = [
                    'action' =>
                        'remove_exact_duplicate',
                    'index' => $index,
                    'relationship_id' =>
                        $record['relationship_id']
                        ?? null,
                    'duplicate_of_index' =>
                        $seenEdges[$edgeKey],
                    'applied' => true,
                ];

                continue;
            }

            if ($edgeKey !== '') {
                $seenEdges[$edgeKey] = $index;
            }

            foreach ($recordActions as $action) {
                $actions[] = array_merge(
                    [
                        'index' => $index,
                        'relationship_id' =>
                            $record[
                                'relationship_id'
                            ] ?? null,
                    ],
                    $action
                );
            }

            $repaired[] = $record;
        }

        if (
            (bool)(
                $options['create_missing_inverses']
                ?? true
            )
        ) {
            $inverseResult =
                $this->addMissingInverses(
                    $repaired,
                    $actorId
                );

            $repaired =
                $inverseResult['relationships'];

            $actions = array_merge(
                $actions,
                $inverseResult['actions']
            );
        }

        $this->addMessage(
            'Safe graph repair completed.',
            [
                'original_count' =>
                    count($records),
                'repaired_count' =>
                    count($repaired),
                'action_count' =>
                    count($actions),
            ]
        );

        return [
            'generated_at' => gmdate('c'),
            'actor_id' => $actorId,
            'original_count' =>
                count($records),
            'repaired_count' =>
                count($repaired),
            'inspection' => $inspection,
            'actions' => $actions,
            'relationships' => $repaired,
        ];
    }

    /**
     * Repair one record using safe deterministic corrections.
     *
     * @param array<string,mixed> $record
     * @param array<int,array<string,mixed>> $actions
     * @return array<string,mixed>
     */
    public function repairRecord(
        array $record,
        string $actorId,
        array &$actions = []
    ): array {
        $actorId = trim($actorId);

        $relationshipType = trim(
            (string)(
                $record['relationship_type']
                ?? ''
            )
        );

        $normalizedType =
            $this->normalizeRelationshipType(
                $relationshipType
            );

        if ($relationshipType !== $normalizedType) {
            $record['relationship_type'] =
                $normalizedType;

            $record['inverse_type'] =
                $this->relationships
                    ->inverseType(
                        $normalizedType
                    );

            $actions[] = $this->repairAction(
                'normalize_relationship_type',
                [
                    'before' =>
                        $relationshipType,
                    'after' =>
                        $normalizedType,
                ]
            );
        }

        $status = trim(
            (string)(
                $record['status']
                ?? ''
            )
        );

        $normalizedStatus =
            $this->normalizeStatus($status);

        if ($status !== $normalizedStatus) {
            $record['status'] =
                $normalizedStatus;

            $actions[] = $this->repairAction(
                'normalize_status',
                [
                    'before' => $status,
                    'after' =>
                        $normalizedStatus,
                ]
            );
        }

        $record = $this->repairNumericField(
            $record,
            'confidence',
            100.0,
            0,
            100,
            'normalize_confidence',
            $actions
        );

        $record = $this->repairNumericField(
            $record,
            'weight',
            1.0,
            0,
            1,
            'normalize_weight',
            $actions
        );

        $record = $this->repairNumericField(
            $record,
            'strength',
            1.0,
            0,
            1,
            'normalize_strength',
            $actions
        );

        if (!is_array(
            $record['metadata']
            ?? null
        )) {
            $before =
                $record['metadata']
                ?? null;

            $record['metadata'] = [];

            $actions[] = $this->repairAction(
                'normalize_metadata',
                [
                    'before' => $before,
                    'after' => [],
                ]
            );
        }

        if (!is_array(
            $record['tags']
            ?? null
        )) {
            $before =
                $record['tags']
                ?? null;

            $record['tags'] =
                $this->normalizeStringList(
                    $before
                );

            $actions[] = $this->repairAction(
                'normalize_tags',
                [
                    'before' => $before,
                    'after' =>
                        $record['tags'],
                ]
            );
        } else {
            $normalizedTags =
                $this->normalizeStringList(
                    $record['tags']
                );

            if (
                $normalizedTags
                !== $record['tags']
            ) {
                $actions[] =
                    $this->repairAction(
                        'normalize_tags',
                        [
                            'before' =>
                                $record['tags'],
                            'after' =>
                                $normalizedTags,
                        ]
                    );

                $record['tags'] =
                    $normalizedTags;
            }
        }

        $updatedAt = trim(
            (string)(
                $record['updated_at']
                ?? ''
            )
        );

        if (
            $updatedAt === ''
            || strtotime($updatedAt) === false
        ) {
            $record['updated_at'] =
                gmdate('Y-m-d H:i:s');

            $actions[] = $this->repairAction(
                'set_updated_at',
                [
                    'before' => $updatedAt,
                    'after' =>
                        $record['updated_at'],
                ]
            );
        }

        $record['metadata']['last_repaired_by'] =
            $actorId;

        $record['metadata']['last_repaired_at'] =
            gmdate('c');

        $record['checksum'] =
            $this->relationshipChecksum(
                $record
            );

        $actions[] = $this->repairAction(
            'recalculate_checksum',
            [
                'after' =>
                    $record['checksum'],
            ]
        );

        return $record;
    }

    /**
     * Create missing inverse edges.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array{
     *     relationships:array<int,array<string,mixed>>,
     *     actions:array<int,array<string,mixed>>
     * }
     */
    public function addMissingInverses(
        array $records,
        string $actorId = 'system'
    ): array {
        $actorId = trim($actorId);

        $result = array_values($records);
        $actions = [];

        $edgeIndex = [];

        foreach ($result as $index => $record) {
            if (!is_array($record)) {
                continue;
            }

            $key = $this->safeEdgeKey($record);

            if ($key !== '') {
                $edgeIndex[$key] = $index;
            }
        }

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            if (
                ($record['directional'] ?? true)
                === false
            ) {
                continue;
            }

            $relationshipType = trim(
                (string)(
                    $record[
                        'relationship_type'
                    ]
                    ?? ''
                )
            );

            if (
                !$this->relationships
                    ->hasInverse(
                        $relationshipType
                    )
            ) {
                continue;
            }

            $inverse =
                $this->relationships
                    ->createInverse($record);

            if ($inverse === null) {
                continue;
            }

            $inverseKey =
                $this->safeEdgeKey(
                    $inverse
                );

            if (
                $inverseKey === ''
                || isset(
                    $edgeIndex[$inverseKey]
                )
            ) {
                continue;
            }

            $inverse['created_by'] =
                trim(
                    (string)(
                        $inverse['created_by']
                        ?? $actorId
                    )
                );

            if ($inverse['created_by'] === '') {
                $inverse['created_by'] =
                    $actorId;
            }

            $inverse['metadata'] =
                is_array(
                    $inverse['metadata']
                    ?? null
                )
                    ? $inverse['metadata']
                    : [];

            $inverse['metadata']
                ['generated_as_inverse'] =
                true;

            $inverse['metadata']
                ['inverse_of_relationship_id'] =
                $record['relationship_id']
                ?? null;

            $inverse['metadata']
                ['generated_by'] =
                static::class;

            $inverse['metadata']
                ['generated_at'] =
                gmdate('c');

            $inverse['checksum'] =
                $this->relationshipChecksum(
                    $inverse
                );

            $edgeIndex[$inverseKey] =
                count($result);

            $result[] = $inverse;

            $actions[] = [
                'action' => 'add_inverse',
                'relationship_id' =>
                    $inverse[
                        'relationship_id'
                    ] ?? null,
                'inverse_of_relationship_id' =>
                    $record[
                        'relationship_id'
                    ] ?? null,
                'applied' => true,
            ];
        }

        return [
            'relationships' => $result,
            'actions' => $actions,
        ];
    }

    /**
     * Synchronize existing inverse pairs.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<string,mixed>
     */
    public function synchronizeInverses(
        array $records
    ): array {
        $result = array_values($records);
        $actions = [];
        $processed = [];

        foreach ($result as $leftIndex => $left) {
            if (!is_array($left)) {
                continue;
            }

            $leftId = trim(
                (string)(
                    $left['relationship_id']
                    ?? ''
                )
            );

            if (
                $leftId !== ''
                && isset($processed[$leftId])
            ) {
                continue;
            }

            foreach (
                $result
                as $rightIndex => $right
            ) {
                if (
                    $leftIndex === $rightIndex
                    || !is_array($right)
                ) {
                    continue;
                }

                if (
                    !$this->relationships
                        ->areInverse(
                            $left,
                            $right
                        )
                ) {
                    continue;
                }

                $synchronized =
                    $this->relationships
                        ->synchronizeInverse(
                            $left,
                            $right
                        );

                $result[$rightIndex] =
                    $synchronized;

                $rightId = trim(
                    (string)(
                        $right[
                            'relationship_id'
                        ]
                        ?? ''
                    )
                );

                if ($leftId !== '') {
                    $processed[$leftId] =
                        true;
                }

                if ($rightId !== '') {
                    $processed[$rightId] =
                        true;
                }

                $actions[] = [
                    'action' =>
                        'synchronize_inverse',
                    'source_relationship_id' =>
                        $leftId,
                    'inverse_relationship_id' =>
                        $rightId,
                    'applied' => true,
                ];

                break;
            }
        }

        return [
            'relationships' => $result,
            'actions' => $actions,
        ];
    }

    /**
     * Remove exact duplicate edges.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<string,mixed>
     */
    public function removeDuplicates(
        array $records
    ): array {
        $unique = [];
        $actions = [];
        $seen = [];

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                continue;
            }

            $key = $this->safeEdgeKey(
                $record
            );

            if ($key === '') {
                $unique[] = $record;
                continue;
            }

            if (!isset($seen[$key])) {
                $seen[$key] = $index;
                $unique[] = $record;
                continue;
            }

            $actions[] = [
                'action' =>
                    'remove_exact_duplicate',
                'index' => $index,
                'relationship_id' =>
                    $record[
                        'relationship_id'
                    ] ?? null,
                'duplicate_of_index' =>
                    $seen[$key],
                'applied' => true,
            ];
        }

        return [
            'relationships' =>
                array_values($unique),
            'actions' => $actions,
        ];
    }

    /**
     * Return repair service diagnostics.
     *
     * @return array<string,mixed>
     */
    public function diagnostics(): array
    {
        return array_merge(
            parent::diagnostics(),
            [
                'safe_repair_types' =>
                    $this->safeRepairTypes,

                'automatic_inverse_creation' =>
                    true,

                'automatic_duplicate_removal' =>
                    true,

                'ambiguous_repairs_automatic' =>
                    false,

                'checksum_algorithm' =>
                    'sha256',

                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Inspect inverse integrity.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    private function inspectInverseIntegrity(
        array $records
    ): array {
        $issues = [];

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                continue;
            }

            if (
                ($record['directional'] ?? true)
                === false
            ) {
                continue;
            }

            $type = trim(
                (string)(
                    $record['relationship_type']
                    ?? ''
                )
            );

            if (
                !$this->relationships
                    ->hasInverse($type)
            ) {
                continue;
            }

            $inverseFound = false;
            $inverseMismatch = false;

            foreach ($records as $otherIndex => $other) {
                if (
                    $index === $otherIndex
                    || !is_array($other)
                ) {
                    continue;
                }

                if (
                    $this->relationships
                        ->areInverse(
                            $record,
                            $other
                        )
                ) {
                    $inverseFound = true;

                    if (
                        !$this->inverseStateMatches(
                            $record,
                            $other
                        )
                    ) {
                        $inverseMismatch = true;
                    }

                    break;
                }
            }

            if (!$inverseFound) {
                $issues[] = $this->issue(
                    'missing_inverse',
                    'warning',
                    'Directional relationship has no canonical inverse edge.',
                    [
                        'index' => $index,
                        'relationship_id' =>
                            $record[
                                'relationship_id'
                            ] ?? null,
                        'relationship_type' =>
                            $type,
                    ],
                    true,
                    'add_inverse'
                );

                continue;
            }

            if ($inverseMismatch) {
                $issues[] = $this->issue(
                    'inverse_state_mismatch',
                    'warning',
                    'Inverse relationship exists but shared state is inconsistent.',
                    [
                        'index' => $index,
                        'relationship_id' =>
                            $record[
                                'relationship_id'
                            ] ?? null,
                    ],
                    true,
                    'synchronize_inverse'
                );
            }
        }

        return $issues;
    }

    /**
     * Check fields shared by inverse edges.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function inverseStateMatches(
        array $left,
        array $right
    ): bool {
        $fields = [
            'confidence',
            'weight',
            'strength',
            'status',
            'valid_from',
            'valid_to',
            'tags',
        ];

        foreach ($fields as $field) {
            if (
                $this->normalizeForHash(
                    $left[$field] ?? null
                )
                !==
                $this->normalizeForHash(
                    $right[$field] ?? null
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Inspect one numeric field.
     *
     * @param array<string,mixed> $record
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    private function inspectNumericField(
        array $record,
        string $field,
        float $minimum,
        float $maximum,
        string $repairType,
        array $context
    ): array {
        $value = $record[$field] ?? null;

        if (
            $value !== null
            && $value !== ''
            && is_numeric($value)
            && (float)$value >= $minimum
            && (float)$value <= $maximum
        ) {
            return [];
        }

        return [
            $this->issue(
                'invalid_numeric_field',
                'warning',
                sprintf(
                    '%s must be numeric between %s and %s.',
                    ucfirst($field),
                    $minimum,
                    $maximum
                ),
                array_merge(
                    $context,
                    [
                        'field' => $field,
                        'value' => $value,
                    ]
                ),
                true,
                $repairType
            ),
        ];
    }

    /**
     * Repair one numeric field.
     *
     * @param array<string,mixed> $record
     * @param array<int,array<string,mixed>> $actions
     * @return array<string,mixed>
     */
    private function repairNumericField(
        array $record,
        string $field,
        float $default,
        float $minimum,
        float $maximum,
        string $action,
        array &$actions
    ): array {
        $before = $record[$field] ?? null;

        $after = is_numeric($before)
            ? max(
                $minimum,
                min(
                    $maximum,
                    (float)$before
                )
            )
            : $default;

        if (
            !is_numeric($before)
            || (float)$before !== $after
        ) {
            $record[$field] = $after;

            $actions[] = $this->repairAction(
                $action,
                [
                    'field' => $field,
                    'before' => $before,
                    'after' => $after,
                ]
            );
        }

        return $record;
    }

    /**
     * Create one inspection issue.
     *
     * @return array<string,mixed>
     */
    private function issue(
        string $type,
        string $severity,
        string $message,
        array $context = [],
        bool $repairable = false,
        ?string $repairType = null
    ): array {
        return [
            'issue_id' =>
                $this->generateIssueId(),

            'type' => $type,

            'severity' => $this->normalizeSeverity(
                $severity
            ),

            'message' => trim($message),

            'repairable' => $repairable,

            'repair_type' =>
                $repairable
                    ? $repairType
                    : null,

            'context' => $context,
        ];
    }

    /**
     * Create one applied repair action.
     *
     * @return array<string,mixed>
     */
    private function repairAction(
        string $action,
        array $details = []
    ): array {
        return [
            'action' => $action,
            'applied' => true,
            'details' => $details,
            'applied_at' => gmdate('c'),
        ];
    }

    /**
     * Summarize inspection issues.
     *
     * @param array<int,array<string,mixed>> $issues
     * @return array<string,mixed>
     */
    private function summarizeIssues(
        array $issues
    ): array {
        $types = [];
        $severities = [];
        $repairTypes = [];
        $repairableCount = 0;

        foreach ($issues as $issue) {
            $type = trim(
                (string)(
                    $issue['type']
                    ?? 'unknown'
                )
            );

            $severity = trim(
                (string)(
                    $issue['severity']
                    ?? 'notice'
                )
            );

            $types[$type] =
                ($types[$type] ?? 0) + 1;

            $severities[$severity] =
                ($severities[$severity] ?? 0)
                + 1;

            if (
                ($issue['repairable'] ?? false)
                === true
            ) {
                $repairableCount++;

                $repairType = trim(
                    (string)(
                        $issue['repair_type']
                        ?? ''
                    )
                );

                if ($repairType !== '') {
                    $repairTypes[$repairType] =
                        (
                            $repairTypes[
                                $repairType
                            ] ?? 0
                        ) + 1;
                }
            }
        }

        arsort($types);
        arsort($severities);
        arsort($repairTypes);

        return [
            'total' => count($issues),
            'repairable_count' =>
                $repairableCount,
            'manual_review_count' =>
                count($issues)
                - $repairableCount,
            'types' => $types,
            'severities' => $severities,
            'repair_types' => $repairTypes,
        ];
    }

    /**
     * Safely calculate the canonical edge key.
     *
     * @param array<string,mixed> $record
     */
    private function safeEdgeKey(
        array $record
    ): string {
        try {
            return $this->relationships
                ->edgeKey($record);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Calculate relationship checksum.
     *
     * @param array<string,mixed> $record
     */
    private function relationshipChecksum(
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
                'Unable to encode relationship for checksum.'
            );
        }

        return hash('sha256', $json);
    }

    /**
     * Verify relationship checksum.
     *
     * @param array<string,mixed> $record
     */
    private function relationshipChecksumMatches(
        array $record
    ): bool {
        $stored = trim(
            (string)(
                $record['checksum']
                ?? ''
            )
        );

        if ($stored === '') {
            return false;
        }

        return hash_equals(
            $stored,
            $this->relationshipChecksum($record)
        );
    }

    /**
     * Normalize string lists.
     *
     * @return array<int,string>
     */
    private function normalizeStringList(
        mixed $values
    ): array {
        if (is_string($values)) {
            $values = preg_split(
                '/[\r\n,]+/',
                $values
            ) ?: [];
        }

        if (!is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            $value = trim((string)$value);

            if ($value !== '') {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    /**
     * Normalize issue severity.
     */
    private function normalizeSeverity(
        string $severity
    ): string {
        $severity = strtolower(
            trim($severity)
        );

        $allowed = [
            'debug',
            'notice',
            'warning',
            'error',
            'critical',
        ];

        return in_array(
            $severity,
            $allowed,
            true
        )
            ? $severity
            : 'notice';
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
     * Generate issue identifier.
     */
    private function generateIssueId(): string
    {
        try {
            $random = strtoupper(
                bin2hex(random_bytes(5))
            );
        } catch (Throwable) {
            $random = strtoupper(
                substr(
                    hash(
                        'sha256',
                        uniqid('', true)
                        . microtime(true)
                    ),
                    0,
                    10
                )
            );
        }

        return 'GRI-'
            . gmdate('Ymd-His')
            . '-'
            . $random;
    }
}