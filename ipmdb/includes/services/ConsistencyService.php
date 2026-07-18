<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/ConsistencyService.php
|--------------------------------------------------------------------------
| IPMdb Consistency Service
|--------------------------------------------------------------------------
|
| Detects contradictions, incompatible claims, structural conflicts,
| lifecycle conflicts, temporal conflicts, and attribution deficiencies.
|
| Responsibilities:
| - Detect contradictory relationship pairs.
| - Detect duplicate and mutually incompatible edges.
| - Detect inverse-state mismatches.
| - Detect temporal validity conflicts.
| - Detect lifecycle inconsistencies.
| - Detect provenance and attribution gaps.
| - Detect conflicting entity field values.
| - Detect graph cycles where acyclic meaning is expected.
| - Produce explainable findings requiring resolution.
|
| This service performs no database operations.
|
| Consistency detects.
| Evidence explains.
| Resolution remains attributable.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/RelationshipService.php';
require_once __DIR__ . '/GraphTraversalService.php';
require_once __DIR__ . '/PathService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class ConsistencyService extends Service
{
    use GraphUtilities;

    private RelationshipService $relationships;

    private GraphTraversalService $traversal;

    private PathService $paths;

    /**
     * Relationship types that oppose one another.
     *
     * @var array<string,array<int,string>>
     */
    private array $contradictoryTypes = [
        'supports' => [
            'contradicts',
            'evidence_against',
            'blocks',
            'rejects',
        ],

        'supported_by' => [
            'contradicted_by',
            'evidence_against',
            'blocked_by',
            'rejected_by',
        ],

        'evidence_for' => [
            'evidence_against',
            'contradicts',
        ],

        'evidence_against' => [
            'evidence_for',
            'supports',
        ],

        'enables' => [
            'blocks',
            'prevents',
        ],

        'blocks' => [
            'enables',
            'permits',
        ],

        'approved_by' => [
            'rejected_by',
        ],

        'verified_by' => [
            'disputed_by',
            'rejected_by',
        ],

        'eligible_for' => [
            'ineligible_for',
        ],

        'complies_with' => [
            'violates',
            'conflicts_with',
        ],

        'aligns_with' => [
            'conflicts_with',
            'opposes',
        ],

        'same_as' => [
            'distinct_from',
        ],

        'duplicate_of' => [
            'distinct_from',
        ],

        'active_with' => [
            'terminated_with',
        ],
    ];

    /**
     * Types expected to form acyclic hierarchies.
     *
     * @var array<int,string>
     */
    private array $acyclicTypes = [
        'parent_of',
        'child_of',
        'contains',
        'contained_by',
        'derived_from',
        'derives',
        'depends_on',
        'supersedes',
        'replaces',
        'version_of',
    ];

    /**
     * Status combinations that cannot coexist for one record.
     *
     * @var array<string,array<int,string>>
     */
    private array $incompatibleStatuses = [
        'active' => [
            'rejected',
            'archived',
            'expired',
            'cancelled',
        ],

        'verified' => [
            'rejected',
            'disputed',
            'cancelled',
        ],

        'approved' => [
            'rejected',
            'cancelled',
        ],

        'completed' => [
            'pending',
            'cancelled',
        ],

        'archived' => [
            'active',
            'verified',
        ],

        'expired' => [
            'active',
            'verified',
        ],
    ];

    /**
     * Entity fields treated as single-valued facts by default.
     *
     * @var array<int,string>
     */
    private array $singleValueFields = [
        'entity_id',
        'asset_id',
        'translation_id',
        'document_id',
        'relationship_id',
        'version',
        'checksum',
        'content_hash',
        'status',
        'language',
        'source_language',
        'target_language',
        'originator_id',
        'created_by',
        'provenance_id',
        'license',
        'currency',
    ];

    /**
     * Severity order used for sorting.
     *
     * @var array<string,int>
     */
    private array $severityOrder = [
        'critical' => 60,
        'error' => 50,
        'warning' => 40,
        'notice' => 30,
        'info' => 20,
        'debug' => 10,
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?RelationshipService $relationships = null,
        ?GraphTraversalService $traversal = null,
        ?PathService $paths = null
    ) {
        parent::__construct($config, $context);

        $this->relationships = $relationships
            ?? new RelationshipService();

        $this->traversal = $traversal
            ?? new GraphTraversalService();

        $this->paths = $paths
            ?? new PathService();
    }

    /**
     * Run a complete consistency inspection.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    public function inspect(
        array $entities = [],
        array $relationships = [],
        array $options = []
    ): array {
        $this->reset();

        $findings = [];

        if (
            (bool)(
                $options['check_relationships']
                ?? true
            )
        ) {
            $findings = array_merge(
                $findings,
                $this->inspectRelationships(
                    $relationships,
                    $options
                )
            );
        }

        if (
            (bool)(
                $options['check_entities']
                ?? true
            )
        ) {
            $findings = array_merge(
                $findings,
                $this->inspectEntities(
                    $entities,
                    $options
                )
            );
        }

        if (
            (bool)(
                $options['check_references']
                ?? true
            )
        ) {
            $findings = array_merge(
                $findings,
                $this->inspectReferences(
                    $entities,
                    $relationships
                )
            );
        }

        if (
            (bool)(
                $options['check_cycles']
                ?? true
            )
        ) {
            $findings = array_merge(
                $findings,
                $this->inspectHierarchyCycles(
                    $relationships,
                    (int)(
                        $options['maximum_cycles']
                        ?? 250
                    )
                )
            );
        }

        $findings = $this->deduplicateFindings(
            $findings
        );

        $this->sortFindings($findings);

        $summary = $this->summarizeFindings(
            $findings
        );

        $result = [
            'generated_at' => gmdate('c'),

            'entity_count' =>
                count($entities),

            'relationship_count' =>
                count($relationships),

            'finding_count' =>
                count($findings),

            'consistent' =>
                $summary['blocking_count'] === 0,

            'summary' => $summary,

            'findings' => $findings,
        ];

        $this->addMessage(
            'Consistency inspection completed.',
            [
                'entity_count' =>
                    count($entities),

                'relationship_count' =>
                    count($relationships),

                'finding_count' =>
                    count($findings),

                'blocking_count' =>
                    $summary['blocking_count'],
            ]
        );

        return $result;
    }

    /**
     * Inspect relationship consistency.
     *
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<int,array<string,mixed>>
     */
    public function inspectRelationships(
        array $relationships,
        array $options = []
    ): array {
        $findings = [];

        $findings = array_merge(
            $findings,
            $this->findDuplicateIdentifiers(
                $relationships
            ),
            $this->findDuplicateEdges(
                $relationships
            ),
            $this->findContradictoryEdges(
                $relationships
            ),
            $this->findInverseConflicts(
                $relationships
            ),
            $this->findTemporalConflicts(
                $relationships
            ),
            $this->findLifecycleConflicts(
                $relationships
            ),
            $this->findAttributionGaps(
                $relationships
            ),
            $this->findIntegrityConflicts(
                $relationships
            )
        );

        if (
            (bool)(
                $options['check_competing_targets']
                ?? true
            )
        ) {
            $findings = array_merge(
                $findings,
                $this->findCompetingTargets(
                    $relationships,
                    $this->normalizeStringList(
                        $options[
                            'single_target_relationship_types'
                        ]
                        ?? [
                            'same_as',
                            'duplicate_of',
                            'replaces',
                            'supersedes',
                            'version_of',
                            'translated_from',
                            'originated_from',
                        ]
                    )
                )
            );
        }

        return $findings;
    }

    /**
     * Inspect entity consistency.
     *
     * @param array<int,array<string,mixed>> $entities
     *
     * @return array<int,array<string,mixed>>
     */
    public function inspectEntities(
        array $entities,
        array $options = []
    ): array {
        $findings = [];

        $findings = array_merge(
            $findings,
            $this->findDuplicateEntities(
                $entities
            ),
            $this->findEntityIntegrityConflicts(
                $entities
            ),
            $this->findEntityLifecycleConflicts(
                $entities
            )
        );

        $singleValueFields =
            $this->normalizeStringList(
                $options['single_value_fields']
                    ?? $this->singleValueFields
            );

        $findings = array_merge(
            $findings,
            $this->findConflictingEntityFacts(
                $entities,
                $singleValueFields
            )
        );

        return $findings;
    }

    /**
     * Find contradictory relationship pairs.
     *
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<int,array<string,mixed>>
     */
    public function findContradictoryEdges(
        array $relationships
    ): array {
        $findings = [];
        $count = count($relationships);

        for ($leftIndex = 0; $leftIndex < $count; $leftIndex++) {
            $left = $relationships[$leftIndex] ?? null;

            if (
                !is_array($left)
                || !$this->isOperationalRelationship($left)
            ) {
                continue;
            }

            for (
                $rightIndex = $leftIndex + 1;
                $rightIndex < $count;
                $rightIndex++
            ) {
                $right = $relationships[$rightIndex]
                    ?? null;

                if (
                    !is_array($right)
                    || !$this->isOperationalRelationship($right)
                ) {
                    continue;
                }

                if (
                    !$this->sameOrderedEndpoints(
                        $left,
                        $right
                    )
                    && !$this->sameUnorderedEndpoints(
                        $left,
                        $right
                    )
                ) {
                    continue;
                }

                $leftType = $this->relationshipType(
                    $left
                );

                $rightType = $this->relationshipType(
                    $right
                );

                if (
                    !$this->typesContradict(
                        $leftType,
                        $rightType
                    )
                ) {
                    continue;
                }

                $findings[] = $this->finding(
                    'contradictory_relationships',
                    'error',
                    sprintf(
                        'Relationships "%s" and "%s" assert incompatible meanings between the same entities.',
                        $leftType,
                        $rightType
                    ),
                    [
                        'left_index' => $leftIndex,

                        'right_index' => $rightIndex,

                        'left_relationship_id' =>
                            $left['relationship_id']
                            ?? null,

                        'right_relationship_id' =>
                            $right['relationship_id']
                            ?? null,

                        'source_key' =>
                            $this->sourceNodeKey($left),

                        'target_key' =>
                            $this->targetNodeKey($left),

                        'left_type' => $leftType,

                        'right_type' => $rightType,
                    ],
                    [
                        'review_relationship_meaning',
                        'verify_evidence',
                        'dispute_or_archive_one_edge',
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * Find duplicate relationship identifiers.
     *
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<int,array<string,mixed>>
     */
    public function findDuplicateIdentifiers(
        array $relationships
    ): array {
        $seen = [];
        $findings = [];

        foreach ($relationships as $index => $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $relationshipId = trim(
                (string)(
                    $relationship[
                        'relationship_id'
                    ]
                    ?? ''
                )
            );

            if ($relationshipId === '') {
                continue;
            }

            if (!isset($seen[$relationshipId])) {
                $seen[$relationshipId] = $index;
                continue;
            }

            $findings[] = $this->finding(
                'duplicate_relationship_id',
                'critical',
                'Multiple relationship records use the same public identifier.',
                [
                    'relationship_id' =>
                        $relationshipId,

                    'first_index' =>
                        $seen[$relationshipId],

                    'duplicate_index' =>
                        $index,
                ],
                [
                    'assign_unique_identifier',
                    'preserve_identifier_history',
                ]
            );
        }

        return $findings;
    }

    /**
     * Find duplicate graph edges.
     *
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<int,array<string,mixed>>
     */
    public function findDuplicateEdges(
        array $relationships
    ): array {
        $seen = [];
        $findings = [];

        foreach ($relationships as $index => $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $edgeKey = $this->safeEdgeKey(
                $relationship
            );

            if ($edgeKey === '') {
                continue;
            }

            if (!isset($seen[$edgeKey])) {
                $seen[$edgeKey] = [
                    'index' => $index,
                    'record' => $relationship,
                ];

                continue;
            }

            $first = $seen[$edgeKey];

            $sameContent =
                $this->normalizedRecordForComparison(
                    $first['record']
                )
                ===
                $this->normalizedRecordForComparison(
                    $relationship
                );

            $findings[] = $this->finding(
                $sameContent
                    ? 'exact_duplicate_edge'
                    : 'competing_duplicate_edge',

                $sameContent
                    ? 'warning'
                    : 'error',

                $sameContent
                    ? 'An identical relationship edge appears more than once.'
                    : 'Equivalent graph edges contain different state or evidence.',

                [
                    'edge_key' => $edgeKey,

                    'first_index' =>
                        $first['index'],

                    'duplicate_index' =>
                        $index,

                    'first_relationship_id' =>
                        $first['record'][
                            'relationship_id'
                        ] ?? null,

                    'duplicate_relationship_id' =>
                        $relationship[
                            'relationship_id'
                        ] ?? null,

                    'same_content' =>
                        $sameContent,
                ],

                $sameContent
                    ? [
                        'merge_duplicate_records',
                        'preserve_one_canonical_edge',
                    ]
                    : [
                        'compare_evidence',
                        'merge_or_dispute_edges',
                    ]
            );
        }

        return $findings;
    }

    /**
     * Find inverse relationship conflicts.
     *
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<int,array<string,mixed>>
     */
    public function findInverseConflicts(
        array $relationships
    ): array {
        $findings = [];
        $processedPairs = [];

        foreach ($relationships as $leftIndex => $left) {
            if (
                !is_array($left)
                || (
                    $left['directional']
                    ?? true
                ) === false
            ) {
                continue;
            }

            $leftType = $this->relationshipType(
                $left
            );

            $expectedInverse =
                $this->relationships->inverseType(
                    $leftType
                );

            if ($expectedInverse === null) {
                continue;
            }

            $inverseFound = false;

            foreach (
                $relationships
                as $rightIndex => $right
            ) {
                if (
                    $leftIndex === $rightIndex
                    || !is_array($right)
                ) {
                    continue;
                }

                if (
                    !$this->relationships->areInverse(
                        $left,
                        $right
                    )
                ) {
                    continue;
                }

                $pairKey = $this->pairKey(
                    (string)(
                        $left['relationship_id']
                        ?? $leftIndex
                    ),
                    (string)(
                        $right['relationship_id']
                        ?? $rightIndex
                    )
                );

                if (isset($processedPairs[$pairKey])) {
                    $inverseFound = true;
                    break;
                }

                $processedPairs[$pairKey] = true;
                $inverseFound = true;

                $differences =
                    $this->inverseDifferences(
                        $left,
                        $right
                    );

                if ($differences !== []) {
                    $findings[] =
                        $this->finding(
                            'inverse_state_conflict',
                            'error',
                            'Inverse relationship records contain inconsistent shared state.',
                            [
                                'left_index' =>
                                    $leftIndex,

                                'right_index' =>
                                    $rightIndex,

                                'left_relationship_id' =>
                                    $left[
                                        'relationship_id'
                                    ] ?? null,

                                'right_relationship_id' =>
                                    $right[
                                        'relationship_id'
                                    ] ?? null,

                                'differences' =>
                                    $differences,
                            ],
                            [
                                'select_canonical_edge',
                                'synchronize_inverse_state',
                                'recalculate_checksums',
                            ]
                        );
                }

                break;
            }

            if (!$inverseFound) {
                $findings[] = $this->finding(
                    'missing_inverse_relationship',
                    'warning',
                    sprintf(
                        'Relationship "%s" expects inverse type "%s", but no inverse edge exists.',
                        $leftType,
                        $expectedInverse
                    ),
                    [
                        'index' => $leftIndex,

                        'relationship_id' =>
                            $left['relationship_id']
                            ?? null,

                        'expected_inverse_type' =>
                            $expectedInverse,

                        'source_key' =>
                            $this->sourceNodeKey($left),

                        'target_key' =>
                            $this->targetNodeKey($left),
                    ],
                    [
                        'create_inverse_relationship',
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * Find overlapping temporal relationships containing incompatible state.
     *
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<int,array<string,mixed>>
     */
    public function findTemporalConflicts(
        array $relationships
    ): array {
        $findings = [];

        foreach ($relationships as $index => $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $validFrom = $this->timestamp(
                $relationship['valid_from']
                    ?? null
            );

            $validTo = $this->timestamp(
                $relationship['valid_to']
                    ?? null
            );

            if (
                $validFrom !== null
                && $validTo !== null
                && $validTo < $validFrom
            ) {
                $findings[] = $this->finding(
                    'inverted_validity_window',
                    'error',
                    'Relationship validity ends before it begins.',
                    [
                        'index' => $index,

                        'relationship_id' =>
                            $relationship[
                                'relationship_id'
                            ] ?? null,

                        'valid_from' =>
                            $relationship[
                                'valid_from'
                            ] ?? null,

                        'valid_to' =>
                            $relationship[
                                'valid_to'
                            ] ?? null,
                    ],
                    [
                        'correct_validity_window',
                        'verify_source_dates',
                    ]
                );
            }

            $status = $this->status($relationship);

            if (
                in_array(
                    $status,
                    ['expired', 'archived'],
                    true
                )
                && $validTo === null
            ) {
                $findings[] = $this->finding(
                    'closed_status_without_end_time',
                    'warning',
                    'Expired or archived relationship has no validity end timestamp.',
                    [
                        'index' => $index,

                        'relationship_id' =>
                            $relationship[
                                'relationship_id'
                            ] ?? null,

                        'status' => $status,
                    ],
                    [
                        'set_valid_to',
                        'confirm_lifecycle_transition',
                    ]
                );
            }

            if (
                in_array(
                    $status,
                    ['active', 'verified'],
                    true
                )
                && $validTo !== null
                && $validTo < time()
            ) {
                $findings[] = $this->finding(
                    'active_status_after_expiry',
                    'error',
                    'Relationship remains active after its validity window ended.',
                    [
                        'index' => $index,

                        'relationship_id' =>
                            $relationship[
                                'relationship_id'
                            ] ?? null,

                        'status' => $status,

                        'valid_to' =>
                            $relationship[
                                'valid_to'
                            ] ?? null,
                    ],
                    [
                        'expire_relationship',
                        'extend_validity_with_evidence',
                    ]
                );
            }
        }

        $count = count($relationships);

        for ($leftIndex = 0; $leftIndex < $count; $leftIndex++) {
            $left = $relationships[$leftIndex] ?? null;

            if (!is_array($left)) {
                continue;
            }

            for (
                $rightIndex = $leftIndex + 1;
                $rightIndex < $count;
                $rightIndex++
            ) {
                $right = $relationships[$rightIndex]
                    ?? null;

                if (!is_array($right)) {
                    continue;
                }

                if (
                    !$this->sameOrderedEndpoints(
                        $left,
                        $right
                    )
                ) {
                    continue;
                }

                if (
                    !$this->typesContradict(
                        $this->relationshipType($left),
                        $this->relationshipType($right)
                    )
                ) {
                    continue;
                }

                if (
                    !$this->timeRangesOverlap(
                        $left['valid_from'] ?? null,
                        $left['valid_to'] ?? null,
                        $right['valid_from'] ?? null,
                        $right['valid_to'] ?? null
                    )
                ) {
                    continue;
                }

                $findings[] = $this->finding(
                    'overlapping_temporal_contradiction',
                    'error',
                    'Contradictory relationships are valid during overlapping periods.',
                    [
                        'left_index' => $leftIndex,

                        'right_index' => $rightIndex,

                        'left_relationship_id' =>
                            $left['relationship_id']
                            ?? null,

                        'right_relationship_id' =>
                            $right['relationship_id']
                            ?? null,

                        'left_valid_from' =>
                            $left['valid_from']
                            ?? null,

                        'left_valid_to' =>
                            $left['valid_to']
                            ?? null,

                        'right_valid_from' =>
                            $right['valid_from']
                            ?? null,

                        'right_valid_to' =>
                            $right['valid_to']
                            ?? null,
                    ],
                    [
                        'verify_effective_dates',
                        'dispute_conflicting_claim',
                        'split_historical_periods',
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * Find relationship lifecycle conflicts.
     *
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<int,array<string,mixed>>
     */
    public function findLifecycleConflicts(
        array $relationships
    ): array {
        $findings = [];

        foreach ($relationships as $index => $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $status = $this->status(
                $relationship
            );

            if (
                ($relationship[
                    'suggested_by_ai'
                ] ?? false) === true
                && (
                    $relationship[
                        'accepted_by_human'
                    ] ?? false
                ) === false
                && in_array(
                    $status,
                    ['active', 'verified'],
                    true
                )
            ) {
                $findings[] = $this->finding(
                    'unaccepted_ai_relationship',
                    'error',
                    'AI-suggested relationship is active without recorded human acceptance.',
                    [
                        'index' => $index,

                        'relationship_id' =>
                            $relationship[
                                'relationship_id'
                            ] ?? null,

                        'status' => $status,
                    ],
                    [
                        'record_human_acceptance',
                        'return_relationship_to_proposed',
                    ]
                );
            }

            if (
                $status === 'verified'
                && trim(
                    (string)(
                        $relationship[
                            'provenance_id'
                        ]
                        ?? ''
                    )
                ) === ''
            ) {
                $findings[] = $this->finding(
                    'verified_without_provenance',
                    'error',
                    'Verified relationship lacks provenance.',
                    [
                        'index' => $index,

                        'relationship_id' =>
                            $relationship[
                                'relationship_id'
                            ] ?? null,
                    ],
                    [
                        'attach_provenance',
                        'return_relationship_to_active',
                    ]
                );
            }

            if (
                $status === 'rejected'
                && (
                    $relationship[
                        'accepted_by_human'
                    ] ?? false
                ) === true
            ) {
                $findings[] = $this->finding(
                    'rejected_but_accepted',
                    'warning',
                    'Relationship is marked rejected while retaining human-accepted state.',
                    [
                        'index' => $index,

                        'relationship_id' =>
                            $relationship[
                                'relationship_id'
                            ] ?? null,
                    ],
                    [
                        'confirm_final_decision',
                        'preserve_acceptance_history_in_metadata',
                    ]
                );
            }

            if (
                in_array(
                    $status,
                    ['archived', 'expired'],
                    true
                )
                && (
                    $relationship[
                        'analytics_pending'
                    ] ?? false
                ) === true
            ) {
                $findings[] = $this->finding(
                    'closed_relationship_pending_processing',
                    'notice',
                    'Closed relationship remains marked for graph processing.',
                    [
                        'index' => $index,

                        'relationship_id' =>
                            $relationship[
                                'relationship_id'
                            ] ?? null,

                        'status' => $status,
                    ],
                    [
                        'clear_processing_flags',
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * Find missing attribution and provenance.
     *
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<int,array<string,mixed>>
     */
    public function findAttributionGaps(
        array $relationships
    ): array {
        $findings = [];

        foreach ($relationships as $index => $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $createdBy = trim(
                (string)(
                    $relationship['created_by']
                    ?? ''
                )
            );

            if ($createdBy === '') {
                $findings[] = $this->finding(
                    'missing_relationship_attribution',
                    'error',
                    'Relationship lacks creator attribution.',
                    [
                        'index' => $index,

                        'relationship_id' =>
                            $relationship[
                                'relationship_id'
                            ] ?? null,
                    ],
                    [
                        'identify_creator',
                        'preserve_unknown_attribution_explicitly',
                    ]
                );
            }

            $provenanceId = trim(
                (string)(
                    $relationship['provenance_id']
                    ?? ''
                )
            );

            if ($provenanceId === '') {
                $findings[] = $this->finding(
                    'missing_relationship_provenance',
                    'warning',
                    'Relationship lacks a provenance reference.',
                    [
                        'index' => $index,

                        'relationship_id' =>
                            $relationship[
                                'relationship_id'
                            ] ?? null,
                    ],
                    [
                        'create_provenance_record',
                        'attach_existing_provenance',
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * Find checksum and structural integrity conflicts.
     *
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<int,array<string,mixed>>
     */
    public function findIntegrityConflicts(
        array $relationships
    ): array {
        $findings = [];

        foreach ($relationships as $index => $relationship) {
            if (!is_array($relationship)) {
                $findings[] = $this->finding(
                    'invalid_relationship_record',
                    'critical',
                    'Relationship collection contains a non-array record.',
                    [
                        'index' => $index,
                    ],
                    [
                        'remove_or_reconstruct_record',
                    ]
                );

                continue;
            }

            foreach (
                [
                    'relationship_id',
                    'relationship_type',
                    'source_id',
                    'source_type',
                    'target_id',
                    'target_type',
                    'status',
                    'created_at',
                    'updated_at',
                ]
                as $field
            ) {
                if (
                    $this->valueIsEmpty(
                        $relationship[$field]
                            ?? null
                    )
                ) {
                    $findings[] = $this->finding(
                        'missing_relationship_field',
                        'error',
                        sprintf(
                            'Relationship field "%s" is missing.',
                            $field
                        ),
                        [
                            'index' => $index,

                            'relationship_id' =>
                                $relationship[
                                    'relationship_id'
                                ] ?? null,

                            'field' => $field,
                        ],
                        [
                            'supply_required_field',
                            'review_source_record',
                        ]
                    );
                }
            }

            $checksum = trim(
                (string)(
                    $relationship['checksum']
                    ?? ''
                )
            );

            if ($checksum === '') {
                $findings[] = $this->finding(
                    'missing_relationship_checksum',
                    'warning',
                    'Relationship checksum is missing.',
                    [
                        'index' => $index,

                        'relationship_id' =>
                            $relationship[
                                'relationship_id'
                            ] ?? null,
                    ],
                    [
                        'recalculate_checksum',
                    ]
                );
            } elseif (
                !$this->relationshipChecksumMatches(
                    $relationship
                )
            ) {
                $findings[] = $this->finding(
                    'relationship_checksum_mismatch',
                    'error',
                    'Relationship checksum does not match its content.',
                    [
                        'index' => $index,

                        'relationship_id' =>
                            $relationship[
                                'relationship_id'
                            ] ?? null,

                        'stored_checksum' =>
                            $checksum,

                        'calculated_checksum' =>
                            $this->relationshipChecksum(
                                $relationship
                            ),
                    ],
                    [
                        'investigate_unaudited_change',
                        'recalculate_checksum_after_review',
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * Find multiple targets where one target is expected.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @param array<int,string> $relationshipTypes
     *
     * @return array<int,array<string,mixed>>
     */
    public function findCompetingTargets(
        array $relationships,
        array $relationshipTypes
    ): array {
        $groups = [];
        $findings = [];

        foreach ($relationships as $index => $relationship) {
            if (
                !is_array($relationship)
                || !$this->isOperationalRelationship(
                    $relationship
                )
            ) {
                continue;
            }

            $relationshipType =
                $this->relationshipType(
                    $relationship
                );

            if (
                !in_array(
                    $relationshipType,
                    $relationshipTypes,
                    true
                )
            ) {
                continue;
            }

            $sourceKey = $this->sourceNodeKey(
                $relationship
            );

            if ($sourceKey === '') {
                continue;
            }

            $groupKey =
                $sourceKey
                . '|'
                . $relationshipType;

            $groups[$groupKey][] = [
                'index' => $index,
                'record' => $relationship,
                'target_key' =>
                    $this->targetNodeKey(
                        $relationship
                    ),
            ];
        }

        foreach ($groups as $groupKey => $items) {
            $targets = [];

            foreach ($items as $item) {
                $targetKey = trim(
                    (string)(
                        $item['target_key']
                        ?? ''
                    )
                );

                if ($targetKey !== '') {
                    $targets[$targetKey] = true;
                }
            }

            if (count($targets) <= 1) {
                continue;
            }

            $findings[] = $this->finding(
                'competing_single_target_relationships',
                'error',
                'One source entity has multiple active targets for a relationship expected to be single-valued.',
                [
                    'group_key' => $groupKey,

                    'target_keys' =>
                        array_keys($targets),

                    'relationship_ids' =>
                        array_values(
                            array_filter(
                                array_map(
                                    static fn (
                                        array $item
                                    ): ?string =>
                                        isset(
                                            $item['record']
                                                ['relationship_id']
                                        )
                                            ? (string)$item['record']
                                                ['relationship_id']
                                            : null,
                                    $items
                                )
                            )
                        ),
                ],
                [
                    'select_canonical_target',
                    'archive_superseded_edges',
                    'preserve_decision_history',
                ]
            );
        }

        return $findings;
    }

    /**
     * Find duplicate entities.
     *
     * @param array<int,array<string,mixed>> $entities
     *
     * @return array<int,array<string,mixed>>
     */
    public function findDuplicateEntities(
        array $entities
    ): array {
        $findings = [];
        $identifierIndex = [];
        $checksumIndex = [];

        foreach ($entities as $index => $entity) {
            if (!is_array($entity)) {
                $findings[] = $this->finding(
                    'invalid_entity_record',
                    'critical',
                    'Entity collection contains a non-array record.',
                    [
                        'index' => $index,
                    ],
                    [
                        'remove_or_reconstruct_record',
                    ]
                );

                continue;
            }

            $entityId = $this->resolveEntityId(
                $entity
            );

            $entityType = $this->resolveEntityType(
                $entity
            );

            if ($entityId !== '') {
                $key = $entityType
                    . ':'
                    . $entityId;

                if (isset($identifierIndex[$key])) {
                    $findings[] = $this->finding(
                        'duplicate_entity_identifier',
                        'critical',
                        'Multiple entity records share the same type and identifier.',
                        [
                            'entity_key' => $key,

                            'first_index' =>
                                $identifierIndex[$key],

                            'duplicate_index' =>
                                $index,
                        ],
                        [
                            'merge_entity_records',
                            'assign_unique_identifier',
                        ]
                    );
                } else {
                    $identifierIndex[$key] = $index;
                }
            }

            $checksum = trim(
                (string)(
                    $entity['checksum']
                    ?? $entity['content_hash']
                    ?? ''
                )
            );

            if ($checksum === '') {
                continue;
            }

            if (isset($checksumIndex[$checksum])) {
                $firstIndex =
                    $checksumIndex[$checksum];

                $firstEntity =
                    $entities[$firstIndex]
                    ?? [];

                $firstKey =
                    $this->resolveEntityType(
                        is_array($firstEntity)
                            ? $firstEntity
                            : []
                    )
                    . ':'
                    . $this->resolveEntityId(
                        is_array($firstEntity)
                            ? $firstEntity
                            : []
                    );

                $currentKey =
                    $entityType
                    . ':'
                    . $entityId;

                if ($firstKey !== $currentKey) {
                    $findings[] = $this->finding(
                        'duplicate_entity_content',
                        'warning',
                        'Different entity identifiers contain identical hashed content.',
                        [
                            'checksum' => $checksum,

                            'first_index' =>
                                $firstIndex,

                            'duplicate_index' =>
                                $index,

                            'first_entity_key' =>
                                $firstKey,

                            'duplicate_entity_key' =>
                                $currentKey,
                        ],
                        [
                            'review_for_duplicate_of_relationship',
                            'merge_if_semantically_identical',
                        ]
                    );
                }
            } else {
                $checksumIndex[$checksum] = $index;
            }
        }

        return $findings;
    }

    /**
     * Find conflicting single-valued facts.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,string> $fields
     *
     * @return array<int,array<string,mixed>>
     */
    public function findConflictingEntityFacts(
        array $entities,
        array $fields
    ): array {
        $groups = [];
        $findings = [];

        foreach ($entities as $index => $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $identityKey =
                $this->entityIdentityGroupKey(
                    $entity
                );

            if ($identityKey === '') {
                continue;
            }

            $groups[$identityKey][] = [
                'index' => $index,
                'entity' => $entity,
            ];
        }

        foreach ($groups as $identityKey => $items) {
            if (count($items) < 2) {
                continue;
            }

            foreach ($fields as $field) {
                $values = [];

                foreach ($items as $item) {
                    $value =
                        $item['entity'][$field]
                        ?? null;

                    if ($this->valueIsEmpty($value)) {
                        continue;
                    }

                    $normalized =
                        $this->normalizeForHash(
                            $value
                        );

                    $encoded = json_encode(
                        $normalized,
                        JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_PRESERVE_ZERO_FRACTION
                    );

                    if ($encoded === false) {
                        continue;
                    }

                    $values[$encoded][] = [
                        'index' => $item['index'],
                        'value' => $value,
                    ];
                }

                if (count($values) <= 1) {
                    continue;
                }

                $findings[] = $this->finding(
                    'conflicting_entity_fact',
                    'error',
                    sprintf(
                        'Entity records disagree on single-valued field "%s".',
                        $field
                    ),
                    [
                        'entity_identity' =>
                            $identityKey,

                        'field' => $field,

                        'values' =>
                            array_values($values),
                    ],
                    [
                        'compare_versions',
                        'select_authoritative_value',
                        'preserve_disputed_values',
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * Find entity checksum and provenance conflicts.
     *
     * @param array<int,array<string,mixed>> $entities
     *
     * @return array<int,array<string,mixed>>
     */
    public function findEntityIntegrityConflicts(
        array $entities
    ): array {
        $findings = [];

        foreach ($entities as $index => $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $entityKey =
                $this->resolveEntityType($entity)
                . ':'
                . $this->resolveEntityId($entity);

            $status = $this->normalizedScalar(
                $entity['status']
                ?? ''
            );

            $provenanceId = trim(
                (string)(
                    $entity['provenance_id']
                    ?? ''
                )
            );

            if (
                in_array(
                    $status,
                    ['verified', 'approved', 'implemented'],
                    true
                )
                && $provenanceId === ''
            ) {
                $findings[] = $this->finding(
                    'trusted_entity_without_provenance',
                    'error',
                    'Trusted entity state lacks provenance.',
                    [
                        'index' => $index,
                        'entity_key' => $entityKey,
                        'status' => $status,
                    ],
                    [
                        'attach_provenance',
                        'downgrade_trust_status',
                    ]
                );
            }

            $checksum = trim(
                (string)(
                    $entity['checksum']
                    ?? ''
                )
            );

            $contentHash = trim(
                (string)(
                    $entity['content_hash']
                    ?? ''
                )
            );

            if (
                $checksum !== ''
                && $contentHash !== ''
                && hash_equals(
                    $checksum,
                    $contentHash
                )
            ) {
                $findings[] = $this->finding(
                    'checksum_reused_as_content_hash',
                    'notice',
                    'Entity checksum and content hash are identical; verify that record-level and content-level hashes were calculated separately.',
                    [
                        'index' => $index,
                        'entity_key' => $entityKey,
                        'hash' => $checksum,
                    ],
                    [
                        'verify_hashing_scope',
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * Find entity lifecycle conflicts.
     *
     * @param array<int,array<string,mixed>> $entities
     *
     * @return array<int,array<string,mixed>>
     */
    public function findEntityLifecycleConflicts(
        array $entities
    ): array {
        $findings = [];

        foreach ($entities as $index => $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $status = $this->normalizedScalar(
                $entity['status']
                ?? ''
            );

            $archivedAt = $this->timestamp(
                $entity['archived_at']
                    ?? null
            );

            $completedAt = $this->timestamp(
                $entity['completed_at']
                    ?? null
            );

            if (
                $status === 'archived'
                && $archivedAt === null
            ) {
                $findings[] = $this->finding(
                    'archived_entity_without_timestamp',
                    'warning',
                    'Archived entity has no archival timestamp.',
                    [
                        'index' => $index,

                        'entity_key' =>
                            $this->resolveEntityType($entity)
                            . ':'
                            . $this->resolveEntityId($entity),
                    ],
                    [
                        'set_archived_at',
                    ]
                );
            }

            if (
                $status !== 'archived'
                && $archivedAt !== null
            ) {
                $findings[] = $this->finding(
                    'active_entity_with_archive_timestamp',
                    'warning',
                    'Entity is not archived but retains an archival timestamp.',
                    [
                        'index' => $index,

                        'entity_key' =>
                            $this->resolveEntityType($entity)
                            . ':'
                            . $this->resolveEntityId($entity),

                        'status' => $status,

                        'archived_at' =>
                            $entity['archived_at']
                            ?? null,
                    ],
                    [
                        'confirm_entity_status',
                        'clear_or_preserve_archive_history',
                    ]
                );
            }

            if (
                $status === 'completed'
                && $completedAt === null
            ) {
                $findings[] = $this->finding(
                    'completed_entity_without_timestamp',
                    'notice',
                    'Completed entity has no completion timestamp.',
                    [
                        'index' => $index,

                        'entity_key' =>
                            $this->resolveEntityType($entity)
                            . ':'
                            . $this->resolveEntityId($entity),
                    ],
                    [
                        'set_completed_at',
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * Find references to unavailable entities.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<int,array<string,mixed>>
     */
    public function inspectReferences(
        array $entities,
        array $relationships
    ): array {
        $entityKeys = [];
        $findings = [];

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $entityId = $this->resolveEntityId(
                $entity
            );

            $entityType = $this->resolveEntityType(
                $entity
            );

            $key = $this->graphNodeKey(
                $entityType,
                $entityId
            );

            if ($key !== '') {
                $entityKeys[$key] = true;
            }
        }

        if ($entityKeys === []) {
            return [];
        }

        foreach ($relationships as $index => $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $sourceKey = $this->sourceNodeKey(
                $relationship
            );

            $targetKey = $this->targetNodeKey(
                $relationship
            );

            if (
                $sourceKey !== ''
                && !isset($entityKeys[$sourceKey])
            ) {
                $findings[] = $this->finding(
                    'missing_source_entity',
                    'error',
                    'Relationship source entity is absent from the supplied entity collection.',
                    [
                        'index' => $index,

                        'relationship_id' =>
                            $relationship[
                                'relationship_id'
                            ] ?? null,

                        'source_key' =>
                            $sourceKey,
                    ],
                    [
                        'restore_or_import_source_entity',
                        'archive_orphaned_relationship',
                    ]
                );
            }

            if (
                $targetKey !== ''
                && !isset($entityKeys[$targetKey])
            ) {
                $findings[] = $this->finding(
                    'missing_target_entity',
                    'error',
                    'Relationship target entity is absent from the supplied entity collection.',
                    [
                        'index' => $index,

                        'relationship_id' =>
                            $relationship[
                                'relationship_id'
                            ] ?? null,

                        'target_key' =>
                            $targetKey,
                    ],
                    [
                        'restore_or_import_target_entity',
                        'archive_orphaned_relationship',
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * Detect cycles within relationship types expected to be acyclic.
     *
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<int,array<string,mixed>>
     */
    public function inspectHierarchyCycles(
        array $relationships,
        int $maximumCycles = 250
    ): array {
        $maximumCycles = max(
            1,
            min(10000, $maximumCycles)
        );

        $findings = [];

        foreach ($this->acyclicTypes as $type) {
            $cycles = $this->traversal->cycles(
                $relationships,
                [$type],
                ['active', 'verified'],
                false,
                $maximumCycles
            );

            foreach ($cycles as $cycle) {
                $findings[] = $this->finding(
                    'acyclic_relationship_cycle',
                    'error',
                    sprintf(
                        'Relationship type "%s" forms a cycle but is expected to remain acyclic.',
                        $type
                    ),
                    [
                        'relationship_type' =>
                            $type,

                        'cycle' => $cycle,
                    ],
                    [
                        'review_hierarchy_direction',
                        'remove_or_dispute_incorrect_edge',
                    ]
                );
            }
        }

        return $findings;
    }

    /**
     * Determine whether two relationship types contradict.
     */
    public function typesContradict(
        string $leftType,
        string $rightType
    ): bool {
        $leftType = $this->normalizeType(
            $leftType
        );

        $rightType = $this->normalizeType(
            $rightType
        );

        if (
            $leftType === ''
            || $rightType === ''
            || $leftType === $rightType
        ) {
            return false;
        }

        return in_array(
            $rightType,
            $this->contradictoryTypes[
                $leftType
            ] ?? [],
            true
        )
            || in_array(
                $leftType,
                $this->contradictoryTypes[
                    $rightType
                ] ?? [],
                true
            );
    }

    /**
     * Compare two records directly.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     *
     * @return array<string,mixed>
     */
    public function compareRecords(
        array $left,
        array $right
    ): array {
        $fields = array_unique(
            array_merge(
                array_keys($left),
                array_keys($right)
            )
        );

        $differences = [];

        foreach ($fields as $field) {
            $leftValue = $left[$field] ?? null;
            $rightValue = $right[$field] ?? null;

            if (
                $this->normalizeForHash(
                    $leftValue
                )
                ===
                $this->normalizeForHash(
                    $rightValue
                )
            ) {
                continue;
            }

            $differences[$field] = [
                'left' => $leftValue,
                'right' => $rightValue,
            ];
        }

        return [
            'identical' =>
                $differences === [],

            'difference_count' =>
                count($differences),

            'differences' =>
                $differences,
        ];
    }

    /**
     * Mark a finding resolved while preserving the decision.
     *
     * @param array<string,mixed> $finding
     *
     * @return array<string,mixed>
     */
    public function resolve(
        array $finding,
        string $actorId,
        string $resolution,
        array $metadata = []
    ): array {
        $actorId = trim($actorId);
        $resolution = trim($resolution);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Consistency resolution requires actor attribution.'
            );
        }

        if ($resolution === '') {
            throw new InvalidArgumentException(
                'Consistency resolution requires a decision.'
            );
        }

        return array_merge(
            $finding,
            [
                'status' => 'resolved',

                'resolved_by' =>
                    $actorId,

                'resolved_at' =>
                    gmdate('c'),

                'resolution' =>
                    $resolution,

                'resolution_metadata' =>
                    $metadata,
            ]
        );
    }

    /**
     * Mark a finding accepted as an intentional exception.
     *
     * @param array<string,mixed> $finding
     *
     * @return array<string,mixed>
     */
    public function acceptException(
        array $finding,
        string $actorId,
        string $reason
    ): array {
        $actorId = trim($actorId);
        $reason = trim($reason);

        if (
            $actorId === ''
            || $reason === ''
        ) {
            throw new InvalidArgumentException(
                'Consistency exception requires attribution and reason.'
            );
        }

        return array_merge(
            $finding,
            [
                'status' =>
                    'accepted_exception',

                'accepted_by' =>
                    $actorId,

                'accepted_at' =>
                    gmdate('c'),

                'exception_reason' =>
                    $reason,
            ]
        );
    }

    /**
     * Return findings at or above one severity.
     *
     * @param array<int,array<string,mixed>> $findings
     *
     * @return array<int,array<string,mixed>>
     */
    public function minimumSeverity(
        array $findings,
        string $minimumSeverity
    ): array {
        $minimumSeverity =
            $this->normalizeSeverity(
                $minimumSeverity
            );

        $minimumScore =
            $this->severityOrder[
                $minimumSeverity
            ] ?? 0;

        return array_values(
            array_filter(
                $findings,
                fn (array $finding): bool =>
                    (
                        $this->severityOrder[
                            $this->normalizeSeverity(
                                (string)(
                                    $finding['severity']
                                    ?? 'notice'
                                )
                            )
                        ] ?? 0
                    ) >= $minimumScore
            )
        );
    }

    /**
     * Summarize consistency findings.
     *
     * @param array<int,array<string,mixed>> $findings
     *
     * @return array<string,mixed>
     */
    public function summarizeFindings(
        array $findings
    ): array {
        $types = [];
        $severities = [];
        $statuses = [];
        $blockingCount = 0;
        $resolvableCount = 0;

        foreach ($findings as $finding) {
            $type = trim(
                (string)(
                    $finding['type']
                    ?? 'unknown'
                )
            );

            $severity =
                $this->normalizeSeverity(
                    (string)(
                        $finding['severity']
                        ?? 'notice'
                    )
                );

            $status = trim(
                (string)(
                    $finding['status']
                    ?? 'open'
                )
            );

            $types[$type] =
                ($types[$type] ?? 0)
                + 1;

            $severities[$severity] =
                ($severities[$severity] ?? 0)
                + 1;

            $statuses[$status] =
                ($statuses[$status] ?? 0)
                + 1;

            if (
                in_array(
                    $severity,
                    ['critical', 'error'],
                    true
                )
                && !in_array(
                    $status,
                    [
                        'resolved',
                        'accepted_exception',
                    ],
                    true
                )
            ) {
                $blockingCount++;
            }

            if (
                (
                    $finding[
                        'recommended_actions'
                    ] ?? []
                ) !== []
            ) {
                $resolvableCount++;
            }
        }

        arsort($types);
        arsort($severities);
        arsort($statuses);

        return [
            'total' => count($findings),

            'blocking_count' =>
                $blockingCount,

            'non_blocking_count' =>
                count($findings)
                - $blockingCount,

            'resolvable_count' =>
                $resolvableCount,

            'types' => $types,

            'severities' => $severities,

            'statuses' => $statuses,
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
                'contradictory_types' =>
                    $this->contradictoryTypes,

                'acyclic_types' =>
                    $this->acyclicTypes,

                'incompatible_statuses' =>
                    $this->incompatibleStatuses,

                'single_value_fields' =>
                    $this->singleValueFields,

                'automatic_resolution' =>
                    false,

                'exception_attribution_required' =>
                    true,

                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Create one consistency finding.
     *
     * @return array<string,mixed>
     */
    private function finding(
        string $type,
        string $severity,
        string $message,
        array $evidence = [],
        array $recommendedActions = []
    ): array {
        return [
            'finding_id' =>
                $this->generateFindingId(),

            'type' =>
                $this->normalizeType($type),

            'severity' =>
                $this->normalizeSeverity(
                    $severity
                ),

            'status' => 'open',

            'message' => trim($message),

            'evidence' => $evidence,

            'recommended_actions' =>
                array_values(
                    array_unique(
                        array_filter(
                            array_map(
                                static fn (
                                    mixed $action
                                ): string =>
                                    trim(
                                        (string)$action
                                    ),
                                $recommendedActions
                            )
                        )
                    )
                ),

            'detected_by' =>
                static::class,

            'detected_at' =>
                gmdate('c'),

            'requires_human_resolution' =>
                true,
        ];
    }

    /**
     * Compare inverse shared state.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     *
     * @return array<string,array<string,mixed>>
     */
    private function inverseDifferences(
        array $left,
        array $right
    ): array {
        $fields = [
            'confidence',
            'weight',
            'strength',
            'status',
            'valid_from',
            'valid_to',
            'tags',
        ];

        $differences = [];

        foreach ($fields as $field) {
            $leftValue = $left[$field] ?? null;
            $rightValue = $right[$field] ?? null;

            if (
                $this->normalizeForHash(
                    $leftValue
                )
                ===
                $this->normalizeForHash(
                    $rightValue
                )
            ) {
                continue;
            }

            $differences[$field] = [
                'left' => $leftValue,
                'right' => $rightValue,
            ];
        }

        return $differences;
    }

    /**
     * Determine whether two records have identical directional endpoints.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function sameOrderedEndpoints(
        array $left,
        array $right
    ): bool {
        return $this->sourceNodeKey($left)
            !== ''
            && $this->sourceNodeKey($left)
                === $this->sourceNodeKey($right)
            && $this->targetNodeKey($left)
                === $this->targetNodeKey($right);
    }

    /**
     * Determine whether two records connect the same nodes in either direction.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function sameUnorderedEndpoints(
        array $left,
        array $right
    ): bool {
        $leftNodes = [
            $this->sourceNodeKey($left),
            $this->targetNodeKey($left),
        ];

        $rightNodes = [
            $this->sourceNodeKey($right),
            $this->targetNodeKey($right),
        ];

        sort($leftNodes);
        sort($rightNodes);

        return $leftNodes[0] !== ''
            && $leftNodes === $rightNodes;
    }

    /**
     * Determine whether a relationship participates in operational truth.
     *
     * @param array<string,mixed> $relationship
     */
    private function isOperationalRelationship(
        array $relationship
    ): bool {
        $status = $this->status(
            $relationship
        );

        if (
            !in_array(
                $status,
                [
                    'active',
                    'verified',
                    'proposed',
                    'disputed',
                ],
                true
            )
        ) {
            return false;
        }

        return $this->isTemporallyActive(
            $relationship
        );
    }

    /**
     * Normalize relationship status.
     *
     * @param array<string,mixed> $relationship
     */
    private function status(
        array $relationship
    ): string {
        return $this->normalizedScalar(
            $relationship['status']
            ?? ''
        );
    }

    /**
     * Normalize relationship type.
     *
     * @param array<string,mixed> $relationship
     */
    private function relationshipType(
        array $relationship
    ): string {
        return $this->normalizeType(
            (string)(
                $relationship[
                    'relationship_type'
                ]
                ?? ''
            )
        );
    }

    /**
     * Return source node key.
     *
     * @param array<string,mixed> $relationship
     */
    private function sourceNodeKey(
        array $relationship
    ): string {
        return $this->graphNodeKey(
            $this->normalizeEntityType(
                (string)(
                    $relationship['source_type']
                    ?? ''
                )
            ),
            trim(
                (string)(
                    $relationship['source_id']
                    ?? ''
                )
            )
        );
    }

    /**
     * Return target node key.
     *
     * @param array<string,mixed> $relationship
     */
    private function targetNodeKey(
        array $relationship
    ): string {
        return $this->graphNodeKey(
            $this->normalizeEntityType(
                (string)(
                    $relationship['target_type']
                    ?? ''
                )
            ),
            trim(
                (string)(
                    $relationship['target_id']
                    ?? ''
                )
            )
        );
    }

    /**
     * Resolve generic entity identifier.
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
                'url_id',
                'person_id',
                'organization_id',
                'program_id',
                'decision_id',
                'mission_id',
                'relationship_id',
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
     * Resolve generic entity type.
     *
     * @param array<string,mixed> $entity
     */
    private function resolveEntityType(
        array $entity
    ): string {
        return $this->normalizeEntityType(
            (string)(
                $entity['entity_type']
                ?? $entity['type']
                ?? 'entity'
            )
        );
    }

    /**
     * Build a grouping identity for potentially duplicated entity records.
     *
     * @param array<string,mixed> $entity
     */
    private function entityIdentityGroupKey(
        array $entity
    ): string {
        $entityType = $this->resolveEntityType(
            $entity
        );

        foreach (
            [
                'entity_id',
                'asset_id',
                'translation_id',
                'document_id',
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
                return $entityType
                    . ':'
                    . $value;
            }
        }

        $sourceReference = $this->normalizedScalar(
            $entity['source_reference']
            ?? $entity['url']
            ?? ''
        );

        if ($sourceReference !== '') {
            return $entityType
                . ':source:'
                . hash(
                    'sha256',
                    $sourceReference
                );
        }

        return '';
    }

    /**
     * Determine temporal validity.
     *
     * @param array<string,mixed> $relationship
     */
    private function isTemporallyActive(
        array $relationship
    ): bool {
        $now = time();

        $validFrom = $this->timestamp(
            $relationship['valid_from']
                ?? null
        );

        if (
            $validFrom !== null
            && $validFrom > $now
        ) {
            return false;
        }

        $validTo = $this->timestamp(
            $relationship['valid_to']
                ?? null
        );

        if (
            $validTo !== null
            && $validTo < $now
        ) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether two time ranges overlap.
     */
    private function timeRangesOverlap(
        mixed $leftStart,
        mixed $leftEnd,
        mixed $rightStart,
        mixed $rightEnd
    ): bool {
        $leftStartTime =
            $this->timestamp($leftStart)
            ?? PHP_INT_MIN;

        $leftEndTime =
            $this->timestamp($leftEnd)
            ?? PHP_INT_MAX;

        $rightStartTime =
            $this->timestamp($rightStart)
            ?? PHP_INT_MIN;

        $rightEndTime =
            $this->timestamp($rightEnd)
            ?? PHP_INT_MAX;

        return $leftStartTime <= $rightEndTime
            && $rightStartTime <= $leftEndTime;
    }

    /**
     * Convert a date value to timestamp.
     */
    private function timestamp(
        mixed $value
    ): ?int {
        if (
            $value === null
            || trim((string)$value) === ''
        ) {
            return null;
        }

        $timestamp = strtotime(
            (string)$value
        );

        return $timestamp === false
            ? null
            : $timestamp;
    }

    /**
     * Safely calculate canonical edge key.
     *
     * @param array<string,mixed> $relationship
     */
    private function safeEdgeKey(
        array $relationship
    ): string {
        try {
            return $this->relationships->edgeKey(
                $relationship
            );
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Normalize a record before duplicate comparison.
     *
     * @param array<string,mixed> $record
     */
    private function normalizedRecordForComparison(
        array $record
    ): mixed {
        $copy = $record;

        unset(
            $copy['relationship_id'],
            $copy['created_at'],
            $copy['updated_at'],
            $copy['checksum']
        );

        return $this->normalizeForHash(
            $copy
        );
    }

    /**
     * Calculate relationship checksum.
     *
     * @param array<string,mixed> $relationship
     */
    private function relationshipChecksum(
        array $relationship
    ): string {
        $copy = $relationship;

        unset($copy['checksum']);

        $json = json_encode(
            $this->normalizeForHash($copy),
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
        );

        if ($json === false) {
            throw new RuntimeException(
                'Unable to calculate relationship checksum.'
            );
        }

        return hash('sha256', $json);
    }

    /**
     * Verify relationship checksum.
     *
     * @param array<string,mixed> $relationship
     */
    private function relationshipChecksumMatches(
        array $relationship
    ): bool {
        $stored = trim(
            (string)(
                $relationship['checksum']
                ?? ''
            )
        );

        if ($stored === '') {
            return false;
        }

        return hash_equals(
            $stored,
            $this->relationshipChecksum(
                $relationship
            )
        );
    }

    /**
     * Deduplicate equivalent findings.
     *
     * @param array<int,array<string,mixed>> $findings
     *
     * @return array<int,array<string,mixed>>
     */
    private function deduplicateFindings(
        array $findings
    ): array {
        $unique = [];

        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }

            $keyMaterial = [
                'type' =>
                    $finding['type']
                    ?? '',

                'severity' =>
                    $finding['severity']
                    ?? '',

                'message' =>
                    $finding['message']
                    ?? '',

                'evidence' =>
                    $finding['evidence']
                    ?? [],
            ];

            $json = json_encode(
                $this->normalizeForHash(
                    $keyMaterial
                ),
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
            );

            if ($json === false) {
                $unique[] = $finding;
                continue;
            }

            $key = hash(
                'sha256',
                $json
            );

            if (!isset($unique[$key])) {
                $unique[$key] = $finding;
            }
        }

        return array_values($unique);
    }

    /**
     * Sort findings by severity and type.
     *
     * @param array<int,array<string,mixed>> $findings
     */
    private function sortFindings(
        array &$findings
    ): void {
        usort(
            $findings,
            function (
                array $left,
                array $right
            ): int {
                $leftSeverity =
                    $this->severityOrder[
                        $this->normalizeSeverity(
                            (string)(
                                $left['severity']
                                ?? 'notice'
                            )
                        )
                    ] ?? 0;

                $rightSeverity =
                    $this->severityOrder[
                        $this->normalizeSeverity(
                            (string)(
                                $right['severity']
                                ?? 'notice'
                            )
                        )
                    ] ?? 0;

                if ($leftSeverity !== $rightSeverity) {
                    return $rightSeverity
                        <=> $leftSeverity;
                }

                $typeComparison = strcmp(
                    (string)(
                        $left['type']
                        ?? ''
                    ),
                    (string)(
                        $right['type']
                        ?? ''
                    )
                );

                if ($typeComparison !== 0) {
                    return $typeComparison;
                }

                return strcmp(
                    (string)(
                        $left['finding_id']
                        ?? ''
                    ),
                    (string)(
                        $right['finding_id']
                        ?? ''
                    )
                );
            }
        );
    }

    /**
     * Normalize entity type.
     */
    private function normalizeEntityType(
        string $entityType
    ): string {
        return $this->normalizeType(
            $entityType
        );
    }

    /**
     * Normalize machine key.
     */
    private function normalizeType(
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
     * Normalize severity.
     */
    private function normalizeSeverity(
        string $severity
    ): string {
        $severity = strtolower(
            trim($severity)
        );

        return array_key_exists(
            $severity,
            $this->severityOrder
        )
            ? $severity
            : 'notice';
    }

    /**
     * Normalize scalar comparison value.
     */
    private function normalizedScalar(
        mixed $value
    ): string {
        return strtolower(
            trim((string)$value)
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
                '/[\r\n,]+/',
                $values
            ) ?: [];
        }

        if (!is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            $value = $this->normalizeType(
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
     * Build a stable pair key.
     */
    private function pairKey(
        string $left,
        string $right
    ): string {
        $parts = [
            trim($left),
            trim($right),
        ];

        sort($parts);

        return implode('|', $parts);
    }

    /**
     * Generate finding identifier.
     */
    private function generateFindingId(): string
    {
        try {
            $random = strtoupper(
                bin2hex(
                    random_bytes(6)
                )
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
                    12
                )
            );
        }

        return 'CON-'
            . gmdate('Ymd-His')
            . '-'
            . $random;
    }
}