<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/KnowledgeGraphService.php
|--------------------------------------------------------------------------
| IPMdb Knowledge Graph Service
|--------------------------------------------------------------------------
|
| Coordinates the complete IPMdb graph-services layer.
|
| Responsibilities:
| - Maintain an in-memory working graph.
| - Index entities and relationships.
| - Add, replace, remove, and retrieve graph records.
| - Search, traverse, inspect, analyze, compare, infer, and recommend.
| - Run consistency and repair inspections.
| - Execute registered graph rules.
| - Produce complete graph intelligence reports.
| - Preserve explicit boundaries between proposals and accepted records.
|
| This service performs no database operations.
|
| Repository persists.
| Services interpret.
| The graph connects.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/ValidationService.php';
require_once __DIR__ . '/RelationshipService.php';
require_once __DIR__ . '/GraphTraversalService.php';
require_once __DIR__ . '/PathService.php';
require_once __DIR__ . '/GraphAnalyticsService.php';
require_once __DIR__ . '/GraphRepairService.php';
require_once __DIR__ . '/GraphSearchService.php';
require_once __DIR__ . '/RelationshipSuggestionService.php';
require_once __DIR__ . '/InferenceService.php';
require_once __DIR__ . '/ConsistencyService.php';
require_once __DIR__ . '/RuleEngineService.php';
require_once __DIR__ . '/SimilarityService.php';
require_once __DIR__ . '/RecommendationService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class KnowledgeGraphService extends Service
{
    use GraphUtilities;

    private ValidationService $validation;

    private RelationshipService $relationshipService;

    private GraphTraversalService $traversal;

    private PathService $paths;

    private GraphAnalyticsService $analytics;

    private GraphRepairService $repair;

    private GraphSearchService $search;

    private RelationshipSuggestionService $suggestions;

    private InferenceService $inference;

    private ConsistencyService $consistency;

    private RuleEngineService $rules;

    private SimilarityService $similarity;

    private RecommendationService $recommendations;

    /**
     * @var array<string,array<string,mixed>>
     */
    private array $entities = [];

    /**
     * @var array<string,array<string,mixed>>
     */
    private array $relationships = [];

    /**
     * @var array<string,array<string,bool>>
     */
    private array $outgoingIndex = [];

    /**
     * @var array<string,array<string,bool>>
     */
    private array $incomingIndex = [];

    /**
     * @var array<string,array<string,bool>>
     */
    private array $entityTypeIndex = [];

    /**
     * @var array<string,array<string,bool>>
     */
    private array $relationshipTypeIndex = [];

    private int $revision = 0;

    private bool $indexesCurrent = true;

    private string $loadedAt;

    public function __construct(
        array $config = [],
        array $context = [],
        ?ValidationService $validation = null,
        ?RelationshipService $relationshipService = null,
        ?GraphTraversalService $traversal = null,
        ?PathService $paths = null,
        ?GraphAnalyticsService $analytics = null,
        ?GraphRepairService $repair = null,
        ?GraphSearchService $search = null,
        ?RelationshipSuggestionService $suggestions = null,
        ?InferenceService $inference = null,
        ?ConsistencyService $consistency = null,
        ?RuleEngineService $rules = null,
        ?SimilarityService $similarity = null,
        ?RecommendationService $recommendations = null
    ) {
        parent::__construct($config, $context);

        $this->validation = $validation
            ?? new ValidationService();

        $this->relationshipService = $relationshipService
            ?? new RelationshipService();

        $this->traversal = $traversal
            ?? new GraphTraversalService();

        $this->paths = $paths
            ?? new PathService();

        $this->analytics = $analytics
            ?? new GraphAnalyticsService();

        $this->repair = $repair
            ?? new GraphRepairService();

        $this->search = $search
            ?? new GraphSearchService();

        $this->suggestions = $suggestions
            ?? new RelationshipSuggestionService();

        $this->inference = $inference
            ?? new InferenceService();

        $this->consistency = $consistency
            ?? new ConsistencyService();

        $this->rules = $rules
            ?? new RuleEngineService();

        $this->similarity = $similarity
            ?? new SimilarityService();

        $this->recommendations = $recommendations
            ?? new RecommendationService();

        $this->loadedAt = gmdate('c');

        if (
            isset($config['entities'])
            && is_array($config['entities'])
        ) {
            $this->loadEntities(
                $config['entities'],
                false
            );
        }

        if (
            isset($config['relationships'])
            && is_array($config['relationships'])
        ) {
            $this->loadRelationships(
                $config['relationships'],
                false
            );
        }

        $this->rebuildIndexes();
    }

    /**
     * Replace or merge the entire working graph.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    public function load(
        array $entities,
        array $relationships,
        bool $replace = true
    ): array {
        $this->reset();

        if ($replace) {
            $this->clear();
        }

        $entityResult = $this->loadEntities(
            $entities,
            false
        );

        $relationshipResult =
            $this->loadRelationships(
                $relationships,
                false
            );

        $this->rebuildIndexes();

        $this->revision++;
        $this->loadedAt = gmdate('c');

        return [
            'loaded_at' => $this->loadedAt,

            'revision' => $this->revision,

            'entities' => $entityResult,

            'relationships' =>
                $relationshipResult,

            'summary' => $this->summary(),
        ];
    }

    /**
     * Load entities.
     *
     * @param array<int,array<string,mixed>> $entities
     *
     * @return array<string,int>
     */
    public function loadEntities(
        array $entities,
        bool $rebuildIndexes = true
    ): array {
        $added = 0;
        $replaced = 0;
        $skipped = 0;

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                $skipped++;
                continue;
            }

            $key = $this->entityKey($entity);

            if ($key === '') {
                $skipped++;
                continue;
            }

            if (isset($this->entities[$key])) {
                $replaced++;
            } else {
                $added++;
            }

            $this->entities[$key] = $entity;
        }

        $this->indexesCurrent = false;

        if ($rebuildIndexes) {
            $this->rebuildIndexes();
            $this->revision++;
        }

        return [
            'added' => $added,
            'replaced' => $replaced,
            'skipped' => $skipped,
            'total' => count($this->entities),
        ];
    }

    /**
     * Load relationships.
     *
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,int>
     */
    public function loadRelationships(
        array $relationships,
        bool $rebuildIndexes = true
    ): array {
        $added = 0;
        $replaced = 0;
        $skipped = 0;

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                $skipped++;
                continue;
            }

            $relationshipId = trim(
                (string)(
                    $relationship['relationship_id']
                    ?? ''
                )
            );

            if ($relationshipId === '') {
                $skipped++;
                continue;
            }

            if (
                isset(
                    $this->relationships[
                        $relationshipId
                    ]
                )
            ) {
                $replaced++;
            } else {
                $added++;
            }

            $this->relationships[
                $relationshipId
            ] = $relationship;
        }

        $this->indexesCurrent = false;

        if ($rebuildIndexes) {
            $this->rebuildIndexes();
            $this->revision++;
        }

        return [
            'added' => $added,
            'replaced' => $replaced,
            'skipped' => $skipped,
            'total' =>
                count($this->relationships),
        ];
    }

    /**
     * Add or replace one entity.
     *
     * @param array<string,mixed> $entity
     */
    public function putEntity(
        array $entity
    ): array {
        $key = $this->entityKey($entity);

        if ($key === '') {
            throw new InvalidArgumentException(
                'Entity requires a type and public identifier.'
            );
        }

        $replaced = isset(
            $this->entities[$key]
        );

        $this->entities[$key] = $entity;

        $this->rebuildIndexes();
        $this->revision++;

        return [
            'entity_key' => $key,
            'replaced' => $replaced,
            'entity' => $entity,
            'revision' => $this->revision,
        ];
    }

    /**
     * Create and add one relationship.
     *
     * @param array<string,mixed> $input
     */
    public function createRelationship(
        array $input
    ): array {
        $relationship =
            $this->relationshipService->create(
                $input
            );

        return $this->putRelationship(
            $relationship
        )['relationship'];
    }

    /**
     * Add or replace one relationship.
     *
     * @param array<string,mixed> $relationship
     */
    public function putRelationship(
        array $relationship
    ): array {
        $relationshipId = trim(
            (string)(
                $relationship['relationship_id']
                ?? ''
            )
        );

        if ($relationshipId === '') {
            throw new InvalidArgumentException(
                'Relationship requires a public identifier.'
            );
        }

        $replaced = isset(
            $this->relationships[
                $relationshipId
            ]
        );

        $this->relationships[
            $relationshipId
        ] = $relationship;

        $this->rebuildIndexes();
        $this->revision++;

        return [
            'relationship_id' =>
                $relationshipId,

            'replaced' => $replaced,

            'relationship' =>
                $relationship,

            'revision' => $this->revision,
        ];
    }

    /**
     * Remove one entity.
     *
     * Relationships are retained unless cascade is explicitly enabled.
     */
    public function removeEntity(
        string $entityId,
        ?string $entityType = null,
        bool $cascade = false
    ): array {
        $key = $this->resolveEntityKey(
            $entityId,
            $entityType
        );

        if (
            $key === null
            || !isset($this->entities[$key])
        ) {
            return [
                'removed' => false,
                'entity_key' => $key,
                'relationship_count' => 0,
            ];
        }

        unset($this->entities[$key]);

        $removedRelationships = [];

        if ($cascade) {
            foreach (
                $this->relationships
                as $relationshipId => $relationship
            ) {
                if (
                    $this->relationshipSourceKey(
                        $relationship
                    ) === $key
                    || $this->relationshipTargetKey(
                        $relationship
                    ) === $key
                ) {
                    $removedRelationships[] =
                        $relationshipId;

                    unset(
                        $this->relationships[
                            $relationshipId
                        ]
                    );
                }
            }
        }

        $this->rebuildIndexes();
        $this->revision++;

        return [
            'removed' => true,

            'entity_key' => $key,

            'cascade' => $cascade,

            'relationship_count' =>
                count($removedRelationships),

            'removed_relationship_ids' =>
                $removedRelationships,

            'revision' => $this->revision,
        ];
    }

    /**
     * Remove one relationship.
     */
    public function removeRelationship(
        string $relationshipId
    ): bool {
        $relationshipId = trim(
            $relationshipId
        );

        if (
            $relationshipId === ''
            || !isset(
                $this->relationships[
                    $relationshipId
                ]
            )
        ) {
            return false;
        }

        unset(
            $this->relationships[
                $relationshipId
            ]
        );

        $this->rebuildIndexes();
        $this->revision++;

        return true;
    }

    /**
     * Return one entity.
     *
     * @return array<string,mixed>|null
     */
    public function entity(
        string $entityId,
        ?string $entityType = null
    ): ?array {
        $key = $this->resolveEntityKey(
            $entityId,
            $entityType
        );

        return $key !== null
            ? (
                $this->entities[$key]
                ?? null
            )
            : null;
    }

    /**
     * Return one relationship.
     *
     * @return array<string,mixed>|null
     */
    public function relationship(
        string $relationshipId
    ): ?array {
        return $this->relationships[
            trim($relationshipId)
        ] ?? null;
    }

    /**
     * Return all entities.
     *
     * @return array<int,array<string,mixed>>
     */
    public function entities(
        ?string $entityType = null
    ): array {
        if ($entityType === null) {
            return array_values(
                $this->entities
            );
        }

        $entityType =
            $this->normalizeMachineKey(
                $entityType
            );

        $this->ensureIndexes();

        $keys = array_keys(
            $this->entityTypeIndex[
                $entityType
            ] ?? []
        );

        $results = [];

        foreach ($keys as $key) {
            if (isset($this->entities[$key])) {
                $results[] =
                    $this->entities[$key];
            }
        }

        return $results;
    }

    /**
     * Return all relationships.
     *
     * @return array<int,array<string,mixed>>
     */
    public function relationships(
        ?string $relationshipType = null
    ): array {
        if ($relationshipType === null) {
            return array_values(
                $this->relationships
            );
        }

        $relationshipType =
            $this->normalizeMachineKey(
                $relationshipType
            );

        $this->ensureIndexes();

        $ids = array_keys(
            $this->relationshipTypeIndex[
                $relationshipType
            ] ?? []
        );

        $results = [];

        foreach ($ids as $id) {
            if (
                isset(
                    $this->relationships[$id]
                )
            ) {
                $results[] =
                    $this->relationships[$id];
            }
        }

        return $results;
    }

    /**
     * Return relationships connected to one entity.
     *
     * @return array<int,array<string,mixed>>
     */
    public function connectedRelationships(
        string $entityId,
        ?string $entityType = null,
        string $direction = 'both'
    ): array {
        $this->ensureIndexes();

        $key = $this->resolveEntityKey(
            $entityId,
            $entityType
        );

        if ($key === null) {
            return [];
        }

        $relationshipIds = [];

        if (
            $direction === 'outgoing'
            || $direction === 'both'
        ) {
            foreach (
                $this->outgoingIndex[$key]
                    ?? []
                as $relationshipId => $_
            ) {
                $relationshipIds[
                    $relationshipId
                ] = true;
            }
        }

        if (
            $direction === 'incoming'
            || $direction === 'both'
        ) {
            foreach (
                $this->incomingIndex[$key]
                    ?? []
                as $relationshipId => $_
            ) {
                $relationshipIds[
                    $relationshipId
                ] = true;
            }
        }

        $results = [];

        foreach (
            array_keys($relationshipIds)
            as $relationshipId
        ) {
            if (
                isset(
                    $this->relationships[
                        $relationshipId
                    ]
                )
            ) {
                $results[] =
                    $this->relationships[
                        $relationshipId
                    ];
            }
        }

        return $results;
    }

    /**
     * Search the working graph.
     *
     * @return array<string,mixed>
     */
    public function search(
        string $query,
        array $options = []
    ): array {
        return $this->search->search(
            $query,
            $this->entities(),
            $this->relationships(),
            $options
        );
    }

    /**
     * Traverse from one entity.
     *
     * @return array<string,mixed>
     */
    public function traverse(
        string $entityId,
        ?string $entityType = null,
        int $depth = 2,
        string $direction = 'both',
        array $relationshipTypes = [],
        array $statuses = [
            'active',
            'verified',
        ],
        bool $includeExpired = false,
        int $maximumNodes = 5000
    ): array {
        return $this->traversal->traverse(
            $this->relationships(),
            $entityId,
            $entityType,
            $depth,
            $direction,
            $relationshipTypes,
            $statuses,
            $includeExpired,
            $maximumNodes
        );
    }

    /**
     * Return shortest path.
     *
     * @return array<string,mixed>|null
     */
    public function shortestPath(
        string $sourceId,
        ?string $sourceType,
        string $targetId,
        ?string $targetType,
        array $options = []
    ): ?array {
        return $this->paths->shortestPath(
            $this->relationships(),
            $sourceId,
            $sourceType,
            $targetId,
            $targetType,
            (string)(
                $options['direction']
                ?? 'both'
            ),
            $this->normalizeStringList(
                $options[
                    'relationship_types'
                ] ?? []
            ),
            $this->normalizeStringList(
                $options['statuses']
                ?? [
                    'active',
                    'verified',
                ]
            ),
            (bool)(
                $options['include_expired']
                ?? false
            ),
            (int)(
                $options['maximum_depth']
                ?? 12
            )
        );
    }

    /**
     * Analyze the complete working graph.
     *
     * @return array<string,mixed>
     */
    public function analyze(
        array $options = []
    ): array {
        return $this->analytics->analyze(
            $this->entities(),
            $this->relationships(),
            $options
        );
    }

    /**
     * Inspect consistency.
     *
     * @return array<string,mixed>
     */
    public function inspectConsistency(
        array $options = []
    ): array {
        return $this->consistency->inspect(
            $this->entities(),
            $this->relationships(),
            $options
        );
    }

    /**
     * Inspect graph repair opportunities.
     *
     * @return array<string,mixed>
     */
    public function inspectRepair(
        array $options = []
    ): array {
        return $this->repair->inspect(
            $this->relationships(),
            $options
        );
    }

    /**
     * Apply safe relationship repairs to the working graph.
     *
     * @return array<string,mixed>
     */
    public function repairSafe(
        string $actorId,
        array $options = []
    ): array {
        $result = $this->repair->repairSafe(
            $this->relationships(),
            $actorId,
            $options
        );

        $repairedRelationships =
            $result['relationships']
            ?? $result['repaired_relationships']
            ?? null;

        if (is_array($repairedRelationships)) {
            $this->relationships = [];

            $this->loadRelationships(
                $repairedRelationships,
                false
            );

            $this->rebuildIndexes();
            $this->revision++;
        }

        $result['graph_revision'] =
            $this->revision;

        return $result;
    }

    /**
     * Compare two graph entities.
     *
     * @return array<string,mixed>
     */
    public function compareEntities(
        string $leftId,
        ?string $leftType,
        string $rightId,
        ?string $rightType,
        array $options = []
    ): array {
        $left = $this->entity(
            $leftId,
            $leftType
        );

        $right = $this->entity(
            $rightId,
            $rightType
        );

        if ($left === null || $right === null) {
            throw new RuntimeException(
                'Both entities must exist in the working graph.'
            );
        }

        return $this->similarity->compare(
            $left,
            $right,
            $this->relationships(),
            $options
        );
    }

    /**
     * Recommend entities related to one focus entity.
     *
     * @return array<string,mixed>
     */
    public function recommendForEntity(
        string $entityId,
        ?string $entityType = null,
        array $options = []
    ): array {
        $focus = $this->entity(
            $entityId,
            $entityType
        );

        if ($focus === null) {
            throw new RuntimeException(
                'Focus entity was not found.'
            );
        }

        return $this->recommendations
            ->recommendEntities(
                $focus,
                $this->entities(),
                $this->relationships(),
                $options
            );
    }

    /**
     * Recommend Government Program Alignment.
     *
     * @return array<string,mixed>
     */
    public function recommendGovernmentProgramAlignment(
        string $entityId,
        ?string $entityType = null,
        array $options = []
    ): array {
        $focus = $this->entity(
            $entityId,
            $entityType
        );

        if ($focus === null) {
            throw new RuntimeException(
                'Focus entity was not found.'
            );
        }

        return $this->recommendations
            ->recommendGovernmentProgramAlignment(
                $focus,
                $this->entities(),
                $this->relationships(),
                $options
            );
    }

    /**
     * Suggest relationships for one entity.
     *
     * @return array<string,mixed>
     */
    public function suggestRelationships(
        string $entityId,
        ?string $entityType = null,
        array $options = []
    ): array {
        $focus = $this->entity(
            $entityId,
            $entityType
        );

        if ($focus === null) {
            throw new RuntimeException(
                'Focus entity was not found.'
            );
        }

        return $this->suggestions
            ->suggestForEntity(
                $focus,
                $this->entities(),
                $this->relationships(),
                $options
            );
    }

    /**
     * Generate graph inferences.
     *
     * @return array<string,mixed>
     */
    public function infer(
        array $options = []
    ): array {
        return $this->inference->infer(
            $this->relationships(),
            $options
        );
    }

    /**
     * Accept one inference and add its relationship.
     *
     * @param array<string,mixed> $inference
     *
     * @return array<string,mixed>
     */
    public function acceptInference(
        array $inference,
        string $actorId,
        array $overrides = []
    ): array {
        $relationship =
            $this->inference->accept(
                $inference,
                $actorId,
                $overrides
            );

        $this->putRelationship(
            $relationship
        );

        return $relationship;
    }

    /**
     * Accept one relationship suggestion and add it.
     *
     * @param array<string,mixed> $suggestion
     *
     * @return array<string,mixed>
     */
    public function acceptSuggestion(
        array $suggestion,
        string $actorId,
        array $overrides = []
    ): array {
        $relationship =
            $this->suggestions->accept(
                $suggestion,
                $actorId,
                $overrides
            );

        $this->putRelationship(
            $relationship
        );

        return $relationship;
    }

    /**
     * Register one graph rule.
     *
     * @param array<string,mixed> $rule
     *
     * @return array<string,mixed>
     */
    public function registerRule(
        array $rule,
        bool $replaceExisting = false
    ): array {
        return $this->rules->register(
            $rule,
            $replaceExisting
        );
    }

    /**
     * Execute registered graph rules.
     *
     * @param array<int,array<string,mixed>> $facts
     *
     * @return array<string,mixed>
     */
    public function runRules(
        array $facts = [],
        array $options = []
    ): array {
        return $this->rules->run(
            $this->entities(),
            $this->relationships(),
            $facts,
            $options
        );
    }

    /**
     * Produce one complete graph intelligence report.
     *
     * @return array<string,mixed>
     */
    public function intelligenceReport(
        array $options = []
    ): array {
        $includeAnalytics = (bool)(
            $options['include_analytics']
            ?? true
        );

        $includeConsistency = (bool)(
            $options['include_consistency']
            ?? true
        );

        $includeRepair = (bool)(
            $options['include_repair']
            ?? true
        );

        $includeInference = (bool)(
            $options['include_inference']
            ?? true
        );

        $includeRecommendations = (bool)(
            $options['include_recommendations']
            ?? true
        );

        $report = [
            'report_id' =>
                $this->generateReportId(),

            'generated_at' => gmdate('c'),

            'graph' => $this->summary(),

            'analytics' => null,

            'consistency' => null,

            'repair' => null,

            'inference' => null,

            'recommendations' => null,
        ];

        if ($includeAnalytics) {
            $report['analytics'] =
                $this->analyze(
                    $options['analytics']
                    ?? []
                );
        }

        if ($includeConsistency) {
            $report['consistency'] =
                $this->inspectConsistency(
                    $options['consistency']
                    ?? []
                );
        }

        if ($includeRepair) {
            $report['repair'] =
                $this->inspectRepair(
                    $options['repair']
                    ?? []
                );
        }

        if ($includeInference) {
            $report['inference'] =
                $this->infer(
                    $options['inference']
                    ?? []
                );
        }

        if ($includeRecommendations) {
            $report['recommendations'] =
                $this->recommendations
                    ->recommendActions(
                        $this->entities(),
                        $this->relationships(),
                        $options[
                            'recommendations'
                        ] ?? []
                    );
        }

        $report['health'] =
            $this->calculateHealth($report);

        return $report;
    }

    /**
     * Export the complete working graph.
     *
     * @return array<string,mixed>
     */
    public function export(): array
    {
        return [
            'exported_at' => gmdate('c'),

            'revision' => $this->revision,

            'entities' =>
                $this->entities(),

            'relationships' =>
                $this->relationships(),

            'summary' => $this->summary(),
        ];
    }

    /**
     * Return graph summary.
     *
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $this->ensureIndexes();

        return [
            'revision' => $this->revision,

            'loaded_at' => $this->loadedAt,

            'entity_count' =>
                count($this->entities),

            'relationship_count' =>
                count($this->relationships),

            'entity_types' =>
                $this->indexCounts(
                    $this->entityTypeIndex
                ),

            'relationship_types' =>
                $this->indexCounts(
                    $this->relationshipTypeIndex
                ),

            'node_count_with_outgoing_edges' =>
                count($this->outgoingIndex),

            'node_count_with_incoming_edges' =>
                count($this->incomingIndex),

            'indexes_current' =>
                $this->indexesCurrent,
        ];
    }

    /**
     * Clear the working graph.
     */
    public function clear(): void
    {
        $this->entities = [];
        $this->relationships = [];
        $this->outgoingIndex = [];
        $this->incomingIndex = [];
        $this->entityTypeIndex = [];
        $this->relationshipTypeIndex = [];
        $this->indexesCurrent = true;
        $this->revision++;
        $this->loadedAt = gmdate('c');
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
                'graph' => $this->summary(),

                'services' => [
                    'validation' =>
                        $this->validation
                            ->diagnostics(),

                    'relationships' =>
                        $this->relationshipService
                            ->diagnostics(),

                    'traversal' =>
                        $this->traversal
                            ->diagnostics(),

                    'paths' =>
                        $this->paths
                            ->diagnostics(),

                    'analytics' =>
                        $this->analytics
                            ->diagnostics(),

                    'repair' =>
                        $this->repair
                            ->diagnostics(),

                    'search' =>
                        $this->search
                            ->diagnostics(),

                    'suggestions' =>
                        $this->suggestions
                            ->diagnostics(),

                    'inference' =>
                        $this->inference
                            ->diagnostics(),

                    'consistency' =>
                        $this->consistency
                            ->diagnostics(),

                    'rules' =>
                        $this->rules
                            ->diagnostics(),

                    'similarity' =>
                        $this->similarity
                            ->diagnostics(),

                    'recommendations' =>
                        $this->recommendations
                            ->diagnostics(),
                ],

                'database_operations' =>
                    false,

                'automatic_persistence' =>
                    false,

                'human_acceptance_preserved' =>
                    true,

                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Rebuild all graph indexes.
     */
    private function rebuildIndexes(): void
    {
        $this->outgoingIndex = [];
        $this->incomingIndex = [];
        $this->entityTypeIndex = [];
        $this->relationshipTypeIndex = [];

        foreach (
            $this->entities
            as $key => $entity
        ) {
            $type = $this->resolveEntityType(
                $entity
            );

            $this->entityTypeIndex[
                $type
            ][$key] = true;
        }

        foreach (
            $this->relationships
            as $relationshipId => $relationship
        ) {
            $sourceKey =
                $this->relationshipSourceKey(
                    $relationship
                );

            $targetKey =
                $this->relationshipTargetKey(
                    $relationship
                );

            $relationshipType =
                $this->normalizeMachineKey(
                    (string)(
                        $relationship[
                            'relationship_type'
                        ] ?? 'related_to'
                    )
                );

            if ($sourceKey !== '') {
                $this->outgoingIndex[
                    $sourceKey
                ][$relationshipId] = true;
            }

            if ($targetKey !== '') {
                $this->incomingIndex[
                    $targetKey
                ][$relationshipId] = true;
            }

            if ($relationshipType !== '') {
                $this->relationshipTypeIndex[
                    $relationshipType
                ][$relationshipId] = true;
            }
        }

        $this->indexesCurrent = true;
    }

    /**
     * Ensure graph indexes are current.
     */
    private function ensureIndexes(): void
    {
        if (!$this->indexesCurrent) {
            $this->rebuildIndexes();
        }
    }

    /**
     * Resolve one entity key.
     */
    private function resolveEntityKey(
        string $entityId,
        ?string $entityType = null
    ): ?string {
        $entityId = trim($entityId);

        if ($entityId === '') {
            return null;
        }

        if ($entityType !== null) {
            $key = $this->graphNodeKey(
                $this->normalizeMachineKey(
                    $entityType
                ),
                $entityId
            );

            return isset($this->entities[$key])
                ? $key
                : null;
        }

        $matches = [];

        foreach ($this->entities as $key => $entity) {
            if (
                $this->resolveEntityId($entity)
                === $entityId
            ) {
                $matches[] = $key;
            }
        }

        return count($matches) === 1
            ? $matches[0]
            : null;
    }

    /**
     * Build canonical entity key.
     *
     * @param array<string,mixed> $entity
     */
    private function entityKey(
        array $entity
    ): string {
        return $this->graphNodeKey(
            $this->resolveEntityType($entity),
            $this->resolveEntityId($entity)
        );
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
                'program_id',
                'decision_id',
                'mission_id',
                'organization_id',
                'person_id',
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
        $type = $this->normalizeMachineKey(
            (string)(
                $entity['entity_type']
                ?? $entity['type']
                ?? 'entity'
            )
        );

        return $type !== ''
            ? $type
            : 'entity';
    }

    /**
     * Return relationship source key.
     *
     * @param array<string,mixed> $relationship
     */
    private function relationshipSourceKey(
        array $relationship
    ): string {
        return $this->graphNodeKey(
            $this->normalizeMachineKey(
                (string)(
                    $relationship[
                        'source_type'
                    ] ?? 'entity'
                )
            ),
            trim(
                (string)(
                    $relationship[
                        'source_id'
                    ] ?? ''
                )
            )
        );
    }

    /**
     * Return relationship target key.
     *
     * @param array<string,mixed> $relationship
     */
    private function relationshipTargetKey(
        array $relationship
    ): string {
        return $this->graphNodeKey(
            $this->normalizeMachineKey(
                (string)(
                    $relationship[
                        'target_type'
                    ] ?? 'entity'
                )
            ),
            trim(
                (string)(
                    $relationship[
                        'target_id'
                    ] ?? ''
                )
            )
        );
    }

    /**
     * Convert index maps into counts.
     *
     * @param array<string,array<string,bool>> $index
     * @return array<string,int>
     */
    private function indexCounts(
        array $index
    ): array {
        $counts = [];

        foreach ($index as $name => $items) {
            $counts[$name] = count($items);
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Calculate top-level graph health.
     *
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function calculateHealth(
        array $report
    ): array {
        $score = 100.0;
        $signals = [];

        $consistency = $report[
            'consistency'
        ] ?? [];

        $blocking = (int)(
            $consistency['summary']
                ['blocking_count']
            ?? 0
        );

        if ($blocking > 0) {
            $penalty = min(
                50.0,
                $blocking * 8.0
            );

            $score -= $penalty;

            $signals[] = [
                'signal' =>
                    'blocking_consistency_findings',

                'value' => $blocking,

                'penalty' => $penalty,
            ];
        }

        $repair = $report['repair'] ?? [];

        $repairCount = (int)(
            $repair['finding_count']
            ?? $repair['issue_count']
            ?? 0
        );

        if ($repairCount > 0) {
            $penalty = min(
                20.0,
                $repairCount * 2.0
            );

            $score -= $penalty;

            $signals[] = [
                'signal' =>
                    'repair_opportunities',

                'value' => $repairCount,

                'penalty' => $penalty,
            ];
        }

        $entityCount =
            count($this->entities);

        $relationshipCount =
            count($this->relationships);

        if (
            $entityCount > 1
            && $relationshipCount === 0
        ) {
            $score -= 15.0;

            $signals[] = [
                'signal' =>
                    'unconnected_graph',

                'value' => true,

                'penalty' => 15.0,
            ];
        }

        $score = max(
            0.0,
            min(100.0, $score)
        );

        return [
            'score' => round($score, 2),

            'classification' =>
                match (true) {
                    $score >= 90 =>
                        'excellent',

                    $score >= 75 =>
                        'good',

                    $score >= 55 =>
                        'attention_required',

                    $score >= 30 =>
                        'poor',

                    default =>
                        'critical',
                },

            'signals' => $signals,
        ];
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
            $value = $this->normalizeMachineKey(
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
     * Generate report identifier.
     */
    private function generateReportId(): string
    {
        try {
            $token = strtoupper(
                bin2hex(
                    random_bytes(6)
                )
            );
        } catch (Throwable) {
            $token = strtoupper(
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

        return 'KGR-'
            . gmdate('Ymd-His')
            . '-'
            . $token;
    }
}