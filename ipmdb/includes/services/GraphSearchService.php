<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/GraphSearchService.php
|--------------------------------------------------------------------------
| IPMdb Graph Search Service
|--------------------------------------------------------------------------
|
| Searches entities and relationships across the IPMdb knowledge graph.
|
| Responsibilities:
| - Search entity fields.
| - Search relationship fields.
| - Filter by entity type, relationship type, status, and provenance.
| - Rank exact, prefix, phrase, token, and fuzzy matches.
| - Search within a bounded graph neighbourhood.
| - Return explainable match scores.
| - Build suggestions and facets.
|
| This service performs no database operations.
| Repository classes supply records.
|
| Search discovers.
| Relationships provide context.
| Provenance establishes trust.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/GraphTraversalService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class GraphSearchService extends Service
{
    use GraphUtilities;

    private GraphTraversalService $traversal;

    /**
     * @var array<int,string>
     */
    private array $defaultEntitySearchFields = [
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
        'source_reference',
        'entity_id',
        'asset_id',
        'translation_id',
        'document_id',
        'url',
    ];

    /**
     * @var array<int,string>
     */
    private array $defaultRelationshipSearchFields = [
        'relationship_id',
        'relationship_type',
        'label',
        'description',
        'source_id',
        'source_type',
        'target_id',
        'target_type',
        'created_by',
        'provenance_id',
        'version_id',
        'tags',
        'metadata',
    ];

    /**
     * @var array<string,float>
     */
    private array $fieldWeights = [
        'title' => 8.0,
        'name' => 8.0,
        'label' => 7.0,
        'entity_id' => 7.0,
        'asset_id' => 7.0,
        'translation_id' => 7.0,
        'document_id' => 7.0,
        'relationship_id' => 7.0,
        'relationship_type' => 6.0,
        'idea' => 6.0,
        'keywords' => 5.0,
        'tags' => 5.0,
        'summary' => 4.0,
        'description' => 3.0,
        'content' => 2.0,
        'notes' => 2.0,
        'metadata' => 1.0,
        'source_reference' => 1.5,
        'url' => 1.5,
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?GraphTraversalService $traversal = null
    ) {
        parent::__construct($config, $context);

        $this->traversal = $traversal
            ?? new GraphTraversalService();
    }

    /**
     * Search entities and relationships together.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     * @return array<string,mixed>
     */
    public function search(
        string $query,
        array $entities = [],
        array $relationships = [],
        array $options = []
    ): array {
        $this->reset();

        $query = $this->normalizeQuery($query);

        if ($query === '') {
            return $this->emptySearchResult(
                $query,
                $options
            );
        }

        $limit = max(
            1,
            min(
                1000,
                (int)($options['limit'] ?? 50)
            )
        );

        $minimumScore = max(
            0.0,
            (float)($options['minimum_score'] ?? 1.0)
        );

        $entityResults = $this->searchEntities(
            $query,
            $entities,
            array_merge(
                $options,
                [
                    'limit' => $limit,
                    'minimum_score' => $minimumScore,
                ]
            )
        );

        $relationshipResults =
            $this->searchRelationships(
                $query,
                $relationships,
                array_merge(
                    $options,
                    [
                        'limit' => $limit,
                        'minimum_score' => $minimumScore,
                    ]
                )
            );

        $combined = [];

        foreach ($entityResults as $result) {
            $result['result_kind'] = 'entity';
            $combined[] = $result;
        }

        foreach ($relationshipResults as $result) {
            $result['result_kind'] = 'relationship';
            $combined[] = $result;
        }

        usort(
            $combined,
            fn (array $left, array $right): int =>
                $this->compareResults($left, $right)
        );

        $combined = array_slice(
            $combined,
            0,
            $limit
        );

        $facets = $this->buildFacets($combined);

        $result = [
            'query' => $query,
            'generated_at' => gmdate('c'),
            'result_count' => count($combined),
            'entity_result_count' =>
                count($entityResults),
            'relationship_result_count' =>
                count($relationshipResults),
            'limit' => $limit,
            'minimum_score' => $minimumScore,
            'facets' => $facets,
            'results' => $combined,
        ];

        $this->addMessage(
            'Graph search completed.',
            [
                'query' => $query,
                'result_count' => count($combined),
            ]
        );

        return $result;
    }

    /**
     * Search entity records.
     *
     * @param array<int,array<string,mixed>> $entities
     * @return array<int,array<string,mixed>>
     */
    public function searchEntities(
        string $query,
        array $entities,
        array $options = []
    ): array {
        $query = $this->normalizeQuery($query);

        if ($query === '') {
            return [];
        }

        $fields = $this->normalizeStringList(
            $options['fields']
            ?? $this->defaultEntitySearchFields
        );

        $entityTypes = $this->normalizeStringList(
            $options['entity_types']
            ?? []
        );

        $statuses = $this->normalizeStringList(
            $options['statuses']
            ?? []
        );

        $languages = $this->normalizeStringList(
            $options['languages']
            ?? []
        );

        $visibility = $this->normalizeStringList(
            $options['visibility']
            ?? []
        );

        $minimumScore = max(
            0.0,
            (float)($options['minimum_score'] ?? 1.0)
        );

        $limit = max(
            1,
            min(
                1000,
                (int)($options['limit'] ?? 50)
            )
        );

        $results = [];

        foreach ($entities as $index => $entity) {
            if (!is_array($entity)) {
                continue;
            }

            if (
                !$this->entityPassesFilters(
                    $entity,
                    $entityTypes,
                    $statuses,
                    $languages,
                    $visibility
                )
            ) {
                continue;
            }

            $score = $this->scoreRecord(
                $query,
                $entity,
                $fields
            );

            if ($score['score'] < $minimumScore) {
                continue;
            }

            $result = [
                'result_kind' => 'entity',
                'record_index' => $index,
                'entity_id' =>
                    $this->resolveEntityId($entity),
                'entity_type' =>
                    $this->resolveEntityType($entity),
                'title' =>
                    $this->resolveEntityTitle($entity),
                'score' => $score['score'],
                'match_count' =>
                    count($score['matches']),
                'matches' => $score['matches'],
                'matched_fields' =>
                    $score['matched_fields'],
                'record' => $entity,
            ];

            $results[] = $result;
        }

        usort(
            $results,
            fn (array $left, array $right): int =>
                $this->compareResults($left, $right)
        );

        return array_slice(
            $results,
            0,
            $limit
        );
    }

    /**
     * Search relationship records.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<int,array<string,mixed>>
     */
    public function searchRelationships(
        string $query,
        array $relationships,
        array $options = []
    ): array {
        $query = $this->normalizeQuery($query);

        if ($query === '') {
            return [];
        }

        $fields = $this->normalizeStringList(
            $options['fields']
            ?? $this->defaultRelationshipSearchFields
        );

        $relationshipTypes =
            $this->normalizeStringList(
                $options['relationship_types']
                ?? []
            );

        $statuses = $this->normalizeStringList(
            $options['statuses']
            ?? []
        );

        $sourceTypes = $this->normalizeStringList(
            $options['source_types']
            ?? []
        );

        $targetTypes = $this->normalizeStringList(
            $options['target_types']
            ?? []
        );

        $minimumScore = max(
            0.0,
            (float)($options['minimum_score'] ?? 1.0)
        );

        $limit = max(
            1,
            min(
                1000,
                (int)($options['limit'] ?? 50)
            )
        );

        $includeExpired = (bool)(
            $options['include_expired']
            ?? false
        );

        $results = [];

        foreach (
            $relationships
            as $index => $relationship
        ) {
            if (!is_array($relationship)) {
                continue;
            }

            if (
                !$this->relationshipPassesFilters(
                    $relationship,
                    $relationshipTypes,
                    $statuses,
                    $sourceTypes,
                    $targetTypes,
                    $includeExpired
                )
            ) {
                continue;
            }

            $score = $this->scoreRecord(
                $query,
                $relationship,
                $fields
            );

            if ($score['score'] < $minimumScore) {
                continue;
            }

            $results[] = [
                'result_kind' => 'relationship',
                'record_index' => $index,
                'relationship_id' => trim(
                    (string)(
                        $relationship[
                            'relationship_id'
                        ]
                        ?? ''
                    )
                ),
                'relationship_type' => trim(
                    (string)(
                        $relationship[
                            'relationship_type'
                        ]
                        ?? ''
                    )
                ),
                'source_id' => trim(
                    (string)(
                        $relationship['source_id']
                        ?? ''
                    )
                ),
                'source_type' => trim(
                    (string)(
                        $relationship['source_type']
                        ?? ''
                    )
                ),
                'target_id' => trim(
                    (string)(
                        $relationship['target_id']
                        ?? ''
                    )
                ),
                'target_type' => trim(
                    (string)(
                        $relationship['target_type']
                        ?? ''
                    )
                ),
                'score' => $score['score'],
                'match_count' =>
                    count($score['matches']),
                'matches' => $score['matches'],
                'matched_fields' =>
                    $score['matched_fields'],
                'record' => $relationship,
            ];
        }

        usort(
            $results,
            fn (array $left, array $right): int =>
                $this->compareResults($left, $right)
        );

        return array_slice(
            $results,
            0,
            $limit
        );
    }

    /**
     * Search within a bounded graph neighbourhood.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     * @return array<string,mixed>
     */
    public function searchNeighbourhood(
        string $query,
        string $focusEntityId,
        ?string $focusEntityType,
        array $entities,
        array $relationships,
        int $depth = 2,
        array $options = []
    ): array {
        $focusEntityId = trim($focusEntityId);

        if ($focusEntityId === '') {
            throw new InvalidArgumentException(
                'Neighbourhood search requires a focus entity ID.'
            );
        }

        $depth = max(
            0,
            min(20, $depth)
        );

        $traversal = $this->traversal->traverse(
            $relationships,
            $focusEntityId,
            $focusEntityType,
            $depth,
            (string)($options['direction'] ?? 'both'),
            $this->normalizeStringList(
                $options['relationship_types']
                ?? []
            ),
            $this->normalizeStringList(
                $options['relationship_statuses']
                ?? ['active', 'verified']
            ),
            (bool)(
                $options['include_expired']
                ?? false
            ),
            (int)(
                $options['maximum_nodes']
                ?? 5000
            )
        );

        $allowedNodeKeys = [];

        foreach ($traversal['nodes'] as $node) {
            $nodeKey = trim(
                (string)(
                    $node['node_key']
                    ?? ''
                )
            );

            if ($nodeKey !== '') {
                $allowedNodeKeys[$nodeKey] = true;
            }
        }

        $localEntities = [];

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $entityKey = $this->graphNodeKey(
                $this->resolveEntityType($entity),
                $this->resolveEntityId($entity)
            );

            if (
                $entityKey !== ''
                && isset($allowedNodeKeys[$entityKey])
            ) {
                $localEntities[] = $entity;
            }
        }

        $allowedRelationshipIds = [];

        foreach ($traversal['edges'] as $edge) {
            $relationshipId = trim(
                (string)(
                    $edge['relationship_id']
                    ?? ''
                )
            );

            if ($relationshipId !== '') {
                $allowedRelationshipIds[
                    $relationshipId
                ] = true;
            }
        }

        $localRelationships = [];

        foreach ($relationships as $relationship) {
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

            if (
                $relationshipId !== ''
                && isset(
                    $allowedRelationshipIds[
                        $relationshipId
                    ]
                )
            ) {
                $localRelationships[] =
                    $relationship;
            }
        }

        $search = $this->search(
            $query,
            $localEntities,
            $localRelationships,
            $options
        );

        $search['focus'] = [
            'entity_id' => $focusEntityId,
            'entity_type' => $focusEntityType,
            'depth' => $depth,
        ];

        $search['neighbourhood'] = [
            'node_count' =>
                $traversal['visited_count'],
            'relationship_count' =>
                $traversal['edge_count'],
            'truncated' =>
                $traversal['truncated'],
        ];

        return $search;
    }

    /**
     * Search entities connected to one matching relationship type.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     * @return array<int,array<string,mixed>>
     */
    public function connectedEntitySearch(
        string $query,
        string $focusEntityId,
        ?string $focusEntityType,
        array $entities,
        array $relationships,
        array $relationshipTypes = [],
        int $depth = 1,
        array $options = []
    ): array {
        $result = $this->searchNeighbourhood(
            $query,
            $focusEntityId,
            $focusEntityType,
            $entities,
            $relationships,
            $depth,
            array_merge(
                $options,
                [
                    'relationship_types' =>
                        $relationshipTypes,
                ]
            )
        );

        return array_values(
            array_filter(
                $result['results'],
                static fn (array $item): bool =>
                    ($item['result_kind'] ?? '')
                    === 'entity'
            )
        );
    }

    /**
     * Return compact search suggestions.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     * @return array<int,array<string,mixed>>
     */
    public function suggest(
        string $query,
        array $entities = [],
        array $relationships = [],
        int $limit = 10
    ): array {
        $query = $this->normalizeQuery($query);

        if ($query === '') {
            return [];
        }

        $limit = max(
            1,
            min(100, $limit)
        );

        $search = $this->search(
            $query,
            $entities,
            $relationships,
            [
                'limit' => $limit * 3,
                'minimum_score' => 2.0,
            ]
        );

        $suggestions = [];
        $seen = [];

        foreach ($search['results'] as $result) {
            $label = '';

            if (
                ($result['result_kind'] ?? '')
                === 'entity'
            ) {
                $label = trim(
                    (string)(
                        $result['title']
                        ?? $result['entity_id']
                        ?? ''
                    )
                );
            } else {
                $label = trim(
                    (string)(
                        $result[
                            'relationship_type'
                        ]
                        ?? ''
                    )
                );
            }

            if ($label === '') {
                continue;
            }

            $key = $this->lower($label);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $suggestions[] = [
                'label' => $label,
                'kind' =>
                    $result['result_kind']
                    ?? 'unknown',
                'score' =>
                    $result['score']
                    ?? 0,
                'entity_id' =>
                    $result['entity_id']
                    ?? null,
                'relationship_id' =>
                    $result['relationship_id']
                    ?? null,
            ];

            if (count($suggestions) >= $limit) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * Search one exact identifier.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     * @return array<string,mixed>|null
     */
    public function findIdentifier(
        string $identifier,
        array $entities = [],
        array $relationships = []
    ): ?array {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

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
                if (
                    trim(
                        (string)(
                            $entity[$field]
                            ?? ''
                        )
                    ) === $identifier
                ) {
                    return [
                        'result_kind' => 'entity',
                        'entity_id' =>
                            $this->resolveEntityId(
                                $entity
                            ),
                        'entity_type' =>
                            $this->resolveEntityType(
                                $entity
                            ),
                        'record' => $entity,
                    ];
                }
            }
        }

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            if (
                trim(
                    (string)(
                        $relationship[
                            'relationship_id'
                        ]
                        ?? ''
                    )
                ) === $identifier
            ) {
                return [
                    'result_kind' =>
                        'relationship',
                    'relationship_id' =>
                        $identifier,
                    'record' => $relationship,
                ];
            }
        }

        return null;
    }

    /**
     * Explain a scored result.
     *
     * @param array<string,mixed> $result
     */
    public function explainResult(
        array $result
    ): string {
        $matches = is_array(
            $result['matches']
            ?? null
        )
            ? $result['matches']
            : [];

        if ($matches === []) {
            return 'No match explanation is available.';
        }

        $parts = [];

        foreach ($matches as $match) {
            $field = trim(
                (string)(
                    $match['field']
                    ?? 'field'
                )
            );

            $matchType = trim(
                (string)(
                    $match['match_type']
                    ?? 'match'
                )
            );

            $parts[] = sprintf(
                '%s matched by %s',
                str_replace('_', ' ', $field),
                str_replace('_', ' ', $matchType)
            );
        }

        return implode('; ', $parts)
            . sprintf(
                '. Total score: %.4f.',
                (float)(
                    $result['score']
                    ?? 0
                )
            );
    }

    /**
     * Return diagnostics.
     *
     * @return array<string,mixed>
     */
    public function diagnostics(): array
    {
        return array_merge(
            parent::diagnostics(),
            [
                'entity_search_fields' =>
                    $this->defaultEntitySearchFields,

                'relationship_search_fields' =>
                    $this->defaultRelationshipSearchFields,

                'field_weights' =>
                    $this->fieldWeights,

                'supports_neighbourhood_search' =>
                    true,

                'supports_facets' =>
                    true,

                'supports_fuzzy_matching' =>
                    true,

                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Score a record against one query.
     *
     * @param array<string,mixed> $record
     * @param array<int,string> $fields
     * @return array<string,mixed>
     */
    private function scoreRecord(
        string $query,
        array $record,
        array $fields
    ): array {
        $queryLower = $this->lower($query);
        $queryTokens = $this->tokenize($query);

        $score = 0.0;
        $matches = [];
        $matchedFields = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $record)) {
                continue;
            }

            $values = $this->flattenSearchValue(
                $record[$field]
            );

            if ($values === []) {
                continue;
            }

            $fieldWeight =
                $this->fieldWeights[$field]
                ?? 1.0;

            foreach ($values as $value) {
                $value = trim($value);

                if ($value === '') {
                    continue;
                }

                $match = $this->scoreText(
                    $queryLower,
                    $queryTokens,
                    $value,
                    $fieldWeight
                );

                if ($match['score'] <= 0) {
                    continue;
                }

                $score += $match['score'];

                $matches[] = [
                    'field' => $field,
                    'value' =>
                        $this->truncate(
                            $value,
                            240
                        ),
                    'match_type' =>
                        $match['match_type'],
                    'score' =>
                        $match['score'],
                    'token_hits' =>
                        $match['token_hits'],
                ];

                $matchedFields[$field] = true;
            }
        }

        return [
            'score' => round($score, 6),
            'matches' => $matches,
            'matched_fields' =>
                array_keys($matchedFields),
        ];
    }

    /**
     * Score one string.
     *
     * @param array<int,string> $queryTokens
     * @return array<string,mixed>
     */
    private function scoreText(
        string $queryLower,
        array $queryTokens,
        string $value,
        float $fieldWeight
    ): array {
        $valueLower = $this->lower($value);
        $valueTrimmed = trim($valueLower);

        $score = 0.0;
        $matchType = '';
        $tokenHits = 0;

        if ($valueTrimmed === $queryLower) {
            return [
                'score' => 20.0 * $fieldWeight,
                'match_type' => 'exact',
                'token_hits' =>
                    count($queryTokens),
            ];
        }

        if (
            str_starts_with(
                $valueTrimmed,
                $queryLower
            )
        ) {
            $score += 12.0 * $fieldWeight;
            $matchType = 'prefix';
        } elseif (
            str_contains(
                $valueLower,
                $queryLower
            )
        ) {
            $score += 8.0 * $fieldWeight;
            $matchType = 'phrase';
        }

        $valueTokens = $this->tokenize($value);

        if ($queryTokens !== []) {
            foreach ($queryTokens as $token) {
                if (
                    in_array(
                        $token,
                        $valueTokens,
                        true
                    )
                ) {
                    $tokenHits++;
                    $score += 2.5 * $fieldWeight;
                    continue;
                }

                foreach ($valueTokens as $valueToken) {
                    if (
                        strlen($token) >= 4
                        && $this->similarity(
                            $token,
                            $valueToken
                        ) >= 0.84
                    ) {
                        $tokenHits++;
                        $score += 1.0 * $fieldWeight;

                        if ($matchType === '') {
                            $matchType = 'fuzzy';
                        }

                        break;
                    }
                }
            }

            if (
                $tokenHits === count($queryTokens)
                && $tokenHits > 0
            ) {
                $score += 3.0 * $fieldWeight;

                if ($matchType === '') {
                    $matchType = 'all_tokens';
                }
            } elseif (
                $tokenHits > 0
                && $matchType === ''
            ) {
                $matchType = 'token';
            }
        }

        return [
            'score' => $score,
            'match_type' =>
                $matchType !== ''
                    ? $matchType
                    : 'none',
            'token_hits' => $tokenHits,
        ];
    }

    /**
     * Compare ranked search results.
     */
    private function compareResults(
        array $left,
        array $right
    ): int {
        $scoreComparison =
            (float)($right['score'] ?? 0)
            <=>
            (float)($left['score'] ?? 0);

        if ($scoreComparison !== 0) {
            return $scoreComparison;
        }

        $matchComparison =
            (int)($right['match_count'] ?? 0)
            <=>
            (int)($left['match_count'] ?? 0);

        if ($matchComparison !== 0) {
            return $matchComparison;
        }

        return strcmp(
            (string)(
                $left['title']
                ?? $left['entity_id']
                ?? $left['relationship_id']
                ?? ''
            ),
            (string)(
                $right['title']
                ?? $right['entity_id']
                ?? $right['relationship_id']
                ?? ''
            )
        );
    }

    /**
     * Determine whether an entity passes filters.
     *
     * @param array<string,mixed> $entity
     * @param array<int,string> $entityTypes
     * @param array<int,string> $statuses
     * @param array<int,string> $languages
     * @param array<int,string> $visibility
     */
    private function entityPassesFilters(
        array $entity,
        array $entityTypes,
        array $statuses,
        array $languages,
        array $visibility
    ): bool {
        $entityType = $this->resolveEntityType(
            $entity
        );

        if (
            $entityTypes !== []
            && !in_array(
                $entityType,
                $entityTypes,
                true
            )
        ) {
            return false;
        }

        $status = trim(
            (string)(
                $entity['status']
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
            return false;
        }

        $language = trim(
            (string)(
                $entity['language']
                ?? $entity['target_language']
                ?? ''
            )
        );

        if (
            $languages !== []
            && !in_array(
                $language,
                $languages,
                true
            )
        ) {
            return false;
        }

        $entityVisibility = trim(
            (string)(
                $entity['visibility']
                ?? ''
            )
        );

        if (
            $visibility !== []
            && !in_array(
                $entityVisibility,
                $visibility,
                true
            )
        ) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether a relationship passes filters.
     *
     * @param array<string,mixed> $relationship
     * @param array<int,string> $relationshipTypes
     * @param array<int,string> $statuses
     * @param array<int,string> $sourceTypes
     * @param array<int,string> $targetTypes
     */
    private function relationshipPassesFilters(
        array $relationship,
        array $relationshipTypes,
        array $statuses,
        array $sourceTypes,
        array $targetTypes,
        bool $includeExpired
    ): bool {
        $type = trim(
            (string)(
                $relationship[
                    'relationship_type'
                ]
                ?? ''
            )
        );

        if (
            $relationshipTypes !== []
            && !in_array(
                $type,
                $relationshipTypes,
                true
            )
        ) {
            return false;
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
            return false;
        }

        $sourceType = trim(
            (string)(
                $relationship['source_type']
                ?? ''
            )
        );

        if (
            $sourceTypes !== []
            && !in_array(
                $sourceType,
                $sourceTypes,
                true
            )
        ) {
            return false;
        }

        $targetType = trim(
            (string)(
                $relationship['target_type']
                ?? ''
            )
        );

        if (
            $targetTypes !== []
            && !in_array(
                $targetType,
                $targetTypes,
                true
            )
        ) {
            return false;
        }

        if (
            !$includeExpired
            && !$this->isTemporallyActive(
                $relationship
            )
        ) {
            return false;
        }

        return true;
    }

    /**
     * Build search facets.
     *
     * @param array<int,array<string,mixed>> $results
     * @return array<string,mixed>
     */
    private function buildFacets(
        array $results
    ): array {
        $kinds = [];
        $entityTypes = [];
        $relationshipTypes = [];
        $statuses = [];

        foreach ($results as $result) {
            $kind = trim(
                (string)(
                    $result['result_kind']
                    ?? 'unknown'
                )
            );

            $kinds[$kind] =
                ($kinds[$kind] ?? 0) + 1;

            if ($kind === 'entity') {
                $entityType = trim(
                    (string)(
                        $result['entity_type']
                        ?? 'unknown'
                    )
                );

                $entityTypes[$entityType] =
                    ($entityTypes[$entityType] ?? 0)
                    + 1;

                $status = trim(
                    (string)(
                        $result['record']['status']
                        ?? ''
                    )
                );

                if ($status !== '') {
                    $statuses[$status] =
                        ($statuses[$status] ?? 0)
                        + 1;
                }
            }

            if ($kind === 'relationship') {
                $relationshipType = trim(
                    (string)(
                        $result[
                            'relationship_type'
                        ]
                        ?? 'unknown'
                    )
                );

                $relationshipTypes[
                    $relationshipType
                ] = (
                    $relationshipTypes[
                        $relationshipType
                    ] ?? 0
                ) + 1;

                $status = trim(
                    (string)(
                        $result['record']['status']
                        ?? ''
                    )
                );

                if ($status !== '') {
                    $statuses[$status] =
                        ($statuses[$status] ?? 0)
                        + 1;
                }
            }
        }

        arsort($kinds);
        arsort($entityTypes);
        arsort($relationshipTypes);
        arsort($statuses);

        return [
            'kinds' => $kinds,
            'entity_types' => $entityTypes,
            'relationship_types' =>
                $relationshipTypes,
            'statuses' => $statuses,
        ];
    }

    /**
     * Resolve an entity identifier.
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
     * Resolve an entity type.
     *
     * @param array<string,mixed> $entity
     */
    private function resolveEntityType(
        array $entity
    ): string {
        $type = strtolower(
            trim(
                (string)(
                    $entity['entity_type']
                    ?? $entity['type']
                    ?? 'entity'
                )
            )
        );

        return preg_replace(
            '/[^a-z0-9_]+/',
            '_',
            $type
        ) ?? 'entity';
    }

    /**
     * Resolve a display title.
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
     * Flatten searchable values.
     *
     * @return array<int,string>
     */
    private function flattenSearchValue(
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
                return $this->flattenSearchValue(
                    $value->jsonSerialize()
                );
            }

            if (method_exists($value, 'toArray')) {
                return $this->flattenSearchValue(
                    $value->toArray()
                );
            }

            return $this->flattenSearchValue(
                get_object_vars($value)
            );
        }

        if (!is_array($value)) {
            return [];
        }

        $flattened = [];

        foreach ($value as $item) {
            foreach (
                $this->flattenSearchValue($item)
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
     * Normalize query text.
     */
    private function normalizeQuery(
        string $query
    ): string {
        $query = trim($query);

        return preg_replace(
            '/\s+/u',
            ' ',
            $query
        ) ?? $query;
    }

    /**
     * Tokenize text.
     *
     * @return array<int,string>
     */
    private function tokenize(
        string $value
    ): array {
        $value = $this->lower($value);

        $tokens = preg_split(
            '/[^\p{L}\p{N}_-]+/u',
            $value
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
     * Calculate normalized similarity.
     */
    private function similarity(
        string $left,
        string $right
    ): float {
        if ($left === $right) {
            return 1.0;
        }

        if ($left === '' || $right === '') {
            return 0.0;
        }

        $maximumLength = max(
            strlen($left),
            strlen($right)
        );

        if ($maximumLength === 0) {
            return 1.0;
        }

        $distance = levenshtein(
            $left,
            $right
        );

        return max(
            0.0,
            1.0 - ($distance / $maximumLength)
        );
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
     * Lowercase using multibyte support when available.
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
     * Truncate display text.
     */
    private function truncate(
        string $value,
        int $length
    ): string {
        $length = max(1, $length);

        $currentLength =
            function_exists('mb_strlen')
                ? mb_strlen($value, 'UTF-8')
                : strlen($value);

        if ($currentLength <= $length) {
            return $value;
        }

        $trimmed =
            function_exists('mb_substr')
                ? mb_substr(
                    $value,
                    0,
                    $length - 1,
                    'UTF-8'
                )
                : substr(
                    $value,
                    0,
                    $length - 1
                );

        return rtrim($trimmed) . '…';
    }

    /**
     * Return an empty search response.
     *
     * @return array<string,mixed>
     */
    private function emptySearchResult(
        string $query,
        array $options
    ): array {
        return [
            'query' => $query,
            'generated_at' => gmdate('c'),
            'result_count' => 0,
            'entity_result_count' => 0,
            'relationship_result_count' => 0,
            'limit' => max(
                1,
                (int)($options['limit'] ?? 50)
            ),
            'minimum_score' => max(
                0.0,
                (float)(
                    $options['minimum_score']
                    ?? 1.0
                )
            ),
            'facets' => [
                'kinds' => [],
                'entity_types' => [],
                'relationship_types' => [],
                'statuses' => [],
            ],
            'results' => [],
        ];
    }
}