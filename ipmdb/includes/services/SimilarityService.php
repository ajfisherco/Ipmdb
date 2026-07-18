<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/SimilarityService.php
|--------------------------------------------------------------------------
| IPMdb Similarity Service
|--------------------------------------------------------------------------
|
| Calculates explainable similarity between entities, relationships,
| documents, ideas, assets, translations, programs, and graph nodes.
|
| Responsibilities:
| - Compare textual content.
| - Compare structured fields.
| - Compare tags, keywords, categories, and classifications.
| - Compare provenance, attribution, language, and lifecycle metadata.
| - Compare graph neighbourhoods.
| - Rank similar records.
| - Detect likely duplicates and near-duplicates.
| - Preserve component scores and evidence.
| - Support deterministic similarity without external AI dependencies.
|
| Similarity measures resemblance.
| Similarity does not establish identity.
| Human review confirms consequential matches.
|
| This service performs no database operations.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/GraphTraversalService.php';
require_once __DIR__ . '/PathService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class SimilarityService extends Service
{
    use GraphUtilities;

    private GraphTraversalService $traversal;

    private PathService $paths;

    /**
     * Default fields contributing textual evidence.
     *
     * @var array<int,string>
     */
    private array $textFields = [
        'title',
        'name',
        'label',
        'idea',
        'summary',
        'description',
        'content',
        'notes',
        'purpose',
        'objective',
        'mission',
        'source_reference',
    ];

    /**
     * Default fields contributing term-set evidence.
     *
     * @var array<int,string>
     */
    private array $termFields = [
        'tags',
        'keywords',
        'categories',
        'classifications',
        'topics',
        'themes',
        'sectors',
        'domains',
    ];

    /**
     * Default fields contributing exact structured evidence.
     *
     * @var array<int,string>
     */
    private array $structuredFields = [
        'entity_type',
        'type',
        'category',
        'status',
        'language',
        'source_language',
        'target_language',
        'license',
        'currency',
        'jurisdiction',
        'program_type',
        'asset_type',
        'document_type',
    ];

    /**
     * Default component weights.
     *
     * Total equals 1.00.
     *
     * @var array<string,float>
     */
    private array $weights = [
        'identifier' => 0.12,
        'checksum' => 0.14,
        'text' => 0.24,
        'terms' => 0.14,
        'structured' => 0.10,
        'provenance' => 0.08,
        'attribution' => 0.06,
        'temporal' => 0.04,
        'graph' => 0.08,
    ];

    /**
     * Confidence bands.
     *
     * @var array<string,float>
     */
    private array $bands = [
        'identical' => 99.5,
        'very_high' => 85.0,
        'high' => 70.0,
        'moderate' => 50.0,
        'low' => 25.0,
        'minimal' => 0.0,
    ];

    /**
     * Common low-information tokens excluded from token comparisons.
     *
     * @var array<int,string>
     */
    private array $stopWords = [
        'a',
        'an',
        'and',
        'are',
        'as',
        'at',
        'be',
        'by',
        'for',
        'from',
        'has',
        'have',
        'in',
        'into',
        'is',
        'it',
        'of',
        'on',
        'or',
        'that',
        'the',
        'this',
        'to',
        'was',
        'were',
        'will',
        'with',
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?GraphTraversalService $traversal = null,
        ?PathService $paths = null
    ) {
        parent::__construct($config, $context);

        $this->traversal = $traversal
            ?? new GraphTraversalService();

        $this->paths = $paths
            ?? new PathService();

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

        if (
            isset($config['bands'])
            && is_array($config['bands'])
        ) {
            $this->bands = array_merge(
                $this->bands,
                array_map(
                    static fn (mixed $value): float =>
                        (float)$value,
                    $config['bands']
                )
            );
        }
    }

    /**
     * Compare two records.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    public function compare(
        array $left,
        array $right,
        array $relationships = [],
        array $options = []
    ): array {
        $this->reset();

        $weights = isset($options['weights'])
            && is_array($options['weights'])
            ? $this->normalizeWeights(
                array_merge(
                    $this->weights,
                    $options['weights']
                )
            )
            : $this->weights;

        $components = [];

        $components['identifier'] =
            $this->identifierSimilarity(
                $left,
                $right
            );

        $components['checksum'] =
            $this->checksumSimilarity(
                $left,
                $right
            );

        $components['text'] =
            $this->textSimilarity(
                $left,
                $right,
                $this->normalizeStringList(
                    $options['text_fields']
                        ?? $this->textFields
                )
            );

        $components['terms'] =
            $this->termSimilarity(
                $left,
                $right,
                $this->normalizeStringList(
                    $options['term_fields']
                        ?? $this->termFields
                )
            );

        $components['structured'] =
            $this->structuredSimilarity(
                $left,
                $right,
                $this->normalizeStringList(
                    $options['structured_fields']
                        ?? $this->structuredFields
                )
            );

        $components['provenance'] =
            $this->provenanceSimilarity(
                $left,
                $right
            );

        $components['attribution'] =
            $this->attributionSimilarity(
                $left,
                $right
            );

        $components['temporal'] =
            $this->temporalSimilarity(
                $left,
                $right
            );

        $components['graph'] =
            $relationships !== []
                ? $this->graphSimilarity(
                    $left,
                    $right,
                    $relationships,
                    $options
                )
                : $this->emptyComponent(
                    'graph',
                    'No relationship collection was supplied.'
                );

        $weightedScore = 0.0;
        $availableWeight = 0.0;

        foreach ($components as $name => $component) {
            $weight = $weights[$name] ?? 0.0;

            if (
                ($component['available'] ?? false)
                !== true
            ) {
                continue;
            }

            $weightedScore += (
                (float)(
                    $component['score']
                    ?? 0
                )
                * $weight
            );

            $availableWeight += $weight;
        }

        $normalizedScore = $availableWeight > 0
            ? $weightedScore / $availableWeight
            : 0.0;

        $score = round(
            $this->clamp(
                $normalizedScore,
                0.0,
                100.0
            ),
            2
        );

        $classification =
            $this->classifyScore($score);

        $evidence = $this->collectEvidence(
            $components
        );

        $result = [
            'comparison_id' =>
                $this->generateComparisonId(),

            'generated_at' =>
                gmdate('c'),

            'left' =>
                $this->recordIdentity($left),

            'right' =>
                $this->recordIdentity($right),

            'score' => $score,

            'classification' =>
                $classification,

            'possible_duplicate' =>
                $this->possibleDuplicate(
                    $score,
                    $components,
                    $options
                ),

            'possible_same_entity' =>
                $this->possibleSameEntity(
                    $score,
                    $components,
                    $options
                ),

            'available_weight' =>
                round($availableWeight, 6),

            'weights' => $weights,

            'components' => $components,

            'evidence_count' =>
                count($evidence),

            'evidence' => $evidence,

            'explanation' =>
                $this->explainComparison(
                    $score,
                    $classification,
                    $components
                ),

            'requires_human_review' =>
                $score >= (float)(
                    $options[
                        'human_review_threshold'
                    ] ?? 70.0
                ),
        ];

        $this->addMessage(
            'Similarity comparison completed.',
            [
                'score' => $score,
                'classification' =>
                    $classification,
            ]
        );

        return $result;
    }

    /**
     * Rank records by similarity to one focus record.
     *
     * @param array<string,mixed> $focus
     * @param array<int,array<string,mixed>> $candidates
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    public function rank(
        array $focus,
        array $candidates,
        array $relationships = [],
        array $options = []
    ): array {
        $minimumScore = $this->clamp(
            (float)(
                $options['minimum_score']
                    ?? 0.0
            ),
            0.0,
            100.0
        );

        $limit = max(
            1,
            min(
                10000,
                (int)(
                    $options['limit']
                    ?? 50
                )
            )
        );

        $excludeSameIdentifier = (bool)(
            $options['exclude_same_identifier']
                ?? true
        );

        $focusIdentity =
            $this->recordIdentity($focus);

        $results = [];

        foreach ($candidates as $index => $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $candidateIdentity =
                $this->recordIdentity(
                    $candidate
                );

            if (
                $excludeSameIdentifier
                && $focusIdentity['identifier'] !== ''
                && $focusIdentity['identifier']
                    === $candidateIdentity['identifier']
                && $focusIdentity['type']
                    === $candidateIdentity['type']
            ) {
                continue;
            }

            $comparison = $this->compare(
                $focus,
                $candidate,
                $relationships,
                $options
            );

            if (
                $comparison['score']
                < $minimumScore
            ) {
                continue;
            }

            $results[] = [
                'candidate_index' => $index,

                'candidate_identity' =>
                    $candidateIdentity,

                'score' =>
                    $comparison['score'],

                'classification' =>
                    $comparison[
                        'classification'
                    ],

                'possible_duplicate' =>
                    $comparison[
                        'possible_duplicate'
                    ],

                'possible_same_entity' =>
                    $comparison[
                        'possible_same_entity'
                    ],

                'evidence_count' =>
                    $comparison[
                        'evidence_count'
                    ],

                'components' =>
                    $comparison['components'],

                'explanation' =>
                    $comparison[
                        'explanation'
                    ],

                'record' => $candidate,
            ];
        }

        usort(
            $results,
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

                $evidenceComparison =
                    (int)(
                        $right[
                            'evidence_count'
                        ]
                        ?? 0
                    )
                    <=>
                    (int)(
                        $left[
                            'evidence_count'
                        ]
                        ?? 0
                    );

                if ($evidenceComparison !== 0) {
                    return $evidenceComparison;
                }

                return strcmp(
                    (string)(
                        $left[
                            'candidate_identity'
                        ]['identifier']
                        ?? ''
                    ),
                    (string)(
                        $right[
                            'candidate_identity'
                        ]['identifier']
                        ?? ''
                    )
                );
            }
        );

        $results = array_slice(
            $results,
            0,
            $limit
        );

        return [
            'generated_at' => gmdate('c'),

            'focus' => $focusIdentity,

            'candidate_count' =>
                count($candidates),

            'result_count' =>
                count($results),

            'minimum_score' =>
                $minimumScore,

            'limit' => $limit,

            'results' => $results,

            'summary' =>
                $this->summarizeRankedResults(
                    $results
                ),
        ];
    }

    /**
     * Detect likely duplicates across a collection.
     *
     * Pair checks are bounded.
     *
     * @param array<int,array<string,mixed>> $records
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    public function detectDuplicates(
        array $records,
        array $relationships = [],
        array $options = []
    ): array {
        $threshold = $this->clamp(
            (float)(
                $options['threshold']
                    ?? 82.0
            ),
            0.0,
            100.0
        );

        $maximumPairs = max(
            1,
            min(
                1000000,
                (int)(
                    $options['maximum_pairs']
                    ?? 10000
                )
            )
        );

        $limit = max(
            1,
            min(
                100000,
                (int)(
                    $options['limit']
                    ?? 1000
                )
            )
        );

        $pairs = [];
        $checked = 0;
        $recordCount = count($records);

        for (
            $leftIndex = 0;
            $leftIndex < $recordCount;
            $leftIndex++
        ) {
            $left = $records[$leftIndex]
                ?? null;

            if (!is_array($left)) {
                continue;
            }

            for (
                $rightIndex = $leftIndex + 1;
                $rightIndex < $recordCount;
                $rightIndex++
            ) {
                if ($checked >= $maximumPairs) {
                    break 2;
                }

                $right = $records[$rightIndex]
                    ?? null;

                if (!is_array($right)) {
                    continue;
                }

                $checked++;

                if (
                    !$this->candidatePairAllowed(
                        $left,
                        $right,
                        $options
                    )
                ) {
                    continue;
                }

                $comparison = $this->compare(
                    $left,
                    $right,
                    $relationships,
                    $options
                );

                if (
                    $comparison['score']
                    < $threshold
                ) {
                    continue;
                }

                $pairs[] = [
                    'left_index' => $leftIndex,

                    'right_index' => $rightIndex,

                    'left' =>
                        $comparison['left'],

                    'right' =>
                        $comparison['right'],

                    'score' =>
                        $comparison['score'],

                    'classification' =>
                        $comparison[
                            'classification'
                        ],

                    'possible_duplicate' =>
                        $comparison[
                            'possible_duplicate'
                        ],

                    'possible_same_entity' =>
                        $comparison[
                            'possible_same_entity'
                        ],

                    'components' =>
                        $comparison['components'],

                    'evidence' =>
                        $comparison['evidence'],

                    'requires_human_review' =>
                        true,
                ];
            }
        }

        usort(
            $pairs,
            static fn (
                array $left,
                array $right
            ): int =>
                (float)(
                    $right['score']
                    ?? 0
                )
                <=>
                (float)(
                    $left['score']
                    ?? 0
                )
        );

        $pairs = array_slice(
            $pairs,
            0,
            $limit
        );

        return [
            'generated_at' => gmdate('c'),

            'record_count' =>
                $recordCount,

            'pairs_checked' =>
                $checked,

            'maximum_pairs' =>
                $maximumPairs,

            'truncated' =>
                $checked >= $maximumPairs,

            'threshold' => $threshold,

            'duplicate_pair_count' =>
                count($pairs),

            'pairs' => $pairs,

            'summary' =>
                $this->summarizeDuplicatePairs(
                    $pairs
                ),
        ];
    }

    /**
     * Compare textual content only.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @param array<int,string> $fields
     *
     * @return array<string,mixed>
     */
    public function textSimilarity(
        array $left,
        array $right,
        array $fields = []
    ): array {
        $fields = $fields !== []
            ? $fields
            : $this->textFields;

        $leftText = $this->collectText(
            $left,
            $fields
        );

        $rightText = $this->collectText(
            $right,
            $fields
        );

        if (
            $leftText === ''
            || $rightText === ''
        ) {
            return $this->emptyComponent(
                'text',
                'One or both records contain no comparable text.'
            );
        }

        $leftNormalized =
            $this->normalizeText($leftText);

        $rightNormalized =
            $this->normalizeText($rightText);

        if (
            $leftNormalized === ''
            || $rightNormalized === ''
        ) {
            return $this->emptyComponent(
                'text',
                'Normalized text is empty.'
            );
        }

        if (
            $leftNormalized === $rightNormalized
        ) {
            return $this->component(
                'text',
                100.0,
                [
                    [
                        'signal' =>
                            'exact_normalized_text',

                        'description' =>
                            'Normalized textual content is identical.',
                    ],
                ],
                [
                    'left_length' =>
                        $this->stringLength(
                            $leftNormalized
                        ),

                    'right_length' =>
                        $this->stringLength(
                            $rightNormalized
                        ),
                ]
            );
        }

        $leftTokens = $this->tokenize(
            $leftNormalized
        );

        $rightTokens = $this->tokenize(
            $rightNormalized
        );

        $jaccard = $this->jaccard(
            $leftTokens,
            $rightTokens
        );

        $dice = $this->dice(
            $leftTokens,
            $rightTokens
        );

        $containment = $this->containmentRatio(
            $leftTokens,
            $rightTokens
        );

        $characterSimilarity =
            $this->characterSimilarity(
                $leftNormalized,
                $rightNormalized
            );

        $prefixSimilarity =
            $this->prefixSimilarity(
                $leftNormalized,
                $rightNormalized
            );

        $score = (
            $jaccard * 0.32
        ) + (
            $dice * 0.24
        ) + (
            $containment * 0.18
        ) + (
            $characterSimilarity * 0.20
        ) + (
            $prefixSimilarity * 0.06
        );

        $evidence = [];

        if ($jaccard >= 0.5) {
            $evidence[] = [
                'signal' =>
                    'high_token_overlap',

                'description' =>
                    'The records share substantial token overlap.',

                'value' =>
                    round($jaccard, 6),
            ];
        }

        if ($containment >= 0.75) {
            $evidence[] = [
                'signal' =>
                    'text_containment',

                'description' =>
                    'Most terms from the shorter record appear in the longer record.',

                'value' =>
                    round($containment, 6),
            ];
        }

        if ($characterSimilarity >= 0.85) {
            $evidence[] = [
                'signal' =>
                    'high_character_similarity',

                'description' =>
                    'Normalized text differs by relatively few character edits.',

                'value' =>
                    round(
                        $characterSimilarity,
                        6
                    ),
            ];
        }

        return $this->component(
            'text',
            $score * 100,
            $evidence,
            [
                'jaccard' =>
                    round($jaccard, 6),

                'dice' =>
                    round($dice, 6),

                'containment' =>
                    round($containment, 6),

                'character_similarity' =>
                    round(
                        $characterSimilarity,
                        6
                    ),

                'prefix_similarity' =>
                    round(
                        $prefixSimilarity,
                        6
                    ),

                'left_token_count' =>
                    count($leftTokens),

                'right_token_count' =>
                    count($rightTokens),
            ]
        );
    }

    /**
     * Compare term collections.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @param array<int,string> $fields
     *
     * @return array<string,mixed>
     */
    public function termSimilarity(
        array $left,
        array $right,
        array $fields = []
    ): array {
        $fields = $fields !== []
            ? $fields
            : $this->termFields;

        $leftTerms = $this->collectTerms(
            $left,
            $fields
        );

        $rightTerms = $this->collectTerms(
            $right,
            $fields
        );

        if (
            $leftTerms === []
            || $rightTerms === []
        ) {
            return $this->emptyComponent(
                'terms',
                'One or both records contain no comparable term sets.'
            );
        }

        $intersection = array_values(
            array_intersect(
                $leftTerms,
                $rightTerms
            )
        );

        $jaccard = $this->jaccard(
            $leftTerms,
            $rightTerms
        );

        $containment =
            $this->containmentRatio(
                $leftTerms,
                $rightTerms
            );

        $score = (
            $jaccard * 0.65
        ) + (
            $containment * 0.35
        );

        return $this->component(
            'terms',
            $score * 100,
            $intersection !== []
                ? [
                    [
                        'signal' =>
                            'shared_terms',

                        'description' =>
                            'The records share tags, keywords, or classifications.',

                        'values' =>
                            $intersection,
                    ],
                ]
                : [],
            [
                'shared_terms' =>
                    $intersection,

                'shared_count' =>
                    count($intersection),

                'left_count' =>
                    count($leftTerms),

                'right_count' =>
                    count($rightTerms),

                'jaccard' =>
                    round($jaccard, 6),

                'containment' =>
                    round($containment, 6),
            ]
        );
    }

    /**
     * Compare structured scalar fields.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @param array<int,string> $fields
     *
     * @return array<string,mixed>
     */
    public function structuredSimilarity(
        array $left,
        array $right,
        array $fields = []
    ): array {
        $fields = $fields !== []
            ? $fields
            : $this->structuredFields;

        $compared = [];
        $matches = [];
        $mismatches = [];

        foreach ($fields as $field) {
            $leftValue = $left[$field]
                ?? null;

            $rightValue = $right[$field]
                ?? null;

            if (
                $this->valueIsEmpty($leftValue)
                || $this->valueIsEmpty($rightValue)
            ) {
                continue;
            }

            $leftNormalized =
                $this->normalizedScalar(
                    $leftValue
                );

            $rightNormalized =
                $this->normalizedScalar(
                    $rightValue
                );

            $compared[] = $field;

            if (
                $leftNormalized
                === $rightNormalized
            ) {
                $matches[$field] = [
                    'left' => $leftValue,
                    'right' => $rightValue,
                ];
            } else {
                $mismatches[$field] = [
                    'left' => $leftValue,
                    'right' => $rightValue,
                ];
            }
        }

        if ($compared === []) {
            return $this->emptyComponent(
                'structured',
                'No populated structured fields were available on both records.'
            );
        }

        $score = count($matches)
            / count($compared);

        return $this->component(
            'structured',
            $score * 100,
            $matches !== []
                ? [
                    [
                        'signal' =>
                            'matching_structured_fields',

                        'description' =>
                            'The records share structured field values.',

                        'fields' =>
                            array_keys($matches),
                    ],
                ]
                : [],
            [
                'compared_fields' =>
                    $compared,

                'matches' => $matches,

                'mismatches' =>
                    $mismatches,

                'match_count' =>
                    count($matches),

                'comparison_count' =>
                    count($compared),
            ]
        );
    }

    /**
     * Compare graph neighbourhoods.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    public function graphSimilarity(
        array $left,
        array $right,
        array $relationships,
        array $options = []
    ): array {
        $leftIdentity =
            $this->recordIdentity($left);

        $rightIdentity =
            $this->recordIdentity($right);

        if (
            $leftIdentity['identifier'] === ''
            || $rightIdentity['identifier'] === ''
        ) {
            return $this->emptyComponent(
                'graph',
                'Both records require identifiers for graph comparison.'
            );
        }

        $statuses = $this->normalizeStringList(
            $options['graph_statuses']
                ?? ['active', 'verified']
        );

        $relationshipTypes =
            $this->normalizeStringList(
                $options[
                    'graph_relationship_types'
                ] ?? []
            );

        $leftNeighbours =
            $this->traversal->neighbours(
                $relationships,
                $leftIdentity['identifier'],
                $leftIdentity['type'],
                'both',
                $relationshipTypes,
                $statuses,
                (bool)(
                    $options[
                        'include_expired_relationships'
                    ] ?? false
                )
            );

        $rightNeighbours =
            $this->traversal->neighbours(
                $relationships,
                $rightIdentity['identifier'],
                $rightIdentity['type'],
                'both',
                $relationshipTypes,
                $statuses,
                (bool)(
                    $options[
                        'include_expired_relationships'
                    ] ?? false
                )
            );

        $leftKeys = $this->extractNeighbourKeys(
            $leftNeighbours
        );

        $rightKeys = $this->extractNeighbourKeys(
            $rightNeighbours
        );

        $leftTypes =
            $this->extractNeighbourRelationshipTypes(
                $leftNeighbours
            );

        $rightTypes =
            $this->extractNeighbourRelationshipTypes(
                $rightNeighbours
            );

        if (
            $leftKeys === []
            && $rightKeys === []
        ) {
            return $this->emptyComponent(
                'graph',
                'Neither record has an eligible graph neighbourhood.'
            );
        }

        $nodeOverlap = $this->jaccard(
            $leftKeys,
            $rightKeys
        );

        $typeOverlap = $this->jaccard(
            $leftTypes,
            $rightTypes
        );

        $degreeSimilarity =
            $this->degreeSimilarity(
                count($leftKeys),
                count($rightKeys)
            );

        $score = (
            $nodeOverlap * 0.60
        ) + (
            $typeOverlap * 0.25
        ) + (
            $degreeSimilarity * 0.15
        );

        $sharedNeighbours = array_values(
            array_intersect(
                $leftKeys,
                $rightKeys
            )
        );

        return $this->component(
            'graph',
            $score * 100,
            $sharedNeighbours !== []
                ? [
                    [
                        'signal' =>
                            'shared_graph_neighbours',

                        'description' =>
                            'The records connect to common graph nodes.',

                        'values' =>
                            $sharedNeighbours,
                    ],
                ]
                : [],
            [
                'shared_neighbours' =>
                    $sharedNeighbours,

                'left_degree' =>
                    count($leftKeys),

                'right_degree' =>
                    count($rightKeys),

                'node_overlap' =>
                    round($nodeOverlap, 6),

                'relationship_type_overlap' =>
                    round($typeOverlap, 6),

                'degree_similarity' =>
                    round(
                        $degreeSimilarity,
                        6
                    ),
            ]
        );
    }

    /**
     * Return similarity diagnostics.
     *
     * @return array<string,mixed>
     */
    public function diagnostics(): array
    {
        return array_merge(
            parent::diagnostics(),
            [
                'text_fields' =>
                    $this->textFields,

                'term_fields' =>
                    $this->termFields,

                'structured_fields' =>
                    $this->structuredFields,

                'weights' =>
                    $this->weights,

                'bands' =>
                    $this->bands,

                'supports_graph_similarity' =>
                    true,

                'supports_duplicate_detection' =>
                    true,

                'external_ai_required' =>
                    false,

                'automatic_merge' =>
                    false,

                'human_review_required_for_duplicates' =>
                    true,

                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Compare public identifiers.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     *
     * @return array<string,mixed>
     */
    private function identifierSimilarity(
        array $left,
        array $right
    ): array {
        $leftIdentity =
            $this->recordIdentity($left);

        $rightIdentity =
            $this->recordIdentity($right);

        if (
            $leftIdentity['identifier'] === ''
            || $rightIdentity['identifier'] === ''
        ) {
            return $this->emptyComponent(
                'identifier',
                'One or both records lack a public identifier.'
            );
        }

        $sameIdentifier =
            $leftIdentity['identifier']
            === $rightIdentity['identifier'];

        $sameType =
            $leftIdentity['type']
            === $rightIdentity['type'];

        $score = 0.0;

        if ($sameIdentifier && $sameType) {
            $score = 100.0;
        } elseif ($sameIdentifier) {
            $score = 78.0;
        } else {
            $score = $this->characterSimilarity(
                $this->lower(
                    $leftIdentity['identifier']
                ),
                $this->lower(
                    $rightIdentity['identifier']
                )
            ) * 45.0;
        }

        return $this->component(
            'identifier',
            $score,
            $sameIdentifier
                ? [
                    [
                        'signal' =>
                            'same_identifier',

                        'description' =>
                            $sameType
                                ? 'The records use the same identifier and type.'
                                : 'The records use the same identifier with different types.',
                    ],
                ]
                : [],
            [
                'left_identifier' =>
                    $leftIdentity[
                        'identifier'
                    ],

                'right_identifier' =>
                    $rightIdentity[
                        'identifier'
                    ],

                'same_identifier' =>
                    $sameIdentifier,

                'same_type' =>
                    $sameType,
            ]
        );
    }

    /**
     * Compare checksum values.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     *
     * @return array<string,mixed>
     */
    private function checksumSimilarity(
        array $left,
        array $right
    ): array {
        $leftChecksums =
            $this->extractChecksums($left);

        $rightChecksums =
            $this->extractChecksums($right);

        if (
            $leftChecksums === []
            || $rightChecksums === []
        ) {
            return $this->emptyComponent(
                'checksum',
                'One or both records lack comparable checksums.'
            );
        }

        $shared = array_values(
            array_intersect(
                $leftChecksums,
                $rightChecksums
            )
        );

        $score = $shared !== []
            ? 100.0
            : 0.0;

        return $this->component(
            'checksum',
            $score,
            $shared !== []
                ? [
                    [
                        'signal' =>
                            'same_checksum',

                        'description' =>
                            'The records share at least one checksum.',

                        'values' => $shared,
                    ],
                ]
                : [],
            [
                'left_checksums' =>
                    $leftChecksums,

                'right_checksums' =>
                    $rightChecksums,

                'shared_checksums' =>
                    $shared,
            ]
        );
    }

    /**
     * Compare provenance references.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     *
     * @return array<string,mixed>
     */
    private function provenanceSimilarity(
        array $left,
        array $right
    ): array {
        $fields = [
            'provenance_id',
            'source_entity_id',
            'source_reference',
            'source_url',
            'url',
            'origin_id',
        ];

        $leftValues = $this->collectCanonicalValues(
            $left,
            $fields
        );

        $rightValues = $this->collectCanonicalValues(
            $right,
            $fields
        );

        if (
            $leftValues === []
            || $rightValues === []
        ) {
            return $this->emptyComponent(
                'provenance',
                'One or both records lack comparable provenance values.'
            );
        }

        $shared = array_values(
            array_intersect(
                $leftValues,
                $rightValues
            )
        );

        $score = $this->jaccard(
            $leftValues,
            $rightValues
        ) * 100;

        return $this->component(
            'provenance',
            $score,
            $shared !== []
                ? [
                    [
                        'signal' =>
                            'shared_provenance',

                        'description' =>
                            'The records share provenance or source references.',

                        'values' => $shared,
                    ],
                ]
                : [],
            [
                'shared_values' => $shared,

                'left_values' =>
                    $leftValues,

                'right_values' =>
                    $rightValues,
            ]
        );
    }

    /**
     * Compare attribution.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     *
     * @return array<string,mixed>
     */
    private function attributionSimilarity(
        array $left,
        array $right
    ): array {
        $fields = [
            'originator_id',
            'originator_email',
            'created_by',
            'contributor_id',
            'translator_id',
            'author_id',
            'author',
            'owner_id',
        ];

        $leftValues = $this->collectCanonicalValues(
            $left,
            $fields
        );

        $rightValues = $this->collectCanonicalValues(
            $right,
            $fields
        );

        if (
            $leftValues === []
            || $rightValues === []
        ) {
            return $this->emptyComponent(
                'attribution',
                'One or both records lack comparable attribution.'
            );
        }

        $shared = array_values(
            array_intersect(
                $leftValues,
                $rightValues
            )
        );

        $score = $this->jaccard(
            $leftValues,
            $rightValues
        ) * 100;

        return $this->component(
            'attribution',
            $score,
            $shared !== []
                ? [
                    [
                        'signal' =>
                            'shared_attribution',

                        'description' =>
                            'The records share attributed contributors or creators.',

                        'values' => $shared,
                    ],
                ]
                : [],
            [
                'shared_values' => $shared,

                'left_values' =>
                    $leftValues,

                'right_values' =>
                    $rightValues,
            ]
        );
    }

    /**
     * Compare temporal proximity.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     *
     * @return array<string,mixed>
     */
    private function temporalSimilarity(
        array $left,
        array $right
    ): array {
        $leftTime = $this->firstTimestamp(
            $left,
            [
                'created_at',
                'captured_at',
                'published_at',
                'effective_at',
                'updated_at',
            ]
        );

        $rightTime = $this->firstTimestamp(
            $right,
            [
                'created_at',
                'captured_at',
                'published_at',
                'effective_at',
                'updated_at',
            ]
        );

        if (
            $leftTime === null
            || $rightTime === null
        ) {
            return $this->emptyComponent(
                'temporal',
                'One or both records lack comparable timestamps.'
            );
        }

        $differenceSeconds = abs(
            $leftTime - $rightTime
        );

        $differenceDays =
            $differenceSeconds / 86400;

        if ($differenceDays <= 1) {
            $score = 100.0;
        } elseif ($differenceDays <= 7) {
            $score = 90.0;
        } elseif ($differenceDays <= 30) {
            $score = 75.0;
        } elseif ($differenceDays <= 90) {
            $score = 55.0;
        } elseif ($differenceDays <= 365) {
            $score = 30.0;
        } else {
            $score = max(
                0.0,
                20.0 - log10(
                    max(
                        1.0,
                        $differenceDays
                    )
                ) * 5
            );
        }

        return $this->component(
            'temporal',
            $score,
            $differenceDays <= 30
                ? [
                    [
                        'signal' =>
                            'temporal_proximity',

                        'description' =>
                            'The records were created or published within a similar period.',

                        'difference_days' =>
                            round(
                                $differenceDays,
                                2
                            ),
                    ],
                ]
                : [],
            [
                'left_timestamp' =>
                    gmdate('c', $leftTime),

                'right_timestamp' =>
                    gmdate('c', $rightTime),

                'difference_seconds' =>
                    $differenceSeconds,

                'difference_days' =>
                    round(
                        $differenceDays,
                        6
                    ),
            ]
        );
    }

    /**
     * Build one component response.
     *
     * @param array<int,array<string,mixed>> $evidence
     * @param array<string,mixed> $details
     *
     * @return array<string,mixed>
     */
    private function component(
        string $name,
        float $score,
        array $evidence = [],
        array $details = []
    ): array {
        return [
            'name' => $name,

            'available' => true,

            'score' => round(
                $this->clamp(
                    $score,
                    0.0,
                    100.0
                ),
                2
            ),

            'evidence_count' =>
                count($evidence),

            'evidence' => $evidence,

            'details' => $details,
        ];
    }

    /**
     * Build unavailable component response.
     *
     * @return array<string,mixed>
     */
    private function emptyComponent(
        string $name,
        string $reason
    ): array {
        return [
            'name' => $name,

            'available' => false,

            'score' => 0.0,

            'evidence_count' => 0,

            'evidence' => [],

            'details' => [
                'reason' => trim($reason),
            ],
        ];
    }

    /**
     * Determine possible duplicate state.
     *
     * @param array<string,array<string,mixed>> $components
     */
    private function possibleDuplicate(
        float $score,
        array $components,
        array $options
    ): bool {
        $threshold = (float)(
            $options[
                'duplicate_threshold'
            ] ?? 82.0
        );

        $checksumMatch =
            (
                $components['checksum'][
                    'score'
                ] ?? 0
            ) >= 100;

        $highText =
            (
                $components['text']['score']
                ?? 0
            ) >= 88;

        $highTerms =
            (
                $components['terms']['score']
                ?? 0
            ) >= 75;

        return $checksumMatch
            || $score >= $threshold
            || ($highText && $highTerms);
    }

    /**
     * Determine possible identity match.
     *
     * @param array<string,array<string,mixed>> $components
     */
    private function possibleSameEntity(
        float $score,
        array $components,
        array $options
    ): bool {
        $threshold = (float)(
            $options[
                'same_entity_threshold'
            ] ?? 90.0
        );

        $sameIdentifier =
            (
                $components['identifier'][
                    'details'
                ]['same_identifier']
                ?? false
            ) === true;

        $sameChecksum =
            (
                $components['checksum'][
                    'score'
                ] ?? 0
            ) >= 100;

        return $sameIdentifier
            || $sameChecksum
            || $score >= $threshold;
    }

    /**
     * Determine whether a duplicate candidate pair is eligible.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function candidatePairAllowed(
        array $left,
        array $right,
        array $options
    ): bool {
        $requireSameType = (bool)(
            $options['require_same_type']
                ?? false
        );

        if (!$requireSameType) {
            return true;
        }

        return $this->recordIdentity(
            $left
        )['type']
            ===
            $this->recordIdentity(
                $right
            )['type'];
    }

    /**
     * Classify one score.
     */
    private function classifyScore(
        float $score
    ): string {
        foreach ($this->bands as $name => $minimum) {
            if ($score >= $minimum) {
                return $name;
            }
        }

        return 'minimal';
    }

    /**
     * Explain one comparison.
     *
     * @param array<string,array<string,mixed>> $components
     */
    private function explainComparison(
        float $score,
        string $classification,
        array $components
    ): string {
        $strongest = [];

        foreach ($components as $name => $component) {
            if (
                ($component['available'] ?? false)
                !== true
            ) {
                continue;
            }

            $strongest[$name] = (float)(
                $component['score']
                ?? 0
            );
        }

        arsort($strongest);

        $top = array_slice(
            $strongest,
            0,
            3,
            true
        );

        $parts = [];

        foreach ($top as $name => $componentScore) {
            $parts[] = sprintf(
                '%s %.2f',
                str_replace('_', ' ', $name),
                $componentScore
            );
        }

        return sprintf(
            'Overall similarity is %.2f%% (%s). Strongest components: %s.',
            $score,
            str_replace('_', ' ', $classification),
            $parts !== []
                ? implode(', ', $parts)
                : 'none'
        );
    }

    /**
     * Collect component evidence.
     *
     * @param array<string,array<string,mixed>> $components
     * @return array<int,array<string,mixed>>
     */
    private function collectEvidence(
        array $components
    ): array {
        $evidence = [];

        foreach ($components as $name => $component) {
            foreach (
                $component['evidence']
                    ?? []
                as $item
            ) {
                if (!is_array($item)) {
                    continue;
                }

                $item['component'] = $name;
                $evidence[] = $item;
            }
        }

        return $evidence;
    }

    /**
     * Summarize ranked results.
     *
     * @param array<int,array<string,mixed>> $results
     * @return array<string,mixed>
     */
    private function summarizeRankedResults(
        array $results
    ): array {
        $bands = [];
        $duplicates = 0;
        $sameEntities = 0;
        $totalScore = 0.0;

        foreach ($results as $result) {
            $classification = trim(
                (string)(
                    $result[
                        'classification'
                    ]
                    ?? 'minimal'
                )
            );

            $bands[$classification] =
                ($bands[$classification] ?? 0)
                + 1;

            $totalScore += (float)(
                $result['score']
                ?? 0
            );

            if (
                ($result[
                    'possible_duplicate'
                ] ?? false) === true
            ) {
                $duplicates++;
            }

            if (
                ($result[
                    'possible_same_entity'
                ] ?? false) === true
            ) {
                $sameEntities++;
            }
        }

        arsort($bands);

        return [
            'count' => count($results),

            'average_score' =>
                $results !== []
                    ? round(
                        $totalScore
                        / count($results),
                        2
                    )
                    : 0.0,

            'possible_duplicate_count' =>
                $duplicates,

            'possible_same_entity_count' =>
                $sameEntities,

            'classifications' => $bands,
        ];
    }

    /**
     * Summarize duplicate pairs.
     *
     * @param array<int,array<string,mixed>> $pairs
     * @return array<string,mixed>
     */
    private function summarizeDuplicatePairs(
        array $pairs
    ): array {
        $bands = [];
        $sameEntityCount = 0;
        $totalScore = 0.0;

        foreach ($pairs as $pair) {
            $classification = trim(
                (string)(
                    $pair[
                        'classification'
                    ]
                    ?? 'minimal'
                )
            );

            $bands[$classification] =
                ($bands[$classification] ?? 0)
                + 1;

            $totalScore += (float)(
                $pair['score']
                ?? 0
            );

            if (
                ($pair[
                    'possible_same_entity'
                ] ?? false) === true
            ) {
                $sameEntityCount++;
            }
        }

        arsort($bands);

        return [
            'count' => count($pairs),

            'average_score' =>
                $pairs !== []
                    ? round(
                        $totalScore
                        / count($pairs),
                        2
                    )
                    : 0.0,

            'possible_same_entity_count' =>
                $sameEntityCount,

            'classifications' => $bands,
        ];
    }

    /**
     * Resolve record identity.
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
                'relationship_id',
                'program_id',
                'decision_id',
                'mission_id',
                'person_id',
                'organization_id',
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
     * Resolve display title.
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
     * Collect text from selected fields.
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
                $value = trim($value);

                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }

        return trim(
            implode(' ', $values)
        );
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
                foreach (
                    $this->tokenize($value)
                    as $term
                ) {
                    $terms[$term] = $term;
                }
            }
        }

        return array_values($terms);
    }

    /**
     * Collect canonical scalar values.
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
                $canonical =
                    $this->canonicalValue(
                        $value
                    );

                if ($canonical !== '') {
                    $values[$canonical] =
                        $canonical;
                }
            }
        }

        return array_values($values);
    }

    /**
     * Extract known checksum fields.
     *
     * @param array<string,mixed> $record
     * @return array<int,string>
     */
    private function extractChecksums(
        array $record
    ): array {
        $checksums = [];

        foreach (
            [
                'checksum',
                'content_hash',
                'source_hash',
                'file_hash',
                'sha256',
                'sha1',
                'md5',
            ]
            as $field
        ) {
            $value = strtolower(
                trim(
                    (string)(
                        $record[$field]
                        ?? ''
                    )
                )
            );

            if ($value !== '') {
                $checksums[$value] = $value;
            }
        }

        return array_values($checksums);
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
     * Normalize natural language text.
     */
    private function normalizeText(
        string $value
    ): string {
        $value = $this->lower($value);

        $value = preg_replace(
            '/[^\p{L}\p{N}\s_-]+/u',
            ' ',
            $value
        ) ?? $value;

        return trim(
            preg_replace(
                '/\s+/u',
                ' ',
                $value
            ) ?? $value
        );
    }

    /**
     * Tokenize normalized text.
     *
     * @return array<int,string>
     */
    private function tokenize(
        string $value
    ): array {
        $value = $this->normalizeText($value);

        $tokens = preg_split(
            '/[\s_-]+/u',
            $value
        ) ?: [];

        $normalized = [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if (
                $token === ''
                || $this->stringLength($token) < 2
                || in_array(
                    $token,
                    $this->stopWords,
                    true
                )
            ) {
                continue;
            }

            $normalized[$token] = $token;
        }

        return array_values($normalized);
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
            return 1.0;
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
     * Calculate Sørensen-Dice similarity.
     *
     * @param array<int,string> $left
     * @param array<int,string> $right
     */
    private function dice(
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
            return 1.0;
        }

        $denominator =
            count($left) + count($right);

        if ($denominator === 0) {
            return 0.0;
        }

        return (
            2 * count(
                array_intersect(
                    $left,
                    $right
                )
            )
        ) / $denominator;
    }

    /**
     * Calculate containment of the smaller set.
     *
     * @param array<int,string> $left
     * @param array<int,string> $right
     */
    private function containmentRatio(
        array $left,
        array $right
    ): float {
        $left = array_values(
            array_unique($left)
        );

        $right = array_values(
            array_unique($right)
        );

        $minimum = min(
            count($left),
            count($right)
        );

        if ($minimum === 0) {
            return 0.0;
        }

        return count(
            array_intersect(
                $left,
                $right
            )
        ) / $minimum;
    }

    /**
     * Calculate normalized character similarity.
     */
    private function characterSimilarity(
        string $left,
        string $right
    ): float {
        if ($left === $right) {
            return 1.0;
        }

        if ($left === '' || $right === '') {
            return 0.0;
        }

        $leftLength = strlen($left);
        $rightLength = strlen($right);

        $maximumLength = max(
            $leftLength,
            $rightLength
        );

        if ($maximumLength === 0) {
            return 1.0;
        }

        if ($maximumLength > 255) {
            similar_text(
                $left,
                $right,
                $percentage
            );

            return $percentage / 100;
        }

        $distance = levenshtein(
            $left,
            $right
        );

        return max(
            0.0,
            1.0 - (
                $distance
                / $maximumLength
            )
        );
    }

    /**
     * Calculate common prefix similarity.
     */
    private function prefixSimilarity(
        string $left,
        string $right
    ): float {
        $leftLength = strlen($left);
        $rightLength = strlen($right);

        $maximum = min(
            $leftLength,
            $rightLength
        );

        if ($maximum === 0) {
            return 0.0;
        }

        $matching = 0;

        for (
            $index = 0;
            $index < $maximum;
            $index++
        ) {
            if (
                $left[$index]
                !== $right[$index]
            ) {
                break;
            }

            $matching++;
        }

        return $matching / $maximum;
    }

    /**
     * Calculate similarity between degrees.
     */
    private function degreeSimilarity(
        int $leftDegree,
        int $rightDegree
    ): float {
        if (
            $leftDegree === 0
            && $rightDegree === 0
        ) {
            return 1.0;
        }

        $maximum = max(
            $leftDegree,
            $rightDegree
        );

        if ($maximum === 0) {
            return 0.0;
        }

        return 1.0 - (
            abs(
                $leftDegree
                - $rightDegree
            ) / $maximum
        );
    }

    /**
     * Extract neighbour node keys.
     *
     * @param array<int,array<string,mixed>> $neighbours
     * @return array<int,string>
     */
    private function extractNeighbourKeys(
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
     * Extract relationship types from neighbour records.
     *
     * @param array<int,array<string,mixed>> $neighbours
     * @return array<int,string>
     */
    private function extractNeighbourRelationshipTypes(
        array $neighbours
    ): array {
        $types = [];

        foreach ($neighbours as $neighbour) {
            if (!is_array($neighbour)) {
                continue;
            }

            $type = $this->normalizeMachineKey(
                (string)(
                    $neighbour[
                        'relationship_type'
                    ]
                    ?? $neighbour[
                        'relationship'
                    ]['relationship_type']
                    ?? ''
                )
            );

            if ($type !== '') {
                $types[$type] = $type;
            }
        }

        return array_values($types);
    }

    /**
     * Resolve first available timestamp.
     *
     * @param array<string,mixed> $record
     * @param array<int,string> $fields
     */
    private function firstTimestamp(
        array $record,
        array $fields
    ): ?int {
        foreach ($fields as $field) {
            $value = trim(
                (string)(
                    $record[$field]
                    ?? ''
                )
            );

            if ($value === '') {
                continue;
            }

            $timestamp = strtotime($value);

            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return null;
    }

    /**
     * Normalize all configured weights.
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
     * Canonicalize a comparison value.
     */
    private function canonicalValue(
        string $value
    ): string {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace(
            '/#.*$/',
            '',
            $value
        ) ?? $value;

        return rtrim(
            $this->lower($value),
            '/'
        );
    }

    /**
     * Normalize scalar comparison value.
     */
    private function normalizedScalar(
        mixed $value
    ): string {
        if (is_bool($value)) {
            return $value
                ? 'true'
                : 'false';
        }

        if (is_array($value)) {
            $json = json_encode(
                $this->normalizeForHash($value),
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
            );

            return $json !== false
                ? $json
                : '';
        }

        return $this->lower(
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
        return function_exists('mb_strlen')
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
     * Generate comparison identifier.
     */
    private function generateComparisonId(): string
    {
        return 'SIM-'
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