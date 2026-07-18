<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/GraphTraversalService.php
|--------------------------------------------------------------------------
| IPMdb Graph Traversal Service
|--------------------------------------------------------------------------
|
| Traverses relationship records without assuming a database engine.
|
| Responsibilities:
| - Discover direct neighbours.
| - Traverse outward or inward through multiple levels.
| - Find ancestors and descendants.
| - Identify connected components.
| - Detect cycles.
| - Produce traversal trees and reachable node sets.
|
| RelationshipService manages edges.
| GraphTraversalService walks them.
| Repository classes persist them.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class GraphTraversalService extends Service
{
    use GraphUtilities;

    /**
     * Return all direct neighbours of one node.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @param array<int,string> $relationshipTypes
     * @param array<int,string> $statuses
     *
     * @return array<int,array<string,mixed>>
     */
    public function neighbours(
        array $relationships,
        string $entityId,
        ?string $entityType = null,
        string $direction = 'both',
        array $relationshipTypes = [],
        array $statuses = ['active', 'verified'],
        bool $includeExpired = false
    ): array {
        $entityId = trim($entityId);
        $entityType = $this->normalizeOptionalEntityType(
            $entityType
        );
        $direction = $this->normalizeDirection(
            $direction
        );

        if ($entityId === '') {
            return [];
        }

        $relationshipTypes =
            $this->normalizeRelationshipTypeList(
                $relationshipTypes
            );

        $statuses = $this->normalizeStatusList(
            $statuses
        );

        $neighbours = [];

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            if (
                !$this->relationshipMatchesFilters(
                    $relationship,
                    $relationshipTypes,
                    $statuses,
                    $includeExpired
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

            $sourceType = $this->normalizeEntityType(
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

            $targetType = $this->normalizeEntityType(
                (string)(
                    $relationship['target_type']
                    ?? ''
                )
            );

            $sourceMatches =
                $sourceId === $entityId
                && (
                    $entityType === null
                    || $sourceType === $entityType
                );

            $targetMatches =
                $targetId === $entityId
                && (
                    $entityType === null
                    || $targetType === $entityType
                );

            if (
                $sourceMatches
                && in_array(
                    $direction,
                    ['outgoing', 'both'],
                    true
                )
            ) {
                $neighbourKey = $this->graphNodeKey(
                    $targetType,
                    $targetId
                );

                if ($neighbourKey !== '') {
                    $neighbours[] = [
                        'node_id' => $targetId,
                        'node_type' => $targetType,
                        'node_key' => $neighbourKey,
                        'direction' => 'outgoing',
                        'relationship' => $relationship,
                    ];
                }
            }

            if (
                $targetMatches
                && in_array(
                    $direction,
                    ['incoming', 'both'],
                    true
                )
            ) {
                $neighbourKey = $this->graphNodeKey(
                    $sourceType,
                    $sourceId
                );

                if ($neighbourKey !== '') {
                    $neighbours[] = [
                        'node_id' => $sourceId,
                        'node_type' => $sourceType,
                        'node_key' => $neighbourKey,
                        'direction' => 'incoming',
                        'relationship' => $relationship,
                    ];
                }
            }
        }

        return $this->uniqueNeighbourRecords(
            $neighbours
        );
    }

    /**
     * Return outgoing direct neighbours.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<int,array<string,mixed>>
     */
    public function outgoing(
        array $relationships,
        string $entityId,
        ?string $entityType = null,
        array $relationshipTypes = [],
        array $statuses = ['active', 'verified'],
        bool $includeExpired = false
    ): array {
        return $this->neighbours(
            $relationships,
            $entityId,
            $entityType,
            'outgoing',
            $relationshipTypes,
            $statuses,
            $includeExpired
        );
    }

    /**
     * Return incoming direct neighbours.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<int,array<string,mixed>>
     */
    public function incoming(
        array $relationships,
        string $entityId,
        ?string $entityType = null,
        array $relationshipTypes = [],
        array $statuses = ['active', 'verified'],
        bool $includeExpired = false
    ): array {
        return $this->neighbours(
            $relationships,
            $entityId,
            $entityType,
            'incoming',
            $relationshipTypes,
            $statuses,
            $includeExpired
        );
    }

    /**
     * Traverse the graph using breadth-first search.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @param array<int,string> $relationshipTypes
     * @param array<int,string> $statuses
     *
     * @return array<string,mixed>
     */
    public function traverse(
        array $relationships,
        string $startId,
        ?string $startType = null,
        int $maxDepth = 3,
        string $direction = 'both',
        array $relationshipTypes = [],
        array $statuses = ['active', 'verified'],
        bool $includeExpired = false,
        int $maxNodes = 1000
    ): array {
        $this->reset();

        $startId = trim($startId);
        $startType = $this->normalizeOptionalEntityType(
            $startType
        );
        $maxDepth = max(
            0,
            min(100, $maxDepth)
        );
        $maxNodes = max(
            1,
            min(100000, $maxNodes)
        );
        $direction = $this->normalizeDirection(
            $direction
        );

        if ($startId === '') {
            throw new InvalidArgumentException(
                'Traversal requires a starting entity ID.'
            );
        }

        $startNodeKey = $this->graphNodeKey(
            $startType ?? 'entity',
            $startId
        );

        if ($startNodeKey === '') {
            throw new InvalidArgumentException(
                'Traversal starting node is invalid.'
            );
        }

        $queue = [
            [
                'node_id' => $startId,
                'node_type' => $startType,
                'node_key' => $startNodeKey,
                'depth' => 0,
                'parent_key' => null,
                'via_relationship_id' => null,
            ],
        ];

        $visited = [];
        $nodes = [];
        $edges = [];
        $levels = [];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (!is_array($current)) {
                continue;
            }

            $currentKey = (string)$current['node_key'];
            $currentDepth = (int)$current['depth'];

            if (isset($visited[$currentKey])) {
                continue;
            }

            $visited[$currentKey] = true;
            $nodes[$currentKey] = $current;
            $levels[$currentDepth][] = $currentKey;

            if (
                count($nodes) >= $maxNodes
                || $currentDepth >= $maxDepth
            ) {
                continue;
            }

            $neighbours = $this->neighbours(
                $relationships,
                (string)$current['node_id'],
                is_string($current['node_type'])
                    ? $current['node_type']
                    : null,
                $direction,
                $relationshipTypes,
                $statuses,
                $includeExpired
            );

            foreach ($neighbours as $neighbour) {
                $neighbourKey = trim(
                    (string)(
                        $neighbour['node_key']
                        ?? ''
                    )
                );

                if ($neighbourKey === '') {
                    continue;
                }

                $relationship =
                    $neighbour['relationship']
                    ?? [];

                $relationshipId = trim(
                    (string)(
                        $relationship['relationship_id']
                        ?? ''
                    )
                );

                if ($relationshipId !== '') {
                    $edges[$relationshipId] =
                        $relationship;
                }

                if (isset($visited[$neighbourKey])) {
                    continue;
                }

                $queue[] = [
                    'node_id' =>
                        (string)$neighbour['node_id'],

                    'node_type' =>
                        (string)$neighbour['node_type'],

                    'node_key' =>
                        $neighbourKey,

                    'depth' =>
                        $currentDepth + 1,

                    'parent_key' =>
                        $currentKey,

                    'via_relationship_id' =>
                        $relationshipId !== ''
                            ? $relationshipId
                            : null,
                ];
            }
        }

        ksort($levels);

        $result = [
            'start' => [
                'node_id' => $startId,
                'node_type' => $startType,
                'node_key' => $startNodeKey,
            ],

            'direction' => $direction,

            'max_depth' => $maxDepth,

            'visited_count' => count($nodes),

            'edge_count' => count($edges),

            'truncated' =>
                count($nodes) >= $maxNodes,

            'nodes' => array_values($nodes),

            'edges' => array_values($edges),

            'levels' => $levels,
        ];

        $this->addMessage(
            'Graph traversal completed.',
            [
                'start_node' => $startNodeKey,
                'visited_count' => count($nodes),
                'edge_count' => count($edges),
                'max_depth' => $maxDepth,
            ]
        );

        return $result;
    }

    /**
     * Return every reachable node key.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<int,string>
     */
    public function reachable(
        array $relationships,
        string $startId,
        ?string $startType = null,
        int $maxDepth = 3,
        string $direction = 'both',
        array $relationshipTypes = [],
        array $statuses = ['active', 'verified'],
        bool $includeExpired = false
    ): array {
        $traversal = $this->traverse(
            $relationships,
            $startId,
            $startType,
            $maxDepth,
            $direction,
            $relationshipTypes,
            $statuses,
            $includeExpired
        );

        return array_values(
            array_filter(
                array_map(
                    static fn (
                        array $node
                    ): string => trim(
                        (string)(
                            $node['node_key']
                            ?? ''
                        )
                    ),
                    $traversal['nodes']
                )
            )
        );
    }

    /**
     * Return descendants using outgoing edges.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<string,mixed>
     */
    public function descendants(
        array $relationships,
        string $startId,
        ?string $startType = null,
        int $maxDepth = 10,
        array $relationshipTypes = [
            'parent_of',
            'contains',
            'has_member',
            'derives',
            'extends',
            'implements',
        ]
    ): array {
        return $this->traverse(
            $relationships,
            $startId,
            $startType,
            $maxDepth,
            'outgoing',
            $relationshipTypes
        );
    }

    /**
     * Return ancestors using incoming edges.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<string,mixed>
     */
    public function ancestors(
        array $relationships,
        string $startId,
        ?string $startType = null,
        int $maxDepth = 10,
        array $relationshipTypes = [
            'parent_of',
            'contains',
            'has_member',
            'derives',
            'extends',
            'implements',
        ]
    ): array {
        return $this->traverse(
            $relationships,
            $startId,
            $startType,
            $maxDepth,
            'incoming',
            $relationshipTypes
        );
    }

    /**
     * Build a nested traversal tree.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<string,mixed>
     */
    public function tree(
        array $relationships,
        string $startId,
        ?string $startType = null,
        int $maxDepth = 5,
        string $direction = 'outgoing',
        array $relationshipTypes = [],
        array $statuses = ['active', 'verified'],
        bool $includeExpired = false
    ): array {
        $traversal = $this->traverse(
            $relationships,
            $startId,
            $startType,
            $maxDepth,
            $direction,
            $relationshipTypes,
            $statuses,
            $includeExpired
        );

        $nodesByKey = [];

        foreach ($traversal['nodes'] as $node) {
            $nodeKey = trim(
                (string)(
                    $node['node_key']
                    ?? ''
                )
            );

            if ($nodeKey === '') {
                continue;
            }

            $nodesByKey[$nodeKey] = [
                'node_id' =>
                    $node['node_id'] ?? '',

                'node_type' =>
                    $node['node_type'] ?? '',

                'node_key' =>
                    $nodeKey,

                'depth' =>
                    $node['depth'] ?? 0,

                'via_relationship_id' =>
                    $node['via_relationship_id']
                    ?? null,

                'children' => [],
            ];
        }

        foreach ($traversal['nodes'] as $node) {
            $nodeKey = trim(
                (string)(
                    $node['node_key']
                    ?? ''
                )
            );

            $parentKey = trim(
                (string)(
                    $node['parent_key']
                    ?? ''
                )
            );

            if (
                $nodeKey === ''
                || $parentKey === ''
                || !isset($nodesByKey[$parentKey])
                || !isset($nodesByKey[$nodeKey])
            ) {
                continue;
            }

            $nodesByKey[$parentKey]['children'][] =
                &$nodesByKey[$nodeKey];
        }

        $rootKey = (string)(
            $traversal['start']['node_key']
            ?? ''
        );

        return $nodesByKey[$rootKey]
            ?? [
                'node_id' => $startId,
                'node_type' => $startType,
                'node_key' => $rootKey,
                'depth' => 0,
                'via_relationship_id' => null,
                'children' => [],
            ];
    }

    /**
     * Identify all connected components.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<int,array<string,mixed>>
     */
    public function connectedComponents(
        array $relationships,
        array $statuses = ['active', 'verified'],
        bool $includeExpired = false
    ): array {
        $adjacency = $this->buildAdjacency(
            $relationships,
            'both',
            [],
            $statuses,
            $includeExpired
        );

        $visited = [];
        $components = [];

        foreach (array_keys($adjacency) as $startKey) {
            if (isset($visited[$startKey])) {
                continue;
            }

            $queue = [$startKey];
            $componentNodes = [];
            $componentEdges = [];

            while ($queue !== []) {
                $currentKey = array_shift($queue);

                if (
                    $currentKey === null
                    || isset($visited[$currentKey])
                ) {
                    continue;
                }

                $visited[$currentKey] = true;
                $componentNodes[] = $currentKey;

                foreach (
                    $adjacency[$currentKey] ?? []
                    as $connection
                ) {
                    $neighbourKey = trim(
                        (string)(
                            $connection['node_key']
                            ?? ''
                        )
                    );

                    $relationshipId = trim(
                        (string)(
                            $connection['relationship_id']
                            ?? ''
                        )
                    );

                    if ($relationshipId !== '') {
                        $componentEdges[$relationshipId] =
                            true;
                    }

                    if (
                        $neighbourKey !== ''
                        && !isset($visited[$neighbourKey])
                    ) {
                        $queue[] = $neighbourKey;
                    }
                }
            }

            $components[] = [
                'component_id' =>
                    'CMP-' . str_pad(
                        (string)(
                            count($components) + 1
                        ),
                        4,
                        '0',
                        STR_PAD_LEFT
                    ),

                'node_count' =>
                    count($componentNodes),

                'edge_count' =>
                    count($componentEdges),

                'nodes' =>
                    $componentNodes,

                'relationship_ids' =>
                    array_keys($componentEdges),
            ];
        }

        usort(
            $components,
            static fn (
                array $left,
                array $right
            ): int =>
                ($right['node_count'] ?? 0)
                <=>
                ($left['node_count'] ?? 0)
        );

        return $components;
    }

    /**
     * Detect directed cycles.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<int,array<int,string>>
     */
    public function cycles(
        array $relationships,
        array $relationshipTypes = [],
        array $statuses = ['active', 'verified'],
        bool $includeExpired = false,
        int $maximumCycles = 1000
    ): array {
        $maximumCycles = max(
            1,
            min(100000, $maximumCycles)
        );

        $adjacency = $this->buildAdjacency(
            $relationships,
            'outgoing',
            $relationshipTypes,
            $statuses,
            $includeExpired
        );

        $cycles = [];
        $cycleKeys = [];
        $path = [];
        $pathIndex = [];
        $completed = [];

        $visit = function (
            string $nodeKey
        ) use (
            &$visit,
            &$cycles,
            &$cycleKeys,
            &$path,
            &$pathIndex,
            &$completed,
            $adjacency,
            $maximumCycles
        ): void {
            if (count($cycles) >= $maximumCycles) {
                return;
            }

            if (isset($completed[$nodeKey])) {
                return;
            }

            if (isset($pathIndex[$nodeKey])) {
                $cycle = array_slice(
                    $path,
                    $pathIndex[$nodeKey]
                );

                $cycle[] = $nodeKey;

                $canonicalKey =
                    $this->canonicalCycleKey(
                        $cycle
                    );

                if (
                    $canonicalKey !== ''
                    && !isset(
                        $cycleKeys[$canonicalKey]
                    )
                ) {
                    $cycleKeys[$canonicalKey] = true;
                    $cycles[] = $cycle;
                }

                return;
            }

            $pathIndex[$nodeKey] = count($path);
            $path[] = $nodeKey;

            foreach (
                $adjacency[$nodeKey] ?? []
                as $connection
            ) {
                $neighbourKey = trim(
                    (string)(
                        $connection['node_key']
                        ?? ''
                    )
                );

                if ($neighbourKey !== '') {
                    $visit($neighbourKey);
                }
            }

            array_pop($path);
            unset($pathIndex[$nodeKey]);
            $completed[$nodeKey] = true;
        };

        foreach (array_keys($adjacency) as $nodeKey) {
            $visit($nodeKey);

            if (count($cycles) >= $maximumCycles) {
                break;
            }
        }

        return $cycles;
    }

    /**
     * Determine whether one node can reach another.
     *
     * @param array<int,array<string,mixed>> $relationships
     */
    public function canReach(
        array $relationships,
        string $sourceId,
        string $targetId,
        ?string $sourceType = null,
        ?string $targetType = null,
        int $maxDepth = 10,
        string $direction = 'outgoing',
        array $relationshipTypes = [],
        array $statuses = ['active', 'verified'],
        bool $includeExpired = false
    ): bool {
        $sourceId = trim($sourceId);
        $targetId = trim($targetId);

        if (
            $sourceId === ''
            || $targetId === ''
        ) {
            return false;
        }

        $targetType =
            $this->normalizeOptionalEntityType(
                $targetType
            );

        $targetKeys = [];

        if ($targetType !== null) {
            $targetKeys[] = $this->graphNodeKey(
                $targetType,
                $targetId
            );
        }

        $traversal = $this->traverse(
            $relationships,
            $sourceId,
            $sourceType,
            $maxDepth,
            $direction,
            $relationshipTypes,
            $statuses,
            $includeExpired
        );

        foreach ($traversal['nodes'] as $node) {
            $nodeId = trim(
                (string)(
                    $node['node_id']
                    ?? ''
                )
            );

            $nodeType = $this->normalizeEntityType(
                (string)(
                    $node['node_type']
                    ?? ''
                )
            );

            if ($nodeId !== $targetId) {
                continue;
            }

            if (
                $targetType === null
                || $nodeType === $targetType
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return graph depth from one starting node.
     *
     * @param array<int,array<string,mixed>> $relationships
     */
    public function depth(
        array $relationships,
        string $startId,
        ?string $startType = null,
        string $direction = 'outgoing',
        array $relationshipTypes = [],
        array $statuses = ['active', 'verified'],
        bool $includeExpired = false
    ): int {
        $traversal = $this->traverse(
            $relationships,
            $startId,
            $startType,
            100,
            $direction,
            $relationshipTypes,
            $statuses,
            $includeExpired
        );

        $depths = array_map(
            static fn (
                array $node
            ): int => (int)(
                $node['depth']
                ?? 0
            ),
            $traversal['nodes']
        );

        return $depths === []
            ? 0
            : max($depths);
    }

    /**
     * Build an adjacency list.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @param array<int,string> $relationshipTypes
     * @param array<int,string> $statuses
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function buildAdjacency(
        array $relationships,
        string $direction = 'both',
        array $relationshipTypes = [],
        array $statuses = ['active', 'verified'],
        bool $includeExpired = false
    ): array {
        $direction = $this->normalizeDirection(
            $direction
        );

        $relationshipTypes =
            $this->normalizeRelationshipTypeList(
                $relationshipTypes
            );

        $statuses = $this->normalizeStatusList(
            $statuses
        );

        $adjacency = [];

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            if (
                !$this->relationshipMatchesFilters(
                    $relationship,
                    $relationshipTypes,
                    $statuses,
                    $includeExpired
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

            $sourceType = $this->normalizeEntityType(
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

            $targetType = $this->normalizeEntityType(
                (string)(
                    $relationship['target_type']
                    ?? ''
                )
            );

            $sourceKey = $this->graphNodeKey(
                $sourceType,
                $sourceId
            );

            $targetKey = $this->graphNodeKey(
                $targetType,
                $targetId
            );

            if (
                $sourceKey === ''
                || $targetKey === ''
            ) {
                continue;
            }

            $relationshipId = trim(
                (string)(
                    $relationship['relationship_id']
                    ?? ''
                )
            );

            $adjacency[$sourceKey] ??= [];
            $adjacency[$targetKey] ??= [];

            if (
                in_array(
                    $direction,
                    ['outgoing', 'both'],
                    true
                )
            ) {
                $adjacency[$sourceKey][] = [
                    'node_id' => $targetId,
                    'node_type' => $targetType,
                    'node_key' => $targetKey,
                    'direction' => 'outgoing',
                    'relationship_id' =>
                        $relationshipId,
                    'relationship_type' =>
                        $relationship[
                            'relationship_type'
                        ] ?? '',
                    'relationship' =>
                        $relationship,
                ];
            }

            if (
                in_array(
                    $direction,
                    ['incoming', 'both'],
                    true
                )
            ) {
                $adjacency[$targetKey][] = [
                    'node_id' => $sourceId,
                    'node_type' => $sourceType,
                    'node_key' => $sourceKey,
                    'direction' => 'incoming',
                    'relationship_id' =>
                        $relationshipId,
                    'relationship_type' =>
                        $relationship[
                            'relationship_type'
                        ] ?? '',
                    'relationship' =>
                        $relationship,
                ];
            }
        }

        ksort($adjacency);

        return $adjacency;
    }

    /**
     * Summarize graph traversal characteristics.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @return array<string,mixed>
     */
    public function summarize(
        array $relationships
    ): array {
        $adjacency = $this->buildAdjacency(
            $relationships,
            'both',
            [],
            [],
            true
        );

        $degrees = [];

        foreach ($adjacency as $nodeKey => $edges) {
            $degrees[$nodeKey] = count($edges);
        }

        arsort($degrees);

        $components = $this->connectedComponents(
            $relationships,
            [],
            true
        );

        return [
            'relationship_count' =>
                count($relationships),

            'node_count' =>
                count($adjacency),

            'component_count' =>
                count($components),

            'largest_component_size' =>
                $components !== []
                    ? (
                        $components[0]['node_count']
                        ?? 0
                    )
                    : 0,

            'maximum_degree' =>
                $degrees !== []
                    ? max($degrees)
                    : 0,

            'average_degree' =>
                $degrees !== []
                    ? round(
                        array_sum($degrees)
                        / count($degrees),
                        4
                    )
                    : 0.0,

            'degrees' => $degrees,
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
                'traversal_strategy' =>
                    'breadth_first_search',

                'supported_directions' => [
                    'outgoing',
                    'incoming',
                    'both',
                ],

                'cycle_detection' =>
                    true,

                'connected_components' =>
                    true,

                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Determine whether a relationship passes traversal filters.
     *
     * @param array<string,mixed> $relationship
     * @param array<int,string> $relationshipTypes
     * @param array<int,string> $statuses
     */
    private function relationshipMatchesFilters(
        array $relationship,
        array $relationshipTypes,
        array $statuses,
        bool $includeExpired
    ): bool {
        $relationshipType =
            $this->normalizeRelationshipType(
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
                $relationshipType,
                $relationshipTypes,
                true
            )
        ) {
            return false;
        }

        $status = strtolower(
            trim(
                (string)(
                    $relationship['status']
                    ?? ''
                )
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

        if (
            !$includeExpired
            && !$this->relationshipIsTemporallyActive(
                $relationship
            )
        ) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the relationship is valid at the current time.
     *
     * @param array<string,mixed> $relationship
     */
    private function relationshipIsTemporallyActive(
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
            $validFromTime = strtotime(
                $validFrom
            );

            if (
                $validFromTime !== false
                && $validFromTime > $now
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
            $validToTime = strtotime(
                $validTo
            );

            if (
                $validToTime !== false
                && $validToTime < $now
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize traversal direction.
     */
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

    /**
     * Normalize an optional entity type.
     */
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

    /**
     * Normalize an entity-type key.
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
     * Normalize relationship-type filters.
     *
     * @param array<int,string> $types
     * @return array<int,string>
     */
    private function normalizeRelationshipTypeList(
        array $types
    ): array {
        $normalized = [];

        foreach ($types as $type) {
            $type = $this->normalizeRelationshipType(
                (string)$type
            );

            if ($type !== '') {
                $normalized[$type] = $type;
            }
        }

        return array_values($normalized);
    }

    /**
     * Normalize status filters.
     *
     * @param array<int,string> $statuses
     * @return array<int,string>
     */
    private function normalizeStatusList(
        array $statuses
    ): array {
        $normalized = [];

        foreach ($statuses as $status) {
            $status = strtolower(
                trim(
                    (string)$status
                )
            );

            if ($status !== '') {
                $normalized[$status] = $status;
            }
        }

        return array_values($normalized);
    }

    /**
     * Deduplicate neighbour records by node, direction, and edge.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    private function uniqueNeighbourRecords(
        array $records
    ): array {
        $unique = [];

        foreach ($records as $record) {
            $relationship = is_array(
                $record['relationship']
                ?? null
            )
                ? $record['relationship']
                : [];

            $key = implode(
                '|',
                [
                    (string)(
                        $record['node_key']
                        ?? ''
                    ),

                    (string)(
                        $record['direction']
                        ?? ''
                    ),

                    (string)(
                        $relationship[
                            'relationship_id'
                        ]
                        ?? ''
                    ),
                ]
            );

            $unique[$key] = $record;
        }

        return array_values($unique);
    }

    /**
     * Create a stable key for a detected cycle.
     *
     * @param array<int,string> $cycle
     */
    private function canonicalCycleKey(
        array $cycle
    ): string {
        if (count($cycle) < 2) {
            return '';
        }

        if (
            $cycle[0]
            === $cycle[
                array_key_last($cycle)
            ]
        ) {
            array_pop($cycle);
        }

        if ($cycle === []) {
            return '';
        }

        $variants = [];
        $count = count($cycle);

        for ($index = 0; $index < $count; $index++) {
            $rotated = array_merge(
                array_slice($cycle, $index),
                array_slice($cycle, 0, $index)
            );

            $variants[] = implode(
                '>',
                $rotated
            );
        }

        sort($variants);

        return hash(
            'sha256',
            $variants[0]
        );
    }
}