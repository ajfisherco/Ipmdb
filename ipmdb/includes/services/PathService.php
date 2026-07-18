<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/PathService.php
|--------------------------------------------------------------------------
| IPMdb Path Service
|--------------------------------------------------------------------------
|
| Finds and explains routes between entities in the IPMdb knowledge graph.
|
| Responsibilities:
| - Find shortest paths.
| - Find strongest paths.
| - Find all simple paths within safe limits.
| - Explain how two entities are connected.
| - Calculate path confidence, strength, weight, and depth.
|
| RelationshipService manages edges.
| GraphTraversalService discovers reachable nodes.
| PathService evaluates complete routes.
|
| This service performs no database operations.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/GraphTraversalService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class PathService extends Service
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
     * Find the shortest path using breadth-first search.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @param array<int,string> $relationshipTypes
     * @param array<int,string> $statuses
     *
     * @return array<string,mixed>|null
     */
    public function shortestPath(
        array $relationships,
        string $sourceId,
        string $targetId,
        ?string $sourceType = null,
        ?string $targetType = null,
        string $direction = 'both',
        array $relationshipTypes = [],
        array $statuses = ['active', 'verified'],
        bool $includeExpired = false,
        int $maxDepth = 12,
        int $maxVisitedNodes = 10000
    ): ?array {
        $this->reset();

        $sourceId = trim($sourceId);
        $targetId = trim($targetId);

        $sourceType = $this->normalizeOptionalEntityType(
            $sourceType
        );

        $targetType = $this->normalizeOptionalEntityType(
            $targetType
        );

        $direction = $this->normalizeDirection(
            $direction
        );

        $maxDepth = max(
            0,
            min(100, $maxDepth)
        );

        $maxVisitedNodes = max(
            1,
            min(1000000, $maxVisitedNodes)
        );

        if ($sourceId === '' || $targetId === '') {
            throw new InvalidArgumentException(
                'Source and target entity IDs are required.'
            );
        }

        $sourceKey = $this->graphNodeKey(
            $sourceType ?? 'entity',
            $sourceId
        );

        $targetKey = $targetType !== null
            ? $this->graphNodeKey(
                $targetType,
                $targetId
            )
            : null;

        if ($sourceKey === '') {
            throw new InvalidArgumentException(
                'Source node is invalid.'
            );
        }

        if (
            $sourceId === $targetId
            && (
                $targetType === null
                || $sourceType === $targetType
            )
        ) {
            return $this->buildPathResult(
                [
                    [
                        'node_id' => $sourceId,
                        'node_type' =>
                            $sourceType ?? 'entity',
                        'node_key' => $sourceKey,
                    ],
                ],
                [],
                'shortest'
            );
        }

        $adjacency = $this->traversal->buildAdjacency(
            $relationships,
            $direction,
            $relationshipTypes,
            $statuses,
            $includeExpired
        );

        if (!isset($adjacency[$sourceKey])) {
            return null;
        }

        $queue = [
            [
                'node_key' => $sourceKey,
                'depth' => 0,
            ],
        ];

        $visited = [
            $sourceKey => true,
        ];

        $parents = [];

        $targetFoundKey = null;

        while ($queue !== []) {
            $current = array_shift($queue);

            if (!is_array($current)) {
                continue;
            }

            $currentKey = trim(
                (string)(
                    $current['node_key']
                    ?? ''
                )
            );

            $depth = (int)(
                $current['depth']
                ?? 0
            );

            if (
                $currentKey === ''
                || $depth >= $maxDepth
            ) {
                continue;
            }

            foreach (
                $adjacency[$currentKey] ?? []
                as $connection
            ) {
                $nextKey = trim(
                    (string)(
                        $connection['node_key']
                        ?? ''
                    )
                );

                if (
                    $nextKey === ''
                    || isset($visited[$nextKey])
                ) {
                    continue;
                }

                $visited[$nextKey] = true;

                $parents[$nextKey] = [
                    'parent_key' => $currentKey,
                    'connection' => $connection,
                ];

                $nextId = trim(
                    (string)(
                        $connection['node_id']
                        ?? ''
                    )
                );

                $nextType = $this->normalizeEntityType(
                    (string)(
                        $connection['node_type']
                        ?? ''
                    )
                );

                $matchesTarget =
                    $nextId === $targetId
                    && (
                        $targetType === null
                        || $nextType === $targetType
                    );

                if (
                    $targetKey !== null
                    && $nextKey === $targetKey
                ) {
                    $matchesTarget = true;
                }

                if ($matchesTarget) {
                    $targetFoundKey = $nextKey;
                    break 2;
                }

                if (
                    count($visited)
                    >= $maxVisitedNodes
                ) {
                    break 2;
                }

                $queue[] = [
                    'node_key' => $nextKey,
                    'depth' => $depth + 1,
                ];
            }
        }

        if ($targetFoundKey === null) {
            return null;
        }

        $result = $this->reconstructPath(
            $sourceKey,
            $targetFoundKey,
            $parents,
            'shortest'
        );

        $this->addMessage(
            'Shortest path found.',
            [
                'source' => $sourceKey,
                'target' => $targetFoundKey,
                'edge_count' =>
                    $result['edge_count'],
                'visited_count' =>
                    count($visited),
            ]
        );

        return $result;
    }

    /**
     * Find the strongest path using a maximum-product score.
     *
     * Each edge contributes:
     *
     * confidence × weight × strength.
     *
     * The path with the highest cumulative product wins.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @param array<int,string> $relationshipTypes
     * @param array<int,string> $statuses
     *
     * @return array<string,mixed>|null
     */
    public function strongestPath(
        array $relationships,
        string $sourceId,
        string $targetId,
        ?string $sourceType = null,
        ?string $targetType = null,
        string $direction = 'both',
        array $relationshipTypes = [],
        array $statuses = ['active', 'verified'],
        bool $includeExpired = false,
        int $maxDepth = 12,
        int $maxVisitedNodes = 10000
    ): ?array {
        $this->reset();

        $sourceId = trim($sourceId);
        $targetId = trim($targetId);

        $sourceType = $this->normalizeOptionalEntityType(
            $sourceType
        );

        $targetType = $this->normalizeOptionalEntityType(
            $targetType
        );

        $direction = $this->normalizeDirection(
            $direction
        );

        $maxDepth = max(
            1,
            min(100, $maxDepth)
        );

        $maxVisitedNodes = max(
            1,
            min(1000000, $maxVisitedNodes)
        );

        if ($sourceId === '' || $targetId === '') {
            throw new InvalidArgumentException(
                'Source and target entity IDs are required.'
            );
        }

        $sourceKey = $this->graphNodeKey(
            $sourceType ?? 'entity',
            $sourceId
        );

        if ($sourceKey === '') {
            throw new InvalidArgumentException(
                'Source node is invalid.'
            );
        }

        $adjacency = $this->traversal->buildAdjacency(
            $relationships,
            $direction,
            $relationshipTypes,
            $statuses,
            $includeExpired
        );

        if (!isset($adjacency[$sourceKey])) {
            return null;
        }

        $queue = [
            [
                'node_key' => $sourceKey,
                'score' => 1.0,
                'depth' => 0,
                'nodes' => [$sourceKey],
                'connections' => [],
            ],
        ];

        $bestScores = [
            $sourceKey => 1.0,
        ];

        $visitedCount = 0;
        $bestResult = null;
        $bestTargetScore = -1.0;

        while ($queue !== []) {
            usort(
                $queue,
                static fn (
                    array $left,
                    array $right
                ): int =>
                    ($right['score'] ?? 0)
                    <=>
                    ($left['score'] ?? 0)
            );

            $current = array_shift($queue);

            if (!is_array($current)) {
                continue;
            }

            $visitedCount++;

            if ($visitedCount > $maxVisitedNodes) {
                break;
            }

            $currentKey = trim(
                (string)(
                    $current['node_key']
                    ?? ''
                )
            );

            $depth = (int)(
                $current['depth']
                ?? 0
            );

            $score = (float)(
                $current['score']
                ?? 0
            );

            if (
                $currentKey === ''
                || $depth >= $maxDepth
            ) {
                continue;
            }

            foreach (
                $adjacency[$currentKey] ?? []
                as $connection
            ) {
                $nextKey = trim(
                    (string)(
                        $connection['node_key']
                        ?? ''
                    )
                );

                if (
                    $nextKey === ''
                    || in_array(
                        $nextKey,
                        $current['nodes'],
                        true
                    )
                ) {
                    continue;
                }

                $relationship = is_array(
                    $connection['relationship']
                    ?? null
                )
                    ? $connection['relationship']
                    : [];

                $edgeScore =
                    $this->edgeScore(
                        $relationship
                    );

                $nextScore =
                    $score * $edgeScore;

                $nextNodes = array_merge(
                    $current['nodes'],
                    [$nextKey]
                );

                $nextConnections = array_merge(
                    $current['connections'],
                    [$connection]
                );

                $nextId = trim(
                    (string)(
                        $connection['node_id']
                        ?? ''
                    )
                );

                $nextType =
                    $this->normalizeEntityType(
                        (string)(
                            $connection['node_type']
                            ?? ''
                        )
                    );

                $matchesTarget =
                    $nextId === $targetId
                    && (
                        $targetType === null
                        || $nextType === $targetType
                    );

                if ($matchesTarget) {
                    if ($nextScore > $bestTargetScore) {
                        $bestTargetScore = $nextScore;

                        $bestResult =
                            $this->buildPathFromConnections(
                                $sourceKey,
                                $nextConnections,
                                'strongest'
                            );
                    }

                    continue;
                }

                if (
                    isset($bestScores[$nextKey])
                    && $bestScores[$nextKey]
                        >= $nextScore
                ) {
                    continue;
                }

                $bestScores[$nextKey] =
                    $nextScore;

                $queue[] = [
                    'node_key' => $nextKey,
                    'score' => $nextScore,
                    'depth' => $depth + 1,
                    'nodes' => $nextNodes,
                    'connections' =>
                        $nextConnections,
                ];
            }
        }

        if ($bestResult !== null) {
            $this->addMessage(
                'Strongest path found.',
                [
                    'source' => $sourceKey,
                    'target_id' => $targetId,
                    'score' =>
                        $bestResult['path_score'],
                    'edge_count' =>
                        $bestResult['edge_count'],
                ]
            );
        }

        return $bestResult;
    }

    /**
     * Find all simple paths within bounded limits.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @param array<int,string> $relationshipTypes
     * @param array<int,string> $statuses
     *
     * @return array<int,array<string,mixed>>
     */
    public function allPaths(
        array $relationships,
        string $sourceId,
        string $targetId,
        ?string $sourceType = null,
        ?string $targetType = null,
        string $direction = 'both',
        array $relationshipTypes = [],
        array $statuses = ['active', 'verified'],
        bool $includeExpired = false,
        int $maxDepth = 8,
        int $maxPaths = 100
    ): array {
        $this->reset();

        $sourceId = trim($sourceId);
        $targetId = trim($targetId);

        $sourceType = $this->normalizeOptionalEntityType(
            $sourceType
        );

        $targetType = $this->normalizeOptionalEntityType(
            $targetType
        );

        $maxDepth = max(
            1,
            min(30, $maxDepth)
        );

        $maxPaths = max(
            1,
            min(10000, $maxPaths)
        );

        if ($sourceId === '' || $targetId === '') {
            throw new InvalidArgumentException(
                'Source and target entity IDs are required.'
            );
        }

        $sourceKey = $this->graphNodeKey(
            $sourceType ?? 'entity',
            $sourceId
        );

        $adjacency = $this->traversal->buildAdjacency(
            $relationships,
            $this->normalizeDirection($direction),
            $relationshipTypes,
            $statuses,
            $includeExpired
        );

        $paths = [];

        $walk = function (
            string $currentKey,
            array $visitedKeys,
            array $connections,
            int $depth
        ) use (
            &$walk,
            &$paths,
            $adjacency,
            $targetId,
            $targetType,
            $maxDepth,
            $maxPaths,
            $sourceKey
        ): void {
            if (
                count($paths) >= $maxPaths
                || $depth >= $maxDepth
            ) {
                return;
            }

            foreach (
                $adjacency[$currentKey] ?? []
                as $connection
            ) {
                $nextKey = trim(
                    (string)(
                        $connection['node_key']
                        ?? ''
                    )
                );

                if (
                    $nextKey === ''
                    || isset($visitedKeys[$nextKey])
                ) {
                    continue;
                }

                $nextConnections = array_merge(
                    $connections,
                    [$connection]
                );

                $nextId = trim(
                    (string)(
                        $connection['node_id']
                        ?? ''
                    )
                );

                $nextType =
                    $this->normalizeEntityType(
                        (string)(
                            $connection['node_type']
                            ?? ''
                        )
                    );

                $matchesTarget =
                    $nextId === $targetId
                    && (
                        $targetType === null
                        || $nextType === $targetType
                    );

                if ($matchesTarget) {
                    $paths[] =
                        $this->buildPathFromConnections(
                            $sourceKey,
                            $nextConnections,
                            'all_paths'
                        );

                    if (
                        count($paths)
                        >= $maxPaths
                    ) {
                        return;
                    }

                    continue;
                }

                $nextVisited = $visitedKeys;
                $nextVisited[$nextKey] = true;

                $walk(
                    $nextKey,
                    $nextVisited,
                    $nextConnections,
                    $depth + 1
                );
            }
        };

        $walk(
            $sourceKey,
            [
                $sourceKey => true,
            ],
            [],
            0
        );

        usort(
            $paths,
            static function (
                array $left,
                array $right
            ): int {
                $lengthComparison =
                    ($left['edge_count'] ?? 0)
                    <=>
                    ($right['edge_count'] ?? 0);

                if ($lengthComparison !== 0) {
                    return $lengthComparison;
                }

                return (
                    $right['path_score']
                    ?? 0
                )
                <=>
                (
                    $left['path_score']
                    ?? 0
                );
            }
        );

        $this->addMessage(
            'Path enumeration completed.',
            [
                'source' => $sourceKey,
                'target_id' => $targetId,
                'path_count' => count($paths),
                'max_depth' => $maxDepth,
            ]
        );

        return $paths;
    }

    /**
     * Return a human-readable explanation of one path.
     *
     * @param array<string,mixed> $path
     */
    public function explain(
        array $path
    ): string {
        $nodes = is_array(
            $path['nodes']
            ?? null
        )
            ? $path['nodes']
            : [];

        $edges = is_array(
            $path['edges']
            ?? null
        )
            ? $path['edges']
            : [];

        if ($nodes === []) {
            return 'No path is available.';
        }

        if ($edges === []) {
            $firstNode = $nodes[0];

            return sprintf(
                '%s:%s is the starting and ending entity.',
                (string)(
                    $firstNode['node_type']
                    ?? 'entity'
                ),
                (string)(
                    $firstNode['node_id']
                    ?? ''
                )
            );
        }

        $parts = [];

        foreach ($edges as $index => $edge) {
            $source = $nodes[$index] ?? [];
            $target = $nodes[$index + 1] ?? [];

            $relationship = is_array(
                $edge['relationship']
                ?? null
            )
                ? $edge['relationship']
                : [];

            $relationshipType = trim(
                (string)(
                    $relationship[
                        'relationship_type'
                    ]
                    ?? $edge[
                        'relationship_type'
                    ]
                    ?? 'related_to'
                )
            );

            $parts[] = sprintf(
                '%s:%s %s %s:%s',
                (string)(
                    $source['node_type']
                    ?? 'entity'
                ),
                (string)(
                    $source['node_id']
                    ?? ''
                ),
                str_replace(
                    '_',
                    ' ',
                    $relationshipType
                ),
                (string)(
                    $target['node_type']
                    ?? 'entity'
                ),
                (string)(
                    $target['node_id']
                    ?? ''
                )
            );
        }

        return implode(
            '; then ',
            $parts
        ) . '.';
    }

    /**
     * Compare two path results.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     *
     * @return array<string,mixed>
     */
    public function compare(
        array $left,
        array $right
    ): array {
        return [
            'left_edge_count' =>
                (int)(
                    $left['edge_count']
                    ?? 0
                ),

            'right_edge_count' =>
                (int)(
                    $right['edge_count']
                    ?? 0
                ),

            'left_score' =>
                (float)(
                    $left['path_score']
                    ?? 0
                ),

            'right_score' =>
                (float)(
                    $right['path_score']
                    ?? 0
                ),

            'shorter_path' =>
                $this->compareMetric(
                    (float)(
                        $left['edge_count']
                        ?? 0
                    ),
                    (float)(
                        $right['edge_count']
                        ?? 0
                    ),
                    true
                ),

            'stronger_path' =>
                $this->compareMetric(
                    (float)(
                        $left['path_score']
                        ?? 0
                    ),
                    (float)(
                        $right['path_score']
                        ?? 0
                    ),
                    false
                ),

            'higher_confidence' =>
                $this->compareMetric(
                    (float)(
                        $left[
                            'average_confidence'
                        ]
                        ?? 0
                    ),
                    (float)(
                        $right[
                            'average_confidence'
                        ]
                        ?? 0
                    ),
                    false
                ),
        ];
    }

    /**
     * Calculate metrics for an existing path.
     *
     * @param array<int,array<string,mixed>> $edges
     * @return array<string,mixed>
     */
    public function metrics(
        array $edges
    ): array {
        if ($edges === []) {
            return [
                'edge_count' => 0,
                'path_score' => 1.0,
                'average_confidence' => 100.0,
                'minimum_confidence' => 100.0,
                'average_weight' => 1.0,
                'average_strength' => 1.0,
            ];
        }

        $confidences = [];
        $weights = [];
        $strengths = [];
        $pathScore = 1.0;

        foreach ($edges as $edge) {
            $relationship = is_array(
                $edge['relationship']
                ?? null
            )
                ? $edge['relationship']
                : $edge;

            $confidence =
                $this->safeConfidence(
                    $relationship[
                        'confidence'
                    ]
                    ?? 100
                );

            $weight =
                $this->safeWeight(
                    $relationship['weight']
                    ?? 1
                );

            $strength =
                $this->safeWeight(
                    $relationship['strength']
                    ?? 1
                );

            $confidences[] = $confidence;
            $weights[] = $weight;
            $strengths[] = $strength;

            $pathScore *= (
                $confidence / 100
            )
                * $weight
                * $strength;
        }

        return [
            'edge_count' => count($edges),

            'path_score' => round(
                $pathScore,
                12
            ),

            'average_confidence' => round(
                array_sum($confidences)
                / count($confidences),
                4
            ),

            'minimum_confidence' => round(
                min($confidences),
                4
            ),

            'average_weight' => round(
                array_sum($weights)
                / count($weights),
                6
            ),

            'average_strength' => round(
                array_sum($strengths)
                / count($strengths),
                6
            ),
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
                'shortest_path_algorithm' =>
                    'breadth_first_search',

                'strongest_path_algorithm' =>
                    'maximum_product_best_first',

                'simple_path_enumeration' =>
                    true,

                'cycle_reuse_in_paths' =>
                    false,

                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Reconstruct a path from parent pointers.
     *
     * @param array<string,array<string,mixed>> $parents
     * @return array<string,mixed>
     */
    private function reconstructPath(
        string $sourceKey,
        string $targetKey,
        array $parents,
        string $strategy
    ): array {
        $connections = [];
        $currentKey = $targetKey;

        while (
            $currentKey !== $sourceKey
            && isset($parents[$currentKey])
        ) {
            $parent = $parents[$currentKey];

            array_unshift(
                $connections,
                $parent['connection']
            );

            $currentKey = trim(
                (string)(
                    $parent['parent_key']
                    ?? ''
                )
            );
        }

        if ($currentKey !== $sourceKey) {
            throw new RuntimeException(
                'Unable to reconstruct graph path.'
            );
        }

        return $this->buildPathFromConnections(
            $sourceKey,
            $connections,
            $strategy
        );
    }

    /**
     * Build a complete path result from ordered connections.
     *
     * @param array<int,array<string,mixed>> $connections
     * @return array<string,mixed>
     */
    private function buildPathFromConnections(
        string $sourceKey,
        array $connections,
        string $strategy
    ): array {
        [$sourceType, $sourceId] =
            $this->splitNodeKey(
                $sourceKey
            );

        $nodes = [
            [
                'node_id' => $sourceId,
                'node_type' => $sourceType,
                'node_key' => $sourceKey,
            ],
        ];

        $edges = [];

        foreach ($connections as $connection) {
            $nextKey = trim(
                (string)(
                    $connection['node_key']
                    ?? ''
                )
            );

            $nextId = trim(
                (string)(
                    $connection['node_id']
                    ?? ''
                )
            );

            $nextType =
                $this->normalizeEntityType(
                    (string)(
                        $connection['node_type']
                        ?? ''
                    )
                );

            if (
                $nextKey === ''
                && $nextId !== ''
                && $nextType !== ''
            ) {
                $nextKey =
                    $this->graphNodeKey(
                        $nextType,
                        $nextId
                    );
            }

            $nodes[] = [
                'node_id' => $nextId,
                'node_type' => $nextType,
                'node_key' => $nextKey,
            ];

            $edges[] = $connection;
        }

        return $this->buildPathResult(
            $nodes,
            $edges,
            $strategy
        );
    }

    /**
     * Build one standardized path result.
     *
     * @param array<int,array<string,mixed>> $nodes
     * @param array<int,array<string,mixed>> $edges
     * @return array<string,mixed>
     */
    private function buildPathResult(
        array $nodes,
        array $edges,
        string $strategy
    ): array {
        $metrics = $this->metrics($edges);

        $relationshipIds = [];

        foreach ($edges as $edge) {
            $relationship = is_array(
                $edge['relationship']
                ?? null
            )
                ? $edge['relationship']
                : [];

            $relationshipId = trim(
                (string)(
                    $relationship[
                        'relationship_id'
                    ]
                    ?? $edge[
                        'relationship_id'
                    ]
                    ?? ''
                )
            );

            if ($relationshipId !== '') {
                $relationshipIds[] =
                    $relationshipId;
            }
        }

        $result = array_merge(
            [
                'strategy' => $strategy,

                'source' =>
                    $nodes[0] ?? null,

                'target' =>
                    $nodes !== []
                        ? $nodes[
                            array_key_last($nodes)
                        ]
                        : null,

                'nodes' => $nodes,

                'edges' => $edges,

                'relationship_ids' =>
                    $relationshipIds,
            ],
            $metrics
        );

        $result['explanation'] =
            $this->explain($result);

        return $result;
    }

    /**
     * Calculate one edge's normalized score.
     *
     * @param array<string,mixed> $relationship
     */
    private function edgeScore(
        array $relationship
    ): float {
        $confidence = $this->safeConfidence(
            $relationship['confidence']
            ?? 100
        );

        $weight = $this->safeWeight(
            $relationship['weight']
            ?? 1
        );

        $strength = $this->safeWeight(
            $relationship['strength']
            ?? 1
        );

        return max(
            0.000000000001,
            ($confidence / 100)
                * $weight
                * $strength
        );
    }

    private function safeConfidence(
        mixed $confidence
    ): float {
        if (!is_numeric($confidence)) {
            return 100.0;
        }

        return max(
            0.0,
            min(
                100.0,
                (float)$confidence
            )
        );
    }

    private function safeWeight(
        mixed $weight
    ): float {
        if (!is_numeric($weight)) {
            return 1.0;
        }

        return max(
            0.0,
            min(
                1.0,
                (float)$weight
            )
        );
    }

    private function normalizeDirection(
        string $direction
    ): string {
        $direction = strtolower(
            trim($direction)
        );

        return in_array(
            $direction,
            [
                'outgoing',
                'incoming',
                'both',
            ],
            true
        )
            ? $direction
            : 'both';
    }

    private function normalizeOptionalEntityType(
        ?string $entityType
    ): ?string {
        if ($entityType === null) {
            return null;
        }

        $entityType = $this->normalizeEntityType(
            $entityType
        );

        return $entityType !== ''
            ? $entityType
            : null;
    }

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
     * Split a node key into entity type and ID.
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
                (string)($parts[0] ?? 'entity')
            ),
            trim(
                (string)($parts[1] ?? '')
            ),
        ];
    }

    private function compareMetric(
        float $left,
        float $right,
        bool $lowerWins
    ): string {
        if ($left === $right) {
            return 'equal';
        }

        if ($lowerWins) {
            return $left < $right
                ? 'left'
                : 'right';
        }

        return $left > $right
            ? 'left'
            : 'right';
    }
}