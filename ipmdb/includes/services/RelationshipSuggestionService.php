<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/RelationshipSuggestionService.php
|--------------------------------------------------------------------------
| IPMdb Relationship Suggestion Service
|--------------------------------------------------------------------------
|
| Generates explainable relationship proposals from supplied entities
| and existing graph relationships.
|
| Responsibilities:
| - Suggest relationships from shared fields, tags, language, provenance,
|   references, categories, contributors, and graph neighbourhoods.
| - Detect likely duplicate, reference, translation, derivation,
|   support, alignment, and implementation relationships.
| - Rank suggestions using evidence and confidence.
| - Prevent existing-edge and self-edge suggestions.
| - Preserve AI suggestions as proposals requiring human acceptance.
|
| This service performs no database operations.
|
| Suggestions are evidence.
| Suggestions are not decisions.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/RelationshipService.php';
require_once __DIR__ . '/GraphTraversalService.php';
require_once __DIR__ . '/GraphSearchService.php';
require_once __DIR__ . '/PathService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class RelationshipSuggestionService extends Service
{
    use GraphUtilities;

    private RelationshipService $relationships;

    private GraphTraversalService $traversal;

    private GraphSearchService $search;

    private PathService $paths;

    /**
     * @var array<int,string>
     */
    private array $defaultTextFields = [
        'title',
        'name',
        'label',
        'idea',
        'summary',
        'description',
        'content',
        'notes',
        'keywords',
        'tags',
        'category',
        'purpose',
        'source_reference',
    ];

    /**
     * @var array<string,float>
     */
    private array $evidenceWeights = [
        'same_identifier' => 1.00,
        'same_checksum' => 1.00,
        'same_source_reference' => 0.92,
        'explicit_reference' => 0.90,
        'translation_pair' => 0.88,
        'shared_provenance' => 0.82,
        'shared_originator' => 0.72,
        'shared_category' => 0.62,
        'shared_tags' => 0.58,
        'shared_keywords' => 0.56,
        'text_similarity' => 0.54,
        'graph_neighbourhood' => 0.48,
        'shared_language' => 0.18,
        'shared_status' => 0.08,
    ];

    /**
     * @var array<int,string>
     */
    private array $allowedSuggestionTypes = [
        'related_to',
        'same_as',
        'duplicate_of',
        'references',
        'supports',
        'extends',
        'derived_from',
        'implements',
        'aligns_with',
        'translated_from',
        'evidence_for',
        'evidence_against',
        'depends_on',
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?RelationshipService $relationships = null,
        ?GraphTraversalService $traversal = null,
        ?GraphSearchService $search = null,
        ?PathService $paths = null
    ) {
        parent::__construct($config, $context);

        $this->relationships = $relationships
            ?? new RelationshipService();

        $this->traversal = $traversal
            ?? new GraphTraversalService();

        $this->search = $search
            ?? new GraphSearchService();

        $this->paths = $paths
            ?? new PathService();
    }

    /**
     * Suggest relationships for one focus entity.
     *
     * @param array<string,mixed> $focus
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $existingRelationships
     *
     * @return array<string,mixed>
     */
    public function suggestForEntity(
        array $focus,
        array $entities,
        array $existingRelationships = [],
        array $options = []
    ): array {
        $this->reset();

        $focusId = $this->resolveEntityId($focus);
        $focusType = $this->resolveEntityType($focus);

        if ($focusId === '') {
            throw new InvalidArgumentException(
                'Focus entity requires a public identifier.'
            );
        }

        $minimumConfidence = $this->clamp(
            (float)($options['minimum_confidence'] ?? 35.0),
            0.0,
            100.0
        );

        $limit = max(
            1,
            min(
                1000,
                (int)($options['limit'] ?? 50)
            )
        );

        $candidateEntityTypes = $this->normalizeStringList(
            $options['candidate_entity_types'] ?? []
        );

        $allowedTypes = $this->normalizeSuggestionTypes(
            $options['relationship_types']
                ?? $this->allowedSuggestionTypes
        );

        $includeExisting = (bool)(
            $options['include_existing'] ?? false
        );

        $includeWeak = (bool)(
            $options['include_weak'] ?? false
        );

        $suggestions = [];

        foreach ($entities as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $candidateId = $this->resolveEntityId(
                $candidate
            );

            $candidateType = $this->resolveEntityType(
                $candidate
            );

            if (
                $candidateId === ''
                || (
                    $candidateId === $focusId
                    && $candidateType === $focusType
                )
            ) {
                continue;
            }

            if (
                $candidateEntityTypes !== []
                && !in_array(
                    $candidateType,
                    $candidateEntityTypes,
                    true
                )
            ) {
                continue;
            }

            $existing = $this->existingRelationship(
                $existingRelationships,
                $focusId,
                $focusType,
                $candidateId,
                $candidateType
            );

            if (
                $existing !== null
                && !$includeExisting
            ) {
                continue;
            }

            $analysis = $this->analyzePair(
                $focus,
                $candidate,
                $existingRelationships,
                $options
            );

            if (
                $analysis['confidence']
                < $minimumConfidence
                && !$includeWeak
            ) {
                continue;
            }

            $relationshipType =
                $this->chooseRelationshipType(
                    $focus,
                    $candidate,
                    $analysis,
                    $allowedTypes
                );

            if ($relationshipType === null) {
                continue;
            }

            $suggestion = $this->buildSuggestion(
                $focus,
                $candidate,
                $relationshipType,
                $analysis,
                $existing,
                $options
            );

            $suggestions[] = $suggestion;
        }

        usort(
            $suggestions,
            static function (
                array $left,
                array $right
            ): int {
                $confidenceComparison =
                    (float)($right['confidence'] ?? 0)
                    <=>
                    (float)($left['confidence'] ?? 0);

                if ($confidenceComparison !== 0) {
                    return $confidenceComparison;
                }

                $evidenceComparison =
                    (int)($right['evidence_count'] ?? 0)
                    <=>
                    (int)($left['evidence_count'] ?? 0);

                if ($evidenceComparison !== 0) {
                    return $evidenceComparison;
                }

                return strcmp(
                    (string)($left['target_id'] ?? ''),
                    (string)($right['target_id'] ?? '')
                );
            }
        );

        $suggestions = array_slice(
            $suggestions,
            0,
            $limit
        );

        $result = [
            'generated_at' => gmdate('c'),

            'focus' => [
                'entity_id' => $focusId,
                'entity_type' => $focusType,
                'title' =>
                    $this->resolveEntityTitle($focus),
            ],

            'candidate_count' => count($entities),

            'suggestion_count' =>
                count($suggestions),

            'minimum_confidence' =>
                $minimumConfidence,

            'suggestions' => $suggestions,

            'summary' =>
                $this->summarizeSuggestions(
                    $suggestions
                ),
        ];

        $this->addMessage(
            'Relationship suggestions generated.',
            [
                'focus_entity_id' => $focusId,
                'candidate_count' => count($entities),
                'suggestion_count' =>
                    count($suggestions),
            ]
        );

        return $result;
    }

    /**
     * Suggest relationships across an entity collection.
     *
     * Pair checks are bounded to prevent uncontrolled quadratic work.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $existingRelationships
     *
     * @return array<string,mixed>
     */
    public function suggestAcrossGraph(
        array $entities,
        array $existingRelationships = [],
        array $options = []
    ): array {
        $this->reset();

        $maximumPairs = max(
            1,
            min(
                1000000,
                (int)($options['maximum_pairs'] ?? 10000)
            )
        );

        $limit = max(
            1,
            min(
                10000,
                (int)($options['limit'] ?? 500)
            )
        );

        $minimumConfidence = $this->clamp(
            (float)($options['minimum_confidence'] ?? 45.0),
            0.0,
            100.0
        );

        $suggestions = [];
        $pairCount = 0;
        $entityCount = count($entities);

        for (
            $leftIndex = 0;
            $leftIndex < $entityCount;
            $leftIndex++
        ) {
            $left = $entities[$leftIndex] ?? null;

            if (!is_array($left)) {
                continue;
            }

            for (
                $rightIndex = $leftIndex + 1;
                $rightIndex < $entityCount;
                $rightIndex++
            ) {
                if ($pairCount >= $maximumPairs) {
                    break 2;
                }

                $right = $entities[$rightIndex] ?? null;

                if (!is_array($right)) {
                    continue;
                }

                $pairCount++;

                $leftId = $this->resolveEntityId($left);
                $rightId = $this->resolveEntityId($right);

                $leftType =
                    $this->resolveEntityType($left);

                $rightType =
                    $this->resolveEntityType($right);

                if (
                    $leftId === ''
                    || $rightId === ''
                    || (
                        $leftId === $rightId
                        && $leftType === $rightType
                    )
                ) {
                    continue;
                }

                if (
                    $this->existingRelationship(
                        $existingRelationships,
                        $leftId,
                        $leftType,
                        $rightId,
                        $rightType
                    ) !== null
                ) {
                    continue;
                }

                $analysis = $this->analyzePair(
                    $left,
                    $right,
                    $existingRelationships,
                    $options
                );

                if (
                    $analysis['confidence']
                    < $minimumConfidence
                ) {
                    continue;
                }

                $relationshipType =
                    $this->chooseRelationshipType(
                        $left,
                        $right,
                        $analysis,
                        $this->allowedSuggestionTypes
                    );

                if ($relationshipType === null) {
                    continue;
                }

                $suggestions[] =
                    $this->buildSuggestion(
                        $left,
                        $right,
                        $relationshipType,
                        $analysis,
                        null,
                        $options
                    );
            }
        }

        usort(
            $suggestions,
            static fn (
                array $left,
                array $right
            ): int =>
                (float)($right['confidence'] ?? 0)
                <=>
                (float)($left['confidence'] ?? 0)
        );

        $suggestions = array_slice(
            $suggestions,
            0,
            $limit
        );

        $this->addMessage(
            'Graph-wide relationship suggestions generated.',
            [
                'entity_count' => $entityCount,
                'pairs_checked' => $pairCount,
                'suggestion_count' =>
                    count($suggestions),
                'truncated' =>
                    $pairCount >= $maximumPairs,
            ]
        );

        return [
            'generated_at' => gmdate('c'),
            'entity_count' => $entityCount,
            'pairs_checked' => $pairCount,
            'maximum_pairs' => $maximumPairs,
            'truncated' =>
                $pairCount >= $maximumPairs,
            'suggestion_count' =>
                count($suggestions),
            'suggestions' => $suggestions,
            'summary' =>
                $this->summarizeSuggestions(
                    $suggestions
                ),
        ];
    }

    /**
     * Analyze evidence connecting two entities.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $target
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    public function analyzePair(
        array $source,
        array $target,
        array $relationships = [],
        array $options = []
    ): array {
        $sourceId = $this->resolveEntityId($source);
        $targetId = $this->resolveEntityId($target);

        $sourceType =
            $this->resolveEntityType($source);

        $targetType =
            $this->resolveEntityType($target);

        $evidence = [];

        $sourceChecksum = trim(
            (string)($source['checksum'] ?? '')
        );

        $targetChecksum = trim(
            (string)($target['checksum'] ?? '')
        );

        if (
            $sourceChecksum !== ''
            && $targetChecksum !== ''
            && hash_equals(
                $sourceChecksum,
                $targetChecksum
            )
        ) {
            $evidence[] = $this->evidence(
                'same_checksum',
                'The entities contain the same checksum.',
                $this->evidenceWeights[
                    'same_checksum'
                ],
                [
                    'checksum' => $sourceChecksum,
                ]
            );
        }

        $sourceReference = $this->canonicalReference(
            $source['source_reference']
                ?? $source['url']
                ?? ''
        );

        $targetReference = $this->canonicalReference(
            $target['source_reference']
                ?? $target['url']
                ?? ''
        );

        if (
            $sourceReference !== ''
            && $targetReference !== ''
            && $sourceReference
                === $targetReference
        ) {
            $evidence[] = $this->evidence(
                'same_source_reference',
                'The entities use the same source reference.',
                $this->evidenceWeights[
                    'same_source_reference'
                ],
                [
                    'source_reference' =>
                        $sourceReference,
                ]
            );
        }

        if (
            $this->recordReferences(
                $source,
                $targetId
            )
        ) {
            $evidence[] = $this->evidence(
                'explicit_reference',
                'The source entity explicitly references the target.',
                $this->evidenceWeights[
                    'explicit_reference'
                ],
                [
                    'direction' => 'source_to_target',
                ]
            );
        }

        if (
            $this->recordReferences(
                $target,
                $sourceId
            )
        ) {
            $evidence[] = $this->evidence(
                'explicit_reference',
                'The target entity explicitly references the source.',
                $this->evidenceWeights[
                    'explicit_reference'
                ],
                [
                    'direction' => 'target_to_source',
                ]
            );
        }

        $translationEvidence =
            $this->translationEvidence(
                $source,
                $target
            );

        if ($translationEvidence !== null) {
            $evidence[] = $translationEvidence;
        }

        $sourceProvenance = trim(
            (string)(
                $source['provenance_id']
                ?? $source['source_entity_id']
                ?? ''
            )
        );

        $targetProvenance = trim(
            (string)(
                $target['provenance_id']
                ?? $target['source_entity_id']
                ?? ''
            )
        );

        if (
            $sourceProvenance !== ''
            && $targetProvenance !== ''
            && $sourceProvenance
                === $targetProvenance
        ) {
            $evidence[] = $this->evidence(
                'shared_provenance',
                'The entities share a provenance reference.',
                $this->evidenceWeights[
                    'shared_provenance'
                ],
                [
                    'provenance_reference' =>
                        $sourceProvenance,
                ]
            );
        }

        $sourceOriginator =
            $this->resolveContributor($source);

        $targetOriginator =
            $this->resolveContributor($target);

        if (
            $sourceOriginator !== ''
            && $targetOriginator !== ''
            && $sourceOriginator
                === $targetOriginator
        ) {
            $evidence[] = $this->evidence(
                'shared_originator',
                'The entities share the same attributed originator.',
                $this->evidenceWeights[
                    'shared_originator'
                ],
                [
                    'originator' =>
                        $sourceOriginator,
                ]
            );
        }

        $sourceCategory = $this->normalizedScalar(
            $source['category'] ?? ''
        );

        $targetCategory = $this->normalizedScalar(
            $target['category'] ?? ''
        );

        if (
            $sourceCategory !== ''
            && $targetCategory !== ''
            && $sourceCategory === $targetCategory
        ) {
            $evidence[] = $this->evidence(
                'shared_category',
                'The entities share the same category.',
                $this->evidenceWeights[
                    'shared_category'
                ],
                [
                    'category' => $sourceCategory,
                ]
            );
        }

        $sharedTags = $this->intersection(
            $this->extractTerms(
                $source['tags'] ?? []
            ),
            $this->extractTerms(
                $target['tags'] ?? []
            )
        );

        if ($sharedTags !== []) {
            $ratio = $this->overlapRatio(
                $this->extractTerms(
                    $source['tags'] ?? []
                ),
                $this->extractTerms(
                    $target['tags'] ?? []
                )
            );

            $evidence[] = $this->evidence(
                'shared_tags',
                'The entities share tags.',
                $this->evidenceWeights[
                    'shared_tags'
                ] * max(0.25, $ratio),
                [
                    'shared_tags' => $sharedTags,
                    'overlap_ratio' => $ratio,
                ]
            );
        }

        $sourceKeywords = $this->extractTerms(
            $source['keywords'] ?? []
        );

        $targetKeywords = $this->extractTerms(
            $target['keywords'] ?? []
        );

        $sharedKeywords = $this->intersection(
            $sourceKeywords,
            $targetKeywords
        );

        if ($sharedKeywords !== []) {
            $ratio = $this->overlapRatio(
                $sourceKeywords,
                $targetKeywords
            );

            $evidence[] = $this->evidence(
                'shared_keywords',
                'The entities share indexed keywords.',
                $this->evidenceWeights[
                    'shared_keywords'
                ] * max(0.25, $ratio),
                [
                    'shared_keywords' =>
                        $sharedKeywords,
                    'overlap_ratio' => $ratio,
                ]
            );
        }

        $textSimilarity =
            $this->recordTextSimilarity(
                $source,
                $target,
                $this->normalizeStringList(
                    $options['text_fields']
                        ?? $this->defaultTextFields
                )
            );

        if (
            $textSimilarity
            >= (float)(
                $options[
                    'minimum_text_similarity'
                ] ?? 0.28
            )
        ) {
            $evidence[] = $this->evidence(
                'text_similarity',
                'The entities contain similar textual content.',
                $this->evidenceWeights[
                    'text_similarity'
                ] * $textSimilarity,
                [
                    'similarity' =>
                        round(
                            $textSimilarity,
                            6
                        ),
                ]
            );
        }

        if ($relationships !== []) {
            $graphEvidence =
                $this->graphNeighbourhoodEvidence(
                    $sourceId,
                    $sourceType,
                    $targetId,
                    $targetType,
                    $relationships
                );

            if ($graphEvidence !== null) {
                $evidence[] = $graphEvidence;
            }
        }

        $sourceLanguage = $this->resolveLanguage(
            $source
        );

        $targetLanguage = $this->resolveLanguage(
            $target
        );

        if (
            $sourceLanguage !== ''
            && $targetLanguage !== ''
            && $sourceLanguage === $targetLanguage
        ) {
            $evidence[] = $this->evidence(
                'shared_language',
                'The entities use the same language.',
                $this->evidenceWeights[
                    'shared_language'
                ],
                [
                    'language' => $sourceLanguage,
                ]
            );
        }

        $sourceStatus = $this->normalizedScalar(
            $source['status'] ?? ''
        );

        $targetStatus = $this->normalizedScalar(
            $target['status'] ?? ''
        );

        if (
            $sourceStatus !== ''
            && $targetStatus !== ''
            && $sourceStatus === $targetStatus
        ) {
            $evidence[] = $this->evidence(
                'shared_status',
                'The entities share the same lifecycle status.',
                $this->evidenceWeights[
                    'shared_status'
                ],
                [
                    'status' => $sourceStatus,
                ]
            );
        }

        $confidence =
            $this->combineEvidenceConfidence(
                $evidence
            );

        return [
            'source_id' => $sourceId,
            'source_type' => $sourceType,
            'target_id' => $targetId,
            'target_type' => $targetType,
            'confidence' => $confidence,
            'evidence_count' => count($evidence),
            'evidence' => $evidence,
            'signals' =>
                array_values(
                    array_unique(
                        array_map(
                            static fn (
                                array $item
                            ): string =>
                                (string)(
                                    $item['type']
                                    ?? ''
                                ),
                            $evidence
                        )
                    )
                ),
        ];
    }

    /**
     * Convert an accepted suggestion into a RelationshipService record.
     *
     * @param array<string,mixed> $suggestion
     * @return array<string,mixed>
     */
    public function accept(
        array $suggestion,
        string $actorId,
        array $overrides = []
    ): array {
        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Human acceptance requires actor attribution.'
            );
        }

        $sourceId = trim(
            (string)(
                $suggestion['source_id']
                ?? ''
            )
        );

        $targetId = trim(
            (string)(
                $suggestion['target_id']
                ?? ''
            )
        );

        if (
            $sourceId === ''
            || $targetId === ''
        ) {
            throw new InvalidArgumentException(
                'Suggestion requires source and target identifiers.'
            );
        }

        $metadata = is_array(
            $suggestion['metadata']
            ?? null
        )
            ? $suggestion['metadata']
            : [];

        $metadata['suggestion_id'] =
            $suggestion['suggestion_id']
            ?? null;

        $metadata['suggestion_evidence'] =
            $suggestion['evidence']
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
                        $suggestion[
                            'source_type'
                        ]
                        ?? 'entity'
                    )
                ),

                'target_id' => $targetId,

                'target_type' => trim(
                    (string)(
                        $suggestion[
                            'target_type'
                        ]
                        ?? 'entity'
                    )
                ),

                'relationship_type' => trim(
                    (string)(
                        $suggestion[
                            'relationship_type'
                        ]
                        ?? 'related_to'
                    )
                ),

                'label' => trim(
                    (string)(
                        $suggestion['label']
                        ?? ''
                    )
                ),

                'description' => trim(
                    (string)(
                        $suggestion[
                            'explanation'
                        ]
                        ?? ''
                    )
                ),

                'confidence' =>
                    $this->clamp(
                        (float)(
                            $suggestion[
                                'confidence'
                            ]
                            ?? 0
                        ),
                        0.0,
                        100.0
                    ),

                'weight' =>
                    $this->suggestedWeight(
                        $suggestion
                    ),

                'strength' =>
                    $this->suggestedStrength(
                        $suggestion
                    ),

                'status' => 'proposed',

                'created_by' => $actorId,

                'suggested_by_ai' => true,

                'accepted_by_human' => true,

                'metadata' => $metadata,

                'tags' =>
                    $this->normalizeStringList(
                        $suggestion['tags']
                            ?? [
                                'relationship_suggestion',
                                'human_accepted',
                            ]
                    ),
            ],
            $overrides
        );

        $relationship =
            $this->relationships->create(
                $input
            );

        $this->addMessage(
            'Relationship suggestion accepted.',
            [
                'suggestion_id' =>
                    $suggestion['suggestion_id']
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
     * Reject a suggestion while preserving the decision.
     *
     * @param array<string,mixed> $suggestion
     * @return array<string,mixed>
     */
    public function reject(
        array $suggestion,
        string $actorId,
        string $reason = ''
    ): array {
        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Suggestion rejection requires actor attribution.'
            );
        }

        return array_merge(
            $suggestion,
            [
                'status' => 'rejected',
                'rejected_by' => $actorId,
                'rejected_at' => gmdate('c'),
                'rejection_reason' =>
                    trim($reason),
            ]
        );
    }

    /**
     * Return suggestions involving one entity.
     *
     * @param array<int,array<string,mixed>> $suggestions
     * @return array<int,array<string,mixed>>
     */
    public function forEntity(
        array $suggestions,
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
                $suggestions,
                static function (
                    array $suggestion
                ) use (
                    $entityId,
                    $entityType
                ): bool {
                    $sourceMatches =
                        trim(
                            (string)(
                                $suggestion[
                                    'source_id'
                                ]
                                ?? ''
                            )
                        ) === $entityId
                        && (
                            $entityType === null
                            || trim(
                                (string)(
                                    $suggestion[
                                        'source_type'
                                    ]
                                    ?? ''
                                )
                            ) === $entityType
                        );

                    $targetMatches =
                        trim(
                            (string)(
                                $suggestion[
                                    'target_id'
                                ]
                                ?? ''
                            )
                        ) === $entityId
                        && (
                            $entityType === null
                            || trim(
                                (string)(
                                    $suggestion[
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
     * Filter suggestions by confidence.
     *
     * @param array<int,array<string,mixed>> $suggestions
     * @return array<int,array<string,mixed>>
     */
    public function aboveConfidence(
        array $suggestions,
        float $minimumConfidence
    ): array {
        $minimumConfidence = $this->clamp(
            $minimumConfidence,
            0.0,
            100.0
        );

        return array_values(
            array_filter(
                $suggestions,
                static fn (
                    array $suggestion
                ): bool =>
                    (float)(
                        $suggestion[
                            'confidence'
                        ]
                        ?? 0
                    ) >= $minimumConfidence
            )
        );
    }

    /**
     * Summarize relationship suggestions.
     *
     * @param array<int,array<string,mixed>> $suggestions
     * @return array<string,mixed>
     */
    public function summarizeSuggestions(
        array $suggestions
    ): array {
        $types = [];
        $confidenceBands = [
            'very_high' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];

        $signals = [];
        $totalConfidence = 0.0;

        foreach ($suggestions as $suggestion) {
            $type = trim(
                (string)(
                    $suggestion[
                        'relationship_type'
                    ]
                    ?? 'related_to'
                )
            );

            $types[$type] =
                ($types[$type] ?? 0) + 1;

            $confidence = (float)(
                $suggestion['confidence']
                ?? 0
            );

            $totalConfidence += $confidence;

            if ($confidence >= 85) {
                $confidenceBands['very_high']++;
            } elseif ($confidence >= 65) {
                $confidenceBands['high']++;
            } elseif ($confidence >= 40) {
                $confidenceBands['medium']++;
            } else {
                $confidenceBands['low']++;
            }

            foreach (
                $suggestion['signals'] ?? []
                as $signal
            ) {
                $signal = trim((string)$signal);

                if ($signal !== '') {
                    $signals[$signal] =
                        ($signals[$signal] ?? 0)
                        + 1;
                }
            }
        }

        arsort($types);
        arsort($signals);

        return [
            'count' => count($suggestions),

            'average_confidence' =>
                $suggestions !== []
                    ? round(
                        $totalConfidence
                        / count($suggestions),
                        2
                    )
                    : 0.0,

            'relationship_types' => $types,

            'confidence_bands' =>
                $confidenceBands,

            'evidence_signals' => $signals,
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
                'allowed_suggestion_types' =>
                    $this->allowedSuggestionTypes,

                'default_text_fields' =>
                    $this->defaultTextFields,

                'evidence_weights' =>
                    $this->evidenceWeights,

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
     * Choose the most plausible relationship type.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $target
     * @param array<string,mixed> $analysis
     * @param array<int,string> $allowedTypes
     */
    private function chooseRelationshipType(
        array $source,
        array $target,
        array $analysis,
        array $allowedTypes
    ): ?string {
        $signals = array_fill_keys(
            $analysis['signals'] ?? [],
            true
        );

        $candidateTypes = [];

        if (
            isset($signals['same_checksum'])
            || isset($signals['same_identifier'])
        ) {
            $candidateTypes[] = 'duplicate_of';
            $candidateTypes[] = 'same_as';
        }

        if (
            isset($signals['translation_pair'])
        ) {
            $candidateTypes[] =
                'translated_from';
        }

        if (
            isset($signals['explicit_reference'])
        ) {
            $candidateTypes[] = 'references';
        }

        if (
            isset($signals['same_source_reference'])
            || isset($signals['shared_provenance'])
        ) {
            $candidateTypes[] =
                'derived_from';
        }

        $sourceType =
            $this->resolveEntityType($source);

        $targetType =
            $this->resolveEntityType($target);

        if (
            in_array(
                $targetType,
                [
                    'government_program',
                    'policy',
                    'regulation',
                    'legislation',
                ],
                true
            )
            || in_array(
                $sourceType,
                [
                    'government_program',
                    'policy',
                    'regulation',
                    'legislation',
                ],
                true
            )
        ) {
            $candidateTypes[] =
                'aligns_with';
        }

        if (
            in_array(
                $targetType,
                [
                    'implementation',
                    'project',
                    'deployment',
                    'application',
                ],
                true
            )
        ) {
            $candidateTypes[] =
                'implements';
        }

        if (
            isset($signals['text_similarity'])
            || isset($signals['shared_tags'])
            || isset($signals['shared_keywords'])
            || isset($signals['shared_category'])
        ) {
            $candidateTypes[] = 'related_to';
        }

        $candidateTypes[] = 'related_to';

        foreach (
            array_values(
                array_unique($candidateTypes)
            )
            as $candidateType
        ) {
            if (
                in_array(
                    $candidateType,
                    $allowedTypes,
                    true
                )
            ) {
                return $candidateType;
            }
        }

        return null;
    }

    /**
     * Build one suggestion record.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $target
     * @param array<string,mixed> $analysis
     * @param array<string,mixed>|null $existing
     *
     * @return array<string,mixed>
     */
    private function buildSuggestion(
        array $source,
        array $target,
        string $relationshipType,
        array $analysis,
        ?array $existing,
        array $options
    ): array {
        $sourceId = $this->resolveEntityId($source);
        $targetId = $this->resolveEntityId($target);

        $sourceType =
            $this->resolveEntityType($source);

        $targetType =
            $this->resolveEntityType($target);

        $suggestionId =
            $this->generateSuggestionId();

        $explanation =
            $this->explainEvidence(
                $analysis['evidence'] ?? []
            );

        return [
            'suggestion_id' => $suggestionId,

            'status' => 'proposed',

            'source_id' => $sourceId,

            'source_type' => $sourceType,

            'source_title' =>
                $this->resolveEntityTitle($source),

            'target_id' => $targetId,

            'target_type' => $targetType,

            'target_title' =>
                $this->resolveEntityTitle($target),

            'relationship_type' =>
                $relationshipType,

            'label' => ucwords(
                str_replace(
                    '_',
                    ' ',
                    $relationshipType
                )
            ),

            'confidence' =>
                round(
                    (float)(
                        $analysis['confidence']
                        ?? 0
                    ),
                    2
                ),

            'evidence_count' =>
                count(
                    $analysis['evidence']
                    ?? []
                ),

            'evidence' =>
                $analysis['evidence']
                ?? [],

            'signals' =>
                $analysis['signals']
                ?? [],

            'explanation' => $explanation,

            'existing_relationship_id' =>
                $existing[
                    'relationship_id'
                ] ?? null,

            'suggested_by' =>
                trim(
                    (string)(
                        $options['suggested_by']
                        ?? 'sq'
                    )
                ),

            'suggested_by_type' =>
                trim(
                    (string)(
                        $options[
                            'suggested_by_type'
                        ]
                        ?? 'ai'
                    )
                ),

            'suggested_at' => gmdate('c'),

            'requires_human_acceptance' =>
                true,

            'metadata' => [
                'source_entity_type' =>
                    $sourceType,

                'target_entity_type' =>
                    $targetType,

                'suggestion_engine' =>
                    static::class,

                'evidence_model' =>
                    'weighted_independent_signals',

                'source_version' =>
                    $source['version'] ?? null,

                'target_version' =>
                    $target['version'] ?? null,
            ],

            'tags' => [
                'relationship_suggestion',
                'ai_assisted',
                $relationshipType,
            ],
        ];
    }

    /**
     * Find an existing relationship between two entities.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<string,mixed>|null
     */
    private function existingRelationship(
        array $relationships,
        string $sourceId,
        string $sourceType,
        string $targetId,
        string $targetType
    ): ?array {
        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $forward =
                trim(
                    (string)(
                        $relationship['source_id']
                        ?? ''
                    )
                ) === $sourceId
                && trim(
                    (string)(
                        $relationship['source_type']
                        ?? ''
                    )
                ) === $sourceType
                && trim(
                    (string)(
                        $relationship['target_id']
                        ?? ''
                    )
                ) === $targetId
                && trim(
                    (string)(
                        $relationship['target_type']
                        ?? ''
                    )
                ) === $targetType;

            $reverse =
                trim(
                    (string)(
                        $relationship['source_id']
                        ?? ''
                    )
                ) === $targetId
                && trim(
                    (string)(
                        $relationship['source_type']
                        ?? ''
                    )
                ) === $targetType
                && trim(
                    (string)(
                        $relationship['target_id']
                        ?? ''
                    )
                ) === $sourceId
                && trim(
                    (string)(
                        $relationship['target_type']
                        ?? ''
                    )
                ) === $sourceType;

            if ($forward || $reverse) {
                return $relationship;
            }
        }

        return null;
    }

    /**
     * Detect shared graph neighbourhood.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<string,mixed>|null
     */
    private function graphNeighbourhoodEvidence(
        string $sourceId,
        string $sourceType,
        string $targetId,
        string $targetType,
        array $relationships
    ): ?array {
        if (
            $sourceId === ''
            || $targetId === ''
        ) {
            return null;
        }

        $sourceNeighbours =
            $this->traversal->neighbours(
                $relationships,
                $sourceId,
                $sourceType,
                'both',
                [],
                ['active', 'verified'],
                false
            );

        $targetNeighbours =
            $this->traversal->neighbours(
                $relationships,
                $targetId,
                $targetType,
                'both',
                [],
                ['active', 'verified'],
                false
            );

        $sourceKeys = [];

        foreach ($sourceNeighbours as $neighbour) {
            $key = trim(
                (string)(
                    $neighbour['node_key']
                    ?? ''
                )
            );

            if ($key !== '') {
                $sourceKeys[$key] = true;
            }
        }

        $shared = [];

        foreach ($targetNeighbours as $neighbour) {
            $key = trim(
                (string)(
                    $neighbour['node_key']
                    ?? ''
                )
            );

            if (
                $key !== ''
                && isset($sourceKeys[$key])
            ) {
                $shared[$key] = $key;
            }
        }

        if ($shared === []) {
            return null;
        }

        $normalizer = max(
            1,
            min(
                count($sourceNeighbours),
                count($targetNeighbours)
            )
        );

        $ratio = min(
            1.0,
            count($shared) / $normalizer
        );

        return $this->evidence(
            'graph_neighbourhood',
            'The entities share graph neighbours.',
            $this->evidenceWeights[
                'graph_neighbourhood'
            ] * max(0.2, $ratio),
            [
                'shared_neighbours' =>
                    array_values($shared),

                'overlap_ratio' =>
                    round($ratio, 6),
            ]
        );
    }

    /**
     * Detect a source/translation pair.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $target
     * @return array<string,mixed>|null
     */
    private function translationEvidence(
        array $source,
        array $target
    ): ?array {
        $sourceId = $this->resolveEntityId($source);
        $targetId = $this->resolveEntityId($target);

        $sourceSourceId = trim(
            (string)(
                $source['source_entity_id']
                ?? ''
            )
        );

        $targetSourceId = trim(
            (string)(
                $target['source_entity_id']
                ?? ''
            )
        );

        $direct =
            $sourceSourceId !== ''
            && $sourceSourceId === $targetId;

        $reverse =
            $targetSourceId !== ''
            && $targetSourceId === $sourceId;

        if (!$direct && !$reverse) {
            return null;
        }

        return $this->evidence(
            'translation_pair',
            'One entity identifies the other as its translation source.',
            $this->evidenceWeights[
                'translation_pair'
            ],
            [
                'direction' => $direct
                    ? 'source_to_target'
                    : 'target_to_source',
            ]
        );
    }

    /**
     * Calculate textual similarity across selected fields.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $target
     * @param array<int,string> $fields
     */
    private function recordTextSimilarity(
        array $source,
        array $target,
        array $fields
    ): float {
        $sourceText = $this->collectText(
            $source,
            $fields
        );

        $targetText = $this->collectText(
            $target,
            $fields
        );

        if (
            $sourceText === ''
            || $targetText === ''
        ) {
            return 0.0;
        }

        $sourceTokens = $this->tokenize(
            $sourceText
        );

        $targetTokens = $this->tokenize(
            $targetText
        );

        if (
            $sourceTokens === []
            || $targetTokens === []
        ) {
            return 0.0;
        }

        $intersection = count(
            array_intersect(
                $sourceTokens,
                $targetTokens
            )
        );

        $union = count(
            array_unique(
                array_merge(
                    $sourceTokens,
                    $targetTokens
                )
            )
        );

        return $union > 0
            ? $intersection / $union
            : 0.0;
    }

    /**
     * Combine independent evidence weights.
     *
     * The probability-union method prevents simple addition from
     * exceeding the valid confidence range.
     *
     * @param array<int,array<string,mixed>> $evidence
     */
    private function combineEvidenceConfidence(
        array $evidence
    ): float {
        if ($evidence === []) {
            return 0.0;
        }

        $remainingProbability = 1.0;

        foreach ($evidence as $item) {
            $weight = $this->clamp(
                (float)($item['weight'] ?? 0),
                0.0,
                1.0
            );

            $remainingProbability *= (
                1.0 - $weight
            );
        }

        return round(
            (
                1.0 - $remainingProbability
            ) * 100,
            2
        );
    }

    /**
     * Create one evidence record.
     *
     * @return array<string,mixed>
     */
    private function evidence(
        string $type,
        string $description,
        float $weight,
        array $details = []
    ): array {
        return [
            'type' => $type,
            'description' =>
                trim($description),
            'weight' => round(
                $this->clamp(
                    $weight,
                    0.0,
                    1.0
                ),
                6
            ),
            'details' => $details,
        ];
    }

    /**
     * Explain accumulated evidence.
     *
     * @param array<int,array<string,mixed>> $evidence
     */
    private function explainEvidence(
        array $evidence
    ): string {
        if ($evidence === []) {
            return 'No material relationship evidence was detected.';
        }

        $descriptions = [];

        foreach ($evidence as $item) {
            $description = trim(
                (string)(
                    $item['description']
                    ?? ''
                )
            );

            if ($description !== '') {
                $descriptions[$description] =
                    $description;
            }
        }

        return implode(
            ' ',
            array_values($descriptions)
        );
    }

    /**
     * Check whether a record explicitly references an identifier.
     *
     * @param array<string,mixed> $record
     */
    private function recordReferences(
        array $record,
        string $identifier
    ): bool {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return false;
        }

        foreach (
            [
                'source_entity_id',
                'parent_id',
                'related_asset_id',
                'references',
                'source_reference',
                'content',
                'notes',
                'metadata',
            ]
            as $field
        ) {
            if (!array_key_exists($field, $record)) {
                continue;
            }

            foreach (
                $this->flattenValues(
                    $record[$field]
                )
                as $value
            ) {
                if (
                    trim($value) === $identifier
                    || str_contains(
                        $value,
                        $identifier
                    )
                ) {
                    return true;
                }
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
                'url_id',
                'person_id',
                'organization_id',
                'program_id',
                'decision_id',
                'mission_id',
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
        return $this->normalizeEntityType(
            (string)(
                $entity['entity_type']
                ?? $entity['type']
                ?? 'entity'
            )
        );
    }

    /**
     * Resolve entity display title.
     *
     * @param array<string,mixed> $entity
     */
    private function resolveEntityTitle(
        array $entity
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

        return 'Untitled entity';
    }

    /**
     * Resolve contributor attribution.
     *
     * @param array<string,mixed> $entity
     */
    private function resolveContributor(
        array $entity
    ): string {
        foreach (
            [
                'originator_id',
                'contributor_id',
                'created_by',
                'translator_id',
                'originator_email',
                'email',
            ]
            as $field
        ) {
            $value = $this->normalizedScalar(
                $entity[$field] ?? ''
            );

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Resolve language.
     *
     * @param array<string,mixed> $entity
     */
    private function resolveLanguage(
        array $entity
    ): string {
        foreach (
            [
                'language',
                'target_language',
                'source_language',
            ]
            as $field
        ) {
            $value = $this->normalizedScalar(
                $entity[$field] ?? ''
            );

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Normalize suggestion types.
     *
     * @return array<int,string>
     */
    private function normalizeSuggestionTypes(
        mixed $types
    ): array {
        $types = $this->normalizeStringList(
            $types
        );

        return array_values(
            array_filter(
                $types,
                fn (string $type): bool =>
                    in_array(
                        $type,
                        $this->allowedSuggestionTypes,
                        true
                    )
            )
        );
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
     * Extract normalized terms.
     *
     * @return array<int,string>
     */
    private function extractTerms(
        mixed $value
    ): array {
        $terms = [];

        foreach (
            $this->flattenValues($value)
            as $item
        ) {
            foreach (
                preg_split(
                    '/[^\p{L}\p{N}_-]+/u',
                    $this->lower($item)
                ) ?: []
                as $term
            ) {
                $term = trim($term);

                if (
                    $term !== ''
                    && strlen($term) >= 2
                ) {
                    $terms[$term] = $term;
                }
            }
        }

        return array_values($terms);
    }

    /**
     * Flatten arbitrary values into strings.
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
     * Collect selected record text.
     *
     * @param array<string,mixed> $record
     * @param array<int,string> $fields
     */
    private function collectText(
        array $record,
        array $fields
    ): string {
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
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }

        return implode(' ', $values);
    }

    /**
     * Tokenize text.
     *
     * @return array<int,string>
     */
    private function tokenize(
        string $value
    ): array {
        $tokens = preg_split(
            '/[^\p{L}\p{N}_-]+/u',
            $this->lower($value)
        ) ?: [];

        $normalized = [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if (
                $token !== ''
                && strlen($token) >= 2
            ) {
                $normalized[$token] = $token;
            }
        }

        return array_values($normalized);
    }

    /**
     * Calculate intersection.
     *
     * @param array<int,string> $left
     * @param array<int,string> $right
     * @return array<int,string>
     */
    private function intersection(
        array $left,
        array $right
    ): array {
        return array_values(
            array_unique(
                array_intersect(
                    $left,
                    $right
                )
            )
        );
    }

    /**
     * Calculate overlap ratio.
     *
     * @param array<int,string> $left
     * @param array<int,string> $right
     */
    private function overlapRatio(
        array $left,
        array $right
    ): float {
        if ($left === [] || $right === []) {
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
     * Normalize references for comparison.
     */
    private function canonicalReference(
        mixed $reference
    ): string {
        $reference = trim(
            (string)$reference
        );

        if ($reference === '') {
            return '';
        }

        $reference = preg_replace(
            '/#.*$/',
            '',
            $reference
        ) ?? $reference;

        return rtrim(
            $this->lower($reference),
            '/'
        );
    }

    /**
     * Calculate relationship weight from suggestion confidence.
     *
     * @param array<string,mixed> $suggestion
     */
    private function suggestedWeight(
        array $suggestion
    ): float {
        return round(
            $this->clamp(
                (
                    (float)(
                        $suggestion[
                            'confidence'
                        ]
                        ?? 0
                    )
                ) / 100,
                0.0,
                1.0
            ),
            6
        );
    }

    /**
     * Calculate relationship strength from evidence count and confidence.
     *
     * @param array<string,mixed> $suggestion
     */
    private function suggestedStrength(
        array $suggestion
    ): float {
        $confidence = (
            (float)(
                $suggestion['confidence']
                ?? 0
            )
        ) / 100;

        $evidenceCount = max(
            0,
            (int)(
                $suggestion[
                    'evidence_count'
                ]
                ?? 0
            )
        );

        $evidenceFactor = min(
            1.0,
            $evidenceCount / 5
        );

        return round(
            $this->clamp(
                (
                    $confidence * 0.75
                )
                + (
                    $evidenceFactor * 0.25
                ),
                0.0,
                1.0
            ),
            6
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
     * Lowercase with multibyte support.
     */
    private function lower(
        string $value
    ): string {
        return function_exists('mb_strtolower')
            ? mb_strtolower(
                $value,
                'UTF-8'
            )
            : strtolower($value);
    }

    /**
     * Clamp a numeric value.
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
     * Generate suggestion identifier.
     */
    private function generateSuggestionId(): string
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

        return 'RSG-'
            . gmdate('Ymd-His')
            . '-'
            . $random;
    }
}