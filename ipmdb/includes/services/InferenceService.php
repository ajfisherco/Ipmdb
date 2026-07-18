<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/InferenceService.php
|--------------------------------------------------------------------------
| IPMdb Inference Service
|--------------------------------------------------------------------------
|
| Derives explainable relationship proposals from accepted graph facts.
|
| Responsibilities:
| - Apply deterministic relationship inference rules.
| - Derive transitive relationships.
| - Derive inverse relationships.
| - Derive symmetric relationships.
| - Propagate selected classifications and alignments.
| - Preserve evidence paths for every inferred edge.
| - Prevent duplicate, recursive, and unsupported conclusions.
| - Return proposals requiring validation and acceptance.
|
| Inference proposes.
| Evidence explains.
| Validation tests.
| The Doer decides.
|
| This service performs no database operations.
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

final class InferenceService extends Service
{
    use GraphUtilities;

    private RelationshipService $relationships;

    private GraphTraversalService $traversal;

    private PathService $paths;

    /**
     * Relationship types that may be inferred transitively.
     *
     * Example:
     *
     * A parent_of B
     * B parent_of C
     * therefore
     * A parent_of C
     *
     * @var array<int,string>
     */
    private array $transitiveTypes = [
        'parent_of',
        'contains',
        'depends_on',
        'derived_from',
        'extends',
        'implements',
        'aligns_with',
        'member_of',
        'belongs_to',
        'evidence_for',
    ];

    /**
     * Relationship types that are symmetric.
     *
     * Example:
     *
     * A related_to B
     * therefore
     * B related_to A
     *
     * @var array<int,string>
     */
    private array $symmetricTypes = [
        'related_to',
        'same_as',
        'duplicate_of',
        'contradicts',
    ];

    /**
     * Relationship types whose inverse may be inferred.
     *
     * @var array<string,string>
     */
    private array $inverseTypes = [
        'contains' => 'contained_by',
        'contained_by' => 'contains',

        'parent_of' => 'child_of',
        'child_of' => 'parent_of',

        'member_of' => 'has_member',
        'has_member' => 'member_of',

        'references' => 'referenced_by',
        'referenced_by' => 'references',

        'supports' => 'supported_by',
        'supported_by' => 'supports',

        'funds' => 'funded_by',
        'funded_by' => 'funds',

        'derived_from' => 'derives',
        'derives' => 'derived_from',

        'created_by' => 'created',
        'verified_by' => 'verified',
        'approved_by' => 'approved',

        'administered_by' => 'administers',
        'regulated_by' => 'regulates',

        'mission_of' => 'has_mission',
        'objective_of' => 'has_objective',

        'translated_from' => 'translated_as',
        'translated_as' => 'translated_from',
    ];

    /**
     * Chain rules derive a third relationship from two different types.
     *
     * Format:
     *
     * left_type + right_type => inferred_type
     *
     * @var array<string,string>
     */
    private array $chainRules = [
        'contains|contains' => 'contains',

        'parent_of|parent_of' => 'parent_of',

        'depends_on|depends_on' => 'depends_on',

        'derived_from|derived_from' => 'derived_from',

        'extends|extends' => 'extends',

        'implements|implements' => 'implements',

        'aligns_with|aligns_with' => 'aligns_with',

        'member_of|member_of' => 'member_of',

        'belongs_to|belongs_to' => 'belongs_to',

        'supports|supports' => 'supports',

        'evidence_for|supports' => 'evidence_for',

        'supports|evidence_for' => 'evidence_for',

        'derived_from|supports' => 'related_to',

        'references|supports' => 'related_to',

        'implements|aligns_with' => 'aligns_with',

        'eligible_for|administered_by' => 'aligns_with',

        'regulated_by|administered_by' => 'related_to',

        'funded_by|administered_by' => 'aligns_with',

        'mission_of|objective_of' => 'related_to',
    ];

    /**
     * Default status values eligible for inference.
     *
     * @var array<int,string>
     */
    private array $eligibleStatuses = [
        'active',
        'verified',
    ];

