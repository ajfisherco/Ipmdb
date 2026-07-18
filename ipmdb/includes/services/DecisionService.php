<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/DecisionService.php
|--------------------------------------------------------------------------
| IPMdb Decision Service
|--------------------------------------------------------------------------
|
| Coordinates attributable decisions affecting ideas, assets,
| relationships, workflows, programs, and graph entities.
|
| Responsibilities:
| - Create canonical decision records.
| - Preserve actor, authority, rationale, evidence, provenance, and scope.
| - Manage proposal, review, approval, rejection, execution, and appeal.
| - Record options considered and selected outcomes.
| - Require explicit human attribution for consequential decisions.
| - Maintain deterministic checksums and revision context.
| - Produce graph-ready decision entities and decision relationships.
| - Support voting, consensus, quorum, and delegated authority records.
| - Calculate decision completeness, validity, and execution readiness.
|
| Recommendations advise.
| Rules evaluate.
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
require_once __DIR__ . '/ProvenanceService.php';
require_once __DIR__ . '/VersionService.php';
require_once __DIR__ . '/EventService.php';
require_once __DIR__ . '/RelationshipService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class DecisionService extends Service
{
    use GraphUtilities;

    private ValidationService $validation;

    private ProvenanceService $provenance;

    private VersionService $versions;

    private EventService $events;

    private RelationshipService $relationships;

    /**
     * Supported lifecycle states.
     *
     * @var array<int,string>
     */
    private array $statuses = [
        'draft',
        'proposed',
        'under_review',
        'pending_vote',
        'approved',
        'rejected',
        'deferred',
        'blocked',
        'executing',
        'executed',
        'failed',
        'appealed',
        'reversed',
        'superseded',
        'archived',
    ];

    /**
     * Allowed lifecycle transitions.
     *
     * @var array<string,array<int,string>>
     */
    private array $transitions = [
        'draft' => [
            'proposed',
            'under_review',
            'rejected',
            'archived',
        ],

        'proposed' => [
            'draft',
            'under_review',
            'pending_vote',
            'approved',
            'rejected',
            'deferred',
            'blocked',
            'archived',
        ],

        'under_review' => [
            'proposed',
            'pending_vote',
            'approved',
            'rejected',
            'deferred',
            'blocked',
            'archived',
        ],

        'pending_vote' => [
            'under_review',
            'approved',
            'rejected',
            'deferred',
            'blocked',
            'archived',
        ],

        'approved' => [
            'executing',
            'executed',
            'blocked',
            'appealed',
            'reversed',
            'superseded',
            'archived',
        ],

        'rejected' => [
            'draft',
            'proposed',
            'appealed',
            'archived',
        ],

        'deferred' => [
            'draft',
            'proposed',
            'under_review',
            'pending_vote',
            'rejected',
            'archived',
        ],

        'blocked' => [
            'draft',
            'proposed',
            'under_review',
            'approved',
            'rejected',
            'archived',
        ],

        'executing' => [
            'executed',
            'failed',
            'blocked',
            'reversed',
            'archived',
        ],

        'executed' => [
            'appealed',
            'reversed',
            'superseded',
            'archived',
        ],

        'failed' => [
            'draft',
            'proposed',
            'approved',
            'executing',
            'archived',
        ],

        'appealed' => [
            'under_review',
            'approved',
            'rejected',
            'reversed',
            'archived',
        ],

        'reversed' => [
            'draft',
            'proposed',
            'superseded',
            'archived',
        ],

        'superseded' => [
            'archived',
        ],

        'archived' => [
            'draft',
            'proposed',
        ],
    ];

    /**
     * Supported decision types.
     *
     * @var array<int,string>
     */
    private array $decisionTypes = [
        'approval',
        'rejection',
        'selection',
        'classification',
        'prioritization',
        'allocation',
        'authorization',
        'verification',
        'validation',
        'deployment',
        'implementation',
        'funding',
        'policy',
        'governance',
        'relationship',
        'provenance',
        'moderation',
        'workflow',
        'appeal',
        'reversal',
        'supersession',
        'other',
    ];

    /**
     * Supported decision methods.
     *
     * @var array<int,string>
     */
    private array $methods = [
        'individual',
        'delegated',
        'consensus',
        'majority_vote',
        'supermajority_vote',
        'unanimous_vote',
        'rule_based',
        'administrative',
        'emergency',
        'automated_proposal',
    ];

    /**
     * Fields protected after creation.
     *
     * @var array<int,string>
     */
    private array $immutableFields = [
        'decision_id',
        'entity_id',
        'entity_type',
        'created_at',
        'created_by',
        'proposed_by',
        'subject_id',
        'subject_type',
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
        'view_count',
        'search_score',
        'analytics',
        'runtime',
    ];

    /**
     * Completeness weights.
     *
     * @var array<string,float>
     */
    private array $completenessWeights = [
        'title' => 0.10,
        'decision_type' => 0.08,
        'subject_id' => 0.10,
        'subject_type' => 0.05,
        'rationale' => 0.14,
        'selected_option_id' => 0.08,
        'authority' => 0.08,
        'proposed_by' => 0.08,
        'evidence' => 0.08,
        'provenance_id' => 0.08,
        'method' => 0.05,
        'options' => 0.04,
        'effective_at' => 0.04,
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?ValidationService $validation = null,
        ?ProvenanceService $provenance = null,
        ?VersionService $versions = null,
        ?EventService $events = null,
        ?RelationshipService $relationships = null
    ) {
        parent::__construct($config, $context);

        $this->validation = $validation
            ?? new ValidationService();

        $this->provenance = $provenance
            ?? new ProvenanceService();

        $this->versions = $versions
            ?? new VersionService();

        $this->events = $events
            ?? new EventService();

        $this->relationships = $relationships
            ?? new RelationshipService();

        if (
            isset($config['decision_types'])
            && is_array($config['decision_types'])
        ) {
            $this->decisionTypes = $this->normalizeStringList(
                array_merge(
                    $this->decisionTypes,
                    $config['decision_types']
                )
            );
        }

        if (
            isset($config['methods'])
            && is_array($config['methods'])
        ) {
            $this->methods = $this->normalizeStringList(
                array_merge(
                    $this->methods,
                    $config['methods']
                )
            );
        }
    }

    /**
     * Create one canonical decision.
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
                    ?? $input['proposed_by']
                    ?? ''
                )
        );

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Decision creation requires actor attribution.'
            );
        }

        $subjectId = trim(
            (string)(
                $input['subject_id']
                ?? ''
            )
        );

        $subjectType = $this->normalizeMachineKey(
            (string)(
                $input['subject_type']
                ?? 'entity'
            )
        );

        if ($subjectId === '') {
            throw new InvalidArgumentException(
                'Decision subject identifier is required.'
            );
        }

        $decisionType = $this->normalizeDecisionType(
            (string)(
                $input['decision_type']
                ?? $input['type']
                ?? 'other'
            )
        );

        $title = trim(
            (string)(
                $input['title']
                ?? $this->defaultTitle(
                    $decisionType,
                    $subjectId
                )
            )
        );

        $method = $this->normalizeMethod(
            (string)(
                $input['method']
                ?? 'individual'
            )
        );

        $decisionId = trim(
            (string)(
                $input['decision_id']
                ?? ''
            )
        );

        if ($decisionId === '') {
            $decisionId = $this->generateDecisionId(
                $decisionType,
                $subjectId
            );
        }

        $now = gmdate('c');

        $options = $this->normalizeOptions(
            $input['options']
                ?? []
        );

        $selectedOptionId = trim(
            (string)(
                $input['selected_option_id']
                ?? ''
            )
        );

        if (
            $selectedOptionId !== ''
            && !$this->optionExists(
                $options,
                $selectedOptionId
            )
        ) {
            throw new InvalidArgumentException(
                'Selected decision option does not exist.'
            );
        }

        $metadata = is_array(
            $input['metadata']
                ?? null
        )
            ? $input['metadata']
            : [];

        $metadata['decision_service'] = array_merge(
            is_array(
                $metadata['decision_service']
                    ?? null
            )
                ? $metadata['decision_service']
                : [],
            [
                'created_by_service' =>
                    static::class,

                'created_at' =>
                    $now,
            ]
        );

        $decision = [
            'decision_id' =>
                $decisionId,

            'entity_id' =>
                $decisionId,

            'entity_type' =>
                'decision',

            'subject_id' =>
                $subjectId,

            'subject_type' =>
                $subjectType !== ''
                    ? $subjectType
                    : 'entity',

            'title' => $title,

            'description' => trim(
                (string)(
                    $input['description']
                    ?? ''
                )
            ),

            'decision_type' =>
                $decisionType,

            'method' => $method,

            'status' => $this->normalizeStatus(
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

            'rationale' => trim(
                (string)(
                    $input['rationale']
                    ?? ''
                )
            ),

            'authority' => trim(
                (string)(
                    $input['authority']
                    ?? ''
                )
            ),

            'authority_reference' => trim(
                (string)(
                    $input[
                        'authority_reference'
                    ] ?? ''
                )
            ),

            'scope' => trim(
                (string)(
                    $input['scope']
                    ?? ''
                )
            ),

            'options' => $options,

            'selected_option_id' =>
                $selectedOptionId !== ''
                    ? $selectedOptionId
                    : null,

            'outcome' =>
                $input['outcome']
                ?? null,

            'conditions' =>
                $this->normalizeConditions(
                    $input['conditions']
                    ?? []
                ),

            'consequences' =>
                $this->normalizeStringList(
                    $input['consequences']
                    ?? []
                ),

            'evidence' =>
                $this->normalizeEvidence(
                    $input['evidence']
                    ?? []
                ),

            'provenance_id' => trim(
                (string)(
                    $input['provenance_id']
                    ?? ''
                )
            ),

            'source_reference' => trim(
                (string)(
                    $input['source_reference']
                    ?? $input['source_url']
                    ?? ''
                )
            ),

            'proposed_by' =>
                $actorId,

            'proposed_at' =>
                $now,

            'created_by' =>
                $actorId,

            'created_at' =>
                $now,

            'updated_by' =>
                $actorId,

            'updated_at' =>
                $now,

            'reviewed_by' =>
                null,

            'reviewed_at' =>
                null,

            'approved_by' =>
                null,

            'approved_at' =>
                null,

            'rejected_by' =>
                null,

            'rejected_at' =>
                null,

            'rejection_reason' =>
                null,

            'deferred_by' =>
                null,

            'deferred_at' =>
                null,

            'defer_reason' =>
                null,

            'blocked_by' =>
                null,

            'blocked_at' =>
                null,

            'block_reason' =>
                null,

            'executed_by' =>
                null,

            'execution_started_at' =>
                null,

            'executed_at' =>
                null,

            'execution_result' =>
                null,

            'execution_error' =>
                null,

            'appealed_by' =>
                null,

            'appealed_at' =>
                null,

            'appeal_reason' =>
                null,

            'reversed_by' =>
                null,

            'reversed_at' =>
                null,

            'reversal_reason' =>
                null,

            'superseded_by_decision_id' =>
                null,

            'superseded_at' =>
                null,

            'effective_at' =>
                $this->normalizeDate(
                    $input['effective_at']
                    ?? null
                ),

            'expires_at' =>
                $this->normalizeDate(
                    $input['expires_at']
                    ?? null
                ),

            'quorum_required' => max(
                0,
                (int)(
                    $input['quorum_required']
                    ?? 0
                )
            ),

            'approval_threshold' =>
                $this->normalizeThreshold(
                    $input['approval_threshold']
                    ?? 0.5
                ),

            'votes' =>
                $this->normalizeVotes(
                    $input['votes']
                    ?? []
                ),

            'delegations' =>
                $this->normalizeDelegations(
                    $input['delegations']
                    ?? []
                ),

            'tags' =>
                $this->normalizeStringList(
                    $input['tags']
                    ?? []
                ),

            'metadata' =>
                $metadata,

            'checksum' => '',
        ];

        $decision = $this->mergeAdditionalFields(
            $decision,
            $input
        );

        $decision['vote_summary'] =
            $this->calculateVoteSummary(
                $decision['votes'],
                $decision[
                    'approval_threshold'
                ],
                $decision[
                    'quorum_required'
                ]
            );

        $decision['checksum'] =
            $this->calculateChecksum(
                $decision
            );

        $decision['completeness'] =
            $this->calculateCompleteness(
                $decision
            );

        $decision['readiness'] =
            $this->calculateReadiness(
                $decision
            );

        $validation = $this->validate(
            $decision
        );

        if (
            ($validation['valid'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Decision validation failed: '
                . implode(
                    ' ',
                    $validation['errors']
                    ?? []
                )
            );
        }

        $this->addMessage(
            'Decision created.',
            [
                'decision_id' =>
                    $decisionId,

                'decision_type' =>
                    $decisionType,

                'status' =>
                    $decision['status'],
            ]
        );

        return $decision;
    }

    /**
     * Update one decision.
     *
     * @param array<string,mixed> $decision
     * @param array<string,mixed> $changes
     *
     * @return array<string,mixed>
     */
    public function update(
        array $decision,
        array $changes,
        string $actorId,
        string $reason = ''
    ): array {
        $this->assertDecision(
            $decision
        );

        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Decision update requires actor attribution.'
            );
        }

        if (
            in_array(
                (string)(
                    $decision['status']
                    ?? ''
                ),
                [
                    'executed',
                    'reversed',
                    'superseded',
                    'archived',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Finalized decision requires a new decision or formal reversal.'
            );
        }

        $updated = $decision;

        foreach ($changes as $field => $value) {
            $field = trim((string)$field);

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
                    $decision['version']
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

        $updated['vote_summary'] =
            $this->calculateVoteSummary(
                $updated['votes']
                    ?? [],
                (float)(
                    $updated[
                        'approval_threshold'
                    ] ?? 0.5
                ),
                (int)(
                    $updated[
                        'quorum_required'
                    ] ?? 0
                )
            );

        $updated['checksum'] =
            $this->calculateChecksum(
                $updated
            );

        $updated['completeness'] =
            $this->calculateCompleteness(
                $updated
            );

        $updated['readiness'] =
            $this->calculateReadiness(
                $updated
            );

        $validation = $this->validate(
            $updated
        );

        if (
            ($validation['valid'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Updated decision is invalid: '
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
     * Transition decision lifecycle status.
     */
    public function transition(
        array $decision,
        string $newStatus,
        string $actorId,
        string $reason = ''
    ): array {
        $this->assertDecision(
            $decision
        );

        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Decision transition requires actor attribution.'
            );
        }

        $currentStatus =
            $this->normalizeStatus(
                (string)(
                    $decision['status']
                    ?? 'draft'
                )
            );

        $newStatus =
            $this->normalizeStatus(
                $newStatus
            );

        if ($currentStatus === $newStatus) {
            return $decision;
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
                    'Decision status cannot transition from "%s" to "%s".',
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
            case 'under_review':
                $changes['reviewed_by'] =
                    $actorId;

                $changes['reviewed_at'] =
                    $now;
                break;

            case 'approved':
                $changes['approved_by'] =
                    $actorId;

                $changes['approved_at'] =
                    $now;

                if (
                    empty(
                        $decision[
                            'effective_at'
                        ]
                    )
                ) {
                    $changes['effective_at'] =
                        $now;
                }
                break;

            case 'rejected':
                $changes['rejected_by'] =
                    $actorId;

                $changes['rejected_at'] =
                    $now;

                $changes['rejection_reason'] =
                    trim($reason);
                break;

            case 'deferred':
                $changes['deferred_by'] =
                    $actorId;

                $changes['deferred_at'] =
                    $now;

                $changes['defer_reason'] =
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

            case 'executing':
                $changes[
                    'execution_started_at'
                ] = $now;

                $changes['executed_by'] =
                    $actorId;
                break;

            case 'executed':
                $changes['executed_by'] =
                    $actorId;

                $changes['executed_at'] =
                    $now;
                break;

            case 'failed':
                $changes['executed_by'] =
                    $actorId;

                $changes['execution_error'] =
                    trim($reason);
                break;

            case 'appealed':
                $changes['appealed_by'] =
                    $actorId;

                $changes['appealed_at'] =
                    $now;

                $changes['appeal_reason'] =
                    trim($reason);
                break;

            case 'reversed':
                $changes['reversed_by'] =
                    $actorId;

                $changes['reversed_at'] =
                    $now;

                $changes['reversal_reason'] =
                    trim($reason);
                break;

            case 'superseded':
                $changes['superseded_at'] =
                    $now;
                break;
        }

        return $this->update(
            $decision,
            $changes,
            $actorId,
            $reason !== ''
                ? $reason
                : sprintf(
                    'Status changed from %s to %s.',
                    $currentStatus,
                    $newStatus
                )
        );
    }

    /**
     * Propose one decision.
     */
    public function propose(
        array $decision,
        string $actorId,
        string $reason = ''
    ): array {
        return $this->transition(
            $decision,
            'proposed',
            $actorId,
            $reason
        );
    }

    /**
     * Begin formal review.
     */
    public function beginReview(
        array $decision,
        string $actorId,
        string $reason = ''
    ): array {
        return $this->transition(
            $decision,
            'under_review',
            $actorId,
            $reason
        );
    }

    /**
     * Open voting.
     */
    public function openVote(
        array $decision,
        string $actorId,
        string $reason = ''
    ): array {
        return $this->transition(
            $decision,
            'pending_vote',
            $actorId,
            $reason
        );
    }

    /**
     * Approve one decision.
     */
    public function approve(
        array $decision,
        string $actorId,
        string $reason = ''
    ): array {
        $readiness = $this->calculateReadiness(
            $decision
        );

        if (
            ($readiness['approvable'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Decision approval requirements are incomplete.'
            );
        }

        if (
            in_array(
                (string)(
                    $decision['method']
                    ?? ''
                ),
                [
                    'majority_vote',
                    'supermajority_vote',
                    'unanimous_vote',
                    'consensus',
                ],
                true
            )
            && (
                $decision[
                    'vote_summary'
                ]['passed'] ?? false
            ) !== true
        ) {
            throw new RuntimeException(
                'Decision vote requirements have not passed.'
            );
        }

        return $this->transition(
            $decision,
            'approved',
            $actorId,
            $reason
        );
    }

    /**
     * Reject one decision.
     */
    public function reject(
        array $decision,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Decision rejection requires a reason.'
            );
        }

        return $this->transition(
            $decision,
            'rejected',
            $actorId,
            $reason
        );
    }

    /**
     * Defer one decision.
     */
    public function defer(
        array $decision,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Decision deferral requires a reason.'
            );
        }

        return $this->transition(
            $decision,
            'deferred',
            $actorId,
            $reason
        );
    }

    /**
     * Begin execution.
     */
    public function beginExecution(
        array $decision,
        string $actorId,
        string $reason = ''
    ): array {
        if (
            ($decision['status'] ?? '')
            !== 'approved'
        ) {
            throw new RuntimeException(
                'Only approved decisions may begin execution.'
            );
        }

        return $this->transition(
            $decision,
            'executing',
            $actorId,
            $reason
        );
    }

    /**
     * Complete execution.
     */
    public function completeExecution(
        array $decision,
        string $actorId,
        mixed $result = null,
        string $reason = ''
    ): array {
        if (
            !in_array(
                (string)(
                    $decision['status']
                    ?? ''
                ),
                [
                    'approved',
                    'executing',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Decision is not in an executable state.'
            );
        }

        $updated = $this->transition(
            $decision,
            'executed',
            $actorId,
            $reason
        );

        $updated['execution_result'] =
            $result;

        $updated['checksum'] =
            $this->calculateChecksum(
                $updated
            );

        return $updated;
    }

    /**
     * Record execution failure.
     */
    public function failExecution(
        array $decision,
        string $actorId,
        string $error
    ): array {
        if (trim($error) === '') {
            throw new InvalidArgumentException(
                'Execution failure requires an error description.'
            );
        }

        return $this->transition(
            $decision,
            'failed',
            $actorId,
            $error
        );
    }

    /**
     * Appeal one decision.
     */
    public function appeal(
        array $decision,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Decision appeal requires a reason.'
            );
        }

        return $this->transition(
            $decision,
            'appealed',
            $actorId,
            $reason
        );
    }

    /**
     * Reverse one decision.
     */
    public function reverse(
        array $decision,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Decision reversal requires a reason.'
            );
        }

        return $this->transition(
            $decision,
            'reversed',
            $actorId,
            $reason
        );
    }

    /**
     * Supersede one decision with another.
     */
    public function supersede(
        array $decision,
        string $replacementDecisionId,
        string $actorId,
        string $reason = ''
    ): array {
        $replacementDecisionId = trim(
            $replacementDecisionId
        );

        if ($replacementDecisionId === '') {
            throw new InvalidArgumentException(
                'Replacement decision identifier is required.'
            );
        }

        $updated = $this->transition(
            $decision,
            'superseded',
            $actorId,
            $reason
        );

        $updated[
            'superseded_by_decision_id'
        ] = $replacementDecisionId;

        $updated['checksum'] =
            $this->calculateChecksum(
                $updated
            );

        return $updated;
    }

    /**
     * Add or replace one option.
     */
    public function addOption(
        array $decision,
        array $option,
        string $actorId
    ): array {
        $normalized = $this->normalizeOption(
            $option
        );

        $options = $this->normalizeOptions(
            $decision['options']
                ?? []
        );

        $indexed = [];

        foreach ($options as $item) {
            $indexed[
                $item['option_id']
            ] = $item;
        }

        $indexed[
            $normalized['option_id']
        ] = $normalized;

        return $this->update(
            $decision,
            [
                'options' =>
                    array_values($indexed),
            ],
            $actorId,
            'Decision option added or updated.'
        );
    }

    /**
     * Remove one option.
     */
    public function removeOption(
        array $decision,
        string $optionId,
        string $actorId
    ): array {
        $optionId = trim($optionId);

        $options = array_values(
            array_filter(
                $this->normalizeOptions(
                    $decision['options']
                        ?? []
                ),
                static fn (
                    array $option
                ): bool =>
                    (
                        $option['option_id']
                        ?? ''
                    ) !== $optionId
            )
        );

        $changes = [
            'options' => $options,
        ];

        if (
            (
                $decision[
                    'selected_option_id'
                ] ?? null
            ) === $optionId
        ) {
            $changes['selected_option_id'] =
                null;
        }

        return $this->update(
            $decision,
            $changes,
            $actorId,
            'Decision option removed.'
        );
    }

    /**
     * Select one decision option.
     */
    public function selectOption(
        array $decision,
        string $optionId,
        string $actorId,
        string $reason = ''
    ): array {
        $optionId = trim($optionId);

        if (
            !$this->optionExists(
                $decision['options']
                    ?? [],
                $optionId
            )
        ) {
            throw new InvalidArgumentException(
                'Selected decision option does not exist.'
            );
        }

        return $this->update(
            $decision,
            [
                'selected_option_id' =>
                    $optionId,
            ],
            $actorId,
            $reason !== ''
                ? $reason
                : 'Decision option selected.'
        );
    }

    /**
     * Record one vote.
     */
    public function castVote(
        array $decision,
        string $voterId,
        string $choice,
        string $actorId = '',
        float $weight = 1.0,
        string $reason = ''
    ): array {
        $this->assertDecision(
            $decision
        );

        $voterId = trim($voterId);

        if ($voterId === '') {
            throw new InvalidArgumentException(
                'Vote requires voter attribution.'
            );
        }

        $actorId = trim(
            $actorId !== ''
                ? $actorId
                : $voterId
        );

        $choice =
            $this->normalizeVoteChoice(
                $choice
            );

        $weight = max(
            0.0,
            min(
                1000000.0,
                $weight
            )
        );

        $votes = $this->normalizeVotes(
            $decision['votes']
                ?? []
        );

        $indexed = [];

        foreach ($votes as $vote) {
            $indexed[
                $vote['voter_id']
            ] = $vote;
        }

        $indexed[$voterId] = [
            'vote_id' =>
                $this->generateVoteId(
                    $decision['decision_id'],
                    $voterId
                ),

            'voter_id' =>
                $voterId,

            'choice' =>
                $choice,

            'weight' =>
                $weight,

            'reason' =>
                trim($reason),

            'cast_by' =>
                $actorId,

            'cast_at' =>
                gmdate('c'),

            'metadata' => [],
        ];

        return $this->update(
            $decision,
            [
                'votes' =>
                    array_values($indexed),
            ],
            $actorId,
            'Vote recorded.'
        );
    }

    /**
     * Remove one vote.
     */
    public function removeVote(
        array $decision,
        string $voterId,
        string $actorId
    ): array {
        $voterId = trim($voterId);

        $votes = array_values(
            array_filter(
                $this->normalizeVotes(
                    $decision['votes']
                        ?? []
                ),
                static fn (
                    array $vote
                ): bool =>
                    (
                        $vote['voter_id']
                        ?? ''
                    ) !== $voterId
            )
        );

        return $this->update(
            $decision,
            [
                'votes' => $votes,
            ],
            $actorId,
            'Vote removed.'
        );
    }

    /**
     * Add delegated authority.
     */
    public function addDelegation(
        array $decision,
        array $delegation,
        string $actorId
    ): array {
        $normalized =
            $this->normalizeDelegation(
                $delegation
            );

        $delegations =
            $this->normalizeDelegations(
                $decision['delegations']
                    ?? []
            );

        $indexed = [];

        foreach ($delegations as $item) {
            $indexed[
                $item['delegation_id']
            ] = $item;
        }

        $indexed[
            $normalized['delegation_id']
        ] = $normalized;

        return $this->update(
            $decision,
            [
                'delegations' =>
                    array_values($indexed),
            ],
            $actorId,
            'Decision delegation added.'
        );
    }

    /**
     * Add evidence.
     */
    public function addEvidence(
        array $decision,
        array $evidence,
        string $actorId
    ): array {
        $existing = $this->normalizeEvidence(
            $decision['evidence']
                ?? []
        );

        $incoming = $this->normalizeEvidence(
            $evidence
        );

        return $this->update(
            $decision,
            [
                'evidence' =>
                    $this->deduplicateEvidence(
                        array_merge(
                            $existing,
                            $incoming
                        )
                    ),
            ],
            $actorId,
            'Decision evidence updated.'
        );
    }

    /**
     * Create a graph relationship from this decision to another entity.
     */
    public function createRelationship(
        array $decision,
        array $target,
        string $relationshipType,
        string $actorId,
        array $options = []
    ): array {
        $this->assertDecision(
            $decision
        );

        $targetId = $this->resolveRecordId(
            $target
        );

        if ($targetId === '') {
            throw new InvalidArgumentException(
                'Relationship target requires an identifier.'
            );
        }

        $targetType =
            $this->normalizeMachineKey(
                (string)(
                    $target['entity_type']
                    ?? $target['type']
                    ?? 'entity'
                )
            );

        return $this->relationships->create(
            array_merge(
                [
                    'source_id' =>
                        $decision[
                            'decision_id'
                        ],

                    'source_type' =>
                        'decision',

                    'target_id' =>
                        $targetId,

                    'target_type' =>
                        $targetType,

                    'relationship_type' =>
                        $this->normalizeMachineKey(
                            $relationshipType
                        ),

                    'status' =>
                        'proposed',

                    'confidence' =>
                        100,

                    'weight' => 1,

                    'strength' => 1,

                    'created_by' =>
                        $actorId,

                    'provenance_id' =>
                        $decision[
                            'provenance_id'
                        ] ?? '',

                    'metadata' => [
                        'created_by_service' =>
                            static::class,
                    ],
                ],
                $options
            )
        );
    }

    /**
     * Create canonical relationship to the decision subject.
     */
    public function subjectRelationship(
        array $decision,
        string $actorId
    ): array {
        $this->assertDecision(
            $decision
        );

        return $this->relationships->create(
            [
                'source_id' =>
                    $decision['decision_id'],

                'source_type' =>
                    'decision',

                'target_id' =>
                    $decision['subject_id'],

                'target_type' =>
                    $decision['subject_type'],

                'relationship_type' =>
                    'decides',

                'status' =>
                    in_array(
                        $decision['status']
                            ?? '',
                        [
                            'approved',
                            'executed',
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

                'provenance_id' =>
                    $decision[
                        'provenance_id'
                    ] ?? '',

                'metadata' => [
                    'decision_status' =>
                        $decision['status']
                        ?? null,
                ],
            ]
        );
    }

    /**
     * Convert decision to graph entity form.
     */
    public function toGraphEntity(
        array $decision
    ): array {
        $this->assertDecision(
            $decision
        );

        return array_merge(
            $decision,
            [
                'entity_id' =>
                    $decision[
                        'decision_id'
                    ],

                'entity_type' =>
                    'decision',

                'graph_label' =>
                    $decision['title']
                    ?? $decision[
                        'decision_id'
                    ],

                'graph_status' =>
                    $decision['status']
                    ?? 'draft',
            ]
        );
    }

    /**
     * Validate one decision.
     *
     * @return array<string,mixed>
     */
    public function validate(
        array $decision
    ): array {
        $errors = [];
        $warnings = [];

        foreach (
            [
                'decision_id',
                'entity_id',
                'entity_type',
                'subject_id',
                'subject_type',
                'title',
                'decision_type',
                'method',
                'status',
                'version',
                'proposed_by',
                'created_by',
                'created_at',
                'updated_at',
            ]
            as $field
        ) {
            if (
                $this->valueIsEmpty(
                    $decision[$field]
                        ?? null
                )
            ) {
                $errors[] = sprintf(
                    'Decision field "%s" is required.',
                    $field
                );
            }
        }

        if (
            isset($decision['entity_type'])
            && $decision['entity_type']
                !== 'decision'
        ) {
            $errors[] =
                'Decision entity type must be "decision".';
        }

        try {
            $status = $this->normalizeStatus(
                (string)(
                    $decision['status']
                    ?? 'draft'
                )
            );
        } catch (Throwable $exception) {
            $status = '';

            $errors[] =
                $exception->getMessage();
        }

        try {
            $this->normalizeDecisionType(
                (string)(
                    $decision[
                        'decision_type'
                    ] ?? 'other'
                )
            );
        } catch (Throwable $exception) {
            $errors[] =
                $exception->getMessage();
        }

        try {
            $this->normalizeMethod(
                (string)(
                    $decision['method']
                    ?? 'individual'
                )
            );
        } catch (Throwable $exception) {
            $errors[] =
                $exception->getMessage();
        }

        $selectedOptionId = trim(
            (string)(
                $decision[
                    'selected_option_id'
                ] ?? ''
            )
        );

        if (
            $selectedOptionId !== ''
            && !$this->optionExists(
                $decision['options']
                    ?? [],
                $selectedOptionId
            )
        ) {
            $errors[] =
                'Selected option does not exist.';
        }

        if (
            in_array(
                $status,
                [
                    'approved',
                    'executing',
                    'executed',
                ],
                true
            )
            && trim(
                (string)(
                    $decision['rationale']
                    ?? ''
                )
            ) === ''
        ) {
            $errors[] =
                'Approved decision requires rationale.';
        }

        if (
            in_array(
                $status,
                [
                    'approved',
                    'executing',
                    'executed',
                ],
                true
            )
            && trim(
                (string)(
                    $decision['approved_by']
                    ?? ''
                )
            ) === ''
        ) {
            $errors[] =
                'Approved decision requires approving actor attribution.';
        }

        if (
            $status === 'executed'
            && trim(
                (string)(
                    $decision['executed_by']
                    ?? ''
                )
            ) === ''
        ) {
            $errors[] =
                'Executed decision requires execution attribution.';
        }

        if (
            in_array(
                $status,
                [
                    'rejected',
                    'deferred',
                    'blocked',
                    'appealed',
                    'reversed',
                ],
                true
            )
        ) {
            $reasonField = match ($status) {
                'rejected' =>
                    'rejection_reason',

                'deferred' =>
                    'defer_reason',

                'blocked' =>
                    'block_reason',

                'appealed' =>
                    'appeal_reason',

                'reversed' =>
                    'reversal_reason',

                default => '',
            };

            if (
                $reasonField !== ''
                && trim(
                    (string)(
                        $decision[$reasonField]
                        ?? ''
                    )
                ) === ''
            ) {
                $errors[] = sprintf(
                    'Decision status "%s" requires a reason.',
                    $status
                );
            }
        }

        if (
            trim(
                (string)(
                    $decision['rationale']
                    ?? ''
                )
            ) === ''
        ) {
            $warnings[] =
                'Decision rationale is empty.';
        }

        if (
            ($decision['evidence'] ?? [])
            === []
        ) {
            $warnings[] =
                'Decision has no supporting evidence.';
        }

        if (
            trim(
                (string)(
                    $decision[
                        'provenance_id'
                    ] ?? ''
                )
            ) === ''
            && trim(
                (string)(
                    $decision[
                        'source_reference'
                    ] ?? ''
                )
            ) === ''
        ) {
            $warnings[] =
                'Decision has no provenance reference.';
        }

        $storedChecksum = trim(
            (string)(
                $decision['checksum']
                ?? ''
            )
        );

        if (
            $storedChecksum !== ''
            && !hash_equals(
                $storedChecksum,
                $this->calculateChecksum(
                    $decision
                )
            )
        ) {
            $errors[] =
                'Decision checksum does not match content.';
        }

        return [
            'valid' =>
                $errors === [],

            'error_count' =>
                count($errors),

            'warning_count' =>
                count($warnings),

            'errors' =>
                $errors,

            'warnings' =>
                $warnings,
        ];
    }

    /**
     * Inspect one decision.
     *
     * @return array<string,mixed>
     */
    public function inspect(
        array $decision
    ): array {
        $this->assertDecision(
            $decision
        );

        return [
            'decision_id' =>
                $decision[
                    'decision_id'
                ],

            'generated_at' =>
                gmdate('c'),

            'validation' =>
                $this->validate(
                    $decision
                ),

            'completeness' =>
                $this->calculateCompleteness(
                    $decision
                ),

            'readiness' =>
                $this->calculateReadiness(
                    $decision
                ),

            'vote_summary' =>
                $this->calculateVoteSummary(
                    $decision['votes']
                        ?? [],
                    (float)(
                        $decision[
                            'approval_threshold'
                        ] ?? 0.5
                    ),
                    (int)(
                        $decision[
                            'quorum_required'
                        ] ?? 0
                    )
                ),

            'checksum_valid' =>
                isset($decision['checksum'])
                && hash_equals(
                    (string)$decision[
                        'checksum'
                    ],
                    $this->calculateChecksum(
                        $decision
                    )
                ),

            'available_transitions' =>
                $this->availableTransitions(
                    $decision
                ),

            'currently_effective' =>
                $this->isEffective(
                    $decision
                ),
        ];
    }

    /**
     * Return available transitions.
     *
     * @return array<int,string>
     */
    public function availableTransitions(
        array $decision
    ): array {
        $status = $this->normalizeStatus(
            (string)(
                $decision['status']
                ?? 'draft'
            )
        );

        return $this->transitions[$status]
            ?? [];
    }

    /**
     * Determine whether decision is currently effective.
     */
    public function isEffective(
        array $decision
    ): bool {
        if (
            !in_array(
                (string)(
                    $decision['status']
                    ?? ''
                ),
                [
                    'approved',
                    'executing',
                    'executed',
                ],
                true
            )
        ) {
            return false;
        }

        $now = time();

        $effectiveAt = trim(
            (string)(
                $decision['effective_at']
                ?? ''
            )
        );

        if ($effectiveAt !== '') {
            $timestamp = strtotime(
                $effectiveAt
            );

            if (
                $timestamp !== false
                && $timestamp > $now
            ) {
                return false;
            }
        }

        $expiresAt = trim(
            (string)(
                $decision['expires_at']
                ?? ''
            )
        );

        if ($expiresAt !== '') {
            $timestamp = strtotime(
                $expiresAt
            );

            if (
                $timestamp !== false
                && $timestamp < $now
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return compact decision summary.
     *
     * @return array<string,mixed>
     */
    public function summarize(
        array $decision
    ): array {
        $this->assertDecision(
            $decision
        );

        return [
            'decision_id' =>
                $decision[
                    'decision_id'
                ],

            'title' =>
                $decision['title']
                ?? '',

            'decision_type' =>
                $decision[
                    'decision_type'
                ] ?? 'other',

            'subject_id' =>
                $decision['subject_id']
                ?? '',

            'subject_type' =>
                $decision['subject_type']
                ?? 'entity',

            'status' =>
                $decision['status']
                ?? 'draft',

            'method' =>
                $decision['method']
                ?? 'individual',

            'selected_option_id' =>
                $decision[
                    'selected_option_id'
                ] ?? null,

            'option_count' =>
                count(
                    $decision['options']
                    ?? []
                ),

            'evidence_count' =>
                count(
                    $decision['evidence']
                    ?? []
                ),

            'vote_summary' =>
                $decision['vote_summary']
                ?? $this->calculateVoteSummary(
                    $decision['votes']
                        ?? [],
                    (float)(
                        $decision[
                            'approval_threshold'
                        ] ?? 0.5
                    ),
                    (int)(
                        $decision[
                            'quorum_required'
                        ] ?? 0
                    )
                ),

            'effective' =>
                $this->isEffective(
                    $decision
                ),

            'completeness' =>
                $this->calculateCompleteness(
                    $decision
                ),

            'readiness' =>
                $this->calculateReadiness(
                    $decision
                ),

            'created_at' =>
                $decision['created_at']
                ?? null,

            'updated_at' =>
                $decision['updated_at']
                ?? null,

            'checksum' =>
                $decision['checksum']
                ?? null,
        ];
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
                'statuses' =>
                    $this->statuses,

                'transitions' =>
                    $this->transitions,

                'decision_types' =>
                    $this->decisionTypes,

                'methods' =>
                    $this->methods,

                'immutable_fields' =>
                    $this->immutableFields,

                'completeness_weights' =>
                    $this->completenessWeights,

                'supports_voting' =>
                    true,

                'supports_consensus' =>
                    true,

                'supports_delegation' =>
                    true,

                'supports_appeal' =>
                    true,

                'supports_reversal' =>
                    true,

                'database_operations' =>
                    false,

                'automatic_execution' =>
                    false,

                'human_attribution_required' =>
                    true,

                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Calculate completeness.
     */
    private function calculateCompleteness(
        array $decision
    ): float {
        $score = 0.0;

        foreach (
            $this->completenessWeights
            as $field => $weight
        ) {
            if (
                !$this->valueIsEmpty(
                    $decision[$field]
                        ?? null
                )
            ) {
                $score += $weight;
            }
        }

        return round(
            min(1.0, $score) * 100,
            2
        );
    }

    /**
     * Calculate approval and execution readiness.
     *
     * @return array<string,mixed>
     */
    private function calculateReadiness(
        array $decision
    ): array {
        $method = (string)(
            $decision['method']
            ?? 'individual'
        );

        $voteSummary =
            $this->calculateVoteSummary(
                $decision['votes']
                    ?? [],
                (float)(
                    $decision[
                        'approval_threshold'
                    ] ?? 0.5
                ),
                (int)(
                    $decision[
                        'quorum_required'
                    ] ?? 0
                )
            );

        $votingRequired = in_array(
            $method,
            [
                'majority_vote',
                'supermajority_vote',
                'unanimous_vote',
                'consensus',
            ],
            true
        );

        $requirements = [
            'subject' =>
                trim(
                    (string)(
                        $decision[
                            'subject_id'
                        ] ?? ''
                    )
                ) !== '',

            'title' =>
                trim(
                    (string)(
                        $decision['title']
                        ?? ''
                    )
                ) !== '',

            'rationale' =>
                trim(
                    (string)(
                        $decision[
                            'rationale'
                        ] ?? ''
                    )
                ) !== '',

            'authority' =>
                trim(
                    (string)(
                        $decision[
                            'authority'
                        ] ?? ''
                    )
                ) !== '',

            'attribution' =>
                trim(
                    (string)(
                        $decision[
                            'proposed_by'
                        ] ?? ''
                    )
                ) !== '',

            'selection' =>
                (
                    $decision['options']
                    ?? []
                ) === []
                || trim(
                    (string)(
                        $decision[
                            'selected_option_id'
                        ] ?? ''
                    )
                ) !== '',

            'vote' =>
                !$votingRequired
                || (
                    $voteSummary['passed']
                    ?? false
                ),

            'checksum' =>
                trim(
                    (string)(
                        $decision['checksum']
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
            'score' => $score,

            'passed' => $passed,

            'total' => $total,

            'approvable' =>
                $requirements['subject']
                && $requirements['title']
                && $requirements[
                    'rationale'
                ]
                && $requirements[
                    'attribution'
                ]
                && $requirements[
                    'selection'
                ]
                && $requirements['vote']
                && $requirements[
                    'checksum'
                ],

            'executable' =>
                in_array(
                    (string)(
                        $decision['status']
                        ?? ''
                    ),
                    [
                        'approved',
                        'executing',
                    ],
                    true
                )
                && $requirements[
                    'subject'
                ]
                && $requirements[
                    'attribution'
                ]
                && $requirements[
                    'checksum'
                ],

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
        ];
    }

    /**
     * Calculate vote summary.
     *
     * @param array<int,array<string,mixed>> $votes
     * @return array<string,mixed>
     */
    private function calculateVoteSummary(
        array $votes,
        float $threshold,
        int $quorumRequired
    ): array {
        $votes = $this->normalizeVotes(
            $votes
        );

        $totals = [
            'approve' => 0.0,
            'reject' => 0.0,
            'abstain' => 0.0,
        ];

        foreach ($votes as $vote) {
            $choice = $vote['choice']
                ?? 'abstain';

            $weight = (float)(
                $vote['weight']
                ?? 1
            );

            $totals[$choice] =
                ($totals[$choice] ?? 0)
                + $weight;
        }

        $participatingWeight =
            $totals['approve']
            + $totals['reject'];

        $approvalRatio =
            $participatingWeight > 0
                ? $totals['approve']
                    / $participatingWeight
                : 0.0;

        $quorumMet =
            $quorumRequired <= 0
            || count($votes)
                >= $quorumRequired;

        return [
            'vote_count' =>
                count($votes),

            'approve_weight' =>
                round(
                    $totals['approve'],
                    6
                ),

            'reject_weight' =>
                round(
                    $totals['reject'],
                    6
                ),

            'abstain_weight' =>
                round(
                    $totals['abstain'],
                    6
                ),

            'participating_weight' =>
                round(
                    $participatingWeight,
                    6
                ),

            'approval_ratio' =>
                round(
                    $approvalRatio,
                    6
                ),

            'approval_threshold' =>
                $threshold,

            'quorum_required' =>
                $quorumRequired,

            'quorum_met' =>
                $quorumMet,

            'passed' =>
                $quorumMet
                && $participatingWeight > 0
                && $approvalRatio
                    >= $threshold,
        ];
    }

    /**
     * Normalize update field value.
     */
    private function normalizeFieldValue(
        string $field,
        mixed $value
    ): mixed {
        return match ($field) {
            'status' =>
                $this->normalizeStatus(
                    (string)$value
                ),

            'decision_type' =>
                $this->normalizeDecisionType(
                    (string)$value
                ),

            'method' =>
                $this->normalizeMethod(
                    (string)$value
                ),

            'options' =>
                $this->normalizeOptions(
                    $value
                ),

            'conditions' =>
                $this->normalizeConditions(
                    $value
                ),

            'consequences',
            'tags' =>
                $this->normalizeStringList(
                    $value
                ),

            'evidence' =>
                $this->normalizeEvidence(
                    $value
                ),

            'votes' =>
                $this->normalizeVotes(
                    $value
                ),

            'delegations' =>
                $this->normalizeDelegations(
                    $value
                ),

            'approval_threshold' =>
                $this->normalizeThreshold(
                    $value
                ),

            'quorum_required' =>
                max(0, (int)$value),

            'effective_at',
            'expires_at' =>
                $this->normalizeDate(
                    $value
                ),

            'metadata' =>
                is_array($value)
                    ? $value
                    : [],

            default => $value,
        };
    }

    /**
     * Normalize decision options.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeOptions(
        mixed $options
    ): array {
        if (!is_array($options)) {
            return [];
        }

        if (
            $options !== []
            && !array_is_list($options)
        ) {
            $options = [$options];
        }

        $normalized = [];

        foreach ($options as $option) {
            if (is_string($option)) {
                $option = [
                    'label' => $option,
                ];
            }

            if (!is_array($option)) {
                continue;
            }

            $item = $this->normalizeOption(
                $option
            );

            $normalized[
                $item['option_id']
            ] = $item;
        }

        return array_values($normalized);
    }

    /**
     * Normalize one option.
     *
     * @return array<string,mixed>
     */
    private function normalizeOption(
        array $option
    ): array {
        $label = trim(
            (string)(
                $option['label']
                ?? $option['title']
                ?? $option['name']
                ?? ''
            )
        );

        if ($label === '') {
            throw new InvalidArgumentException(
                'Decision option requires a label.'
            );
        }

        $optionId = trim(
            (string)(
                $option['option_id']
                ?? ''
            )
        );

        if ($optionId === '') {
            $optionId =
                $this->generateOptionId(
                    $label
                );
        }

        return [
            'option_id' =>
                $optionId,

            'label' => $label,

            'description' => trim(
                (string)(
                    $option['description']
                    ?? ''
                )
            ),

            'value' =>
                $option['value']
                ?? null,

            'benefits' =>
                $this->normalizeStringList(
                    $option['benefits']
                    ?? []
                ),

            'risks' =>
                $this->normalizeStringList(
                    $option['risks']
                    ?? []
                ),

            'cost' =>
                $option['cost']
                ?? null,

            'score' =>
                isset($option['score'])
                    ? (float)$option['score']
                    : null,

            'metadata' => is_array(
                $option['metadata']
                    ?? null
            )
                ? $option['metadata']
                : [],
        ];
    }

    /**
     * Determine whether an option exists.
     */
    private function optionExists(
        array $options,
        string $optionId
    ): bool {
        foreach (
            $this->normalizeOptions($options)
            as $option
        ) {
            if (
                ($option['option_id'] ?? '')
                === $optionId
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize conditions.
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
                        $condition,
                ];
            }

            if (!is_array($condition)) {
                continue;
            }

            $normalized[] = [
                'condition_id' =>
                    trim(
                        (string)(
                            $condition[
                                'condition_id'
                            ] ?? ''
                        )
                    )
                    ?: $this
                        ->generateConditionId(
                            $condition
                        ),

                'description' => trim(
                    (string)(
                        $condition[
                            'description'
                        ] ?? ''
                    )
                ),

                'required' =>
                    (bool)(
                        $condition['required']
                        ?? true
                    ),

                'satisfied' =>
                    (bool)(
                        $condition['satisfied']
                        ?? false
                    ),

                'satisfied_by' => trim(
                    (string)(
                        $condition[
                            'satisfied_by'
                        ] ?? ''
                    )
                ),

                'satisfied_at' =>
                    $this->normalizeDate(
                        $condition[
                            'satisfied_at'
                        ] ?? null
                    ),

                'metadata' => is_array(
                    $condition['metadata']
                        ?? null
                )
                    ? $condition['metadata']
                    : [],
            ];
        }

        return $normalized;
    }

    /**
     * Normalize votes.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeVotes(
        mixed $votes
    ): array {
        if (!is_array($votes)) {
            return [];
        }

        if (
            $votes !== []
            && !array_is_list($votes)
        ) {
            $votes = [$votes];
        }

        $normalized = [];

        foreach ($votes as $vote) {
            if (!is_array($vote)) {
                continue;
            }

            $voterId = trim(
                (string)(
                    $vote['voter_id']
                    ?? ''
                )
            );

            if ($voterId === '') {
                continue;
            }

            $item = [
                'vote_id' => trim(
                    (string)(
                        $vote['vote_id']
                        ?? ''
                    )
                )
                    ?: $this->generateVoteId(
                        'decision',
                        $voterId
                    ),

                'voter_id' =>
                    $voterId,

                'choice' =>
                    $this->normalizeVoteChoice(
                        (string)(
                            $vote['choice']
                            ?? 'abstain'
                        )
                    ),

                'weight' => max(
                    0.0,
                    (float)(
                        $vote['weight']
                        ?? 1
                    )
                ),

                'reason' => trim(
                    (string)(
                        $vote['reason']
                        ?? ''
                    )
                ),

                'cast_by' => trim(
                    (string)(
                        $vote['cast_by']
                        ?? $voterId
                    )
                ),

                'cast_at' =>
                    $this->normalizeDate(
                        $vote['cast_at']
                            ?? null
                    )
                    ?? gmdate('c'),

                'metadata' => is_array(
                    $vote['metadata']
                        ?? null
                )
                    ? $vote['metadata']
                    : [],
            ];

            $normalized[$voterId] =
                $item;
        }

        return array_values($normalized);
    }

    /**
     * Normalize delegations.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeDelegations(
        mixed $delegations
    ): array {
        if (!is_array($delegations)) {
            return [];
        }

        if (
            $delegations !== []
            && !array_is_list($delegations)
        ) {
            $delegations = [$delegations];
        }

        $normalized = [];

        foreach ($delegations as $delegation) {
            if (!is_array($delegation)) {
                continue;
            }

            $item = $this->normalizeDelegation(
                $delegation
            );

            $normalized[
                $item['delegation_id']
            ] = $item;
        }

        return array_values($normalized);
    }

    /**
     * Normalize one delegation.
     *
     * @return array<string,mixed>
     */
    private function normalizeDelegation(
        array $delegation
    ): array {
        $delegatorId = trim(
            (string)(
                $delegation['delegator_id']
                ?? ''
            )
        );

        $delegateId = trim(
            (string)(
                $delegation['delegate_id']
                ?? ''
            )
        );

        if (
            $delegatorId === ''
            || $delegateId === ''
        ) {
            throw new InvalidArgumentException(
                'Delegation requires delegator and delegate identifiers.'
            );
        }

        return [
            'delegation_id' => trim(
                (string)(
                    $delegation[
                        'delegation_id'
                    ] ?? ''
                )
            )
                ?: $this->generateDelegationId(
                    $delegatorId,
                    $delegateId
                ),

            'delegator_id' =>
                $delegatorId,

            'delegate_id' =>
                $delegateId,

            'scope' => trim(
                (string)(
                    $delegation['scope']
                    ?? ''
                )
            ),

            'weight' => max(
                0.0,
                (float)(
                    $delegation['weight']
                    ?? 1
                )
            ),

            'valid_from' =>
                $this->normalizeDate(
                    $delegation['valid_from']
                        ?? null
                ),

            'valid_to' =>
                $this->normalizeDate(
                    $delegation['valid_to']
                        ?? null
                ),

            'created_at' =>
                $this->normalizeDate(
                    $delegation['created_at']
                        ?? null
                )
                ?? gmdate('c'),

            'metadata' => is_array(
                $delegation['metadata']
                    ?? null
            )
                ? $delegation['metadata']
                : [],
        ];
    }

    /**
     * Normalize evidence.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeEvidence(
        mixed $evidence
    ): array {
        if (!is_array($evidence)) {
            return [];
        }

        if (
            $evidence !== []
            && !array_is_list($evidence)
        ) {
            $evidence = [$evidence];
        }

        $normalized = [];

        foreach ($evidence as $item) {
            if (is_string($item)) {
                $item = [
                    'description' => $item,
                ];
            }

            if (!is_array($item)) {
                continue;
            }

            $evidenceId = trim(
                (string)(
                    $item['evidence_id']
                    ?? ''
                )
            );

            if ($evidenceId === '') {
                $evidenceId =
                    $this->generateEvidenceId(
                        $item
                    );
            }

            $normalized[] = [
                'evidence_id' =>
                    $evidenceId,

                'type' =>
                    $this->normalizeMachineKey(
                        (string)(
                            $item['type']
                            ?? 'supporting'
                        )
                    ),

                'title' => trim(
                    (string)(
                        $item['title']
                        ?? ''
                    )
                ),

                'description' => trim(
                    (string)(
                        $item['description']
                        ?? ''
                    )
                ),

                'source_reference' => trim(
                    (string)(
                        $item[
                            'source_reference'
                        ] ?? $item['url']
                        ?? ''
                    )
                ),

                'provenance_id' => trim(
                    (string)(
                        $item['provenance_id']
                        ?? ''
                    )
                ),

                'confidence' => max(
                    0.0,
                    min(
                        100.0,
                        (float)(
                            $item['confidence']
                            ?? 100
                        )
                    )
                ),

                'created_by' => trim(
                    (string)(
                        $item['created_by']
                        ?? ''
                    )
                ),

                'created_at' =>
                    $this->normalizeDate(
                        $item['created_at']
                            ?? null
                    )
                    ?? gmdate('c'),

                'metadata' => is_array(
                    $item['metadata']
                        ?? null
                )
                    ? $item['metadata']
                    : [],
            ];
        }

        return $this->deduplicateEvidence(
            $normalized
        );
    }

    /**
     * Deduplicate evidence.
     *
     * @return array<int,array<string,mixed>>
     */
    private function deduplicateEvidence(
        array $evidence
    ): array {
        $unique = [];

        foreach ($evidence as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = trim(
                (string)(
                    $item['evidence_id']
                    ?? ''
                )
            );

            if ($key === '') {
                $key = hash(
                    'sha256',
                    json_encode(
                        $this->normalizeForHash(
                            $item
                        ),
                        JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                    ) ?: ''
                );
            }

            $unique[$key] = $item;
        }

        return array_values($unique);
    }

    /**
     * Merge non-canonical fields.
     */
    private function mergeAdditionalFields(
        array $decision,
        array $input
    ): array {
        foreach ($input as $field => $value) {
            if (
                !array_key_exists(
                    $field,
                    $decision
                )
            ) {
                $decision[$field] =
                    $value;
            }
        }

        return $decision;
    }

    /**
     * Resolve generic record identifier.
     */
    private function resolveRecordId(
        array $record
    ): string {
        foreach (
            [
                'entity_id',
                'decision_id',
                'idea_id',
                'asset_id',
                'program_id',
                'document_id',
                'organization_id',
                'person_id',
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

        return '';
    }

    /**
     * Assert canonical decision record.
     */
    private function assertDecision(
        array $decision
    ): void {
        if (
            trim(
                (string)(
                    $decision[
                        'decision_id'
                    ] ?? ''
                )
            ) === ''
        ) {
            throw new InvalidArgumentException(
                'Decision record requires decision_id.'
            );
        }
    }

    /**
     * Normalize status.
     */
    private function normalizeStatus(
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
                    'Unsupported decision status "%s".',
                    $status
                )
            );
        }

        return $status;
    }

    /**
     * Normalize decision type.
     */
    private function normalizeDecisionType(
        string $type
    ): string {
        $type = $this->normalizeMachineKey(
            $type
        );

        return $type !== ''
            ? $type
            : 'other';
    }

    /**
     * Normalize decision method.
     */
    private function normalizeMethod(
        string $method
    ): string {
        $method = $this->normalizeMachineKey(
            $method
        );

        if (
            !in_array(
                $method,
                $this->methods,
                true
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported decision method "%s".',
                    $method
                )
            );
        }

        return $method;
    }

    /**
     * Normalize vote choice.
     */
    private function normalizeVoteChoice(
        string $choice
    ): string {
        $choice = $this->normalizeMachineKey(
            $choice
        );

        return match ($choice) {
            'yes',
            'approve',
            'approved',
            'for' => 'approve',

            'no',
            'reject',
            'rejected',
            'against' => 'reject',

            default => 'abstain',
        };
    }

    /**
     * Normalize approval threshold.
     */
    private function normalizeThreshold(
        mixed $value
    ): float {
        $threshold = (float)$value;

        if ($threshold > 1.0) {
            $threshold /= 100.0;
        }

        return max(
            0.0,
            min(
                1.0,
                $threshold
            )
        );
    }

    /**
     * Normalize date.
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
     * Normalize string list.
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
     * Create default title.
     */
    private function defaultTitle(
        string $decisionType,
        string $subjectId
    ): string {
        return sprintf(
            '%s decision for %s',
            ucwords(
                str_replace(
                    '_',
                    ' ',
                    $decisionType
                )
            ),
            $subjectId
        );
    }

    /**
     * Calculate deterministic checksum.
     */
    private function calculateChecksum(
        array $decision
    ): string {
        $copy = $decision;

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
                'Unable to calculate decision checksum.'
            );
        }

        return hash('sha256', $json);
    }

    /**
     * Generate decision identifier.
     */
    private function generateDecisionId(
        string $decisionType,
        string $subjectId
    ): string {
        $typePrefix = strtoupper(
            substr(
                preg_replace(
                    '/[^A-Za-z0-9]+/',
                    '',
                    $decisionType
                ) ?: 'DEC',
                0,
                3
            )
        );

        return 'DEC-'
            . $typePrefix
            . '-'
            . gmdate('Ymd-His')
            . '-'
            . $this->randomToken(5);
    }

    /**
     * Generate option identifier.
     */
    private function generateOptionId(
        string $label
    ): string {
        return 'OPT-'
            . strtoupper(
                substr(
                    hash(
                        'sha256',
                        $label
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
     * Generate vote identifier.
     */
    private function generateVoteId(
        string $decisionId,
        string $voterId
    ): string {
        return 'VOT-'
            . strtoupper(
                substr(
                    hash(
                        'sha256',
                        $decisionId
                        . '|'
                        . $voterId
                        . '|'
                        . microtime(true)
                    ),
                    0,
                    14
                )
            );
    }

    /**
     * Generate delegation identifier.
     */
    private function generateDelegationId(
        string $delegatorId,
        string $delegateId
    ): string {
        return 'DLG-'
            . strtoupper(
                substr(
                    hash(
                        'sha256',
                        $delegatorId
                        . '|'
                        . $delegateId
                        . '|'
                        . microtime(true)
                    ),
                    0,
                    14
                )
            );
    }

    /**
     * Generate evidence identifier.
     */
    private function generateEvidenceId(
        array $evidence
    ): string {
        $json = json_encode(
            $this->normalizeForHash(
                $evidence
            ),
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );

        return 'EVD-'
            . strtoupper(
                substr(
                    hash(
                        'sha256',
                        $json !== false
                            ? $json
                            : uniqid('', true)
                    ),
                    0,
                    16
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