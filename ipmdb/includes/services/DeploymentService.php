<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/DeploymentService.php
|--------------------------------------------------------------------------
| IPMdb Deployment Service
|--------------------------------------------------------------------------
|
| Creates attributable deployment plans, validates readiness, compares
| manifests, records promotion results, and prepares rollback instructions.
|
| This service performs no automatic filesystem or database writes.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/RelationshipService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class DeploymentService extends Service
{
    use GraphUtilities;

    private AuditService $audits;

    private RelationshipService $relationships;

    /**
     * @var array<int,string>
     */
    private array $statuses = [
        'draft',
        'planned',
        'preparing',
        'ready',
        'validating',
        'approved',
        'promoting',
        'verifying',
        'completed',
        'completed_with_warnings',
        'blocked',
        'failed',
        'rolling_back',
        'rolled_back',
        'cancelled',
        'archived',
    ];

    /**
     * @var array<string,array<int,string>>
     */
    private array $transitions = [
        'draft' => [
            'planned',
            'cancelled',
            'archived',
        ],

        'planned' => [
            'draft',
            'preparing',
            'cancelled',
            'archived',
        ],

        'preparing' => [
            'ready',
            'blocked',
            'failed',
            'cancelled',
        ],

        'ready' => [
            'validating',
            'approved',
            'blocked',
            'cancelled',
        ],

        'validating' => [
            'ready',
            'approved',
            'blocked',
            'failed',
            'cancelled',
        ],

        'approved' => [
            'promoting',
            'blocked',
            'cancelled',
        ],

        'promoting' => [
            'verifying',
            'failed',
            'rolling_back',
        ],

        'verifying' => [
            'completed',
            'completed_with_warnings',
            'failed',
            'rolling_back',
        ],

        'completed' => [
            'archived',
            'rolling_back',
        ],

        'completed_with_warnings' => [
            'archived',
            'rolling_back',
        ],

        'blocked' => [
            'planned',
            'preparing',
            'ready',
            'validating',
            'approved',
            'cancelled',
            'archived',
        ],

        'failed' => [
            'draft',
            'planned',
            'preparing',
            'rolling_back',
            'cancelled',
            'archived',
        ],

        'rolling_back' => [
            'rolled_back',
            'failed',
        ],

        'rolled_back' => [
            'draft',
            'planned',
            'archived',
        ],

        'cancelled' => [
            'draft',
            'archived',
        ],

        'archived' => [],
    ];

    /**
     * @var array<int,string>
     */
    private array $environments = [
        'development',
        'testing',
        'staging',
        'production',
        'backup',
        'archive',
        'custom',
    ];

    /**
     * @var array<int,string>
     */
    private array $strategies = [
        'copy',
        'replace',
        'merge',
        'promote',
        'blue_green',
        'rolling',
        'manual',
        'custom',
    ];

    /**
     * @var array<int,string>
     */
    private array $checkStatuses = [
        'pending',
        'running',
        'passed',
        'warning',
        'failed',
        'skipped',
        'blocked',
    ];

    /**
     * @var array<int,string>
     */
    private array $itemStatuses = [
        'pending',
        'prepared',
        'validated',
        'promoted',
        'verified',
        'warning',
        'failed',
        'skipped',
        'rolled_back',
    ];

    /**
     * @var array<int,string>
     */
    private array $immutableFields = [
        'deployment_id',
        'entity_id',
        'entity_type',
        'source_path',
        'target_path',
        'created_by',
        'created_at',
    ];

    /**
     * @var array<int,string>
     */
    private array $checksumExcludedFields = [
        'checksum',
        'updated_at',
        'runtime',
        'analytics',
        'search_score',
        'last_accessed_at',
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?AuditService $audits = null,
        ?RelationshipService $relationships = null
    ) {
        parent::__construct($config, $context);

        $this->audits = $audits
            ?? new AuditService();

        $this->relationships = $relationships
            ?? new RelationshipService();
    }

    /**
     * Create one canonical deployment record.
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
                'Deployment creation requires actor attribution.'
            );
        }

        $sourcePath = $this->normalizeDeploymentPath(
            (string)(
                $input['source_path']
                    ?? ''
            )
        );

        $targetPath = $this->normalizeDeploymentPath(
            (string)(
                $input['target_path']
                    ?? ''
            )
        );

        if ($sourcePath === '') {
            throw new InvalidArgumentException(
                'Deployment source path is required.'
            );
        }

        if ($targetPath === '') {
            throw new InvalidArgumentException(
                'Deployment target path is required.'
            );
        }

        if ($sourcePath === $targetPath) {
            throw new InvalidArgumentException(
                'Deployment source and target paths must differ.'
            );
        }

        $sourceEnvironment =
            $this->normalizeEnvironment(
                (string)(
                    $input['source_environment']
                    ?? 'development'
                )
            );

        $targetEnvironment =
            $this->normalizeEnvironment(
                (string)(
                    $input['target_environment']
                    ?? 'production'
                )
            );

        $strategy = $this->normalizeStrategy(
            (string)(
                $input['strategy']
                    ?? 'promote'
            )
        );

        $deploymentId = trim(
            (string)(
                $input['deployment_id']
                    ?? ''
            )
        );

        if ($deploymentId === '') {
            $deploymentId =
                $this->generateDeploymentId(
                    $sourceEnvironment,
                    $targetEnvironment
                );
        }

        $now = gmdate('c');

        $deployment = [
            'deployment_id' =>
                $deploymentId,

            'entity_id' =>
                $deploymentId,

            'entity_type' =>
                'deployment',

            'title' => trim(
                (string)(
                    $input['title']
                    ?? sprintf(
                        '%s to %s deployment',
                        ucwords(
                            str_replace(
                                '_',
                                ' ',
                                $sourceEnvironment
                            )
                        ),
                        ucwords(
                            str_replace(
                                '_',
                                ' ',
                                $targetEnvironment
                            )
                        )
                    )
                )
            ),

            'description' => trim(
                (string)(
                    $input['description']
                    ?? ''
                )
            ),

            'status' =>
                $this->normalizeDeploymentStatus(
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

            'release_name' => trim(
                (string)(
                    $input['release_name']
                    ?? ''
                )
            ),

            'release_version' => trim(
                (string)(
                    $input['release_version']
                    ?? ''
                )
            ),

            'source_environment' =>
                $sourceEnvironment,

            'target_environment' =>
                $targetEnvironment,

            'source_path' =>
                $sourcePath,

            'target_path' =>
                $targetPath,

            'strategy' =>
                $strategy,

            'owner_id' => trim(
                (string)(
                    $input['owner_id']
                    ?? $actorId
                )
            ),

            'decision_id' => trim(
                (string)(
                    $input['decision_id']
                    ?? ''
                )
            ),

            'audit_id' => trim(
                (string)(
                    $input['audit_id']
                    ?? ''
                )
            ),

            'workflow_id' => trim(
                (string)(
                    $input['workflow_id']
                    ?? ''
                )
            ),

            'items' =>
                $this->normalizeItems(
                    $input['items']
                    ?? []
                ),

            'checks' =>
                $this->normalizeChecks(
                    $input['checks']
                    ?? []
                ),

            'source_manifest' =>
                $this->normalizeManifest(
                    $input['source_manifest']
                    ?? []
                ),

            'target_manifest' =>
                $this->normalizeManifest(
                    $input['target_manifest']
                    ?? []
                ),

            'manifest_comparison' =>
                null,

            'requirements' =>
                $this->normalizeRequirements(
                    $input['requirements']
                    ?? []
                ),

            'approvals' =>
                $this->normalizeApprovals(
                    $input['approvals']
                    ?? []
                ),

            'minimum_approvals' =>
                max(
                    0,
                    (int)(
                        $input['minimum_approvals']
                        ?? 1
                    )
                ),

            'blockers' =>
                $this->normalizeBlockers(
                    $input['blockers']
                    ?? []
                ),

            'backup' =>
                $this->normalizeBackup(
                    $input['backup']
                    ?? []
                ),

            'rollback' =>
                $this->normalizeRollback(
                    $input['rollback']
                    ?? []
                ),

            'requires_decision' =>
                (bool)(
                    $input['requires_decision']
                    ?? true
                ),

            'requires_audit' =>
                (bool)(
                    $input['requires_audit']
                    ?? true
                ),

            'requires_backup' =>
                (bool)(
                    $input['requires_backup']
                    ?? true
                ),

            'dry_run' =>
                (bool)(
                    $input['dry_run']
                    ?? true
                ),

            'progress' =>
                0.0,

            'readiness' =>
                null,

            'started_by' => null,
            'started_at' => null,

            'prepared_by' => null,
            'prepared_at' => null,

            'validated_by' => null,
            'validated_at' => null,

            'approved_by' => null,
            'approved_at' => null,

            'promoted_by' => null,
            'promoted_at' => null,

            'verified_by' => null,
            'verified_at' => null,

            'completed_by' => null,
            'completed_at' => null,

            'blocked_by' => null,
            'blocked_at' => null,
            'block_reason' => null,

            'failed_by' => null,
            'failed_at' => null,
            'failure_reason' => null,

            'rollback_started_by' => null,
            'rollback_started_at' => null,

            'rolled_back_by' => null,
            'rolled_back_at' => null,
            'rollback_reason' => null,

            'cancelled_by' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,

            'archived_by' => null,
            'archived_at' => null,

            'scheduled_at' =>
                $this->normalizeDate(
                    $input['scheduled_at']
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

            'metadata' => is_array(
                $input['metadata']
                    ?? null
            )
                ? $input['metadata']
                : [],

            'checksum' => '',
        ];

        $deployment['progress'] =
            $this->calculateProgress(
                $deployment
            );

        $deployment['readiness'] =
            $this->calculateReadiness(
                $deployment
            );

        $deployment['checksum'] =
            $this->calculateChecksum(
                $deployment
            );

        $validation =
            $this->validate(
                $deployment
            );

        if (
            ($validation['valid'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Deployment validation failed: '
                . implode(
                    ' ',
                    $validation['errors']
                    ?? []
                )
            );
        }

        return $deployment;
    }

    /**
     * Update a deployment while preserving immutable identity fields.
     *
     * @param array<string,mixed> $deployment
     * @param array<string,mixed> $changes
     *
     * @return array<string,mixed>
     */
    public function update(
        array $deployment,
        array $changes,
        string $actorId,
        string $reason = ''
    ): array {
        $this->assertDeployment(
            $deployment
        );

        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Deployment update requires actor attribution.'
            );
        }

        $updated = $deployment;

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
                    $deployment['version']
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
                $updated
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
            $this->validate(
                $updated
            );

        if (
            ($validation['valid'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Updated deployment is invalid: '
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
     * Transition deployment lifecycle status.
     *
     * @return array<string,mixed>
     */
    public function transition(
        array $deployment,
        string $newStatus,
        string $actorId,
        string $reason = ''
    ): array {
        $this->assertDeployment(
            $deployment
        );

        $currentStatus =
            $this->normalizeDeploymentStatus(
                (string)(
                    $deployment['status']
                    ?? 'draft'
                )
            );

        $newStatus =
            $this->normalizeDeploymentStatus(
                $newStatus
            );

        if ($currentStatus === $newStatus) {
            return $deployment;
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
                    'Deployment cannot transition from "%s" to "%s".',
                    $currentStatus,
                    $newStatus
                )
            );
        }

        $changes = [
            'status' =>
                $newStatus,
        ];

        $now = gmdate('c');

        switch ($newStatus) {
            case 'preparing':
                $changes['started_by'] =
                    $actorId;

                $changes['started_at'] =
                    $deployment['started_at']
                    ?? $now;
                break;

            case 'ready':
                $changes['prepared_by'] =
                    $actorId;

                $changes['prepared_at'] =
                    $now;
                break;

            case 'validating':
                $changes['validated_by'] =
                    null;

                $changes['validated_at'] =
                    null;
                break;

            case 'approved':
                $changes['approved_by'] =
                    $actorId;

                $changes['approved_at'] =
                    $now;
                break;

            case 'promoting':
                $changes['promoted_by'] =
                    $actorId;

                $changes['promoted_at'] =
                    $now;
                break;

            case 'verifying':
                $changes['verified_by'] =
                    null;

                $changes['verified_at'] =
                    null;
                break;

            case 'completed':
            case 'completed_with_warnings':
                $changes['completed_by'] =
                    $actorId;

                $changes['completed_at'] =
                    $now;

                $changes['progress'] =
                    100.0;
                break;

            case 'blocked':
                $changes['blocked_by'] =
                    $actorId;

                $changes['blocked_at'] =
                    $now;

                $changes['block_reason'] =
                    trim($reason);
                break;

            case 'failed':
                $changes['failed_by'] =
                    $actorId;

                $changes['failed_at'] =
                    $now;

                $changes['failure_reason'] =
                    trim($reason);
                break;

            case 'rolling_back':
                $changes[
                    'rollback_started_by'
                ] = $actorId;

                $changes[
                    'rollback_started_at'
                ] = $now;

                $changes['rollback_reason'] =
                    trim($reason);
                break;

            case 'rolled_back':
                $changes['rolled_back_by'] =
                    $actorId;

                $changes['rolled_back_at'] =
                    $now;

                $changes['rollback_reason'] =
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
            $deployment,
            $changes,
            $actorId,
            $reason !== ''
                ? $reason
                : sprintf(
                    'Deployment moved from %s to %s.',
                    $currentStatus,
                    $newStatus
                )
        );
    }

    /**
     * Mark the deployment planned.
     */
    public function plan(
        array $deployment,
        string $actorId
    ): array {
        return $this->transition(
            $deployment,
            'planned',
            $actorId,
            'Deployment planned.'
        );
    }

    /**
     * Begin preparation.
     */
    public function beginPreparation(
        array $deployment,
        string $actorId
    ): array {
        return $this->transition(
            $deployment,
            'preparing',
            $actorId,
            'Deployment preparation started.'
        );
    }

    /**
     * Mark preparation complete.
     */
    public function markReady(
        array $deployment,
        string $actorId
    ): array {
        $readiness =
            $this->calculateReadiness(
                $deployment
            );

        if (
            ($readiness['structurally_ready']
                ?? false) !== true
        ) {
            throw new RuntimeException(
                'Deployment structure is incomplete.'
            );
        }

        return $this->transition(
            $deployment,
            'ready',
            $actorId,
            'Deployment preparation completed.'
        );
    }

    /**
     * Begin validation.
     */
    public function beginValidation(
        array $deployment,
        string $actorId
    ): array {
        return $this->transition(
            $deployment,
            'validating',
            $actorId,
            'Deployment validation started.'
        );
    }

    /**
     * Approve a deployment.
     */
    public function approve(
        array $deployment,
        string $actorId,
        string $reason = ''
    ): array {
        $readiness =
            $this->calculateReadiness(
                $deployment
            );

        if (
            ($readiness['ready_for_approval']
                ?? false) !== true
        ) {
            throw new RuntimeException(
                'Deployment approval requirements are incomplete.'
            );
        }

        return $this->transition(
            $deployment,
            'approved',
            $actorId,
            $reason !== ''
                ? $reason
                : 'Deployment approved.'
        );
    }

    /**
     * Record promotion start.
     */
    public function beginPromotion(
        array $deployment,
        string $actorId
    ): array {
        $readiness =
            $this->calculateReadiness(
                $deployment
            );

        if (
            ($readiness['ready_for_promotion']
                ?? false) !== true
        ) {
            throw new RuntimeException(
                'Deployment is not ready for promotion.'
            );
        }

        return $this->transition(
            $deployment,
            'promoting',
            $actorId,
            'Deployment promotion started.'
        );
    }

    /**
     * Begin post-promotion verification.
     */
    public function beginVerification(
        array $deployment,
        string $actorId
    ): array {
        return $this->transition(
            $deployment,
            'verifying',
            $actorId,
            'Deployment verification started.'
        );
    }

    /**
     * Complete deployment according to check results.
     */
    public function complete(
        array $deployment,
        string $actorId
    ): array {
        $checkSummary =
            $this->summarizeChecks(
                $deployment['checks']
                ?? []
            );

        if (
            $checkSummary['failed_required']
            > 0
        ) {
            throw new RuntimeException(
                'Deployment contains failed required checks.'
            );
        }

        $status =
            $checkSummary['warning'] > 0
                ? 'completed_with_warnings'
                : 'completed';

        $deployment['verified_by'] =
            $actorId;

        $deployment['verified_at'] =
            gmdate('c');

        return $this->transition(
            $deployment,
            $status,
            $actorId,
            'Deployment verification completed.'
        );
    }

    /**
     * Block deployment.
     */
    public function block(
        array $deployment,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Deployment block requires a reason.'
            );
        }

        return $this->transition(
            $deployment,
            'blocked',
            $actorId,
            $reason
        );
    }

    /**
     * Fail deployment.
     */
    public function fail(
        array $deployment,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Deployment failure requires a reason.'
            );
        }

        return $this->transition(
            $deployment,
            'failed',
            $actorId,
            $reason
        );
    }

    /**
     * Cancel deployment.
     */
    public function cancel(
        array $deployment,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Deployment cancellation requires a reason.'
            );
        }

        return $this->transition(
            $deployment,
            'cancelled',
            $actorId,
            $reason
        );
    }

    /**
     * Begin rollback.
     */
    public function beginRollback(
        array $deployment,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Rollback requires a reason.'
            );
        }

        return $this->transition(
            $deployment,
            'rolling_back',
            $actorId,
            $reason
        );
    }

    /**
     * Complete rollback.
     */
    public function completeRollback(
        array $deployment,
        string $actorId,
        string $reason = ''
    ): array {
        return $this->transition(
            $deployment,
            'rolled_back',
            $actorId,
            $reason !== ''
                ? $reason
                : 'Deployment rollback completed.'
        );
    }

    /**
     * Archive deployment.
     */
    public function archive(
        array $deployment,
        string $actorId
    ): array {
        return $this->transition(
            $deployment,
            'archived',
            $actorId,
            'Deployment archived.'
        );
    }

    /**
     * Add one deployment item.
     *
     * @return array<string,mixed>
     */
    public function addItem(
        array $deployment,
        array $input,
        string $actorId
    ): array {
        $items = $this->normalizeItems(
            $deployment['items']
                ?? []
        );

        $item = $this->createItem(
            $input,
            count($items)
        );

        foreach ($items as $existing) {
            if (
                ($existing['item_id'] ?? '')
                === $item['item_id']
            ) {
                throw new RuntimeException(
                    'Deployment item identifier already exists.'
                );
            }
        }

        $items[] = $item;

        return $this->update(
            $deployment,
            [
                'items' => $items,
            ],
            $actorId,
            'Deployment item added.'
        );
    }

    /**
     * Add one deployment check.
     *
     * @return array<string,mixed>
     */
    public function addCheck(
        array $deployment,
        array $input,
        string $actorId
    ): array {
        $checks = $this->normalizeChecks(
            $deployment['checks']
                ?? []
        );

        $check = $this->createCheck(
            $input,
            count($checks)
        );

        foreach ($checks as $existing) {
            if (
                ($existing['check_id'] ?? '')
                === $check['check_id']
            ) {
                throw new RuntimeException(
                    'Deployment check identifier already exists.'
                );
            }
        }

        $checks[] = $check;

        return $this->update(
            $deployment,
            [
                'checks' => $checks,
            ],
            $actorId,
            'Deployment check added.'
        );
    }

    /**
     * Record one check result.
     *
     * @return array<string,mixed>
     */
    public function recordCheckResult(
        array $deployment,
        string $checkId,
        string $status,
        string $actorId,
        mixed $actual = null,
        string $message = '',
        array $evidence = []
    ): array {
        $status =
            $this->normalizeCheckStatus(
                $status
            );

        $checks = $deployment['checks']
            ?? [];

        $found = false;

        foreach ($checks as $index => $check) {
            if (
                !is_array($check)
                || ($check['check_id'] ?? '')
                    !== $checkId
            ) {
                continue;
            }

            $checks[$index]['status'] =
                $status;

            $checks[$index]['actual'] =
                $actual;

            $checks[$index]['message'] =
                trim($message);

            $checks[$index]['completed_by'] =
                $actorId;

            $checks[$index]['completed_at'] =
                gmdate('c');

            $checks[$index]['evidence'] =
                $this->normalizeEvidence(
                    $evidence
                );

            $found = true;
            break;
        }

        if (!$found) {
            throw new RuntimeException(
                'Deployment check was not found.'
            );
        }

        return $this->update(
            $deployment,
            [
                'checks' => $checks,
            ],
            $actorId,
            'Deployment check result recorded.'
        );
    }

    /**
     * Record one item status.
     *
     * @return array<string,mixed>
     */
    public function recordItemStatus(
        array $deployment,
        string $itemId,
        string $status,
        string $actorId,
        string $reason = ''
    ): array {
        $status =
            $this->normalizeItemStatus(
                $status
            );

        $items = $deployment['items']
            ?? [];

        $found = false;

        foreach ($items as $index => $item) {
            if (
                !is_array($item)
                || ($item['item_id'] ?? '')
                    !== $itemId
            ) {
                continue;
            }

            $items[$index]['status'] =
                $status;

            $timestampField = match ($status) {
                'prepared' => 'prepared_at',
                'validated' => 'validated_at',
                'promoted' => 'promoted_at',
                'verified' => 'verified_at',
                'failed' => 'failed_at',
                'rolled_back' => 'rolled_back_at',
                default => null,
            };

            if ($timestampField !== null) {
                $items[$index][$timestampField] =
                    gmdate('c');
            }

            if ($status === 'failed') {
                $items[$index][
                    'failure_reason'
                ] = trim($reason);
            }

            $found = true;
            break;
        }

        if (!$found) {
            throw new RuntimeException(
                'Deployment item was not found.'
            );
        }

        return $this->update(
            $deployment,
            [
                'items' => $items,
            ],
            $actorId,
            'Deployment item status recorded.'
        );
    }

    /**
     * Record a verified backup.
     *
     * @return array<string,mixed>
     */
    public function recordBackup(
        array $deployment,
        array $backup,
        string $actorId
    ): array {
        $normalized =
            $this->normalizeBackup(
                array_merge(
                    $backup,
                    [
                        'verified' =>
                            $backup['verified']
                            ?? true,

                        'verified_by' =>
                            $backup[
                                'verified_by'
                            ] ?? $actorId,

                        'verified_at' =>
                            $backup[
                                'verified_at'
                            ] ?? gmdate('c'),
                    ]
                )
            );

        return $this->update(
            $deployment,
            [
                'backup' =>
                    $normalized,
            ],
            $actorId,
            'Deployment backup recorded.'
        );
    }

    /**
     * Attach an audit report.
     *
     * @return array<string,mixed>
     */
    public function attachAudit(
        array $deployment,
        array $auditReport,
        string $actorId
    ): array {
        $auditId = trim(
            (string)(
                $auditReport['audit_id']
                ?? ''
            )
        );

        if ($auditId === '') {
            throw new InvalidArgumentException(
                'Audit report requires audit_id.'
            );
        }

        $readiness =
            $auditReport[
                'deployment_readiness'
            ] ?? $this->audits
                ->deploymentReadiness(
                    $auditReport
                );

        $metadata = is_array(
            $deployment['metadata']
                ?? null
        )
            ? $deployment['metadata']
            : [];

        $metadata['audit'] = [
            'audit_id' =>
                $auditId,

            'status' =>
                $auditReport['status']
                ?? null,

            'ready' =>
                $readiness['ready']
                ?? false,

            'score' =>
                $readiness['score']
                ?? null,

            'attached_by' =>
                $actorId,

            'attached_at' =>
                gmdate('c'),
        ];

        return $this->update(
            $deployment,
            [
                'audit_id' =>
                    $auditId,

                'metadata' =>
                    $metadata,
            ],
            $actorId,
            'Deployment audit attached.'
        );
    }

    /**
     * Record one approval.
     *
     * @return array<string,mixed>
     */
    public function addApproval(
        array $deployment,
        string $actorId,
        string $reason = ''
    ): array {
        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Deployment approval requires actor attribution.'
            );
        }

        $approvals =
            $this->normalizeApprovals(
                $deployment['approvals']
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
                    $deployment[
                        'deployment_id'
                    ],
                    $actorId
                ),

            'actor_id' =>
                $actorId,

            'status' =>
                'approved',

            'reason' =>
                trim($reason),

            'approved_at' =>
                gmdate('c'),
        ];

        return $this->update(
            $deployment,
            [
                'approvals' =>
                    array_values($indexed),
            ],
            $actorId,
            'Deployment approval recorded.'
        );
    }

    /**
     * Add one blocker.
     *
     * @return array<string,mixed>
     */
    public function addBlocker(
        array $deployment,
        string $description,
        string $actorId,
        string $severity = 'warning'
    ): array {
        $description = trim(
            $description
        );

        if ($description === '') {
            throw new InvalidArgumentException(
                'Deployment blocker requires a description.'
            );
        }

        $blockers =
            $this->normalizeBlockers(
                $deployment['blockers']
                    ?? []
            );

        $blockers[] = [
            'blocker_id' =>
                $this->generateBlockerId(
                    $description
                ),

            'description' =>
                $description,

            'severity' =>
                $this->normalizeMachineKey(
                    $severity
                ),

            'resolved' =>
                false,

            'created_by' =>
                $actorId,

            'created_at' =>
                gmdate('c'),

            'resolved_by' =>
                null,

            'resolved_at' =>
                null,

            'resolution' =>
                null,
        ];

        return $this->update(
            $deployment,
            [
                'blockers' =>
                    $blockers,
            ],
            $actorId,
            'Deployment blocker added.'
        );
    }

    /**
     * Resolve one blocker.
     *
     * @return array<string,mixed>
     */
    public function resolveBlocker(
        array $deployment,
        string $blockerId,
        string $actorId,
        string $resolution = ''
    ): array {
        $blockers =
            $this->normalizeBlockers(
                $deployment['blockers']
                    ?? []
            );

        $found = false;

        foreach (
            $blockers
            as $index => $blocker
        ) {
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
                'Deployment blocker was not found.'
            );
        }

        return $this->update(
            $deployment,
            [
                'blockers' =>
                    $blockers,
            ],
            $actorId,
            'Deployment blocker resolved.'
        );
    }

    /**
     * Compare source and target manifests.
     *
     * @param array<int,array<string,mixed>> $sourceManifest
     * @param array<int,array<string,mixed>> $targetManifest
     *
     * @return array<string,mixed>
     */
    public function compareManifests(
        array $sourceManifest,
        array $targetManifest
    ): array {
        $sourceManifest =
            $this->normalizeManifest(
                $sourceManifest
            );

        $targetManifest =
            $this->normalizeManifest(
                $targetManifest
            );

        $sourceIndex = [];
        $targetIndex = [];

        foreach ($sourceManifest as $record) {
            $sourceIndex[
                $record['path']
            ] = $record;
        }

        foreach ($targetManifest as $record) {
            $targetIndex[
                $record['path']
            ] = $record;
        }

        $missing = [];
        $unexpected = [];
        $changed = [];
        $unchanged = [];

        foreach (
            $sourceIndex
            as $path => $source
        ) {
            if (!isset($targetIndex[$path])) {
                $missing[] = $source;
                continue;
            }

            $target = $targetIndex[$path];

            $differences = [];

            foreach (
                [
                    'type',
                    'size',
                    'checksum',
                    'permissions',
                ]
                as $field
            ) {
                $sourceValue =
                    $source[$field]
                    ?? null;

                $targetValue =
                    $target[$field]
                    ?? null;

                if (
                    $sourceValue !== null
                    && $targetValue !== null
                    && $sourceValue
                        !== $targetValue
                ) {
                    $differences[$field] = [
                        'source' =>
                            $sourceValue,

                        'target' =>
                            $targetValue,
                    ];
                }
            }

            if ($differences === []) {
                $unchanged[] = [
                    'path' => $path,
                ];
            } else {
                $changed[] = [
                    'path' =>
                        $path,

                    'differences' =>
                        $differences,

                    'source' =>
                        $source,

                    'target' =>
                        $target,
                ];
            }
        }

        foreach (
            $targetIndex
            as $path => $target
        ) {
            if (!isset($sourceIndex[$path])) {
                $unexpected[] = $target;
            }
        }

        return [
            'generated_at' =>
                gmdate('c'),

            'source_count' =>
                count($sourceManifest),

            'target_count' =>
                count($targetManifest),

            'missing_count' =>
                count($missing),

            'unexpected_count' =>
                count($unexpected),

            'changed_count' =>
                count($changed),

            'unchanged_count' =>
                count($unchanged),

            'matching' =>
                $missing === []
                && $unexpected === []
                && $changed === [],

            'missing' =>
                $missing,

            'unexpected' =>
                $unexpected,

            'changed' =>
                $changed,

            'unchanged' =>
                $unchanged,
        ];
    }

    /**
     * Attach manifest comparison.
     *
     * @return array<string,mixed>
     */
    public function compareDeploymentManifests(
        array $deployment,
        string $actorId
    ): array {
        $comparison =
            $this->compareManifests(
                $deployment[
                    'source_manifest'
                ] ?? [],
                $deployment[
                    'target_manifest'
                ] ?? []
            );

        return $this->update(
            $deployment,
            [
                'manifest_comparison' =>
                    $comparison,
            ],
            $actorId,
            'Deployment manifests compared.'
        );
    }

    /**
     * Validate deployment structure and readiness.
     *
     * @return array<string,mixed>
     */
    public function validate(
        array $deployment
    ): array {
        $errors = [];
        $warnings = [];

        foreach (
            [
                'deployment_id',
                'entity_id',
                'entity_type',
                'title',
                'status',
                'source_environment',
                'target_environment',
                'source_path',
                'target_path',
                'strategy',
                'owner_id',
                'created_by',
                'created_at',
                'updated_at',
            ]
            as $field
        ) {
            if (
                $this->valueIsEmpty(
                    $deployment[$field]
                    ?? null
                )
            ) {
                $errors[] = sprintf(
                    'Deployment field "%s" is required.',
                    $field
                );
            }
        }

        if (
            ($deployment['entity_type']
                ?? '') !== 'deployment'
        ) {
            $errors[] =
                'Deployment entity type must be "deployment".';
        }

        try {
            $this->normalizeDeploymentStatus(
                (string)(
                    $deployment['status']
                    ?? 'draft'
                )
            );

            $this->normalizeEnvironment(
                (string)(
                    $deployment[
                        'source_environment'
                    ] ?? ''
                )
            );

            $this->normalizeEnvironment(
                (string)(
                    $deployment[
                        'target_environment'
                    ] ?? ''
                )
            );

            $this->normalizeStrategy(
                (string)(
                    $deployment['strategy']
                    ?? ''
                )
            );
        } catch (Throwable $exception) {
            $errors[] =
                $exception->getMessage();
        }

        if (
            $this->normalizeDeploymentPath(
                (string)(
                    $deployment['source_path']
                    ?? ''
                )
            ) ===
            $this->normalizeDeploymentPath(
                (string)(
                    $deployment['target_path']
                    ?? ''
                )
            )
        ) {
            $errors[] =
                'Deployment source and target paths must differ.';
        }

        $itemIds = [];

        foreach (
            $deployment['items']
                ?? []
            as $index => $item
        ) {
            if (!is_array($item)) {
                $errors[] = sprintf(
                    'Deployment item %d is invalid.',
                    $index
                );

                continue;
            }

            $itemId = trim(
                (string)(
                    $item['item_id']
                    ?? ''
                )
            );

            if ($itemId === '') {
                $errors[] = sprintf(
                    'Deployment item %d lacks item_id.',
                    $index
                );
            } elseif (isset($itemIds[$itemId])) {
                $errors[] = sprintf(
                    'Duplicate deployment item "%s".',
                    $itemId
                );
            } else {
                $itemIds[$itemId] = true;
            }
        }

        $checkIds = [];

        foreach (
            $deployment['checks']
                ?? []
            as $index => $check
        ) {
            if (!is_array($check)) {
                $errors[] = sprintf(
                    'Deployment check %d is invalid.',
                    $index
                );

                continue;
            }

            $checkId = trim(
                (string)(
                    $check['check_id']
                    ?? ''
                )
            );

            if ($checkId === '') {
                $errors[] = sprintf(
                    'Deployment check %d lacks check_id.',
                    $index
                );
            } elseif (isset($checkIds[$checkId])) {
                $errors[] = sprintf(
                    'Duplicate deployment check "%s".',
                    $checkId
                );
            } else {
                $checkIds[$checkId] = true;
            }
        }

        if (
            ($deployment['items'] ?? [])
            === []
        ) {
            $warnings[] =
                'Deployment contains no explicit items.';
        }

        if (
            ($deployment['checks'] ?? [])
            === []
        ) {
            $warnings[] =
                'Deployment contains no explicit checks.';
        }

        $storedChecksum = trim(
            (string)(
                $deployment['checksum']
                    ?? ''
            )
        );

        if (
            $storedChecksum !== ''
            && !hash_equals(
                $storedChecksum,
                $this->calculateChecksum(
                    $deployment
                )
            )
        ) {
            $errors[] =
                'Deployment checksum does not match content.';
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
     * Inspect deployment.
     *
     * @return array<string,mixed>
     */
    public function inspect(
        array $deployment
    ): array {
        $this->assertDeployment(
            $deployment
        );

        return [
            'deployment_id' =>
                $deployment[
                    'deployment_id'
                ],

            'status' =>
                $deployment['status']
                ?? 'draft',

            'progress' =>
                $this->calculateProgress(
                    $deployment
                ),

            'readiness' =>
                $this->calculateReadiness(
                    $deployment
                ),

            'validation' =>
                $this->validate(
                    $deployment
                ),

            'item_summary' =>
                $this->summarizeItems(
                    $deployment['items']
                    ?? []
                ),

            'check_summary' =>
                $this->summarizeChecks(
                    $deployment['checks']
                    ?? []
                ),

            'active_blockers' =>
                array_values(
                    array_filter(
                        $this->normalizeBlockers(
                            $deployment[
                                'blockers'
                            ] ?? []
                        ),
                        static fn (
                            array $blocker
                        ): bool =>
                            ($blocker['resolved']
                                ?? false) !== true
                    )
                ),

            'available_transitions' =>
                $this->transitions[
                    $deployment['status']
                    ?? 'draft'
                ] ?? [],

            'checksum_valid' =>
                isset($deployment['checksum'])
                && hash_equals(
                    (string)$deployment[
                        'checksum'
                    ],
                    $this->calculateChecksum(
                        $deployment
                    )
                ),
        ];
    }

    /**
     * Return compact summary.
     *
     * @return array<string,mixed>
     */
    public function summarize(
        array $deployment
    ): array {
        return [
            'deployment_id' =>
                $deployment[
                    'deployment_id'
                ] ?? null,

            'title' =>
                $deployment['title']
                ?? '',

            'status' =>
                $deployment['status']
                ?? 'draft',

            'source_environment' =>
                $deployment[
                    'source_environment'
                ] ?? null,

            'target_environment' =>
                $deployment[
                    'target_environment'
                ] ?? null,

            'source_path' =>
                $deployment['source_path']
                ?? null,

            'target_path' =>
                $deployment['target_path']
                ?? null,

            'strategy' =>
                $deployment['strategy']
                ?? null,

            'progress' =>
                $this->calculateProgress(
                    $deployment
                ),

            'readiness' =>
                $this->calculateReadiness(
                    $deployment
                ),

            'item_count' =>
                count(
                    $deployment['items']
                    ?? []
                ),

            'check_count' =>
                count(
                    $deployment['checks']
                    ?? []
                ),

            'created_at' =>
                $deployment['created_at']
                ?? null,

            'updated_at' =>
                $deployment['updated_at']
                ?? null,

            'completed_at' =>
                $deployment['completed_at']
                ?? null,
        ];
    }

    /**
     * Convert deployment into graph entity form.
     *
     * @return array<string,mixed>
     */
    public function toGraphEntity(
        array $deployment
    ): array {
        $this->assertDeployment(
            $deployment
        );

        return array_merge(
            $deployment,
            [
                'entity_id' =>
                    $deployment[
                        'deployment_id'
                    ],

                'entity_type' =>
                    'deployment',

                'graph_label' =>
                    $deployment['title']
                    ?? $deployment[
                        'deployment_id'
                    ],

                'graph_status' =>
                    $deployment['status']
                    ?? 'draft',
            ]
        );
    }

    /**
     * Create relationship from deployment to release subject.
     *
     * @return array<string,mixed>
     */
    public function targetRelationship(
        array $deployment,
        string $targetId,
        string $targetType,
        string $actorId
    ): array {
        return $this->relationships->create(
            [
                'source_id' =>
                    $deployment[
                        'deployment_id'
                    ],

                'source_type' =>
                    'deployment',

                'target_id' =>
                    $targetId,

                'target_type' =>
                    $targetType,

                'relationship_type' =>
                    'deploys',

                'status' =>
                    in_array(
                        $deployment['status']
                        ?? '',
                        [
                            'completed',
                            'completed_with_warnings',
                        ],
                        true
                    )
                        ? 'verified'
                        : 'proposed',

                'confidence' =>
                    100,

                'weight' =>
                    1,

                'strength' =>
                    1,

                'created_by' =>
                    $actorId,
            ]
        );
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

                'transitions' =>
                    $this->transitions,

                'environments' =>
                    $this->environments,

                'strategies' =>
                    $this->strategies,

                'filesystem_operations' =>
                    false,

                'database_operations' =>
                    false,

                'automatic_promotion' =>
                    false,

                'automatic_rollback' =>
                    false,

                'manifest_comparison' =>
                    true,

                'readiness_validation' =>
                    true,

                'human_attribution_required' =>
                    true,

                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Create one deployment item.
     *
     * @return array<string,mixed>
     */
    private function createItem(
        array $input,
        int $position
    ): array {
        $source = $this->normalizeDeploymentPath(
            (string)(
                $input['source']
                ?? $input['source_path']
                ?? ''
            )
        );

        $target = $this->normalizeDeploymentPath(
            (string)(
                $input['target']
                ?? $input['target_path']
                ?? ''
            )
        );

        if ($target === '') {
            throw new InvalidArgumentException(
                'Deployment item target is required.'
            );
        }

        $type = $this->normalizeMachineKey(
            (string)(
                $input['type']
                ?? 'file'
            )
        );

        $itemId = trim(
            (string)(
                $input['item_id']
                ?? ''
            )
        );

        if ($itemId === '') {
            $itemId = 'DIT-'
                . strtoupper(
                    substr(
                        hash(
                            'sha256',
                            $type
                            . '|'
                            . $source
                            . '|'
                            . $target
                            . '|'
                            . $position
                        ),
                        0,
                        16
                    )
                );
        }

        return [
            'item_id' =>
                $itemId,

            'type' =>
                $type !== ''
                    ? $type
                    : 'file',

            'title' => trim(
                (string)(
                    $input['title']
                    ?? basename($target)
                )
            ),

            'position' =>
                max(
                    0,
                    (int)(
                        $input['position']
                        ?? $position
                    )
                ),

            'source' =>
                $source,

            'target' =>
                $target,

            'required' =>
                (bool)(
                    $input['required']
                    ?? true
                ),

            'expected_size' =>
                isset($input['expected_size'])
                    ? max(
                        0,
                        (int)$input[
                            'expected_size'
                        ]
                    )
                    : null,

            'expected_checksum' =>
                strtolower(
                    trim(
                        (string)(
                            $input[
                                'expected_checksum'
                            ] ?? ''
                        )
                    )
                ),

            'status' =>
                $this->normalizeItemStatus(
                    (string)(
                        $input['status']
                        ?? 'pending'
                    )
                ),

            'prepared_at' =>
                $input['prepared_at']
                ?? null,

            'validated_at' =>
                $input['validated_at']
                ?? null,

            'promoted_at' =>
                $input['promoted_at']
                ?? null,

            'verified_at' =>
                $input['verified_at']
                ?? null,

            'failed_at' =>
                $input['failed_at']
                ?? null,

            'failure_reason' =>
                $input['failure_reason']
                ?? null,

            'rolled_back_at' =>
                $input['rolled_back_at']
                ?? null,

            'metadata' => is_array(
                $input['metadata']
                ?? null
            )
                ? $input['metadata']
                : [],
        ];
    }

    /**
     * Create one deployment check.
     *
     * @return array<string,mixed>
     */
    private function createCheck(
        array $input,
        int $position
    ): array {
        $type = $this->normalizeMachineKey(
            (string)(
                $input['type']
                ?? 'manual'
            )
        );

        $title = trim(
            (string)(
                $input['title']
                ?? ucwords(
                    str_replace(
                        '_',
                        ' ',
                        $type
                    )
                )
            )
        );

        $checkId = trim(
            (string)(
                $input['check_id']
                ?? ''
            )
        );

        if ($checkId === '') {
            $checkId = 'DCK-'
                . strtoupper(
                    substr(
                        hash(
                            'sha256',
                            $type
                            . '|'
                            . $title
                            . '|'
                            . $position
                        ),
                        0,
                        16
                    )
                );
        }

        return [
            'check_id' =>
                $checkId,

            'type' =>
                $type !== ''
                    ? $type
                    : 'manual',

            'title' =>
                $title,

            'position' =>
                max(
                    0,
                    (int)(
                        $input['position']
                        ?? $position
                    )
                ),

            'required' =>
                (bool)(
                    $input['required']
                    ?? true
                ),

            'stage' =>
                $this->normalizeMachineKey(
                    (string)(
                        $input['stage']
                        ?? 'preflight'
                    )
                ),

            'target' => trim(
                (string)(
                    $input['target']
                    ?? ''
                )
            ),

            'expected' =>
                $input['expected']
                ?? true,

            'actual' =>
                $input['actual']
                ?? null,

            'status' =>
                $this->normalizeCheckStatus(
                    (string)(
                        $input['status']
                        ?? 'pending'
                    )
                ),

            'message' => trim(
                (string)(
                    $input['message']
                    ?? ''
                )
            ),

            'completed_by' =>
                $input['completed_by']
                ?? null,

            'completed_at' =>
                $input['completed_at']
                ?? null,

            'evidence' =>
                $this->normalizeEvidence(
                    $input['evidence']
                    ?? []
                ),
        ];
    }

    /**
     * Normalize deployment items.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeItems(
        mixed $items
    ): array {
        if (!is_array($items)) {
            return [];
        }

        if (
            $items !== []
            && !array_is_list($items)
        ) {
            $items = [$items];
        }

        $normalized = [];

        foreach ($items as $index => $item) {
            if (is_string($item)) {
                $item = [
                    'source' => $item,
                    'target' => $item,
                ];
            }

            if (!is_array($item)) {
                continue;
            }

            $record =
                $this->createItem(
                    $item,
                    $index
                );

            $normalized[
                $record['item_id']
            ] = $record;
        }

        return array_values($normalized);
    }

    /**
     * Normalize deployment checks.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeChecks(
        mixed $checks
    ): array {
        if (!is_array($checks)) {
            return [];
        }

        if (
            $checks !== []
            && !array_is_list($checks)
        ) {
            $checks = [$checks];
        }

        $normalized = [];

        foreach ($checks as $index => $check) {
            if (is_string($check)) {
                $check = [
                    'title' => $check,
                    'type' => 'manual',
                ];
            }

            if (!is_array($check)) {
                continue;
            }

            $record =
                $this->createCheck(
                    $check,
                    $index
                );

            $normalized[
                $record['check_id']
            ] = $record;
        }

        return array_values($normalized);
    }

    /**
     * Normalize manifest.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeManifest(
        mixed $manifest
    ): array {
        if (!is_array($manifest)) {
            return [];
        }

        if (
            $manifest !== []
            && !array_is_list($manifest)
        ) {
            $manifest = [$manifest];
        }

        $normalized = [];

        foreach ($manifest as $record) {
            if (is_string($record)) {
                $record = [
                    'path' => $record,
                ];
            }

            if (!is_array($record)) {
                continue;
            }

            $path =
                $this->normalizeDeploymentPath(
                    (string)(
                        $record['path']
                        ?? $record['file']
                        ?? ''
                    )
                );

            if ($path === '') {
                continue;
            }

            $normalized[$path] = [
                'path' =>
                    $path,

                'type' =>
                    $this->normalizeMachineKey(
                        (string)(
                            $record['type']
                            ?? 'file'
                        )
                    ),

                'size' =>
                    isset($record['size'])
                        ? max(
                            0,
                            (int)$record[
                                'size'
                            ]
                        )
                        : null,

                'checksum' =>
                    strtolower(
                        trim(
                            (string)(
                                $record['checksum']
                                ?? ''
                            )
                        )
                    ),

                'permissions' => trim(
                    (string)(
                        $record['permissions']
                        ?? ''
                    )
                ),

                'modified_at' =>
                    $this->normalizeDate(
                        $record['modified_at']
                        ?? null
                    ),

                'required' =>
                    (bool)(
                        $record['required']
                        ?? true
                    ),
            ];
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * Normalize requirements.
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
            $requirements = [
                $requirements,
            ];
        }

        $normalized = [];

        foreach ($requirements as $index => $requirement) {
            if (is_string($requirement)) {
                $requirement = [
                    'description' =>
                        $requirement,
                ];
            }

            if (!is_array($requirement)) {
                continue;
            }

            $description = trim(
                (string)(
                    $requirement[
                        'description'
                    ] ?? ''
                )
            );

            if ($description === '') {
                continue;
            }

            $id = trim(
                (string)(
                    $requirement[
                        'requirement_id'
                    ] ?? ''
                )
            );

            if ($id === '') {
                $id = 'DRQ-'
                    . strtoupper(
                        substr(
                            hash(
                                'sha256',
                                $description
                                . '|'
                                . $index
                            ),
                            0,
                            16
                        )
                    );
            }

            $normalized[$id] = [
                'requirement_id' =>
                    $id,

                'description' =>
                    $description,

                'required' =>
                    (bool)(
                        $requirement[
                            'required'
                        ] ?? true
                    ),

                'satisfied' =>
                    (bool)(
                        $requirement[
                            'satisfied'
                        ] ?? false
                    ),

                'evidence' =>
                    $requirement['evidence']
                    ?? null,
            ];
        }

        return array_values($normalized);
    }

    /**
     * Normalize approvals.
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
                        $approval,
                ];
            }

            if (!is_array($approval)) {
                continue;
            }

            $actorId = trim(
                (string)(
                    $approval['actor_id']
                    ?? ''
                )
            );

            if ($actorId === '') {
                continue;
            }

            $normalized[$actorId] = [
                'approval_id' =>
                    $approval['approval_id']
                    ?? $this->generateApprovalId(
                        'deployment',
                        $actorId
                    ),

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
                        $approval['approved_at']
                        ?? null
                    )
                    ?? gmdate('c'),
            ];
        }

        return array_values($normalized);
    }

    /**
     * Normalize blockers.
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
                        $blocker,
                ];
            }

            if (!is_array($blocker)) {
                continue;
            }

            $description = trim(
                (string)(
                    $blocker['description']
                    ?? ''
                )
            );

            if ($description === '') {
                continue;
            }

            $id = trim(
                (string)(
                    $blocker['blocker_id']
                    ?? ''
                )
            );

            if ($id === '') {
                $id =
                    $this->generateBlockerId(
                        $description
                    );
            }

            $normalized[$id] = [
                'blocker_id' =>
                    $id,

                'description' =>
                    $description,

                'severity' =>
                    $this->normalizeMachineKey(
                        (string)(
                            $blocker['severity']
                            ?? 'warning'
                        )
                    ),

                'resolved' =>
                    (bool)(
                        $blocker['resolved']
                        ?? false
                    ),

                'created_by' =>
                    $blocker['created_by']
                    ?? null,

                'created_at' =>
                    $this->normalizeDate(
                        $blocker['created_at']
                        ?? null
                    )
                    ?? gmdate('c'),

                'resolved_by' =>
                    $blocker['resolved_by']
                    ?? null,

                'resolved_at' =>
                    $this->normalizeDate(
                        $blocker['resolved_at']
                        ?? null
                    ),

                'resolution' =>
                    $blocker['resolution']
                    ?? null,
            ];
        }

        return array_values($normalized);
    }

    /**
     * Normalize backup record.
     *
     * @return array<string,mixed>
     */
    private function normalizeBackup(
        mixed $backup
    ): array {
        $backup = is_array($backup)
            ? $backup
            : [];

        return [
            'backup_id' => trim(
                (string)(
                    $backup['backup_id']
                    ?? ''
                )
            ),

            'path' =>
                $this->normalizeDeploymentPath(
                    (string)(
                        $backup['path']
                        ?? ''
                    )
                ),

            'checksum' =>
                strtolower(
                    trim(
                        (string)(
                            $backup['checksum']
                            ?? ''
                        )
                    )
                ),

            'created_by' =>
                $backup['created_by']
                ?? null,

            'created_at' =>
                $this->normalizeDate(
                    $backup['created_at']
                    ?? null
                ),

            'verified' =>
                (bool)(
                    $backup['verified']
                    ?? false
                ),

            'verified_by' =>
                $backup['verified_by']
                ?? null,

            'verified_at' =>
                $this->normalizeDate(
                    $backup['verified_at']
                    ?? null
                ),
        ];
    }

    /**
     * Normalize rollback instructions.
     *
     * @return array<string,mixed>
     */
    private function normalizeRollback(
        mixed $rollback
    ): array {
        $rollback = is_array($rollback)
            ? $rollback
            : [];

        return [
            'strategy' =>
                $this->normalizeMachineKey(
                    (string)(
                        $rollback['strategy']
                        ?? 'restore_backup'
                    )
                ),

            'instructions' => trim(
                (string)(
                    $rollback['instructions']
                    ?? ''
                )
            ),

            'backup_required' =>
                (bool)(
                    $rollback[
                        'backup_required'
                    ] ?? true
                ),

            'tested' =>
                (bool)(
                    $rollback['tested']
                    ?? false
                ),

            'tested_by' =>
                $rollback['tested_by']
                ?? null,

            'tested_at' =>
                $this->normalizeDate(
                    $rollback['tested_at']
                    ?? null
                ),
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

        foreach ($evidence as $index => $item) {
            if (is_string($item)) {
                $item = [
                    'description' =>
                        $item,
                ];
            }

            if (!is_array($item)) {
                continue;
            }

            $description = trim(
                (string)(
                    $item['description']
                    ?? ''
                )
            );

            $reference = trim(
                (string)(
                    $item['reference']
                    ?? $item['url']
                    ?? ''
                )
            );

            if (
                $description === ''
                && $reference === ''
            ) {
                continue;
            }

            $normalized[] = [
                'evidence_id' =>
                    $item['evidence_id']
                    ?? 'DEV-'
                    . strtoupper(
                        substr(
                            hash(
                                'sha256',
                                $description
                                . '|'
                                . $reference
                                . '|'
                                . $index
                            ),
                            0,
                            16
                        )
                    ),

                'description' =>
                    $description,

                'reference' =>
                    $reference,

                'created_at' =>
                    $this->normalizeDate(
                        $item['created_at']
                        ?? null
                    )
                    ?? gmdate('c'),
            ];
        }

        return $normalized;
    }

    /**
     * Calculate deployment progress.
     */
    private function calculateProgress(
        array $deployment
    ): float {
        $statusWeights = [
            'draft' => 0,
            'planned' => 5,
            'preparing' => 15,
            'ready' => 30,
            'validating' => 45,
            'approved' => 60,
            'promoting' => 75,
            'verifying' => 90,
            'completed' => 100,
            'completed_with_warnings' => 100,
            'blocked' => 40,
            'failed' => 40,
            'rolling_back' => 50,
            'rolled_back' => 100,
            'cancelled' => 100,
            'archived' => 100,
        ];

        $status = (string)(
            $deployment['status']
            ?? 'draft'
        );

        $base = (float)(
            $statusWeights[$status]
            ?? 0
        );

        $itemSummary =
            $this->summarizeItems(
                $deployment['items']
                ?? []
            );

        $checkSummary =
            $this->summarizeChecks(
                $deployment['checks']
                ?? []
            );

        $itemRatio =
            $itemSummary['total'] > 0
                ? $itemSummary['complete']
                / $itemSummary['total']
                : 0.0;

        $checkRatio =
            $checkSummary['total'] > 0
                ? $checkSummary['complete']
                / $checkSummary['total']
                : 0.0;

        $supplement =
            (($itemRatio + $checkRatio) / 2)
            * 10;

        return round(
            min(
                100,
                max(
                    0,
                    $base + $supplement
                )
            ),
            2
        );
    }

    /**
     * Calculate deployment readiness.
     *
     * @return array<string,mixed>
     */
    private function calculateReadiness(
        array $deployment
    ): array {
        $requirements =
            $this->normalizeRequirements(
                $deployment['requirements']
                ?? []
            );

        $requiredRequirements = array_values(
            array_filter(
                $requirements,
                static fn (
                    array $requirement
                ): bool =>
                    ($requirement['required']
                    ?? true) === true
            )
        );

        $unsatisfiedRequirements =
            array_values(
                array_filter(
                    $requiredRequirements,
                    static fn (
                        array $requirement
                    ): bool =>
                        ($requirement[
                            'satisfied'
                        ] ?? false) !== true
                )
            );

        $activeBlockers = array_values(
            array_filter(
                $this->normalizeBlockers(
                    $deployment['blockers']
                    ?? []
                ),
                static fn (
                    array $blocker
                ): bool =>
                    ($blocker['resolved']
                    ?? false) !== true
            )
        );

        $approvals =
            $this->normalizeApprovals(
                $deployment['approvals']
                ?? []
            );

        $approvedCount = count(
            array_filter(
                $approvals,
                static fn (
                    array $approval
                ): bool =>
                    ($approval['status']
                    ?? '') === 'approved'
            )
        );

        $checkSummary =
            $this->summarizeChecks(
                $deployment['checks']
                ?? []
            );

        $auditReady = (
            $deployment['requires_audit']
            ?? true
        ) !== true
            || (
                trim(
                    (string)(
                        $deployment['audit_id']
                        ?? ''
                    )
                ) !== ''
                && (
                    $deployment['metadata'][
                        'audit'
                    ]['ready'] ?? false
                ) === true
            );

        $decisionReady = (
            $deployment[
                'requires_decision'
            ] ?? true
        ) !== true
            || trim(
                (string)(
                    $deployment['decision_id']
                    ?? ''
                )
            ) !== '';

        $backupReady = (
            $deployment['requires_backup']
            ?? true
        ) !== true
            || (
                $deployment['backup'][
                    'verified'
                ] ?? false
            ) === true;

        $structurallyReady =
            trim(
                (string)(
                    $deployment['source_path']
                    ?? ''
                )
            ) !== ''
            && trim(
                (string)(
                    $deployment['target_path']
                    ?? ''
                )
            ) !== ''
            && (
                $deployment['source_path']
                ?? ''
            ) !== (
                $deployment['target_path']
                ?? ''
            );

        $readyForApproval =
            $structurallyReady
            && $unsatisfiedRequirements === []
            && $activeBlockers === []
            && $checkSummary[
                'failed_required'
            ] === 0
            && $auditReady
            && $decisionReady;

        $readyForPromotion =
            $readyForApproval
            && $backupReady
            && $approvedCount >= max(
                0,
                (int)(
                    $deployment[
                        'minimum_approvals'
                    ] ?? 1
                )
            );

        return [
            'structurally_ready' =>
                $structurallyReady,

            'ready_for_approval' =>
                $readyForApproval,

            'ready_for_promotion' =>
                $readyForPromotion,

            'audit_ready' =>
                $auditReady,

            'decision_ready' =>
                $decisionReady,

            'backup_ready' =>
                $backupReady,

            'approval_count' =>
                $approvedCount,

            'minimum_approvals' =>
                max(
                    0,
                    (int)(
                        $deployment[
                            'minimum_approvals'
                        ] ?? 1
                    )
                ),

            'active_blocker_count' =>
                count($activeBlockers),

            'unsatisfied_requirement_count' =>
                count(
                    $unsatisfiedRequirements
                ),

            'failed_required_check_count' =>
                $checkSummary[
                    'failed_required'
                ],
        ];
    }

    /**
     * Summarize items.
     *
     * @return array<string,int>
     */
    private function summarizeItems(
        array $items
    ): array {
        $summary = [
            'total' => 0,
            'pending' => 0,
            'prepared' => 0,
            'validated' => 0,
            'promoted' => 0,
            'verified' => 0,
            'warning' => 0,
            'failed' => 0,
            'skipped' => 0,
            'rolled_back' => 0,
            'complete' => 0,
        ];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $status =
                $this->normalizeItemStatus(
                    (string)(
                        $item['status']
                        ?? 'pending'
                    )
                );

            $summary['total']++;
            $summary[$status]++;

            if (
                in_array(
                    $status,
                    [
                        'verified',
                        'warning',
                        'skipped',
                        'rolled_back',
                    ],
                    true
                )
            ) {
                $summary['complete']++;
            }
        }

        return $summary;
    }

    /**
     * Summarize checks.
     *
     * @return array<string,int>
     */
    private function summarizeChecks(
        array $checks
    ): array {
        $summary = [
            'total' => 0,
            'pending' => 0,
            'running' => 0,
            'passed' => 0,
            'warning' => 0,
            'failed' => 0,
            'skipped' => 0,
            'blocked' => 0,
            'complete' => 0,
            'failed_required' => 0,
        ];

        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }

            $status =
                $this->normalizeCheckStatus(
                    (string)(
                        $check['status']
                        ?? 'pending'
                    )
                );

            $summary['total']++;
            $summary[$status]++;

            if (
                in_array(
                    $status,
                    [
                        'passed',
                        'warning',
                        'failed',
                        'skipped',
                    ],
                    true
                )
            ) {
                $summary['complete']++;
            }

            if (
                ($check['required'] ?? true)
                === true
                && in_array(
                    $status,
                    [
                        'failed',
                        'blocked',
                    ],
                    true
                )
            ) {
                $summary[
                    'failed_required'
                ]++;
            }
        }

        return $summary;
    }

    /**
     * Normalize update value.
     */
    private function normalizeFieldValue(
        string $field,
        mixed $value
    ): mixed {
        return match ($field) {
            'status' =>
                $this->normalizeDeploymentStatus(
                    (string)$value
                ),

            'source_environment',
            'target_environment' =>
                $this->normalizeEnvironment(
                    (string)$value
                ),

            'strategy' =>
                $this->normalizeStrategy(
                    (string)$value
                ),

            'items' =>
                $this->normalizeItems(
                    $value
                ),

            'checks' =>
                $this->normalizeChecks(
                    $value
                ),

            'source_manifest',
            'target_manifest' =>
                $this->normalizeManifest(
                    $value
                ),

            'requirements' =>
                $this->normalizeRequirements(
                    $value
                ),

            'approvals' =>
                $this->normalizeApprovals(
                    $value
                ),

            'blockers' =>
                $this->normalizeBlockers(
                    $value
                ),

            'backup' =>
                $this->normalizeBackup(
                    $value
                ),

            'rollback' =>
                $this->normalizeRollback(
                    $value
                ),

            'scheduled_at' =>
                $this->normalizeDate(
                    $value
                ),

            'tags' =>
                $this->normalizeStringList(
                    $value
                ),

            default =>
                $value,
        };
    }

    private function normalizeDeploymentStatus(
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
                    'Unsupported deployment status "%s".',
                    $status
                )
            );
        }

        return $status;
    }

    private function normalizeEnvironment(
        string $environment
    ): string {
        $environment =
            $this->normalizeMachineKey(
                $environment
            );

        if (
            !in_array(
                $environment,
                $this->environments,
                true
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported deployment environment "%s".',
                    $environment
                )
            );
        }

        return $environment;
    }

    private function normalizeStrategy(
        string $strategy
    ): string {
        $strategy =
            $this->normalizeMachineKey(
                $strategy
            );

        if (
            !in_array(
                $strategy,
                $this->strategies,
                true
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported deployment strategy "%s".',
                    $strategy
                )
            );
        }

        return $strategy;
    }

    private function normalizeCheckStatus(
        string $status
    ): string {
        $status =
            $this->normalizeMachineKey(
                $status
            );

        if (
            !in_array(
                $status,
                $this->checkStatuses,
                true
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported deployment check status "%s".',
                    $status
                )
            );
        }

        return $status;
    }

    private function normalizeItemStatus(
        string $status
    ): string {
        $status =
            $this->normalizeMachineKey(
                $status
            );

        if (
            !in_array(
                $status,
                $this->itemStatuses,
                true
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported deployment item status "%s".',
                    $status
                )
            );
        }

        return $status;
    }

    private function normalizeDeploymentPath(
        string $path
    ): string {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        $path = str_replace(
            '\\',
            '/',
            $path
        );

        $path = preg_replace(
            '#/+#',
            '/',
            $path
        ) ?? $path;

        if (
            strlen($path) > 1
            && str_ends_with(
                $path,
                '/'
            )
        ) {
            $path = rtrim(
                $path,
                '/'
            );
        }

        return $path;
    }

    /**
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
            ? gmdate(
                'c',
                $timestamp
            )
            : null;
    }

    private function incrementVersion(
        string $version
    ): string {
        if (
            preg_match(
                '/^(\d+)\.(\d+)$/',
                trim($version),
                $matches
            ) === 1
        ) {
            return sprintf(
                '%d.%d',
                (int)$matches[1],
                (int)$matches[2] + 1
            );
        }

        return '1.1';
    }

    private function assertDeployment(
        array $deployment
    ): void {
        if (
            trim(
                (string)(
                    $deployment[
                        'deployment_id'
                    ] ?? ''
                )
            ) === ''
        ) {
            throw new InvalidArgumentException(
                'Deployment record requires deployment_id.'
            );
        }
    }

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

    private function calculateChecksum(
        array $deployment
    ): string {
        $copy = $deployment;

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
                'Unable to calculate deployment checksum.'
            );
        }

        return hash(
            'sha256',
            $json
        );
    }

    private function generateDeploymentId(
        string $source,
        string $target
    ): string {
        return 'DEP-'
            . strtoupper(
                substr($source, 0, 3)
            )
            . '-'
            . strtoupper(
                substr($target, 0, 3)
            )
            . '-'
            . gmdate('Ymd-His')
            . '-'
            . $this->randomToken(4);
    }

    private function generateApprovalId(
        string $deploymentId,
        string $actorId
    ): string {
        return 'DAP-'
            . strtoupper(
                substr(
                    hash(
                        'sha256',
                        $deploymentId
                        . '|'
                        . $actorId
                        . '|'
                        . microtime(true)
                    ),
                    0,
                    16
                )
            );
    }

    private function generateBlockerId(
        string $description
    ): string {
        return 'DBL-'
            . strtoupper(
                substr(
                    hash(
                        'sha256',
                        $description
                        . '|'
                        . microtime(true)
                    ),
                    0,
                    16
                )
            );
    }

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