<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/RuleEngineService.php
|--------------------------------------------------------------------------
| IPMdb Rule Engine Service
|--------------------------------------------------------------------------
|
| Executes explicit, attributable, deterministic rules against entities,
| relationships, graph measurements, supplied facts, and runtime context.
|
| Responsibilities:
| - Register and validate configurable rules.
| - Evaluate nested ALL, ANY, and NOT condition groups.
| - Compare fields using deterministic operators.
| - Produce explainable decisions and proposed actions.
| - Apply narrowly defined safe actions to copied records.
| - Preserve rule, evidence, actor, and execution provenance.
| - Keep business policy outside core entities and repositories.
|
| Rules express policy.
| Inference proposes knowledge.
| Consistency detects conflict.
| The Doer decides.
|
| This service performs no database operations.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/RelationshipService.php';
require_once __DIR__ . '/ConsistencyService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class RuleEngineService extends Service
{
    use GraphUtilities;

    private RelationshipService $relationships;

    private ConsistencyService $consistency;

    /**
     * Registered canonical rules.
     *
     * @var array<string,array<string,mixed>>
     */
    private array $rules = [];

    /**
     * Supported rule scopes.
     *
     * @var array<int,string>
     */
    private array $scopes = [
        'entity',
        'relationship',
        'graph',
        'fact',
        'any',
    ];

    /**
     * Supported condition operators.
     *
     * @var array<int,string>
     */
    private array $operators = [
        'equals',
        'strict_equals',
        'not_equals',
        'strict_not_equals',

        'exists',
        'missing',
        'empty',
        'not_empty',

        'in',
        'not_in',

        'contains',
        'not_contains',
        'starts_with',
        'ends_with',

        'greater_than',
        'greater_than_or_equal',
        'less_than',
        'less_than_or_equal',

        'between',
        'not_between',

        'matches',
        'not_matches',

        'intersects',
        'not_intersects',
        'subset_of',
        'superset_of',

        'before',
        'before_or_equal',
        'after',
        'after_or_equal',

        'same_day',
        'same_month',
        'same_year',

        'type_is',
        'length_equals',
        'length_greater_than',
        'length_less_than',
    ];

    /**
     * Advisory actions never alter supplied records.
     *
     * @var array<int,string>
     */
    private array $advisoryActions = [
        'flag',
        'classify',
        'recommend',
        'require_review',
        'require_provenance',
        'require_attribution',
        'suggest_relationship',
        'suggest_status',
        'suggest_tag',
        'suggest_value',
        'notify',
        'enqueue',
        'allow',
        'block',
        'warn',
        'record_decision',
    ];

    /**
     * Safe actions may be applied to a copied record when explicitly requested.
     *
     * @var array<int,string>
     */
    private array $safeMutationActions = [
        'set',
        'set_if_empty',
        'unset',
        'add_tag',
        'remove_tag',
        'merge_metadata',
        'increment',
        'decrement',
        'append',
        'prepend',
    ];

    /**
     * Actions that may terminate further rule evaluation.
     *
     * @var array<int,string>
     */
    private array $terminalActions = [
        'block',
        'allow',
    ];

    /**
     * Severity ranking.
     *
     * @var array<string,int>
     */
    private array $severityOrder = [
        'critical' => 60,
        'error' => 50,
        'warning' => 40,
        'notice' => 30,
        'info' => 20,
        'debug' => 10,
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?RelationshipService $relationships = null,
        ?ConsistencyService $consistency = null
    ) {
        parent::__construct($config, $context);

        $this->relationships = $relationships
            ?? new RelationshipService();

        $this->consistency = $consistency
            ?? new ConsistencyService();

        $configuredRules = $config['rules']
            ?? [];

        if (is_array($configuredRules)) {
            $this->registerMany(
                $configuredRules,
                false
            );
        }
    }

    /**
     * Register one canonical rule.
     *
     * @param array<string,mixed> $rule
     * @return array<string,mixed>
     */
    public function register(
        array $rule,
        bool $replaceExisting = false
    ): array {
        $normalized = $this->normalizeRule(
            $rule
        );

        $this->validateRuleOrFail(
            $normalized
        );

        $ruleId = $normalized['rule_id'];

        if (
            isset($this->rules[$ruleId])
            && !$replaceExisting
        ) {
            throw new RuntimeException(
                sprintf(
                    'Rule "%s" is already registered.',
                    $ruleId
                )
            );
        }

        $this->rules[$ruleId] = $normalized;

        $this->sortRegisteredRules();

        return $normalized;
    }

    /**
     * Register multiple rules.
     *
     * @param array<int,array<string,mixed>> $rules
     * @return array<int,array<string,mixed>>
     */
    public function registerMany(
        array $rules,
        bool $replaceExisting = false
    ): array {
        $registered = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $registered[] = $this->register(
                $rule,
                $replaceExisting
            );
        }

        return $registered;
    }

    /**
     * Remove one registered rule.
     */
    public function unregister(
        string $ruleId
    ): bool {
        $ruleId = $this->normalizeIdentifier(
            $ruleId
        );

        if (
            $ruleId === ''
            || !isset($this->rules[$ruleId])
        ) {
            return false;
        }

        unset($this->rules[$ruleId]);

        return true;
    }

    /**
     * Enable one registered rule.
     */
    public function enable(
        string $ruleId
    ): array {
        return $this->setEnabled(
            $ruleId,
            true
        );
    }

    /**
     * Disable one registered rule.
     */
    public function disable(
        string $ruleId
    ): array {
        return $this->setEnabled(
            $ruleId,
            false
        );
    }

    /**
     * Return one rule.
     *
     * @return array<string,mixed>|null
     */
    public function rule(
        string $ruleId
    ): ?array {
        $ruleId = $this->normalizeIdentifier(
            $ruleId
        );

        return $this->rules[$ruleId]
            ?? null;
    }

    /**
     * Return all registered rules.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rules(
        bool $enabledOnly = false
    ): array {
        $rules = array_values(
            $this->rules
        );

        if (!$enabledOnly) {
            return $rules;
        }

        return array_values(
            array_filter(
                $rules,
                static fn (
                    array $rule
                ): bool =>
                    ($rule['enabled'] ?? false)
                    === true
            )
        );
    }

    /**
     * Clear all registered rules.
     */
    public function clearRules(): void
    {
        $this->rules = [];
    }

    /**
     * Execute registered rules against all supplied scopes.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     * @param array<int,array<string,mixed>> $facts
     *
     * @return array<string,mixed>
     */
    public function run(
        array $entities = [],
        array $relationships = [],
        array $facts = [],
        array $options = []
    ): array {
        $this->reset();

        $rules = isset($options['rules'])
            && is_array($options['rules'])
            ? $this->normalizeTemporaryRules(
                $options['rules']
            )
            : $this->rules(true);

        $minimumSeverity =
            $this->normalizeSeverity(
                (string)(
                    $options['minimum_severity']
                    ?? 'debug'
                )
            );

        $minimumSeverityScore =
            $this->severityOrder[
                $minimumSeverity
            ] ?? 0;

        $maximumDecisions = max(
            1,
            min(
                1000000,
                (int)(
                    $options['maximum_decisions']
                    ?? 10000
                )
            )
        );

        $stopOnBlock = (bool)(
            $options['stop_on_block']
            ?? false
        );

        $stopOnAllow = (bool)(
            $options['stop_on_allow']
            ?? false
        );

        $runtimeContext = is_array(
            $options['context']
            ?? null
        )
            ? $options['context']
            : [];

        $graphContext = $this->buildGraphContext(
            $entities,
            $relationships,
            $facts,
            $runtimeContext
        );

        $decisions = [];
        $terminated = false;
        $termination = null;

        foreach ($rules as $rule) {
            if (
                ($rule['enabled'] ?? false)
                !== true
            ) {
                continue;
            }

            $severity = $this->normalizeSeverity(
                (string)(
                    $rule['severity']
                    ?? 'info'
                )
            );

            if (
                (
                    $this->severityOrder[$severity]
                    ?? 0
                ) < $minimumSeverityScore
            ) {
                continue;
            }

            $scope = $rule['scope']
                ?? 'any';

            $records = $this->recordsForScope(
                $scope,
                $entities,
                $relationships,
                $facts,
                $graphContext
            );

            foreach ($records as $recordIndex => $record) {
                if (!is_array($record)) {
                    continue;
                }

                $decision = $this->evaluateRule(
                    $rule,
                    $record,
                    $scope,
                    [
                        'record_index' =>
                            $recordIndex,

                        'entities' =>
                            $entities,

                        'relationships' =>
                            $relationships,

                        'facts' => $facts,

                        'graph' =>
                            $graphContext,

                        'runtime' =>
                            $runtimeContext,
                    ]
                );

                if ($decision === null) {
                    continue;
                }

                $decisions[] = $decision;

                $terminalAction =
                    $this->terminalAction(
                        $decision['actions']
                        ?? []
                    );

                if (
                    $terminalAction === 'block'
                    && (
                        $stopOnBlock
                        || (
                            $rule['stop_on_match']
                            ?? false
                        )
                    )
                ) {
                    $terminated = true;

                    $termination = [
                        'reason' => 'block',
                        'rule_id' =>
                            $rule['rule_id'],
                        'decision_id' =>
                            $decision[
                                'decision_id'
                            ],
                    ];

                    break 2;
                }

                if (
                    $terminalAction === 'allow'
                    && (
                        $stopOnAllow
                        || (
                            $rule['stop_on_match']
                            ?? false
                        )
                    )
                ) {
                    $terminated = true;

                    $termination = [
                        'reason' => 'allow',
                        'rule_id' =>
                            $rule['rule_id'],
                        'decision_id' =>
                            $decision[
                                'decision_id'
                            ],
                    ];

                    break 2;
                }

                if (
                    count($decisions)
                    >= $maximumDecisions
                ) {
                    $terminated = true;

                    $termination = [
                        'reason' =>
                            'maximum_decisions',

                        'maximum_decisions' =>
                            $maximumDecisions,
                    ];

                    break 2;
                }

                if (
                    ($rule['stop_on_match'] ?? false)
                    === true
                ) {
                    break;
                }
            }
        }

        $summary = $this->summarizeDecisions(
            $decisions
        );

        $result = [
            'execution_id' =>
                $this->generateExecutionId(),

            'generated_at' => gmdate('c'),

            'rule_count' => count($rules),

            'enabled_rule_count' => count(
                array_filter(
                    $rules,
                    static fn (
                        array $rule
                    ): bool =>
                        ($rule['enabled'] ?? false)
                        === true
                )
            ),

            'entity_count' =>
                count($entities),

            'relationship_count' =>
                count($relationships),

            'fact_count' =>
                count($facts),

            'decision_count' =>
                count($decisions),

            'terminated' => $terminated,

            'termination' => $termination,

            'summary' => $summary,

            'decisions' => $decisions,
        ];

        $this->addMessage(
            'Rule engine execution completed.',
            [
                'rule_count' => count($rules),
                'decision_count' =>
                    count($decisions),
                'terminated' => $terminated,
            ]
        );

        return $result;
    }

    /**
     * Evaluate one rule against one record.
     *
     * Returns null when the rule does not match.
     *
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $record
     * @param array<string,mixed> $executionContext
     *
     * @return array<string,mixed>|null
     */
    public function evaluateRule(
        array $rule,
        array $record,
        string $scope,
        array $executionContext = []
    ): ?array {
        $scope = $this->normalizeScope(
            $scope
        );

        $ruleScope = $this->normalizeScope(
            (string)(
                $rule['scope']
                ?? 'any'
            )
        );

        if (
            $ruleScope !== 'any'
            && $ruleScope !== $scope
        ) {
            return null;
        }

        $context = $this->buildEvaluationContext(
            $record,
            $scope,
            $executionContext
        );

        $conditionResult =
            $this->evaluateConditionNode(
                $rule['conditions']
                    ?? [],
                $context
            );

        if (
            ($conditionResult['matched'] ?? false)
            !== true
        ) {
            return null;
        }

        $actions = $this->resolveActions(
            $rule['actions']
                ?? [],
            $context
        );

        return [
            'decision_id' =>
                $this->generateDecisionId(),

            'rule_id' =>
                $rule['rule_id'],

            'rule_name' =>
                $rule['name'],

            'rule_version' =>
                $rule['version'],

            'scope' => $scope,

            'severity' =>
                $rule['severity'],

            'priority' =>
                $rule['priority'],

            'matched' => true,

            'record_identity' =>
                $this->recordIdentity(
                    $record,
                    $scope
                ),

            'record_index' =>
                $executionContext[
                    'record_index'
                ] ?? null,

            'condition_result' =>
                $conditionResult,

            'actions' => $actions,

            'action_count' =>
                count($actions),

            'requires_human_review' =>
                $this->actionsRequireReview(
                    $actions
                ),

            'terminal_action' =>
                $this->terminalAction(
                    $actions
                ),

            'explanation' =>
                $this->explainDecision(
                    $rule,
                    $conditionResult,
                    $actions
                ),

            'rule_created_by' =>
                $rule['created_by'],

            'evaluated_by' =>
                static::class,

            'evaluated_at' =>
                gmdate('c'),

            'tags' =>
                $rule['tags'],

            'metadata' => [
                'rule_description' =>
                    $rule['description'],

                'rule_checksum' =>
                    $rule['checksum'],

                'record_checksum' =>
                    $this->recordChecksum(
                        $record
                    ),
            ],
        ];
    }

    /**
     * Test one condition tree.
     *
     * @param array<string,mixed> $conditions
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>
     */
    public function evaluateConditions(
        array $conditions,
        array $context
    ): array {
        return $this->evaluateConditionNode(
            $conditions,
            $context
        );
    }

    /**
     * Apply safe actions to a copied record.
     *
     * Advisory actions are preserved but do not modify the record.
     *
     * @param array<string,mixed> $record
     * @param array<int,array<string,mixed>> $actions
     *
     * @return array<string,mixed>
     */
    public function applySafeActions(
        array $record,
        array $actions,
        string $actorId,
        array $options = []
    ): array {
        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Applying rule actions requires actor attribution.'
            );
        }

        $original = $record;
        $updated = $record;
        $applied = [];
        $skipped = [];

        $allowUnset = (bool)(
            $options['allow_unset']
            ?? false
        );

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $type = $this->normalizeMachineKey(
                (string)(
                    $action['type']
                    ?? ''
                )
            );

            if (
                !in_array(
                    $type,
                    $this->safeMutationActions,
                    true
                )
            ) {
                $skipped[] = [
                    'action' => $action,
                    'reason' =>
                        'advisory_or_unsupported',
                ];

                continue;
            }

            $field = trim(
                (string)(
                    $action['field']
                    ?? ''
                )
            );

            if (
                $field === ''
                && !in_array(
                    $type,
                    ['merge_metadata'],
                    true
                )
            ) {
                $skipped[] = [
                    'action' => $action,
                    'reason' =>
                        'missing_field',
                ];

                continue;
            }

            $before = $field !== ''
                ? $this->getPath(
                    $updated,
                    $field,
                    null
                )
                : null;

            switch ($type) {
                case 'set':
                    $updated = $this->setPath(
                        $updated,
                        $field,
                        $action['value']
                            ?? null
                    );
                    break;

                case 'set_if_empty':
                    if (
                        !$this->valueIsEmpty(
                            $before
                        )
                    ) {
                        $skipped[] = [
                            'action' => $action,
                            'reason' =>
                                'field_not_empty',
                        ];

                        continue 2;
                    }

                    $updated = $this->setPath(
                        $updated,
                        $field,
                        $action['value']
                            ?? null
                    );
                    break;

                case 'unset':
                    if (!$allowUnset) {
                        $skipped[] = [
                            'action' => $action,
                            'reason' =>
                                'unset_disabled',
                        ];

                        continue 2;
                    }

                    $updated = $this->unsetPath(
                        $updated,
                        $field
                    );
                    break;

                case 'add_tag':
                    $tags = $this->normalizeStringList(
                        $updated[$field]
                            ?? []
                    );

                    $newTags =
                        $this->normalizeStringList(
                            $action['value']
                                ?? []
                        );

                    $updated[$field] =
                        array_values(
                            array_unique(
                                array_merge(
                                    $tags,
                                    $newTags
                                )
                            )
                        );
                    break;

                case 'remove_tag':
                    $tags = $this->normalizeStringList(
                        $updated[$field]
                            ?? []
                    );

                    $remove =
                        $this->normalizeStringList(
                            $action['value']
                                ?? []
                        );

                    $updated[$field] =
                        array_values(
                            array_diff(
                                $tags,
                                $remove
                            )
                        );
                    break;

                case 'merge_metadata':
                    $metadata = is_array(
                        $updated['metadata']
                            ?? null
                    )
                        ? $updated['metadata']
                        : [];

                    $merge = is_array(
                        $action['value']
                            ?? null
                    )
                        ? $action['value']
                        : [];

                    $updated['metadata'] =
                        array_replace_recursive(
                            $metadata,
                            $merge
                        );
                    break;

                case 'increment':
                    $amount = is_numeric(
                        $action['value']
                            ?? null
                    )
                        ? (float)$action['value']
                        : 1.0;

                    $current = is_numeric($before)
                        ? (float)$before
                        : 0.0;

                    $updated = $this->setPath(
                        $updated,
                        $field,
                        $current + $amount
                    );
                    break;

                case 'decrement':
                    $amount = is_numeric(
                        $action['value']
                            ?? null
                    )
                        ? (float)$action['value']
                        : 1.0;

                    $current = is_numeric($before)
                        ? (float)$before
                        : 0.0;

                    $updated = $this->setPath(
                        $updated,
                        $field,
                        $current - $amount
                    );
                    break;

                case 'append':
                    $current = $before;

                    if (is_array($current)) {
                        $append = is_array(
                            $action['value']
                                ?? null
                        )
                            ? $action['value']
                            : [
                                $action['value']
                                ?? null,
                            ];

                        $updated = $this->setPath(
                            $updated,
                            $field,
                            array_merge(
                                $current,
                                $append
                            )
                        );
                    } else {
                        $updated = $this->setPath(
                            $updated,
                            $field,
                            (string)$current
                            . (string)(
                                $action['value']
                                ?? ''
                            )
                        );
                    }
                    break;

                case 'prepend':
                    $current = $before;

                    if (is_array($current)) {
                        $prepend = is_array(
                            $action['value']
                                ?? null
                        )
                            ? $action['value']
                            : [
                                $action['value']
                                ?? null,
                            ];

                        $updated = $this->setPath(
                            $updated,
                            $field,
                            array_merge(
                                $prepend,
                                $current
                            )
                        );
                    } else {
                        $updated = $this->setPath(
                            $updated,
                            $field,
                            (string)(
                                $action['value']
                                ?? ''
                            )
                            . (string)$current
                        );
                    }
                    break;
            }

            $after = $field !== ''
                ? $this->getPath(
                    $updated,
                    $field,
                    null
                )
                : (
                    $updated['metadata']
                    ?? null
                );

            $applied[] = [
                'type' => $type,
                'field' => $field !== ''
                    ? $field
                    : 'metadata',
                'before' => $before,
                'after' => $after,
                'applied_by' => $actorId,
                'applied_at' => gmdate('c'),
            ];
        }

        $updated['metadata'] = is_array(
            $updated['metadata']
                ?? null
        )
            ? $updated['metadata']
            : [];

        $updated['metadata']
            ['last_rule_action_actor'] =
            $actorId;

        $updated['metadata']
            ['last_rule_action_at'] =
            gmdate('c');

        return [
            'changed' =>
                $this->normalizeForHash(
                    $original
                )
                !==
                $this->normalizeForHash(
                    $updated
                ),

            'actor_id' => $actorId,

            'original' => $original,

            'updated' => $updated,

            'applied_count' =>
                count($applied),

            'skipped_count' =>
                count($skipped),

            'applied' => $applied,

            'skipped' => $skipped,
        ];
    }

    /**
     * Convert a decision's relationship suggestion into a relationship record.
     *
     * @param array<string,mixed> $decision
     *
     * @return array<string,mixed>|null
     */
    public function acceptSuggestedRelationship(
        array $decision,
        string $actorId,
        array $overrides = []
    ): ?array {
        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Relationship acceptance requires actor attribution.'
            );
        }

        foreach (
            $decision['actions']
                ?? []
            as $action
        ) {
            if (
                !is_array($action)
                || (
                    $action['type']
                    ?? ''
                ) !== 'suggest_relationship'
            ) {
                continue;
            }

            $value = is_array(
                $action['value']
                    ?? null
            )
                ? $action['value']
                : [];

            $metadata = is_array(
                $value['metadata']
                    ?? null
            )
                ? $value['metadata']
                : [];

            $metadata['rule_decision_id'] =
                $decision['decision_id']
                ?? null;

            $metadata['rule_id'] =
                $decision['rule_id']
                ?? null;

            $metadata['accepted_by'] =
                $actorId;

            $metadata['accepted_at'] =
                gmdate('c');

            return $this->relationships->create(
                array_merge(
                    [
                        'source_id' =>
                            $value['source_id']
                            ?? '',

                        'source_type' =>
                            $value['source_type']
                            ?? 'entity',

                        'target_id' =>
                            $value['target_id']
                            ?? '',

                        'target_type' =>
                            $value['target_type']
                            ?? 'entity',

                        'relationship_type' =>
                            $value[
                                'relationship_type'
                            ] ?? 'related_to',

                        'label' =>
                            $value['label']
                            ?? '',

                        'description' =>
                            $value['description']
                            ?? (
                                $decision[
                                    'explanation'
                                ] ?? ''
                            ),

                        'confidence' =>
                            $value['confidence']
                            ?? 100,

                        'weight' =>
                            $value['weight']
                            ?? 1,

                        'strength' =>
                            $value['strength']
                            ?? 1,

                        'status' => 'proposed',

                        'created_by' =>
                            $actorId,

                        'suggested_by_ai' =>
                            false,

                        'accepted_by_human' =>
                            true,

                        'metadata' =>
                            $metadata,

                        'tags' =>
                            $value['tags']
                            ?? [
                                'rule_generated',
                                'human_accepted',
                            ],
                    ],
                    $overrides
                )
            );
        }

        return null;
    }

    /**
     * Validate one rule.
     *
     * @param array<string,mixed> $rule
     */
    public function validateRule(
        array $rule
    ): bool {
        $this->reset();

        foreach (
            [
                'rule_id',
                'name',
                'scope',
                'conditions',
                'actions',
                'priority',
                'severity',
                'enabled',
                'version',
                'created_by',
                'checksum',
            ]
            as $field
        ) {
            if (
                $this->valueIsEmpty(
                    $rule[$field]
                        ?? null
                )
            ) {
                $this->addError(
                    sprintf(
                        'Rule field "%s" is required.',
                        $field
                    )
                );
            }
        }

        $scope = $this->normalizeScope(
            (string)(
                $rule['scope']
                ?? ''
            )
        );

        if (
            !in_array(
                $scope,
                $this->scopes,
                true
            )
        ) {
            $this->addError(
                'Rule scope is unsupported.'
            );
        }

        if (
            !is_array(
                $rule['conditions']
                ?? null
            )
        ) {
            $this->addError(
                'Rule conditions must be an array.'
            );
        } elseif (
            !$this->conditionTreeIsValid(
                $rule['conditions']
            )
        ) {
            $this->addError(
                'Rule condition tree is invalid.'
            );
        }

        if (
            !is_array(
                $rule['actions']
                ?? null
            )
            || $rule['actions'] === []
        ) {
            $this->addError(
                'Rule requires at least one action.'
            );
        } else {
            foreach (
                $rule['actions']
                as $index => $action
            ) {
                if (!is_array($action)) {
                    $this->addError(
                        sprintf(
                            'Rule action %d must be an array.',
                            $index
                        )
                    );

                    continue;
                }

                $type = $this->normalizeMachineKey(
                    (string)(
                        $action['type']
                        ?? ''
                    )
                );

                if (
                    !$this->actionTypeSupported(
                        $type
                    )
                ) {
                    $this->addError(
                        sprintf(
                            'Rule action type "%s" is unsupported.',
                            $type
                        )
                    );
                }
            }
        }

        if (
            !is_int(
                $rule['priority']
                ?? null
            )
        ) {
            $this->addError(
                'Rule priority must be an integer.'
            );
        }

        if (
            !is_bool(
                $rule['enabled']
                ?? null
            )
        ) {
            $this->addError(
                'Rule enabled state must be boolean.'
            );
        }

        $severity = $this->normalizeSeverity(
            (string)(
                $rule['severity']
                ?? ''
            )
        );

        if (
            !array_key_exists(
                $severity,
                $this->severityOrder
            )
        ) {
            $this->addError(
                'Rule severity is unsupported.'
            );
        }

        $storedChecksum = trim(
            (string)(
                $rule['checksum']
                ?? ''
            )
        );

        if (
            $storedChecksum !== ''
            && !$this->ruleChecksumMatches(
                $rule
            )
        ) {
            $this->addError(
                'Rule checksum does not match its content.'
            );
        }

        return $this->succeeded();
    }

    /**
     * Validate one rule or throw.
     *
     * @param array<string,mixed> $rule
     * @return array<string,mixed>
     */
    public function validateRuleOrFail(
        array $rule
    ): array {
        if (!$this->validateRule($rule)) {
            $messages = array_values(
                array_filter(
                    array_map(
                        static fn (
                            array $error
                        ): string =>
                            trim(
                                (string)(
                                    $error['message']
                                    ?? ''
                                )
                            ),
                        $this->errors()
                    )
                )
            );

            throw new RuntimeException(
                implode(' ', $messages)
            );
        }

        return $rule;
    }

    /**
     * Summarize decisions.
     *
     * @param array<int,array<string,mixed>> $decisions
     * @return array<string,mixed>
     */
    public function summarizeDecisions(
        array $decisions
    ): array {
        $rules = [];
        $scopes = [];
        $severities = [];
        $actions = [];

        $reviewRequired = 0;
        $blocks = 0;
        $allows = 0;

        foreach ($decisions as $decision) {
            $ruleId = trim(
                (string)(
                    $decision['rule_id']
                    ?? 'unknown'
                )
            );

            $scope = trim(
                (string)(
                    $decision['scope']
                    ?? 'unknown'
                )
            );

            $severity =
                $this->normalizeSeverity(
                    (string)(
                        $decision['severity']
                        ?? 'info'
                    )
                );

            $rules[$ruleId] =
                ($rules[$ruleId] ?? 0) + 1;

            $scopes[$scope] =
                ($scopes[$scope] ?? 0) + 1;

            $severities[$severity] =
                ($severities[$severity] ?? 0)
                + 1;

            if (
                (
                    $decision[
                        'requires_human_review'
                    ] ?? false
                ) === true
            ) {
                $reviewRequired++;
            }

            foreach (
                $decision['actions']
                    ?? []
                as $action
            ) {
                if (!is_array($action)) {
                    continue;
                }

                $type = trim(
                    (string)(
                        $action['type']
                        ?? 'unknown'
                    )
                );

                $actions[$type] =
                    ($actions[$type] ?? 0)
                    + 1;

                if ($type === 'block') {
                    $blocks++;
                }

                if ($type === 'allow') {
                    $allows++;
                }
            }
        }

        arsort($rules);
        arsort($scopes);
        arsort($severities);
        arsort($actions);

        return [
            'count' => count($decisions),

            'human_review_required_count' =>
                $reviewRequired,

            'block_count' => $blocks,

            'allow_count' => $allows,

            'rules' => $rules,

            'scopes' => $scopes,

            'severities' => $severities,

            'actions' => $actions,
        ];
    }

    /**
     * Return rule engine diagnostics.
     *
     * @return array<string,mixed>
     */
    public function diagnostics(): array
    {
        return array_merge(
            parent::diagnostics(),
            [
                'registered_rule_count' =>
                    count($this->rules),

                'enabled_rule_count' =>
                    count($this->rules(true)),

                'scopes' =>
                    $this->scopes,

                'operators' =>
                    $this->operators,

                'advisory_actions' =>
                    $this->advisoryActions,

                'safe_mutation_actions' =>
                    $this->safeMutationActions,

                'terminal_actions' =>
                    $this->terminalActions,

                'automatic_persistence' =>
                    false,

                'mutation_requires_explicit_call' =>
                    true,

                'human_attribution_required' =>
                    true,

                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Normalize one rule.
     *
     * @param array<string,mixed> $rule
     * @return array<string,mixed>
     */
    private function normalizeRule(
        array $rule
    ): array {
        $ruleId = $this->normalizeIdentifier(
            (string)(
                $rule['rule_id']
                ?? ''
            )
        );

        if ($ruleId === '') {
            $ruleId = $this->generateRuleId();
        }

        $actions = [];

        foreach (
            $rule['actions']
                ?? []
            as $action
        ) {
            if (!is_array($action)) {
                continue;
            }

            $type = $this->normalizeMachineKey(
                (string)(
                    $action['type']
                    ?? ''
                )
            );

            $actions[] = array_merge(
                $action,
                [
                    'type' => $type,
                ]
            );
        }

        $normalized = [
            'rule_id' => $ruleId,

            'name' => trim(
                (string)(
                    $rule['name']
                    ?? $ruleId
                )
            ),

            'description' => trim(
                (string)(
                    $rule['description']
                    ?? ''
                )
            ),

            'scope' => $this->normalizeScope(
                (string)(
                    $rule['scope']
                    ?? 'any'
                )
            ),

            'conditions' => is_array(
                $rule['conditions']
                    ?? null
            )
                ? $rule['conditions']
                : [],

            'actions' => $actions,

            'priority' => (int)(
                $rule['priority']
                ?? 100
            ),

            'severity' =>
                $this->normalizeSeverity(
                    (string)(
                        $rule['severity']
                        ?? 'info'
                    )
                ),

            'enabled' => array_key_exists(
                'enabled',
                $rule
            )
                ? (bool)$rule['enabled']
                : true,

            'stop_on_match' => (bool)(
                $rule['stop_on_match']
                ?? false
            ),

            'version' => trim(
                (string)(
                    $rule['version']
                    ?? '1.0'
                )
            ),

            'created_by' => trim(
                (string)(
                    $rule['created_by']
                    ?? 'system'
                )
            ),

            'created_at' => trim(
                (string)(
                    $rule['created_at']
                    ?? gmdate('c')
                )
            ),

            'updated_at' => gmdate('c'),

            'tags' =>
                $this->normalizeStringList(
                    $rule['tags']
                        ?? []
                ),

            'metadata' => is_array(
                $rule['metadata']
                    ?? null
            )
                ? $rule['metadata']
                : [],

            'checksum' => '',
        ];

        $normalized['checksum'] =
            $this->ruleChecksum(
                $normalized
            );

        return $normalized;
    }

    /**
     * Normalize temporary execution rules.
     *
     * @param array<int,array<string,mixed>> $rules
     * @return array<int,array<string,mixed>>
     */
    private function normalizeTemporaryRules(
        array $rules
    ): array {
        $normalized = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $canonical = $this->normalizeRule(
                $rule
            );

            $this->validateRuleOrFail(
                $canonical
            );

            $normalized[] = $canonical;
        }

        usort(
            $normalized,
            static function (
                array $left,
                array $right
            ): int {
                $priorityComparison =
                    (int)(
                        $left['priority']
                        ?? 100
                    )
                    <=>
                    (int)(
                        $right['priority']
                        ?? 100
                    );

                if ($priorityComparison !== 0) {
                    return $priorityComparison;
                }

                return strcmp(
                    (string)(
                        $left['rule_id']
                        ?? ''
                    ),
                    (string)(
                        $right['rule_id']
                        ?? ''
                    )
                );
            }
        );

        return $normalized;
    }

    /**
     * Evaluate one condition node.
     *
     * @param array<string,mixed> $node
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>
     */
    private function evaluateConditionNode(
        array $node,
        array $context
    ): array {
        if (isset($node['all'])) {
            $children = is_array($node['all'])
                ? $node['all']
                : [];

            $results = [];
            $matched = true;

            foreach ($children as $child) {
                if (!is_array($child)) {
                    $matched = false;
                    continue;
                }

                $result =
                    $this->evaluateConditionNode(
                        $child,
                        $context
                    );

                $results[] = $result;

                if (
                    ($result['matched'] ?? false)
                    !== true
                ) {
                    $matched = false;
                }
            }

            return [
                'type' => 'all',
                'matched' =>
                    $children !== []
                    && $matched,
                'children' => $results,
            ];
        }

        if (isset($node['any'])) {
            $children = is_array($node['any'])
                ? $node['any']
                : [];

            $results = [];
            $matched = false;

            foreach ($children as $child) {
                if (!is_array($child)) {
                    continue;
                }

                $result =
                    $this->evaluateConditionNode(
                        $child,
                        $context
                    );

                $results[] = $result;

                if (
                    ($result['matched'] ?? false)
                    === true
                ) {
                    $matched = true;
                }
            }

            return [
                'type' => 'any',
                'matched' =>
                    $children !== []
                    && $matched,
                'children' => $results,
            ];
        }

        if (isset($node['not'])) {
            $child = is_array($node['not'])
                ? $node['not']
                : [];

            $result =
                $this->evaluateConditionNode(
                    $child,
                    $context
                );

            return [
                'type' => 'not',
                'matched' =>
                    !(
                        $result['matched']
                        ?? false
                    ),
                'child' => $result,
            ];
        }

        $field = trim(
            (string)(
                $node['field']
                ?? ''
            )
        );

        $operator = $this->normalizeMachineKey(
            (string)(
                $node['operator']
                ?? 'equals'
            )
        );

        $expected = $node['value']
            ?? null;

        $actual = $this->resolveContextValue(
            $context,
            $field
        );

        $matched = $this->compare(
            $actual,
            $operator,
            $expected
        );

        return [
            'type' => 'condition',

            'field' => $field,

            'operator' => $operator,

            'expected' => $expected,

            'actual' => $actual,

            'matched' => $matched,
        ];
    }

    /**
     * Compare one value using one operator.
     */
    private function compare(
        mixed $actual,
        string $operator,
        mixed $expected
    ): bool {
        switch ($operator) {
            case 'equals':
                return $this->looseComparable(
                    $actual
                ) ===
                    $this->looseComparable(
                        $expected
                    );

            case 'strict_equals':
                return $actual === $expected;

            case 'not_equals':
                return $this->looseComparable(
                    $actual
                ) !==
                    $this->looseComparable(
                        $expected
                    );

            case 'strict_not_equals':
                return $actual !== $expected;

            case 'exists':
                return $actual !== null;

            case 'missing':
                return $actual === null;

            case 'empty':
                return $this->valueIsEmpty(
                    $actual
                );

            case 'not_empty':
                return !$this->valueIsEmpty(
                    $actual
                );

            case 'in':
                return in_array(
                    $this->looseComparable($actual),
                    array_map(
                        fn (mixed $value): mixed =>
                            $this->looseComparable(
                                $value
                            ),
                        $this->toList($expected)
                    ),
                    true
                );

            case 'not_in':
                return !in_array(
                    $this->looseComparable($actual),
                    array_map(
                        fn (mixed $value): mixed =>
                            $this->looseComparable(
                                $value
                            ),
                        $this->toList($expected)
                    ),
                    true
                );

            case 'contains':
                return $this->contains(
                    $actual,
                    $expected
                );

            case 'not_contains':
                return !$this->contains(
                    $actual,
                    $expected
                );

            case 'starts_with':
                return str_starts_with(
                    $this->lower(
                        (string)$actual
                    ),
                    $this->lower(
                        (string)$expected
                    )
                );

            case 'ends_with':
                return str_ends_with(
                    $this->lower(
                        (string)$actual
                    ),
                    $this->lower(
                        (string)$expected
                    )
                );

            case 'greater_than':
                return $this->numeric($actual)
                    > $this->numeric($expected);

            case 'greater_than_or_equal':
                return $this->numeric($actual)
                    >= $this->numeric($expected);

            case 'less_than':
                return $this->numeric($actual)
                    < $this->numeric($expected);

            case 'less_than_or_equal':
                return $this->numeric($actual)
                    <= $this->numeric($expected);

            case 'between':
                return $this->between(
                    $actual,
                    $expected
                );

            case 'not_between':
                return !$this->between(
                    $actual,
                    $expected
                );

            case 'matches':
                return $this->regexMatches(
                    $actual,
                    $expected
                );

            case 'not_matches':
                return !$this->regexMatches(
                    $actual,
                    $expected
                );

            case 'intersects':
                return array_intersect(
                    $this->normalizedList($actual),
                    $this->normalizedList($expected)
                ) !== [];

            case 'not_intersects':
                return array_intersect(
                    $this->normalizedList($actual),
                    $this->normalizedList($expected)
                ) === [];

            case 'subset_of':
                return array_diff(
                    $this->normalizedList($actual),
                    $this->normalizedList($expected)
                ) === [];

            case 'superset_of':
                return array_diff(
                    $this->normalizedList($expected),
                    $this->normalizedList($actual)
                ) === [];

            case 'before':
                return $this->dateCompare(
                    $actual,
                    $expected
                ) < 0;

            case 'before_or_equal':
                return $this->dateCompare(
                    $actual,
                    $expected
                ) <= 0;

            case 'after':
                return $this->dateCompare(
                    $actual,
                    $expected
                ) > 0;

            case 'after_or_equal':
                return $this->dateCompare(
                    $actual,
                    $expected
                ) >= 0;

            case 'same_day':
                return $this->dateFormat(
                    $actual,
                    'Y-m-d'
                ) ===
                    $this->dateFormat(
                        $expected,
                        'Y-m-d'
                    );

            case 'same_month':
                return $this->dateFormat(
                    $actual,
                    'Y-m'
                ) ===
                    $this->dateFormat(
                        $expected,
                        'Y-m'
                    );

            case 'same_year':
                return $this->dateFormat(
                    $actual,
                    'Y'
                ) ===
                    $this->dateFormat(
                        $expected,
                        'Y'
                    );

            case 'type_is':
                return get_debug_type($actual)
                    === (string)$expected;

            case 'length_equals':
                return $this->valueLength($actual)
                    === (int)$expected;

            case 'length_greater_than':
                return $this->valueLength($actual)
                    > (int)$expected;

            case 'length_less_than':
                return $this->valueLength($actual)
                    < (int)$expected;
        }

        return false;
    }

    /**
     * Resolve and interpolate actions.
     *
     * @param array<int,array<string,mixed>> $actions
     * @param array<string,mixed> $context
     *
     * @return array<int,array<string,mixed>>
     */
    private function resolveActions(
        array $actions,
        array $context
    ): array {
        $resolved = [];

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $type = $this->normalizeMachineKey(
                (string)(
                    $action['type']
                    ?? ''
                )
            );

            if (
                !$this->actionTypeSupported(
                    $type
                )
            ) {
                continue;
            }

            $resolvedAction = [
                'action_id' =>
                    $this->generateActionId(),

                'type' => $type,

                'field' => isset($action['field'])
                    ? $this->interpolate(
                        (string)$action['field'],
                        $context
                    )
                    : null,

                'value' =>
                    $this->interpolateValue(
                        $action['value']
                            ?? null,
                        $context
                    ),

                'message' => isset(
                    $action['message']
                )
                    ? $this->interpolate(
                        (string)$action['message'],
                        $context
                    )
                    : '',

                'severity' =>
                    $this->normalizeSeverity(
                        (string)(
                            $action['severity']
                            ?? 'info'
                        )
                    ),

                'requires_human_review' =>
                    array_key_exists(
                        'requires_human_review',
                        $action
                    )
                        ? (bool)$action[
                            'requires_human_review'
                        ]
                        : !in_array(
                            $type,
                            $this->safeMutationActions,
                            true
                        ),

                'metadata' => is_array(
                    $action['metadata']
                        ?? null
                )
                    ? $this->interpolateValue(
                        $action['metadata'],
                        $context
                    )
                    : [],
            ];

            $resolved[] = $resolvedAction;
        }

        return $resolved;
    }

    /**
     * Build graph-level context.
     *
     * @param array<int,array<string,mixed>> $entities
     * @param array<int,array<string,mixed>> $relationships
     * @param array<int,array<string,mixed>> $facts
     * @param array<string,mixed> $runtimeContext
     *
     * @return array<string,mixed>
     */
    private function buildGraphContext(
        array $entities,
        array $relationships,
        array $facts,
        array $runtimeContext
    ): array {
        $entityTypes = [];
        $relationshipTypes = [];
        $statuses = [];
        $missingProvenance = 0;
        $missingAttribution = 0;

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $entityType =
                $this->normalizeMachineKey(
                    (string)(
                        $entity['entity_type']
                        ?? $entity['type']
                        ?? 'entity'
                    )
                );

            $entityTypes[$entityType] =
                ($entityTypes[$entityType] ?? 0)
                + 1;

            $status = $this->normalizeMachineKey(
                (string)(
                    $entity['status']
                    ?? ''
                )
            );

            if ($status !== '') {
                $statuses[$status] =
                    ($statuses[$status] ?? 0)
                    + 1;
            }

            if (
                trim(
                    (string)(
                        $entity['provenance_id']
                        ?? ''
                    )
                ) === ''
            ) {
                $missingProvenance++;
            }

            if (
                trim(
                    (string)(
                        $entity['created_by']
                        ?? $entity['originator_id']
                        ?? ''
                    )
                ) === ''
            ) {
                $missingAttribution++;
            }
        }

        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }

            $type = $this->normalizeMachineKey(
                (string)(
                    $relationship[
                        'relationship_type'
                    ]
                    ?? 'related_to'
                )
            );

            $relationshipTypes[$type] =
                (
                    $relationshipTypes[$type]
                    ?? 0
                ) + 1;

            $status = $this->normalizeMachineKey(
                (string)(
                    $relationship['status']
                    ?? ''
                )
            );

            if ($status !== '') {
                $statuses[$status] =
                    ($statuses[$status] ?? 0)
                    + 1;
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
                        $relationship[
                            'created_by'
                        ]
                        ?? ''
                    )
                ) === ''
            ) {
                $missingAttribution++;
            }
        }

        arsort($entityTypes);
        arsort($relationshipTypes);
        arsort($statuses);

        return [
            'entity_count' =>
                count($entities),

            'relationship_count' =>
                count($relationships),

            'fact_count' =>
                count($facts),

            'entity_types' =>
                $entityTypes,

            'relationship_types' =>
                $relationshipTypes,

            'statuses' => $statuses,

            'missing_provenance_count' =>
                $missingProvenance,

            'missing_attribution_count' =>
                $missingAttribution,

            'generated_at' =>
                gmdate('c'),

            'runtime' =>
                $runtimeContext,
        ];
    }

    /**
     * Build rule evaluation context.
     *
     * @param array<string,mixed> $record
     * @param array<string,mixed> $executionContext
     *
     * @return array<string,mixed>
     */
    private function buildEvaluationContext(
        array $record,
        string $scope,
        array $executionContext
    ): array {
        return [
            'record' => $record,

            'entity' => $scope === 'entity'
                ? $record
                : [],

            'relationship' =>
                $scope === 'relationship'
                    ? $record
                    : [],

            'graph' => is_array(
                $executionContext['graph']
                    ?? null
            )
                ? $executionContext['graph']
                : [],

            'fact' => $scope === 'fact'
                ? $record
                : [],

            'facts' => is_array(
                $executionContext['facts']
                    ?? null
            )
                ? $executionContext['facts']
                : [],

            'entities' => is_array(
                $executionContext['entities']
                    ?? null
            )
                ? $executionContext['entities']
                : [],

            'relationships' => is_array(
                $executionContext[
                    'relationships'
                ] ?? null
            )
                ? $executionContext[
                    'relationships'
                ]
                : [],

            'runtime' => is_array(
                $executionContext['runtime']
                    ?? null
            )
                ? $executionContext['runtime']
                : [],

            'scope' => $scope,

            'record_index' =>
                $executionContext[
                    'record_index'
                ] ?? null,

            'now' => gmdate('c'),
        ];
    }

    /**
     * Return records for one scope.
     *
     * @return array<int,array<string,mixed>>
     */
    private function recordsForScope(
        string $scope,
        array $entities,
        array $relationships,
        array $facts,
        array $graphContext
    ): array {
        return match ($scope) {
            'entity' => $entities,

            'relationship' =>
                $relationships,

            'fact' => $facts,

            'graph' => [
                $graphContext,
            ],

            'any' => array_merge(
                $entities,
                $relationships,
                $facts,
                [
                    $graphContext,
                ]
            ),

            default => [],
        };
    }

    /**
     * Resolve one context path.
     */
    private function resolveContextValue(
        array $context,
        string $path
    ): mixed {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        if (
            !str_contains(
                $path,
                '.'
            )
            && array_key_exists(
                $path,
                $context['record']
                    ?? []
            )
        ) {
            return $context['record'][$path];
        }

        return $this->getPath(
            $context,
            $path,
            null
        );
    }

    /**
     * Read a dot-separated path.
     */
    private function getPath(
        array $source,
        string $path,
        mixed $default = null
    ): mixed {
        $segments = array_values(
            array_filter(
                explode('.', $path),
                static fn (
                    string $segment
                ): bool =>
                    $segment !== ''
            )
        );

        if ($segments === []) {
            return $default;
        }

        $value = $source;

        foreach ($segments as $segment) {
            if (
                is_array($value)
                && array_key_exists(
                    $segment,
                    $value
                )
            ) {
                $value = $value[$segment];
                continue;
            }

            return $default;
        }

        return $value;
    }

    /**
     * Set a dot-separated path.
     *
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function setPath(
        array $source,
        string $path,
        mixed $value
    ): array {
        $segments = array_values(
            array_filter(
                explode('.', $path),
                static fn (
                    string $segment
                ): bool =>
                    $segment !== ''
            )
        );

        if ($segments === []) {
            return $source;
        }

        $cursor =& $source;

        foreach ($segments as $index => $segment) {
            $last =
                $index === array_key_last(
                    $segments
                );

            if ($last) {
                $cursor[$segment] = $value;
                break;
            }

            if (
                !isset($cursor[$segment])
                || !is_array(
                    $cursor[$segment]
                )
            ) {
                $cursor[$segment] = [];
            }

            $cursor =& $cursor[$segment];
        }

        unset($cursor);

        return $source;
    }

    /**
     * Unset a dot-separated path.
     *
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function unsetPath(
        array $source,
        string $path
    ): array {
        $segments = array_values(
            array_filter(
                explode('.', $path),
                static fn (
                    string $segment
                ): bool =>
                    $segment !== ''
            )
        );

        if ($segments === []) {
            return $source;
        }

        $lastSegment = array_pop(
            $segments
        );

        $cursor =& $source;

        foreach ($segments as $segment) {
            if (
                !isset($cursor[$segment])
                || !is_array(
                    $cursor[$segment]
                )
            ) {
                return $source;
            }

            $cursor =& $cursor[$segment];
        }

        unset($cursor[$lastSegment]);
        unset($cursor);

        return $source;
    }

    /**
     * Interpolate {{path.to.value}} placeholders.
     */
    private function interpolate(
        string $value,
        array $context
    ): string {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
            function (
                array $matches
            ) use ($context): string {
                $resolved =
                    $this->resolveContextValue(
                        $context,
                        (string)(
                            $matches[1]
                            ?? ''
                        )
                    );

                if (is_scalar($resolved)) {
                    return (string)$resolved;
                }

                $json = json_encode(
                    $resolved,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                );

                return $json !== false
                    ? $json
                    : '';
            },
            $value
        ) ?? $value;
    }

    /**
     * Interpolate nested action values.
     */
    private function interpolateValue(
        mixed $value,
        array $context
    ): mixed {
        if (is_string($value)) {
            return $this->interpolate(
                $value,
                $context
            );
        }

        if (!is_array($value)) {
            return $value;
        }

        $resolved = [];

        foreach ($value as $key => $item) {
            $resolvedKey = is_string($key)
                ? $this->interpolate(
                    $key,
                    $context
                )
                : $key;

            $resolved[$resolvedKey] =
                $this->interpolateValue(
                    $item,
                    $context
                );
        }

        return $resolved;
    }

    /**
     * Determine whether condition tree structure is valid.
     */
    private function conditionTreeIsValid(
        array $node
    ): bool {
        if (isset($node['all'])) {
            if (
                !is_array($node['all'])
                || $node['all'] === []
            ) {
                return false;
            }

            foreach ($node['all'] as $child) {
                if (
                    !is_array($child)
                    || !$this->conditionTreeIsValid(
                        $child
                    )
                ) {
                    return false;
                }
            }

            return true;
        }

        if (isset($node['any'])) {
            if (
                !is_array($node['any'])
                || $node['any'] === []
            ) {
                return false;
            }

            foreach ($node['any'] as $child) {
                if (
                    !is_array($child)
                    || !$this->conditionTreeIsValid(
                        $child
                    )
                ) {
                    return false;
                }
            }

            return true;
        }

        if (isset($node['not'])) {
            return is_array($node['not'])
                && $this->conditionTreeIsValid(
                    $node['not']
                );
        }

        $field = trim(
            (string)(
                $node['field']
                ?? ''
            )
        );

        $operator = $this->normalizeMachineKey(
            (string)(
                $node['operator']
                ?? ''
            )
        );

        return $field !== ''
            && in_array(
                $operator,
                $this->operators,
                true
            );
    }

    /**
     * Determine whether an action type is supported.
     */
    private function actionTypeSupported(
        string $type
    ): bool {
        return in_array(
            $type,
            array_merge(
                $this->advisoryActions,
                $this->safeMutationActions
            ),
            true
        );
    }

    /**
     * Determine whether actions require human review.
     *
     * @param array<int,array<string,mixed>> $actions
     */
    private function actionsRequireReview(
        array $actions
    ): bool {
        foreach ($actions as $action) {
            if (
                is_array($action)
                && (
                    $action[
                        'requires_human_review'
                    ] ?? false
                ) === true
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return first terminal action.
     *
     * @param array<int,array<string,mixed>> $actions
     */
    private function terminalAction(
        array $actions
    ): ?string {
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $type = trim(
                (string)(
                    $action['type']
                    ?? ''
                )
            );

            if (
                in_array(
                    $type,
                    $this->terminalActions,
                    true
                )
            ) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Explain one decision.
     *
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $conditionResult
     * @param array<int,array<string,mixed>> $actions
     */
    private function explainDecision(
        array $rule,
        array $conditionResult,
        array $actions
    ): string {
        $actionTypes = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn (
                            array $action
                        ): string =>
                            trim(
                                (string)(
                                    $action['type']
                                    ?? ''
                                )
                            ),
                        $actions
                    )
                )
            )
        );

        return sprintf(
            'Rule "%s" matched its condition tree and proposed %d action%s: %s.',
            (string)(
                $rule['name']
                ?? $rule['rule_id']
                ?? 'Unnamed rule'
            ),
            count($actions),
            count($actions) === 1
                ? ''
                : 's',
            $actionTypes !== []
                ? implode(', ', $actionTypes)
                : 'none'
        );
    }

    /**
     * Return one record identity.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function recordIdentity(
        array $record,
        string $scope
    ): array {
        $fields = match ($scope) {
            'entity' => [
                'entity_id',
                'asset_id',
                'translation_id',
                'document_id',
                'program_id',
                'decision_id',
                'id',
            ],

            'relationship' => [
                'relationship_id',
                'id',
            ],

            'fact' => [
                'fact_id',
                'claim_id',
                'id',
            ],

            default => [
                'id',
            ],
        };

        $identifier = '';

        foreach ($fields as $field) {
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

        return [
            'scope' => $scope,

            'identifier' =>
                $identifier,

            'type' => trim(
                (string)(
                    $record['entity_type']
                    ?? $record[
                        'relationship_type'
                    ]
                    ?? $record['type']
                    ?? $scope
                )
            ),
        ];
    }

    /**
     * Set registered rule enabled state.
     *
     * @return array<string,mixed>
     */
    private function setEnabled(
        string $ruleId,
        bool $enabled
    ): array {
        $ruleId = $this->normalizeIdentifier(
            $ruleId
        );

        if (
            $ruleId === ''
            || !isset($this->rules[$ruleId])
        ) {
            throw new RuntimeException(
                sprintf(
                    'Rule "%s" is not registered.',
                    $ruleId
                )
            );
        }

        $rule = $this->rules[$ruleId];

        $rule['enabled'] = $enabled;
        $rule['updated_at'] = gmdate('c');
        $rule['checksum'] =
            $this->ruleChecksum($rule);

        $this->rules[$ruleId] = $rule;

        return $rule;
    }

    /**
     * Sort registered rules by priority.
     */
    private function sortRegisteredRules(): void
    {
        uasort(
            $this->rules,
            static function (
                array $left,
                array $right
            ): int {
                $priorityComparison =
                    (int)(
                        $left['priority']
                        ?? 100
                    )
                    <=>
                    (int)(
                        $right['priority']
                        ?? 100
                    );

                if ($priorityComparison !== 0) {
                    return $priorityComparison;
                }

                return strcmp(
                    (string)(
                        $left['rule_id']
                        ?? ''
                    ),
                    (string)(
                        $right['rule_id']
                        ?? ''
                    )
                );
            }
        );
    }

    /**
     * Calculate canonical rule checksum.
     *
     * @param array<string,mixed> $rule
     */
    private function ruleChecksum(
        array $rule
    ): string {
        $copy = $rule;

        unset($copy['checksum']);

        $json = json_encode(
            $this->normalizeForHash($copy),
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
        );

        if ($json === false) {
            throw new RuntimeException(
                'Unable to calculate rule checksum.'
            );
        }

        return hash('sha256', $json);
    }

    /**
     * Verify canonical rule checksum.
     *
     * @param array<string,mixed> $rule
     */
    private function ruleChecksumMatches(
        array $rule
    ): bool {
        $stored = trim(
            (string)(
                $rule['checksum']
                ?? ''
            )
        );

        if ($stored === '') {
            return false;
        }

        return hash_equals(
            $stored,
            $this->ruleChecksum($rule)
        );
    }

    /**
     * Calculate record checksum for decision evidence.
     *
     * @param array<string,mixed> $record
     */
    private function recordChecksum(
        array $record
    ): string {
        $json = json_encode(
            $this->normalizeForHash($record),
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
        );

        return $json !== false
            ? hash('sha256', $json)
            : '';
    }

    /**
     * Convert values for case-insensitive equality.
     */
    private function looseComparable(
        mixed $value
    ): mixed {
        if (is_string($value)) {
            return $this->lower(
                trim($value)
            );
        }

        if (is_array($value)) {
            return $this->normalizeForHash(
                $value
            );
        }

        return $value;
    }

    /**
     * Determine whether a value contains another.
     */
    private function contains(
        mixed $actual,
        mixed $expected
    ): bool {
        if (is_array($actual)) {
            foreach ($actual as $value) {
                if (
                    $this->looseComparable($value)
                    ===
                    $this->looseComparable($expected)
                ) {
                    return true;
                }
            }

            return false;
        }

        return str_contains(
            $this->lower(
                (string)$actual
            ),
            $this->lower(
                (string)$expected
            )
        );
    }

    /**
     * Test numeric or date range.
     */
    private function between(
        mixed $actual,
        mixed $expected
    ): bool {
        $range = $this->toList(
            $expected
        );

        if (count($range) < 2) {
            return false;
        }

        if (
            is_numeric($actual)
            && is_numeric($range[0])
            && is_numeric($range[1])
        ) {
            $actualNumber = (float)$actual;

            $minimum = min(
                (float)$range[0],
                (float)$range[1]
            );

            $maximum = max(
                (float)$range[0],
                (float)$range[1]
            );

            return $actualNumber >= $minimum
                && $actualNumber <= $maximum;
        }

        $actualTime = strtotime(
            (string)$actual
        );

        $leftTime = strtotime(
            (string)$range[0]
        );

        $rightTime = strtotime(
            (string)$range[1]
        );

        if (
            $actualTime === false
            || $leftTime === false
            || $rightTime === false
        ) {
            return false;
        }

        return $actualTime >= min(
            $leftTime,
            $rightTime
        )
            && $actualTime <= max(
                $leftTime,
                $rightTime
            );
    }

    /**
     * Test regular expression safely.
     */
    private function regexMatches(
        mixed $actual,
        mixed $expected
    ): bool {
        $pattern = (string)$expected;

        if ($pattern === '') {
            return false;
        }

        set_error_handler(
            static fn (): bool => true
        );

        try {
            $result = preg_match(
                $pattern,
                (string)$actual
            );
        } finally {
            restore_error_handler();
        }

        return $result === 1;
    }

    /**
     * Compare two date values.
     */
    private function dateCompare(
        mixed $actual,
        mixed $expected
    ): int {
        $actualTime = strtotime(
            (string)$actual
        );

        $expectedTime = strtotime(
            (string)$expected
        );

        if (
            $actualTime === false
            || $expectedTime === false
        ) {
            return 0;
        }

        return $actualTime
            <=> $expectedTime;
    }

    /**
     * Format a date value.
     */
    private function dateFormat(
        mixed $value,
        string $format
    ): string {
        $timestamp = strtotime(
            (string)$value
        );

        return $timestamp !== false
            ? gmdate($format, $timestamp)
            : '';
    }

    /**
     * Return value length.
     */
    private function valueLength(
        mixed $value
    ): int {
        if (is_array($value)) {
            return count($value);
        }

        if (is_string($value)) {
            return function_exists('mb_strlen')
                ? mb_strlen(
                    $value,
                    'UTF-8'
                )
                : strlen($value);
        }

        if ($value === null) {
            return 0;
        }

        return 1;
    }

    /**
     * Convert to numeric value.
     */
    private function numeric(
        mixed $value
    ): float {
        return is_numeric($value)
            ? (float)$value
            : 0.0;
    }

    /**
     * Convert arbitrary value into list.
     *
     * @return array<int,mixed>
     */
    private function toList(
        mixed $value
    ): array {
        if (is_array($value)) {
            return array_values($value);
        }

        if (is_string($value)) {
            return preg_split(
                '/[\r\n,]+/',
                $value
            ) ?: [];
        }

        return [$value];
    }

    /**
     * Normalize list values for comparison.
     *
     * @return array<int,string>
     */
    private function normalizedList(
        mixed $value
    ): array {
        $normalized = [];

        foreach ($this->toList($value) as $item) {
            $item = $this->lower(
                trim((string)$item)
            );

            if ($item !== '') {
                $normalized[$item] = $item;
            }
        }

        return array_values($normalized);
    }

    /**
     * Normalize string list.
     *
     * @return array<int,string>
     */
    private function normalizeStringList(
        mixed $values
    ): array {
        $normalized = [];

        foreach ($this->toList($values) as $value) {
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
     * Normalize rule scope.
     */
    private function normalizeScope(
        string $scope
    ): string {
        $scope = $this->normalizeMachineKey(
            $scope
        );

        return in_array(
            $scope,
            $this->scopes,
            true
        )
            ? $scope
            : 'any';
    }

    /**
     * Normalize severity.
     */
    private function normalizeSeverity(
        string $severity
    ): string {
        $severity = strtolower(
            trim($severity)
        );

        return array_key_exists(
            $severity,
            $this->severityOrder
        )
            ? $severity
            : 'info';
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
     * Lowercase with multibyte support.
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
     * Generate rule identifier.
     */
    private function generateRuleId(): string
    {
        return 'RUL-'
            . gmdate('Ymd-His')
            . '-'
            . $this->randomToken(6);
    }

    /**
     * Generate execution identifier.
     */
    private function generateExecutionId(): string
    {
        return 'REX-'
            . gmdate('Ymd-His')
            . '-'
            . $this->randomToken(6);
    }

    /**
     * Generate decision identifier.
     */
    private function generateDecisionId(): string
    {
        return 'RDC-'
            . gmdate('Ymd-His')
            . '-'
            . $this->randomToken(6);
    }

    /**
     * Generate action identifier.
     */
    private function generateActionId(): string
    {
        return 'RAC-'
            . gmdate('Ymd-His')
            . '-'
            . $this->randomToken(5);
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
}