    /**
     * Relationship types excluded from automatic inference.
     *
     * @var array<int,string>
     */
    private array $excludedTypes = [
        'rejected',
        'archived',
        'disputed',
        'duplicate_of',
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
     * Run all enabled inference strategies.
     *
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    public function infer(
        array $relationships,
        array $options = []
    ): array {
        $this->reset();

        $minimumConfidence = $this->clamp(
            (float)(
                $options['minimum_confidence']
                ?? 40.0
            ),
            0.0,
            100.0
        );

        $maximumInferences = max(
            1,
            min(
                100000,
                (int)(
                    $options['maximum_inferences']
                    ?? 1000
                )
            )
        );

        $statuses = $this->normalizeStringList(
            $options['statuses']
                ?? $this->eligibleStatuses
        );

        $allowedTypes = $this->normalizeStringList(
            $options['relationship_types']
                ?? []
        );

        $filtered = $this->filterRelationships(
            $relationships,
            $statuses,
            $allowedTypes,
            (bool)(
                $options['include_expired']
                ?? false
            )
        );

        $inferences = [];

        if (
            (bool)(
                $options['infer_inverses']
                ?? true
            )
        ) {
            $inferences = array_merge(
                $inferences,
                $this->inferInverses(
                    $filtered,
                    $relationships,
                    $minimumConfidence
                )
            );
        }

        if (
            (bool)(
                $options['infer_symmetric']
                ?? true
            )
        ) {
            $inferences = array_merge(
                $inferences,
                $this->inferSymmetric(
                    $filtered,
                    $relationships,
                    $minimumConfidence
                )
            );
        }

        if (
            (bool)(
                $options['infer_transitive']
                ?? true
            )
        ) {
            $inferences = array_merge(
                $inferences,
                $this->inferTransitive(
                    $filtered,
                    $relationships,
                    $minimumConfidence,
                    (int)(
                        $options['maximum_depth']
                        ?? 4
                    )
                )
            );
        }

        if (
            (bool)(
                $options['infer_chain_rules']
                ?? true
            )
        ) {
            $inferences = array_merge(
                $inferences,
                $this->inferChainRules(
                    $filtered,
                    $relationships,
                    $minimumConfidence
                )
            );
        }

        $inferences = $this->deduplicateInferences(
            $inferences,
            $relationships
        );

        usort(
            $inferences,
            static function (
                array $left,
                array $right
            ): int {
                $confidenceComparison =
                    (float)(
                        $right['confidence']
                        ?? 0
                    )
                    <=>
                    (float)(
                        $left['confidence']
                        ?? 0
                    );

                if ($confidenceComparison !== 0) {
                    return $confidenceComparison;
                }

                $evidenceComparison =
                    (int)(
                        $right['evidence_count']
                        ?? 0
                    )
                    <=>
                    (int)(
                        $left['evidence_count']
                        ?? 0
                    );

                if ($evidenceComparison !== 0) {
                    return $evidenceComparison;
                }

                return strcmp(
                    (string)(
                        $left['inference_id']
                        ?? ''
                    ),
                    (string)(
                        $right['inference_id']
                        ?? ''
                    )
                );
            }
        );

        $inferences = array_slice(
            $inferences,
            0,
            $maximumInferences
        );

        $result = [
            'generated_at' => gmdate('c'),

            'input_relationship_count' =>
                count($relationships),

            'eligible_relationship_count' =>
                count($filtered),

            'inference_count' =>
                count($inferences),

            'minimum_confidence' =>
                $minimumConfidence,

            'maximum_inferences' =>
                $maximumInferences,

            'inferences' =>
                $inferences,

            'summary' =>
                $this->summarize(
                    $inferences
                ),
        ];

        $this->addMessage(
            'Graph inference completed.',
            [
                'input_relationship_count' =>
                    count($relationships),

                'eligible_relationship_count' =>
                    count($filtered),

                'inference_count' =>
                    count($inferences),
            ]
        );

        return $result;
    }

    /**
     * Infer canonical inverse edges.
     *
     * @param array<int,array<string,mixed>> $eligible
     * @param array<int,array<string,mixed>> $allRelationships
     *
     * @return array<int,array<string,mixed>>
     */
    public function inferInverses(
        array $eligible,
        array $allRelationships = [],
        float $minimumConfidence = 40.0
    ): array {
        $inferences = [];

        foreach ($eligible as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            if (
                ($relationship['directional'] ?? true)
                === false
            ) {
                continue;
            }

            $relationshipType = trim(
                (string)(
                    $relationship[
                        'relationship_type'
                    ]
                    ?? ''
                )
            );

            $inverseType =
                $this->inverseType(
                    $relationshipType
                );

            if ($inverseType === null) {
                continue;
            }

            $sourceId = trim(
                (string)(
                    $relationship['source_id']
                    ?? ''
                )
            );

            $sourceType = trim(
                (string)(
                    $relationship['source_type']
                    ?? ''
                )
            );

            $targetId = trim(
                (string)(
                    $relationship['target_id']
                    ?? ''
                )
            );

            $targetType = trim(
                (string)(
                    $relationship['target_type']
                    ?? ''
                )
            );

            if (
                $this->relationshipExists(
                    $allRelationships,
                    $targetId,
                    $targetType,
                    $sourceId,
                    $sourceType,
                    $inverseType
                )
            ) {
                continue;
            }

            $confidence =
                $this->singleEdgeConfidence(
                    $relationship,
                    0.98
                );

            if ($confidence < $minimumConfidence) {
                continue;
            }

            $inferences[] =
                $this->buildInference(
                    $targetId,
                    $targetType,
                    $sourceId,
                    $sourceType,
                    $inverseType,
                    $confidence,
                    'inverse',
                    [
                        $this->evidenceFromRelationship(
                            $relationship,
                            'Canonical inverse relationship.'
                        ),
                    ],
                    [
                        'source_relationship_id' =>
                            $relationship[
                                'relationship_id'
                            ] ?? null,
                    ]
                );
        }

        return $inferences;
    }

    /**
     * Infer reverse symmetric edges.
     *
     * @param array<int,array<string,mixed>> $eligible
     * @param array<int,array<string,mixed>> $allRelationships
     *
     * @return array<int,array<string,mixed>>
     */
    public function inferSymmetric(
        array $eligible,
        array $allRelationships = [],
        float $minimumConfidence = 40.0
    ): array {
        $inferences = [];

        foreach ($eligible as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $relationshipType = trim(
                (string)(
                    $relationship[
                        'relationship_type'
                    ]
                    ?? ''
                )
            );

            if (
                !in_array(
                    $relationshipType,
                    $this->symmetricTypes,
                    true
                )
            ) {
                continue;
            }

            $sourceId = trim(
                (string)(
                    $relationship['source_id']
                    ?? ''
                )
            );

            $sourceType = trim(
                (string)(
                    $relationship['source_type']
                    ?? ''
                )
            );

            $targetId = trim(
                (string)(
                    $relationship['target_id']
                    ?? ''
                )
            );

            $targetType = trim(
                (string)(
                    $relationship['target_type']
                    ?? ''
                )
            );

            if (
                $this->relationshipExists(
                    $allRelationships,
                    $targetId,
                    $targetType,
                    $sourceId,
                    $sourceType,
                    $relationshipType
                )
            ) {
                continue;
            }

            $confidence =
                $this->singleEdgeConfidence(
                    $relationship,
                    0.96
                );

            if ($confidence < $minimumConfidence) {
                continue;
            }

            $inferences[] =
                $this->buildInference(
                    $targetId,
                    $targetType,
                    $sourceId,
                    $sourceType,
                    $relationshipType,
                    $confidence,
                    'symmetric',
                    [
                        $this->evidenceFromRelationship(
                            $relationship,
                            'Symmetric relationship rule.'
                        ),
                    ],
                    [
                        'source_relationship_id' =>
                            $relationship[
                                'relationship_id'
                            ] ?? null,
                    ]
                );
        }

        return $inferences;
    }

    /**
     * Infer transitive edges.
     *
     * @param array<int,array<string,mixed>> $eligible
     * @param array<int,array<string,mixed>> $allRelationships
     *
     * @return array<int,array<string,mixed>>
     */
    public function inferTransitive(
        array $eligible,
        array $allRelationships = [],
        float $minimumConfidence = 40.0,
        int $maximumDepth = 4
    ): array {
        $maximumDepth = max(
            2,
            min(20, $maximumDepth)
        );

        $inferences = [];

        foreach ($this->transitiveTypes as $type) {
            $typed = array_values(
                array_filter(
                    $eligible,
                    static fn (
                        array $relationship
                    ): bool =>
                        (
                            $relationship[
                                'relationship_type'
                            ]
                            ?? ''
                        ) === $type
                )
            );

            if (count($typed) < 2) {
                continue;
            }

            $adjacency = [];

            foreach ($typed as $relationship) {
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

                $adjacency[$sourceKey][] = [
                    'target_key' => $targetKey,
                    'relationship' =>
                        $relationship,
                ];
            }

            foreach (
                array_keys($adjacency)
                as $startKey
            ) {
                $queue = [
                    [
                        'node_key' => $startKey,
                        'depth' => 0,
                        'path' => [],
                        'visited' => [
                            $startKey => true,
                        ],
                    ],
                ];

                while ($queue !== []) {
                    $current = array_shift($queue);

                    if (!is_array($current)) {
                        continue;
                    }

                    $depth = (int)(
                        $current['depth']
                        ?? 0
                    );

                    if ($depth >= $maximumDepth) {
                        continue;
                    }

                    $currentKey = trim(
                        (string)(
                            $current['node_key']
                            ?? ''
                        )
                    );

                    foreach (
                        $adjacency[$currentKey]
                            ?? []
                        as $connection
                    ) {
                        $targetKey = trim(
                            (string)(
                                $connection[
                                    'target_key'
                                ]
                                ?? ''
                            )
                        );

                        if (
                            $targetKey === ''
                            || isset(
                                $current['visited'][
                                    $targetKey
                                ]
                            )
                        ) {
                            continue;
                        }

                        $nextPath = array_merge(
                            $current['path'],
                            [
                                $connection[
                                    'relationship'
                                ],
                            ]
                        );

                        $nextDepth = $depth + 1;

                        if ($nextDepth >= 2) {
                            [
                                $sourceType,
                                $sourceId,
                            ] = $this->splitNodeKey(
                                $startKey
                            );

                            [
                                $targetType,
                                $targetId,
                            ] = $this->splitNodeKey(
                                $targetKey
                            );

                            if (
                                !$this->relationshipExists(
                                    $allRelationships,
                                    $sourceId,
                                    $sourceType,
                                    $targetId,
                                    $targetType,
                                    $type
                                )
                            ) {
                                $confidence =
                                    $this->pathConfidence(
                                        $nextPath,
                                        0.92
                                    );

                                if (
                                    $confidence
                                    >= $minimumConfidence
                                ) {
                                    $inferences[] =
                                        $this->buildInference(
                                            $sourceId,
                                            $sourceType,
                                            $targetId,
                                            $targetType,
                                            $type,
                                            $confidence,
                                            'transitive',
                                            array_map(
                                                fn (
                                                    array $edge
                                                ): array =>
                                                    $this->evidenceFromRelationship(
                                                        $edge,
                                                        'Transitive path evidence.'
                                                    ),
                                                $nextPath
                                            ),
                                            [
                                                'path_length' =>
                                                    count(
                                                        $nextPath
                                                    ),

                                                'path_relationship_ids' =>
                                                    $this->relationshipIds(
                                                        $nextPath
                                                    ),
                                            ]
                                        );
                                }
                            }
                        }

                        $nextVisited =
                            $current['visited'];

                        $nextVisited[$targetKey] =
                            true;

                        $queue[] = [
                            'node_key' => $targetKey,
                            'depth' => $nextDepth,
                            'path' => $nextPath,
                            'visited' =>
                                $nextVisited,
                        ];
                    }
                }
            }
        }

        return $inferences;
    }

    /**
     * Apply configured two-edge chain rules.
     *
     * @param array<int,array<string,mixed>> $eligible
     * @param array<int,array<string,mixed>> $allRelationships
     *
     * @return array<int,array<string,mixed>>
     */
    public function inferChainRules(
        array $eligible,
        array $allRelationships = [],
        float $minimumConfidence = 40.0
    ): array {
        $inferences = [];

        $outgoingBySource = [];

        foreach ($eligible as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $sourceKey =
                $this->relationshipSourceKey(
                    $relationship
                );

            if ($sourceKey === '') {
                continue;
            }

            $outgoingBySource[$sourceKey][] =
                $relationship;
        }

        foreach ($eligible as $left) {
            if (!is_array($left)) {
                continue;
            }

            $middleKey =
                $this->relationshipTargetKey(
                    $left
                );

            if ($middleKey === '') {
                continue;
            }

            foreach (
                $outgoingBySource[$middleKey]
                    ?? []
                as $right
            ) {
                if (!is_array($right)) {
                    continue;
                }

                $leftType = trim(
                    (string)(
                        $left[
                            'relationship_type'
                        ]
                        ?? ''
                    )
                );

                $rightType = trim(
                    (string)(
                        $right[
                            'relationship_type'
                        ]
                        ?? ''
                    )
                );

                $ruleKey =
                    $leftType . '|' . $rightType;

                $inferredType =
                    $this->chainRules[$ruleKey]
                    ?? null;

                if ($inferredType === null) {
                    continue;
                }

                $sourceId = trim(
                    (string)(
                        $left['source_id']
                        ?? ''
                    )
                );

                $sourceType = trim(
                    (string)(
                        $left['source_type']
                        ?? ''
                    )
                );

                $targetId = trim(
                    (string)(
                        $right['target_id']
                        ?? ''
                    )
                );

                $targetType = trim(
                    (string)(
                        $right['target_type']
                        ?? ''
                    )
                );

                if (
                    $sourceId === ''
                    || $targetId === ''
                    || (
                        $sourceId === $targetId
                        && $sourceType === $targetType
                    )
                ) {
                    continue;
                }

                if (
                    $this->relationshipExists(
                        $allRelationships,
                        $sourceId,
                        $sourceType,
                        $targetId,
                        $targetType,
                        $inferredType
                    )
                ) {
                    continue;
                }

                $confidence =
                    $this->pathConfidence(
                        [$left, $right],
                        0.86
                    );

                if ($confidence < $minimumConfidence) {
                    continue;
                }

                $inferences[] =
                    $this->buildInference(
                        $sourceId,
                        $sourceType,
                        $targetId,
                        $targetType,
                        $inferredType,
                        $confidence,
                        'chain_rule',
                        [
                            $this->evidenceFromRelationship(
                                $left,
                                sprintf(
                                    'Left side of rule %s.',
                                    $ruleKey
                                )
                            ),

                            $this->evidenceFromRelationship(
                                $right,
                                sprintf(
                                    'Right side of rule %s.',
                                    $ruleKey
                                )
                            ),
                        ],
                        [
                            'rule' => $ruleKey,
                            'source_relationship_ids' => [
                                $left[
                                    'relationship_id'
                                ] ?? null,

                                $right[
                                    'relationship_id'
                                ] ?? null,
                            ],
                        ]
                    );
            }
        }

        return $inferences;
    }

    /**
     * Convert an accepted inference into a relationship record.
     *
     * @param array<string,mixed> $inference
     *
     * @return array<string,mixed>
     */
    public function accept(
        array $inference,
        string $actorId,
        array $overrides = []
    ): array {
        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Inference acceptance requires actor attribution.'
            );
        }

