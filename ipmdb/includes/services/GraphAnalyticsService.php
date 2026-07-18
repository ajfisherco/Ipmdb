<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/GraphAnalyticsService.php
|--------------------------------------------------------------------------
| IPMdb Graph Analytics Service
|--------------------------------------------------------------------------
|
| Calculates structural and operational measurements for an IPMdb graph.
|
| Responsibilities:
| - Count nodes and relationships.
| - Calculate node degree.
| - Rank connected entities.
| - Identify hubs, leaves, isolates, and bridges.
| - Measure graph density.
| - Summarize relationship types and statuses.
| - Analyze confidence, weight, and strength.
| - Report connected components and cycles.
|
| This service performs no database operations.
|
| Measurement describes the graph.
| Interpretation remains attributable.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/GraphTraversalService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class GraphAnalyticsService extends Service
{
    use GraphUtilities;

    private GraphTraversalService $traversal;

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
     * Produce a complete graph analytics report.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @param array<int,array<string,mixed>> $entities
     *
     * @return array<string,mixed>
     */
    public function analyze(
        array $relationships,
        array $entities = [],
        array $options = []
    ): array {
        $this->reset();

        $statuses = $this->normalizeStringList(
            $options['statuses']
            ?? ['active', 'verified']
        );

        $relationshipTypes = $this->normalizeStringList(
            $options['relationship_types']
            ?? []
        );

        $includeExpired = (bool)(
            $options['include_expired']
            ?? false
        );

        $filteredRelationships = $this->filterRelationships(
            $relationships,
            $relationshipTypes,
            $statuses,
            $includeExpired
        );

        $nodeIndex = $this->buildNodeIndex(
            $filteredRelationships,
            $entities
        );

        $degrees = $this->degreeMetrics(
            $filteredRelationships,
            $nodeIndex
        );

        $components = $this->traversal
            ->connectedComponents(
                $filteredRelationships,
                [],
                true
            );

        $cycles = $this->traversal->cycles(
            $filteredRelationships,
            [],
            [],
            true,
            (int)(
                $options['maximum_cycles']
                ?? 100
            )
        );

        $relationshipSummary =
            $this->relationshipSummary(
                $filteredRelationships
            );

        $nodeSummary = $this->nodeSummary(
            $nodeIndex,
            $degrees
        );

        $density = $this->density(
            count($nodeIndex),
            count($filteredRelationships),
            $this->isDirectedGraph(
                $filteredRelationships
            )
        );

        $bridges = $this->bridgeEdges(
            $filteredRelationships,
            (int)(
                $options['maximum_bridge_checks']
                ?? 5000
            )
        );

        $result = [
            'generated_at' => gmdate('c'),

            'filters' => [
                'statuses' => $statuses,
                'relationship_types' =>
                    $relationshipTypes,
                'include_expired' =>
                    $includeExpired,
            ],

            'totals' => [
                'nodes' => count($nodeIndex),
                'relationships' =>
                    count($filteredRelationships),
                'components' =>
                    count($components),
                'cycles' => count($cycles),
                'bridges' => count($bridges),
            ],

            'structure' => [
                'directed' =>
                    $this->isDirectedGraph(
                        $filteredRelationships
                    ),

                'density' => $density,

                'connected' =>
                    count($components) <= 1
                    && count($nodeIndex) > 0,

                'largest_component_size' =>
                    $components !== []
                        ? (int)(
                            $components[0]['node_count']
                            ?? 0
                        )
                        : 0,

                'isolated_component_count' =>
                    count(
                        array_filter(
                            $components,
                            static fn (
                                array $component
                            ): bool =>
                                (int)(
                                    $component['node_count']
                                    ?? 0
                                ) === 1
                        )
                    ),
            ],

            'nodes' => $nodeSummary,

            'relationships' =>
                $relationshipSummary,

            'degree' => $degrees,

            'components' => $components,

            'cycles' => $cycles,

            'bridges' => $bridges,

            'quality' => $this->qualitySummary(
                $filteredRelationships
            ),
        ];

        $this->addMessage(
            'Graph analytics completed.',
            [
                'node_count' =>
                    count($nodeIndex),
                'relationship_count' =>
                    count($filteredRelationships),
                'component_count' =>
                    count($components),
            ]
        );

        return $result;
    }

    /**
     * Calculate incoming, outgoing, and total degree.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @param array<string,array<string,mixed>> $nodeIndex
     *
     * @return array<string,mixed>
     */
    public function degreeMetrics(
        array $relationships,
        array $nodeIndex = []
    ): array {
        if ($nodeIndex === []) {
            $nodeIndex = $this->buildNodeIndex(
                $relationships
            );
        }

        $incoming = [];
        $outgoing = [];
        $total = [];

        foreach (array_keys($nodeIndex) as $nodeKey) {
            $incoming[$nodeKey] = 0;
            $outgoing[$nodeKey] = 0;
            $total[$nodeKey] = 0;
        }

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $sourceKey = $this->relationshipSourceKey(
                $relationship
            );

            $targetKey = $this->relationshipTargetKey(
                $relationship
            );

            if (
                $sourceKey === ''
                || $targetKey === ''
            ) {
                continue;
            }

            $outgoing[$sourceKey] =
                ($outgoing[$sourceKey] ?? 0) + 1;

            $incoming[$targetKey] =
                ($incoming[$targetKey] ?? 0) + 1;

            $total[$sourceKey] =
                ($total[$sourceKey] ?? 0) + 1;

            $total[$targetKey] =
                ($total[$targetKey] ?? 0) + 1;

            if (
                ($relationship['directional'] ?? true)
                === false
            ) {
                $outgoing[$targetKey] =
                    ($outgoing[$targetKey] ?? 0)
                    + 1;

                $incoming[$sourceKey] =
                    ($incoming[$sourceKey] ?? 0)
                    + 1;
            }
        }

        arsort($incoming);
        arsort($outgoing);
        arsort($total);

        $nodeCount = count($nodeIndex);

        return [
            'incoming' => $incoming,

            'outgoing' => $outgoing,

            'total' => $total,

            'maximum_incoming' =>
                $incoming !== []
                    ? max($incoming)
                    : 0,

            'maximum_outgoing' =>
                $outgoing !== []
                    ? max($outgoing)
                    : 0,

            'maximum_total' =>
                $total !== []
                    ? max($total)
                    : 0,

            'average_incoming' =>
                $nodeCount > 0
                    ? round(
                        array_sum($incoming)
                        / $nodeCount,
                        4
                    )
                    : 0.0,

            'average_outgoing' =>
                $nodeCount > 0
                    ? round(
                        array_sum($outgoing)
                        / $nodeCount,
                        4
                    )
                    : 0.0,

            'average_total' =>
                $nodeCount > 0
                    ? round(
                        array_sum($total)
                        / $nodeCount,
                        4
                    )
                    : 0.0,
        ];
    }

    /**
     * Return the highest-degree graph nodes.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<int,array<string,mixed>>
     */
    public function hubs(
        array $relationships,
        int $limit = 20,
        int $minimumDegree = 2
    ): array {
        $limit = max(
            1,
            min(1000, $limit)
        );

        $minimumDegree = max(
            0,
            $minimumDegree
        );

        $nodeIndex = $this->buildNodeIndex(
            $relationships
        );

        $degree = $this->degreeMetrics(
            $relationships,
            $nodeIndex
        );

        $results = [];

        foreach ($degree['total'] as $nodeKey => $count) {
            if ((int)$count < $minimumDegree) {
                continue;
            }

            $results[] = array_merge(
                $nodeIndex[$nodeKey]
                    ?? [
                        'node_key' => $nodeKey,
                    ],
                [
                    'total_degree' =>
                        (int)$count,

                    'incoming_degree' =>
                        (int)(
                            $degree['incoming'][$nodeKey]
                            ?? 0
                        ),

                    'outgoing_degree' =>
                        (int)(
                            $degree['outgoing'][$nodeKey]
                            ?? 0
                        ),
                ]
            );

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Return nodes with exactly one connection.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<int,array<string,mixed>>
     */
    public function leaves(
        array $relationships
    ): array {
        $nodeIndex = $this->buildNodeIndex(
            $relationships
        );

        $degree = $this->degreeMetrics(
            $relationships,
            $nodeIndex
        );

        $leaves = [];

        foreach ($degree['total'] as $nodeKey => $count) {
            if ((int)$count !== 1) {
                continue;
            }

            $leaves[] = array_merge(
                $nodeIndex[$nodeKey]
                    ?? [
                        'node_key' => $nodeKey,
                    ],
                [
                    'total_degree' => 1,
                ]
            );
        }

        return $leaves;
    }

    /**
     * Return entities with no relationship edges.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<int,array<string,mixed>>
     */
    public function isolates(
        array $entities,
        array $relationships
    ): array {
        $connected = [];

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $sourceKey = $this->relationshipSourceKey(
                $relationship
            );

            $targetKey = $this->relationshipTargetKey(
                $relationship
            );

            if ($sourceKey !== '') {
                $connected[$sourceKey] = true;
            }

            if ($targetKey !== '') {
                $connected[$targetKey] = true;
            }
        }

        $isolates = [];

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $node = $this->normalizeEntityRecord(
                $entity
            );

            $nodeKey = (string)(
                $node['node_key']
                ?? ''
            );

            if (
                $nodeKey !== ''
                && !isset($connected[$nodeKey])
            ) {
                $isolates[] = $node;
            }
        }

        return $isolates;
    }

    /**
     * Calculate graph density.
     */
    public function density(
        int $nodeCount,
        int $edgeCount,
        bool $directed = true
    ): float {
        if ($nodeCount < 2) {
            return 0.0;
        }

        $maximumEdges = $directed
            ? $nodeCount * ($nodeCount - 1)
            : (
                $nodeCount
                * ($nodeCount - 1)
            ) / 2;

        if ($maximumEdges <= 0) {
            return 0.0;
        }

        return round(
            min(
                1.0,
                $edgeCount / $maximumEdges
            ),
            8
        );
    }

    /**
     * Summarize relationship vocabulary and states.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<string,mixed>
     */
    public function relationshipSummary(
        array $relationships
    ): array {
        $types = [];
        $statuses = [];
        $sourceTypes = [];
        $targetTypes = [];
        $directional = 0;
        $undirected = 0;

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $type = trim(
                (string)(
                    $relationship[
                        'relationship_type'
                    ]
                    ?? 'related_to'
                )
            );

            $status = trim(
                (string)(
                    $relationship['status']
                    ?? 'unknown'
                )
            );

            $sourceType = trim(
                (string)(
                    $relationship['source_type']
                    ?? 'unknown'
                )
            );

            $targetType = trim(
                (string)(
                    $relationship['target_type']
                    ?? 'unknown'
                )
            );

            $types[$type] =
                ($types[$type] ?? 0) + 1;

            $statuses[$status] =
                ($statuses[$status] ?? 0) + 1;

            $sourceTypes[$sourceType] =
                ($sourceTypes[$sourceType] ?? 0)
                + 1;

            $targetTypes[$targetType] =
                ($targetTypes[$targetType] ?? 0)
                + 1;

            if (
                ($relationship['directional'] ?? true)
                === false
            ) {
                $undirected++;
            } else {
                $directional++;
            }
        }

        arsort($types);
        arsort($statuses);
        arsort($sourceTypes);
        arsort($targetTypes);

        return [
            'count' => count($relationships),

            'directional_count' =>
                $directional,

            'undirected_count' =>
                $undirected,

            'types' => $types,

            'statuses' => $statuses,

            'source_entity_types' =>
                $sourceTypes,

            'target_entity_types' =>
                $targetTypes,
        ];
    }

    /**
     * Summarize node structure.
     *
     * @param array<string,array<string,mixed>> $nodeIndex
     * @param array<string,mixed> $degrees
     *
     * @return array<string,mixed>
     */
    public function nodeSummary(
        array $nodeIndex,
        array $degrees
    ): array {
        $types = [];
        $hubs = [];
        $leaves = [];
        $isolates = [];

        foreach ($nodeIndex as $nodeKey => $node) {
            $nodeType = trim(
                (string)(
                    $node['node_type']
                    ?? 'unknown'
                )
            );

            $types[$nodeType] =
                ($types[$nodeType] ?? 0) + 1;

            $totalDegree = (int)(
                $degrees['total'][$nodeKey]
                ?? 0
            );

            if ($totalDegree === 0) {
                $isolates[] = $node;
            } elseif ($totalDegree === 1) {
                $leaves[] = array_merge(
                    $node,
                    [
                        'total_degree' => 1,
                    ]
                );
            }

            if ($totalDegree >= 2) {
                $hubs[] = array_merge(
                    $node,
                    [
                        'total_degree' =>
                            $totalDegree,

                        'incoming_degree' =>
                            (int)(
                                $degrees[
                                    'incoming'
                                ][$nodeKey]
                                ?? 0
                            ),

                        'outgoing_degree' =>
                            (int)(
                                $degrees[
                                    'outgoing'
                                ][$nodeKey]
                                ?? 0
                            ),
                    ]
                );
            }
        }

        arsort($types);

        usort(
            $hubs,
            static fn (
                array $left,
                array $right
            ): int =>
                ($right['total_degree'] ?? 0)
                <=>
                ($left['total_degree'] ?? 0)
        );

        return [
            'count' => count($nodeIndex),

            'types' => $types,

            'hub_count' => count($hubs),

            'leaf_count' => count($leaves),

            'isolate_count' =>
                count($isolates),

            'top_hubs' =>
                array_slice(
                    $hubs,
                    0,
                    25
                ),

            'leaves' => $leaves,

            'isolates' => $isolates,
        ];
    }

    /**
     * Calculate confidence, weight, and strength quality measurements.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<string,mixed>
     */
    public function qualitySummary(
        array $relationships
    ): array {
        $confidences = [];
        $weights = [];
        $strengths = [];

        $missingConfidence = 0;
        $missingProvenance = 0;
        $missingCreator = 0;
        $aiSuggested = 0;
        $humanAccepted = 0;
        $checksumFailures = 0;

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            if (
                isset($relationship['confidence'])
                && is_numeric(
                    $relationship['confidence']
                )
            ) {
                $confidences[] = max(
                    0.0,
                    min(
                        100.0,
                        (float)$relationship[
                            'confidence'
                        ]
                    )
                );
            } else {
                $missingConfidence++;
            }

            if (
                isset($relationship['weight'])
                && is_numeric(
                    $relationship['weight']
                )
            ) {
                $weights[] = max(
                    0.0,
                    min(
                        1.0,
                        (float)$relationship['weight']
                    )
                );
            }

            if (
                isset($relationship['strength'])
                && is_numeric(
                    $relationship['strength']
                )
            ) {
                $strengths[] = max(
                    0.0,
                    min(
                        1.0,
                        (float)$relationship[
                            'strength'
                        ]
                    )
                );
            }

            if (
                trim(
                    (string)(
                        $relationship[
                            'provenance_id'
                        ]
                        ?? ''
                    )
                ) === ''
            ) {
                $missingProvenance++;
            }

            if (
                trim(
                    (string)(
                        $relationship['created_by']
                        ?? ''
                    )
                ) === ''
            ) {
                $missingCreator++;
            }

            if (
                ($relationship[
                    'suggested_by_ai'
                ] ?? false) === true
            ) {
                $aiSuggested++;
            }

            if (
                ($relationship[
                    'accepted_by_human'
                ] ?? false) === true
            ) {
                $humanAccepted++;
            }

            if (
                isset($relationship['checksum'])
                && trim(
                    (string)$relationship[
                        'checksum'
                    ]
                ) !== ''
                && !$this->relationshipChecksumMatches(
                    $relationship
                )
            ) {
                $checksumFailures++;
            }
        }

        $count = count($relationships);

        return [
            'average_confidence' =>
                $this->average($confidences),

            'minimum_confidence' =>
                $confidences !== []
                    ? round(
                        min($confidences),
                        4
                    )
                    : null,

            'maximum_confidence' =>
                $confidences !== []
                    ? round(
                        max($confidences),
                        4
                    )
                    : null,

            'average_weight' =>
                $this->average($weights, 6),

            'average_strength' =>
                $this->average($strengths, 6),

            'missing_confidence_count' =>
                $missingConfidence,

            'missing_provenance_count' =>
                $missingProvenance,

            'missing_creator_count' =>
                $missingCreator,

            'ai_suggested_count' =>
                $aiSuggested,

            'human_accepted_count' =>
                $humanAccepted,

            'checksum_failure_count' =>
                $checksumFailures,

            'provenance_coverage_percent' =>
                $count > 0
                    ? round(
                        (
                            ($count - $missingProvenance)
                            / $count
                        ) * 100,
                        2
                    )
                    : 0.0,

            'attribution_coverage_percent' =>
                $count > 0
                    ? round(
                        (
                            ($count - $missingCreator)
                            / $count
                        ) * 100,
                        2
                    )
                    : 0.0,
        ];
    }

    /**
     * Identify bridge relationships.
     *
     * Removing a bridge increases the number of connected components.
     *
     * This bounded implementation favours clarity over large-graph speed.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<int,array<string,mixed>>
     */
    public function bridgeEdges(
        array $relationships,
        int $maximumChecks = 5000
    ): array {
        $maximumChecks = max(
            0,
            min(100000, $maximumChecks)
        );

        if (
            $relationships === []
            || $maximumChecks === 0
        ) {
            return [];
        }

        $baselineComponents = count(
            $this->traversal
                ->connectedComponents(
                    $relationships,
                    [],
                    true
                )
        );

        $bridges = [];
        $checked = 0;

        foreach (
            $relationships
            as $index => $relationship
        ) {
            if (
                !is_array($relationship)
                || $checked >= $maximumChecks
            ) {
                continue;
            }

            $checked++;

            $remaining = $relationships;
            unset($remaining[$index]);

            $remaining = array_values(
                $remaining
            );

            $newComponentCount = count(
                $this->traversal
                    ->connectedComponents(
                        $remaining,
                        [],
                        true
                    )
            );

            if (
                $newComponentCount
                <= $baselineComponents
            ) {
                continue;
            }

            $bridges[] = [
                'relationship_id' =>
                    $relationship[
                        'relationship_id'
                    ] ?? null,

                'relationship_type' =>
                    $relationship[
                        'relationship_type'
                    ] ?? null,

                'source_key' =>
                    $this->relationshipSourceKey(
                        $relationship
                    ),

                'target_key' =>
                    $this->relationshipTargetKey(
                        $relationship
                    ),

                'component_increase' =>
                    $newComponentCount
                    - $baselineComponents,

                'relationship' =>
                    $relationship,
            ];
        }

        return $bridges;
    }

    /**
     * Rank relationship types by frequency.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<int,array<string,mixed>>
     */
    public function relationshipTypeRanking(
        array $relationships
    ): array {
        $counts = [];

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $type = trim(
                (string)(
                    $relationship[
                        'relationship_type'
                    ]
                    ?? 'related_to'
                )
            );

            $counts[$type] =
                ($counts[$type] ?? 0) + 1;
        }

        arsort($counts);

        $ranking = [];
        $rank = 1;

        foreach ($counts as $type => $count) {
            $ranking[] = [
                'rank' => $rank++,
                'relationship_type' => $type,
                'count' => $count,
            ];
        }

        return $ranking;
    }

    /**
     * Return analytics diagnostics.
     *
     * @return array<string,mixed>
     */
    public function diagnostics(): array
    {
        return array_merge(
            parent::diagnostics(),
            [
                'degree_metrics' => true,
                'density_metrics' => true,
                'connected_components' => true,
                'cycle_analysis' => true,
                'bridge_detection' =>
                    'bounded_edge_removal',
                'quality_metrics' => true,
                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Build a node index from edges and optional entities.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @param array<int,array<string,mixed>> $entities
     *
     * @return array<string,array<string,mixed>>
     */
    private function buildNodeIndex(
        array $relationships,
        array $entities = []
    ): array {
        $nodes = [];

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $node = $this->normalizeEntityRecord(
                $entity
            );

            $nodeKey = trim(
                (string)(
                    $node['node_key']
                    ?? ''
                )
            );

            if ($nodeKey !== '') {
                $nodes[$nodeKey] = $node;
            }
        }

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $sourceKey = $this->relationshipSourceKey(
                $relationship
            );

            $targetKey = $this->relationshipTargetKey(
                $relationship
            );

            if ($sourceKey !== '') {
                [$sourceType, $sourceId] =
                    $this->splitNodeKey(
                        $sourceKey
                    );

                $nodes[$sourceKey] ??= [
                    'node_id' => $sourceId,
                    'node_type' => $sourceType,
                    'node_key' => $sourceKey,
                ];
            }

            if ($targetKey !== '') {
                [$targetType, $targetId] =
                    $this->splitNodeKey(
                        $targetKey
                    );

                $nodes[$targetKey] ??= [
                    'node_id' => $targetId,
                    'node_type' => $targetType,
                    'node_key' => $targetKey,
                ];
            }
        }

        ksort($nodes);

        return $nodes;
    }

    /**
     * Normalize a generic entity record into a graph node.
     *
     * @param array<string,mixed> $entity
     * @return array<string,mixed>
     */
    private function normalizeEntityRecord(
        array $entity
    ): array {
        $entityType = $this->normalizeEntityType(
            (string)(
                $entity['entity_type']
                ?? $entity['type']
                ?? 'entity'
            )
        );

        $entityId = trim(
            (string)(
                $entity['entity_id']
                ?? $entity['asset_id']
                ?? $entity['translation_id']
                ?? $entity['relationship_id']
                ?? $entity['id']
                ?? ''
            )
        );

        return array_merge(
            $entity,
            [
                'node_id' => $entityId,
                'node_type' => $entityType,
                'node_key' =>
                    $this->graphNodeKey(
                        $entityType,
                        $entityId
                    ),
            ]
        );
    }

    /**
     * Filter relationships before analytics.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @param array<int,string> $relationshipTypes
     * @param array<int,string> $statuses
     *
     * @return array<int,array<string,mixed>>
     */
    private function filterRelationships(
        array $relationships,
        array $relationshipTypes,
        array $statuses,
        bool $includeExpired
    ): array {
        $filtered = [];

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $type = trim(
                (string)(
                    $relationship[
                        'relationship_type'
                    ]
                    ?? ''
                )
            );

            $status = trim(
                (string)(
                    $relationship['status']
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
                continue;
            }

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

            if (
                !$includeExpired
                && !$this->isTemporallyActive(
                    $relationship
                )
            ) {
                continue;
            }

            $filtered[] = $relationship;
        }

        return $filtered;
    }

    /**
     * Determine whether the graph contains directional edges.
     *
     * @param array<int,array<string,mixed>> $relationships
     */
    private function isDirectedGraph(
        array $relationships
    ): bool {
        foreach ($relationships as $relationship) {
            if (
                is_array($relationship)
                && (
                    $relationship['directional']
                    ?? true
                ) === true
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether an edge is currently valid.
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
     * Create the source node key.
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
     * Create the target node key.
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
     * Confirm a relationship checksum without depending on RelationshipService.
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

        $copy = $relationship;
        unset($copy['checksum']);

        $normalized = $this->normalizeForHash(
            $copy
        );

        $json = json_encode(
            $normalized,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
        );

        if ($json === false) {
            return false;
        }

        return hash_equals(
            $stored,
            hash('sha256', $json)
        );
    }

    /**
     * Normalize entity types.
     */
    private function normalizeEntityType(
        string $entityType
    ): string {
        $entityType = strtolower(
            trim($entityType)
        );

        return preg_replace(
            '/[^a-z0-9_]+/',
            '_',
            $entityType
        ) ?? '';
    }

    /**
     * Split a node key.
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
     * Normalize a string collection.
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
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    /**
     * Calculate an average safely.
     *
     * @param array<int,float|int> $values
     */
    private function average(
        array $values,
        int $precision = 4
    ): ?float {
        if ($values === []) {
            return null;
        }

        return round(
            array_sum($values)
            / count($values),
            $precision
        );
    }
}