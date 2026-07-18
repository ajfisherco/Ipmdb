<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/RecommendationService.php
|--------------------------------------------------------------------------
| IPMdb Recommendation Service
|--------------------------------------------------------------------------
|
| Produces explainable, ranked recommendations from entities,
| relationships, graph structure, similarity, search signals, and context.
|
| Responsibilities:
| - Recommend related entities.
| - Recommend relationships worth reviewing.
| - Recommend next actions.
| - Recommend government-program alignment candidates.
| - Recommend provenance, validation, and deployment priorities.
| - Rank candidates using configurable weighted evidence.
| - Prevent self-recommendations and existing-edge duplication.
| - Preserve reasons, evidence, scores, and source signals.
|
| Recommendations assist prioritization.
| Recommendations do not execute decisions.
|
| This service performs no database operations.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/SimilarityService.php';
require_once __DIR__ . '/GraphTraversalService.php';
require_once __DIR__ . '/GraphSearchService.php';
require_once __DIR__ . '/RelationshipSuggestionService.php';
require_once __DIR__ . '/ConsistencyService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class RecommendationService extends Service
{
    use GraphUtilities;

    private SimilarityService $similarity;

    private GraphTraversalService $traversal;

    private GraphSearchService $search;

    private RelationshipSuggestionService $suggestions;

    private ConsistencyService $consistency;

    /**
     * Default recommendation weights.
     *
     * @var array<string,float>
     */
    private array $weights = [
        'similarity' => 0.30,
        'graph_proximity' => 0.18,
        'shared_neighbours' => 0.12,
        'shared_terms' => 0.10,
        'provenance' => 0.08,
        'status_priority' => 0.06,
        'recency' => 0.05,
        'confidence' => 0.05,
        'alignment' => 0.04,
        'completeness' => 0.02,
    ];

    /**
     * Entity types commonly representing implementation opportunities.
     *
     * @var array<int,string>
     */
    private array $implementationTypes = [
        'project',
        'program',
        'government_program',
        'implementation',
        'deployment',
        'application',
        'initiative',
        'policy',
        'service',
        'funding_program',
    ];

    /**
     * Entity types commonly representing intellectual assets.
     *
     * @var array<int,string>
     */
    private array $assetTypes = [
        'idea',
        'asset',
        'concept',
        'proposal',
        'design',
        'invention',
        'document',
        'mission',
        'objective',
        'decision',
        'translation',
    ];

    /**
     * Relationship types treated as recommendation blockers.
     *
     * @var array<int,string>
     */
    private array $blockingRelationshipTypes = [
        'duplicate_of',
        'same_as',
        'rejected_by',
        'blocked_by',
        'conflicts_with',
        'ineligible_for',
    ];

    /**
     * Relationship types that indicate an existing useful connection.
     *
     * @var array<int,string>
     */
    private array $existingConnectionTypes = [
        'related_to',
        'aligns_with',
        'supports',
        'implements',
        'references',
        'depends_on',
        'derived_from',
        'evidence_for',
        'eligible_for',
        'funded_by',
        'administered_by',
    ];

    /**
     * Status priority used for action recommendations.
     *
     * @var array<string,float>
     */
    private array $statusPriority = [
        'blocked' => 1.00,
        'disputed' => 0.95,
        'pending_review' => 0.90,
        'proposed' => 0.82,
        'draft' => 0.75,
        'active' => 0.65,
        'verified' => 0.45,
        'approved' => 0.35,
        'implemented' => 0.20,
        'completed' => 0.10,
        'archived' => 0.00,
        'rejected' => 0.00,
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?SimilarityService $similarity = null,
        ?GraphTraversalService $traversal = null,
        ?GraphSearchService $search = null,
        ?RelationshipSuggestionService $suggestions = null,
        ?ConsistencyService $consistency = null
    ) {
        parent::__construct($config, $context);

        $this->similarity = $similarity
            ?? new SimilarityService();

        $this->traversal = $traversal
            ?? new GraphTraversalService();

        $this->search = $search
            ?? new GraphSearchService();

        $this->suggestions = $suggestions
            ?? new RelationshipSuggestionService();

        $this->consistency = $consistency
            ?? new ConsistencyService();

        if (
            isset($config['weights'])
            && is_array($config['weights'])
        ) {
            $this->weights = $this->normalizeWeights(
                array_merge(
                    $this->weights,
                    $config['weights']
                )
            );
        }
    }

    /**
     * Recommend entities related to one focus entity.
     *
     * @param array<string,mixed> $focus
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    public function recommendEntities(
        array $focus,
        array $entities,
        array $relationships = [],
        array $options = []
    ): array {
        $this->reset();

        $focusIdentity = $this->recordIdentity(
            $focus
        );

        if ($focusIdentity['identifier'] === '') {
            throw new InvalidArgumentException(
                'Entity recommendation requires a focus identifier.'
            );
        }

        $minimumScore = $this->clamp(
            (float)(
                $options['minimum_score']
                ?? 20.0
            ),
            0.0,
            100.0
        );

        $limit = max(
            1,
            min(
                1000,
                (int)(
                    $options['limit']
                    ?? 25
                )
            )
        );

        $candidateTypes =
            $this->normalizeStringList(
                $options['candidate_types']
                ?? []
            );

        $excludeExisting = (bool)(
            $options['exclude_existing']
                ?? false
        );

        $weights = isset($options['weights'])
            && is_array($options['weights'])
            ? $this->normalizeWeights(
                array_merge(
                    $this->weights,
                    $options['weights']
                )
            )
            : $this->weights;

        $results = [];

        foreach ($entities as $index => $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $candidateIdentity =
                $this->recordIdentity(
                    $candidate
                );

            if (
                $candidateIdentity['identifier'] === ''
                || (
                    $candidateIdentity['identifier']
                    === $focusIdentity['identifier']
                    && $candidateIdentity['type']
                    === $focusIdentity['type']
                )
            ) {
                continue;
            }

            if (
                $candidateTypes !== []
                && !in_array(
                    $candidateIdentity['type'],
                    $candidateTypes,
                    true
                )
            ) {
                continue;
            }

            $existingRelationship =
                $this->findExistingConnection(
                    $relationships,
                    $focusIdentity,
                    $candidateIdentity
                );

            if (
                $excludeExisting
                && $existingRelationship !== null
            ) {
                continue;
            }

            if (
                $this->hasBlockingConnection(
                    $relationships,
                    $focusIdentity,
                    $candidateIdentity
                )
            ) {
                continue;
            }

            $recommendation =
                $this->scoreEntityCandidate(
                    $focus,
                    $candidate,
                    $relationships,
                    $weights,
                    $options
                );

            if (
                $recommendation['score']
                < $minimumScore
            ) {
                continue;
            }

            $recommendation[
                'candidate_index'
            ] = $index;

            $recommendation[
                'existing_relationship'
            ] = $existingRelationship;

            $recommendation['record'] =
                $candidate;

            $results[] = $recommendation;
        }

        $this->sortRecommendations(
            $results
        );

        $results = array_slice(
            $results,
            0,
            $limit
        );

        $result = [
            'generated_at' => gmdate('c'),

            'recommendation_type' =>
                'entity',

            'focus' => $focusIdentity,

            'candidate_count' =>
                count($entities),

            'result_count' =>
                count($results),

            'minimum_score' =>
                $minimumScore,

            'limit' => $limit,

            'weights' => $weights,

            'results' => $results,

            'summary' =>
                $this->summarize(
                    $results
                ),
        ];

        $this->addMessage(
            'Entity recommendations generated.',
            [
                'focus_id' =>
                    $focusIdentity[
                        'identifier'
                    ],

                'result_count' =>
                    count($results),
            ]
        );

        return $result;
    }

    /**
     * Recommend government-program alignment candidates.
     *
     * @param array<string,mixed> $focus
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    public function recommendGovernmentProgramAlignment(
        array $focus,
        array $entities,
        array $relationships = [],
        array $options = []
    ): array {
        $programTypes = $this->normalizeStringList(
            $options['program_types']
                ?? [
                    'government_program',
                    'funding_program',
                    'program',
                    'policy',
                    'initiative',
                    'public_service',
                    'grant',
                    'regulation',
                    'legislation',
                ]
        );

        $result = $this->recommendEntities(
            $focus,
            $entities,
            $relationships,
            array_merge(
                $options,
                [
                    'candidate_types' =>
                        $programTypes,

                    'minimum_score' =>
                        $options[
                            'minimum_score'
                        ] ?? 25.0,
                ]
            )
        );

        $aligned = [];

        foreach ($result['results'] as $recommendation) {
            $candidate = $recommendation['record']
                ?? [];

            if (!is_array($candidate)) {
                continue;
            }

            $alignment = $this->alignmentEvidence(
                $focus,
                $candidate
            );

            $recommendation[
                'alignment_evidence'
            ] = $alignment;

            $recommendation[
                'recommended_relationship_type'
            ] = $this->governmentAlignmentType(
                $focus,
                $candidate
            );

            $recommendation[
                'recommendation_type'
            ] = 'government_program_alignment';

            $recommendation[
                'explanation'
            ] = $this->governmentAlignmentExplanation(
                $recommendation,
                $alignment
            );

            $aligned[] = $recommendation;
        }

        $this->sortRecommendations(
            $aligned
        );

        $result['recommendation_type'] =
            'government_program_alignment';

        $result['results'] = $aligned;

        $result['result_count'] =
            count($aligned);

        $result['summary'] =
            $this->summarize($aligned);

        return $result;
    }

    /**
     * Recommend relationship proposals for one focus entity.
     *
     * @param array<string,mixed> $focus
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    public function recommendRelationships(
        array $focus,
        array $entities,
        array $relationships = [],
        array $options = []
    ): array {
        $suggestionResult =
            $this->suggestions->suggestForEntity(
                $focus,
                $entities,
                $relationships,
                [
                    'minimum_confidence' =>
                        $options[
                            'minimum_confidence'
                        ] ?? 35.0,

                    'limit' =>
                        $options['limit']
                        ?? 50,

                    'candidate_entity_types' =>
                        $options[
                            'candidate_entity_types'
                        ] ?? [],

                    'relationship_types' =>
                        $options[
                            'relationship_types'
                        ] ?? [],

                    'include_existing' =>
                        $options[
                            'include_existing'
                        ] ?? false,

                    'include_weak' =>
                        $options[
                            'include_weak'
                        ] ?? false,

                    'suggested_by' =>
                        $options[
                            'suggested_by'
                        ] ?? 'sq',
                ]
            );

        $recommendations = [];

        foreach (
            $suggestionResult['suggestions']
                ?? []
            as $suggestion
        ) {
            if (!is_array($suggestion)) {
                continue;
            }

            $confidence = (float)(
                $suggestion['confidence']
                ?? 0
            );

            $score = $this->clamp(
                $confidence,
                0.0,
                100.0
            );

            $recommendations[] = [
                'recommendation_id' =>
                    $this->generateRecommendationId(),

                'recommendation_type' =>
                    'relationship',

                'score' =>
                    round($score, 2),

                'classification' =>
                    $this->classifyScore(
                        $score
                    ),

                'source_id' =>
                    $suggestion['source_id']
                    ?? null,

                'source_type' =>
                    $suggestion['source_type']
                    ?? null,

                'target_id' =>
                    $suggestion['target_id']
                    ?? null,

                'target_type' =>
                    $suggestion['target_type']
                    ?? null,

                'relationship_type' =>
                    $suggestion[
                        'relationship_type'
                    ] ?? 'related_to',

                'title' => sprintf(
                    'Review %s relationship',
                    str_replace(
                        '_',
                        ' ',
                        (string)(
                            $suggestion[
                                'relationship_type'
                            ] ?? 'related_to'
                        )
                    )
                ),

                'reason_count' =>
                    count(
                        $suggestion['evidence']
                        ?? []
                    ),

                'reasons' =>
                    $suggestion['evidence']
                    ?? [],

                'explanation' =>
                    $suggestion[
                        'explanation'
                    ] ?? '',

                'requires_human_review' =>
                    true,

                'suggestion' =>
                    $suggestion,

                'generated_at' =>
                    gmdate('c'),
            ];
        }

        $this->sortRecommendations(
            $recommendations
        );

        return [
            'generated_at' => gmdate('c'),

            'recommendation_type' =>
                'relationship',

            'focus' =>
                $suggestionResult['focus']
                ?? $this->recordIdentity(
                    $focus
                ),

            'result_count' =>
                count($recommendations),

            'results' =>
                $recommendations,

            'summary' =>
                $this->summarize(
                    $recommendations
                ),
        ];
    }

    /**
     * Recommend next operational actions.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    public function recommendActions(
        array $entities,
        array $relationships = [],
        array $options = []
    ): array {
        $this->reset();

        $limit = max(
            1,
            min(
                1000,
                (int)(
                    $options['limit']
                    ?? 50
                )
            )
        );

        $recommendations = [];

        $consistencyResult =
            $this->consistency->inspect(
                $entities,
                $relationships,
                [
                    'check_cycles' =>
                        $options[
                            'check_cycles'
                        ] ?? true,

                    'maximum_cycles' =>
                        $options[
                            'maximum_cycles'
                        ] ?? 100,
                ]
            );

        foreach (
            $consistencyResult['findings']
                ?? []
            as $finding
        ) {
            if (!is_array($finding)) {
                continue;
            }

            $severity = trim(
                (string)(
                    $finding['severity']
                    ?? 'notice'
                )
            );

            $score =
                $this->severityScore(
                    $severity
                );

            $recommendations[] = [
                'recommendation_id' =>
                    $this->generateRecommendationId(),

                'recommendation_type' =>
                    'resolve_consistency',

                'score' => $score,

                'classification' =>
                    $this->classifyScore(
                        $score
                    ),

                'title' =>
                    $this->findingTitle(
                        $finding
                    ),

                'explanation' =>
                    $finding['message']
                    ?? '',

                'reasons' => [
                    [
                        'signal' =>
                            'consistency_finding',

                        'description' =>
                            $finding['message']
                            ?? '',

                        'finding_id' =>
                            $finding['finding_id']
                            ?? null,

                        'severity' =>
                            $severity,
                    ],
                ],

                'recommended_actions' =>
                    $finding[
                        'recommended_actions'
                    ] ?? [],

                'requires_human_review' =>
                    true,

                'finding' => $finding,

                'generated_at' =>
                    gmdate('c'),
            ];
        }

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $entityIdentity =
                $this->recordIdentity(
                    $entity
                );

            if (
                $entityIdentity['identifier']
                === ''
            ) {
                continue;
            }

            foreach (
                $this->entityActionRecommendations(
                    $entity
                )
                as $recommendation
            ) {
                $recommendations[] =
                    $recommendation;
            }
        }

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            foreach (
                $this->relationshipActionRecommendations(
                    $relationship
                )
                as $recommendation
            ) {
                $recommendations[] =
                    $recommendation;
            }
        }

        $recommendations =
            $this->deduplicateRecommendations(
                $recommendations
            );

        $this->sortRecommendations(
            $recommendations
        );

        $recommendations = array_slice(
            $recommendations,
            0,
            $limit
        );

        return [
            'generated_at' => gmdate('c'),

            'recommendation_type' =>
                'action',

            'entity_count' =>
                count($entities),

            'relationship_count' =>
                count($relationships),

            'result_count' =>
                count($recommendations),

            'results' =>
                $recommendations,

            'summary' =>
                $this->summarize(
                    $recommendations
                ),
        ];
    }

    /**
     * Recommend provenance priorities.
     *
     * @param array<int,array<string,mixed>> $records
     *
     * @return array<string,mixed>
     */
    public function recommendProvenanceWork(
        array $records,
        array $options = []
    ): array {
        $limit = max(
            1,
            min(
                1000,
                (int)(
                    $options['limit']
                    ?? 50
                )
            )
        );

        $results = [];

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                continue;
            }

            $identity =
                $this->recordIdentity(
                    $record
                );

            $provenanceId = trim(
                (string)(
                    $record['provenance_id']
                    ?? ''
                )
            );

            $sourceReference = trim(
                (string)(
                    $record['source_reference']
                    ?? $record['source_url']
                    ?? $record['url']
                    ?? ''
                )
            );

            $createdBy = trim(
                (string)(
                    $record['created_by']
                    ?? $record['originator_id']
                    ?? $record['originator_email']
                    ?? ''
                )
            );

            $status = $this->normalizeMachineKey(
                (string)(
                    $record['status']
                    ?? ''
                )
            );

            $score = 0.0;
            $reasons = [];
            $actions = [];

            if ($provenanceId === '') {
                $score += 45;

                $reasons[] = [
                    'signal' =>
                        'missing_provenance',

                    'description' =>
                        'The record has no provenance identifier.',
                ];

                $actions[] =
                    'create_provenance_record';
            }

            if ($sourceReference === '') {
                $score += 25;

                $reasons[] = [
                    'signal' =>
                        'missing_source_reference',

                    'description' =>
                        'The record has no source reference.',
                ];

                $actions[] =
                    'attach_source_reference';
            }

            if ($createdBy === '') {
                $score += 20;

                $reasons[] = [
                    'signal' =>
                        'missing_attribution',

                    'description' =>
                        'The record has no creator or originator attribution.',
                ];

                $actions[] =
                    'identify_originator';
            }

            if (
                in_array(
                    $status,
                    [
                        'verified',
                        'approved',
                        'implemented',
                    ],
                    true
                )
                && $provenanceId === ''
            ) {
                $score += 10;

                $reasons[] = [
                    'signal' =>
                        'trusted_without_provenance',

                    'description' =>
                        'The record holds a trusted status without provenance.',
                ];
            }

            if ($score <= 0) {
                continue;
            }

            $score = $this->clamp(
                $score,
                0.0,
                100.0
            );

            $results[] = [
                'recommendation_id' =>
                    $this->generateRecommendationId(),

                'recommendation_type' =>
                    'provenance',

                'record_index' => $index,

                'record_identity' =>
                    $identity,

                'score' => $score,

                'classification' =>
                    $this->classifyScore(
                        $score
                    ),

                'title' =>
                    'Complete provenance record',

                'explanation' =>
                    $this->provenanceExplanation(
                        $identity,
                        $reasons
                    ),

                'reasons' => $reasons,

                'recommended_actions' =>
                    array_values(
                        array_unique($actions)
                    ),

                'requires_human_review' =>
                    true,

                'record' => $record,

                'generated_at' =>
                    gmdate('c'),
            ];
        }

        $this->sortRecommendations(
            $results
        );

        $results = array_slice(
            $results,
            0,
            $limit
        );

        return [
            'generated_at' => gmdate('c'),

            'recommendation_type' =>
                'provenance',

            'record_count' =>
                count($records),

            'result_count' =>
                count($results),

            'results' => $results,

            'summary' =>
                $this->summarize(
                    $results
                ),
        ];
    }

    /**
     * Recommend deployment priorities.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    public function recommendDeploymentPriorities(
        array $entities,
        array $relationships = [],
        array $options = []
    ): array {
        $minimumScore = $this->clamp(
            (float)(
                $options['minimum_score']
                ?? 25.0
            ),
            0.0,
            100.0
        );

        $limit = max(
            1,
            min(
                1000,
                (int)(
                    $options['limit']
                    ?? 50
                )
            )
        );

        $results = [];

        foreach ($entities as $index => $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $identity =
                $this->recordIdentity(
                    $entity
                );

            if ($identity['identifier'] === '') {
                continue;
            }

            $statusScore =
                $this->statusPriorityScore(
                    $entity
                );

            $completeness =
                $this->recordCompleteness(
                    $entity
                );

            $confidence = $this->clamp(
                (float)(
                    $entity['confidence']
                    ?? 50
                ),
                0.0,
                100.0
            ) / 100;

            $graphScore =
                $this->deploymentGraphScore(
                    $identity,
                    $relationships
                );

            $provenanceScore =
                $this->hasProvenance($entity)
                    ? 1.0
                    : 0.35;

            $score = (
                $statusScore * 0.28
            ) + (
                $completeness * 0.20
            ) + (
                $confidence * 0.18
            ) + (
                $graphScore * 0.18
            ) + (
                $provenanceScore * 0.16
            );

            $score *= 100;

            if ($score < $minimumScore) {
                continue;
            }

            $reasons = [];

            if ($statusScore >= 0.75) {
                $reasons[] = [
                    'signal' =>
                        'status_requires_attention',

                    'description' =>
                        'The current lifecycle status indicates pending work.',
                ];
            }

            if ($completeness >= 0.75) {
                $reasons[] = [
                    'signal' =>
                        'record_substantially_complete',

                    'description' =>
                        'The record contains enough information for deployment review.',
                ];
            }

            if ($graphScore >= 0.60) {
                $reasons[] = [
                    'signal' =>
                        'strong_graph_context',

                    'description' =>
                        'The record has meaningful graph connections.',
                ];
            }

            if ($provenanceScore >= 1.0) {
                $reasons[] = [
                    'signal' =>
                        'provenance_present',

                    'description' =>
                        'The record includes provenance.',
                ];
            }

            $results[] = [
                'recommendation_id' =>
                    $this->generateRecommendationId(),

                'recommendation_type' =>
                    'deployment_priority',

                'record_index' => $index,

                'record_identity' =>
                    $identity,

                'score' => round(
                    $score,
                    2
                ),

                'classification' =>
                    $this->classifyScore(
                        $score
                    ),

                'title' =>
                    'Review for deployment',

                'explanation' =>
                    sprintf(
                        '%s is ranked for deployment review based on lifecycle status, completeness, confidence, provenance, and graph context.',
                        $identity['title']
                    ),

                'reasons' => $reasons,

                'recommended_actions' => [
                    'review_deployment_readiness',
                    'confirm_provenance',
                    'confirm_relationship_context',
                    'record_deployment_decision',
                ],

                'components' => [
                    'status_priority' =>
                        round(
                            $statusScore * 100,
                            2
                        ),

                    'completeness' =>
                        round(
                            $completeness * 100,
                            2
                        ),

                    'confidence' =>
                        round(
                            $confidence * 100,
                            2
                        ),

                    'graph_context' =>
                        round(
                            $graphScore * 100,
                            2
                        ),

                    'provenance' =>
                        round(
                            $provenanceScore * 100,
                            2
                        ),
                ],

                'requires_human_review' =>
                    true,

                'record' => $entity,

                'generated_at' =>
                    gmdate('c'),
            ];
        }

        $this->sortRecommendations(
            $results
        );

        $results = array_slice(
            $results,
            0,
            $limit
        );

        return [
            'generated_at' => gmdate('c'),

            'recommendation_type' =>
                'deployment_priority',

            'entity_count' =>
                count($entities),

            'result_count' =>
                count($results),

            'results' => $results,

            'summary' =>
                $this->summarize(
                    $results
                ),
        ];
    }

    /**
     * Return recommendations involving one entity.
     *
     * @param array<int,array<string,mixed>> $recommendations
     *
     * @return array<int,array<string,mixed>>
     */
    public function forEntity(
        array $recommendations,
        string $entityId,
        ?string $entityType = null
    ): array {
        $entityId = trim($entityId);

        $entityType = $entityType !== null
            ? $this->normalizeMachineKey(
                $entityType
            )
            : null;

        return array_values(
            array_filter(
                $recommendations,
                static function (
                    array $recommendation
                ) use (
                    $entityId,
                    $entityType
                ): bool {
                    $identities = [
                        [
                            'identifier' =>
                                $recommendation[
                                    'source_id'
                                ] ?? null,

                            'type' =>
                                $recommendation[
                                    'source_type'
                                ] ?? null,
                        ],

                        [
                            'identifier' =>
                                $recommendation[
                                    'target_id'
                                ] ?? null,

                            'type' =>
                                $recommendation[
                                    'target_type'
                                ] ?? null,
                        ],

                        $recommendation[
                            'record_identity'
                        ] ?? [],

                        $recommendation[
                            'candidate_identity'
                        ] ?? [],
                    ];

                    foreach ($identities as $identity) {
                        if (!is_array($identity)) {
                            continue;
                        }

                        if (
                            trim(
                                (string)(
                                    $identity[
                                        'identifier'
                                    ] ?? ''
                                )
                            ) !== $entityId
                        ) {
                            continue;
                        }

                        if (
                            $entityType === null
                            || trim(
                                (string)(
                                    $identity['type']
                                    ?? ''
                                )
                            ) === $entityType
                        ) {
                            return true;
                        }
                    }

                    return false;
                }
            )
        );
    }

    /**
     * Filter recommendations by minimum score.
     *
     * @param array<int,array<string,mixed>> $recommendations
     *
     * @return array<int,array<string,mixed>>
     */
    public function aboveScore(
        array $recommendations,
        float $minimumScore
    ): array {
        $minimumScore = $this->clamp(
            $minimumScore,
            0.0,
            100.0
        );

        return array_values(
            array_filter(
                $recommendations,
                static fn (
                    array $recommendation
                ): bool =>
                    (float)(
                        $recommendation['score']
                        ?? 0
                    ) >= $minimumScore
            )
        );
    }

    /**
     * Mark a recommendation accepted.
     *
     * @param array<string,mixed> $recommendation
     *
     * @return array<string,mixed>
     */
    public function accept(
        array $recommendation,
        string $actorId,
        string $decision = ''
    ): array {
        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Recommendation acceptance requires actor attribution.'
            );
        }

        return array_merge(
            $recommendation,
            [
                'status' => 'accepted',

                'accepted_by' =>
                    $actorId,

                'accepted_at' =>
                    gmdate('c'),

                'decision' =>
                    trim($decision),
            ]
        );
    }

    /**
     * Mark a recommendation rejected.
     *
     * @param array<string,mixed> $recommendation
     *
     * @return array<string,mixed>
     */
    public function reject(
        array $recommendation,
        string $actorId,
        string $reason = ''
    ): array {
        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Recommendation rejection requires actor attribution.'
            );
        }

        return array_merge(
            $recommendation,
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
     * Summarize recommendations.
     *
     * @param array<int,array<string,mixed>> $recommendations
     *
     * @return array<string,mixed>
     */
    public function summarize(
        array $recommendations
    ): array {
        $types = [];
        $classifications = [];
        $totalScore = 0.0;
        $reviewCount = 0;

        foreach ($recommendations as $recommendation) {
            $type = trim(
                (string)(
                    $recommendation[
                        'recommendation_type'
                    ] ?? 'unknown'
                )
            );

            $classification = trim(
                (string)(
                    $recommendation[
                        'classification'
                    ] ?? 'minimal'
                )
            );

            $types[$type] =
                ($types[$type] ?? 0)
                + 1;

            $classifications[
                $classification
            ] = (
                $classifications[
                    $classification
                ] ?? 0
            ) + 1;

            $totalScore += (float)(
                $recommendation['score']
                ?? 0
            );

            if (
                (
                    $recommendation[
                        'requires_human_review'
                    ] ?? false
                ) === true
            ) {
                $reviewCount++;
            }
        }

        arsort($types);
        arsort($classifications);

        return [
            'count' =>
                count($recommendations),

            'average_score' =>
                $recommendations !== []
                    ? round(
                        $totalScore
                        / count(
                            $recommendations
                        ),
                        2
                    )
                    : 0.0,

            'human_review_required_count' =>
                $reviewCount,

            'types' => $types,

            'classifications' =>
                $classifications,
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
                'weights' =>
                    $this->weights,

                'implementation_types' =>
                    $this->implementationTypes,

                'asset_types' =>
                    $this->assetTypes,

                'blocking_relationship_types' =>
                    $this->blockingRelationshipTypes,

                'existing_connection_types' =>
                    $this->existingConnectionTypes,

                'status_priority' =>
                    $this->statusPriority,

                'government_program_alignment_supported' =>
                    true,

                'automatic_execution' =>
                    false,

                'human_review_required' =>
                    true,

                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Score one entity candidate.
     *
     * @param array<string,mixed> $focus
     * @param array<string,mixed> $candidate
     * @param array<int,array<string,mixed>> $relationships
     * @param array<string,float> $weights
     *
     * @return array<string,mixed>
     */
    private function scoreEntityCandidate(
        array $focus,
        array $candidate,
        array $relationships,
        array $weights,
        array $options
    ): array {
        $similarity = $this->similarity->compare(
            $focus,
            $candidate,
            $relationships,
            [
                'human_review_threshold' =>
                    100.0,
            ]
        );

        $focusIdentity =
            $this->recordIdentity($focus);

        $candidateIdentity =
            $this->recordIdentity(
                $candidate
            );

        $graphProximity =
            $this->graphProximityScore(
                $focusIdentity,
                $candidateIdentity,
                $relationships,
                (int)(
                    $options[
                        'maximum_graph_depth'
                    ] ?? 4
                )
            );

        $sharedNeighbours =
            $this->sharedNeighbourScore(
                $focusIdentity,
                $candidateIdentity,
                $relationships
            );

        $sharedTerms =
            $this->sharedTermScore(
                $focus,
                $candidate
            );

        $provenance =
            $this->provenanceScore(
                $focus,
                $candidate
            );

        $statusPriority =
            $this->statusPriorityScore(
                $candidate
            );

        $recency =
            $this->recencyScore(
                $candidate
            );

        $confidence = $this->clamp(
            (float)(
                $candidate['confidence']
                ?? 50
            ),
            0.0,
            100.0
        ) / 100;

        $alignment =
            $this->alignmentScore(
                $focus,
                $candidate
            );

        $completeness =
            $this->recordCompleteness(
                $candidate
            );

        $components = [
            'similarity' =>
                (
                    (float)(
                        $similarity['score']
                        ?? 0
                    )
                ) / 100,

            'graph_proximity' =>
                $graphProximity,

            'shared_neighbours' =>
                $sharedNeighbours,

            'shared_terms' =>
                $sharedTerms,

            'provenance' =>
                $provenance,

            'status_priority' =>
                $statusPriority,

            'recency' => $recency,

            'confidence' =>
                $confidence,

            'alignment' =>
                $alignment,

            'completeness' =>
                $completeness,
        ];

        $score = 0.0;

        foreach ($weights as $name => $weight) {
            $score += (
                $components[$name]
                ?? 0.0
            ) * $weight;
        }

        $score *= 100;

        $reasons = $this->buildEntityReasons(
            $components,
            $similarity
        );

        return [
            'recommendation_id' =>
                $this->generateRecommendationId(),

            'recommendation_type' =>
                'entity',

            'candidate_identity' =>
                $candidateIdentity,

            'score' => round(
                $score,
                2
            ),

            'classification' =>
                $this->classifyScore(
                    $score
                ),

            'title' => sprintf(
                'Review %s',
                $candidateIdentity['title']
            ),

            'reason_count' =>
                count($reasons),

            'reasons' => $reasons,

            'components' =>
                array_map(
                    static fn (
                        float $value
                    ): float =>
                        round(
                            $value * 100,
                            2
                        ),
                    $components
                ),

            'explanation' =>
                $this->entityExplanation(
                    $focusIdentity,
                    $candidateIdentity,
                    $score,
                    $reasons
                ),

            'requires_human_review' =>
                true,

            'generated_at' =>
                gmdate('c'),
        ];
    }

    /**
     * Build entity recommendation reasons.
     *
     * @param array<string,float> $components
     * @param array<string,mixed> $similarity
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildEntityReasons(
        array $components,
        array $similarity
    ): array {
        $reasons = [];

        if (
            ($components['similarity'] ?? 0)
            >= 0.60
        ) {
            $reasons[] = [
                'signal' =>
                    'high_similarity',

                'description' =>
                    'The candidate is materially similar to the focus entity.',

                'value' =>
                    round(
                        (
                            $components[
                                'similarity'
                            ] ?? 0
                        ) * 100,
                        2
                    ),
            ];
        }

        if (
            (
                $components[
                    'graph_proximity'
                ] ?? 0
            ) >= 0.50
        ) {
            $reasons[] = [
                'signal' =>
                    'graph_proximity',

                'description' =>
                    'The candidate is near the focus entity in the graph.',

                'value' =>
                    round(
                        (
                            $components[
                                'graph_proximity'
                            ] ?? 0
                        ) * 100,
                        2
                    ),
            ];
        }

        if (
            (
                $components[
                    'shared_neighbours'
                ] ?? 0
            ) >= 0.30
        ) {
            $reasons[] = [
                'signal' =>
                    'shared_neighbours',

                'description' =>
                    'The candidate shares graph neighbours with the focus entity.',

                'value' =>
                    round(
                        (
                            $components[
                                'shared_neighbours'
                            ] ?? 0
                        ) * 100,
                        2
                    ),
            ];
        }

        if (
            (
                $components[
                    'shared_terms'
                ] ?? 0
            ) >= 0.30
        ) {
            $reasons[] = [
                'signal' =>
                    'shared_terms',

                'description' =>
                    'The candidate shares tags, keywords, or categories.',

                'value' =>
                    round(
                        (
                            $components[
                                'shared_terms'
                            ] ?? 0
                        ) * 100,
                        2
                    ),
            ];
        }

        if (
            (
                $components['alignment']
                ?? 0
            ) >= 0.45
        ) {
            $reasons[] = [
                'signal' =>
                    'alignment',

                'description' =>
                    'The candidate presents a plausible alignment opportunity.',

                'value' =>
                    round(
                        (
                            $components[
                                'alignment'
                            ] ?? 0
                        ) * 100,
                        2
                    ),
            ];
        }

        foreach (
            $similarity['evidence']
                ?? []
            as $evidence
        ) {
            if (
                !is_array($evidence)
                || count($reasons) >= 8
            ) {
                continue;
            }

            $reasons[] = [
                'signal' =>
                    $evidence['signal']
                    ?? 'similarity_evidence',

                'description' =>
                    $evidence['description']
                    ?? 'Similarity evidence was detected.',

                'component' =>
                    $evidence['component']
                    ?? null,
            ];
        }

        return $reasons;
    }

    /**
     * Calculate graph proximity score.
     *
     * @param array<string,string> $left
     * @param array<string,string> $right
     * @param array<int,array<string,mixed>> $relationships
     */
    private function graphProximityScore(
        array $left,
        array $right,
        array $relationships,
        int $maximumDepth
    ): float {
        if (
            $relationships === []
            || $left['identifier'] === ''
            || $right['identifier'] === ''
        ) {
            return 0.0;
        }

        $maximumDepth = max(
            1,
            min(20, $maximumDepth)
        );

        try {
            $traversal =
                $this->traversal->traverse(
                    $relationships,
                    $left['identifier'],
                    $left['type'],
                    $maximumDepth,
                    'both',
                    [],
                    ['active', 'verified'],
                    false,
                    5000
                );
        } catch (Throwable) {
            return 0.0;
        }

        $targetKey = $this->graphNodeKey(
            $right['type'],
            $right['identifier']
        );

        foreach (
            $traversal['nodes']
                ?? []
            as $node
        ) {
            if (!is_array($node)) {
                continue;
            }

            if (
                trim(
                    (string)(
                        $node['node_key']
                        ?? ''
                    )
                ) !== $targetKey
            ) {
                continue;
            }

            $depth = max(
                0,
                (int)(
                    $node['depth']
                    ?? $maximumDepth
                )
            );

            return 1.0 / (
                1.0 + $depth
            );
        }

        return 0.0;
    }

    /**
     * Calculate shared-neighbour score.
     *
     * @param array<string,string> $left
     * @param array<string,string> $right
     * @param array<int,array<string,mixed>> $relationships
     */
    private function sharedNeighbourScore(
        array $left,
        array $right,
        array $relationships
    ): float {
        if ($relationships === []) {
            return 0.0;
        }

        try {
            $leftNeighbours =
                $this->traversal->neighbours(
                    $relationships,
                    $left['identifier'],
                    $left['type'],
                    'both',
                    [],
                    ['active', 'verified'],
                    false
                );

            $rightNeighbours =
                $this->traversal->neighbours(
                    $relationships,
                    $right['identifier'],
                    $right['type'],
                    'both',
                    [],
                    ['active', 'verified'],
                    false
                );
        } catch (Throwable) {
            return 0.0;
        }

        $leftKeys =
            $this->neighbourKeys(
                $leftNeighbours
            );

        $rightKeys =
            $this->neighbourKeys(
                $rightNeighbours
            );

        return $this->jaccard(
            $leftKeys,
            $rightKeys
        );
    }

    /**
     * Calculate shared-term score.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function sharedTermScore(
        array $left,
        array $right
    ): float {
        $fields = [
            'tags',
            'keywords',
            'category',
            'categories',
            'topics',
            'themes',
            'sectors',
            'domains',
            'purpose',
            'objective',
            'mission',
        ];

        $leftTerms = $this->collectTerms(
            $left,
            $fields
        );

        $rightTerms = $this->collectTerms(
            $right,
            $fields
        );

        return $this->jaccard(
            $leftTerms,
            $rightTerms
        );
    }

    /**
     * Calculate provenance score.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function provenanceScore(
        array $left,
        array $right
    ): float {
        $fields = [
            'provenance_id',
            'source_entity_id',
            'source_reference',
            'source_url',
            'originator_id',
            'created_by',
        ];

        $leftValues =
            $this->collectCanonicalValues(
                $left,
                $fields
            );

        $rightValues =
            $this->collectCanonicalValues(
                $right,
                $fields
            );

        return $this->jaccard(
            $leftValues,
            $rightValues
        );
    }

    /**
     * Calculate alignment score.
     *
     * @param array<string,mixed> $focus
     * @param array<string,mixed> $candidate
     */
    private function alignmentScore(
        array $focus,
        array $candidate
    ): float {
        $focusIdentity =
            $this->recordIdentity($focus);

        $candidateIdentity =
            $this->recordIdentity(
                $candidate
            );

        $typeComplement = 0.0;

        if (
            in_array(
                $focusIdentity['type'],
                $this->assetTypes,
                true
            )
            && in_array(
                $candidateIdentity['type'],
                $this->implementationTypes,
                true
            )
        ) {
            $typeComplement = 1.0;
        } elseif (
            in_array(
                $candidateIdentity['type'],
                $this->assetTypes,
                true
            )
            && in_array(
                $focusIdentity['type'],
                $this->implementationTypes,
                true
            )
        ) {
            $typeComplement = 0.85;
        }

        $termScore =
            $this->sharedTermScore(
                $focus,
                $candidate
            );

        $jurisdictionScore =
            $this->fieldMatchScore(
                $focus,
                $candidate,
                [
                    'jurisdiction',
                    'country',
                    'province',
                    'state',
                    'region',
                    'municipality',
                ]
            );

        return $this->clamp(
            (
                $typeComplement * 0.45
            ) + (
                $termScore * 0.40
            ) + (
                $jurisdictionScore * 0.15
            ),
            0.0,
            1.0
        );
    }

    /**
     * Return alignment evidence.
     *
     * @param array<string,mixed> $focus
     * @param array<string,mixed> $candidate
     *
     * @return array<int,array<string,mixed>>
     */
    private function alignmentEvidence(
        array $focus,
        array $candidate
    ): array {
        $evidence = [];

        $sharedTerms = array_values(
            array_intersect(
                $this->collectTerms(
                    $focus,
                    [
                        'tags',
                        'keywords',
                        'category',
                        'topics',
                        'purpose',
                        'objective',
                        'mission',
                    ]
                ),
                $this->collectTerms(
                    $candidate,
                    [
                        'tags',
                        'keywords',
                        'category',
                        'topics',
                        'purpose',
                        'objective',
                        'mission',
                    ]
                )
            )
        );

        if ($sharedTerms !== []) {
            $evidence[] = [
                'signal' =>
                    'shared_alignment_terms',

                'description' =>
                    'The asset and program share relevant terms.',

                'values' =>
                    $sharedTerms,
            ];
        }

        $focusType =
            $this->recordIdentity(
                $focus
            )['type'];

        $candidateType =
            $this->recordIdentity(
                $candidate
            )['type'];

        if (
            in_array(
                $focusType,
                $this->assetTypes,
                true
            )
            && in_array(
                $candidateType,
                $this->implementationTypes,
                true
            )
        ) {
            $evidence[] = [
                'signal' =>
                    'complementary_entity_types',

                'description' =>
                    'The focus is an intellectual asset and the candidate represents an implementation or program channel.',
            ];
        }

        foreach (
            [
                'jurisdiction',
                'country',
                'province',
                'state',
                'region',
                'municipality',
            ]
            as $field
        ) {
            $left = $this->normalizedScalar(
                $focus[$field]
                ?? ''
            );

            $right = $this->normalizedScalar(
                $candidate[$field]
                ?? ''
            );

            if (
                $left !== ''
                && $left === $right
            ) {
                $evidence[] = [
                    'signal' =>
                        'shared_jurisdiction',

                    'description' =>
                        sprintf(
                            'Both records identify the same %s.',
                            str_replace(
                                '_',
                                ' ',
                                $field
                            )
                        ),

                    'field' => $field,

                    'value' =>
                        $focus[$field],
                ];
            }
        }

        return $evidence;
    }

    /**
     * Determine recommended government alignment relationship type.
     *
     * @param array<string,mixed> $focus
     * @param array<string,mixed> $candidate
     */
    private function governmentAlignmentType(
        array $focus,
        array $candidate
    ): string {
        $candidateType =
            $this->recordIdentity(
                $candidate
            )['type'];

        if (
            in_array(
                $candidateType,
                [
                    'funding_program',
                    'grant',
                ],
                true
            )
        ) {
            return 'eligible_for';
        }

        if (
            in_array(
                $candidateType,
                [
                    'policy',
                    'regulation',
                    'legislation',
                ],
                true
            )
        ) {
            return 'aligns_with';
        }

        if (
            in_array(
                $candidateType,
                [
                    'government_program',
                    'program',
                    'public_service',
                    'initiative',
                ],
                true
            )
        ) {
            return 'aligns_with';
        }

        return 'related_to';
    }

    /**
     * Build government alignment explanation.
     *
     * @param array<string,mixed> $recommendation
     * @param array<int,array<string,mixed>> $alignment
     */
    private function governmentAlignmentExplanation(
        array $recommendation,
        array $alignment
    ): string {
        $candidate = $recommendation[
            'candidate_identity'
        ] ?? [];

        return sprintf(
            '%s is recommended for Government Program Alignment review with a score of %.2f based on similarity, graph context, shared terms, jurisdiction, and complementary entity roles. %d alignment signal%s were recorded.',
            (string)(
                $candidate['title']
                ?? 'This candidate'
            ),
            (float)(
                $recommendation['score']
                ?? 0
            ),
            count($alignment),
            count($alignment) === 1
                ? ''
                : 's'
        );
    }

    /**
     * Build operational recommendations for one entity.
     *
     * @param array<string,mixed> $entity
     * @return array<int,array<string,mixed>>
     */
    private function entityActionRecommendations(
        array $entity
    ): array {
        $recommendations = [];
        $identity =
            $this->recordIdentity(
                $entity
            );

        $status = $this->normalizeMachineKey(
            (string)(
                $entity['status']
                ?? ''
            )
        );

        if (
            in_array(
                $status,
                [
                    'draft',
                    'proposed',
                    'pending_review',
                ],
                true
            )
        ) {
            $recommendations[] =
                $this->actionRecommendation(
                    'review_entity',
                    72.0,
                    $identity,
                    'Review entity state',
                    sprintf(
                        '%s remains in %s status and requires a recorded review decision.',
                        $identity['title'],
                        str_replace(
                            '_',
                            ' ',
                            $status
                        )
                    ),
                    [
                        'review_content',
                        'confirm_attribution',
                        'confirm_provenance',
                        'record_status_decision',
                    ]
                );
        }

        if (!$this->hasProvenance($entity)) {
            $recommendations[] =
                $this->actionRecommendation(
                    'complete_provenance',
                    80.0,
                    $identity,
                    'Complete provenance',
                    sprintf(
                        '%s lacks a complete provenance reference.',
                        $identity['title']
                    ),
                    [
                        'create_provenance_record',
                        'attach_source_reference',
                    ]
                );
        }

        if (
            trim(
                (string)(
                    $entity['created_by']
                    ?? $entity['originator_id']
                    ?? $entity['originator_email']
                    ?? ''
                )
            ) === ''
        ) {
            $recommendations[] =
                $this->actionRecommendation(
                    'complete_attribution',
                    78.0,
                    $identity,
                    'Complete attribution',
                    sprintf(
                        '%s lacks creator or originator attribution.',
                        $identity['title']
                    ),
                    [
                        'identify_originator',
                        'record_attribution',
                    ]
                );
        }

        if (
            $this->recordCompleteness(
                $entity
            ) < 0.45
        ) {
            $recommendations[] =
                $this->actionRecommendation(
                    'complete_entity',
                    60.0,
                    $identity,
                    'Complete entity fields',
                    sprintf(
                        '%s has limited structured content.',
                        $identity['title']
                    ),
                    [
                        'complete_title',
                        'complete_description',
                        'add_keywords',
                        'add_category',
                    ]
                );
        }

        return $recommendations;
    }

    /**
     * Build operational recommendations for one relationship.
     *
     * @param array<string,mixed> $relationship
     * @return array<int,array<string,mixed>>
     */
    private function relationshipActionRecommendations(
        array $relationship
    ): array {
        $recommendations = [];

        $identity = [
            'identifier' => trim(
                (string)(
                    $relationship[
                        'relationship_id'
                    ] ?? ''
                )
            ),

            'type' => 'relationship',

            'title' => ucwords(
                str_replace(
                    '_',
                    ' ',
                    (string)(
                        $relationship[
                            'relationship_type'
                        ] ?? 'relationship'
                    )
                )
            ),
        ];

        $status = $this->normalizeMachineKey(
            (string)(
                $relationship['status']
                ?? ''
            )
        );

        if ($status === 'proposed') {
            $recommendations[] =
                $this->actionRecommendation(
                    'review_relationship',
                    74.0,
                    $identity,
                    'Review proposed relationship',
                    'A proposed relationship requires acceptance, rejection, or revision.',
                    [
                        'review_relationship_evidence',
                        'accept_or_reject_relationship',
                    ]
                );
        }

        if (
            (
                $relationship[
                    'suggested_by_ai'
                ] ?? false
            ) === true
            && (
                $relationship[
                    'accepted_by_human'
                ] ?? false
            ) === false
        ) {
            $recommendations[] =
                $this->actionRecommendation(
                    'review_ai_relationship',
                    84.0,
                    $identity,
                    'Review AI-assisted relationship',
                    'The relationship was AI-assisted and has no recorded human acceptance.',
                    [
                        'review_relationship_evidence',
                        'record_human_decision',
                    ]
                );
        }

        if (
            trim(
                (string)(
                    $relationship[
                        'provenance_id'
                    ] ?? ''
                )
            ) === ''
        ) {
            $recommendations[] =
                $this->actionRecommendation(
                    'complete_relationship_provenance',
                    76.0,
                    $identity,
                    'Complete relationship provenance',
                    'The relationship lacks a provenance reference.',
                    [
                        'create_provenance_record',
                        'attach_relationship_provenance',
                    ]
                );
        }

        return $recommendations;
    }

    /**
     * Build one action recommendation.
     *
     * @param array<string,string> $identity
     * @param array<int,string> $actions
     *
     * @return array<string,mixed>
     */
    private function actionRecommendation(
        string $type,
        float $score,
        array $identity,
        string $title,
        string $explanation,
        array $actions
    ): array {
        return [
            'recommendation_id' =>
                $this->generateRecommendationId(),

            'recommendation_type' =>
                $type,

            'record_identity' =>
                $identity,

            'score' => $score,

            'classification' =>
                $this->classifyScore(
                    $score
                ),

            'title' => $title,

            'explanation' =>
                $explanation,

            'reasons' => [
                [
                    'signal' => $type,

                    'description' =>
                        $explanation,
                ],
            ],

            'recommended_actions' =>
                $actions,

            'requires_human_review' =>
                true,

            'generated_at' =>
                gmdate('c'),
        ];
    }

    /**
     * Find an existing connection.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @param array<string,string> $left
     * @param array<string,string> $right
     *
     * @return array<string,mixed>|null
     */
    private function findExistingConnection(
        array $relationships,
        array $left,
        array $right
    ): ?array {
        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $type = $this->normalizeMachineKey(
                (string)(
                    $relationship[
                        'relationship_type'
                    ] ?? ''
                )
            );

            if (
                !in_array(
                    $type,
                    $this->existingConnectionTypes,
                    true
                )
            ) {
                continue;
            }

            if (
                $this->relationshipConnects(
                    $relationship,
                    $left,
                    $right
                )
            ) {
                return $relationship;
            }
        }

        return null;
    }

    /**
     * Detect blocking connection.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @param array<string,string> $left
     * @param array<string,string> $right
     */
    private function hasBlockingConnection(
        array $relationships,
        array $left,
        array $right
    ): bool {
        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $type = $this->normalizeMachineKey(
                (string)(
                    $relationship[
                        'relationship_type'
                    ] ?? ''
                )
            );

            if (
                !in_array(
                    $type,
                    $this->blockingRelationshipTypes,
                    true
                )
            ) {
                continue;
            }

            if (
                $this->relationshipConnects(
                    $relationship,
                    $left,
                    $right
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether one relationship connects two identities.
     *
     * @param array<string,mixed> $relationship
     * @param array<string,string> $left
     * @param array<string,string> $right
     */
    private function relationshipConnects(
        array $relationship,
        array $left,
        array $right
    ): bool {
        $sourceId = trim(
            (string)(
                $relationship['source_id']
                ?? ''
            )
        );

        $sourceType =
            $this->normalizeMachineKey(
                (string)(
                    $relationship[
                        'source_type'
                    ] ?? ''
                )
            );

        $targetId = trim(
            (string)(
                $relationship['target_id']
                ?? ''
            )
        );

        $targetType =
            $this->normalizeMachineKey(
                (string)(
                    $relationship[
                        'target_type'
                    ] ?? ''
                )
            );

        $forward =
            $sourceId === $left['identifier']
            && $sourceType === $left['type']
            && $targetId === $right['identifier']
            && $targetType === $right['type'];

        $reverse =
            $sourceId === $right['identifier']
            && $sourceType === $right['type']
            && $targetId === $left['identifier']
            && $targetType === $left['type'];

        return $forward || $reverse;
    }

    /**
     * Calculate lifecycle status priority.
     *
     * @param array<string,mixed> $record
     */
    private function statusPriorityScore(
        array $record
    ): float {
        $status = $this->normalizeMachineKey(
            (string)(
                $record['status']
                ?? ''
            )
        );

        return $this->statusPriority[$status]
            ?? 0.50;
    }

    /**
     * Calculate record recency.
     *
     * @param array<string,mixed> $record
     */
    private function recencyScore(
        array $record
    ): float {
        $timestamp = null;

        foreach (
            [
                'updated_at',
                'created_at',
                'captured_at',
                'published_at',
            ]
            as $field
        ) {
            $value = trim(
                (string)(
                    $record[$field]
                    ?? ''
                )
            );

            if ($value === '') {
                continue;
            }

            $parsed = strtotime($value);

            if ($parsed !== false) {
                $timestamp = $parsed;
                break;
            }
        }

        if ($timestamp === null) {
            return 0.35;
        }

        $days = max(
            0.0,
            (time() - $timestamp)
            / 86400
        );

        if ($days <= 7) {
            return 1.0;
        }

        if ($days <= 30) {
            return 0.85;
        }

        if ($days <= 90) {
            return 0.65;
        }

        if ($days <= 365) {
            return 0.40;
        }

        return 0.20;
    }

    /**
     * Calculate record completeness.
     *
     * @param array<string,mixed> $record
     */
    private function recordCompleteness(
        array $record
    ): float {
        $fields = [
            'title',
            'name',
            'label',
            'description',
            'summary',
            'content',
            'status',
            'created_by',
            'provenance_id',
            'category',
            'tags',
            'keywords',
            'created_at',
            'updated_at',
        ];

        $available = 0;

        foreach ($fields as $field) {
            if (
                !$this->valueIsEmpty(
                    $record[$field]
                    ?? null
                )
            ) {
                $available++;
            }
        }

        return count($fields) > 0
            ? $available / count($fields)
            : 0.0;
    }

    /**
     * Calculate deployment graph score.
     *
     * @param array<string,string> $identity
     * @param array<int,array<string,mixed>> $relationships
     */
    private function deploymentGraphScore(
        array $identity,
        array $relationships
    ): float {
        if (
            $relationships === []
            || $identity['identifier'] === ''
        ) {
            return 0.0;
        }

        try {
            $neighbours =
                $this->traversal->neighbours(
                    $relationships,
                    $identity['identifier'],
                    $identity['type'],
                    'both',
                    [],
                    ['active', 'verified'],
                    false
                );
        } catch (Throwable) {
            return 0.0;
        }

        $degree = count($neighbours);

        if ($degree >= 10) {
            return 1.0;
        }

        if ($degree >= 6) {
            return 0.80;
        }

        if ($degree >= 3) {
            return 0.60;
        }

        if ($degree >= 1) {
            return 0.35;
        }

        return 0.0;
    }

    /**
     * Determine provenance presence.
     *
     * @param array<string,mixed> $record
     */
    private function hasProvenance(
        array $record
    ): bool {
        return trim(
            (string)(
                $record['provenance_id']
                ?? ''
            )
        ) !== ''
            || trim(
                (string)(
                    $record['source_reference']
                    ?? $record['source_url']
                    ?? $record['url']
                    ?? ''
                )
            ) !== '';
    }

    /**
     * Calculate field match score.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @param array<int,string> $fields
     */
    private function fieldMatchScore(
        array $left,
        array $right,
        array $fields
    ): float {
        $comparisons = 0;
        $matches = 0;

        foreach ($fields as $field) {
            $leftValue =
                $this->normalizedScalar(
                    $left[$field]
                    ?? ''
                );

            $rightValue =
                $this->normalizedScalar(
                    $right[$field]
                    ?? ''
                );

            if (
                $leftValue === ''
                || $rightValue === ''
            ) {
                continue;
            }

            $comparisons++;

            if ($leftValue === $rightValue) {
                $matches++;
            }
        }

        return $comparisons > 0
            ? $matches / $comparisons
            : 0.0;
    }

    /**
     * Build entity explanation.
     *
     * @param array<string,string> $focus
     * @param array<string,string> $candidate
     * @param array<int,array<string,mixed>> $reasons
     */
    private function entityExplanation(
        array $focus,
        array $candidate,
        float $score,
        array $reasons
    ): string {
        return sprintf(
            '%s is recommended in relation to %s with a score of %.2f based on %d recorded signal%s.',
            $candidate['title'],
            $focus['title'],
            $score,
            count($reasons),
            count($reasons) === 1
                ? ''
                : 's'
        );
    }

    /**
     * Build provenance explanation.
     *
     * @param array<string,string> $identity
     * @param array<int,array<string,mixed>> $reasons
     */
    private function provenanceExplanation(
        array $identity,
        array $reasons
    ): string {
        return sprintf(
            '%s requires provenance work because %d provenance or attribution gap%s were detected.',
            $identity['title'],
            count($reasons),
            count($reasons) === 1
                ? ' was'
                : 's were'
        );
    }

    /**
     * Build finding title.
     *
     * @param array<string,mixed> $finding
     */
    private function findingTitle(
        array $finding
    ): string {
        return ucwords(
            str_replace(
                '_',
                ' ',
                (string)(
                    $finding['type']
                    ?? 'consistency finding'
                )
            )
        );
    }

    /**
     * Convert severity into recommendation score.
     */
    private function severityScore(
        string $severity
    ): float {
        return match (
            strtolower(trim($severity))
        ) {
            'critical' => 100.0,
            'error' => 90.0,
            'warning' => 72.0,
            'notice' => 50.0,
            'info' => 30.0,
            default => 20.0,
        };
    }

    /**
     * Classify recommendation score.
     */
    private function classifyScore(
        float $score
    ): string {
        if ($score >= 90) {
            return 'critical';
        }

        if ($score >= 80) {
            return 'very_high';
        }

        if ($score >= 65) {
            return 'high';
        }

        if ($score >= 45) {
            return 'moderate';
        }

        if ($score >= 25) {
            return 'low';
        }

        return 'minimal';
    }

    /**
     * Sort recommendations.
     *
     * @param array<int,array<string,mixed>> $recommendations
     */
    private function sortRecommendations(
        array &$recommendations
    ): void {
        usort(
            $recommendations,
            static function (
                array $left,
                array $right
            ): int {
                $scoreComparison =
                    (float)(
                        $right['score']
                        ?? 0
                    )
                    <=>
                    (float)(
                        $left['score']
                        ?? 0
                    );

                if ($scoreComparison !== 0) {
                    return $scoreComparison;
                }

                $reasonComparison =
                    count(
                        $right['reasons']
                        ?? []
                    )
                    <=>
                    count(
                        $left['reasons']
                        ?? []
                    );

                if ($reasonComparison !== 0) {
                    return $reasonComparison;
                }

                return strcmp(
                    (string)(
                        $left[
                            'recommendation_id'
                        ] ?? ''
                    ),
                    (string)(
                        $right[
                            'recommendation_id'
                        ] ?? ''
                    )
                );
            }
        );
    }

    /**
     * Remove duplicate recommendations.
     *
     * @param array<int,array<string,mixed>> $recommendations
     * @return array<int,array<string,mixed>>
     */
    private function deduplicateRecommendations(
        array $recommendations
    ): array {
        $unique = [];

        foreach ($recommendations as $recommendation) {
            if (!is_array($recommendation)) {
                continue;
            }

            $identity =
                $recommendation[
                    'record_identity'
                ] ?? [];

            $key = implode(
                '|',
                [
                    (string)(
                        $recommendation[
                            'recommendation_type'
                        ] ?? ''
                    ),

                    (string)(
                        $identity[
                            'identifier'
                        ] ?? ''
                    ),

                    (string)(
                        $identity['type']
                        ?? ''
                    ),

                    (string)(
                        $recommendation['title']
                        ?? ''
                    ),
                ]
            );

            if (!isset($unique[$key])) {
                $unique[$key] =
                    $recommendation;

                continue;
            }

            if (
                (float)(
                    $recommendation['score']
                    ?? 0
                )
                >
                (float)(
                    $unique[$key]['score']
                    ?? 0
                )
            ) {
                $unique[$key] =
                    $recommendation;
            }
        }

        return array_values($unique);
    }

    /**
     * Resolve generic record identity.
     *
     * @param array<string,mixed> $record
     * @return array<string,string>
     */
    private function recordIdentity(
        array $record
    ): array {
        $identifier = '';

        foreach (
            [
                'entity_id',
                'asset_id',
                'translation_id',
                'document_id',
                'program_id',
                'decision_id',
                'mission_id',
                'relationship_id',
                'organization_id',
                'person_id',
                'url_id',
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
                $identifier = $value;
                break;
            }
        }

        $type = $this->normalizeMachineKey(
            (string)(
                $record['entity_type']
                ?? $record['type']
                ?? (
                    isset(
                        $record[
                            'relationship_type'
                        ]
                    )
                        ? 'relationship'
                        : 'entity'
                )
            )
        );

        return [
            'identifier' => $identifier,

            'type' => $type !== ''
                ? $type
                : 'entity',

            'title' =>
                $this->resolveTitle($record),
        ];
    }

    /**
     * Resolve record title.
     *
     * @param array<string,mixed> $record
     */
    private function resolveTitle(
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
                'program_id',
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
     * Extract neighbour keys.
     *
     * @param array<int,array<string,mixed>> $neighbours
     * @return array<int,string>
     */
    private function neighbourKeys(
        array $neighbours
    ): array {
        $keys = [];

        foreach ($neighbours as $neighbour) {
            if (!is_array($neighbour)) {
                continue;
            }

            $key = trim(
                (string)(
                    $neighbour['node_key']
                    ?? $neighbour[
                        'neighbour_key'
                    ]
                    ?? ''
                )
            );

            if ($key !== '') {
                $keys[$key] = $key;
            }
        }

        return array_values($keys);
    }

    /**
     * Collect normalized terms.
     *
     * @param array<string,mixed> $record
     * @param array<int,string> $fields
     * @return array<int,string>
     */
    private function collectTerms(
        array $record,
        array $fields
    ): array {
        $terms = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $record)) {
                continue;
            }

            foreach (
                $this->flattenValues(
                    $record[$field]
                )
                as $value
            ) {
                $tokens = preg_split(
                    '/[^\p{L}\p{N}_-]+/u',
                    $this->lower($value)
                ) ?: [];

                foreach ($tokens as $token) {
                    $token = trim($token);

                    if (
                        $token !== ''
                        && $this->stringLength(
                            $token
                        ) >= 2
                    ) {
                        $terms[$token] =
                            $token;
                    }
                }
            }
        }

        return array_values($terms);
    }

    /**
     * Collect canonical comparison values.
     *
     * @param array<string,mixed> $record
     * @param array<int,string> $fields
     * @return array<int,string>
     */
    private function collectCanonicalValues(
        array $record,
        array $fields
    ): array {
        $values = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $record)) {
                continue;
            }

            foreach (
                $this->flattenValues(
                    $record[$field]
                )
                as $value
            ) {
                $value = rtrim(
                    $this->lower(
                        trim($value)
                    ),
                    '/'
                );

                if ($value !== '') {
                    $values[$value] =
                        $value;
                }
            }
        }

        return array_values($values);
    }

    /**
     * Flatten arbitrary values.
     *
     * @return array<int,string>
     */
    private function flattenValues(
        mixed $value
    ): array {
        if ($value === null) {
            return [];
        }

        if (is_scalar($value)) {
            return [
                trim((string)$value),
            ];
        }

        if (is_object($value)) {
            if ($value instanceof JsonSerializable) {
                return $this->flattenValues(
                    $value->jsonSerialize()
                );
            }

            if (method_exists($value, 'toArray')) {
                return $this->flattenValues(
                    $value->toArray()
                );
            }

            return $this->flattenValues(
                get_object_vars($value)
            );
        }

        if (!is_array($value)) {
            return [];
        }

        $flattened = [];

        foreach ($value as $item) {
            foreach (
                $this->flattenValues($item)
                as $text
            ) {
                if ($text !== '') {
                    $flattened[] = $text;
                }
            }
        }

        return $flattened;
    }

    /**
     * Calculate Jaccard similarity.
     *
     * @param array<int,string> $left
     * @param array<int,string> $right
     */
    private function jaccard(
        array $left,
        array $right
    ): float {
        $left = array_values(
            array_unique($left)
        );

        $right = array_values(
            array_unique($right)
        );

        if (
            $left === []
            && $right === []
        ) {
            return 0.0;
        }

        if (
            $left === []
            || $right === []
        ) {
            return 0.0;
        }

        $intersection = count(
            array_intersect(
                $left,
                $right
            )
        );

        $union = count(
            array_unique(
                array_merge(
                    $left,
                    $right
                )
            )
        );

        return $union > 0
            ? $intersection / $union
            : 0.0;
    }

    /**
     * Normalize weights.
     *
     * @param array<string,mixed> $weights
     * @return array<string,float>
     */
    private function normalizeWeights(
        array $weights
    ): array {
        $normalized = [];
        $total = 0.0;

        foreach ($this->weights as $name => $default) {
            $value = max(
                0.0,
                (float)(
                    $weights[$name]
                    ?? $default
                )
            );

            $normalized[$name] = $value;
            $total += $value;
        }

        if ($total <= 0) {
            return $this->weights;
        }

        foreach ($normalized as $name => $value) {
            $normalized[$name] =
                $value / $total;
        }

        return $normalized;
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
            $value =
                $this->normalizeMachineKey(
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
     * Normalize scalar comparison value.
     */
    private function normalizedScalar(
        mixed $value
    ): string {
        return $this->lower(
            trim((string)$value)
        );
    }

    /**
     * Normalize machine key.
     */
    private function normalizeMachineKey(
        string $value
    ): string {
        $value = $this->lower(
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
     * Lowercase with multibyte support.
     */
    private function lower(
        string $value
    ): string {
        return function_exists(
            'mb_strtolower'
        )
            ? mb_strtolower(
                $value,
                'UTF-8'
            )
            : strtolower($value);
    }

    /**
     * Return string length.
     */
    private function stringLength(
        string $value
    ): int {
        return function_exists(
            'mb_strlen'
        )
            ? mb_strlen(
                $value,
                'UTF-8'
            )
            : strlen($value);
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

    /**
     * Generate recommendation identifier.
     */
    private function generateRecommendationId(): string
    {
        return 'REC-'
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
}