        $sourceId = trim(
            (string)(
                $inference['source_id']
                ?? ''
            )
        );

        $targetId = trim(
            (string)(
                $inference['target_id']
                ?? ''
            )
        );

        if (
            $sourceId === ''
            || $targetId === ''
        ) {
            throw new InvalidArgumentException(
                'Inference requires source and target identifiers.'
            );
        }

        $metadata = is_array(
            $inference['metadata']
                ?? null
        )
            ? $inference['metadata']
            : [];

        $metadata['inference_id'] =
            $inference['inference_id']
            ?? null;

        $metadata['inference_strategy'] =
            $inference['strategy']
            ?? null;

        $metadata['inference_evidence'] =
            $inference['evidence']
            ?? [];

        $metadata['accepted_by'] =
            $actorId;

        $metadata['accepted_at'] =
            gmdate('c');

        $input = array_merge(
            [
                'source_id' => $sourceId,

                'source_type' => trim(
                    (string)(
                        $inference[
                            'source_type'
                        ]
                        ?? 'entity'
                    )
                ),

                'target_id' => $targetId,

                'target_type' => trim(
                    (string)(
                        $inference[
                            'target_type'
                        ]
                        ?? 'entity'
                    )
                ),

                'relationship_type' =>
                    trim(
                        (string)(
                            $inference[
                                'relationship_type'
                            ]
                            ?? 'related_to'
                        )
                    ),

                'label' => ucwords(
                    str_replace(
                        '_',
                        ' ',
                        (string)(
                            $inference[
                                'relationship_type'
                            ]
                            ?? 'related_to'
                        )
                    )
                ),

                'description' => trim(
                    (string)(
                        $inference[
                            'explanation'
                        ]
                        ?? ''
                    )
                ),

                'confidence' =>
                    $this->clamp(
                        (float)(
                            $inference[
                                'confidence'
                            ]
                            ?? 0
                        ),
                        0.0,
                        100.0
                    ),

                'weight' =>
                    $this->confidenceWeight(
                        (float)(
                            $inference[
                                'confidence'
                            ]
                            ?? 0
                        )
                    ),

                'strength' =>
                    $this->inferenceStrength(
                        $inference
                    ),

                'status' => 'proposed',

                'created_by' => $actorId,

                'suggested_by_ai' => true,

                'accepted_by_human' => true,

                'metadata' => $metadata,

                'tags' => [
                    'inferred_relationship',
                    'human_accepted',
                    (string)(
                        $inference[
                            'strategy'
                        ]
                        ?? 'inference'
                    ),
                ],
            ],
            $overrides
        );

