<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/AuditService.php
|--------------------------------------------------------------------------
| IPMdb Audit Service
|--------------------------------------------------------------------------
|
| Produces attributable, non-destructive audit reports across the IPMdb
| service layer.
|
| Responsibilities:
| - Audit entities, ideas, assets, decisions, workflows, relationships,
|   ledger entries, events, provenance records, versions, and graph data.
| - Consolidate validation, integrity, attribution, chronology, and status.
| - Detect missing identifiers, missing attribution, checksum failures,
|   chain failures, invalid relationships, duplicate records, and blockers.
| - Calculate audit severity, confidence, readiness, and disposition.
| - Produce deployment-readiness and remediation reports.
| - Preserve evidence and findings without changing audited records.
|
| Audits inspect.
| Findings explain.
| Decisions authorize remediation.
| Services perform remediation.
|
| This service performs no database writes.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/ValidationService.php';
require_once __DIR__ . '/ProvenanceService.php';
require_once __DIR__ . '/VersionService.php';
require_once __DIR__ . '/EventService.php';
require_once __DIR__ . '/RelationshipService.php';
require_once __DIR__ . '/GraphTraversalService.php';
require_once __DIR__ . '/GraphAnalyticsService.php';
require_once __DIR__ . '/GraphRepairService.php';
require_once __DIR__ . '/ConsistencyService.php';
require_once __DIR__ . '/RuleEngineService.php';
require_once __DIR__ . '/AssetService.php';
require_once __DIR__ . '/IdeaService.php';
require_once __DIR__ . '/DecisionService.php';
require_once __DIR__ . '/WorkflowService.php';
require_once __DIR__ . '/LedgerService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class AuditService extends Service
{
    use GraphUtilities;

    private ValidationService $validation;

    private ProvenanceService $provenance;

    private VersionService $versions;

    private EventService $events;

    private RelationshipService $relationships;

    private GraphTraversalService $traversal;

    private GraphAnalyticsService $analytics;

    private GraphRepairService $repairs;

    private ConsistencyService $consistency;

    private RuleEngineService $rules;

    private AssetService $assets;

    private IdeaService $ideas;

    private DecisionService $decisions;

    private WorkflowService $workflows;

    private LedgerService $ledger;

    /**
     * Supported audit statuses.
     *
     * @var array<int,string>
     */
    private array $statuses = [
        'draft',
        'running',
        'completed',
        'completed_with_findings',
        'failed',
        'archived',
    ];

    /**
     * Supported audit scopes.
     *
     * @var array<int,string>
     */
    private array $scopes = [
        'record',
        'collection',
        'entity',
        'idea',
        'asset',
        'decision',
        'workflow',
        'relationship',
        'graph',
        'ledger',
        'event',
        'provenance',
        'version',
        'service',
        'deployment',
        'system',
    ];

    /**
     * Finding severities from least to most consequential.
     *
     * @var array<int,string>
     */
    private array $severities = [
        'info',
        'notice',
        'warning',
        'error',
        'critical',
    ];

    /**
     * Finding categories.
     *
     * @var array<int,string>
     */
    private array $categories = [
        'structure',
        'validation',
        'identity',
        'attribution',
        'provenance',
        'checksum',
        'hash_chain',
        'chronology',
        'relationship',
        'graph',
        'workflow',
        'decision',
        'ledger',
        'rule',
        'consistency',
        'duplicate',
        'configuration',
        'dependency',
        'deployment',
        'security',
        'quality',
        'other',
    ];

    /**
     * Fields excluded from report checksum calculations.
     *
     * @var array<int,string>
     */
    private array $checksumExcludedFields = [
        'checksum',
        'updated_at',
        'runtime',
        'search_score',
        'analytics',
    ];

    /**
     * Severity weights used in audit scoring.
     *
     * @var array<string,int>
     */
    private array $severityWeights = [
        'info' => 0,
        'notice' => 1,
        'warning' => 4,
        'error' => 12,
        'critical' => 30,
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?ValidationService $validation = null,
        ?ProvenanceService $provenance = null,
        ?VersionService $versions = null,
        ?EventService $events = null,
        ?RelationshipService $relationships = null,
        ?GraphTraversalService $traversal = null,
        ?GraphAnalyticsService $analytics = null,
        ?GraphRepairService $repairs = null,
        ?ConsistencyService $consistency = null,
        ?RuleEngineService $rules = null,
        ?AssetService $assets = null,
        ?IdeaService $ideas = null,
        ?DecisionService $decisions = null,
        ?WorkflowService $workflows = null,
        ?LedgerService $ledger = null
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

        $this->traversal = $traversal
            ?? new GraphTraversalService();

        $this->analytics = $analytics
            ?? new GraphAnalyticsService();

        $this->repairs = $repairs
            ?? new GraphRepairService();

        $this->consistency = $consistency
            ?? new ConsistencyService();

        $this->rules = $rules
            ?? new RuleEngineService();

        $this->assets = $assets
            ?? new AssetService();

        $this->ideas = $ideas
            ?? new IdeaService();

        $this->decisions = $decisions
            ?? new DecisionService();

        $this->workflows = $workflows
            ?? new WorkflowService();

        $this->ledger = $ledger
            ?? new LedgerService();

        if (
            isset($config['severity_weights'])
            && is_array($config['severity_weights'])
        ) {
            foreach (
                $config['severity_weights']
                as $severity => $weight
            ) {
                $severity = $this->normalizeSeverity(
                    (string)$severity
                );

                $this->severityWeights[$severity] =
                    max(0, (int)$weight);
            }
        }
    }

    /**
     * Create an audit report shell.
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
                    ?? $input['auditor_id']
                    ?? ''
                )
        );

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Audit creation requires actor attribution.'
            );
        }

        $scope = $this->normalizeScope(
            (string)(
                $input['scope']
                ?? 'system'
            )
        );

        $subjectId = trim(
            (string)(
                $input['subject_id']
                ?? ''
            )
        );

        $subjectType = $this->normalizeMachineKey(
            (string)(
                $input['subject_type']
                ?? $scope
            )
        );

        $auditId = trim(
            (string)(
                $input['audit_id']
                ?? ''
            )
        );

        if ($auditId === '') {
            $auditId = $this->generateAuditId(
                $scope,
                $subjectId
            );
        }

        $now = gmdate('c');

        $report = [
            'audit_id' => $auditId,

            'entity_id' => $auditId,

            'entity_type' => 'audit',

            'scope' => $scope,

            'subject_id' => $subjectId,

            'subject_type' =>
                $subjectType !== ''
                    ? $subjectType
                    : $scope,

            'title' => trim(
                (string)(
                    $input['title']
                    ?? $this->defaultTitle(
                        $scope,
                        $subjectId
                    )
                )
            ),

            'description' => trim(
                (string)(
                    $input['description']
                    ?? ''
                )
            ),

            'status' => $this->normalizeStatus(
                (string)(
                    $input['status']
                    ?? 'draft'
                )
            ),

            'auditor_id' => $actorId,

            'started_at' => null,

            'completed_at' => null,

            'failed_at' => null,

            'failure_reason' => null,

            'findings' => [],

            'finding_summary' => $this->emptyFindingSummary(),

            'metrics' => [],

            'evidence' => $this->normalizeEvidence(
                $input['evidence']
                ?? []
            ),

            'recommendations' => [],

            'deployment_readiness' => null,

            'created_by' => $actorId,

            'created_at' => $now,

            'updated_by' => $actorId,

            'updated_at' => $now,

            'metadata' => is_array(
                $input['metadata']
                    ?? null
            )
                ? $input['metadata']
                : [],

            'checksum' => '',
        ];

        $report['checksum'] =
            $this->calculateChecksum(
                $report
            );

        return $report;
    }

    /**
     * Audit a mixed IPMdb dataset.
     *
     * Expected keys may include:
     * entities, ideas, assets, decisions, workflows, relationships,
     * ledger_entries, events, provenance_records, versions, services.
     *
     * @param array<string,mixed> $dataset
     *
     * @return array<string,mixed>
     */
    public function auditSystem(
        array $dataset,
        string $actorId,
        array $options = []
    ): array {
        $report = $this->create(
            [
                'scope' => 'system',
                'subject_id' =>
                    trim(
                        (string)(
                            $options['subject_id']
                            ?? 'ipmdb'
                        )
                    ),
                'title' =>
                    $options['title']
                    ?? 'IPMdb System Audit',
                'description' =>
                    $options['description']
                    ?? 'Consolidated integrity and readiness audit.',
            ],
            $actorId
        );

        $report['status'] = 'running';
        $report['started_at'] = gmdate('c');

        try {
            $entities = $this->normalizeRecordCollection(
                $dataset['entities']
                    ?? []
            );

            $ideas = $this->normalizeRecordCollection(
                $dataset['ideas']
                    ?? []
            );

            $assets = $this->normalizeRecordCollection(
                $dataset['assets']
                    ?? []
            );

            $decisions = $this->normalizeRecordCollection(
                $dataset['decisions']
                    ?? []
            );

            $workflows = $this->normalizeRecordCollection(
                $dataset['workflows']
                    ?? []
            );

            $relationships =
                $this->normalizeRecordCollection(
                    $dataset['relationships']
                        ?? []
                );

            $ledgerEntries =
                $this->normalizeRecordCollection(
                    $dataset['ledger_entries']
                        ?? $dataset['ledger']
                        ?? []
                );

            $events = $this->normalizeRecordCollection(
                $dataset['events']
                    ?? []
            );

            $provenanceRecords =
                $this->normalizeRecordCollection(
                    $dataset['provenance_records']
                        ?? $dataset['provenance']
                        ?? []
                );

            $versions =
                $this->normalizeRecordCollection(
                    $dataset['versions']
                        ?? []
                );

            $serviceFiles =
                $this->normalizeRecordCollection(
                    $dataset['services']
                        ?? []
                );

            $sections = [];

            $sections['entities'] =
                $this->auditGenericCollection(
                    $entities,
                    'entity'
                );

            $sections['ideas'] =
                $this->auditIdeas($ideas);

            $sections['assets'] =
                $this->auditAssets($assets);

            $sections['decisions'] =
                $this->auditDecisions(
                    $decisions
                );

            $sections['workflows'] =
                $this->auditWorkflows(
                    $workflows
                );

            $sections['relationships'] =
                $this->auditRelationships(
                    $relationships,
                    array_merge(
                        $entities,
                        $ideas,
                        $assets,
                        $decisions,
                        $workflows
                    )
                );

            $sections['ledger'] =
                $this->auditLedger(
                    $ledgerEntries
                );

            $sections['events'] =
                $this->auditEvents(
                    $events
                );

            $sections['provenance'] =
                $this->auditProvenance(
                    $provenanceRecords
                );

            $sections['versions'] =
                $this->auditVersions(
                    $versions
                );

            $sections['services'] =
                $this->auditServices(
                    $serviceFiles
                );

            $allFindings = [];

            foreach ($sections as $section) {
                foreach (
                    $section['findings']
                        ?? []
                    as $finding
                ) {
                    $allFindings[] = $finding;
                }
            }

            $duplicateFindings =
                $this->detectDuplicateIdentifiers(
                    [
                        'entities' => $entities,
                        'ideas' => $ideas,
                        'assets' => $assets,
                        'decisions' => $decisions,
                        'workflows' => $workflows,
                        'relationships' => $relationships,
                        'ledger_entries' => $ledgerEntries,
                        'events' => $events,
                        'provenance' => $provenanceRecords,
                        'versions' => $versions,
                    ]
                );

            $allFindings = array_merge(
                $allFindings,
                $duplicateFindings
            );

            $report['sections'] = $sections;

            $report['findings'] =
                $this->normalizeFindings(
                    $allFindings
                );

            $report['finding_summary'] =
                $this->summarizeFindings(
                    $report['findings']
                );

            $report['metrics'] = [
                'entity_count' =>
                    count($entities),

                'idea_count' =>
                    count($ideas),

                'asset_count' =>
                    count($assets),

                'decision_count' =>
                    count($decisions),

                'workflow_count' =>
                    count($workflows),

                'relationship_count' =>
                    count($relationships),

                'ledger_entry_count' =>
                    count($ledgerEntries),

                'event_count' =>
                    count($events),

                'provenance_record_count' =>
                    count($provenanceRecords),

                'version_count' =>
                    count($versions),

                'service_count' =>
                    count($serviceFiles),
            ];

            $report['deployment_readiness'] =
                $this->calculateDeploymentReadiness(
                    $report,
                    $options
                );

            $report['recommendations'] =
                $this->buildRecommendations(
                    $report['findings']
                );

            $report['status'] =
                ($report[
                    'finding_summary'
                ]['error_count'] > 0
                || $report[
                    'finding_summary'
                ]['critical_count'] > 0)
                    ? 'completed_with_findings'
                    : 'completed';

            $report['completed_at'] =
                gmdate('c');

            $report['updated_at'] =
                gmdate('c');

            $report['checksum'] =
                $this->calculateChecksum(
                    $report
                );

            return $report;
        } catch (Throwable $exception) {
            $report['status'] = 'failed';
            $report['failed_at'] = gmdate('c');
            $report['failure_reason'] =
                get_class($exception)
                . ': '
                . $exception->getMessage();

            $report['findings'][] =
                $this->createFinding(
                    'critical',
                    'other',
                    'audit_execution_failed',
                    $report['failure_reason'],
                    [
                        'exception_class' =>
                            get_class($exception),
                    ]
                );

            $report['finding_summary'] =
                $this->summarizeFindings(
                    $report['findings']
                );

            $report['checksum'] =
                $this->calculateChecksum(
                    $report
                );

            return $report;
        }
    }

    /**
     * Audit idea records.
     *
     * @param array<int,array<string,mixed>> $ideas
     *
     * @return array<string,mixed>
     */
    public function auditIdeas(
        array $ideas
    ): array {
        $findings = [];
        $validCount = 0;

        foreach ($ideas as $index => $idea) {
            if (!is_array($idea)) {
                $findings[] =
                    $this->createFinding(
                        'error',
                        'structure',
                        'invalid_idea_record',
                        'Idea collection contains a non-array record.',
                        [
                            'index' => $index,
                        ]
                    );

                continue;
            }

            try {
                $validation =
                    $this->ideas->validate(
                        $idea
                    );

                if (
                    ($validation['valid']
                        ?? false) === true
                ) {
                    $validCount++;
                } else {
                    foreach (
                        $validation['errors']
                            ?? []
                        as $error
                    ) {
                        $findings[] =
                            $this->createFinding(
                                'error',
                                'validation',
                                'idea_validation_failure',
                                (string)$error,
                                [
                                    'idea_id' =>
                                        $idea['idea_id']
                                            ?? null,
                                    'index' => $index,
                                ]
                            );
                    }
                }

                foreach (
                    $validation['warnings']
                        ?? []
                    as $warning
                ) {
                    $findings[] =
                        $this->createFinding(
                            'warning',
                            'quality',
                            'idea_validation_warning',
                            (string)$warning,
                            [
                                'idea_id' =>
                                    $idea['idea_id']
                                        ?? null,
                                'index' => $index,
                            ]
                        );
                }
            } catch (Throwable $exception) {
                $findings[] =
                    $this->exceptionFinding(
                        $exception,
                        'idea_audit_exception',
                        [
                            'idea_id' =>
                                $idea['idea_id']
                                    ?? null,
                            'index' => $index,
                        ]
                    );
            }

            $findings = array_merge(
                $findings,
                $this->auditIdentityAndAttribution(
                    $idea,
                    'idea',
                    $index
                )
            );
        }

        return $this->sectionResult(
            'ideas',
            count($ideas),
            $validCount,
            $findings
        );
    }

    /**
     * Audit asset records.
     *
     * @param array<int,array<string,mixed>> $assets
     *
     * @return array<string,mixed>
     */
    public function auditAssets(
        array $assets
    ): array {
        $findings = [];
        $validCount = 0;

        foreach ($assets as $index => $asset) {
            if (!is_array($asset)) {
                $findings[] =
                    $this->createFinding(
                        'error',
                        'structure',
                        'invalid_asset_record',
                        'Asset collection contains a non-array record.',
                        [
                            'index' => $index,
                        ]
                    );

                continue;
            }

            try {
                $validation =
                    $this->assets->validate(
                        $asset
                    );

                if (
                    ($validation['valid']
                        ?? false) === true
                ) {
                    $validCount++;
                } else {
                    foreach (
                        $validation['errors']
                            ?? []
                        as $error
                    ) {
                        $findings[] =
                            $this->createFinding(
                                'error',
                                'validation',
                                'asset_validation_failure',
                                (string)$error,
                                [
                                    'asset_id' =>
                                        $asset['asset_id']
                                            ?? null,
                                    'index' => $index,
                                ]
                            );
                    }
                }

                foreach (
                    $validation['warnings']
                        ?? []
                    as $warning
                ) {
                    $findings[] =
                        $this->createFinding(
                            'warning',
                            'quality',
                            'asset_validation_warning',
                            (string)$warning,
                            [
                                'asset_id' =>
                                    $asset['asset_id']
                                        ?? null,
                                'index' => $index,
                            ]
                        );
                }
            } catch (Throwable $exception) {
                $findings[] =
                    $this->exceptionFinding(
                        $exception,
                        'asset_audit_exception',
                        [
                            'asset_id' =>
                                $asset['asset_id']
                                    ?? null,
                            'index' => $index,
                        ]
                    );
            }

            $findings = array_merge(
                $findings,
                $this->auditIdentityAndAttribution(
                    $asset,
                    'asset',
                    $index
                )
            );
        }

        return $this->sectionResult(
            'assets',
            count($assets),
            $validCount,
            $findings
        );
    }

    /**
     * Audit decision records.
     *
     * @param array<int,array<string,mixed>> $decisions
     *
     * @return array<string,mixed>
     */
    public function auditDecisions(
        array $decisions
    ): array {
        $findings = [];
        $validCount = 0;

        foreach (
            $decisions
            as $index => $decision
        ) {
            if (!is_array($decision)) {
                $findings[] =
                    $this->createFinding(
                        'error',
                        'structure',
                        'invalid_decision_record',
                        'Decision collection contains a non-array record.',
                        [
                            'index' => $index,
                        ]
                    );

                continue;
            }

            try {
                $validation =
                    $this->decisions->validate(
                        $decision
                    );

                if (
                    ($validation['valid']
                        ?? false) === true
                ) {
                    $validCount++;
                } else {
                    foreach (
                        $validation['errors']
                            ?? []
                        as $error
                    ) {
                        $findings[] =
                            $this->createFinding(
                                'error',
                                'decision',
                                'decision_validation_failure',
                                (string)$error,
                                [
                                    'decision_id' =>
                                        $decision[
                                            'decision_id'
                                        ] ?? null,
                                    'index' => $index,
                                ]
                            );
                    }
                }

                if (
                    in_array(
                        $decision['status']
                            ?? '',
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
                    $findings[] =
                        $this->createFinding(
                            'error',
                            'decision',
                            'decision_missing_rationale',
                            'Approved or executed decision lacks rationale.',
                            [
                                'decision_id' =>
                                    $decision[
                                        'decision_id'
                                    ] ?? null,
                            ]
                        );
                }
            } catch (Throwable $exception) {
                $findings[] =
                    $this->exceptionFinding(
                        $exception,
                        'decision_audit_exception',
                        [
                            'decision_id' =>
                                $decision[
                                    'decision_id'
                                ] ?? null,
                            'index' => $index,
                        ]
                    );
            }

            $findings = array_merge(
                $findings,
                $this->auditIdentityAndAttribution(
                    $decision,
                    'decision',
                    $index
                )
            );
        }

        return $this->sectionResult(
            'decisions',
            count($decisions),
            $validCount,
            $findings
        );
    }

    /**
     * Audit workflow records.
     *
     * @param array<int,array<string,mixed>> $workflows
     *
     * @return array<string,mixed>
     */
    public function auditWorkflows(
        array $workflows
    ): array {
        $findings = [];
        $validCount = 0;

        foreach (
            $workflows
            as $index => $workflow
        ) {
            if (!is_array($workflow)) {
                $findings[] =
                    $this->createFinding(
                        'error',
                        'structure',
                        'invalid_workflow_record',
                        'Workflow collection contains a non-array record.',
                        [
                            'index' => $index,
                        ]
                    );

                continue;
            }

            try {
                $validation =
                    $this->workflows->validate(
                        $workflow
                    );

                if (
                    ($validation['valid']
                        ?? false) === true
                ) {
                    $validCount++;
                } else {
                    foreach (
                        $validation['errors']
                            ?? []
                        as $error
                    ) {
                        $findings[] =
                            $this->createFinding(
                                'error',
                                'workflow',
                                'workflow_validation_failure',
                                (string)$error,
                                [
                                    'workflow_id' =>
                                        $workflow[
                                            'workflow_id'
                                        ] ?? null,
                                    'index' => $index,
                                ]
                            );
                    }
                }

                $inspection =
                    $this->workflows->inspect(
                        $workflow
                    );

                if (
                    ($inspection['overdue']
                        ?? false) === true
                ) {
                    $findings[] =
                        $this->createFinding(
                            'warning',
                            'workflow',
                            'workflow_overdue',
                            'Workflow is overdue.',
                            [
                                'workflow_id' =>
                                    $workflow[
                                        'workflow_id'
                                    ] ?? null,
                            ]
                        );
                }

                if (
                    (int)(
                        $inspection[
                            'active_blocker_count'
                        ] ?? 0
                    ) > 0
                ) {
                    $findings[] =
                        $this->createFinding(
                            'warning',
                            'workflow',
                            'workflow_blocked',
                            'Workflow contains active blockers.',
                            [
                                'workflow_id' =>
                                    $workflow[
                                        'workflow_id'
                                    ] ?? null,
                                'blocker_count' =>
                                    $inspection[
                                        'active_blocker_count'
                                    ],
                            ]
                        );
                }
            } catch (Throwable $exception) {
                $findings[] =
                    $this->exceptionFinding(
                        $exception,
                        'workflow_audit_exception',
                        [
                            'workflow_id' =>
                                $workflow[
                                    'workflow_id'
                                ] ?? null,
                            'index' => $index,
                        ]
                    );
            }

            $findings = array_merge(
                $findings,
                $this->auditIdentityAndAttribution(
                    $workflow,
                    'workflow',
                    $index
                )
            );
        }

        return $this->sectionResult(
            'workflows',
            count($workflows),
            $validCount,
            $findings
        );
    }

    /**
     * Audit relationship records and graph references.
     *
     * @param array<int,array<string,mixed>> $relationships
     * @param array<int,array<string,mixed>> $entities
     *
     * @return array<string,mixed>
     */
    public function auditRelationships(
        array $relationships,
        array $entities = []
    ): array {
        $findings = [];
        $validCount = 0;
        $entityIndex = [];

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $id = $this->resolveRecordId(
                $entity
            );

            if ($id !== '') {
                $entityIndex[$id] = true;
            }
        }

        $relationshipIds = [];

        foreach (
            $relationships
            as $index => $relationship
        ) {
            if (!is_array($relationship)) {
                $findings[] =
                    $this->createFinding(
                        'error',
                        'structure',
                        'invalid_relationship_record',
                        'Relationship collection contains a non-array record.',
                        [
                            'index' => $index,
                        ]
                    );

                continue;
            }

            $relationshipId = trim(
                (string)(
                    $relationship[
                        'relationship_id'
                    ] ?? ''
                )
            );

            if ($relationshipId === '') {
                $findings[] =
                    $this->createFinding(
                        'error',
                        'identity',
                        'relationship_missing_identifier',
                        'Relationship lacks relationship_id.',
                        [
                            'index' => $index,
                        ]
                    );
            } elseif (
                isset(
                    $relationshipIds[
                        $relationshipId
                    ]
                )
            ) {
                $findings[] =
                    $this->createFinding(
                        'error',
                        'duplicate',
                        'duplicate_relationship_identifier',
                        'Duplicate relationship identifier detected.',
                        [
                            'relationship_id' =>
                                $relationshipId,
                            'index' => $index,
                        ]
                    );
            } else {
                $relationshipIds[
                    $relationshipId
                ] = true;
            }

            $sourceId = trim(
                (string)(
                    $relationship['source_id']
                        ?? ''
                )
            );

            $targetId = trim(
                (string)(
                    $relationship['target_id']
                        ?? ''
                )
            );

            $type = trim(
                (string)(
                    $relationship[
                        'relationship_type'
                    ] ?? ''
                )
            );

            $recordValid = true;

            if ($sourceId === '') {
                $recordValid = false;

                $findings[] =
                    $this->createFinding(
                        'error',
                        'relationship',
                        'relationship_missing_source',
                        'Relationship lacks source_id.',
                        [
                            'relationship_id' =>
                                $relationshipId,
                            'index' => $index,
                        ]
                    );
            }

            if ($targetId === '') {
                $recordValid = false;

                $findings[] =
                    $this->createFinding(
                        'error',
                        'relationship',
                        'relationship_missing_target',
                        'Relationship lacks target_id.',
                        [
                            'relationship_id' =>
                                $relationshipId,
                            'index' => $index,
                        ]
                    );
            }

            if ($type === '') {
                $recordValid = false;

                $findings[] =
                    $this->createFinding(
                        'error',
                        'relationship',
                        'relationship_missing_type',
                        'Relationship lacks relationship_type.',
                        [
                            'relationship_id' =>
                                $relationshipId,
                            'index' => $index,
                        ]
                    );
            }

            if (
                $sourceId !== ''
                && $targetId !== ''
                && $sourceId === $targetId
            ) {
                $findings[] =
                    $this->createFinding(
                        'warning',
                        'relationship',
                        'relationship_self_reference',
                        'Relationship connects an entity to itself.',
                        [
                            'relationship_id' =>
                                $relationshipId,
                            'entity_id' =>
                                $sourceId,
                        ]
                    );
            }

            if (
                $entityIndex !== []
                && $sourceId !== ''
                && !isset(
                    $entityIndex[$sourceId]
                )
            ) {
                $findings[] =
                    $this->createFinding(
                        'warning',
                        'graph',
                        'relationship_source_missing',
                        'Relationship source is absent from the audited entity collection.',
                        [
                            'relationship_id' =>
                                $relationshipId,
                            'source_id' =>
                                $sourceId,
                        ]
                    );
            }

            if (
                $entityIndex !== []
                && $targetId !== ''
                && !isset(
                    $entityIndex[$targetId]
                )
            ) {
                $findings[] =
                    $this->createFinding(
                        'warning',
                        'graph',
                        'relationship_target_missing',
                        'Relationship target is absent from the audited entity collection.',
                        [
                            'relationship_id' =>
                                $relationshipId,
                            'target_id' =>
                                $targetId,
                        ]
                    );
            }

            if ($recordValid) {
                $validCount++;
            }

            $findings = array_merge(
                $findings,
                $this->auditIdentityAndAttribution(
                    $relationship,
                    'relationship',
                    $index
                )
            );
        }

        try {
            if (
                $entities !== []
                || $relationships !== []
            ) {
                $analysis =
                    $this->analytics->analyze(
                        $entities,
                        $relationships
                    );

                $isolates = $analysis[
                    'isolates'
                ] ?? [];

                if (
                    is_array($isolates)
                    && $isolates !== []
                ) {
                    $findings[] =
                        $this->createFinding(
                            'notice',
                            'graph',
                            'graph_isolates_detected',
                            'Graph contains isolated entities.',
                            [
                                'isolate_count' =>
                                    count($isolates),
                            ]
                        );
                }
            }
        } catch (Throwable $exception) {
            $findings[] =
                $this->exceptionFinding(
                    $exception,
                    'graph_analysis_exception'
                );
        }

        return $this->sectionResult(
            'relationships',
            count($relationships),
            $validCount,
            $findings
        );
    }

    /**
     * Audit ledger entries and transaction integrity.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function auditLedger(
        array $entries
    ): array {
        $findings = [];
        $validCount = 0;

        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                $findings[] =
                    $this->createFinding(
                        'error',
                        'structure',
                        'invalid_ledger_record',
                        'Ledger collection contains a non-array record.',
                        [
                            'index' => $index,
                        ]
                    );

                continue;
            }

            try {
                $validation =
                    $this->ledger->validateEntry(
                        $entry
                    );

                if (
                    ($validation['valid']
                        ?? false) === true
                ) {
                    $validCount++;
                } else {
                    foreach (
                        $validation['errors']
                            ?? []
                        as $error
                    ) {
                        $findings[] =
                            $this->createFinding(
                                'error',
                                'ledger',
                                'ledger_validation_failure',
                                (string)$error,
                                [
                                    'ledger_entry_id' =>
                                        $entry[
                                            'ledger_entry_id'
                                        ] ?? null,
                                    'index' => $index,
                                ]
                            );
                    }
                }

                foreach (
                    $validation['warnings']
                        ?? []
                    as $warning
                ) {
                    $findings[] =
                        $this->createFinding(
                            'warning',
                            'ledger',
                            'ledger_validation_warning',
                            (string)$warning,
                            [
                                'ledger_entry_id' =>
                                    $entry[
                                        'ledger_entry_id'
                                    ] ?? null,
                                'index' => $index,
                            ]
                        );
                }
            } catch (Throwable $exception) {
                $findings[] =
                    $this->exceptionFinding(
                        $exception,
                        'ledger_audit_exception',
                        [
                            'ledger_entry_id' =>
                                $entry[
                                    'ledger_entry_id'
                                ] ?? null,
                            'index' => $index,
                        ]
                    );
            }
        }

        try {
            $groups =
                $this->ledger->groupTransactions(
                    $entries
                );

            foreach (
                $groups['transactions']
                    ?? []
                as $transaction
            ) {
                if (
                    ($transaction['balanced']
                        ?? false) !== true
                ) {
                    $findings[] =
                        $this->createFinding(
                            'critical',
                            'ledger',
                            'transaction_unbalanced',
                            'Ledger transaction is unbalanced.',
                            [
                                'transaction_id' =>
                                    $transaction[
                                        'transaction_id'
                                    ] ?? null,
                            ]
                        );
                }

                if (
                    ($transaction['chain_valid']
                        ?? false) !== true
                ) {
                    $findings[] =
                        $this->createFinding(
                            'critical',
                            'hash_chain',
                            'transaction_chain_invalid',
                            'Ledger transaction hash chain is invalid.',
                            [
                                'transaction_id' =>
                                    $transaction[
                                        'transaction_id'
                                    ] ?? null,
                            ]
                        );
                }

                if (
                    ($transaction['sequence_valid']
                        ?? false) !== true
                ) {
                    $findings[] =
                        $this->createFinding(
                            'error',
                            'chronology',
                            'transaction_sequence_invalid',
                            'Ledger transaction sequence is incomplete or duplicated.',
                            [
                                'transaction_id' =>
                                    $transaction[
                                        'transaction_id'
                                    ] ?? null,
                            ]
                        );
                }
            }

            $exceptions =
                $this->ledger->detectExceptions(
                    $entries
                );

            foreach (
                $exceptions['exceptions']
                    ?? []
                as $exception
            ) {
                $findings[] =
                    $this->createFinding(
                        (string)(
                            $exception['severity']
                                ?? 'warning'
                        ),
                        'ledger',
                        (string)(
                            $exception['type']
                                ?? 'ledger_exception'
                        ),
                        (string)(
                            $exception['message']
                                ?? 'Ledger exception detected.'
                        ),
                        $exception
                    );
            }
        } catch (Throwable $exception) {
            $findings[] =
                $this->exceptionFinding(
                    $exception,
                    'ledger_collection_audit_exception'
                );
        }

        return $this->sectionResult(
            'ledger',
            count($entries),
            $validCount,
            $findings
        );
    }

    /**
     * Audit event chronology.
     *
     * @param array<int,array<string,mixed>> $events
     *
     * @return array<string,mixed>
     */
    public function auditEvents(
        array $events
    ): array {
        $findings = [];
        $validCount = 0;
        $seenIds = [];
        $previousTimestamp = null;

        usort(
            $events,
            static fn (
                array $left,
                array $right
            ): int =>
                strcmp(
                    (string)(
                        $left['occurred_at']
                            ?? $left['created_at']
                            ?? ''
                    ),
                    (string)(
                        $right['occurred_at']
                            ?? $right['created_at']
                            ?? ''
                    )
                )
        );

        foreach ($events as $index => $event) {
            if (!is_array($event)) {
                $findings[] =
                    $this->createFinding(
                        'error',
                        'structure',
                        'invalid_event_record',
                        'Event collection contains a non-array record.',
                        [
                            'index' => $index,
                        ]
                    );

                continue;
            }

            $eventId = trim(
                (string)(
                    $event['event_id']
                        ?? $event['entity_id']
                        ?? ''
                )
            );

            $eventType = trim(
                (string)(
                    $event['event_type']
                        ?? $event['type']
                        ?? ''
                )
            );

            $timestamp = trim(
                (string)(
                    $event['occurred_at']
                        ?? $event['created_at']
                        ?? ''
                )
            );

            $recordValid = true;

            if ($eventId === '') {
                $recordValid = false;

                $findings[] =
                    $this->createFinding(
                        'error',
                        'identity',
                        'event_missing_identifier',
                        'Event lacks event_id.',
                        [
                            'index' => $index,
                        ]
                    );
            } elseif (isset($seenIds[$eventId])) {
                $recordValid = false;

                $findings[] =
                    $this->createFinding(
                        'error',
                        'duplicate',
                        'duplicate_event_identifier',
                        'Duplicate event identifier detected.',
                        [
                            'event_id' => $eventId,
                            'index' => $index,
                        ]
                    );
            } else {
                $seenIds[$eventId] = true;
            }

            if ($eventType === '') {
                $recordValid = false;

                $findings[] =
                    $this->createFinding(
                        'error',
                        'structure',
                        'event_missing_type',
                        'Event lacks event_type.',
                        [
                            'event_id' => $eventId,
                            'index' => $index,
                        ]
                    );
            }

            if (
                $timestamp === ''
                || strtotime($timestamp) === false
            ) {
                $recordValid = false;

                $findings[] =
                    $this->createFinding(
                        'error',
                        'chronology',
                        'event_invalid_timestamp',
                        'Event lacks a valid occurrence timestamp.',
                        [
                            'event_id' => $eventId,
                            'index' => $index,
                        ]
                    );
            }

            if (
                $timestamp !== ''
                && $previousTimestamp !== null
                && strcmp(
                    $timestamp,
                    $previousTimestamp
                ) < 0
            ) {
                $findings[] =
                    $this->createFinding(
                        'warning',
                        'chronology',
                        'event_chronology_reversal',
                        'Event chronology moved backward.',
                        [
                            'event_id' => $eventId,
                            'index' => $index,
                        ]
                    );
            }

            if ($timestamp !== '') {
                $previousTimestamp = $timestamp;
            }

            if ($recordValid) {
                $validCount++;
            }

            $findings = array_merge(
                $findings,
                $this->auditIdentityAndAttribution(
                    $event,
                    'event',
                    $index
                )
            );
        }

        return $this->sectionResult(
            'events',
            count($events),
            $validCount,
            $findings
        );
    }

    /**
     * Audit provenance records.
     *
     * @param array<int,array<string,mixed>> $records
     *
     * @return array<string,mixed>
     */
    public function auditProvenance(
        array $records
    ): array {
        $findings = [];
        $validCount = 0;

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                $findings[] =
                    $this->createFinding(
                        'error',
                        'structure',
                        'invalid_provenance_record',
                        'Provenance collection contains a non-array record.',
                        [
                            'index' => $index,
                        ]
                    );

                continue;
            }

            $provenanceId = trim(
                (string)(
                    $record['provenance_id']
                        ?? $record['entity_id']
                        ?? ''
                )
            );

            $source = trim(
                (string)(
                    $record['source_reference']
                        ?? $record['source']
                        ?? $record['source_url']
                        ?? ''
                )
            );

            $actor = trim(
                (string)(
                    $record['created_by']
                        ?? $record['actor_id']
                        ?? $record['originator_id']
                        ?? ''
                )
            );

            $recordValid = true;

            if ($provenanceId === '') {
                $recordValid = false;

                $findings[] =
                    $this->createFinding(
                        'error',
                        'provenance',
                        'provenance_missing_identifier',
                        'Provenance record lacks provenance_id.',
                        [
                            'index' => $index,
                        ]
                    );
            }

            if ($source === '') {
                $recordValid = false;

                $findings[] =
                    $this->createFinding(
                        'warning',
                        'provenance',
                        'provenance_missing_source',
                        'Provenance record lacks source reference.',
                        [
                            'provenance_id' =>
                                $provenanceId,
                            'index' => $index,
                        ]
                    );
            }

            if ($actor === '') {
                $recordValid = false;

                $findings[] =
                    $this->createFinding(
                        'error',
                        'attribution',
                        'provenance_missing_actor',
                        'Provenance record lacks actor attribution.',
                        [
                            'provenance_id' =>
                                $provenanceId,
                            'index' => $index,
                        ]
                    );
            }

            if ($recordValid) {
                $validCount++;
            }

            $findings = array_merge(
                $findings,
                $this->auditStoredChecksum(
                    $record,
                    'provenance',
                    $provenanceId
                )
            );
        }

        return $this->sectionResult(
            'provenance',
            count($records),
            $validCount,
            $findings
        );
    }

    /**
     * Audit version records.
     *
     * @param array<int,array<string,mixed>> $versions
     *
     * @return array<string,mixed>
     */
    public function auditVersions(
        array $versions
    ): array {
        $findings = [];
        $validCount = 0;
        $groups = [];

        foreach ($versions as $index => $version) {
            if (!is_array($version)) {
                $findings[] =
                    $this->createFinding(
                        'error',
                        'structure',
                        'invalid_version_record',
                        'Version collection contains a non-array record.',
                        [
                            'index' => $index,
                        ]
                    );

                continue;
            }

            $versionId = trim(
                (string)(
                    $version['version_id']
                        ?? $version['entity_id']
                        ?? ''
                )
            );

            $subjectId = trim(
                (string)(
                    $version['subject_id']
                        ?? $version['asset_id']
                        ?? $version['idea_id']
                        ?? ''
                )
            );

            $number = trim(
                (string)(
                    $version['version']
                        ?? $version['version_number']
                        ?? ''
                )
            );

            $recordValid = true;

            if ($versionId === '') {
                $recordValid = false;

                $findings[] =
                    $this->createFinding(
                        'error',
                        'identity',
                        'version_missing_identifier',
                        'Version record lacks version_id.',
                        [
                            'index' => $index,
                        ]
                    );
            }

            if ($subjectId === '') {
                $recordValid = false;

                $findings[] =
                    $this->createFinding(
                        'error',
                        'version',
                        'version_missing_subject',
                        'Version record lacks subject identifier.',
                        [
                            'version_id' =>
                                $versionId,
                            'index' => $index,
                        ]
                    );
            }

            if ($number === '') {
                $recordValid = false;

                $findings[] =
                    $this->createFinding(
                        'error',
                        'version',
                        'version_missing_number',
                        'Version record lacks version number.',
                        [
                            'version_id' =>
                                $versionId,
                            'index' => $index,
                        ]
                    );
            }

            if ($subjectId !== '') {
                $groups[$subjectId][] =
                    $number;
            }

            if ($recordValid) {
                $validCount++;
            }

            $findings = array_merge(
                $findings,
                $this->auditIdentityAndAttribution(
                    $version,
                    'version',
                    $index
                )
            );
        }

        foreach ($groups as $subjectId => $numbers) {
            $duplicates = array_filter(
                array_count_values($numbers),
                static fn (int $count): bool =>
                    $count > 1
            );

            foreach (
                array_keys($duplicates)
                as $duplicateVersion
            ) {
                $findings[] =
                    $this->createFinding(
                        'error',
                        'duplicate',
                        'duplicate_subject_version',
                        'Subject contains duplicate version number.',
                        [
                            'subject_id' =>
                                $subjectId,
                            'version' =>
                                $duplicateVersion,
                        ]
                    );
            }
        }

        return $this->sectionResult(
            'versions',
            count($versions),
            $validCount,
            $findings
        );
    }

    /**
     * Audit declared service files.
     *
     * Each record may contain:
     * file, class, required, instantiated, syntax_valid, exists.
     *
     * @param array<int,array<string,mixed>> $services
     *
     * @return array<string,mixed>
     */
    public function auditServices(
        array $services
    ): array {
        $findings = [];
        $validCount = 0;

        foreach ($services as $index => $service) {
            if (!is_array($service)) {
                $findings[] =
                    $this->createFinding(
                        'error',
                        'structure',
                        'invalid_service_record',
                        'Service inventory contains a non-array record.',
                        [
                            'index' => $index,
                        ]
                    );

                continue;
            }

            $file = trim(
                (string)(
                    $service['file']
                        ?? $service['path']
                        ?? ''
                )
            );

            $class = trim(
                (string)(
                    $service['class']
                        ?? $service['symbol']
                        ?? ''
                )
            );

            $required = (bool)(
                $service['required']
                    ?? true
            );

            $exists = (bool)(
                $service['exists']
                    ?? (
                        $file !== ''
                        && is_file($file)
                    )
            );

            $syntaxValid = (bool)(
                $service['syntax_valid']
                    ?? true
            );

            $declared = (bool)(
                $service['declared']
                    ?? (
                        $class !== ''
                        && class_exists(
                            $class,
                            false
                        )
                    )
            );

            $instantiated = $service[
                'instantiated'
            ] ?? null;

            $recordValid = true;

            if ($required && !$exists) {
                $recordValid = false;

                $findings[] =
                    $this->createFinding(
                        'critical',
                        'dependency',
                        'required_service_missing',
                        'Required service file is missing.',
                        [
                            'file' => $file,
                            'class' => $class,
                            'index' => $index,
                        ]
                    );
            }

            if ($exists && !$syntaxValid) {
                $recordValid = false;

                $findings[] =
                    $this->createFinding(
                        'critical',
                        'deployment',
                        'service_syntax_failure',
                        'Service file failed syntax validation.',
                        [
                            'file' => $file,
                            'class' => $class,
                            'index' => $index,
                        ]
                    );
            }

            if (
                $required
                && $exists
                && !$declared
            ) {
                $recordValid = false;

                $findings[] =
                    $this->createFinding(
                        'critical',
                        'dependency',
                        'service_class_missing',
                        'Required service class was not declared.',
                        [
                            'file' => $file,
                            'class' => $class,
                            'index' => $index,
                        ]
                    );
            }

            if (
                $instantiated === false
            ) {
                $recordValid = false;

                $findings[] =
                    $this->createFinding(
                        'error',
                        'dependency',
                        'service_instantiation_failure',
                        'Service class could not be instantiated.',
                        [
                            'file' => $file,
                            'class' => $class,
                            'index' => $index,
                            'message' =>
                                $service['message']
                                    ?? null,
                        ]
                    );
            }

            if ($recordValid) {
                $validCount++;
            }
        }

        return $this->sectionResult(
            'services',
            count($services),
            $validCount,
            $findings
        );
    }

    /**
     * Audit a generic collection.
     *
     * @param array<int,array<string,mixed>> $records
     *
     * @return array<string,mixed>
     */
    public function auditGenericCollection(
        array $records,
        string $recordType = 'record'
    ): array {
        $findings = [];
        $validCount = 0;

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                $findings[] =
                    $this->createFinding(
                        'error',
                        'structure',
                        'invalid_generic_record',
                        sprintf(
                            '%s collection contains a non-array record.',
                            ucfirst($recordType)
                        ),
                        [
                            'index' => $index,
                        ]
                    );

                continue;
            }

            $id = $this->resolveRecordId(
                $record
            );

            if ($id === '') {
                $findings[] =
                    $this->createFinding(
                        'error',
                        'identity',
                        'record_missing_identifier',
                        sprintf(
                            '%s record lacks an identifier.',
                            ucfirst($recordType)
                        ),
                        [
                            'index' => $index,
                        ]
                    );

                continue;
            }

            $validCount++;

            $findings = array_merge(
                $findings,
                $this->auditIdentityAndAttribution(
                    $record,
                    $recordType,
                    $index
                )
            );
        }

        return $this->sectionResult(
            $recordType,
            count($records),
            $validCount,
            $findings
        );
    }

    /**
     * Create a deployment-readiness report directly from audit sections.
     *
     * @param array<string,mixed> $auditReport
     *
     * @return array<string,mixed>
     */
    public function deploymentReadiness(
        array $auditReport,
        array $options = []
    ): array {
        return $this->calculateDeploymentReadiness(
            $auditReport,
            $options
        );
    }

    /**
     * Produce a compact audit summary.
     *
     * @return array<string,mixed>
     */
    public function summarize(
        array $auditReport
    ): array {
        return [
            'audit_id' =>
                $auditReport['audit_id']
                    ?? null,

            'scope' =>
                $auditReport['scope']
                    ?? null,

            'subject_id' =>
                $auditReport['subject_id']
                    ?? null,

            'status' =>
                $auditReport['status']
                    ?? null,

            'finding_summary' =>
                $auditReport[
                    'finding_summary'
                ] ?? $this->emptyFindingSummary(),

            'deployment_readiness' =>
                $auditReport[
                    'deployment_readiness'
                ] ?? null,

            'recommendation_count' =>
                count(
                    $auditReport[
                        'recommendations'
                    ] ?? []
                ),

            'started_at' =>
                $auditReport['started_at']
                    ?? null,

            'completed_at' =>
                $auditReport['completed_at']
                    ?? null,

            'checksum' =>
                $auditReport['checksum']
                    ?? null,
        ];
    }

    /**
     * Convert audit into graph entity form.
     *
     * @return array<string,mixed>
     */
    public function toGraphEntity(
        array $auditReport
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

        return array_merge(
            $auditReport,
            [
                'entity_id' => $auditId,

                'entity_type' => 'audit',

                'graph_label' =>
                    $auditReport['title']
                        ?? $auditId,

                'graph_status' =>
                    $auditReport['status']
                        ?? 'draft',
            ]
        );
    }

    /**
     * Create relationship from an audit to its subject.
     *
     * @return array<string,mixed>
     */
    public function subjectRelationship(
        array $auditReport,
        string $actorId
    ): array {
        $auditId = trim(
            (string)(
                $auditReport['audit_id']
                    ?? ''
            )
        );

        $subjectId = trim(
            (string)(
                $auditReport['subject_id']
                    ?? ''
            )
        );

        if (
            $auditId === ''
            || $subjectId === ''
        ) {
            throw new InvalidArgumentException(
                'Audit and subject identifiers are required.'
            );
        }

        return $this->relationships->create(
            [
                'source_id' => $auditId,

                'source_type' => 'audit',

                'target_id' => $subjectId,

                'target_type' =>
                    $auditReport['subject_type']
                        ?? 'entity',

                'relationship_type' =>
                    'audits',

                'status' =>
                    in_array(
                        $auditReport['status']
                            ?? '',
                        [
                            'completed',
                            'completed_with_findings',
                        ],
                        true
                    )
                        ? 'verified'
                        : 'proposed',

                'confidence' => 100,

                'weight' => 1,

                'strength' => 1,

                'created_by' => $actorId,

                'metadata' => [
                    'finding_summary' =>
                        $auditReport[
                            'finding_summary'
                        ] ?? [],
                ],
            ]
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
                'statuses' =>
                    $this->statuses,

                'scopes' =>
                    $this->scopes,

                'severities' =>
                    $this->severities,

                'categories' =>
                    $this->categories,

                'severity_weights' =>
                    $this->severityWeights,

                'supports_system_audit' =>
                    true,

                'supports_deployment_readiness' =>
                    true,

                'supports_ledger_integrity' =>
                    true,

                'supports_graph_audit' =>
                    true,

                'supports_provenance_audit' =>
                    true,

                'supports_service_inventory' =>
                    true,

                'database_operations' =>
                    false,

                'automatic_remediation' =>
                    false,

                'human_attribution_required' =>
                    true,

                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Audit identity, attribution, chronology, and stored checksum.
     *
     * @return array<int,array<string,mixed>>
     */
    private function auditIdentityAndAttribution(
        array $record,
        string $recordType,
        int|string $index
    ): array {
        $findings = [];

        $id = $this->resolveRecordId(
            $record
        );

        if ($id === '') {
            $findings[] =
                $this->createFinding(
                    'error',
                    'identity',
                    'missing_record_identifier',
                    sprintf(
                        '%s record lacks a canonical identifier.',
                        ucfirst($recordType)
                    ),
                    [
                        'record_type' =>
                            $recordType,
                        'index' => $index,
                    ]
                );
        }

        $actor = trim(
            (string)(
                $record['created_by']
                    ?? $record['originator_id']
                    ?? $record['actor_id']
                    ?? ''
            )
        );

        if ($actor === '') {
            $findings[] =
                $this->createFinding(
                    'error',
                    'attribution',
                    'missing_record_attribution',
                    sprintf(
                        '%s record lacks creator attribution.',
                        ucfirst($recordType)
                    ),
                    [
                        'record_id' => $id,
                        'record_type' =>
                            $recordType,
                        'index' => $index,
                    ]
                );
        }

        $createdAt = trim(
            (string)(
                $record['created_at']
                    ?? ''
            )
        );

        if (
            $createdAt === ''
            || strtotime($createdAt) === false
        ) {
            $findings[] =
                $this->createFinding(
                    'warning',
                    'chronology',
                    'missing_or_invalid_created_at',
                    sprintf(
                        '%s record lacks a valid creation timestamp.',
                        ucfirst($recordType)
                    ),
                    [
                        'record_id' => $id,
                        'record_type' =>
                            $recordType,
                        'index' => $index,
                    ]
                );
        }

        $updatedAt = trim(
            (string)(
                $record['updated_at']
                    ?? ''
            )
        );

        if (
            $createdAt !== ''
            && $updatedAt !== ''
            && strtotime($createdAt) !== false
            && strtotime($updatedAt) !== false
            && strtotime($updatedAt)
                < strtotime($createdAt)
        ) {
            $findings[] =
                $this->createFinding(
                    'error',
                    'chronology',
                    'updated_before_created',
                    sprintf(
                        '%s record was updated before it was created.',
                        ucfirst($recordType)
                    ),
                    [
                        'record_id' => $id,
                        'record_type' =>
                            $recordType,
                        'index' => $index,
                    ]
                );
        }

        return array_merge(
            $findings,
            $this->auditStoredChecksum(
                $record,
                $recordType,
                $id
            )
        );
    }

    /**
     * Audit a stored generic checksum.
     *
     * This uses the AuditService normalization method and is intended as an
     * additional integrity signal. Domain services remain authoritative.
     *
     * @return array<int,array<string,mixed>>
     */
    private function auditStoredChecksum(
        array $record,
        string $recordType,
        string $recordId
    ): array {
        $storedChecksum = trim(
            (string)(
                $record['checksum']
                    ?? ''
            )
        );

        if ($storedChecksum === '') {
            return [
                $this->createFinding(
                    'notice',
                    'checksum',
                    'checksum_missing',
                    sprintf(
                        '%s record has no stored checksum.',
                        ucfirst($recordType)
                    ),
                    [
                        'record_id' =>
                            $recordId,
                        'record_type' =>
                            $recordType,
                    ]
                ),
            ];
        }

        if (
            preg_match(
                '/^[a-f0-9]{64}$/i',
                $storedChecksum
            ) !== 1
        ) {
            return [
                $this->createFinding(
                    'warning',
                    'checksum',
                    'checksum_format_invalid',
                    sprintf(
                        '%s record checksum is not a SHA-256 value.',
                        ucfirst($recordType)
                    ),
                    [
                        'record_id' =>
                            $recordId,
                        'record_type' =>
                            $recordType,
                    ]
                ),
            ];
        }

        return [];
    }

    /**
     * Detect duplicate canonical identifiers across collections.
     *
     * @param array<string,array<int,array<string,mixed>>> $collections
     *
     * @return array<int,array<string,mixed>>
     */
    private function detectDuplicateIdentifiers(
        array $collections
    ): array {
        $findings = [];
        $seen = [];

        foreach (
            $collections
            as $collectionName => $records
        ) {
            foreach ($records as $index => $record) {
                if (!is_array($record)) {
                    continue;
                }

                $id = $this->resolveRecordId(
                    $record
                );

                if ($id === '') {
                    continue;
                }

                if (isset($seen[$id])) {
                    $findings[] =
                        $this->createFinding(
                            'error',
                            'duplicate',
                            'duplicate_canonical_identifier',
                            'Canonical identifier appears more than once.',
                            [
                                'record_id' => $id,

                                'first_collection' =>
                                    $seen[$id][
                                        'collection'
                                    ],

                                'first_index' =>
                                    $seen[$id]['index'],

                                'duplicate_collection' =>
                                    $collectionName,

                                'duplicate_index' =>
                                    $index,
                            ]
                        );
                } else {
                    $seen[$id] = [
                        'collection' =>
                            $collectionName,
                        'index' => $index,
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * Calculate deployment readiness.
     *
     * @return array<string,mixed>
     */
    private function calculateDeploymentReadiness(
        array $auditReport,
        array $options = []
    ): array {
        $options = array_replace(
            [
                'maximum_warnings' => 25,
                'minimum_score' => 90.0,
                'require_service_section' =>
                    false,
                'require_zero_errors' =>
                    true,
                'require_zero_critical' =>
                    true,
            ],
            $options
        );

        $summary =
            $auditReport[
                'finding_summary'
            ] ?? $this->summarizeFindings(
                $auditReport['findings']
                    ?? []
            );

        $criticalCount = (int)(
            $summary['critical_count']
                ?? 0
        );

        $errorCount = (int)(
            $summary['error_count']
                ?? 0
        );

        $warningCount = (int)(
            $summary['warning_count']
                ?? 0
        );

        $weightedRisk = (int)(
            $summary['weighted_risk']
                ?? 0
        );

        $score = max(
            0.0,
            min(
                100.0,
                100.0 - $weightedRisk
            )
        );

        $sections = is_array(
            $auditReport['sections']
                ?? null
        )
            ? $auditReport['sections']
            : [];

        $requirements = [
            'audit_completed' =>
                in_array(
                    $auditReport['status']
                        ?? '',
                    [
                        'completed',
                        'completed_with_findings',
                    ],
                    true
                ),

            'critical_clear' =>
                !$options[
                    'require_zero_critical'
                ]
                || $criticalCount === 0,

            'errors_clear' =>
                !$options[
                    'require_zero_errors'
                ]
                || $errorCount === 0,

            'warnings_within_limit' =>
                $warningCount
                <= (int)$options[
                    'maximum_warnings'
                ],

            'minimum_score' =>
                $score
                >= (float)$options[
                    'minimum_score'
                ],

            'service_section_present' =>
                !$options[
                    'require_service_section'
                ]
                || isset(
                    $sections['services']
                ),
        ];

        $ready = count(
            array_filter(
                $requirements,
                static fn (
                    bool $value
                ): bool => !$value
            )
        ) === 0;

        return [
            'ready' => $ready,

            'disposition' =>
                $ready
                    ? 'promotable'
                    : (
                        $criticalCount > 0
                            ? 'blocked'
                            : 'remediation_required'
                    ),

            'score' =>
                round($score, 2),

            'minimum_score' =>
                (float)$options[
                    'minimum_score'
                ],

            'critical_count' =>
                $criticalCount,

            'error_count' =>
                $errorCount,

            'warning_count' =>
                $warningCount,

            'weighted_risk' =>
                $weightedRisk,

            'requirements' =>
                $requirements,

            'failed_requirements' =>
                array_keys(
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
     * Create recommendations from findings.
     *
     * @param array<int,array<string,mixed>> $findings
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildRecommendations(
        array $findings
    ): array {
        $recommendations = [];
        $seen = [];

        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }

            $code = trim(
                (string)(
                    $finding['code']
                        ?? ''
                )
            );

            $severity = $this->normalizeSeverity(
                (string)(
                    $finding['severity']
                        ?? 'warning'
                )
            );

            $category = $this->normalizeCategory(
                (string)(
                    $finding['category']
                        ?? 'other'
                )
            );

            $key = $category
                . '|'
                . $code;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $action = match ($category) {
                'identity' =>
                    'Assign and preserve a canonical identifier.',

                'attribution' =>
                    'Add attributable actor information.',

                'provenance' =>
                    'Attach a source or provenance record.',

                'checksum' =>
                    'Regenerate and verify the domain checksum.',

                'hash_chain' =>
                    'Rebuild the ledger chain from the last verified entry.',

                'chronology' =>
                    'Correct timestamps or sequence ordering.',

                'relationship',
                'graph' =>
                    'Review graph references and repair missing endpoints.',

                'workflow' =>
                    'Resolve blockers and validate stage dependencies.',

                'decision' =>
                    'Add authority, rationale, and decision attribution.',

                'ledger' =>
                    'Reconcile the ledger and correct through compensating entries.',

                'dependency' =>
                    'Restore the missing file or dependency and rerun the boot test.',

                'deployment' =>
                    'Correct syntax or loading failures before promotion.',

                default =>
                    'Review the finding and record an attributable remediation decision.',
            };

            $recommendations[] = [
                'recommendation_id' =>
                    $this->generateRecommendationId(
                        $key
                    ),

                'priority' =>
                    match ($severity) {
                        'critical' => 1,
                        'error' => 2,
                        'warning' => 3,
                        'notice' => 4,
                        default => 5,
                    },

                'severity' =>
                    $severity,

                'category' =>
                    $category,

                'finding_code' =>
                    $code,

                'action' =>
                    $action,

                'automatic' =>
                    false,

                'decision_required' =>
                    in_array(
                        $severity,
                        [
                            'error',
                            'critical',
                        ],
                        true
                    ),
            ];
        }

        usort(
            $recommendations,
            static fn (
                array $left,
                array $right
            ): int =>
                (
                    (int)(
                        $left['priority']
                            ?? 99
                    )
                ) <=> (
                    (int)(
                        $right['priority']
                            ?? 99
                    )
                )
        );

        return $recommendations;
    }

    /**
     * Build one section result.
     *
     * @return array<string,mixed>
     */
    private function sectionResult(
        string $name,
        int $recordCount,
        int $validCount,
        array $findings
    ): array {
        $findings =
            $this->normalizeFindings(
                $findings
            );

        return [
            'section' => $name,

            'record_count' =>
                $recordCount,

            'valid_count' =>
                $validCount,

            'invalid_count' =>
                max(
                    0,
                    $recordCount
                    - $validCount
                ),

            'finding_count' =>
                count($findings),

            'finding_summary' =>
                $this->summarizeFindings(
                    $findings
                ),

            'findings' =>
                $findings,

            'passed' =>
                count(
                    array_filter(
                        $findings,
                        static fn (
                            array $finding
                        ): bool =>
                            in_array(
                                $finding['severity']
                                    ?? '',
                                [
                                    'error',
                                    'critical',
                                ],
                                true
                            )
                    )
                ) === 0,
        ];
    }

    /**
     * Create one normalized finding.
     *
     * @return array<string,mixed>
     */
    private function createFinding(
        string $severity,
        string $category,
        string $code,
        string $message,
        array $context = []
    ): array {
        $severity =
            $this->normalizeSeverity(
                $severity
            );

        $category =
            $this->normalizeCategory(
                $category
            );

        $code =
            $this->normalizeMachineKey(
                $code
            );

        $message = trim($message);

        $findingId = 'FND-'
            . strtoupper(
                substr(
                    hash(
                        'sha256',
                        $severity
                        . '|'
                        . $category
                        . '|'
                        . $code
                        . '|'
                        . $message
                        . '|'
                        . json_encode(
                            $this->normalizeForHash(
                                $context
                            ),
                            JSON_UNESCAPED_SLASHES
                            | JSON_UNESCAPED_UNICODE
                        )
                    ),
                    0,
                    16
                )
            );

        return [
            'finding_id' =>
                $findingId,

            'severity' =>
                $severity,

            'category' =>
                $category,

            'code' =>
                $code !== ''
                    ? $code
                    : 'unspecified_finding',

            'message' =>
                $message !== ''
                    ? $message
                    : 'Audit finding detected.',

            'context' =>
                $context,

            'detected_at' =>
                gmdate('c'),

            'resolved' =>
                false,

            'resolution' =>
                null,
        ];
    }

    /**
     * Convert an exception into a finding.
     *
     * @return array<string,mixed>
     */
    private function exceptionFinding(
        Throwable $exception,
        string $code,
        array $context = []
    ): array {
        return $this->createFinding(
            'error',
            'other',
            $code,
            get_class($exception)
            . ': '
            . $exception->getMessage(),
            array_merge(
                $context,
                [
                    'exception_class' =>
                        get_class($exception),
                ]
            )
        );
    }

    /**
     * Normalize findings and remove duplicates.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeFindings(
        mixed $findings
    ): array {
        if (!is_array($findings)) {
            return [];
        }

        $normalized = [];

        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }

            $findingId = trim(
                (string)(
                    $finding['finding_id']
                        ?? ''
                )
            );

            if ($findingId === '') {
                $finding = $this->createFinding(
                    (string)(
                        $finding['severity']
                            ?? 'warning'
                    ),
                    (string)(
                        $finding['category']
                            ?? 'other'
                    ),
                    (string)(
                        $finding['code']
                            ?? 'finding'
                    ),
                    (string)(
                        $finding['message']
                            ?? 'Audit finding detected.'
                    ),
                    is_array(
                        $finding['context']
                            ?? null
                    )
                        ? $finding['context']
                        : []
                );

                $findingId =
                    $finding['finding_id'];
            }

            $normalized[$findingId] =
                $finding;
        }

        return array_values($normalized);
    }

    /**
     * Summarize findings.
     *
     * @param array<int,array<string,mixed>> $findings
     *
     * @return array<string,mixed>
     */
    private function summarizeFindings(
        array $findings
    ): array {
        $summary =
            $this->emptyFindingSummary();

        $categories = [];
        $codes = [];

        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }

            $severity =
                $this->normalizeSeverity(
                    (string)(
                        $finding['severity']
                            ?? 'warning'
                    )
                );

            $category =
                $this->normalizeCategory(
                    (string)(
                        $finding['category']
                            ?? 'other'
                    )
                );

            $code = trim(
                (string)(
                    $finding['code']
                        ?? 'unspecified'
                )
            );

            $summary['total']++;

            $summary[
                $severity . '_count'
            ]++;

            $summary['weighted_risk'] +=
                $this->severityWeights[
                    $severity
                ] ?? 0;

            $categories[$category] =
                ($categories[$category]
                    ?? 0) + 1;

            $codes[$code] =
                ($codes[$code]
                    ?? 0) + 1;
        }

        ksort($categories);
        ksort($codes);

        $summary['categories'] =
            $categories;

        $summary['codes'] =
            $codes;

        $summary['highest_severity'] =
            $this->highestSeverity(
                $findings
            );

        return $summary;
    }

    /**
     * Empty finding summary.
     *
     * @return array<string,mixed>
     */
    private function emptyFindingSummary(): array
    {
        return [
            'total' => 0,
            'info_count' => 0,
            'notice_count' => 0,
            'warning_count' => 0,
            'error_count' => 0,
            'critical_count' => 0,
            'weighted_risk' => 0,
            'highest_severity' => null,
            'categories' => [],
            'codes' => [],
        ];
    }

    /**
     * Determine highest severity.
     */
    private function highestSeverity(
        array $findings
    ): ?string {
        $highestIndex = -1;
        $highest = null;

        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }

            $severity =
                $this->normalizeSeverity(
                    (string)(
                        $finding['severity']
                            ?? 'info'
                    )
                );

            $index = array_search(
                $severity,
                $this->severities,
                true
            );

            if (
                $index !== false
                && $index > $highestIndex
            ) {
                $highestIndex = $index;
                $highest = $severity;
            }
        }

        return $highest;
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

            $description = trim(
                (string)(
                    $item['description']
                        ?? $item['title']
                        ?? ''
                )
            );

            $reference = trim(
                (string)(
                    $item['source_reference']
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

            $evidenceId = trim(
                (string)(
                    $item['evidence_id']
                        ?? ''
                )
            );

            if ($evidenceId === '') {
                $evidenceId = 'EVD-'
                    . strtoupper(
                        substr(
                            hash(
                                'sha256',
                                $description
                                . '|'
                                . $reference
                            ),
                            0,
                            16
                        )
                    );
            }

            $normalized[$evidenceId] = [
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

                'description' =>
                    $description,

                'source_reference' =>
                    $reference,

                'provenance_id' => trim(
                    (string)(
                        $item['provenance_id']
                            ?? ''
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

        return array_values($normalized);
    }

    /**
     * Normalize collection of records.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeRecordCollection(
        mixed $records
    ): array {
        if (!is_array($records)) {
            return [];
        }

        if (
            $records !== []
            && !array_is_list($records)
        ) {
            $records = [$records];
        }

        return array_values(
            array_filter(
                $records,
                static fn (
                    mixed $record
                ): bool => is_array($record)
            )
        );
    }

    /**
     * Resolve canonical record identifier.
     */
    private function resolveRecordId(
        array $record
    ): string {
        foreach (
            [
                'entity_id',
                'audit_id',
                'idea_id',
                'asset_id',
                'decision_id',
                'workflow_id',
                'relationship_id',
                'ledger_entry_id',
                'event_id',
                'provenance_id',
                'version_id',
                'program_id',
                'organization_id',
                'person_id',
                'document_id',
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
     * Normalize audit status.
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
                    'Unsupported audit status "%s".',
                    $status
                )
            );
        }

        return $status;
    }

    /**
     * Normalize audit scope.
     */
    private function normalizeScope(
        string $scope
    ): string {
        $scope =
            $this->normalizeMachineKey(
                $scope
            );

        if (
            !in_array(
                $scope,
                $this->scopes,
                true
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported audit scope "%s".',
                    $scope
                )
            );
        }

        return $scope;
    }

    /**
     * Normalize finding severity.
     */
    private function normalizeSeverity(
        string $severity
    ): string {
        $severity =
            $this->normalizeMachineKey(
                $severity
            );

        return in_array(
            $severity,
            $this->severities,
            true
        )
            ? $severity
            : 'warning';
    }

    /**
     * Normalize finding category.
     */
    private function normalizeCategory(
        string $category
    ): string {
        $category =
            $this->normalizeMachineKey(
                $category
            );

        return in_array(
            $category,
            $this->categories,
            true
        )
            ? $category
            : 'other';
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
     * Build default audit title.
     */
    private function defaultTitle(
        string $scope,
        string $subjectId
    ): string {
        $scopeTitle = ucwords(
            str_replace(
                '_',
                ' ',
                $scope
            )
        );

        return $subjectId !== ''
            ? sprintf(
                '%s Audit — %s',
                $scopeTitle,
                $subjectId
            )
            : sprintf(
                '%s Audit',
                $scopeTitle
            );
    }

    /**
     * Calculate deterministic report checksum.
     */
    private function calculateChecksum(
        array $report
    ): string {
        $copy = $report;

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
                'Unable to calculate audit checksum.'
            );
        }

        return hash('sha256', $json);
    }

    /**
     * Generate audit identifier.
     */
    private function generateAuditId(
        string $scope,
        string $subjectId
    ): string {
        $prefix = strtoupper(
            substr(
                preg_replace(
                    '/[^A-Za-z0-9]+/',
                    '',
                    $scope
                ) ?: 'AUD',
                0,
                3
            )
        );

        return 'AUD-'
            . $prefix
            . '-'
            . gmdate('Ymd-His')
            . '-'
            . $this->randomToken(5);
    }

    /**
     * Generate recommendation identifier.
     */
    private function generateRecommendationId(
        string $seed
    ): string {
        return 'REC-'
            . strtoupper(
                substr(
                    hash(
                        'sha256',
                        $seed
                    ),
                    0,
                    16
                )
            );
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