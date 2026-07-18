<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/WorkflowService.php
|--------------------------------------------------------------------------
| IPMdb Workflow Service
|--------------------------------------------------------------------------
|
| Coordinates attributable, stage-based workflows for ideas, assets,
| decisions, relationships, deployments, reviews, and implementation.
|
| Responsibilities:
| - Create canonical workflow definitions and workflow instances.
| - Define stages, transitions, requirements, assignees, and deadlines.
| - Preserve actor attribution and execution context.
| - Validate workflow structure before execution.
| - Track stage status, progress, blockers, approvals, and outcomes.
| - Support manual, rule-assisted, and event-assisted progression.
| - Calculate deterministic checksums and workflow readiness.
| - Produce graph-ready workflow entities and relationships.
|
| Workflow defines sequence.
| Decisions authorize.
| Events record.
| Repository persists.
|
| This service performs no database operations.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/ValidationService.php';
require_once __DIR__ . '/EventService.php';
require_once __DIR__ . '/DecisionService.php';
require_once __DIR__ . '/RelationshipService.php';
require_once __DIR__ . '/RuleEngineService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class WorkflowService extends Service
{
    use GraphUtilities;

    private ValidationService $validation;

    private EventService $events;

    private DecisionService $decisions;

    private RelationshipService $relationships;

    private RuleEngineService $rules;

    /**
     * Supported workflow lifecycle states.
     *
     * @var array<int,string>
     */
    private array $statuses = [
        'draft',
        'ready',
        'active',
        'paused',
        'blocked',
        'completed',
        'failed',
        'cancelled',
        'archived',
    ];

    /**
     * Supported stage states.
     *
     * @var array<int,string>
     */
    private array $stageStatuses = [
        'pending',
        'ready',
        'active',
        'waiting',
        'blocked',
        'completed',
        'skipped',
        'failed',
        'cancelled',
    ];

    /**
     * Supported workflow types.
     *
     * @var array<int,string>
     */
    private array $workflowTypes = [
        'idea_intake',
        'idea_review',
        'idea_to_asset',
        'asset_review',
        'asset_deployment',
        'relationship_review',
        'decision',
        'approval',
        'validation',
        'provenance',
        'translation',
        'government_program_alignment',
        'implementation',
        'publication',
        'notification',
        'audit',
        'custom',
    ];

    /**
     * Supported stage execution modes.
     *
     * @var array<int,string>
     */
    private array $stageModes = [
        'manual',
        'automatic',
        'rule_assisted',
        'event_assisted',
        'approval_required',
        'decision_required',
    ];

    /**
     * Allowed workflow lifecycle transitions.
     *
     * @var array<string,array<int,string>>
     */
    private array $transitions = [
        'draft' => [
            'ready',
            'cancelled',
            'archived',
        ],

        'ready' => [
            'draft',
            'active',
            'cancelled',
            'archived',
        ],

        'active' => [
            'paused',
            'blocked',
            'completed',
            'failed',
            'cancelled',
            'archived',
        ],

        'paused' => [
            'active',
            'blocked',
            'cancelled',
            'archived',
        ],

        'blocked' => [
            'active',
            'paused',
            'failed',
            'cancelled',
            'archived',
        ],

        'completed' => [
            'archived',
        ],

        'failed' => [
            'draft',
            'ready',
            'active',
            'cancelled',
            'archived',
        ],

        'cancelled' => [
            'draft',
            'archived',
        ],

        'archived' => [
            'draft',
        ],
    ];

    /**
     * Allowed stage lifecycle transitions.
     *
     * @var array<string,array<int,string>>
     */
    private array $stageTransitions = [
        'pending' => [
            'ready',
            'skipped',
            'cancelled',
        ],

        'ready' => [
            'active',
            'waiting',
            'blocked',
            'skipped',
            'cancelled',
        ],

        'active' => [
            'waiting',
            'blocked',
            'completed',
            'failed',
            'cancelled',
        ],

        'waiting' => [
            'ready',
            'active',
            'blocked',
            'completed',
            'cancelled',
        ],

        'blocked' => [
            'ready',
            'active',
            'failed',
            'cancelled',
        ],

        'completed' => [],

        'skipped' => [],

        'failed' => [
            'ready',
            'active',
            'cancelled',
        ],

        'cancelled' => [],
    ];

    /**
     * Fields protected after workflow creation.
     *
     * @var array<int,string>
     */
    private array $immutableFields = [
        'workflow_id',
        'entity_id',
        'entity_type',
        'subject_id',
        'subject_type',
        'created_at',
        'created_by',
    ];

    /**
     * Fields excluded from checksum calculation.
     *
     * @var array<int,string>
     */
    private array $checksumExcludedFields = [
        'checksum',
        'updated_at',
        'last_accessed_at',
        'runtime',
        'analytics',
        'search_score',
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?ValidationService $validation = null,
        ?EventService $events = null,
        ?DecisionService $decisions = null,
        ?RelationshipService $relationships = null,
        ?RuleEngineService $rules = null
    ) {
        parent::__construct($config, $context);

        $this->validation = $validation
            ?? new ValidationService();

        $this->events = $events
            ?? new EventService();

        $this->decisions = $decisions
            ?? new DecisionService();

        $this->relationships = $relationships
            ?? new RelationshipService();

        $this->rules = $rules
            ?? new RuleEngineService();

        if (
            isset($config['workflow_types'])
            && is_array($config['workflow_types'])
        ) {
            $this->workflowTypes =
                $this->normalizeStringList(
                    array_merge(
                        $this->workflowTypes,
                        $config['workflow_types']
                    )
                );
        }

        if (
            isset($config['stage_modes'])
            && is_array($config['stage_modes'])
        ) {
            $this->stageModes =
                $this->normalizeStringList(
                    array_merge(
                        $this->stageModes,
                        $config['stage_modes']
                    )
                );
        }
    }

    /**
     * Create one canonical workflow.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function create(
        array $input,
        string $actorId = ''
    ): array {
        $this->reset();

        $actorId = trim(
            $actorId !== ''
                ? $actorId
                : (string)(
                    $input['created_by']
                    ?? $input['owner_id']
                    ?? ''
                )
        );

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Workflow creation requires actor attribution.'
            );
        }

        $subjectId = trim(
            (string)(
                $input['subject_id']
                    ?? ''
            )
        );

        if ($subjectId === '') {
            throw new InvalidArgumentException(
                'Workflow subject identifier is required.'
            );
        }

        $subjectType =
            $this->normalizeMachineKey(
                (string)(
                    $input['subject_type']
                        ?? 'entity'
                )
            );

        $workflowType =
            $this->normalizeWorkflowType(
                (string)(
                    $input['workflow_type']
                        ?? $input['type']
                        ?? 'custom'
                )
            );

        $title = trim(
            (string)(
                $input['title']
                    ?? $this->defaultTitle(
                        $workflowType,
                        $subjectId
                    )
            )
        );

        $workflowId = trim(
            (string)(
                $input['workflow_id']
                    ?? ''
            )
        );

        if ($workflowId === '') {
            $workflowId =
                $this->generateWorkflowId(
                    $workflowType,
                    $subjectId
                );
        }

        $stages = $this->normalizeStages(
            $input['stages']
                ?? []
        );

        if ($stages === []) {
            throw new InvalidArgumentException(
                'Workflow requires at least one stage.'
            );
        }

        $this->assertUniqueStageIds(
            $stages
        );

        $this->assertStageDependenciesExist(
            $stages
        );

        $this->assertNoStageDependencyCycles(
            $stages
        );

        $now = gmdate('c');

        $metadata = is_array(
            $input['metadata']
                ?? null
        )
            ? $input['metadata']
            : [];

        $metadata['workflow_service'] =
            array_merge(
                is_array(
                    $metadata['workflow_service']
                        ?? null
                )
                    ? $metadata[
                        'workflow_service'
                    ]
                    : [],
                [
                    'created_by_service' =>
                        static::class,

                    'created_at' =>
                        $now,
                ]
            );

        $workflow = [
            'workflow_id' =>
                $workflowId,

            'entity_id' =>
                $workflowId,

            'entity_type' =>
                'workflow',

            'subject_id' =>
                $subjectId,

            'subject_type' =>
                $subjectType !== ''
                    ? $subjectType
                    : 'entity',

            'workflow_type' =>
                $workflowType,

            'title' => $title,

            'description' => trim(
                (string)(
                    $input['description']
                        ?? ''
                )
            ),

            'status' =>
                $this->normalizeWorkflowStatus(
                    (string)(
                        $input['status']
                            ?? 'draft'
                    )
                ),

            'version' => trim(
                (string)(
                    $input['version']
                        ?? '1.0'
                )
            ),

            'owner_id' => trim(
                (string)(
                    $input['owner_id']
                        ?? $actorId
                )
            ),

            'assigned_to' =>
                $this->normalizeAssignees(
                    $input['assigned_to']
                        ?? []
                ),

            'participants' =>
                $this->normalizeParticipants(
                    $input['participants']
                        ?? []
                ),

            'stages' => $stages,

            'current_stage_id' =>
                $this->resolveInitialStageId(
                    $stages,
                    $input[
                        'current_stage_id'
                    ] ?? null
                ),

            'context' =>
                $this->normalizeContext(
                    $input['context']
                        ?? []
                ),

            'input' =>
                is_array(
                    $input[
                        'workflow_input'
                    ] ?? null
                )
                    ? $input[
                        'workflow_input'
                    ]
                    : [],

            'output' =>
                is_array(
                    $input[
                        'workflow_output'
                    ] ?? null
                )
                    ? $input[
                        'workflow_output'
                    ]
                    : [],

            'requirements' =>
                $this->normalizeRequirements(
                    $input['requirements']
                        ?? []
                ),

            'conditions' =>
                $this->normalizeConditions(
                    $input['conditions']
                        ?? []
                ),

            'blockers' =>
                $this->normalizeBlockers(
                    $input['blockers']
                        ?? []
                ),

            'priority' =>
                $this->normalizePriority(
                    $input['priority']
                        ?? 50
                ),

            'progress' => 0.0,

            'started_by' => null,

            'started_at' => null,

            'paused_by' => null,

            'paused_at' => null,

            'pause_reason' => null,

            'blocked_by' => null,

            'blocked_at' => null,

            'block_reason' => null,

            'completed_by' => null,

            'completed_at' => null,

            'failed_by' => null,

            'failed_at' => null,

            'failure_reason' => null,

            'cancelled_by' => null,

            'cancelled_at' => null,

            'cancellation_reason' => null,

            'archived_by' => null,

            'archived_at' => null,

            'due_at' =>
                $this->normalizeDate(
                    $input['due_at']
                        ?? null
                ),

            'created_by' =>
                $actorId,

            'created_at' =>
                $now,

            'updated_by' =>
                $actorId,

            'updated_at' =>
                $now,

            'tags' =>
                $this->normalizeStringList(
                    $input['tags']
                        ?? []
                ),

            'metadata' =>
                $metadata,

            'checksum' => '',
        ];

        $workflow =
            $this->mergeAdditionalFields(
                $workflow,
                $input
            );

        $workflow['progress'] =
            $this->calculateProgress(
                $workflow['stages']
            );

        $workflow['readiness'] =
            $this->calculateReadiness(
                $workflow
            );

        $workflow['checksum'] =
            $this->calculateChecksum(
                $workflow
            );

        $validation =
            $this->validate($workflow);

        if (
            ($validation['valid'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Workflow validation failed: '
                . implode(
                    ' ',
                    $validation['errors']
                        ?? []
                )
            );
        }

        $this->addMessage(
            'Workflow created.',
            [
                'workflow_id' =>
                    $workflowId,

                'workflow_type' =>
                    $workflowType,

                'stage_count' =>
                    count($stages),

                'status' =>
                    $workflow['status'],
            ]
        );

        return $workflow;
    }

    /**
     * Create one canonical stage.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function createStage(
        array $input,
        int $position = 0
    ): array {
        $title = trim(
            (string)(
                $input['title']
                    ?? $input['name']
                    ?? ''
            )
        );

        if ($title === '') {
            throw new InvalidArgumentException(
                'Workflow stage title is required.'
            );
        }

        $stageId = trim(
            (string)(
                $input['stage_id']
                    ?? ''
            )
        );

        if ($stageId === '') {
            $stageId =
                $this->generateStageId(
                    $title,
                    $position
                );
        }

        $mode = $this->normalizeStageMode(
            (string)(
                $input['mode']
                    ?? 'manual'
            )
        );

        $stage = [
            'stage_id' => $stageId,

            'title' => $title,

            'description' => trim(
                (string)(
                    $input['description']
                        ?? ''
                )
            ),

            'position' => max(
                0,
                (int)(
                    $input['position']
                        ?? $position
                )
            ),

            'status' =>
                $this->normalizeStageStatus(
                    (string)(
                        $input['status']
                            ?? 'pending'
                    )
                ),

            'mode' => $mode,

            'required' => (bool)(
                $input['required']
                    ?? true
            ),

            'depends_on' =>
                $this->normalizeStringList(
                    $input['depends_on']
                        ?? []
                ),

            'assignees' =>
                $this->normalizeAssignees(
                    $input['assignees']
                        ?? []
                ),

            'requirements' =>
                $this->normalizeRequirements(
                    $input['requirements']
                        ?? []
                ),

            'conditions' =>
                $this->normalizeConditions(
                    $input['conditions']
                        ?? []
                ),

            'actions' =>
                $this->normalizeActions(
                    $input['actions']
                        ?? []
                ),

            'decision_required' => (bool)(
                $input['decision_required']
                    ?? (
                        $mode ===
                        'decision_required'
                    )
            ),

            'approval_required' => (bool)(
                $input['approval_required']
                    ?? (
                        $mode ===
                        'approval_required'
                    )
            ),

            'minimum_approvals' => max(
                0,
                (int)(
                    $input['minimum_approvals']
                        ?? 1
                )
            ),

            'approvals' =>
                $this->normalizeApprovals(
                    $input['approvals']
                        ?? []
                ),

            'decision_id' => trim(
                (string)(
                    $input['decision_id']
                        ?? ''
                )
            ),

            'started_by' => null,

            'started_at' => null,

            'completed_by' => null,

            'completed_at' => null,

            'failed_by' => null,

            'failed_at' => null,

            'failure_reason' => null,

            'blocked_by' => null,

            'blocked_at' => null,

            'block_reason' => null,

            'skipped_by' => null,

            'skipped_at' => null,

            'skip_reason' => null,

            'cancelled_by' => null,

            'cancelled_at' => null,

            'cancellation_reason' => null,

            'due_at' =>
                $this->normalizeDate(
                    $input['due_at']
                        ?? null
                ),

            'input' =>
                is_array(
                    $input['input']
                        ?? null
                )
                    ? $input['input']
                    : [],

            'output' =>
                is_array(
                    $input['output']
                        ?? null
                )
                    ? $input['output']
                    : [],

            'metadata' =>
                is_array(
                    $input['metadata']
                        ?? null
                )
                    ? $input['metadata']
                    : [],
        ];

        $validation =
            $this->validateStage($stage);

        if (
            ($validation['valid'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Workflow stage validation failed: '
                . implode(
                    ' ',
                    $validation['errors']
                        ?? []
                )
            );
        }

        return $stage;
    }

    /**
     * Validate one workflow stage.
     *
     * @param array<string,mixed> $stage
     *
     * @return array<string,mixed>
     */
    public function validateStage(
        array $stage
    ): array {
        $errors = [];
        $warnings = [];

        foreach (
            [
                'stage_id',
                'title',
                'position',
                'status',
                'mode',
            ]
            as $field
        ) {
            if (
                $this->valueIsEmpty(
                    $stage[$field]
                        ?? null
                )
            ) {
                $errors[] = sprintf(
                    'Workflow stage field "%s" is required.',
                    $field
                );
            }
        }

        try {
            $this->normalizeStageStatus(
                (string)(
                    $stage['status']
                        ?? 'pending'
                )
            );
        } catch (Throwable $exception) {
            $errors[] =
                $exception->getMessage();
        }

        try {
            $this->normalizeStageMode(
                (string)(
                    $stage['mode']
                        ?? 'manual'
                )
            );
        } catch (Throwable $exception) {
            $errors[] =
                $exception->getMessage();
        }

        if (
            (
                $stage['approval_required']
                    ?? false
            ) === true
            && (int)(
                $stage['minimum_approvals']
                    ?? 0
            ) < 1
        ) {
            $errors[] =
                'Approval stage requires at least one approval.';
        }

        if (
            (
                $stage['decision_required']
                    ?? false
            ) === true
            && (
                $stage['status']
                    ?? ''
            ) === 'completed'
            && trim(
                (string)(
                    $stage['decision_id']
                        ?? ''
                )
            ) === ''
        ) {
            $errors[] =
                'Completed decision stage requires decision_id.';
        }

        if (
            trim(
                (string)(
                    $stage['description']
                        ?? ''
                )
            ) === ''
        ) {
            $warnings[] =
                'Workflow stage description is empty.';
        }

        return [
            'valid' => $errors === [],

            'error_count' =>
                count($errors),

            'warning_count' =>
                count($warnings),

            'errors' => $errors,

            'warnings' => $warnings,
        ];
    }

    /**
     * Validate one complete workflow.
     *
     * @param array<string,mixed> $workflow
     *
     * @return array<string,mixed>
     */
    public function validate(
        array $workflow
    ): array {
        $errors = [];
        $warnings = [];

        foreach (
            [
                'workflow_id',
                'entity_id',
                'entity_type',
                'subject_id',
                'subject_type',
                'workflow_type',
                'title',
                'status',
                'version',
                'owner_id',
                'stages',
                'created_by',
                'created_at',
                'updated_at',
            ]
            as $field
        ) {
            if (
                $this->valueIsEmpty(
                    $workflow[$field]
                        ?? null
                )
            ) {
                $errors[] = sprintf(
                    'Workflow field "%s" is required.',
                    $field
                );
            }
        }

        if (
            isset($workflow['entity_type'])
            && $workflow['entity_type']
                !== 'workflow'
        ) {
            $errors[] =
                'Workflow entity type must be "workflow".';
        }

        try {
            $this->normalizeWorkflowStatus(
                (string)(
                    $workflow['status']
                        ?? 'draft'
                )
            );
        } catch (Throwable $exception) {
            $errors[] =
                $exception->getMessage();
        }

        try {
            $this->normalizeWorkflowType(
                (string)(
                    $workflow[
                        'workflow_type'
                    ] ?? 'custom'
                )
            );
        } catch (Throwable $exception) {
            $errors[] =
                $exception->getMessage();
        }

        $stages = is_array(
            $workflow['stages']
                ?? null
        )
            ? $workflow['stages']
            : [];

        if ($stages === []) {
            $errors[] =
                'Workflow requires at least one stage.';
        }

        foreach ($stages as $index => $stage) {
            if (!is_array($stage)) {
                $errors[] = sprintf(
                    'Workflow stage at index %d is invalid.',
                    $index
                );

                continue;
            }

            $validation =
                $this->validateStage($stage);

            foreach (
                $validation['errors']
                    ?? []
                as $error
            ) {
                $errors[] = sprintf(
                    'Stage %d: %s',
                    $index,
                    $error
                );
            }

            foreach (
                $validation['warnings']
                    ?? []
                as $warning
            ) {
                $warnings[] = sprintf(
                    'Stage %d: %s',
                    $index,
                    $warning
                );
            }
        }

        try {
            $this->assertUniqueStageIds(
                $stages
            );

            $this->assertStageDependenciesExist(
                $stages
            );

            $this->assertNoStageDependencyCycles(
                $stages
            );
        } catch (Throwable $exception) {
            $errors[] =
                $exception->getMessage();
        }

        $currentStageId = trim(
            (string)(
                $workflow[
                    'current_stage_id'
                ] ?? ''
            )
        );

        if (
            $currentStageId !== ''
            && !$this->stageExists(
                $stages,
                $currentStageId
            )
        ) {
            $errors[] =
                'Current workflow stage does not exist.';
        }

        $storedChecksum = trim(
            (string)(
                $workflow['checksum']
                    ?? ''
            )
        );

        if (
            $storedChecksum !== ''
            && !hash_equals(
                $storedChecksum,
                $this->calculateChecksum(
                    $workflow
                )
            )
        ) {
            $errors[] =
                'Workflow checksum does not match content.';
        }

        return [
            'valid' => $errors === [],

            'error_count' =>
                count($errors),

            'warning_count' =>
                count($warnings),

            'errors' => $errors,

            'warnings' => $warnings,
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
                'statuses' =>
                    $this->statuses,

                'stage_statuses' =>
                    $this->stageStatuses,

                'workflow_types' =>
                    $this->workflowTypes,

                'stage_modes' =>
                    $this->stageModes,

                'transitions' =>
                    $this->transitions,

                'stage_transitions' =>
                    $this->stageTransitions,

                'immutable_fields' =>
                    $this->immutableFields,

                'supports_manual_execution' =>
                    true,

                'supports_rule_assistance' =>
                    true,

                'supports_event_assistance' =>
                    true,

                'supports_approval_stages' =>
                    true,

                'supports_decision_stages' =>
                    true,

                'database_operations' =>
                    false,

                'automatic_persistence' =>
                    false,

                'human_attribution_required' =>
                    true,

                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Normalize stages.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeStages(
        mixed $stages
    ): array {
        if (!is_array($stages)) {
            return [];
        }

        if (
            $stages !== []
            && !array_is_list($stages)
        ) {
            $stages = [$stages];
        }

        $normalized = [];

        foreach ($stages as $index => $stage) {
            if (is_string($stage)) {
                $stage = [
                    'title' => $stage,
                ];
            }

            if (!is_array($stage)) {
                continue;
            }

            $normalized[] =
                $this->createStage(
                    $stage,
                    $index
                );
        }

        usort(
            $normalized,
            static fn (
                array $left,
                array $right
            ): int =>
                (int)(
                    $left['position']
                        ?? 0
                )
                <=>
                (int)(
                    $right['position']
                        ?? 0
                )
        );

        return array_values($normalized);
    }

    /**
     * Normalize workflow context.
     *
     * @return array<string,mixed>
     */
    private function normalizeContext(
        mixed $context
    ): array {
        if (!is_array($context)) {
            return [];
        }

        return $context;
    }

    /**
     * Resolve initial workflow stage.
     *
     * @param array<int,array<string,mixed>> $stages
     */
    private function resolveInitialStageId(
        array $stages,
        mixed $requestedStageId
    ): ?string {
        $requestedStageId = trim(
            (string)$requestedStageId
        );

        if (
            $requestedStageId !== ''
            && $this->stageExists(
                $stages,
                $requestedStageId
            )
        ) {
            return $requestedStageId;
        }

        foreach ($stages as $stage) {
            if (
                ($stage['status'] ?? '')
                === 'active'
            ) {
                return (string)(
                    $stage['stage_id']
                );
            }
        }

        foreach ($stages as $stage) {
            if (
                in_array(
                    $stage['status']
                        ?? '',
                    [
                        'ready',
                        'pending',
                    ],
                    true
                )
            ) {
                return (string)(
                    $stage['stage_id']
                );
            }
        }

        return isset($stages[0])
            ? (string)(
                $stages[0]['stage_id']
                    ?? ''
            )
            : null;
    }

    /**
     * Assert unique stage identifiers.
     *
     * @param array<int,array<string,mixed>> $stages
     */
    private function assertUniqueStageIds(
        array $stages
    ): void {
        $seen = [];

        foreach ($stages as $stage) {
            $stageId = trim(
                (string)(
                    $stage['stage_id']
                        ?? ''
                )
            );

            if ($stageId === '') {
                throw new InvalidArgumentException(
                    'Workflow stage identifier is missing.'
                );
            }

            if (isset($seen[$stageId])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Duplicate workflow stage identifier "%s".',
                        $stageId
                    )
                );
            }

            $seen[$stageId] = true;
        }
    }

    /**
     * Assert that stage dependencies exist.
     *
     * @param array<int,array<string,mixed>> $stages
     */
    private function assertStageDependenciesExist(
        array $stages
    ): void {
        $stageIds = [];

        foreach ($stages as $stage) {
            $stageIds[
                (string)(
                    $stage['stage_id']
                        ?? ''
                )
            ] = true;
        }

        foreach ($stages as $stage) {
            foreach (
                $stage['depends_on']
                    ?? []
                as $dependency
            ) {
                if (!isset($stageIds[$dependency])) {
                    throw new InvalidArgumentException(
                        sprintf(
                            'Workflow stage "%s" depends on missing stage "%s".',
                            (string)(
                                $stage['stage_id']
                                    ?? ''
                            ),
                            $dependency
                        )
                    );
                }

                if (
                    $dependency
                    === (
                        $stage['stage_id']
                            ?? ''
                    )
                ) {
                    throw new InvalidArgumentException(
                        'Workflow stage cannot depend on itself.'
                    );
                }
            }
        }
    }

    /**
     * Assert that stage dependencies contain no cycles.
     *
     * @param array<int,array<string,mixed>> $stages
     */
    private function assertNoStageDependencyCycles(
        array $stages
    ): void {
        $graph = [];

        foreach ($stages as $stage) {
            $stageId = (string)(
                $stage['stage_id']
                    ?? ''
            );

            $graph[$stageId] =
                $stage['depends_on']
                    ?? [];
        }

        $visited = [];
        $active = [];

        $visit = function (
            string $stageId
        ) use (
            &$visit,
            &$visited,
            &$active,
            $graph
        ): void {
            if (isset($active[$stageId])) {
                throw new InvalidArgumentException(
                    'Workflow stage dependency cycle detected.'
                );
            }

            if (isset($visited[$stageId])) {
                return;
            }

            $active[$stageId] = true;

            foreach (
                $graph[$stageId]
                    ?? []
                as $dependency
            ) {
                $visit((string)$dependency);
            }

            unset($active[$stageId]);

            $visited[$stageId] = true;
        };

        foreach (array_keys($graph) as $stageId) {
            $visit((string)$stageId);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | WORKFLOW EXECUTION CONTINUES IN PART 2
    |--------------------------------------------------------------------------
    |
    | Do not close the class yet.
    |
    */    /**
     * Update one workflow while preserving protected identity fields.
     *
     * @param array<string,mixed> $workflow
     * @param array<string,mixed> $changes
     *
     * @return array<string,mixed>
     */
    public function update(
        array $workflow,
        array $changes,
        string $actorId,
        string $reason = ''
    ): array {
        $this->assertWorkflow($workflow);

        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Workflow update requires actor attribution.'
            );
        }

        if (
            in_array(
                (string)(
                    $workflow['status']
                        ?? ''
                ),
                [
                    'completed',
                    'cancelled',
                    'archived',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Finalized workflow requires restoration or a new workflow.'
            );
        }

        $updated = $workflow;

        foreach ($changes as $field => $value) {
            $field = trim(
                (string)$field
            );

            if (
                $field === ''
                || in_array(
                    $field,
                    $this->immutableFields,
                    true
                )
            ) {
                continue;
            }

            $updated[$field] =
                $this->normalizeFieldValue(
                    $field,
                    $value
                );
        }

        $updated['updated_by'] =
            $actorId;

        $updated['updated_at'] =
            gmdate('c');

        $updated['version'] =
            $this->incrementVersion(
                (string)(
                    $workflow['version']
                        ?? '1.0'
                )
            );

        $updated['metadata'] = is_array(
            $updated['metadata']
                ?? null
        )
            ? $updated['metadata']
            : [];

        $updated['metadata']['last_change'] = [
            'changed_by' =>
                $actorId,

            'changed_at' =>
                gmdate('c'),

            'reason' =>
                trim($reason),

            'fields' =>
                array_values(
                    array_diff(
                        array_keys($changes),
                        $this->immutableFields
                    )
                ),
        ];

        $updated['progress'] =
            $this->calculateProgress(
                $updated['stages']
                    ?? []
            );

        $updated['readiness'] =
            $this->calculateReadiness(
                $updated
            );

        $updated['checksum'] =
            $this->calculateChecksum(
                $updated
            );

        $validation =
            $this->validate($updated);

        if (
            ($validation['valid'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Updated workflow is invalid: '
                . implode(
                    ' ',
                    $validation['errors']
                        ?? []
                )
            );
        }

        return $updated;
    }

    /**
     * Transition workflow lifecycle status.
     *
     * @param array<string,mixed> $workflow
     *
     * @return array<string,mixed>
     */
    public function transition(
        array $workflow,
        string $newStatus,
        string $actorId,
        string $reason = ''
    ): array {
        $this->assertWorkflow($workflow);

        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Workflow transition requires actor attribution.'
            );
        }

        $currentStatus =
            $this->normalizeWorkflowStatus(
                (string)(
                    $workflow['status']
                        ?? 'draft'
                )
            );

        $newStatus =
            $this->normalizeWorkflowStatus(
                $newStatus
            );

        if ($currentStatus === $newStatus) {
            return $workflow;
        }

        if (
            !in_array(
                $newStatus,
                $this->transitions[
                    $currentStatus
                ] ?? [],
                true
            )
        ) {
            throw new RuntimeException(
                sprintf(
                    'Workflow status cannot transition from "%s" to "%s".',
                    $currentStatus,
                    $newStatus
                )
            );
        }

        $changes = [
            'status' => $newStatus,
        ];

        $now = gmdate('c');

        switch ($newStatus) {
            case 'active':
                if (
                    empty(
                        $workflow['started_at']
                    )
                ) {
                    $changes['started_by'] =
                        $actorId;

                    $changes['started_at'] =
                        $now;
                }

                $changes['paused_by'] = null;
                $changes['paused_at'] = null;
                $changes['pause_reason'] = null;
                $changes['blocked_by'] = null;
                $changes['blocked_at'] = null;
                $changes['block_reason'] = null;
                break;

            case 'paused':
                $changes['paused_by'] =
                    $actorId;

                $changes['paused_at'] =
                    $now;

                $changes['pause_reason'] =
                    trim($reason);
                break;

            case 'blocked':
                $changes['blocked_by'] =
                    $actorId;

                $changes['blocked_at'] =
                    $now;

                $changes['block_reason'] =
                    trim($reason);
                break;

            case 'completed':
                $changes['completed_by'] =
                    $actorId;

                $changes['completed_at'] =
                    $now;

                $changes['progress'] =
                    100.0;
                break;

            case 'failed':
                $changes['failed_by'] =
                    $actorId;

                $changes['failed_at'] =
                    $now;

                $changes['failure_reason'] =
                    trim($reason);
                break;

            case 'cancelled':
                $changes['cancelled_by'] =
                    $actorId;

                $changes['cancelled_at'] =
                    $now;

                $changes[
                    'cancellation_reason'
                ] = trim($reason);
                break;

            case 'archived':
                $changes['archived_by'] =
                    $actorId;

                $changes['archived_at'] =
                    $now;
                break;
        }

        return $this->update(
            $workflow,
            $changes,
            $actorId,
            $reason !== ''
                ? $reason
                : sprintf(
                    'Workflow status changed from %s to %s.',
                    $currentStatus,
                    $newStatus
                )
        );
    }

    /**
     * Mark a workflow ready for execution.
     */
    public function markReady(
        array $workflow,
        string $actorId,
        string $reason = ''
    ): array {
        $readiness =
            $this->calculateReadiness(
                $workflow
            );

        if (
            ($readiness['ready'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Workflow readiness requirements are incomplete.'
            );
        }

        return $this->transition(
            $workflow,
            'ready',
            $actorId,
            $reason
        );
    }

    /**
     * Start a ready workflow.
     */
    public function start(
        array $workflow,
        string $actorId,
        string $reason = ''
    ): array {
        $this->assertWorkflow($workflow);

        if (
            !in_array(
                (string)(
                    $workflow['status']
                        ?? ''
                ),
                [
                    'ready',
                    'paused',
                    'blocked',
                    'failed',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Workflow is not in a startable state.'
            );
        }

        $updated = $this->transition(
            $workflow,
            'active',
            $actorId,
            $reason !== ''
                ? $reason
                : 'Workflow started.'
        );

        $currentStageId = trim(
            (string)(
                $updated[
                    'current_stage_id'
                ] ?? ''
            )
        );

        if ($currentStageId !== '') {
            $stage = $this->stage(
                $updated,
                $currentStageId
            );

            if (
                $stage !== null
                && in_array(
                    $stage['status']
                        ?? '',
                    [
                        'pending',
                        'ready',
                        'waiting',
                    ],
                    true
                )
            ) {
                $updated =
                    $this->startStage(
                        $updated,
                        $currentStageId,
                        $actorId,
                        'Initial workflow stage started.'
                    );
            }
        }

        return $updated;
    }

    /**
     * Pause an active workflow.
     */
    public function pause(
        array $workflow,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Workflow pause requires a reason.'
            );
        }

        return $this->transition(
            $workflow,
            'paused',
            $actorId,
            $reason
        );
    }

    /**
     * Block a workflow.
     */
    public function block(
        array $workflow,
        string $actorId,
        string $reason,
        array $blocker = []
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Workflow block requires a reason.'
            );
        }

        $updated = $workflow;

        if ($blocker !== []) {
            $blockers =
                $this->normalizeBlockers(
                    $workflow['blockers']
                        ?? []
                );

            $normalizedBlocker =
                $this->normalizeBlocker(
                    array_merge(
                        $blocker,
                        [
                            'description' =>
                                $blocker[
                                    'description'
                                ] ?? $reason,

                            'created_by' =>
                                $blocker[
                                    'created_by'
                                ] ?? $actorId,
                        ]
                    )
                );

            $indexed = [];

            foreach ($blockers as $item) {
                $indexed[
                    $item['blocker_id']
                ] = $item;
            }

            $indexed[
                $normalizedBlocker[
                    'blocker_id'
                ]
            ] = $normalizedBlocker;

            $updated['blockers'] =
                array_values($indexed);
        }

        return $this->transition(
            $updated,
            'blocked',
            $actorId,
            $reason
        );
    }

    /**
     * Resume a paused or blocked workflow.
     */
    public function resume(
        array $workflow,
        string $actorId,
        string $reason = ''
    ): array {
        if (
            !in_array(
                (string)(
                    $workflow['status']
                        ?? ''
                ),
                [
                    'paused',
                    'blocked',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Only paused or blocked workflows may resume.'
            );
        }

        $updated = $workflow;

        if (
            ($workflow['status'] ?? '')
            === 'blocked'
        ) {
            $updated['blockers'] =
                array_values(
                    array_map(
                        static function (
                            array $blocker
                        ) use ($actorId): array {
                            if (
                                ($blocker['resolved']
                                    ?? false) !== true
                            ) {
                                $blocker['resolved'] =
                                    true;

                                $blocker[
                                    'resolved_by'
                                ] = $actorId;

                                $blocker[
                                    'resolved_at'
                                ] = gmdate('c');
                            }

                            return $blocker;
                        },
                        $this->normalizeBlockers(
                            $workflow['blockers']
                                ?? []
                        )
                    )
                );
        }

        return $this->transition(
            $updated,
            'active',
            $actorId,
            $reason !== ''
                ? $reason
                : 'Workflow resumed.'
        );
    }

    /**
     * Cancel one workflow.
     */
    public function cancel(
        array $workflow,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Workflow cancellation requires a reason.'
            );
        }

        return $this->transition(
            $workflow,
            'cancelled',
            $actorId,
            $reason
        );
    }

    /**
     * Fail one workflow.
     */
    public function fail(
        array $workflow,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Workflow failure requires a reason.'
            );
        }

        return $this->transition(
            $workflow,
            'failed',
            $actorId,
            $reason
        );
    }

    /**
     * Archive one workflow.
     */
    public function archive(
        array $workflow,
        string $actorId,
        string $reason = ''
    ): array {
        return $this->transition(
            $workflow,
            'archived',
            $actorId,
            $reason
        );
    }

    /**
     * Restore an archived workflow to draft.
     */
    public function restore(
        array $workflow,
        string $actorId,
        string $reason = ''
    ): array {
        if (
            ($workflow['status'] ?? '')
            !== 'archived'
        ) {
            throw new RuntimeException(
                'Only archived workflows may be restored.'
            );
        }

        return $this->transition(
            $workflow,
            'draft',
            $actorId,
            $reason !== ''
                ? $reason
                : 'Workflow restored.'
        );
    }

    /**
     * Add one stage.
     *
     * @param array<string,mixed> $stageInput
     */
    public function addStage(
        array $workflow,
        array $stageInput,
        string $actorId
    ): array {
        $this->assertWorkflow($workflow);

        if (
            in_array(
                (string)(
                    $workflow['status']
                        ?? ''
                ),
                [
                    'completed',
                    'cancelled',
                    'archived',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Finalized workflow cannot receive new stages.'
            );
        }

        $stages = $this->normalizeStages(
            $workflow['stages']
                ?? []
        );

        $stage = $this->createStage(
            $stageInput,
            count($stages)
        );

        if (
            $this->stageExists(
                $stages,
                (string)$stage['stage_id']
            )
        ) {
            throw new RuntimeException(
                'Workflow stage identifier already exists.'
            );
        }

        $stages[] = $stage;

        $this->assertStageDependenciesExist(
            $stages
        );

        $this->assertNoStageDependencyCycles(
            $stages
        );

        return $this->update(
            $workflow,
            [
                'stages' => $stages,
            ],
            $actorId,
            'Workflow stage added.'
        );
    }

    /**
     * Replace one stage.
     *
     * @param array<string,mixed> $stageInput
     */
    public function updateStage(
        array $workflow,
        string $stageId,
        array $stageInput,
        string $actorId,
        string $reason = ''
    ): array {
        $this->assertWorkflow($workflow);

        $stageId = trim($stageId);

        if ($stageId === '') {
            throw new InvalidArgumentException(
                'Stage identifier is required.'
            );
        }

        $stages = $workflow['stages']
            ?? [];

        $found = false;

        foreach ($stages as $index => $stage) {
            if (!is_array($stage)) {
                continue;
            }

            if (
                ($stage['stage_id'] ?? '')
                !== $stageId
            ) {
                continue;
            }

            $candidate = array_merge(
                $stage,
                $stageInput,
                [
                    'stage_id' => $stageId,
                    'position' =>
                        $stage['position']
                        ?? $index,
                ]
            );

            $stages[$index] =
                $this->createStage(
                    $candidate,
                    $index
                );

            $found = true;
            break;
        }

        if (!$found) {
            throw new RuntimeException(
                'Workflow stage was not found.'
            );
        }

        $this->assertUniqueStageIds(
            $stages
        );

        $this->assertStageDependenciesExist(
            $stages
        );

        $this->assertNoStageDependencyCycles(
            $stages
        );

        return $this->update(
            $workflow,
            [
                'stages' =>
                    array_values($stages),
            ],
            $actorId,
            $reason !== ''
                ? $reason
                : 'Workflow stage updated.'
        );
    }

    /**
     * Remove one stage.
     */
    public function removeStage(
        array $workflow,
        string $stageId,
        string $actorId
    ): array {
        $this->assertWorkflow($workflow);

        $stageId = trim($stageId);

        $stages = $workflow['stages']
            ?? [];

        $remaining = [];

        foreach ($stages as $stage) {
            if (!is_array($stage)) {
                continue;
            }

            if (
                ($stage['stage_id'] ?? '')
                === $stageId
            ) {
                if (
                    in_array(
                        $stage['status']
                            ?? '',
                        [
                            'active',
                            'completed',
                        ],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Active or completed workflow stage cannot be removed.'
                    );
                }

                continue;
            }

            $dependencies =
                $this->normalizeStringList(
                    $stage['depends_on']
                        ?? []
                );

            if (
                in_array(
                    $stageId,
                    $dependencies,
                    true
                )
            ) {
                throw new RuntimeException(
                    'Workflow stage is required by another stage.'
                );
            }

            $remaining[] = $stage;
        }

        if (
            count($remaining)
            === count($stages)
        ) {
            throw new RuntimeException(
                'Workflow stage was not found.'
            );
        }

        if ($remaining === []) {
            throw new RuntimeException(
                'Workflow must retain at least one stage.'
            );
        }

        $currentStageId = (
            $workflow[
                'current_stage_id'
            ] ?? ''
        ) === $stageId
            ? $this->resolveInitialStageId(
                $remaining,
                null
            )
            : $workflow[
                'current_stage_id'
            ];

        return $this->update(
            $workflow,
            [
                'stages' => $remaining,

                'current_stage_id' =>
                    $currentStageId,
            ],
            $actorId,
            'Workflow stage removed.'
        );
    }

    /**
     * Start one stage.
     */
    public function startStage(
        array $workflow,
        string $stageId,
        string $actorId,
        string $reason = ''
    ): array {
        $this->assertWorkflow($workflow);

        if (
            ($workflow['status'] ?? '')
            !== 'active'
        ) {
            throw new RuntimeException(
                'Workflow must be active before a stage may start.'
            );
        }

        $stage = $this->stage(
            $workflow,
            $stageId
        );

        if ($stage === null) {
            throw new RuntimeException(
                'Workflow stage was not found.'
            );
        }

        $status = $stage['status']
            ?? 'pending';

        if (
            !in_array(
                $status,
                [
                    'pending',
                    'ready',
                    'waiting',
                    'blocked',
                    'failed',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Workflow stage is not startable.'
            );
        }

        $dependencyCheck =
            $this->stageDependenciesSatisfied(
                $workflow,
                $stage
            );

        if (
            ($dependencyCheck['satisfied']
                ?? false) !== true
        ) {
            throw new RuntimeException(
                'Workflow stage dependencies are incomplete: '
                . implode(
                    ', ',
                    $dependencyCheck[
                        'missing'
                    ] ?? []
                )
            );
        }

        $requirementCheck =
            $this->requirementsSatisfied(
                $stage['requirements']
                    ?? []
            );

        if (
            ($requirementCheck['satisfied']
                ?? false) !== true
        ) {
            throw new RuntimeException(
                'Workflow stage requirements are incomplete.'
            );
        }

        $updatedStage =
            $this->transitionStageRecord(
                $stage,
                'active',
                $actorId,
                $reason
            );

        return $this->replaceStageAndRefresh(
            $workflow,
            $updatedStage,
            $actorId,
            $reason !== ''
                ? $reason
                : 'Workflow stage started.',
            $stageId
        );
    }

    /**
     * Mark one stage waiting.
     */
    public function waitStage(
        array $workflow,
        string $stageId,
        string $actorId,
        string $reason = ''
    ): array {
        $stage = $this->requireStage(
            $workflow,
            $stageId
        );

        $updatedStage =
            $this->transitionStageRecord(
                $stage,
                'waiting',
                $actorId,
                $reason
            );

        return $this->replaceStageAndRefresh(
            $workflow,
            $updatedStage,
            $actorId,
            $reason !== ''
                ? $reason
                : 'Workflow stage waiting.',
            $stageId
        );
    }

    /**
     * Block one stage.
     */
    public function blockStage(
        array $workflow,
        string $stageId,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Stage block requires a reason.'
            );
        }

        $stage = $this->requireStage(
            $workflow,
            $stageId
        );

        $updatedStage =
            $this->transitionStageRecord(
                $stage,
                'blocked',
                $actorId,
                $reason
            );

        $updated = $this->replaceStageAndRefresh(
            $workflow,
            $updatedStage,
            $actorId,
            $reason,
            $stageId
        );

        return $this->block(
            $updated,
            $actorId,
            $reason,
            [
                'type' => 'stage',

                'subject_id' =>
                    $stageId,

                'description' =>
                    $reason,
            ]
        );
    }

    /**
     * Fail one stage.
     */
    public function failStage(
        array $workflow,
        string $stageId,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Stage failure requires a reason.'
            );
        }

        $stage = $this->requireStage(
            $workflow,
            $stageId
        );

        $updatedStage =
            $this->transitionStageRecord(
                $stage,
                'failed',
                $actorId,
                $reason
            );

        return $this->replaceStageAndRefresh(
            $workflow,
            $updatedStage,
            $actorId,
            $reason,
            $stageId
        );
    }

    /**
     * Skip one optional stage.
     */
    public function skipStage(
        array $workflow,
        string $stageId,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Stage skip requires a reason.'
            );
        }

        $stage = $this->requireStage(
            $workflow,
            $stageId
        );

        if (
            ($stage['required'] ?? true)
            === true
        ) {
            throw new RuntimeException(
                'Required workflow stage cannot be skipped.'
            );
        }

        $updatedStage =
            $this->transitionStageRecord(
                $stage,
                'skipped',
                $actorId,
                $reason
            );

        return $this->replaceStageAndRefresh(
            $workflow,
            $updatedStage,
            $actorId,
            $reason,
            $this->nextStageId(
                $workflow,
                $stageId
            )
        );
    }

    /**
     * Cancel one stage.
     */
    public function cancelStage(
        array $workflow,
        string $stageId,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Stage cancellation requires a reason.'
            );
        }

        $stage = $this->requireStage(
            $workflow,
            $stageId
        );

        $updatedStage =
            $this->transitionStageRecord(
                $stage,
                'cancelled',
                $actorId,
                $reason
            );

        return $this->replaceStageAndRefresh(
            $workflow,
            $updatedStage,
            $actorId,
            $reason,
            $this->nextStageId(
                $workflow,
                $stageId
            )
        );
    }

    /**
     * Complete one stage and advance the workflow.
     *
     * @param array<string,mixed> $output
     */
    public function completeStage(
        array $workflow,
        string $stageId,
        string $actorId,
        array $output = [],
        string $reason = ''
    ): array {
        $stage = $this->requireStage(
            $workflow,
            $stageId
        );

        if (
            ($stage['status'] ?? '')
            !== 'active'
            && ($stage['status'] ?? '')
            !== 'waiting'
        ) {
            throw new RuntimeException(
                'Only active or waiting stages may be completed.'
            );
        }

        if (
            ($stage['approval_required']
                ?? false) === true
            && count(
                $stage['approvals']
                    ?? []
            ) < (int)(
                $stage['minimum_approvals']
                    ?? 1
            )
        ) {
            throw new RuntimeException(
                'Workflow stage approval requirement is incomplete.'
            );
        }

        if (
            ($stage['decision_required']
                ?? false) === true
            && trim(
                (string)(
                    $stage['decision_id']
                        ?? ''
                )
            ) === ''
        ) {
            throw new RuntimeException(
                'Workflow stage requires an attributable decision.'
            );
        }

        $requirementCheck =
            $this->requirementsSatisfied(
                $stage['requirements']
                    ?? []
            );

        if (
            ($requirementCheck['satisfied']
                ?? false) !== true
        ) {
            throw new RuntimeException(
                'Workflow stage requirements are incomplete.'
            );
        }

        $stage['output'] = array_merge(
            is_array(
                $stage['output']
                    ?? null
            )
                ? $stage['output']
                : [],
            $output
        );

        $updatedStage =
            $this->transitionStageRecord(
                $stage,
                'completed',
                $actorId,
                $reason
            );

        $nextStageId = $this->nextStageId(
            $workflow,
            $stageId
        );

        $updated = $this->replaceStageAndRefresh(
            $workflow,
            $updatedStage,
            $actorId,
            $reason !== ''
                ? $reason
                : 'Workflow stage completed.',
            $nextStageId
        );

        if ($nextStageId === null) {
            if (
                $this->allRequiredStagesComplete(
                    $updated
                )
            ) {
                return $this->transition(
                    $updated,
                    'completed',
                    $actorId,
                    'All required workflow stages completed.'
                );
            }

            return $updated;
        }

        $nextStage = $this->stage(
            $updated,
            $nextStageId
        );

        if (
            $nextStage !== null
            && (
                $nextStage['status']
                    ?? ''
            ) === 'pending'
            && $this->stageDependenciesSatisfied(
                $updated,
                $nextStage
            )['satisfied']
        ) {
            $nextStage['status'] =
                'ready';

            $updated =
                $this->replaceStageAndRefresh(
                    $updated,
                    $nextStage,
                    $actorId,
                    'Next workflow stage is ready.',
                    $nextStageId
                );
        }

        return $updated;
    }

    /**
     * Transition an individual stage record.
     *
     * @param array<string,mixed> $stage
     *
     * @return array<string,mixed>
     */
    private function transitionStageRecord(
        array $stage,
        string $newStatus,
        string $actorId,
        string $reason = ''
    ): array {
        $currentStatus =
            $this->normalizeStageStatus(
                (string)(
                    $stage['status']
                        ?? 'pending'
                )
            );

        $newStatus =
            $this->normalizeStageStatus(
                $newStatus
            );

        if ($currentStatus === $newStatus) {
            return $stage;
        }

        if (
            !in_array(
                $newStatus,
                $this->stageTransitions[
                    $currentStatus
                ] ?? [],
                true
            )
        ) {
            throw new RuntimeException(
                sprintf(
                    'Stage status cannot transition from "%s" to "%s".',
                    $currentStatus,
                    $newStatus
                )
            );
        }

        $stage['status'] = $newStatus;

        $now = gmdate('c');

        switch ($newStatus) {
            case 'active':
                $stage['started_by'] =
                    $actorId;

                $stage['started_at'] =
                    $stage['started_at']
                    ?? $now;

                $stage['blocked_by'] =
                    null;

                $stage['blocked_at'] =
                    null;

                $stage['block_reason'] =
                    null;
                break;

            case 'blocked':
                $stage['blocked_by'] =
                    $actorId;

                $stage['blocked_at'] =
                    $now;

                $stage['block_reason'] =
                    trim($reason);
                break;

            case 'completed':
                $stage['completed_by'] =
                    $actorId;

                $stage['completed_at'] =
                    $now;
                break;

            case 'failed':
                $stage['failed_by'] =
                    $actorId;

                $stage['failed_at'] =
                    $now;

                $stage['failure_reason'] =
                    trim($reason);
                break;

            case 'skipped':
                $stage['skipped_by'] =
                    $actorId;

                $stage['skipped_at'] =
                    $now;

                $stage['skip_reason'] =
                    trim($reason);
                break;

            case 'cancelled':
                $stage['cancelled_by'] =
                    $actorId;

                $stage['cancelled_at'] =
                    $now;

                $stage[
                    'cancellation_reason'
                ] = trim($reason);
                break;
        }

        return $stage;
    }

    /**
     * Replace one stage and refresh workflow calculations.
     *
     * @param array<string,mixed> $updatedStage
     */
    private function replaceStageAndRefresh(
        array $workflow,
        array $updatedStage,
        string $actorId,
        string $reason,
        ?string $currentStageId
    ): array {
        $stages = $workflow['stages']
            ?? [];

        $found = false;

        foreach ($stages as $index => $stage) {
            if (
                is_array($stage)
                && (
                    $stage['stage_id']
                        ?? ''
                ) === (
                    $updatedStage['stage_id']
                        ?? ''
                )
            ) {
                $stages[$index] =
                    $updatedStage;

                $found = true;
                break;
            }
        }

        if (!$found) {
            throw new RuntimeException(
                'Workflow stage replacement failed.'
            );
        }

        return $this->update(
            $workflow,
            [
                'stages' =>
                    array_values($stages),

                'current_stage_id' =>
                    $currentStageId,
            ],
            $actorId,
            $reason
        );
    }

    /*
    |--------------------------------------------------------------------------
    | APPROVALS, DECISIONS, ACTIONS, RULES, AND EVENTS CONTINUE IN PART 3
    |--------------------------------------------------------------------------
    |
    | Do not close the class yet.
    |
    */    /**
     * Approve one workflow stage.
     */
    public function approveStage(
        array $workflow,
        string $stageId,
        string $actorId,
        string $reason = ''
    ): array {
        $stage = $this->requireStage(
            $workflow,
            $stageId
        );

        if (
            ($stage['approval_required'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Workflow stage does not require approval.'
            );
        }

        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Stage approval requires actor attribution.'
            );
        }

        $approvals =
            $this->normalizeApprovals(
                $stage['approvals']
                    ?? []
            );

        $indexed = [];

        foreach ($approvals as $approval) {
            $indexed[
                $approval['actor_id']
            ] = $approval;
        }

        $indexed[$actorId] = [
            'approval_id' =>
                $this->generateApprovalId(
                    $stageId,
                    $actorId
                ),

            'actor_id' => $actorId,

            'status' => 'approved',

            'reason' => trim($reason),

            'approved_at' =>
                gmdate('c'),

            'metadata' => [],
        ];

        $stage['approvals'] =
            array_values($indexed);

        return $this->replaceStageAndRefresh(
            $workflow,
            $stage,
            $actorId,
            $reason !== ''
                ? $reason
                : 'Workflow stage approved.',
            $stageId
        );
    }

    /**
     * Revoke one stage approval.
     */
    public function revokeStageApproval(
        array $workflow,
        string $stageId,
        string $actorId,
        string $reason = ''
    ): array {
        $stage = $this->requireStage(
            $workflow,
            $stageId
        );

        $actorId = trim($actorId);

        $approvals = array_values(
            array_filter(
                $this->normalizeApprovals(
                    $stage['approvals']
                        ?? []
                ),
                static fn (
                    array $approval
                ): bool =>
                    (
                        $approval['actor_id']
                        ?? ''
                    ) !== $actorId
            )
        );

        $stage['approvals'] = $approvals;

        return $this->replaceStageAndRefresh(
            $workflow,
            $stage,
            $actorId,
            $reason !== ''
                ? $reason
                : 'Workflow stage approval revoked.',
            $stageId
        );
    }

    /**
     * Attach an attributable decision to one stage.
     *
     * @param array<string,mixed> $decision
     */
    public function attachDecision(
        array $workflow,
        string $stageId,
        array $decision,
        string $actorId
    ): array {
        $stage = $this->requireStage(
            $workflow,
            $stageId
        );

        $decisionId = trim(
            (string)(
                $decision['decision_id']
                    ?? ''
            )
        );

        if ($decisionId === '') {
            throw new InvalidArgumentException(
                'Attached decision requires decision_id.'
            );
        }

        $decisionValidation =
            $this->decisions->validate(
                $decision
            );

        if (
            ($decisionValidation['valid'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Attached workflow decision is invalid.'
            );
        }

        $stage['decision_id'] =
            $decisionId;

        $stage['metadata'] = is_array(
            $stage['metadata']
                ?? null
        )
            ? $stage['metadata']
            : [];

        $stage['metadata']['decision'] = [
            'decision_id' =>
                $decisionId,

            'decision_status' =>
                $decision['status']
                    ?? null,

            'attached_by' =>
                $actorId,

            'attached_at' =>
                gmdate('c'),
        ];

        return $this->replaceStageAndRefresh(
            $workflow,
            $stage,
            $actorId,
            'Decision attached to workflow stage.',
            $stageId
        );
    }

    /**
     * Add one stage action.
     *
     * @param array<string,mixed> $action
     */
    public function addStageAction(
        array $workflow,
        string $stageId,
        array $action,
        string $actorId
    ): array {
        $stage = $this->requireStage(
            $workflow,
            $stageId
        );

        $normalized =
            $this->normalizeAction(
                $action
            );

        $actions =
            $this->normalizeActions(
                $stage['actions']
                    ?? []
            );

        $indexed = [];

        foreach ($actions as $item) {
            $indexed[
                $item['action_id']
            ] = $item;
        }

        $indexed[
            $normalized['action_id']
        ] = $normalized;

        $stage['actions'] =
            array_values($indexed);

        return $this->replaceStageAndRefresh(
            $workflow,
            $stage,
            $actorId,
            'Workflow stage action added.',
            $stageId
        );
    }

    /**
     * Record completion of one stage action.
     *
     * @param array<string,mixed> $output
     */
    public function completeStageAction(
        array $workflow,
        string $stageId,
        string $actionId,
        string $actorId,
        array $output = []
    ): array {
        $stage = $this->requireStage(
            $workflow,
            $stageId
        );

        $actions =
            $this->normalizeActions(
                $stage['actions']
                    ?? []
            );

        $found = false;

        foreach ($actions as $index => $action) {
            if (
                ($action['action_id'] ?? '')
                !== $actionId
            ) {
                continue;
            }

            $actions[$index]['status'] =
                'completed';

            $actions[$index]['completed_by'] =
                $actorId;

            $actions[$index]['completed_at'] =
                gmdate('c');

            $actions[$index]['output'] =
                array_merge(
                    is_array(
                        $action['output']
                            ?? null
                    )
                        ? $action['output']
                        : [],
                    $output
                );

            $found = true;
            break;
        }

        if (!$found) {
            throw new RuntimeException(
                'Workflow stage action was not found.'
            );
        }

        $stage['actions'] = $actions;

        return $this->replaceStageAndRefresh(
            $workflow,
            $stage,
            $actorId,
            'Workflow stage action completed.',
            $stageId
        );
    }

    /**
     * Mark one requirement satisfied.
     */
    public function satisfyRequirement(
        array $workflow,
        string $requirementId,
        string $actorId,
        mixed $evidence = null,
        ?string $stageId = null
    ): array {
        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Requirement satisfaction requires actor attribution.'
            );
        }

        if ($stageId !== null) {
            $stage = $this->requireStage(
                $workflow,
                $stageId
            );

            $stage['requirements'] =
                $this->markRequirementSatisfied(
                    $stage['requirements']
                        ?? [],
                    $requirementId,
                    $actorId,
                    $evidence
                );

            return $this->replaceStageAndRefresh(
                $workflow,
                $stage,
                $actorId,
                'Stage requirement satisfied.',
                $stageId
            );
        }

        $requirements =
            $this->markRequirementSatisfied(
                $workflow['requirements']
                    ?? [],
                $requirementId,
                $actorId,
                $evidence
            );

        return $this->update(
            $workflow,
            [
                'requirements' =>
                    $requirements,
            ],
            $actorId,
            'Workflow requirement satisfied.'
        );
    }

    /**
     * Evaluate workflow and stage conditions.
     *
     * @return array<string,mixed>
     */
    public function evaluateConditions(
        array $workflow,
        array $facts = [],
        ?string $stageId = null
    ): array {
        $conditions = $stageId !== null
            ? (
                $this->requireStage(
                    $workflow,
                    $stageId
                )['conditions'] ?? []
            )
            : (
                $workflow['conditions']
                    ?? []
            );

        $conditions =
            $this->normalizeConditions(
                $conditions
            );

        $results = [];
        $passed = 0;

        foreach ($conditions as $condition) {
            $result = $this->evaluateCondition(
                $condition,
                $workflow,
                $facts
            );

            if ($result['passed']) {
                $passed++;
            }

            $results[] = $result;
        }

        $required = count(
            array_filter(
                $conditions,
                static fn (
                    array $condition
                ): bool =>
                    ($condition['required']
                        ?? true) === true
            )
        );

        $requiredPassed = count(
            array_filter(
                $results,
                static fn (
                    array $result
                ): bool =>
                    ($result['required']
                        ?? true) === true
                    && ($result['passed']
                        ?? false) === true
            )
        );

        return [
            'condition_count' =>
                count($conditions),

            'passed_count' => $passed,

            'required_count' =>
                $required,

            'required_passed_count' =>
                $requiredPassed,

            'satisfied' =>
                $requiredPassed === $required,

            'results' => $results,
        ];
    }

    /**
     * Evaluate registered rules against workflow context.
     *
     * @return array<string,mixed>
     */
    public function evaluateRules(
        array $workflow,
        array $facts = [],
        array $options = []
    ): array {
        return $this->rules->run(
            [
                $this->toGraphEntity(
                    $workflow
                ),
            ],
            [],
            $facts,
            array_merge(
                [
                    'context' => [
                        'workflow_id' =>
                            $workflow[
                                'workflow_id'
                            ],

                        'workflow_status' =>
                            $workflow['status']
                                ?? null,

                        'current_stage_id' =>
                            $workflow[
                                'current_stage_id'
                            ] ?? null,
                    ],
                ],
                $options
            )
        );
    }

    /**
     * Apply one external event to a workflow.
     *
     * @param array<string,mixed> $event
     */
    public function handleEvent(
        array $workflow,
        array $event,
        string $actorId = ''
    ): array {
        $eventType =
            $this->normalizeMachineKey(
                (string)(
                    $event['event_type']
                        ?? $event['type']
                        ?? ''
                )
            );

        if ($eventType === '') {
            throw new InvalidArgumentException(
                'Workflow event requires event_type.'
            );
        }

        $actorId = trim(
            $actorId !== ''
                ? $actorId
                : (string)(
                    $event['actor_id']
                        ?? 'system'
                )
        );

        $stageId = trim(
            (string)(
                $event['stage_id']
                    ?? $workflow[
                        'current_stage_id'
                    ] ?? ''
            )
        );

        return match ($eventType) {
            'workflow_start',
            'start_workflow' =>
                $this->start(
                    $workflow,
                    $actorId,
                    (string)(
                        $event['reason']
                            ?? ''
                    )
                ),

            'workflow_pause',
            'pause_workflow' =>
                $this->pause(
                    $workflow,
                    $actorId,
                    (string)(
                        $event['reason']
                            ?? 'Workflow paused by event.'
                    )
                ),

            'workflow_resume',
            'resume_workflow' =>
                $this->resume(
                    $workflow,
                    $actorId,
                    (string)(
                        $event['reason']
                            ?? ''
                    )
                ),

            'workflow_cancel',
            'cancel_workflow' =>
                $this->cancel(
                    $workflow,
                    $actorId,
                    (string)(
                        $event['reason']
                            ?? 'Workflow cancelled by event.'
                    )
                ),

            'stage_start',
            'start_stage' =>
                $this->startStage(
                    $workflow,
                    $stageId,
                    $actorId,
                    (string)(
                        $event['reason']
                            ?? ''
                    )
                ),

            'stage_complete',
            'complete_stage' =>
                $this->completeStage(
                    $workflow,
                    $stageId,
                    $actorId,
                    is_array(
                        $event['output']
                            ?? null
                    )
                        ? $event['output']
                        : [],
                    (string)(
                        $event['reason']
                            ?? ''
                    )
                ),

            'stage_block',
            'block_stage' =>
                $this->blockStage(
                    $workflow,
                    $stageId,
                    $actorId,
                    (string)(
                        $event['reason']
                            ?? 'Stage blocked by event.'
                    )
                ),

            default =>
                $this->recordUnhandledEvent(
                    $workflow,
                    $event,
                    $actorId
                ),
        };
    }

    /**
     * Add one blocker.
     *
     * @param array<string,mixed> $blocker
     */
    public function addBlocker(
        array $workflow,
        array $blocker,
        string $actorId
    ): array {
        $normalized =
            $this->normalizeBlocker(
                array_merge(
                    $blocker,
                    [
                        'created_by' =>
                            $blocker[
                                'created_by'
                            ] ?? $actorId,
                    ]
                )
            );

        $blockers =
            $this->normalizeBlockers(
                $workflow['blockers']
                    ?? []
            );

        $indexed = [];

        foreach ($blockers as $item) {
            $indexed[
                $item['blocker_id']
            ] = $item;
        }

        $indexed[
            $normalized['blocker_id']
        ] = $normalized;

        return $this->update(
            $workflow,
            [
                'blockers' =>
                    array_values($indexed),
            ],
            $actorId,
            'Workflow blocker added.'
        );
    }

    /**
     * Resolve one blocker.
     */
    public function resolveBlocker(
        array $workflow,
        string $blockerId,
        string $actorId,
        string $resolution = ''
    ): array {
        $blockers =
            $this->normalizeBlockers(
                $workflow['blockers']
                    ?? []
            );

        $found = false;

        foreach ($blockers as $index => $blocker) {
            if (
                ($blocker['blocker_id'] ?? '')
                !== $blockerId
            ) {
                continue;
            }

            $blockers[$index]['resolved'] =
                true;

            $blockers[$index]['resolved_by'] =
                $actorId;

            $blockers[$index]['resolved_at'] =
                gmdate('c');

            $blockers[$index]['resolution'] =
                trim($resolution);

            $found = true;
            break;
        }

        if (!$found) {
            throw new RuntimeException(
                'Workflow blocker was not found.'
            );
        }

        return $this->update(
            $workflow,
            [
                'blockers' =>
                    $blockers,
            ],
            $actorId,
            'Workflow blocker resolved.'
        );
    }

    /**
     * Return one stage.
     *
     * @return array<string,mixed>|null
     */
    public function stage(
        array $workflow,
        string $stageId
    ): ?array {
        foreach (
            $workflow['stages']
                ?? []
            as $stage
        ) {
            if (
                is_array($stage)
                && (
                    $stage['stage_id']
                        ?? ''
                ) === $stageId
            ) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Return current stage.
     *
     * @return array<string,mixed>|null
     */
    public function currentStage(
        array $workflow
    ): ?array {
        $stageId = trim(
            (string)(
                $workflow[
                    'current_stage_id'
                ] ?? ''
            )
        );

        return $stageId !== ''
            ? $this->stage(
                $workflow,
                $stageId
            )
            : null;
    }

    /**
     * Inspect workflow state.
     *
     * @return array<string,mixed>
     */
    public function inspect(
        array $workflow
    ): array {
        $this->assertWorkflow($workflow);

        $stages = $workflow['stages']
            ?? [];

        $stageSummary = [];

        foreach ($stages as $stage) {
            if (!is_array($stage)) {
                continue;
            }

            $status = (string)(
                $stage['status']
                    ?? 'pending'
            );

            $stageSummary[$status] =
                ($stageSummary[$status] ?? 0)
                + 1;
        }

        $activeBlockers = array_values(
            array_filter(
                $this->normalizeBlockers(
                    $workflow['blockers']
                        ?? []
                ),
                static fn (
                    array $blocker
                ): bool =>
                    ($blocker['resolved']
                        ?? false) !== true
            )
        );

        return [
            'workflow_id' =>
                $workflow[
                    'workflow_id'
                ],

            'generated_at' =>
                gmdate('c'),

            'status' =>
                $workflow['status']
                    ?? 'draft',

            'current_stage' =>
                $this->currentStage(
                    $workflow
                ),

            'progress' =>
                $this->calculateProgress(
                    $stages
                ),

            'validation' =>
                $this->validate(
                    $workflow
                ),

            'readiness' =>
                $this->calculateReadiness(
                    $workflow
                ),

            'stage_summary' =>
                $stageSummary,

            'active_blocker_count' =>
                count($activeBlockers),

            'active_blockers' =>
                $activeBlockers,

            'overdue' =>
                $this->isOverdue(
                    $workflow
                ),

            'available_transitions' =>
                $this->transitions[
                    $workflow['status']
                        ?? 'draft'
                ] ?? [],

            'checksum_valid' =>
                isset($workflow['checksum'])
                && hash_equals(
                    (string)$workflow[
                        'checksum'
                    ],
                    $this->calculateChecksum(
                        $workflow
                    )
                ),
        ];
    }

    /**
     * Return compact workflow summary.
     *
     * @return array<string,mixed>
     */
    public function summarize(
        array $workflow
    ): array {
        $this->assertWorkflow($workflow);

        return [
            'workflow_id' =>
                $workflow[
                    'workflow_id'
                ],

            'title' =>
                $workflow['title']
                    ?? '',

            'workflow_type' =>
                $workflow[
                    'workflow_type'
                ] ?? 'custom',

            'subject_id' =>
                $workflow['subject_id']
                    ?? '',

            'subject_type' =>
                $workflow['subject_type']
                    ?? 'entity',

            'status' =>
                $workflow['status']
                    ?? 'draft',

            'current_stage_id' =>
                $workflow[
                    'current_stage_id'
                ] ?? null,

            'stage_count' =>
                count(
                    $workflow['stages']
                        ?? []
                ),

            'progress' =>
                $this->calculateProgress(
                    $workflow['stages']
                        ?? []
                ),

            'priority' =>
                $workflow['priority']
                    ?? 50,

            'owner_id' =>
                $workflow['owner_id']
                    ?? null,

            'active_blocker_count' =>
                count(
                    array_filter(
                        $this->normalizeBlockers(
                            $workflow['blockers']
                                ?? []
                        ),
                        static fn (
                            array $blocker
                        ): bool =>
                            ($blocker['resolved']
                                ?? false) !== true
                    )
                ),

            'overdue' =>
                $this->isOverdue(
                    $workflow
                ),

            'readiness' =>
                $this->calculateReadiness(
                    $workflow
                ),

            'created_at' =>
                $workflow['created_at']
                    ?? null,

            'updated_at' =>
                $workflow['updated_at']
                    ?? null,

            'due_at' =>
                $workflow['due_at']
                    ?? null,

            'checksum' =>
                $workflow['checksum']
                    ?? null,
        ];
    }

    /**
     * Convert workflow into graph entity form.
     *
     * @return array<string,mixed>
     */
    public function toGraphEntity(
        array $workflow
    ): array {
        $this->assertWorkflow($workflow);

        return array_merge(
            $workflow,
            [
                'entity_id' =>
                    $workflow[
                        'workflow_id'
                    ],

                'entity_type' =>
                    'workflow',

                'graph_label' =>
                    $workflow['title']
                        ?? $workflow[
                            'workflow_id'
                        ],

                'graph_status' =>
                    $workflow['status']
                        ?? 'draft',
            ]
        );
    }

    /**
     * Create graph relationship from workflow to its subject.
     */
    public function subjectRelationship(
        array $workflow,
        string $actorId
    ): array {
        $this->assertWorkflow($workflow);

        return $this->relationships->create(
            [
                'source_id' =>
                    $workflow[
                        'workflow_id'
                    ],

                'source_type' =>
                    'workflow',

                'target_id' =>
                    $workflow['subject_id'],

                'target_type' =>
                    $workflow['subject_type'],

                'relationship_type' =>
                    'governs_work',

                'status' =>
                    in_array(
                        $workflow['status']
                            ?? '',
                        [
                            'active',
                            'completed',
                        ],
                        true
                    )
                        ? 'verified'
                        : 'proposed',

                'confidence' => 100,

                'weight' => 1,

                'strength' => 1,

                'created_by' =>
                    $actorId,

                'metadata' => [
                    'workflow_type' =>
                        $workflow[
                            'workflow_type'
                        ] ?? null,
                ],
            ]
        );
    }

    /**
     * Determine whether workflow is overdue.
     */
    public function isOverdue(
        array $workflow
    ): bool {
        $dueAt = trim(
            (string)(
                $workflow['due_at']
                    ?? ''
            )
        );

        if ($dueAt === '') {
            return false;
        }

        if (
            in_array(
                $workflow['status']
                    ?? '',
                [
                    'completed',
                    'cancelled',
                    'archived',
                ],
                true
            )
        ) {
            return false;
        }

        $timestamp = strtotime($dueAt);

        return $timestamp !== false
            && $timestamp < time();
    }

    /**
     * Require one existing stage.
     *
     * @return array<string,mixed>
     */
    private function requireStage(
        array $workflow,
        string $stageId
    ): array {
        $stageId = trim($stageId);

        if ($stageId === '') {
            throw new InvalidArgumentException(
                'Workflow stage identifier is required.'
            );
        }

        $stage = $this->stage(
            $workflow,
            $stageId
        );

        if ($stage === null) {
            throw new RuntimeException(
                'Workflow stage was not found.'
            );
        }

        return $stage;
    }

    /**
     * Determine whether a stage exists.
     *
     * @param array<int,array<string,mixed>> $stages
     */
    private function stageExists(
        array $stages,
        string $stageId
    ): bool {
        foreach ($stages as $stage) {
            if (
                is_array($stage)
                && (
                    $stage['stage_id']
                        ?? ''
                ) === $stageId
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return next incomplete stage identifier.
     */
    private function nextStageId(
        array $workflow,
        string $currentStageId
    ): ?string {
        $stages = $workflow['stages']
            ?? [];

        usort(
            $stages,
            static fn (
                array $left,
                array $right
            ): int =>
                (int)(
                    $left['position']
                        ?? 0
                )
                <=>
                (int)(
                    $right['position']
                        ?? 0
                )
        );

        $currentFound = false;

        foreach ($stages as $stage) {
            if (
                ($stage['stage_id'] ?? '')
                === $currentStageId
            ) {
                $currentFound = true;
                continue;
            }

            if (!$currentFound) {
                continue;
            }

            if (
                !in_array(
                    $stage['status']
                        ?? '',
                    [
                        'completed',
                        'skipped',
                        'cancelled',
                    ],
                    true
                )
            ) {
                return (string)(
                    $stage['stage_id']
                );
            }
        }

        return null;
    }

    /**
     * Determine whether dependencies are complete.
     *
     * @return array<string,mixed>
     */
    private function stageDependenciesSatisfied(
        array $workflow,
        array $stage
    ): array {
        $missing = [];

        foreach (
            $stage['depends_on']
                ?? []
            as $dependencyId
        ) {
            $dependency = $this->stage(
                $workflow,
                (string)$dependencyId
            );

            if (
                $dependency === null
                || !in_array(
                    $dependency['status']
                        ?? '',
                    [
                        'completed',
                        'skipped',
                    ],
                    true
                )
            ) {
                $missing[] =
                    (string)$dependencyId;
            }
        }

        return [
            'satisfied' =>
                $missing === [],

            'missing' => $missing,
        ];
    }

    /**
     * Determine whether all required stages are complete.
     */
    private function allRequiredStagesComplete(
        array $workflow
    ): bool {
        foreach (
            $workflow['stages']
                ?? []
            as $stage
        ) {
            if (!is_array($stage)) {
                return false;
            }

            if (
                ($stage['required'] ?? true)
                === true
                && !in_array(
                    $stage['status']
                        ?? '',
                    [
                        'completed',
                        'skipped',
                    ],
                    true
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check requirement satisfaction.
     *
     * @return array<string,mixed>
     */
    private function requirementsSatisfied(
        array $requirements
    ): array {
        $missing = [];

        foreach (
            $this->normalizeRequirements(
                $requirements
            )
            as $requirement
        ) {
            if (
                ($requirement['required']
                    ?? true) === true
                && ($requirement['satisfied']
                    ?? false) !== true
            ) {
                $missing[] =
                    $requirement[
                        'requirement_id'
                    ];
            }
        }

        return [
            'satisfied' =>
                $missing === [],

            'missing' => $missing,
        ];
    }

    /**
     * Mark one requirement satisfied.
     *
     * @return array<int,array<string,mixed>>
     */
    private function markRequirementSatisfied(
        array $requirements,
        string $requirementId,
        string $actorId,
        mixed $evidence
    ): array {
        $requirements =
            $this->normalizeRequirements(
                $requirements
            );

        $found = false;

        foreach (
            $requirements
            as $index => $requirement
        ) {
            if (
                ($requirement[
                    'requirement_id'
                ] ?? '') !== $requirementId
            ) {
                continue;
            }

            $requirements[$index]['satisfied'] =
                true;

            $requirements[$index][
                'satisfied_by'
            ] = $actorId;

            $requirements[$index][
                'satisfied_at'
            ] = gmdate('c');

            $requirements[$index]['evidence'] =
                $evidence;

            $found = true;
            break;
        }

        if (!$found) {
            throw new RuntimeException(
                'Workflow requirement was not found.'
            );
        }

        return $requirements;
    }

    /**
     * Evaluate one normalized condition.
     *
     * @return array<string,mixed>
     */
    private function evaluateCondition(
        array $condition,
        array $workflow,
        array $facts
    ): array {
        $field = trim(
            (string)(
                $condition['field']
                    ?? ''
            )
        );

        $operator =
            $this->normalizeMachineKey(
                (string)(
                    $condition['operator']
                        ?? 'equals'
                )
            );

        $expected =
            $condition['value']
                ?? true;

        $actual = $this->resolvePathValue(
            [
                'workflow' => $workflow,
                'facts' => $facts,
            ],
            $field
        );

        $passed = match ($operator) {
            'equals',
            'eq' =>
                $actual == $expected,

            'strict_equals',
            'same' =>
                $actual === $expected,

            'not_equals',
            'neq' =>
                $actual != $expected,

            'greater_than',
            'gt' =>
                is_numeric($actual)
                && is_numeric($expected)
                && (float)$actual
                    > (float)$expected,

            'greater_than_or_equal',
            'gte' =>
                is_numeric($actual)
                && is_numeric($expected)
                && (float)$actual
                    >= (float)$expected,

            'less_than',
            'lt' =>
                is_numeric($actual)
                && is_numeric($expected)
                && (float)$actual
                    < (float)$expected,

            'less_than_or_equal',
            'lte' =>
                is_numeric($actual)
                && is_numeric($expected)
                && (float)$actual
                    <= (float)$expected,

            'contains' =>
                is_array($actual)
                    ? in_array(
                        $expected,
                        $actual,
                        true
                    )
                    : str_contains(
                        (string)$actual,
                        (string)$expected
                    ),

            'exists' =>
                $actual !== null,

            'empty' =>
                $this->valueIsEmpty(
                    $actual
                ),

            'not_empty' =>
                !$this->valueIsEmpty(
                    $actual
                ),

            default => false,
        };

        return [
            'condition_id' =>
                $condition[
                    'condition_id'
                ] ?? null,

            'required' =>
                (bool)(
                    $condition['required']
                        ?? true
                ),

            'field' => $field,

            'operator' => $operator,

            'expected' => $expected,

            'actual' => $actual,

            'passed' => $passed,
        ];
    }

    /**
     * Resolve dotted array path.
     */
    private function resolvePathValue(
        array $source,
        string $path
    ): mixed {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        $segments = explode(
            '.',
            $path
        );

        $value = $source;

        foreach ($segments as $segment) {
            if (
                !is_array($value)
                || !array_key_exists(
                    $segment,
                    $value
                )
            ) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Record an unhandled event in workflow metadata.
     */
    private function recordUnhandledEvent(
        array $workflow,
        array $event,
        string $actorId
    ): array {
        $metadata = is_array(
            $workflow['metadata']
                ?? null
        )
            ? $workflow['metadata']
            : [];

        $metadata['unhandled_events'] =
            is_array(
                $metadata[
                    'unhandled_events'
                ] ?? null
            )
                ? $metadata[
                    'unhandled_events'
                ]
                : [];

        $metadata['unhandled_events'][] = [
            'event' => $event,

            'recorded_by' =>
                $actorId,

            'recorded_at' =>
                gmdate('c'),
        ];

        return $this->update(
            $workflow,
            [
                'metadata' => $metadata,
            ],
            $actorId,
            'Unhandled event recorded.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | NORMALIZATION, READINESS, CHECKSUMS, AND CLASS CLOSURE CONTINUE IN PART 4
    |--------------------------------------------------------------------------
    |
    | Do not close the class yet.
    |
    */    /**
     * Calculate workflow progress.
     *
     * @param array<int,array<string,mixed>> $stages
     */
    private function calculateProgress(
        array $stages
    ): float {
        if ($stages === []) {
            return 0.0;
        }

        $totalWeight = 0.0;
        $completedWeight = 0.0;

        foreach ($stages as $stage) {
            if (!is_array($stage)) {
                continue;
            }

            $weight = max(
                0.0,
                (float)(
                    $stage['weight']
                    ?? 1.0
                )
            );

            $totalWeight += $weight;

            $status = (string)(
                $stage['status']
                ?? 'pending'
            );

            $completion = match ($status) {
                'completed',
                'skipped' => 1.0,

                'active' => 0.5,

                'waiting',
                'blocked' => 0.35,

                'ready' => 0.15,

                default => 0.0,
            };

            $completedWeight +=
                $weight * $completion;
        }

        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        return round(
            min(
                100.0,
                max(
                    0.0,
                    (
                        $completedWeight
                        / $totalWeight
                    ) * 100
                )
            ),
            2
        );
    }

    /**
     * Calculate workflow readiness.
     *
     * @return array<string,mixed>
     */
    private function calculateReadiness(
        array $workflow
    ): array {
        $stages = is_array(
            $workflow['stages']
                ?? null
        )
            ? $workflow['stages']
            : [];

        $workflowRequirements =
            $this->requirementsSatisfied(
                $workflow['requirements']
                    ?? []
            );

        $activeBlockers = array_values(
            array_filter(
                $this->normalizeBlockers(
                    $workflow['blockers']
                        ?? []
                ),
                static fn (
                    array $blocker
                ): bool =>
                    ($blocker['resolved']
                        ?? false) !== true
            )
        );

        $stageStructureValid = true;

        try {
            $this->assertUniqueStageIds(
                $stages
            );

            $this->assertStageDependenciesExist(
                $stages
            );

            $this->assertNoStageDependencyCycles(
                $stages
            );
        } catch (Throwable) {
            $stageStructureValid = false;
        }

        $requirements = [
            'subject' =>
                trim(
                    (string)(
                        $workflow['subject_id']
                            ?? ''
                    )
                ) !== '',

            'title' =>
                trim(
                    (string)(
                        $workflow['title']
                            ?? ''
                    )
                ) !== '',

            'owner' =>
                trim(
                    (string)(
                        $workflow['owner_id']
                            ?? ''
                    )
                ) !== '',

            'stages' =>
                $stages !== [],

            'stage_structure' =>
                $stageStructureValid,

            'workflow_requirements' =>
                (
                    $workflowRequirements[
                        'satisfied'
                    ] ?? false
                ) === true,

            'blockers_clear' =>
                $activeBlockers === [],

            'checksum' =>
                trim(
                    (string)(
                        $workflow['checksum']
                            ?? ''
                    )
                ) !== '',
        ];

        $passed = count(
            array_filter(
                $requirements,
                static fn (
                    bool $value
                ): bool => $value
            )
        );

        $total = count($requirements);

        $score = $total > 0
            ? round(
                ($passed / $total) * 100,
                2
            )
            : 0.0;

        return [
            'ready' =>
                $requirements['subject']
                && $requirements['title']
                && $requirements['owner']
                && $requirements['stages']
                && $requirements[
                    'stage_structure'
                ]
                && $requirements[
                    'workflow_requirements'
                ]
                && $requirements[
                    'blockers_clear'
                ],

            'score' => $score,

            'passed' => $passed,

            'total' => $total,

            'requirements' =>
                $requirements,

            'missing' => array_keys(
                array_filter(
                    $requirements,
                    static fn (
                        bool $value
                    ): bool => !$value
                )
            ),

            'active_blocker_count' =>
                count($activeBlockers),

            'missing_requirement_ids' =>
                $workflowRequirements[
                    'missing'
                ] ?? [],
        ];
    }

    /**
     * Normalize workflow update fields.
     */
    private function normalizeFieldValue(
        string $field,
        mixed $value
    ): mixed {
        return match ($field) {
            'status' =>
                $this->normalizeWorkflowStatus(
                    (string)$value
                ),

            'workflow_type' =>
                $this->normalizeWorkflowType(
                    (string)$value
                ),

            'stages' =>
                $this->normalizeStages(
                    $value
                ),

            'assigned_to' =>
                $this->normalizeAssignees(
                    $value
                ),

            'participants' =>
                $this->normalizeParticipants(
                    $value
                ),

            'requirements' =>
                $this->normalizeRequirements(
                    $value
                ),

            'conditions' =>
                $this->normalizeConditions(
                    $value
                ),

            'blockers' =>
                $this->normalizeBlockers(
                    $value
                ),

            'priority' =>
                $this->normalizePriority(
                    $value
                ),

            'context' =>
                $this->normalizeContext(
                    $value
                ),

            'tags' =>
                $this->normalizeStringList(
                    $value
                ),

            'due_at' =>
                $this->normalizeDate(
                    $value
                ),

            'metadata',
            'input',
            'output' =>
                is_array($value)
                    ? $value
                    : [],

            default => $value,
        };
    }

    /**
     * Normalize assignees.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeAssignees(
        mixed $assignees
    ): array {
        if (is_string($assignees)) {
            $assignees = preg_split(
                '/[\r\n,;]+/',
                $assignees
            ) ?: [];
        }

        if (!is_array($assignees)) {
            return [];
        }

        if (
            $assignees !== []
            && !array_is_list($assignees)
        ) {
            $assignees = [$assignees];
        }

        $normalized = [];

        foreach ($assignees as $assignee) {
            if (is_string($assignee)) {
                $assignee = [
                    'actor_id' =>
                        trim($assignee),
                ];
            }

            if (!is_array($assignee)) {
                continue;
            }

            $actorId = trim(
                (string)(
                    $assignee['actor_id']
                        ?? $assignee['id']
                        ?? ''
                )
            );

            if ($actorId === '') {
                continue;
            }

            $normalized[$actorId] = [
                'actor_id' =>
                    $actorId,

                'name' => trim(
                    (string)(
                        $assignee['name']
                            ?? ''
                    )
                ),

                'email' => strtolower(
                    trim(
                        (string)(
                            $assignee['email']
                                ?? ''
                        )
                    )
                ),

                'role' =>
                    $this->normalizeMachineKey(
                        (string)(
                            $assignee['role']
                                ?? 'assignee'
                        )
                    ),

                'assigned_at' =>
                    $this->normalizeDate(
                        $assignee[
                            'assigned_at'
                        ] ?? null
                    )
                    ?? gmdate('c'),

                'metadata' => is_array(
                    $assignee['metadata']
                        ?? null
                )
                    ? $assignee['metadata']
                    : [],
            ];
        }

        return array_values($normalized);
    }

    /**
     * Normalize workflow participants.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeParticipants(
        mixed $participants
    ): array {
        if (is_string($participants)) {
            $participants = preg_split(
                '/[\r\n,;]+/',
                $participants
            ) ?: [];
        }

        if (!is_array($participants)) {
            return [];
        }

        if (
            $participants !== []
            && !array_is_list($participants)
        ) {
            $participants = [$participants];
        }

        $normalized = [];

        foreach ($participants as $participant) {
            if (is_string($participant)) {
                $participant = [
                    'participant_id' =>
                        trim($participant),
                ];
            }

            if (!is_array($participant)) {
                continue;
            }

            $participantId = trim(
                (string)(
                    $participant[
                        'participant_id'
                    ]
                    ?? $participant['actor_id']
                    ?? $participant['id']
                    ?? ''
                )
            );

            if ($participantId === '') {
                continue;
            }

            $normalized[$participantId] = [
                'participant_id' =>
                    $participantId,

                'name' => trim(
                    (string)(
                        $participant['name']
                            ?? ''
                    )
                ),

                'email' => strtolower(
                    trim(
                        (string)(
                            $participant['email']
                                ?? ''
                        )
                    )
                ),

                'role' =>
                    $this->normalizeMachineKey(
                        (string)(
                            $participant['role']
                                ?? 'participant'
                        )
                    ),

                'permissions' =>
                    $this->normalizeStringList(
                        $participant[
                            'permissions'
                        ] ?? []
                    ),

                'joined_at' =>
                    $this->normalizeDate(
                        $participant[
                            'joined_at'
                        ] ?? null
                    )
                    ?? gmdate('c'),

                'metadata' => is_array(
                    $participant['metadata']
                        ?? null
                )
                    ? $participant['metadata']
                    : [],
            ];
        }

        return array_values($normalized);
    }

    /**
     * Normalize workflow requirements.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeRequirements(
        mixed $requirements
    ): array {
        if (!is_array($requirements)) {
            return [];
        }

        if (
            $requirements !== []
            && !array_is_list($requirements)
        ) {
            $requirements = [$requirements];
        }

        $normalized = [];

        foreach ($requirements as $requirement) {
            if (is_string($requirement)) {
                $requirement = [
                    'description' =>
                        trim($requirement),
                ];
            }

            if (!is_array($requirement)) {
                continue;
            }

            $description = trim(
                (string)(
                    $requirement[
                        'description'
                    ] ?? $requirement['title']
                    ?? ''
                )
            );

            if ($description === '') {
                continue;
            }

            $requirementId = trim(
                (string)(
                    $requirement[
                        'requirement_id'
                    ] ?? ''
                )
            );

            if ($requirementId === '') {
                $requirementId =
                    $this->generateRequirementId(
                        $description
                    );
            }

            $normalized[$requirementId] = [
                'requirement_id' =>
                    $requirementId,

                'title' => trim(
                    (string)(
                        $requirement['title']
                            ?? $description
                    )
                ),

                'description' =>
                    $description,

                'type' =>
                    $this->normalizeMachineKey(
                        (string)(
                            $requirement['type']
                                ?? 'general'
                        )
                    ),

                'required' => (bool)(
                    $requirement['required']
                        ?? true
                ),

                'satisfied' => (bool)(
                    $requirement['satisfied']
                        ?? false
                ),

                'satisfied_by' => trim(
                    (string)(
                        $requirement[
                            'satisfied_by'
                        ] ?? ''
                    )
                ),

                'satisfied_at' =>
                    $this->normalizeDate(
                        $requirement[
                            'satisfied_at'
                        ] ?? null
                    ),

                'evidence' =>
                    $requirement['evidence']
                        ?? null,

                'metadata' => is_array(
                    $requirement['metadata']
                        ?? null
                )
                    ? $requirement['metadata']
                    : [],
            ];
        }

        return array_values($normalized);
    }

    /**
     * Normalize workflow conditions.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeConditions(
        mixed $conditions
    ): array {
        if (!is_array($conditions)) {
            return [];
        }

        if (
            $conditions !== []
            && !array_is_list($conditions)
        ) {
            $conditions = [$conditions];
        }

        $normalized = [];

        foreach ($conditions as $condition) {
            if (is_string($condition)) {
                $condition = [
                    'description' =>
                        trim($condition),
                ];
            }

            if (!is_array($condition)) {
                continue;
            }

            $field = trim(
                (string)(
                    $condition['field']
                        ?? ''
                )
            );

            $description = trim(
                (string)(
                    $condition[
                        'description'
                    ] ?? ''
                )
            );

            if (
                $field === ''
                && $description === ''
            ) {
                continue;
            }

            $conditionId = trim(
                (string)(
                    $condition[
                        'condition_id'
                    ] ?? ''
                )
            );

            if ($conditionId === '') {
                $conditionId =
                    $this->generateConditionId(
                        $condition
                    );
            }

            $normalized[$conditionId] = [
                'condition_id' =>
                    $conditionId,

                'description' =>
                    $description,

                'field' => $field,

                'operator' =>
                    $this->normalizeMachineKey(
                        (string)(
                            $condition[
                                'operator'
                            ] ?? 'equals'
                        )
                    ),

                'value' =>
                    $condition['value']
                        ?? true,

                'required' => (bool)(
                    $condition['required']
                        ?? true
                ),

                'metadata' => is_array(
                    $condition['metadata']
                        ?? null
                )
                    ? $condition['metadata']
                    : [],
            ];
        }

        return array_values($normalized);
    }

    /**
     * Normalize stage actions.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeActions(
        mixed $actions
    ): array {
        if (!is_array($actions)) {
            return [];
        }

        if (
            $actions !== []
            && !array_is_list($actions)
        ) {
            $actions = [$actions];
        }

        $normalized = [];

        foreach ($actions as $action) {
            if (is_string($action)) {
                $action = [
                    'title' =>
                        trim($action),
                ];
            }

            if (!is_array($action)) {
                continue;
            }

            $item = $this->normalizeAction(
                $action
            );

            $normalized[
                $item['action_id']
            ] = $item;
        }

        return array_values($normalized);
    }

    /**
     * Normalize one stage action.
     *
     * @return array<string,mixed>
     */
    private function normalizeAction(
        array $action
    ): array {
        $title = trim(
            (string)(
                $action['title']
                    ?? $action['name']
                    ?? ''
            )
        );

        if ($title === '') {
            throw new InvalidArgumentException(
                'Workflow action requires a title.'
            );
        }

        $actionId = trim(
            (string)(
                $action['action_id']
                    ?? ''
            )
        );

        if ($actionId === '') {
            $actionId =
                $this->generateActionId(
                    $title
                );
        }

        $status =
            $this->normalizeMachineKey(
                (string)(
                    $action['status']
                        ?? 'pending'
                )
            );

        if (
            !in_array(
                $status,
                [
                    'pending',
                    'ready',
                    'active',
                    'completed',
                    'failed',
                    'cancelled',
                ],
                true
            )
        ) {
            $status = 'pending';
        }

        return [
            'action_id' =>
                $actionId,

            'title' => $title,

            'description' => trim(
                (string)(
                    $action['description']
                        ?? ''
                )
            ),

            'action_type' =>
                $this->normalizeMachineKey(
                    (string)(
                        $action['action_type']
                            ?? $action['type']
                            ?? 'manual'
                    )
                ),

            'status' => $status,

            'required' => (bool)(
                $action['required']
                    ?? true
            ),

            'handler' => trim(
                (string)(
                    $action['handler']
                        ?? ''
                )
            ),

            'input' => is_array(
                $action['input']
                    ?? null
            )
                ? $action['input']
                : [],

            'output' => is_array(
                $action['output']
                    ?? null
            )
                ? $action['output']
                : [],

            'completed_by' => trim(
                (string)(
                    $action[
                        'completed_by'
                    ] ?? ''
                )
            ),

            'completed_at' =>
                $this->normalizeDate(
                    $action[
                        'completed_at'
                    ] ?? null
                ),

            'metadata' => is_array(
                $action['metadata']
                    ?? null
            )
                ? $action['metadata']
                : [],
        ];
    }

    /**
     * Normalize stage approvals.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeApprovals(
        mixed $approvals
    ): array {
        if (!is_array($approvals)) {
            return [];
        }

        if (
            $approvals !== []
            && !array_is_list($approvals)
        ) {
            $approvals = [$approvals];
        }

        $normalized = [];

        foreach ($approvals as $approval) {
            if (is_string($approval)) {
                $approval = [
                    'actor_id' =>
                        trim($approval),

                    'status' =>
                        'approved',
                ];
            }

            if (!is_array($approval)) {
                continue;
            }

            $actorId = trim(
                (string)(
                    $approval['actor_id']
                        ?? $approval['approved_by']
                        ?? ''
                )
            );

            if ($actorId === '') {
                continue;
            }

            $approvalId = trim(
                (string)(
                    $approval[
                        'approval_id'
                    ] ?? ''
                )
            );

            if ($approvalId === '') {
                $approvalId =
                    $this->generateApprovalId(
                        'stage',
                        $actorId
                    );
            }

            $normalized[$actorId] = [
                'approval_id' =>
                    $approvalId,

                'actor_id' =>
                    $actorId,

                'status' =>
                    $this->normalizeMachineKey(
                        (string)(
                            $approval['status']
                                ?? 'approved'
                        )
                    ),

                'reason' => trim(
                    (string)(
                        $approval['reason']
                            ?? ''
                    )
                ),

                'approved_at' =>
                    $this->normalizeDate(
                        $approval[
                            'approved_at'
                        ] ?? null
                    )
                    ?? gmdate('c'),

                'metadata' => is_array(
                    $approval['metadata']
                        ?? null
                )
                    ? $approval['metadata']
                    : [],
            ];
        }

        return array_values($normalized);
    }

    /**
     * Normalize workflow blockers.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeBlockers(
        mixed $blockers
    ): array {
        if (!is_array($blockers)) {
            return [];
        }

        if (
            $blockers !== []
            && !array_is_list($blockers)
        ) {
            $blockers = [$blockers];
        }

        $normalized = [];

        foreach ($blockers as $blocker) {
            if (is_string($blocker)) {
                $blocker = [
                    'description' =>
                        trim($blocker),
                ];
            }

            if (!is_array($blocker)) {
                continue;
            }

            $item =
                $this->normalizeBlocker(
                    $blocker
                );

            $normalized[
                $item['blocker_id']
            ] = $item;
        }

        return array_values($normalized);
    }

    /**
     * Normalize one blocker.
     *
     * @return array<string,mixed>
     */
    private function normalizeBlocker(
        array $blocker
    ): array {
        $description = trim(
            (string)(
                $blocker['description']
                    ?? $blocker['reason']
                    ?? ''
            )
        );

        if ($description === '') {
            throw new InvalidArgumentException(
                'Workflow blocker requires a description.'
            );
        }

        $blockerId = trim(
            (string)(
                $blocker['blocker_id']
                    ?? ''
            )
        );

        if ($blockerId === '') {
            $blockerId =
                $this->generateBlockerId(
                    $description
                );
        }

        return [
            'blocker_id' =>
                $blockerId,

            'type' =>
                $this->normalizeMachineKey(
                    (string)(
                        $blocker['type']
                            ?? 'general'
                    )
                ),

            'subject_id' => trim(
                (string)(
                    $blocker['subject_id']
                        ?? ''
                )
            ),

            'description' =>
                $description,

            'severity' =>
                $this->normalizeMachineKey(
                    (string)(
                        $blocker['severity']
                            ?? 'warning'
                    )
                ),

            'resolved' => (bool)(
                $blocker['resolved']
                    ?? false
            ),

            'created_by' => trim(
                (string)(
                    $blocker['created_by']
                        ?? ''
                )
            ),

            'created_at' =>
                $this->normalizeDate(
                    $blocker['created_at']
                        ?? null
                )
                ?? gmdate('c'),

            'resolved_by' => trim(
                (string)(
                    $blocker['resolved_by']
                        ?? ''
                )
            ),

            'resolved_at' =>
                $this->normalizeDate(
                    $blocker['resolved_at']
                        ?? null
                ),

            'resolution' => trim(
                (string)(
                    $blocker['resolution']
                        ?? ''
                )
            ),

            'metadata' => is_array(
                $blocker['metadata']
                    ?? null
            )
                ? $blocker['metadata']
                : [],
        ];
    }

    /**
     * Merge non-canonical workflow fields.
     *
     * @return array<string,mixed>
     */
    private function mergeAdditionalFields(
        array $workflow,
        array $input
    ): array {
        foreach ($input as $field => $value) {
            if (
                !array_key_exists(
                    $field,
                    $workflow
                )
            ) {
                $workflow[$field] =
                    $value;
            }
        }

        return $workflow;
    }

    /**
     * Assert canonical workflow structure.
     */
    private function assertWorkflow(
        array $workflow
    ): void {
        if (
            trim(
                (string)(
                    $workflow['workflow_id']
                        ?? ''
                )
            ) === ''
        ) {
            throw new InvalidArgumentException(
                'Workflow record requires workflow_id.'
            );
        }
    }

    /**
     * Normalize workflow lifecycle status.
     */
    private function normalizeWorkflowStatus(
        string $status
    ): string {
        $status =
            $this->normalizeMachineKey(
                $status
            );

        if (
            !in_array(
                $status,
                $this->statuses,
                true
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported workflow status "%s".',
                    $status
                )
            );
        }

        return $status;
    }

    /**
     * Normalize stage lifecycle status.
     */
    private function normalizeStageStatus(
        string $status
    ): string {
        $status =
            $this->normalizeMachineKey(
                $status
            );

        if (
            !in_array(
                $status,
                $this->stageStatuses,
                true
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported workflow stage status "%s".',
                    $status
                )
            );
        }

        return $status;
    }

    /**
     * Normalize workflow type.
     */
    private function normalizeWorkflowType(
        string $type
    ): string {
        $type =
            $this->normalizeMachineKey(
                $type
            );

        return $type !== ''
            ? $type
            : 'custom';
    }

    /**
     * Normalize stage mode.
     */
    private function normalizeStageMode(
        string $mode
    ): string {
        $mode =
            $this->normalizeMachineKey(
                $mode
            );

        if (
            !in_array(
                $mode,
                $this->stageModes,
                true
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported workflow stage mode "%s".',
                    $mode
                )
            );
        }

        return $mode;
    }

    /**
     * Normalize priority to 0–100.
     */
    private function normalizePriority(
        mixed $priority
    ): int {
        return max(
            0,
            min(
                100,
                (int)$priority
            )
        );
    }

    /**
     * Normalize date value.
     */
    private function normalizeDate(
        mixed $value
    ): ?string {
        if (
            $value === null
            || trim((string)$value) === ''
        ) {
            return null;
        }

        $timestamp = strtotime(
            (string)$value
        );

        return $timestamp !== false
            ? gmdate('c', $timestamp)
            : null;
    }

    /**
     * Normalize a string list.
     *
     * @return array<int,string>
     */
    private function normalizeStringList(
        mixed $values
    ): array {
        if (is_string($values)) {
            $values = preg_split(
                '/[\r\n,;]+/',
                $values
            ) ?: [];
        }

        if (!is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

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
     * Normalize machine-readable key.
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
     * Increment semantic-style version.
     */
    private function incrementVersion(
        string $version
    ): string {
        $version = trim($version);

        if (
            preg_match(
                '/^(\d+)\.(\d+)(?:\.(\d+))?$/',
                $version,
                $matches
            ) === 1
        ) {
            $major = (int)$matches[1];
            $minor = (int)$matches[2];

            if (isset($matches[3])) {
                return sprintf(
                    '%d.%d.%d',
                    $major,
                    $minor,
                    (int)$matches[3] + 1
                );
            }

            return sprintf(
                '%d.%d',
                $major,
                $minor + 1
            );
        }

        return '1.1';
    }

    /**
     * Build default workflow title.
     */
    private function defaultTitle(
        string $workflowType,
        string $subjectId
    ): string {
        return sprintf(
            '%s workflow for %s',
            ucwords(
                str_replace(
                    '_',
                    ' ',
                    $workflowType
                )
            ),
            $subjectId
        );
    }

    /**
     * Calculate deterministic workflow checksum.
     */
    private function calculateChecksum(
        array $workflow
    ): string {
        $copy = $workflow;

        foreach (
            $this->checksumExcludedFields
            as $field
        ) {
            unset($copy[$field]);
        }

        $json = json_encode(
            $this->normalizeForHash(
                $copy
            ),
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
        );

        if ($json === false) {
            throw new RuntimeException(
                'Unable to calculate workflow checksum.'
            );
        }

        return hash('sha256', $json);
    }

    /**
     * Generate workflow identifier.
     */
    private function generateWorkflowId(
        string $workflowType,
        string $subjectId
    ): string {
        $prefix = strtoupper(
            substr(
                preg_replace(
                    '/[^A-Za-z0-9]+/',
                    '',
                    $workflowType
                ) ?: 'WFL',
                0,
                3
            )
        );

        return 'WFL-'
            . $prefix
            . '-'
            . gmdate('Ymd-His')
            . '-'
            . $this->randomToken(5);
    }

    /**
     * Generate stage identifier.
     */
    private function generateStageId(
        string $title,
        int $position
    ): string {
        return 'STG-'
            . str_pad(
                (string)max(
                    0,
                    $position
                ),
                3,
                '0',
                STR_PAD_LEFT
            )
            . '-'
            . strtoupper(
                substr(
                    hash(
                        'sha256',
                        $title
                        . '|'
                        . $position
                        . '|'
                        . microtime(true)
                    ),
                    0,
                    12
                )
            );
    }

    /**
     * Generate requirement identifier.
     */
    private function generateRequirementId(
        string $description
    ): string {
        return 'REQ-'
            . strtoupper(
                substr(
                    hash(
                        'sha256',
                        $description
                        . '|'
                        . microtime(true)
                    ),
                    0,
                    14
                )
            );
    }

    /**
     * Generate condition identifier.
     */
    private function generateConditionId(
        array $condition
    ): string {
        return 'CND-'
            . strtoupper(
                substr(
                    hash(
                        'sha256',
                        json_encode(
                            $this->normalizeForHash(
                                $condition
                            ),
                            JSON_UNESCAPED_SLASHES
                            | JSON_UNESCAPED_UNICODE
                        ) ?: uniqid('', true)
                    ),
                    0,
                    14
                )
            );
    }

    /**
     * Generate action identifier.
     */
    private function generateActionId(
        string $title
    ): string {
        return 'ACT-'
            . strtoupper(
                substr(
                    hash(
                        'sha256',
                        $title
                        . '|'
                        . microtime(true)
                    ),
                    0,
                    14
                )
            );
    }

    /**
     * Generate approval identifier.
     */
    private function generateApprovalId(
        string $stageId,
        string $actorId
    ): string {
        return 'APR-'
            . strtoupper(
                substr(
                    hash(
                        'sha256',
                        $stageId
                        . '|'
                        . $actorId
                        . '|'
                        . microtime(true)
                    ),
                    0,
                    14
                )
            );
    }

    /**
     * Generate blocker identifier.
     */
    private function generateBlockerId(
        string $description
    ): string {
        return 'BLK-'
            . strtoupper(
                substr(
                    hash(
                        'sha256',
                        $description
                        . '|'
                        . microtime(true)
                    ),
                    0,
                    14
                )
            );
    }

    /**
     * Generate random uppercase token.
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
}