        $relationship =
            $this->relationships->create(
                $input
            );

        $this->addMessage(
            'Inference accepted as relationship.',
            [
                'inference_id' =>
                    $inference['inference_id']
                    ?? null,

                'relationship_id' =>
                    $relationship[
                        'relationship_id'
                    ] ?? null,

                'accepted_by' => $actorId,
            ]
        );

        return $relationship;
    }

    /**
     * Reject an inference while preserving the decision.
     *
     * @param array<string,mixed> $inference
     *
     * @return array<string,mixed>
     */
    public function reject(
        array $inference,
        string $actorId,
        string $reason = ''
    ): array {
        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Inference rejection requires actor attribution.'
            );
        }

        return array_merge(
            $inference,
            [
                'status' => 'rejected',

                'rejected_by' =>
                    $actorId,

                'rejected_at' =>
                    gmdate('c'),

                'rejection_reason' =>
                    trim($reason),
            ]
        );
    }

    /**
     * Return inferences involving one entity.
     *
     * @param array<int,array<string,mixed>> $inferences
     *
     * @return array<int,array<string,mixed>>
     */
    public function forEntity(
        array $inferences,
        string $entityId,
        ?string $entityType = null
    ): array {
        $entityId = trim($entityId);

        $entityType = $entityType !== null
            ? $this->normalizeEntityType(
                $entityType
            )
            : null;

        return array_values(
            array_filter(
                $inferences,
                static function (
                    array $inference
                ) use (
                    $entityId,
                    $entityType
                ): bool {
                    $sourceMatches =
                        trim(
                            (string)(
                                $inference[
                                    'source_id'
                                ]
                                ?? ''
                            )
                        ) === $entityId
                        && (
                            $entityType === null
                            || trim(
                                (string)(
                                    $inference[
                                        'source_type'
                                    ]
                                    ?? ''
                                )
                            ) === $entityType
                        );

                    $targetMatches =
                        trim(
                            (string)(
                                $inference[
                                    'target_id'
                                ]
                                ?? ''
                            )
                        ) === $entityId
                        && (
                            $entityType === null
                            || trim(
                                (string)(
                                    $inference[
                                        'target_type'
                                    ]
                                    ?? ''
                                )
                            ) === $entityType
                        );

                    return $sourceMatches
                        || $targetMatches;
                }
            )
        );
    }

    /**
     * Summarize inference proposals.
     *
     * @param array<int,array<string,mixed>> $inferences
     *
     * @return array<string,mixed>
     */
    public function summarize(
        array $inferences
    ): array {
        $strategies = [];
        $types = [];
        $confidenceBands = [
            'very_high' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];

        $totalConfidence = 0.0;
        $evidenceCount = 0;

        foreach ($inferences as $inference) {
            $strategy = trim(
                (string)(
                    $inference['strategy']
                    ?? 'unknown'
                )
            );

            $type = trim(
                (string)(
                    $inference[
                        'relationship_type'
                    ]
                    ?? 'related_to'
                )
            );

            $strategies[$strategy] =
                ($strategies[$strategy] ?? 0)
                + 1;

            $types[$type] =
                ($types[$type] ?? 0)
                + 1;

            $confidence = (float)(
                $inference['confidence']
                ?? 0
            );

            $totalConfidence +=
                $confidence;

            $evidenceCount += (int)(
                $inference['evidence_count']
                ?? 0
            );

            if ($confidence >= 85) {
                $confidenceBands['very_high']++;
            } elseif ($confidence >= 65) {
                $confidenceBands['high']++;
            } elseif ($confidence >= 40) {
                $confidenceBands['medium']++;
            } else {
                $confidenceBands['low']++;
            }
        }

        arsort($strategies);
        arsort($types);

        return [
            'count' => count($inferences),

            'average_confidence' =>
                $inferences !== []
                    ? round(
                        $totalConfidence
                        / count($inferences),
                        2
                    )
                    : 0.0,

            'average_evidence_count' =>
                $inferences !== []
                    ? round(
                        $evidenceCount
                        / count($inferences),
                        2
                    )
                    : 0.0,

            'strategies' => $strategies,

            'relationship_types' => $types,

            'confidence_bands' =>
                $confidenceBands,
        ];
    }

    /**
     * Return inference diagnostics.
     *
     * @return array<string,mixed>
     */
    public function diagnostics(): array
    {
        return array_merge(
            parent::diagnostics(),
            [
                'transitive_types' =>
                    $this->transitiveTypes,

                'symmetric_types' =>
                    $this->symmetricTypes,

                'inverse_types' =>
                    $this->inverseTypes,

                'chain_rules' =>
                    $this->chainRules,

                'eligible_statuses' =>
                    $this->eligibleStatuses,

                'human_acceptance_required' =>
                    true,

                'automatic_persistence' =>
                    false,

                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Build one inference proposal.
     *
     * @param array<int,array<string,mixed>> $evidence
     *
     * @return array<string,mixed>
     */
    private function buildInference(
        string $sourceId,
        string $sourceType,
        string $targetId,
        string $targetType,
        string $relationshipType,
        float $confidence,
        string $strategy,
        array $evidence,
        array $metadata = []
    ): array {
        $sourceId = trim($sourceId);
        $targetId = trim($targetId);

        $sourceType =
            $this->normalizeEntityType(
                $sourceType
            );

        $targetType =
            $this->normalizeEntityType(
                $targetType
            );

        $relationshipType = trim(
            $relationshipType
        );

        $confidence = $this->clamp(
            $confidence,
            0.0,
            100.0
        );

        return [
            'inference_id' =>
                $this->generateInferenceId(),

            'status' => 'proposed',

            'source_id' => $sourceId,

            'source_type' => $sourceType,

            'target_id' => $targetId,

            'target_type' => $targetType,

            'relationship_type' =>
                $relationshipType,

            'strategy' => $strategy,

            'confidence' =>
                round(
                    $confidence,
                    2
                ),

            'evidence_count' =>
                count($evidence),

            'evidence' => $evidence,

            'explanation' =>
                $this->explainInference(
                    $relationshipType,
                    $strategy,
                    $evidence
                ),

            'requires_human_acceptance' =>
                true,

            'inferred_by' => 'sq',

            'inferred_by_type' => 'ai',

            'inferred_at' => gmdate('c'),

            'metadata' => array_merge(
                [
                    'inference_engine' =>
                        static::class,

                    'source_node_key' =>
                        $this->graphNodeKey(
                            $sourceType,
                            $sourceId
                        ),

                    'target_node_key' =>
                        $this->graphNodeKey(
                            $targetType,
                            $targetId
                        ),
                ],
                $metadata
            ),

            'tags' => [
                'inferred_relationship',
                $strategy,
                $relationshipType,
            ],
        ];
    }

    /**
     * Explain one inference.
     *
     * @param array<int,array<string,mixed>> $evidence
     */
    private function explainInference(
        string $relationshipType,
        string $strategy,
        array $evidence
    ): string {
        $relationshipLabel = str_replace(
            '_',
            ' ',
            $relationshipType
        );

        $strategyLabel = str_replace(
            '_',
            ' ',
            $strategy
        );

        $parts = [];

        foreach ($evidence as $item) {
            $description = trim(
                (string)(
                    $item['description']
                    ?? ''
                )
            );

            if ($description !== '') {
                $parts[$description] =
                    $description;
            }
        }

        $evidenceText = $parts !== []
            ? implode(
                ' ',
                array_values($parts)
            )
            : 'Existing graph structure supports the conclusion.';

        return sprintf(
            'The relationship "%s" was inferred by %s reasoning. %s',
            $relationshipLabel,
            $strategyLabel,
            $evidenceText
        );
    }

    /**
     * Create evidence from an existing relationship.
     *
     * @param array<string,mixed> $relationship
     *
     * @return array<string,mixed>
     */
    private function evidenceFromRelationship(
        array $relationship,
        string $description
    ): array {
        return [
            'relationship_id' =>
                $relationship[
                    'relationship_id'
                ] ?? null,

            'relationship_type' =>
                $relationship[
                    'relationship_type'
                ] ?? null,

            'source_id' =>
                $relationship['source_id']
                ?? null,

            'source_type' =>
                $relationship['source_type']
                ?? null,

            'target_id' =>
                $relationship['target_id']
                ?? null,

            'target_type' =>
                $relationship['target_type']
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

            'description' =>
                trim($description),
        ];
    }

    /**
     * Filter relationships eligible for inference.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @param array<int,string> $statuses
     * @param array<int,string> $allowedTypes
     *
     * @return array<int,array<string,mixed>>
     */
    private function filterRelationships(
        array $relationships,
        array $statuses,
        array $allowedTypes,
        bool $includeExpired
    ): array {
        $filtered = [];

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $status = trim(
                (string)(
                    $relationship['status']
                    ?? ''
                )
            );

            if (
                $statuses !== []
                && !in_array(
                    $status,
                    $statuses,
                    true
                )
            ) {
                continue;
            }

            $relationshipType = trim(
                (string)(
                    $relationship[
                        'relationship_type'
                    ]
                    ?? ''
                )
            );

            if (
                in_array(
                    $relationshipType,
                    $this->excludedTypes,
                    true
                )
            ) {
                continue;
            }

            if (
                $allowedTypes !== []
                && !in_array(
                    $relationshipType,
                    $allowedTypes,
                    true
                )
            ) {
                continue;
            }

            if (
                !$includeExpired
                && !$this->isTemporallyActive(
                    $relationship
                )
            ) {
                continue;
            }

            $sourceId = trim(
                (string)(
                    $relationship['source_id']
                    ?? ''
                )
            );

            $targetId = trim(
                (string)(
                    $relationship['target_id']
                    ?? ''
                )
            );

            if (
                $sourceId === ''
                || $targetId === ''
            ) {
                continue;
            }

            $filtered[] = $relationship;
        }

        return $filtered;
    }

    /**
     * Remove duplicates and already-existing graph facts.
     *
     * @param array<int,array<string,mixed>> $inferences
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<int,array<string,mixed>>
     */
    private function deduplicateInferences(
        array $inferences,
        array $relationships
    ): array {
        $unique = [];

        foreach ($inferences as $inference) {
            if (!is_array($inference)) {
                continue;
            }

            $sourceId = trim(
                (string)(
                    $inference['source_id']
                    ?? ''
                )
            );

            $sourceType = trim(
                (string)(
                    $inference['source_type']
                    ?? ''
                )
            );

            $targetId = trim(
                (string)(
                    $inference['target_id']
                    ?? ''
                )
            );

            $targetType = trim(
                (string)(
                    $inference['target_type']
                    ?? ''
                )
            );

            $relationshipType = trim(
                (string)(
                    $inference[
                        'relationship_type'
                    ]
                    ?? ''
                )
            );

            if (
                $sourceId === ''
                || $targetId === ''
                || $relationshipType === ''
                || (
                    $sourceId === $targetId
                    && $sourceType === $targetType
                )
            ) {
                continue;
            }

            if (
                $this->relationshipExists(
                    $relationships,
                    $sourceId,
                    $sourceType,
                    $targetId,
                    $targetType,
                    $relationshipType
                )
            ) {
                continue;
            }

            $key = implode(
                '|',
                [
                    $sourceType,
                    $sourceId,
                    $relationshipType,
                    $targetType,
                    $targetId,
                ]
            );

            if (!isset($unique[$key])) {
                $unique[$key] = $inference;
                continue;
            }

            $existingConfidence = (float)(
                $unique[$key]['confidence']
                ?? 0
            );

            $newConfidence = (float)(
                $inference['confidence']
                ?? 0
            );

            if (
                $newConfidence
                > $existingConfidence
            ) {
                $unique[$key] = $inference;
            }
        }

        return array_values($unique);
    }

    /**
     * Determine whether one relationship already exists.
     *
     * @param array<int,array<string,mixed>> $relationships
     */
    private function relationshipExists(
        array $relationships,
        string $sourceId,
        string $sourceType,
        string $targetId,
        string $targetType,
        string $relationshipType
    ): bool {
        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            if (
                trim(
                    (string)(
                        $relationship['source_id']
                        ?? ''
                    )
                ) !== $sourceId
            ) {
                continue;
            }

            if (
                trim(
                    (string)(
                        $relationship['source_type']
                        ?? ''
                    )
                ) !== $sourceType
            ) {
                continue;
            }

            if (
                trim(
                    (string)(
                        $relationship['target_id']
                        ?? ''
                    )
                ) !== $targetId
            ) {
                continue;
            }

            if (
                trim(
                    (string)(
                        $relationship['target_type']
                        ?? ''
                    )
                ) !== $targetType
            ) {
                continue;
            }

            if (
                trim(
                    (string)(
                        $relationship[
                            'relationship_type'
                        ]
                        ?? ''
                    )
                ) !== $relationshipType
            ) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Return canonical inverse type.
     */
    private function inverseType(
        string $relationshipType
    ): ?string {
        $relationshipType = trim(
            $relationshipType
        );

        return $this->inverseTypes[
            $relationshipType
        ] ?? $this->relationships
            ->inverseType(
                $relationshipType
            );
    }

    /**
     * Calculate confidence from one relationship.
     *
     * @param array<string,mixed> $relationship
     */
    private function singleEdgeConfidence(
        array $relationship,
        float $ruleFactor
    ): float {
        $confidence = $this->clamp(
            (float)(
                $relationship['confidence']
                ?? 100
            ),
            0.0,
            100.0
        );

        $weight = $this->clamp(
            (float)(
                $relationship['weight']
                ?? 1
            ),
            0.0,
            1.0
        );

        $strength = $this->clamp(
            (float)(
                $relationship['strength']
                ?? 1
            ),
            0.0,
            1.0
        );

        return round(
            $confidence
            * $weight
            * $strength
            * $ruleFactor,
            2
        );
    }

    /**
     * Calculate confidence across an evidence path.
     *
     * @param array<int,array<string,mixed>> $path
     */
    private function pathConfidence(
        array $path,
        float $ruleFactor
    ): float {
        if ($path === []) {
            return 0.0;
        }

        $product = 1.0;

        foreach ($path as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $confidence = $this->clamp(
                (float)(
                    $relationship[
                        'confidence'
                    ]
                    ?? 100
                ) / 100,
                0.0,
                1.0
            );

            $weight = $this->clamp(
                (float)(
                    $relationship['weight']
                    ?? 1
                ),
                0.0,
                1.0
            );

            $strength = $this->clamp(
                (float)(
                    $relationship[
                        'strength'
                    ]
                    ?? 1
                ),
                0.0,
                1.0
            );

            $product *=
                $confidence
                * $weight
                * $strength;
        }

        $depthPenalty = pow(
            0.94,
            max(
                0,
                count($path) - 1
            )
        );

        return round(
            $product
            * $ruleFactor
            * $depthPenalty
            * 100,
            2
        );
    }

    /**
     * Return relationship IDs from a path.
     *
     * @param array<int,array<string,mixed>> $path
     *
     * @return array<int,string>
     */
    private function relationshipIds(
        array $path
    ): array {
        $ids = [];

        foreach ($path as $relationship) {
            $id = trim(
                (string)(
                    $relationship[
                        'relationship_id'
                    ]
                    ?? ''
                )
            );

            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Convert confidence to relationship weight.
     */
    private function confidenceWeight(
        float $confidence
    ): float {
        return round(
            $this->clamp(
                $confidence / 100,
                0.0,
                1.0
            ),
            6
        );
    }

    /**
     * Calculate strength from confidence and evidence.
     *
     * @param array<string,mixed> $inference
     */
    private function inferenceStrength(
        array $inference
    ): float {
        $confidence = $this->clamp(
            (float)(
                $inference['confidence']
                ?? 0
            ) / 100,
            0.0,
            1.0
        );

        $evidenceCount = max(
            0,
            (int)(
                $inference['evidence_count']
                ?? 0
            )
        );

        $evidenceFactor = min(
            1.0,
            $evidenceCount / 4
        );

        return round(
            (
                $confidence * 0.8
            )
            + (
                $evidenceFactor * 0.2
            ),
            6
        );
    }

    /**
     * Return source node key.
     *
     * @param array<string,mixed> $relationship
     */
    private function relationshipSourceKey(
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
    private function relationshipTargetKey(
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
     * Split node key.
     *
     * @return array{0:string,1:string}
     */
    private function splitNodeKey(
        string $nodeKey
    ): array {
        $parts = explode(
            ':',
            $nodeKey,
            2
        );

        return [
            $this->normalizeEntityType(
                (string)(
                    $parts[0]
                    ?? 'entity'
                )
            ),

            trim(
                (string)(
                    $parts[1]
                    ?? ''
                )
            ),
        ];
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

        $validFrom = trim(
            (string)(
                $relationship['valid_from']
                ?? ''
            )
        );

        if ($validFrom !== '') {
            $from = strtotime($validFrom);

            if (
                $from !== false
                && $from > $now
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
            $to = strtotime($validTo);

            if (
                $to !== false
                && $to < $now
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize entity type.
     */
    private function normalizeEntityType(
        string $entityType
    ): string {
        $entityType = strtolower(
            trim($entityType)
        );

        return trim(
            preg_replace(
                '/[^a-z0-9_]+/',
                '_',
                $entityType
            ) ?? '',
            '_'
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
     * Clamp number.
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

    /**
     * Generate inference ID.
     */
    private function generateInferenceId(): string
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

        return 'INF-'
            . gmdate('Ymd-His')
            . '-'
            . $random;
    }
}