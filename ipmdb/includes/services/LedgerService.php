<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/LedgerService.php
|--------------------------------------------------------------------------
| IPMdb Ledger Service
|--------------------------------------------------------------------------
|
| Maintains attributable, append-oriented ledger records for ideas, assets,
| decisions, relationships, workflows, events, contributions, obligations,
| allocations, transfers, adjustments, and implementation activity.
|
| Responsibilities:
| - Create canonical ledger entries.
| - Preserve immutable entry identity and attribution.
| - Support debit, credit, transfer, allocation, contribution, adjustment,
|   commitment, obligation, release, reversal, and informational entries.
| - Maintain balanced transaction groups where financial values apply.
| - Preserve provenance, evidence, references, and external identifiers.
| - Support public-domain asset accounting and DAD-related categories.
| - Calculate running balances, grouped balances, and reconciliation reports.
| - Reverse entries through compensating records rather than mutation.
| - Produce graph-ready ledger entities and relationships.
| - Calculate deterministic checksums and tamper-evident chain hashes.
|
| Entries append.
| Corrections compensate.
| Decisions authorize.
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
require_once __DIR__ . '/DecisionService.php';
require_once __DIR__ . '/RelationshipService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class LedgerService extends Service
{
    use GraphUtilities;

    private ValidationService $validation;

    private ProvenanceService $provenance;

    private VersionService $versions;

    private EventService $events;

    private DecisionService $decisions;

    private RelationshipService $relationships;

    /**
     * Supported ledger entry statuses.
     *
     * @var array<int,string>
     */
    private array $statuses = [
        'draft',
        'pending',
        'posted',
        'settled',
        'disputed',
        'held',
        'reversed',
        'voided',
        'archived',
    ];

    /**
     * Supported entry types.
     *
     * @var array<int,string>
     */
    private array $entryTypes = [
        'debit',
        'credit',
        'transfer',
        'allocation',
        'contribution',
        'adjustment',
        'commitment',
        'obligation',
        'release',
        'reversal',
        'valuation',
        'recognition',
        'attribution',
        'royalty',
        'license',
        'expense',
        'income',
        'payment',
        'refund',
        'write_off',
        'informational',
    ];

    /**
     * Supported account classes.
     *
     * @var array<int,string>
     */
    private array $accountClasses = [
        'asset',
        'liability',
        'equity',
        'income',
        'expense',
        'contribution',
        'restricted_fund',
        'unrestricted_fund',
        'clearing',
        'memorandum',
    ];

    /**
     * Supported transaction classes.
     *
     * @var array<int,string>
     */
    private array $transactionClasses = [
        'financial',
        'non_financial',
        'intellectual_property',
        'contribution',
        'allocation',
        'governance',
        'implementation',
        'provenance',
        'recognition',
        'audit',
        'memorandum',
    ];

    /**
     * Supported value units.
     *
     * @var array<int,string>
     */
    private array $units = [
        'CAD',
        'USD',
        'EUR',
        'GBP',
        'JPY',
        'AUD',
        'NZD',
        'CHF',
        'CNY',
        'INR',
        'unit',
        'hour',
        'day',
        'point',
        'share',
        'percentage',
        'token',
        'credit',
        'recognition',
        'none',
    ];

    /**
     * Supported ledger scopes.
     *
     * @var array<int,string>
     */
    private array $scopes = [
        'global',
        'organization',
        'program',
        'project',
        'workflow',
        'decision',
        'idea',
        'asset',
        'relationship',
        'person',
        'contribution',
        'dad',
        'custom',
    ];

    /**
     * Allowed entry status transitions.
     *
     * @var array<string,array<int,string>>
     */
    private array $transitions = [
        'draft' => [
            'pending',
            'posted',
            'voided',
            'archived',
        ],

        'pending' => [
            'draft',
            'posted',
            'held',
            'voided',
            'archived',
        ],

        'posted' => [
            'settled',
            'disputed',
            'held',
            'reversed',
            'archived',
        ],

        'settled' => [
            'disputed',
            'reversed',
            'archived',
        ],

        'disputed' => [
            'posted',
            'settled',
            'held',
            'reversed',
            'voided',
            'archived',
        ],

        'held' => [
            'pending',
            'posted',
            'settled',
            'voided',
            'archived',
        ],

        'reversed' => [
            'archived',
        ],

        'voided' => [
            'archived',
        ],

        'archived' => [],
    ];

    /**
     * Fields protected after creation.
     *
     * @var array<int,string>
     */
    private array $immutableFields = [
        'ledger_entry_id',
        'entity_id',
        'entity_type',
        'transaction_id',
        'sequence',
        'created_at',
        'created_by',
        'previous_entry_hash',
    ];

    /**
     * Fields excluded from checksum calculation.
     *
     * @var array<int,string>
     */
    private array $checksumExcludedFields = [
        'checksum',
        'entry_hash',
        'updated_at',
        'last_accessed_at',
        'runtime',
        'analytics',
        'search_score',
    ];

    /**
     * Monetary precision by unit.
     *
     * @var array<string,int>
     */
    private array $unitPrecision = [
        'CAD' => 2,
        'USD' => 2,
        'EUR' => 2,
        'GBP' => 2,
        'JPY' => 0,
        'AUD' => 2,
        'NZD' => 2,
        'CHF' => 2,
        'CNY' => 2,
        'INR' => 2,
        'unit' => 6,
        'hour' => 4,
        'day' => 4,
        'point' => 4,
        'share' => 8,
        'percentage' => 4,
        'token' => 8,
        'credit' => 6,
        'recognition' => 6,
        'none' => 6,
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?ValidationService $validation = null,
        ?ProvenanceService $provenance = null,
        ?VersionService $versions = null,
        ?EventService $events = null,
        ?DecisionService $decisions = null,
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

        $this->decisions = $decisions
            ?? new DecisionService();

        $this->relationships = $relationships
            ?? new RelationshipService();

        if (
            isset($config['entry_types'])
            && is_array($config['entry_types'])
        ) {
            $this->entryTypes = $this->normalizeStringList(
                array_merge(
                    $this->entryTypes,
                    $config['entry_types']
                )
            );
        }

        if (
            isset($config['account_classes'])
            && is_array($config['account_classes'])
        ) {
            $this->accountClasses = $this->normalizeStringList(
                array_merge(
                    $this->accountClasses,
                    $config['account_classes']
                )
            );
        }

        if (
            isset($config['units'])
            && is_array($config['units'])
        ) {
            $this->units = array_values(
                array_unique(
                    array_merge(
                        $this->units,
                        array_map(
                            static fn (mixed $value): string =>
                                trim((string)$value),
                            $config['units']
                        )
                    )
                )
            );
        }

        if (
            isset($config['unit_precision'])
            && is_array($config['unit_precision'])
        ) {
            foreach (
                $config['unit_precision']
                as $unit => $precision
            ) {
                $unit = trim((string)$unit);

                if ($unit === '') {
                    continue;
                }

                $this->unitPrecision[$unit] = max(
                    0,
                    min(
                        12,
                        (int)$precision
                    )
                );
            }
        }
    }

    /**
     * Create one canonical ledger entry.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function createEntry(
        array $input,
        string $actorId = '',
        ?array $previousEntry = null
    ): array {
        $this->reset();

        $actorId = trim(
            $actorId !== ''
                ? $actorId
                : (string)(
                    $input['created_by']
                    ?? $input['actor_id']
                    ?? ''
                )
        );

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Ledger entry creation requires actor attribution.'
            );
        }

        $entryType = $this->normalizeEntryType(
            (string)(
                $input['entry_type']
                ?? $input['type']
                ?? 'informational'
            )
        );

        $transactionClass =
            $this->normalizeTransactionClass(
                (string)(
                    $input['transaction_class']
                    ?? $this->defaultTransactionClass(
                        $entryType
                    )
                )
            );

        $scope = $this->normalizeScope(
            (string)(
                $input['scope']
                ?? 'global'
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
                ?? 'entity'
            )
        );

        $accountId = trim(
            (string)(
                $input['account_id']
                ?? ''
            )
        );

        $counterpartyAccountId = trim(
            (string)(
                $input['counterparty_account_id']
                ?? ''
            )
        );

        $accountClass =
            $this->normalizeAccountClass(
                (string)(
                    $input['account_class']
                    ?? 'memorandum'
                )
            );

        $unit = $this->normalizeUnit(
            (string)(
                $input['unit']
                ?? 'none'
            )
        );

        $quantity = $this->normalizeAmount(
            $input['quantity']
                ?? $input['amount']
                ?? 0,
            $unit
        );

        $debit = $this->normalizeAmount(
            $input['debit']
                ?? 0,
            $unit
        );

        $credit = $this->normalizeAmount(
            $input['credit']
                ?? 0,
            $unit
        );

        if (
            $debit === 0.0
            && $credit === 0.0
            && $quantity !== 0.0
        ) {
            if (
                in_array(
                    $entryType,
                    [
                        'debit',
                        'expense',
                        'allocation',
                        'obligation',
                        'payment',
                        'write_off',
                    ],
                    true
                )
            ) {
                $debit = abs($quantity);
            } elseif (
                in_array(
                    $entryType,
                    [
                        'credit',
                        'income',
                        'contribution',
                        'release',
                        'refund',
                        'recognition',
                        'valuation',
                    ],
                    true
                )
            ) {
                $credit = abs($quantity);
            }
        }

        if (
            $debit < 0
            || $credit < 0
        ) {
            throw new InvalidArgumentException(
                'Ledger debit and credit values cannot be negative.'
            );
        }

        if (
            $debit > 0
            && $credit > 0
        ) {
            throw new InvalidArgumentException(
                'One ledger entry cannot contain both debit and credit values.'
            );
        }

        $transactionId = trim(
            (string)(
                $input['transaction_id']
                ?? ''
            )
        );

        if ($transactionId === '') {
            $transactionId =
                $this->generateTransactionId(
                    $entryType,
                    $subjectId
                );
        }

        $sequence = max(
            1,
            (int)(
                $input['sequence']
                ?? 1
            )
        );

        $ledgerEntryId = trim(
            (string)(
                $input['ledger_entry_id']
                ?? ''
            )
        );

        if ($ledgerEntryId === '') {
            $ledgerEntryId =
                $this->generateLedgerEntryId(
                    $transactionId,
                    $sequence
                );
        }

        $previousEntryHash = trim(
            (string)(
                $input['previous_entry_hash']
                ?? (
                    is_array($previousEntry)
                        ? (
                            $previousEntry['entry_hash']
                            ?? ''
                        )
                        : ''
                )
            )
        );

        $description = trim(
            (string)(
                $input['description']
                ?? $input['memo']
                ?? $this->defaultDescription(
                    $entryType,
                    $subjectId,
                    $quantity,
                    $unit
                )
            )
        );

        $effectiveAt = $this->normalizeDate(
            $input['effective_at']
                ?? $input['posted_at']
                ?? gmdate('c')
        ) ?? gmdate('c');

        $now = gmdate('c');

        $metadata = is_array(
            $input['metadata']
                ?? null
        )
            ? $input['metadata']
            : [];

        $metadata['ledger_service'] = array_merge(
            is_array(
                $metadata['ledger_service']
                    ?? null
            )
                ? $metadata['ledger_service']
                : [],
            [
                'created_by_service' =>
                    static::class,

                'created_at' => $now,
            ]
        );

        $entry = [
            'ledger_entry_id' =>
                $ledgerEntryId,

            'entity_id' =>
                $ledgerEntryId,

            'entity_type' =>
                'ledger_entry',

            'transaction_id' =>
                $transactionId,

            'sequence' =>
                $sequence,

            'entry_type' =>
                $entryType,

            'transaction_class' =>
                $transactionClass,

            'scope' =>
                $scope,

            'subject_id' =>
                $subjectId,

            'subject_type' =>
                $subjectType !== ''
                    ? $subjectType
                    : 'entity',

            'account_id' =>
                $accountId,

            'account_class' =>
                $accountClass,

            'counterparty_account_id' =>
                $counterpartyAccountId,

            'description' =>
                $description,

            'memo' => trim(
                (string)(
                    $input['memo']
                    ?? ''
                )
            ),

            'quantity' =>
                $quantity,

            'debit' =>
                $debit,

            'credit' =>
                $credit,

            'net_amount' =>
                $this->normalizeAmount(
                    $credit - $debit,
                    $unit
                ),

            'unit' =>
                $unit,

            'unit_value' =>
                $this->normalizeAmount(
                    $input['unit_value']
                        ?? 1,
                    $unit
                ),

            'base_unit' => trim(
                (string)(
                    $input['base_unit']
                    ?? $unit
                )
            ),

            'base_value' =>
                $this->normalizeAmount(
                    $input['base_value']
                        ?? (
                            $quantity
                            * (
                                (float)(
                                    $input['unit_value']
                                    ?? 1
                                )
                            )
                        ),
                    $unit
                ),

            'status' =>
                $this->normalizeStatus(
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

            'decision_id' => trim(
                (string)(
                    $input['decision_id']
                        ?? ''
                )
            ),

            'workflow_id' => trim(
                (string)(
                    $input['workflow_id']
                        ?? ''
                )
            ),

            'idea_id' => trim(
                (string)(
                    $input['idea_id']
                        ?? ''
                )
            ),

            'asset_id' => trim(
                (string)(
                    $input['asset_id']
                        ?? ''
                )
            ),

            'relationship_id' => trim(
                (string)(
                    $input['relationship_id']
                        ?? ''
                )
            ),

            'contribution_id' => trim(
                (string)(
                    $input['contribution_id']
                        ?? ''
                )
            ),

            'program_id' => trim(
                (string)(
                    $input['program_id']
                        ?? ''
                )
            ),

            'external_reference' => trim(
                (string)(
                    $input['external_reference']
                        ?? $input[
                            'payment_reference'
                        ] ?? ''
                )
            ),

            'source_reference' => trim(
                (string)(
                    $input['source_reference']
                        ?? $input['source_url']
                        ?? ''
                )
            ),

            'provenance_id' => trim(
                (string)(
                    $input['provenance_id']
                        ?? ''
                )
            ),

            'evidence' =>
                $this->normalizeEvidence(
                    $input['evidence']
                        ?? []
                ),

            'restrictions' =>
                $this->normalizeStringList(
                    $input['restrictions']
                        ?? []
                ),

            'tags' =>
                $this->normalizeStringList(
                    $input['tags']
                        ?? []
                ),

            'effective_at' =>
                $effectiveAt,

            'posted_by' =>
                null,

            'posted_at' =>
                null,

            'settled_by' =>
                null,

            'settled_at' =>
                null,

            'disputed_by' =>
                null,

            'disputed_at' =>
                null,

            'dispute_reason' =>
                null,

            'held_by' =>
                null,

            'held_at' =>
                null,

            'hold_reason' =>
                null,

            'reversed_by' =>
                null,

            'reversed_at' =>
                null,

            'reversal_entry_id' =>
                null,

            'reversal_reason' =>
                null,

            'voided_by' =>
                null,

            'voided_at' =>
                null,

            'void_reason' =>
                null,

            'archived_by' =>
                null,

            'archived_at' =>
                null,

            'created_by' =>
                $actorId,

            'created_at' =>
                $now,

            'updated_by' =>
                $actorId,

            'updated_at' =>
                $now,

            'previous_entry_hash' =>
                $previousEntryHash,

            'metadata' =>
                $metadata,

            'checksum' => '',

            'entry_hash' => '',
        ];

        $entry = $this->mergeAdditionalFields(
            $entry,
            $input
        );

        if (
            in_array(
                $entry['status'],
                [
                    'posted',
                    'settled',
                ],
                true
            )
        ) {
            $entry['posted_by'] =
                trim(
                    (string)(
                        $input['posted_by']
                        ?? $actorId
                    )
                );

            $entry['posted_at'] =
                $this->normalizeDate(
                    $input['posted_at']
                    ?? $now
                )
                ?? $now;
        }

        if ($entry['status'] === 'settled') {
            $entry['settled_by'] =
                trim(
                    (string)(
                        $input['settled_by']
                        ?? $actorId
                    )
                );

            $entry['settled_at'] =
                $this->normalizeDate(
                    $input['settled_at']
                    ?? $now
                )
                ?? $now;
        }

        $entry['checksum'] =
            $this->calculateChecksum(
                $entry
            );

        $entry['entry_hash'] =
            $this->calculateEntryHash(
                $entry
            );

        $validation = $this->validateEntry(
            $entry
        );

        if (
            ($validation['valid'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Ledger entry validation failed: '
                . implode(
                    ' ',
                    $validation['errors']
                        ?? []
                )
            );
        }

        $this->addMessage(
            'Ledger entry created.',
            [
                'ledger_entry_id' =>
                    $ledgerEntryId,

                'transaction_id' =>
                    $transactionId,

                'entry_type' =>
                    $entryType,

                'status' =>
                    $entry['status'],
            ]
        );

        return $entry;
    }

    /**
     * Create a balanced two-entry transfer.
     *
     * @return array<string,mixed>
     */
    public function createTransfer(
        string $fromAccountId,
        string $toAccountId,
        float|int|string $amount,
        string $unit,
        string $actorId,
        array $options = []
    ): array {
        $fromAccountId = trim(
            $fromAccountId
        );

        $toAccountId = trim(
            $toAccountId
        );

        if (
            $fromAccountId === ''
            || $toAccountId === ''
        ) {
            throw new InvalidArgumentException(
                'Transfer requires source and destination accounts.'
            );
        }

        if ($fromAccountId === $toAccountId) {
            throw new InvalidArgumentException(
                'Transfer accounts must be different.'
            );
        }

        $unit = $this->normalizeUnit(
            $unit
        );

        $amount = abs(
            $this->normalizeAmount(
                $amount,
                $unit
            )
        );

        if ($amount <= 0) {
            throw new InvalidArgumentException(
                'Transfer amount must be greater than zero.'
            );
        }

        $transactionId = trim(
            (string)(
                $options['transaction_id']
                    ?? ''
            )
        );

        if ($transactionId === '') {
            $transactionId =
                $this->generateTransactionId(
                    'transfer',
                    $fromAccountId
                    . '-'
                    . $toAccountId
                );
        }

        $common = [
            'transaction_id' =>
                $transactionId,

            'transaction_class' =>
                $options[
                    'transaction_class'
                ] ?? 'financial',

            'scope' =>
                $options['scope']
                    ?? 'global',

            'subject_id' =>
                $options['subject_id']
                    ?? '',

            'subject_type' =>
                $options['subject_type']
                    ?? 'entity',

            'unit' => $unit,

            'effective_at' =>
                $options['effective_at']
                    ?? gmdate('c'),

            'decision_id' =>
                $options['decision_id']
                    ?? '',

            'workflow_id' =>
                $options['workflow_id']
                    ?? '',

            'provenance_id' =>
                $options['provenance_id']
                    ?? '',

            'source_reference' =>
                $options['source_reference']
                    ?? '',

            'external_reference' =>
                $options['external_reference']
                    ?? '',

            'status' =>
                $options['status']
                    ?? 'draft',

            'tags' =>
                $options['tags']
                    ?? [],

            'metadata' =>
                $options['metadata']
                    ?? [],
        ];

        $debitEntry = $this->createEntry(
            array_merge(
                $common,
                [
                    'sequence' => 1,

                    'entry_type' =>
                        'transfer',

                    'account_id' =>
                        $fromAccountId,

                    'counterparty_account_id' =>
                        $toAccountId,

                    'account_class' =>
                        $options[
                            'from_account_class'
                        ] ?? 'asset',

                    'debit' =>
                        $amount,

                    'credit' => 0,

                    'quantity' =>
                        -$amount,

                    'description' =>
                        $options['description']
                            ?? sprintf(
                                'Transfer from %s to %s.',
                                $fromAccountId,
                                $toAccountId
                            ),
                ]
            ),
            $actorId
        );

        $creditEntry = $this->createEntry(
            array_merge(
                $common,
                [
                    'sequence' => 2,

                    'entry_type' =>
                        'transfer',

                    'account_id' =>
                        $toAccountId,

                    'counterparty_account_id' =>
                        $fromAccountId,

                    'account_class' =>
                        $options[
                            'to_account_class'
                        ] ?? 'asset',

                    'debit' => 0,

                    'credit' =>
                        $amount,

                    'quantity' =>
                        $amount,

                    'description' =>
                        $options['description']
                            ?? sprintf(
                                'Transfer from %s to %s.',
                                $fromAccountId,
                                $toAccountId
                            ),

                    'previous_entry_hash' =>
                        $debitEntry[
                            'entry_hash'
                        ],
                ]
            ),
            $actorId,
            $debitEntry
        );

        $validation =
            $this->validateTransaction(
                [
                    $debitEntry,
                    $creditEntry,
                ]
            );

        if (
            ($validation['balanced'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Generated transfer transaction is unbalanced.'
            );
        }

        return [
            'transaction_id' =>
                $transactionId,

            'entry_count' => 2,

            'entries' => [
                $debitEntry,
                $creditEntry,
            ],

            'validation' =>
                $validation,

            'checksum' => hash(
                'sha256',
                $debitEntry['entry_hash']
                . '|'
                . $creditEntry['entry_hash']
            ),
        ];
    }

    /**
     * Create one contribution entry.
     *
     * Supports DAD and other attributable contribution programs.
     *
     * @return array<string,mixed>
     */
    public function createContribution(
        array $input,
        string $actorId
    ): array {
        $contributorId = trim(
            (string)(
                $input['contributor_id']
                    ?? $input['subject_id']
                    ?? ''
            )
        );

        $programId = trim(
            (string)(
                $input['program_id']
                    ?? 'dad'
            )
        );

        $amount = $input['amount']
            ?? $input['quantity']
            ?? 0;

        $unit = (string)(
            $input['unit']
                ?? 'CAD'
        );

        return $this->createEntry(
            array_merge(
                [
                    'entry_type' =>
                        'contribution',

                    'transaction_class' =>
                        'contribution',

                    'scope' =>
                        $programId === 'dad'
                            ? 'dad'
                            : 'program',

                    'subject_id' =>
                        $contributorId,

                    'subject_type' =>
                        'contributor',

                    'account_id' =>
                        $input['account_id']
                            ?? (
                                $programId
                                . ':contributions'
                            ),

                    'account_class' =>
                        'restricted_fund',

                    'program_id' =>
                        $programId,

                    'contribution_id' =>
                        $input[
                            'contribution_id'
                        ] ?? '',

                    'quantity' =>
                        $amount,

                    'credit' =>
                        abs((float)$amount),

                    'debit' => 0,

                    'unit' =>
                        $unit,

                    'status' =>
                        $input['status']
                            ?? 'pending',

                    'description' =>
                        $input['description']
                            ?? sprintf(
                                'Contribution to %s.',
                                strtoupper(
                                    $programId
                                )
                            ),

                    'restrictions' =>
                        $input['restrictions']
                            ?? [
                                'program-restricted',
                            ],

                    'metadata' =>
                        array_merge(
                            is_array(
                                $input['metadata']
                                    ?? null
                            )
                                ? $input['metadata']
                                : [],
                            [
                                'contribution' => [
                                    'program_id' =>
                                        $programId,

                                    'contributor_id' =>
                                        $contributorId,

                                    'anonymous' =>
                                        (bool)(
                                            $input[
                                                'anonymous'
                                            ] ?? false
                                        ),
                                ],
                            ]
                        ),
                ],
                $input
            ),
            $actorId
        );
    }

    /**
     * Validate one ledger entry.
     *
     * @return array<string,mixed>
     */
    public function validateEntry(
        array $entry
    ): array {
        $errors = [];
        $warnings = [];

        foreach (
            [
                'ledger_entry_id',
                'entity_id',
                'entity_type',
                'transaction_id',
                'sequence',
                'entry_type',
                'transaction_class',
                'scope',
                'account_class',
                'unit',
                'status',
                'created_by',
                'created_at',
                'updated_at',
                'effective_at',
            ]
            as $field
        ) {
            if (
                $this->valueIsEmpty(
                    $entry[$field]
                        ?? null
                )
            ) {
                $errors[] = sprintf(
                    'Ledger entry field "%s" is required.',
                    $field
                );
            }
        }

        if (
            isset($entry['entity_type'])
            && $entry['entity_type']
                !== 'ledger_entry'
        ) {
            $errors[] =
                'Ledger entity type must be "ledger_entry".';
        }

        try {
            $this->normalizeStatus(
                (string)(
                    $entry['status']
                        ?? 'draft'
                )
            );
        } catch (Throwable $exception) {
            $errors[] =
                $exception->getMessage();
        }

        try {
            $this->normalizeEntryType(
                (string)(
                    $entry['entry_type']
                        ?? 'informational'
                )
            );
        } catch (Throwable $exception) {
            $errors[] =
                $exception->getMessage();
        }

        try {
            $this->normalizeTransactionClass(
                (string)(
                    $entry[
                        'transaction_class'
                    ] ?? 'memorandum'
                )
            );
        } catch (Throwable $exception) {
            $errors[] =
                $exception->getMessage();
        }

        try {
            $this->normalizeAccountClass(
                (string)(
                    $entry['account_class']
                        ?? 'memorandum'
                )
            );
        } catch (Throwable $exception) {
            $errors[] =
                $exception->getMessage();
        }

        $debit = (float)(
            $entry['debit']
                ?? 0
        );

        $credit = (float)(
            $entry['credit']
                ?? 0
        );

        if (
            $debit < 0
            || $credit < 0
        ) {
            $errors[] =
                'Ledger debit and credit cannot be negative.';
        }

        if (
            $debit > 0
            && $credit > 0
        ) {
            $errors[] =
                'Ledger entry cannot be both debit and credit.';
        }

        $expectedNet = $this->normalizeAmount(
            $credit - $debit,
            (string)(
                $entry['unit']
                    ?? 'none'
            )
        );

        $storedNet = $this->normalizeAmount(
            $entry['net_amount']
                ?? 0,
            (string)(
                $entry['unit']
                    ?? 'none'
            )
        );

        if (
            abs(
                $expectedNet - $storedNet
            ) > $this->tolerance(
                (string)(
                    $entry['unit']
                        ?? 'none'
                )
            )
        ) {
            $errors[] =
                'Ledger net amount does not match debit and credit values.';
        }

        if (
            in_array(
                (string)(
                    $entry['status']
                        ?? ''
                ),
                [
                    'posted',
                    'settled',
                ],
                true
            )
            && trim(
                (string)(
                    $entry['posted_by']
                        ?? ''
                )
            ) === ''
        ) {
            $errors[] =
                'Posted ledger entry requires posting attribution.';
        }

        if (
            ($entry['status'] ?? '')
            === 'settled'
            && trim(
                (string)(
                    $entry['settled_by']
                        ?? ''
                )
            ) === ''
        ) {
            $errors[] =
                'Settled ledger entry requires settlement attribution.';
        }

        if (
            ($entry['status'] ?? '')
            === 'reversed'
            && trim(
                (string)(
                    $entry[
                        'reversal_entry_id'
                    ] ?? ''
                )
            ) === ''
        ) {
            $errors[] =
                'Reversed ledger entry requires compensating entry reference.';
        }

        $storedChecksum = trim(
            (string)(
                $entry['checksum']
                    ?? ''
            )
        );

        if (
            $storedChecksum !== ''
            && !hash_equals(
                $storedChecksum,
                $this->calculateChecksum(
                    $entry
                )
            )
        ) {
            $errors[] =
                'Ledger entry checksum does not match content.';
        }

        $storedEntryHash = trim(
            (string)(
                $entry['entry_hash']
                    ?? ''
            )
        );

        if (
            $storedEntryHash !== ''
            && !hash_equals(
                $storedEntryHash,
                $this->calculateEntryHash(
                    $entry
                )
            )
        ) {
            $errors[] =
                'Ledger entry chain hash does not match content.';
        }

        if (
            trim(
                (string)(
                    $entry['account_id']
                        ?? ''
                )
            ) === ''
        ) {
            $warnings[] =
                'Ledger entry has no account identifier.';
        }

        if (
            trim(
                (string)(
                    $entry['subject_id']
                        ?? ''
                )
            ) === ''
        ) {
            $warnings[] =
                'Ledger entry has no subject identifier.';
        }

        if (
            trim(
                (string)(
                    $entry['provenance_id']
                        ?? ''
                )
            ) === ''
            && trim(
                (string)(
                    $entry['source_reference']
                        ?? ''
                )
            ) === ''
        ) {
            $warnings[] =
                'Ledger entry has no provenance reference.';
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
     * Validate a grouped transaction.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function validateTransaction(
        array $entries
    ): array {
        $errors = [];
        $warnings = [];

        if ($entries === []) {
            return [
                'valid' => false,
                'balanced' => false,
                'errors' => [
                    'Transaction contains no ledger entries.',
                ],
                'warnings' => [],
            ];
        }

        $transactionIds = [];
        $unitTotals = [];
        $sequenceValues = [];
        $entryResults = [];

        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                $errors[] = sprintf(
                    'Transaction entry %d is invalid.',
                    $index
                );

                continue;
            }

            $entryValidation =
                $this->validateEntry(
                    $entry
                );

            $entryResults[] = [
                'index' => $index,

                'ledger_entry_id' =>
                    $entry[
                        'ledger_entry_id'
                    ] ?? null,

                'validation' =>
                    $entryValidation,
            ];

            if (
                ($entryValidation['valid'] ?? false)
                !== true
            ) {
                foreach (
                    $entryValidation['errors']
                        ?? []
                    as $error
                ) {
                    $errors[] = sprintf(
                        'Entry %d: %s',
                        $index,
                        $error
                    );
                }
            }

            $transactionId = trim(
                (string)(
                    $entry['transaction_id']
                        ?? ''
                )
            );

            if ($transactionId !== '') {
                $transactionIds[
                    $transactionId
                ] = true;
            }

            $unit = $this->normalizeUnit(
                (string)(
                    $entry['unit']
                        ?? 'none'
                )
            );

            $unitTotals[$unit] =
                $unitTotals[$unit]
                ?? [
                    'debit' => 0.0,
                    'credit' => 0.0,
                    'net' => 0.0,
                ];

            $unitTotals[$unit]['debit'] +=
                (float)(
                    $entry['debit']
                        ?? 0
                );

            $unitTotals[$unit]['credit'] +=
                (float)(
                    $entry['credit']
                        ?? 0
                );

            $unitTotals[$unit]['net'] +=
                (float)(
                    $entry['net_amount']
                        ?? 0
                );

            $sequence = (int)(
                $entry['sequence']
                    ?? 0
            );

            if ($sequence > 0) {
                if (
                    isset(
                        $sequenceValues[
                            $sequence
                        ]
                    )
                ) {
                    $errors[] = sprintf(
                        'Duplicate transaction sequence %d.',
                        $sequence
                    );
                }

                $sequenceValues[$sequence] =
                    true;
            }
        }

        if (count($transactionIds) > 1) {
            $errors[] =
                'Transaction entries contain multiple transaction identifiers.';
        }

        $balanced = true;

        foreach ($unitTotals as $unit => &$totals) {
            $totals['debit'] =
                $this->normalizeAmount(
                    $totals['debit'],
                    $unit
                );

            $totals['credit'] =
                $this->normalizeAmount(
                    $totals['credit'],
                    $unit
                );

            $totals['net'] =
                $this->normalizeAmount(
                    $totals['net'],
                    $unit
                );

            $totals['balanced'] =
                abs(
                    $totals['credit']
                    - $totals['debit']
                ) <= $this->tolerance(
                    $unit
                );

            if (!$totals['balanced']) {
                $balanced = false;
            }
        }

        unset($totals);

        if (!$balanced) {
            $errors[] =
                'Transaction debits and credits are not balanced by unit.';
        }

        return [
            'valid' =>
                $errors === [],

            'balanced' =>
                $balanced,

            'transaction_id' =>
                count($transactionIds) === 1
                    ? array_key_first(
                        $transactionIds
                    )
                    : null,

            'entry_count' =>
                count($entries),

            'unit_totals' =>
                $unitTotals,

            'entry_results' =>
                $entryResults,

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

                'entry_types' =>
                    $this->entryTypes,

                'account_classes' =>
                    $this->accountClasses,

                'transaction_classes' =>
                    $this->transactionClasses,

                'units' =>
                    $this->units,

                'scopes' =>
                    $this->scopes,

                'transitions' =>
                    $this->transitions,

                'immutable_fields' =>
                    $this->immutableFields,

                'unit_precision' =>
                    $this->unitPrecision,

                'double_entry_supported' =>
                    true,

                'compensating_reversals' =>
                    true,

                'tamper_evident_hash_chain' =>
                    true,

                'dad_contributions_supported' =>
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

    /*
    |--------------------------------------------------------------------------
    | POSTING, REVERSALS, BALANCES, AND RECONCILIATION CONTINUE IN PART 2
    |--------------------------------------------------------------------------
    |
    | Do not close the class yet.
    |
    */    /**
     * Update one draft or pending ledger entry.
     *
     * Posted, settled, reversed, voided, and archived entries remain
     * append-oriented and require compensating records instead of mutation.
     *
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $changes
     *
     * @return array<string,mixed>
     */
    public function updateEntry(
        array $entry,
        array $changes,
        string $actorId,
        string $reason = ''
    ): array {
        $this->assertEntry($entry);

        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Ledger entry update requires actor attribution.'
            );
        }

        $status = $this->normalizeStatus(
            (string)(
                $entry['status']
                    ?? 'draft'
            )
        );

        if (
            !in_array(
                $status,
                [
                    'draft',
                    'pending',
                    'disputed',
                    'held',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Posted or finalized ledger entries cannot be edited directly.'
            );
        }

        $updated = $entry;

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
                    $value,
                    $updated
                );
        }

        $unit = $this->normalizeUnit(
            (string)(
                $updated['unit']
                    ?? 'none'
            )
        );

        $debit = $this->normalizeAmount(
            $updated['debit']
                ?? 0,
            $unit
        );

        $credit = $this->normalizeAmount(
            $updated['credit']
                ?? 0,
            $unit
        );

        if (
            $debit < 0
            || $credit < 0
        ) {
            throw new InvalidArgumentException(
                'Ledger debit and credit values cannot be negative.'
            );
        }

        if (
            $debit > 0
            && $credit > 0
        ) {
            throw new InvalidArgumentException(
                'One ledger entry cannot contain both debit and credit values.'
            );
        }

        $updated['debit'] = $debit;
        $updated['credit'] = $credit;

        $updated['net_amount'] =
            $this->normalizeAmount(
                $credit - $debit,
                $unit
            );

        $updated['updated_by'] =
            $actorId;

        $updated['updated_at'] =
            gmdate('c');

        $updated['version'] =
            $this->incrementVersion(
                (string)(
                    $entry['version']
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

        $updated['checksum'] =
            $this->calculateChecksum(
                $updated
            );

        $updated['entry_hash'] =
            $this->calculateEntryHash(
                $updated
            );

        $validation =
            $this->validateEntry(
                $updated
            );

        if (
            ($validation['valid'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Updated ledger entry is invalid: '
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
     * Transition one ledger entry status.
     *
     * @param array<string,mixed> $entry
     *
     * @return array<string,mixed>
     */
    public function transitionEntry(
        array $entry,
        string $newStatus,
        string $actorId,
        string $reason = ''
    ): array {
        $this->assertEntry($entry);

        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Ledger transition requires actor attribution.'
            );
        }

        $currentStatus =
            $this->normalizeStatus(
                (string)(
                    $entry['status']
                        ?? 'draft'
                )
            );

        $newStatus =
            $this->normalizeStatus(
                $newStatus
            );

        if ($currentStatus === $newStatus) {
            return $entry;
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
                    'Ledger status cannot transition from "%s" to "%s".',
                    $currentStatus,
                    $newStatus
                )
            );
        }

        $updated = $entry;
        $updated['status'] = $newStatus;

        $now = gmdate('c');

        switch ($newStatus) {
            case 'posted':
                $updated['posted_by'] =
                    $actorId;

                $updated['posted_at'] =
                    $now;
                break;

            case 'settled':
                if (
                    trim(
                        (string)(
                            $updated['posted_by']
                                ?? ''
                        )
                    ) === ''
                ) {
                    $updated['posted_by'] =
                        $actorId;

                    $updated['posted_at'] =
                        $now;
                }

                $updated['settled_by'] =
                    $actorId;

                $updated['settled_at'] =
                    $now;
                break;

            case 'disputed':
                $updated['disputed_by'] =
                    $actorId;

                $updated['disputed_at'] =
                    $now;

                $updated['dispute_reason'] =
                    trim($reason);
                break;

            case 'held':
                $updated['held_by'] =
                    $actorId;

                $updated['held_at'] =
                    $now;

                $updated['hold_reason'] =
                    trim($reason);
                break;

            case 'reversed':
                $updated['reversed_by'] =
                    $actorId;

                $updated['reversed_at'] =
                    $now;

                $updated['reversal_reason'] =
                    trim($reason);
                break;

            case 'voided':
                $updated['voided_by'] =
                    $actorId;

                $updated['voided_at'] =
                    $now;

                $updated['void_reason'] =
                    trim($reason);
                break;

            case 'archived':
                $updated['archived_by'] =
                    $actorId;

                $updated['archived_at'] =
                    $now;
                break;
        }

        $updated['updated_by'] =
            $actorId;

        $updated['updated_at'] =
            $now;

        $updated['version'] =
            $this->incrementVersion(
                (string)(
                    $entry['version']
                        ?? '1.0'
                )
            );

        $updated['metadata'] = is_array(
            $updated['metadata']
                ?? null
        )
            ? $updated['metadata']
            : [];

        $updated['metadata']['last_status_change'] = [
            'from' =>
                $currentStatus,

            'to' =>
                $newStatus,

            'changed_by' =>
                $actorId,

            'changed_at' =>
                $now,

            'reason' =>
                trim($reason),
        ];

        $updated['checksum'] =
            $this->calculateChecksum(
                $updated
            );

        $updated['entry_hash'] =
            $this->calculateEntryHash(
                $updated
            );

        $validation =
            $this->validateEntry(
                $updated
            );

        if (
            ($validation['valid'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Ledger transition produced an invalid entry: '
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
     * Submit one draft entry for posting.
     */
    public function markPending(
        array $entry,
        string $actorId,
        string $reason = ''
    ): array {
        return $this->transitionEntry(
            $entry,
            'pending',
            $actorId,
            $reason
        );
    }

    /**
     * Post one ledger entry.
     */
    public function postEntry(
        array $entry,
        string $actorId,
        string $reason = ''
    ): array {
        $this->assertEntry($entry);

        if (
            !in_array(
                (string)(
                    $entry['status']
                        ?? ''
                ),
                [
                    'draft',
                    'pending',
                    'disputed',
                    'held',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Ledger entry is not in a postable state.'
            );
        }

        if (
            trim(
                (string)(
                    $entry['decision_id']
                        ?? ''
                )
            ) !== ''
            && isset(
                $entry['metadata']['decision']
            )
            && is_array(
                $entry['metadata']['decision']
            )
            && (
                $entry['metadata']['decision']['authorized']
                    ?? true
            ) !== true
        ) {
            throw new RuntimeException(
                'Ledger entry decision authorization is incomplete.'
            );
        }

        return $this->transitionEntry(
            $entry,
            'posted',
            $actorId,
            $reason !== ''
                ? $reason
                : 'Ledger entry posted.'
        );
    }

    /**
     * Settle one posted ledger entry.
     */
    public function settleEntry(
        array $entry,
        string $actorId,
        string $reason = ''
    ): array {
        if (
            !in_array(
                (string)(
                    $entry['status']
                        ?? ''
                ),
                [
                    'posted',
                    'disputed',
                    'held',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Ledger entry is not in a settleable state.'
            );
        }

        return $this->transitionEntry(
            $entry,
            'settled',
            $actorId,
            $reason !== ''
                ? $reason
                : 'Ledger entry settled.'
        );
    }

    /**
     * Place one ledger entry on hold.
     */
    public function holdEntry(
        array $entry,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Ledger hold requires a reason.'
            );
        }

        return $this->transitionEntry(
            $entry,
            'held',
            $actorId,
            $reason
        );
    }

    /**
     * Dispute one posted or settled ledger entry.
     */
    public function disputeEntry(
        array $entry,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Ledger dispute requires a reason.'
            );
        }

        return $this->transitionEntry(
            $entry,
            'disputed',
            $actorId,
            $reason
        );
    }

    /**
     * Void one unposted ledger entry.
     */
    public function voidEntry(
        array $entry,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Ledger void requires a reason.'
            );
        }

        if (
            !in_array(
                (string)(
                    $entry['status']
                        ?? ''
                ),
                [
                    'draft',
                    'pending',
                    'disputed',
                    'held',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Posted or settled entries require compensating reversal.'
            );
        }

        return $this->transitionEntry(
            $entry,
            'voided',
            $actorId,
            $reason
        );
    }

    /**
     * Archive one finalized ledger entry.
     */
    public function archiveEntry(
        array $entry,
        string $actorId,
        string $reason = ''
    ): array {
        return $this->transitionEntry(
            $entry,
            'archived',
            $actorId,
            $reason
        );
    }

    /**
     * Create a compensating reversal and mark the original entry reversed.
     *
     * @return array<string,mixed>
     */
    public function reverseEntry(
        array $entry,
        string $actorId,
        string $reason,
        ?array $previousEntry = null
    ): array {
        $this->assertEntry($entry);

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Ledger reversal requires a reason.'
            );
        }

        if (
            !in_array(
                (string)(
                    $entry['status']
                        ?? ''
                ),
                [
                    'posted',
                    'settled',
                    'disputed',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Only posted, settled, or disputed entries may be reversed.'
            );
        }

        if (
            trim(
                (string)(
                    $entry[
                        'reversal_entry_id'
                    ] ?? ''
                )
            ) !== ''
        ) {
            throw new RuntimeException(
                'Ledger entry already has a reversal record.'
            );
        }

        $reversalSequence = max(
            1,
            (int)(
                $entry['sequence']
                    ?? 1
            ) + 1
        );

        $reversal = $this->createEntry(
            [
                'transaction_id' =>
                    $entry['transaction_id'],

                'sequence' =>
                    $reversalSequence,

                'entry_type' =>
                    'reversal',

                'transaction_class' =>
                    $entry[
                        'transaction_class'
                    ],

                'scope' =>
                    $entry['scope'],

                'subject_id' =>
                    $entry['subject_id'],

                'subject_type' =>
                    $entry['subject_type'],

                'account_id' =>
                    $entry['account_id'],

                'account_class' =>
                    $entry['account_class'],

                'counterparty_account_id' =>
                    $entry[
                        'counterparty_account_id'
                    ],

                'description' =>
                    sprintf(
                        'Reversal of ledger entry %s.',
                        $entry[
                            'ledger_entry_id'
                        ]
                    ),

                'memo' =>
                    $reason,

                'quantity' =>
                    -1
                    * (float)(
                        $entry['quantity']
                            ?? 0
                    ),

                'debit' =>
                    (float)(
                        $entry['credit']
                            ?? 0
                    ),

                'credit' =>
                    (float)(
                        $entry['debit']
                            ?? 0
                    ),

                'unit' =>
                    $entry['unit'],

                'unit_value' =>
                    $entry['unit_value']
                        ?? 1,

                'base_unit' =>
                    $entry['base_unit']
                        ?? $entry['unit'],

                'base_value' =>
                    -1
                    * (float)(
                        $entry['base_value']
                            ?? 0
                    ),

                'status' =>
                    'posted',

                'decision_id' =>
                    $entry['decision_id']
                        ?? '',

                'workflow_id' =>
                    $entry['workflow_id']
                        ?? '',

                'idea_id' =>
                    $entry['idea_id']
                        ?? '',

                'asset_id' =>
                    $entry['asset_id']
                        ?? '',

                'relationship_id' =>
                    $entry[
                        'relationship_id'
                    ] ?? '',

                'contribution_id' =>
                    $entry[
                        'contribution_id'
                    ] ?? '',

                'program_id' =>
                    $entry['program_id']
                        ?? '',

                'external_reference' =>
                    $entry[
                        'external_reference'
                    ] ?? '',

                'source_reference' =>
                    $entry[
                        'source_reference'
                    ] ?? '',

                'provenance_id' =>
                    $entry[
                        'provenance_id'
                    ] ?? '',

                'effective_at' =>
                    gmdate('c'),

                'previous_entry_hash' =>
                    is_array($previousEntry)
                        ? (
                            $previousEntry[
                                'entry_hash'
                            ] ?? ''
                        )
                        : (
                            $entry['entry_hash']
                                ?? ''
                        ),

                'metadata' => [
                    'reversal' => [
                        'reverses_entry_id' =>
                            $entry[
                                'ledger_entry_id'
                            ],

                        'reason' =>
                            $reason,

                        'reversed_by' =>
                            $actorId,

                        'reversed_at' =>
                            gmdate('c'),
                    ],
                ],
            ],
            $actorId,
            $previousEntry ?? $entry
        );

        $reversal = $this->postEntry(
            $reversal,
            $actorId,
            'Compensating reversal posted.'
        );

        $original = $entry;

        $original[
            'reversal_entry_id'
        ] = $reversal[
            'ledger_entry_id'
        ];

        $original = $this->transitionEntry(
            $original,
            'reversed',
            $actorId,
            $reason
        );

        return [
            'original_entry' =>
                $original,

            'reversal_entry' =>
                $reversal,

            'balanced' =>
                abs(
                    (
                        (float)(
                            $entry['net_amount']
                                ?? 0
                        )
                    )
                    + (
                        (float)(
                            $reversal['net_amount']
                                ?? 0
                        )
                    )
                ) <= $this->tolerance(
                    (string)(
                        $entry['unit']
                            ?? 'none'
                    )
                ),
        ];
    }

    /**
     * Post every entry in one balanced transaction.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function postTransaction(
        array $entries,
        string $actorId,
        string $reason = ''
    ): array {
        $validation =
            $this->validateTransaction(
                $entries
            );

        if (
            ($validation['valid'] ?? false)
            !== true
            || ($validation['balanced'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Transaction cannot be posted because validation failed.'
            );
        }

        $postedEntries = [];
        $previousEntry = null;

        usort(
            $entries,
            static fn (
                array $left,
                array $right
            ): int =>
                (int)(
                    $left['sequence']
                        ?? 0
                )
                <=>
                (int)(
                    $right['sequence']
                        ?? 0
                )
        );

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if ($previousEntry !== null) {
                $entry[
                    'previous_entry_hash'
                ] = $previousEntry[
                    'entry_hash'
                ] ?? '';

                $entry['checksum'] =
                    $this->calculateChecksum(
                        $entry
                    );

                $entry['entry_hash'] =
                    $this->calculateEntryHash(
                        $entry
                    );
            }

            $posted = (
                $entry['status']
                    ?? ''
            ) === 'posted'
                ? $entry
                : $this->postEntry(
                    $entry,
                    $actorId,
                    $reason !== ''
                        ? $reason
                        : 'Transaction posted.'
                );

            $postedEntries[] =
                $posted;

            $previousEntry =
                $posted;
        }

        $chainValidation =
            $this->verifyChain(
                $postedEntries
            );

        return [
            'transaction_id' =>
                $validation[
                    'transaction_id'
                ],

            'entry_count' =>
                count($postedEntries),

            'entries' =>
                $postedEntries,

            'transaction_validation' =>
                $this->validateTransaction(
                    $postedEntries
                ),

            'chain_validation' =>
                $chainValidation,

            'posted_at' =>
                gmdate('c'),

            'posted_by' =>
                $actorId,
        ];
    }

    /**
     * Settle every posted entry in one transaction.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function settleTransaction(
        array $entries,
        string $actorId,
        string $reason = ''
    ): array {
        $validation =
            $this->validateTransaction(
                $entries
            );

        if (
            ($validation['balanced'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Unbalanced transaction cannot be settled.'
            );
        }

        $settledEntries = [];
        $previousEntry = null;

        usort(
            $entries,
            static fn (
                array $left,
                array $right
            ): int =>
                (int)(
                    $left['sequence']
                        ?? 0
                )
                <=>
                (int)(
                    $right['sequence']
                        ?? 0
                )
        );

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if ($previousEntry !== null) {
                $entry[
                    'previous_entry_hash'
                ] = $previousEntry[
                    'entry_hash'
                ] ?? '';
            }

            if (
                ($entry['status'] ?? '')
                !== 'posted'
                && ($entry['status'] ?? '')
                    !== 'settled'
            ) {
                $entry = $this->postEntry(
                    $entry,
                    $actorId,
                    'Transaction entry posted before settlement.'
                );
            }

            $settled = (
                $entry['status']
                    ?? ''
            ) === 'settled'
                ? $entry
                : $this->settleEntry(
                    $entry,
                    $actorId,
                    $reason !== ''
                        ? $reason
                        : 'Transaction settled.'
                );

            $settledEntries[] =
                $settled;

            $previousEntry =
                $settled;
        }

        return [
            'transaction_id' =>
                $validation[
                    'transaction_id'
                ],

            'entry_count' =>
                count($settledEntries),

            'entries' =>
                $settledEntries,

            'transaction_validation' =>
                $this->validateTransaction(
                    $settledEntries
                ),

            'chain_validation' =>
                $this->verifyChain(
                    $settledEntries
                ),

            'settled_at' =>
                gmdate('c'),

            'settled_by' =>
                $actorId,
        ];
    }

    /**
     * Reverse an entire balanced transaction.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function reverseTransaction(
        array $entries,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Transaction reversal requires a reason.'
            );
        }

        $validation =
            $this->validateTransaction(
                $entries
            );

        if (
            ($validation['balanced'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Unbalanced transaction cannot be reversed as a group.'
            );
        }

        usort(
            $entries,
            static fn (
                array $left,
                array $right
            ): int =>
                (int)(
                    $left['sequence']
                        ?? 0
                )
                <=>
                (int)(
                    $right['sequence']
                        ?? 0
                )
        );

        $reversedOriginals = [];
        $reversalEntries = [];
        $previousEntry = end($entries);

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $result = $this->reverseEntry(
                $entry,
                $actorId,
                $reason,
                is_array($previousEntry)
                    ? $previousEntry
                    : null
            );

            $reversedOriginals[] =
                $result['original_entry'];

            $reversalEntries[] =
                $result['reversal_entry'];

            $previousEntry =
                $result['reversal_entry'];
        }

        return [
            'transaction_id' =>
                $validation[
                    'transaction_id'
                ],

            'reversed_original_entries' =>
                $reversedOriginals,

            'reversal_entries' =>
                $reversalEntries,

            'reversal_validation' =>
                $this->validateTransaction(
                    $reversalEntries
                ),

            'chain_validation' =>
                $this->verifyChain(
                    $reversalEntries
                ),

            'reversed_at' =>
                gmdate('c'),

            'reversed_by' =>
                $actorId,

            'reason' =>
                $reason,
        ];
    }

    /**
     * Verify one ordered chain of ledger entries.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function verifyChain(
        array $entries
    ): array {
        if ($entries === []) {
            return [
                'valid' => true,

                'entry_count' => 0,

                'break_count' => 0,

                'breaks' => [],
            ];
        }

        usort(
            $entries,
            static fn (
                array $left,
                array $right
            ): int =>
                (int)(
                    $left['sequence']
                        ?? 0
                )
                <=>
                (int)(
                    $right['sequence']
                        ?? 0
                )
        );

        $breaks = [];
        $previousHash = '';

        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                $breaks[] = [
                    'index' =>
                        $index,

                    'reason' =>
                        'invalid_entry',
                ];

                continue;
            }

            $storedHash = trim(
                (string)(
                    $entry['entry_hash']
                        ?? ''
                )
            );

            $calculatedHash =
                $this->calculateEntryHash(
                    $entry
                );

            if (
                $storedHash === ''
                || !hash_equals(
                    $storedHash,
                    $calculatedHash
                )
            ) {
                $breaks[] = [
                    'index' =>
                        $index,

                    'ledger_entry_id' =>
                        $entry[
                            'ledger_entry_id'
                        ] ?? null,

                    'reason' =>
                        'entry_hash_mismatch',

                    'stored_hash' =>
                        $storedHash,

                    'calculated_hash' =>
                        $calculatedHash,
                ];
            }

            $storedPreviousHash = trim(
                (string)(
                    $entry[
                        'previous_entry_hash'
                    ] ?? ''
                )
            );

            if (
                $index > 0
                && !hash_equals(
                    $previousHash,
                    $storedPreviousHash
                )
            ) {
                $breaks[] = [
                    'index' =>
                        $index,

                    'ledger_entry_id' =>
                        $entry[
                            'ledger_entry_id'
                        ] ?? null,

                    'reason' =>
                        'previous_hash_mismatch',

                    'expected_previous_hash' =>
                        $previousHash,

                    'stored_previous_hash' =>
                        $storedPreviousHash,
                ];
            }

            $previousHash =
                $storedHash !== ''
                    ? $storedHash
                    : $calculatedHash;
        }

        return [
            'valid' =>
                $breaks === [],

            'entry_count' =>
                count($entries),

            'break_count' =>
                count($breaks),

            'breaks' =>
                $breaks,

            'head_hash' =>
                $previousHash,
        ];
    }

    /**
     * Verify sequence continuity within one transaction.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function verifySequence(
        array $entries
    ): array {
        $sequences = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $sequence = (int)(
                $entry['sequence']
                    ?? 0
            );

            if ($sequence > 0) {
                $sequences[] =
                    $sequence;
            }
        }

        sort($sequences);

        $missing = [];
        $duplicates = [];
        $seen = [];

        foreach ($sequences as $sequence) {
            if (isset($seen[$sequence])) {
                $duplicates[] =
                    $sequence;
            }

            $seen[$sequence] = true;
        }

        if ($sequences !== []) {
            $maximum = max($sequences);

            for (
                $expected = 1;
                $expected <= $maximum;
                $expected++
            ) {
                if (!isset($seen[$expected])) {
                    $missing[] =
                        $expected;
                }
            }
        }

        return [
            'valid' =>
                $missing === []
                && $duplicates === [],

            'sequences' =>
                $sequences,

            'missing' =>
                array_values(
                    array_unique($missing)
                ),

            'duplicates' =>
                array_values(
                    array_unique($duplicates)
                ),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | BALANCES, ACCOUNT SUMMARIES, RECONCILIATION, AND REPORTS CONTINUE IN PART 3
    |--------------------------------------------------------------------------
    |
    | Do not close the class yet.
    |
    */    /**
     * Calculate balances across ledger entries.
     *
     * Balances are grouped by account and unit. Draft, pending, voided,
     * reversed, and archived entries may be included through options.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function calculateBalances(
        array $entries,
        array $options = []
    ): array {
        $options = array_replace(
            [
                'include_draft' => false,
                'include_pending' => false,
                'include_disputed' => true,
                'include_held' => true,
                'include_reversed' => false,
                'include_voided' => false,
                'include_archived' => false,
                'include_informational' => false,
                'account_id' => '',
                'account_class' => '',
                'scope' => '',
                'program_id' => '',
                'subject_id' => '',
                'subject_type' => '',
                'unit' => '',
                'effective_from' => null,
                'effective_to' => null,
                'group_by_account_class' => true,
                'deterministic' => true,
            ],
            $options
        );

        $acceptedStatuses = [
            'posted',
            'settled',
            'disputed',
            'held',
        ];

        if ($options['include_draft']) {
            $acceptedStatuses[] = 'draft';
        }

        if ($options['include_pending']) {
            $acceptedStatuses[] = 'pending';
        }

        if (!$options['include_disputed']) {
            $acceptedStatuses = array_values(
                array_diff(
                    $acceptedStatuses,
                    ['disputed']
                )
            );
        }

        if (!$options['include_held']) {
            $acceptedStatuses = array_values(
                array_diff(
                    $acceptedStatuses,
                    ['held']
                )
            );
        }

        if ($options['include_reversed']) {
            $acceptedStatuses[] = 'reversed';
        }

        if ($options['include_voided']) {
            $acceptedStatuses[] = 'voided';
        }

        if ($options['include_archived']) {
            $acceptedStatuses[] = 'archived';
        }

        $acceptedStatuses = array_values(
            array_unique($acceptedStatuses)
        );

        $balances = [];
        $classBalances = [];
        $unitTotals = [];
        $includedEntries = [];
        $excludedEntries = [];
        $entryCount = 0;

        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                $excludedEntries[] = [
                    'index' => $index,
                    'reason' => 'invalid_entry',
                ];

                continue;
            }

            if (
                !$this->entryMatchesBalanceOptions(
                    $entry,
                    $acceptedStatuses,
                    $options
                )
            ) {
                $excludedEntries[] = [
                    'index' => $index,

                    'ledger_entry_id' =>
                        $entry['ledger_entry_id']
                            ?? null,

                    'reason' =>
                        'filtered',
                ];

                continue;
            }

            $entryType = $this->normalizeEntryType(
                (string)(
                    $entry['entry_type']
                        ?? 'informational'
                )
            );

            if (
                $entryType === 'informational'
                && !$options[
                    'include_informational'
                ]
            ) {
                $excludedEntries[] = [
                    'index' => $index,

                    'ledger_entry_id' =>
                        $entry['ledger_entry_id']
                            ?? null,

                    'reason' =>
                        'informational_excluded',
                ];

                continue;
            }

            $accountId = trim(
                (string)(
                    $entry['account_id']
                        ?? ''
                )
            );

            if ($accountId === '') {
                $accountId = '__unassigned__';
            }

            $accountClass =
                $this->normalizeAccountClass(
                    (string)(
                        $entry['account_class']
                            ?? 'memorandum'
                    )
                );

            $unit = $this->normalizeUnit(
                (string)(
                    $entry['unit']
                        ?? 'none'
                )
            );

            $debit = $this->normalizeAmount(
                $entry['debit']
                    ?? 0,
                $unit
            );

            $credit = $this->normalizeAmount(
                $entry['credit']
                    ?? 0,
                $unit
            );

            $quantity = $this->normalizeAmount(
                $entry['quantity']
                    ?? 0,
                $unit
            );

            $net = $this->normalizeAmount(
                $credit - $debit,
                $unit
            );

            $balanceEffect =
                $this->accountBalanceEffect(
                    $accountClass,
                    $debit,
                    $credit,
                    $unit
                );

            $accountKey = $accountId
                . '|'
                . $unit;

            if (!isset($balances[$accountKey])) {
                $balances[$accountKey] = [
                    'account_id' =>
                        $accountId,

                    'account_class' =>
                        $accountClass,

                    'unit' =>
                        $unit,

                    'opening_balance' =>
                        0.0,

                    'debit_total' =>
                        0.0,

                    'credit_total' =>
                        0.0,

                    'net_total' =>
                        0.0,

                    'quantity_total' =>
                        0.0,

                    'balance' =>
                        0.0,

                    'entry_count' =>
                        0,

                    'first_effective_at' =>
                        null,

                    'last_effective_at' =>
                        null,

                    'transaction_ids' =>
                        [],
                ];
            }

            $balances[$accountKey][
                'debit_total'
            ] += $debit;

            $balances[$accountKey][
                'credit_total'
            ] += $credit;

            $balances[$accountKey][
                'net_total'
            ] += $net;

            $balances[$accountKey][
                'quantity_total'
            ] += $quantity;

            $balances[$accountKey][
                'balance'
            ] += $balanceEffect;

            $balances[$accountKey][
                'entry_count'
            ]++;

            $effectiveAt = trim(
                (string)(
                    $entry['effective_at']
                        ?? ''
                )
            );

            if ($effectiveAt !== '') {
                if (
                    $balances[$accountKey][
                        'first_effective_at'
                    ] === null
                    || strcmp(
                        $effectiveAt,
                        (string)$balances[
                            $accountKey
                        ]['first_effective_at']
                    ) < 0
                ) {
                    $balances[$accountKey][
                        'first_effective_at'
                    ] = $effectiveAt;
                }

                if (
                    $balances[$accountKey][
                        'last_effective_at'
                    ] === null
                    || strcmp(
                        $effectiveAt,
                        (string)$balances[
                            $accountKey
                        ]['last_effective_at']
                    ) > 0
                ) {
                    $balances[$accountKey][
                        'last_effective_at'
                    ] = $effectiveAt;
                }
            }

            $transactionId = trim(
                (string)(
                    $entry['transaction_id']
                        ?? ''
                )
            );

            if ($transactionId !== '') {
                $balances[$accountKey][
                    'transaction_ids'
                ][$transactionId] = true;
            }

            $classKey = $accountClass
                . '|'
                . $unit;

            if (!isset($classBalances[$classKey])) {
                $classBalances[$classKey] = [
                    'account_class' =>
                        $accountClass,

                    'unit' =>
                        $unit,

                    'debit_total' =>
                        0.0,

                    'credit_total' =>
                        0.0,

                    'net_total' =>
                        0.0,

                    'balance' =>
                        0.0,

                    'entry_count' =>
                        0,

                    'account_ids' =>
                        [],
                ];
            }

            $classBalances[$classKey][
                'debit_total'
            ] += $debit;

            $classBalances[$classKey][
                'credit_total'
            ] += $credit;

            $classBalances[$classKey][
                'net_total'
            ] += $net;

            $classBalances[$classKey][
                'balance'
            ] += $balanceEffect;

            $classBalances[$classKey][
                'entry_count'
            ]++;

            $classBalances[$classKey][
                'account_ids'
            ][$accountId] = true;

            if (!isset($unitTotals[$unit])) {
                $unitTotals[$unit] = [
                    'unit' =>
                        $unit,

                    'debit_total' =>
                        0.0,

                    'credit_total' =>
                        0.0,

                    'net_total' =>
                        0.0,

                    'entry_count' =>
                        0,

                    'balanced' =>
                        true,
                ];
            }

            $unitTotals[$unit][
                'debit_total'
            ] += $debit;

            $unitTotals[$unit][
                'credit_total'
            ] += $credit;

            $unitTotals[$unit][
                'net_total'
            ] += $net;

            $unitTotals[$unit][
                'entry_count'
            ]++;

            $includedEntries[] =
                $entry['ledger_entry_id']
                    ?? null;

            $entryCount++;
        }

        foreach ($balances as &$balance) {
            $unit = (string)$balance['unit'];

            foreach (
                [
                    'opening_balance',
                    'debit_total',
                    'credit_total',
                    'net_total',
                    'quantity_total',
                    'balance',
                ]
                as $field
            ) {
                $balance[$field] =
                    $this->normalizeAmount(
                        $balance[$field],
                        $unit
                    );
            }

            $balance['transaction_ids'] =
                array_keys(
                    $balance[
                        'transaction_ids'
                    ]
                );

            sort(
                $balance['transaction_ids']
            );
        }

        unset($balance);

        foreach ($classBalances as &$classBalance) {
            $unit = (string)(
                $classBalance['unit']
            );

            foreach (
                [
                    'debit_total',
                    'credit_total',
                    'net_total',
                    'balance',
                ]
                as $field
            ) {
                $classBalance[$field] =
                    $this->normalizeAmount(
                        $classBalance[$field],
                        $unit
                    );
            }

            $classBalance['account_ids'] =
                array_keys(
                    $classBalance[
                        'account_ids'
                    ]
                );

            sort(
                $classBalance['account_ids']
            );

            $classBalance['account_count'] =
                count(
                    $classBalance[
                        'account_ids'
                    ]
                );
        }

        unset($classBalance);

        foreach ($unitTotals as &$totals) {
            $unit = (string)$totals['unit'];

            $totals['debit_total'] =
                $this->normalizeAmount(
                    $totals['debit_total'],
                    $unit
                );

            $totals['credit_total'] =
                $this->normalizeAmount(
                    $totals['credit_total'],
                    $unit
                );

            $totals['net_total'] =
                $this->normalizeAmount(
                    $totals['net_total'],
                    $unit
                );

            $totals['balanced'] =
                abs(
                    $totals['credit_total']
                    - $totals['debit_total']
                ) <= $this->tolerance($unit);
        }

        unset($totals);

        if ($options['deterministic']) {
            ksort($balances);
            ksort($classBalances);
            ksort($unitTotals);
        }

        return [
            'generated_at' =>
                gmdate('c'),

            'entry_count' =>
                $entryCount,

            'excluded_entry_count' =>
                count($excludedEntries),

            'account_count' =>
                count($balances),

            'account_class_count' =>
                count($classBalances),

            'unit_count' =>
                count($unitTotals),

            'accepted_statuses' =>
                $acceptedStatuses,

            'filters' => [
                'account_id' =>
                    trim(
                        (string)$options[
                            'account_id'
                        ]
                    ),

                'account_class' =>
                    trim(
                        (string)$options[
                            'account_class'
                        ]
                    ),

                'scope' =>
                    trim(
                        (string)$options[
                            'scope'
                        ]
                    ),

                'program_id' =>
                    trim(
                        (string)$options[
                            'program_id'
                        ]
                    ),

                'subject_id' =>
                    trim(
                        (string)$options[
                            'subject_id'
                        ]
                    ),

                'subject_type' =>
                    trim(
                        (string)$options[
                            'subject_type'
                        ]
                    ),

                'unit' =>
                    trim(
                        (string)$options[
                            'unit'
                        ]
                    ),

                'effective_from' =>
                    $this->normalizeDate(
                        $options[
                            'effective_from'
                        ]
                    ),

                'effective_to' =>
                    $this->normalizeDate(
                        $options[
                            'effective_to'
                        ]
                    ),
            ],

            'balances' =>
                array_values($balances),

            'account_class_balances' =>
                $options[
                    'group_by_account_class'
                ]
                    ? array_values(
                        $classBalances
                    )
                    : [],

            'unit_totals' =>
                array_values($unitTotals),

            'included_entry_ids' =>
                array_values(
                    array_filter(
                        $includedEntries,
                        static fn (
                            mixed $value
                        ): bool =>
                            $value !== null
                            && $value !== ''
                    )
                ),

            'excluded_entries' =>
                $excludedEntries,

            'balanced_by_unit' =>
                count(
                    array_filter(
                        $unitTotals,
                        static fn (
                            array $totals
                        ): bool =>
                            (
                                $totals[
                                    'balanced'
                                ] ?? false
                            ) !== true
                    )
                ) === 0,
        ];
    }

    /**
     * Calculate one account balance.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function accountBalance(
        array $entries,
        string $accountId,
        string $unit = '',
        array $options = []
    ): array {
        $accountId = trim($accountId);

        if ($accountId === '') {
            throw new InvalidArgumentException(
                'Account balance requires account identifier.'
            );
        }

        $options['account_id'] =
            $accountId;

        if (trim($unit) !== '') {
            $options['unit'] =
                $this->normalizeUnit($unit);
        }

        $report = $this->calculateBalances(
            $entries,
            $options
        );

        $matchingBalances = [];

        foreach (
            $report['balances']
                ?? []
            as $balance
        ) {
            if (
                is_array($balance)
                && (
                    $balance['account_id']
                        ?? ''
                ) === $accountId
            ) {
                $matchingBalances[] =
                    $balance;
            }
        }

        return [
            'generated_at' =>
                $report['generated_at'],

            'account_id' =>
                $accountId,

            'requested_unit' =>
                trim($unit) !== ''
                    ? $this->normalizeUnit(
                        $unit
                    )
                    : null,

            'balance_count' =>
                count($matchingBalances),

            'balances' =>
                $matchingBalances,

            'entry_count' =>
                array_sum(
                    array_map(
                        static fn (
                            array $balance
                        ): int =>
                            (int)(
                                $balance[
                                    'entry_count'
                                ] ?? 0
                            ),
                        $matchingBalances
                    )
                ),

            'filters' =>
                $report['filters'],
        ];
    }

    /**
     * Produce chronological running balances for one account.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function runningBalance(
        array $entries,
        string $accountId,
        string $unit,
        float|int|string $openingBalance = 0,
        array $options = []
    ): array {
        $accountId = trim($accountId);

        if ($accountId === '') {
            throw new InvalidArgumentException(
                'Running balance requires account identifier.'
            );
        }

        $unit = $this->normalizeUnit(
            $unit
        );

        $openingBalance =
            $this->normalizeAmount(
                $openingBalance,
                $unit
            );

        $acceptedStatuses = $options[
            'statuses'
        ] ?? [
            'posted',
            'settled',
            'disputed',
            'held',
        ];

        $acceptedStatuses =
            $this->normalizeStringList(
                $acceptedStatuses
            );

        $filtered = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (
                trim(
                    (string)(
                        $entry['account_id']
                            ?? ''
                    )
                ) !== $accountId
            ) {
                continue;
            }

            if (
                $this->normalizeUnit(
                    (string)(
                        $entry['unit']
                            ?? 'none'
                    )
                ) !== $unit
            ) {
                continue;
            }

            $status =
                $this->normalizeStatus(
                    (string)(
                        $entry['status']
                            ?? 'draft'
                    )
                );

            if (
                !in_array(
                    $status,
                    $acceptedStatuses,
                    true
                )
            ) {
                continue;
            }

            if (
                !$this->entryWithinDateRange(
                    $entry,
                    $options[
                        'effective_from'
                    ] ?? null,
                    $options[
                        'effective_to'
                    ] ?? null
                )
            ) {
                continue;
            }

            $filtered[] = $entry;
        }

        usort(
            $filtered,
            static function (
                array $left,
                array $right
            ): int {
                $dateComparison = strcmp(
                    (string)(
                        $left['effective_at']
                            ?? ''
                    ),
                    (string)(
                        $right['effective_at']
                            ?? ''
                    )
                );

                if ($dateComparison !== 0) {
                    return $dateComparison;
                }

                $transactionComparison = strcmp(
                    (string)(
                        $left['transaction_id']
                            ?? ''
                    ),
                    (string)(
                        $right['transaction_id']
                            ?? ''
                    )
                );

                if (
                    $transactionComparison
                    !== 0
                ) {
                    return $transactionComparison;
                }

                return (
                    (int)(
                        $left['sequence']
                            ?? 0
                    )
                ) <=> (
                    (int)(
                        $right['sequence']
                            ?? 0
                    )
                );
            }
        );

        $balance = $openingBalance;
        $rows = [];
        $debitTotal = 0.0;
        $creditTotal = 0.0;

        foreach ($filtered as $entry) {
            $accountClass =
                $this->normalizeAccountClass(
                    (string)(
                        $entry['account_class']
                            ?? 'memorandum'
                    )
                );

            $debit = $this->normalizeAmount(
                $entry['debit']
                    ?? 0,
                $unit
            );

            $credit = $this->normalizeAmount(
                $entry['credit']
                    ?? 0,
                $unit
            );

            $effect =
                $this->accountBalanceEffect(
                    $accountClass,
                    $debit,
                    $credit,
                    $unit
                );

            $balance =
                $this->normalizeAmount(
                    $balance + $effect,
                    $unit
                );

            $debitTotal += $debit;
            $creditTotal += $credit;

            $rows[] = [
                'ledger_entry_id' =>
                    $entry[
                        'ledger_entry_id'
                    ] ?? null,

                'transaction_id' =>
                    $entry[
                        'transaction_id'
                    ] ?? null,

                'sequence' =>
                    (int)(
                        $entry['sequence']
                            ?? 0
                    ),

                'effective_at' =>
                    $entry['effective_at']
                        ?? null,

                'entry_type' =>
                    $entry['entry_type']
                        ?? null,

                'status' =>
                    $entry['status']
                        ?? null,

                'description' =>
                    $entry['description']
                        ?? '',

                'debit' =>
                    $debit,

                'credit' =>
                    $credit,

                'effect' =>
                    $effect,

                'balance' =>
                    $balance,

                'unit' =>
                    $unit,

                'entry_hash' =>
                    $entry['entry_hash']
                        ?? null,
            ];
        }

        return [
            'generated_at' =>
                gmdate('c'),

            'account_id' =>
                $accountId,

            'unit' =>
                $unit,

            'opening_balance' =>
                $openingBalance,

            'closing_balance' =>
                $balance,

            'debit_total' =>
                $this->normalizeAmount(
                    $debitTotal,
                    $unit
                ),

            'credit_total' =>
                $this->normalizeAmount(
                    $creditTotal,
                    $unit
                ),

            'entry_count' =>
                count($rows),

            'statuses' =>
                $acceptedStatuses,

            'effective_from' =>
                $this->normalizeDate(
                    $options[
                        'effective_from'
                    ] ?? null
                ),

            'effective_to' =>
                $this->normalizeDate(
                    $options[
                        'effective_to'
                    ] ?? null
                ),

            'rows' =>
                $rows,

            'checksum' => hash(
                'sha256',
                json_encode(
                    $this->normalizeForHash(
                        $rows
                    ),
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION
                ) ?: ''
            ),
        ];
    }

    /**
     * Group entries by transaction identifier.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function groupTransactions(
        array $entries,
        array $options = []
    ): array {
        $groups = [];
        $ungrouped = [];

        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                $ungrouped[] = [
                    'index' => $index,
                    'reason' => 'invalid_entry',
                ];

                continue;
            }

            $transactionId = trim(
                (string)(
                    $entry['transaction_id']
                        ?? ''
                )
            );

            if ($transactionId === '') {
                $ungrouped[] = [
                    'index' => $index,

                    'ledger_entry_id' =>
                        $entry['ledger_entry_id']
                            ?? null,

                    'reason' =>
                        'missing_transaction_id',
                ];

                continue;
            }

            $groups[$transactionId][] =
                $entry;
        }

        $transactions = [];

        foreach (
            $groups
            as $transactionId => $groupEntries
        ) {
            usort(
                $groupEntries,
                static fn (
                    array $left,
                    array $right
                ): int =>
                    (
                        (int)(
                            $left['sequence']
                                ?? 0
                        )
                    ) <=> (
                        (int)(
                            $right['sequence']
                                ?? 0
                        )
                    )
            );

            $validation =
                $this->validateTransaction(
                    $groupEntries
                );

            $chainValidation =
                $this->verifyChain(
                    $groupEntries
                );

            $sequenceValidation =
                $this->verifySequence(
                    $groupEntries
                );

            $statuses = [];
            $entryTypes = [];
            $accountIds = [];
            $units = [];
            $firstEffectiveAt = null;
            $lastEffectiveAt = null;

            foreach ($groupEntries as $entry) {
                $status = trim(
                    (string)(
                        $entry['status']
                            ?? ''
                    )
                );

                if ($status !== '') {
                    $statuses[$status] =
                        ($statuses[$status]
                            ?? 0) + 1;
                }

                $entryType = trim(
                    (string)(
                        $entry['entry_type']
                            ?? ''
                    )
                );

                if ($entryType !== '') {
                    $entryTypes[$entryType] =
                        ($entryTypes[$entryType]
                            ?? 0) + 1;
                }

                $accountId = trim(
                    (string)(
                        $entry['account_id']
                            ?? ''
                    )
                );

                if ($accountId !== '') {
                    $accountIds[$accountId] =
                        true;
                }

                $unit = trim(
                    (string)(
                        $entry['unit']
                            ?? ''
                    )
                );

                if ($unit !== '') {
                    $units[$unit] = true;
                }

                $effectiveAt = trim(
                    (string)(
                        $entry['effective_at']
                            ?? ''
                    )
                );

                if ($effectiveAt !== '') {
                    if (
                        $firstEffectiveAt
                        === null
                        || strcmp(
                            $effectiveAt,
                            $firstEffectiveAt
                        ) < 0
                    ) {
                        $firstEffectiveAt =
                            $effectiveAt;
                    }

                    if (
                        $lastEffectiveAt
                        === null
                        || strcmp(
                            $effectiveAt,
                            $lastEffectiveAt
                        ) > 0
                    ) {
                        $lastEffectiveAt =
                            $effectiveAt;
                    }
                }
            }

            ksort($statuses);
            ksort($entryTypes);
            ksort($accountIds);
            ksort($units);

            $transactions[] = [
                'transaction_id' =>
                    $transactionId,

                'entry_count' =>
                    count($groupEntries),

                'entries' =>
                    $groupEntries,

                'statuses' =>
                    $statuses,

                'entry_types' =>
                    $entryTypes,

                'account_ids' =>
                    array_keys($accountIds),

                'units' =>
                    array_keys($units),

                'first_effective_at' =>
                    $firstEffectiveAt,

                'last_effective_at' =>
                    $lastEffectiveAt,

                'balanced' =>
                    $validation['balanced']
                        ?? false,

                'valid' =>
                    $validation['valid']
                        ?? false,

                'chain_valid' =>
                    $chainValidation['valid']
                        ?? false,

                'sequence_valid' =>
                    $sequenceValidation['valid']
                        ?? false,

                'validation' =>
                    $validation,

                'chain_validation' =>
                    $chainValidation,

                'sequence_validation' =>
                    $sequenceValidation,

                'checksum' => hash(
                    'sha256',
                    implode(
                        '|',
                        array_map(
                            static fn (
                                array $entry
                            ): string =>
                                (string)(
                                    $entry[
                                        'entry_hash'
                                    ] ?? ''
                                ),
                            $groupEntries
                        )
                    )
                ),
            ];
        }

        usort(
            $transactions,
            static fn (
                array $left,
                array $right
            ): int =>
                strcmp(
                    (string)(
                        $left[
                            'transaction_id'
                        ] ?? ''
                    ),
                    (string)(
                        $right[
                            'transaction_id'
                        ] ?? ''
                    )
                )
        );

        return [
            'generated_at' =>
                gmdate('c'),

            'transaction_count' =>
                count($transactions),

            'entry_count' =>
                count(
                    array_filter(
                        $entries,
                        'is_array'
                    )
                ),

            'ungrouped_count' =>
                count($ungrouped),

            'valid_transaction_count' =>
                count(
                    array_filter(
                        $transactions,
                        static fn (
                            array $transaction
                        ): bool =>
                            (
                                $transaction['valid']
                                    ?? false
                            ) === true
                    )
                ),

            'balanced_transaction_count' =>
                count(
                    array_filter(
                        $transactions,
                        static fn (
                            array $transaction
                        ): bool =>
                            (
                                $transaction[
                                    'balanced'
                                ] ?? false
                            ) === true
                    )
                ),

            'transactions' =>
                $transactions,

            'ungrouped' =>
                $ungrouped,
        ];
    }

    /**
     * Summarize one transaction group.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function transactionSummary(
        array $entries
    ): array {
        $validation =
            $this->validateTransaction(
                $entries
            );

        $chainValidation =
            $this->verifyChain(
                $entries
            );

        $sequenceValidation =
            $this->verifySequence(
                $entries
            );

        $accounts = [];
        $subjects = [];
        $programs = [];
        $decisions = [];
        $workflows = [];
        $entryTypes = [];
        $statuses = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $accountId = trim(
                (string)(
                    $entry['account_id']
                        ?? ''
                )
            );

            if ($accountId !== '') {
                $accounts[$accountId] =
                    true;
            }

            $subjectId = trim(
                (string)(
                    $entry['subject_id']
                        ?? ''
                )
            );

            if ($subjectId !== '') {
                $subjectType = trim(
                    (string)(
                        $entry['subject_type']
                            ?? 'entity'
                    )
                );

                $subjects[
                    $subjectType
                    . ':'
                    . $subjectId
                ] = [
                    'subject_id' =>
                        $subjectId,

                    'subject_type' =>
                        $subjectType,
                ];
            }

            foreach (
                [
                    'program_id' =>
                        &$programs,

                    'decision_id' =>
                        &$decisions,

                    'workflow_id' =>
                        &$workflows,
                ]
                as $field => &$collection
            ) {
                $value = trim(
                    (string)(
                        $entry[$field]
                            ?? ''
                    )
                );

                if ($value !== '') {
                    $collection[$value] =
                        true;
                }
            }

            unset($collection);

            $entryType = trim(
                (string)(
                    $entry['entry_type']
                        ?? ''
                )
            );

            if ($entryType !== '') {
                $entryTypes[$entryType] =
                    ($entryTypes[$entryType]
                        ?? 0) + 1;
            }

            $status = trim(
                (string)(
                    $entry['status']
                        ?? ''
                )
            );

            if ($status !== '') {
                $statuses[$status] =
                    ($statuses[$status]
                        ?? 0) + 1;
            }
        }

        ksort($accounts);
        ksort($subjects);
        ksort($programs);
        ksort($decisions);
        ksort($workflows);
        ksort($entryTypes);
        ksort($statuses);

        return [
            'transaction_id' =>
                $validation[
                    'transaction_id'
                ] ?? null,

            'entry_count' =>
                count($entries),

            'balanced' =>
                $validation['balanced']
                    ?? false,

            'valid' =>
                $validation['valid']
                    ?? false,

            'chain_valid' =>
                $chainValidation['valid']
                    ?? false,

            'sequence_valid' =>
                $sequenceValidation['valid']
                    ?? false,

            'unit_totals' =>
                $validation['unit_totals']
                    ?? [],

            'accounts' =>
                array_keys($accounts),

            'subjects' =>
                array_values($subjects),

            'program_ids' =>
                array_keys($programs),

            'decision_ids' =>
                array_keys($decisions),

            'workflow_ids' =>
                array_keys($workflows),

            'entry_types' =>
                $entryTypes,

            'statuses' =>
                $statuses,

            'validation' =>
                $validation,

            'chain_validation' =>
                $chainValidation,

            'sequence_validation' =>
                $sequenceValidation,
        ];
    }

    /**
     * Determine how debit and credit affect an account's natural balance.
     */
    private function accountBalanceEffect(
        string $accountClass,
        float $debit,
        float $credit,
        string $unit
    ): float {
        $accountClass =
            $this->normalizeAccountClass(
                $accountClass
            );

        $debitNormalClasses = [
            'asset',
            'expense',
            'contribution',
            'restricted_fund',
            'unrestricted_fund',
            'clearing',
            'memorandum',
        ];

        $effect = in_array(
            $accountClass,
            $debitNormalClasses,
            true
        )
            ? $debit - $credit
            : $credit - $debit;

        return $this->normalizeAmount(
            $effect,
            $unit
        );
    }

    /**
     * Determine whether an entry passes balance filters.
     *
     * @param array<int,string> $acceptedStatuses
     */
    private function entryMatchesBalanceOptions(
        array $entry,
        array $acceptedStatuses,
        array $options
    ): bool {
        $status = $this->normalizeStatus(
            (string)(
                $entry['status']
                    ?? 'draft'
            )
        );

        if (
            !in_array(
                $status,
                $acceptedStatuses,
                true
            )
        ) {
            return false;
        }

        $fieldFilters = [
            'account_id',
            'account_class',
            'scope',
            'program_id',
            'subject_id',
            'subject_type',
        ];

        foreach ($fieldFilters as $field) {
            $expected = trim(
                (string)(
                    $options[$field]
                        ?? ''
                )
            );

            if ($expected === '') {
                continue;
            }

            $actual = trim(
                (string)(
                    $entry[$field]
                        ?? ''
                )
            );

            if ($actual !== $expected) {
                return false;
            }
        }

        $requestedUnit = trim(
            (string)(
                $options['unit']
                    ?? ''
            )
        );

        if (
            $requestedUnit !== ''
            && $this->normalizeUnit(
                (string)(
                    $entry['unit']
                        ?? 'none'
                )
            ) !== $this->normalizeUnit(
                $requestedUnit
            )
        ) {
            return false;
        }

        return $this->entryWithinDateRange(
            $entry,
            $options['effective_from']
                ?? null,
            $options['effective_to']
                ?? null
        );
    }

    /**
     * Determine whether an entry falls within an effective-date range.
     */
    private function entryWithinDateRange(
        array $entry,
        mixed $effectiveFrom,
        mixed $effectiveTo
    ): bool {
        $entryDate = trim(
            (string)(
                $entry['effective_at']
                    ?? ''
            )
        );

        if ($entryDate === '') {
            return true;
        }

        $entryTimestamp = strtotime(
            $entryDate
        );

        if ($entryTimestamp === false) {
            return false;
        }

        $from = $this->normalizeDate(
            $effectiveFrom
        );

        if ($from !== null) {
            $fromTimestamp = strtotime($from);

            if (
                $fromTimestamp !== false
                && $entryTimestamp
                    < $fromTimestamp
            ) {
                return false;
            }
        }

        $to = $this->normalizeDate(
            $effectiveTo
        );

        if ($to !== null) {
            $toTimestamp = strtotime($to);

            if (
                $toTimestamp !== false
                && $entryTimestamp
                    > $toTimestamp
            ) {
                return false;
            }
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | PART 3B CONTINUES WITH RECONCILIATION AND RESTRICTED-FUND REPORTING
    |--------------------------------------------------------------------------
    |
    | Do not close the class yet.
    |
    */    /**
     * Reconcile ledger entries against an external statement or control set.
     *
     * External records should contain an external_reference, amount or
     * debit/credit values, unit, and optional effective_at date.
     *
     * @param array<int,array<string,mixed>> $entries
     * @param array<int,array<string,mixed>> $externalRecords
     *
     * @return array<string,mixed>
     */
    public function reconcile(
        array $entries,
        array $externalRecords,
        array $options = []
    ): array {
        $options = array_replace(
            [
                'amount_tolerance' => null,
                'date_tolerance_days' => 3,
                'require_unit_match' => true,
                'require_reference_match' => false,
                'include_statuses' => [
                    'posted',
                    'settled',
                    'disputed',
                    'held',
                ],
                'match_account_id' => false,
                'match_subject_id' => false,
                'allow_multiple_matches' => false,
            ],
            $options
        );

        $includeStatuses =
            $this->normalizeStringList(
                $options['include_statuses']
            );

        $eligibleEntries = [];

        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $status = $this->normalizeStatus(
                (string)(
                    $entry['status']
                        ?? 'draft'
                )
            );

            if (
                !in_array(
                    $status,
                    $includeStatuses,
                    true
                )
            ) {
                continue;
            }

            $eligibleEntries[$index] =
                $entry;
        }

        $matchedEntryIndexes = [];
        $matchedExternalIndexes = [];
        $matches = [];
        $ambiguous = [];

        foreach (
            $externalRecords
            as $externalIndex => $externalRecord
        ) {
            if (!is_array($externalRecord)) {
                continue;
            }

            $candidates = [];

            foreach (
                $eligibleEntries
                as $entryIndex => $entry
            ) {
                if (
                    isset(
                        $matchedEntryIndexes[
                            $entryIndex
                        ]
                    )
                    && !$options[
                        'allow_multiple_matches'
                    ]
                ) {
                    continue;
                }

                $comparison =
                    $this->compareReconciliationRecord(
                        $entry,
                        $externalRecord,
                        $options
                    );

                if (
                    ($comparison['match']
                        ?? false) === true
                ) {
                    $candidates[] = [
                        'entry_index' =>
                            $entryIndex,

                        'entry' =>
                            $entry,

                        'comparison' =>
                            $comparison,
                    ];
                }
            }

            usort(
                $candidates,
                static fn (
                    array $left,
                    array $right
                ): int =>
                    (
                        (float)(
                            $right[
                                'comparison'
                            ]['score'] ?? 0
                        )
                    ) <=> (
                        (float)(
                            $left[
                                'comparison'
                            ]['score'] ?? 0
                        )
                    )
            );

            if ($candidates === []) {
                continue;
            }

            if (
                count($candidates) > 1
                && abs(
                    (float)(
                        $candidates[0][
                            'comparison'
                        ]['score'] ?? 0
                    )
                    -
                    (float)(
                        $candidates[1][
                            'comparison'
                        ]['score'] ?? 0
                    )
                ) < 0.000001
            ) {
                $ambiguous[] = [
                    'external_index' =>
                        $externalIndex,

                    'external_record' =>
                        $externalRecord,

                    'candidate_count' =>
                        count($candidates),

                    'candidates' =>
                        array_map(
                            static fn (
                                array $candidate
                            ): array => [
                                'ledger_entry_id' =>
                                    $candidate['entry'][
                                        'ledger_entry_id'
                                    ] ?? null,

                                'score' =>
                                    $candidate[
                                        'comparison'
                                    ]['score'] ?? 0,

                                'reasons' =>
                                    $candidate[
                                        'comparison'
                                    ]['reasons'] ?? [],
                            ],
                            $candidates
                        ),
                ];

                continue;
            }

            $selected = $candidates[0];

            $entryIndex =
                $selected['entry_index'];

            $entry =
                $selected['entry'];

            $matchedEntryIndexes[
                $entryIndex
            ] = true;

            $matchedExternalIndexes[
                $externalIndex
            ] = true;

            $matches[] = [
                'ledger_entry_id' =>
                    $entry[
                        'ledger_entry_id'
                    ] ?? null,

                'transaction_id' =>
                    $entry[
                        'transaction_id'
                    ] ?? null,

                'external_index' =>
                    $externalIndex,

                'external_reference' =>
                    $externalRecord[
                        'external_reference'
                    ]
                    ?? $externalRecord[
                        'reference'
                    ]
                    ?? null,

                'score' =>
                    $selected[
                        'comparison'
                    ]['score'] ?? 0,

                'reasons' =>
                    $selected[
                        'comparison'
                    ]['reasons'] ?? [],

                'differences' =>
                    $selected[
                        'comparison'
                    ]['differences'] ?? [],
            ];
        }

        $unmatchedEntries = [];

        foreach (
            $eligibleEntries
            as $entryIndex => $entry
        ) {
            if (
                !isset(
                    $matchedEntryIndexes[
                        $entryIndex
                    ]
                )
            ) {
                $unmatchedEntries[] =
                    $entry;
            }
        }

        $unmatchedExternal = [];

        foreach (
            $externalRecords
            as $externalIndex => $externalRecord
        ) {
            if (
                !isset(
                    $matchedExternalIndexes[
                        $externalIndex
                    ]
                )
            ) {
                $unmatchedExternal[] = [
                    'external_index' =>
                        $externalIndex,

                    'record' =>
                        $externalRecord,
                ];
            }
        }

        return [
            'generated_at' =>
                gmdate('c'),

            'ledger_entry_count' =>
                count($eligibleEntries),

            'external_record_count' =>
                count($externalRecords),

            'matched_count' =>
                count($matches),

            'unmatched_ledger_count' =>
                count($unmatchedEntries),

            'unmatched_external_count' =>
                count($unmatchedExternal),

            'ambiguous_count' =>
                count($ambiguous),

            'reconciled' =>
                $unmatchedEntries === []
                && $unmatchedExternal === []
                && $ambiguous === [],

            'match_rate' =>
                count($externalRecords) > 0
                    ? round(
                        (
                            count($matches)
                            / count(
                                $externalRecords
                            )
                        ) * 100,
                        2
                    )
                    : 100.0,

            'matches' =>
                $matches,

            'ambiguous' =>
                $ambiguous,

            'unmatched_ledger_entries' =>
                $unmatchedEntries,

            'unmatched_external_records' =>
                $unmatchedExternal,

            'options' => [
                'date_tolerance_days' =>
                    (int)$options[
                        'date_tolerance_days'
                    ],

                'require_unit_match' =>
                    (bool)$options[
                        'require_unit_match'
                    ],

                'require_reference_match' =>
                    (bool)$options[
                        'require_reference_match'
                    ],

                'match_account_id' =>
                    (bool)$options[
                        'match_account_id'
                    ],

                'match_subject_id' =>
                    (bool)$options[
                        'match_subject_id'
                    ],
            ],
        ];
    }

    /**
     * Produce restricted-fund activity and available-balance reporting.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function restrictedFundReport(
        array $entries,
        string $programId = '',
        array $options = []
    ): array {
        $programId = trim($programId);

        $options = array_replace(
            [
                'include_pending' => false,
                'include_disputed' => true,
                'include_held' => true,
                'effective_from' => null,
                'effective_to' => null,
                'unit' => '',
            ],
            $options
        );

        $statuses = [
            'posted',
            'settled',
        ];

        if ($options['include_pending']) {
            $statuses[] = 'pending';
        }

        if ($options['include_disputed']) {
            $statuses[] = 'disputed';
        }

        if ($options['include_held']) {
            $statuses[] = 'held';
        }

        $funds = [];
        $includedEntryIds = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $accountClass =
                $this->normalizeAccountClass(
                    (string)(
                        $entry['account_class']
                            ?? 'memorandum'
                    )
                );

            if (
                $accountClass !==
                'restricted_fund'
            ) {
                continue;
            }

            $status = $this->normalizeStatus(
                (string)(
                    $entry['status']
                        ?? 'draft'
                )
            );

            if (
                !in_array(
                    $status,
                    $statuses,
                    true
                )
            ) {
                continue;
            }

            if (
                $programId !== ''
                && trim(
                    (string)(
                        $entry['program_id']
                            ?? ''
                    )
                ) !== $programId
            ) {
                continue;
            }

            if (
                !$this->entryWithinDateRange(
                    $entry,
                    $options[
                        'effective_from'
                    ],
                    $options[
                        'effective_to'
                    ]
                )
            ) {
                continue;
            }

            $unit = $this->normalizeUnit(
                (string)(
                    $entry['unit']
                        ?? 'none'
                )
            );

            if (
                trim(
                    (string)$options['unit']
                ) !== ''
                && $unit !==
                    $this->normalizeUnit(
                        (string)$options[
                            'unit'
                        ]
                    )
            ) {
                continue;
            }

            $resolvedProgramId = trim(
                (string)(
                    $entry['program_id']
                        ?? 'unassigned'
                )
            );

            if ($resolvedProgramId === '') {
                $resolvedProgramId =
                    'unassigned';
            }

            $accountId = trim(
                (string)(
                    $entry['account_id']
                        ?? '__unassigned__'
                )
            );

            $fundKey = $resolvedProgramId
                . '|'
                . $accountId
                . '|'
                . $unit;

            if (!isset($funds[$fundKey])) {
                $funds[$fundKey] = [
                    'program_id' =>
                        $resolvedProgramId,

                    'account_id' =>
                        $accountId,

                    'account_class' =>
                        'restricted_fund',

                    'unit' =>
                        $unit,

                    'contributions' =>
                        0.0,

                    'allocations' =>
                        0.0,

                    'releases' =>
                        0.0,

                    'expenses' =>
                        0.0,

                    'refunds' =>
                        0.0,

                    'adjustments' =>
                        0.0,

                    'debit_total' =>
                        0.0,

                    'credit_total' =>
                        0.0,

                    'available_balance' =>
                        0.0,

                    'entry_count' =>
                        0,

                    'restriction_tags' =>
                        [],
                ];
            }

            $entryType =
                $this->normalizeEntryType(
                    (string)(
                        $entry['entry_type']
                            ?? 'informational'
                    )
                );

            $debit = $this->normalizeAmount(
                $entry['debit']
                    ?? 0,
                $unit
            );

            $credit = $this->normalizeAmount(
                $entry['credit']
                    ?? 0,
                $unit
            );

            $funds[$fundKey][
                'debit_total'
            ] += $debit;

            $funds[$fundKey][
                'credit_total'
            ] += $credit;

            $funds[$fundKey][
                'entry_count'
            ]++;

            switch ($entryType) {
                case 'contribution':
                case 'income':
                case 'credit':
                case 'recognition':
                    $funds[$fundKey][
                        'contributions'
                    ] += $credit;
                    break;

                case 'allocation':
                case 'commitment':
                case 'obligation':
                    $funds[$fundKey][
                        'allocations'
                    ] += $debit;
                    break;

                case 'release':
                    $funds[$fundKey][
                        'releases'
                    ] += $credit;
                    break;

                case 'expense':
                case 'payment':
                case 'debit':
                case 'write_off':
                    $funds[$fundKey][
                        'expenses'
                    ] += $debit;
                    break;

                case 'refund':
                    $funds[$fundKey][
                        'refunds'
                    ] += $credit;
                    break;

                case 'adjustment':
                case 'reversal':
                    $funds[$fundKey][
                        'adjustments'
                    ] += $credit - $debit;
                    break;
            }

            foreach (
                $this->normalizeStringList(
                    $entry['restrictions']
                        ?? []
                )
                as $restriction
            ) {
                $funds[$fundKey][
                    'restriction_tags'
                ][$restriction] = true;
            }

            $includedEntryIds[] =
                $entry['ledger_entry_id']
                    ?? null;
        }

        $unitTotals = [];

        foreach ($funds as &$fund) {
            $unit = (string)$fund['unit'];

            $fund['available_balance'] =
                $this->normalizeAmount(
                    $fund['credit_total']
                    - $fund['debit_total'],
                    $unit
                );

            foreach (
                [
                    'contributions',
                    'allocations',
                    'releases',
                    'expenses',
                    'refunds',
                    'adjustments',
                    'debit_total',
                    'credit_total',
                ]
                as $field
            ) {
                $fund[$field] =
                    $this->normalizeAmount(
                        $fund[$field],
                        $unit
                    );
            }

            $fund['restriction_tags'] =
                array_keys(
                    $fund[
                        'restriction_tags'
                    ]
                );

            sort(
                $fund['restriction_tags']
            );

            if (!isset($unitTotals[$unit])) {
                $unitTotals[$unit] = [
                    'unit' =>
                        $unit,

                    'contributions' =>
                        0.0,

                    'allocations' =>
                        0.0,

                    'expenses' =>
                        0.0,

                    'available_balance' =>
                        0.0,

                    'fund_count' =>
                        0,
                ];
            }

            $unitTotals[$unit][
                'contributions'
            ] += $fund['contributions'];

            $unitTotals[$unit][
                'allocations'
            ] += $fund['allocations'];

            $unitTotals[$unit][
                'expenses'
            ] += $fund['expenses'];

            $unitTotals[$unit][
                'available_balance'
            ] += $fund[
                'available_balance'
            ];

            $unitTotals[$unit][
                'fund_count'
            ]++;
        }

        unset($fund);

        foreach ($unitTotals as &$totals) {
            $unit = (string)$totals['unit'];

            foreach (
                [
                    'contributions',
                    'allocations',
                    'expenses',
                    'available_balance',
                ]
                as $field
            ) {
                $totals[$field] =
                    $this->normalizeAmount(
                        $totals[$field],
                        $unit
                    );
            }
        }

        unset($totals);

        ksort($funds);
        ksort($unitTotals);

        return [
            'generated_at' =>
                gmdate('c'),

            'program_id' =>
                $programId !== ''
                    ? $programId
                    : null,

            'fund_count' =>
                count($funds),

            'entry_count' =>
                count(
                    array_filter(
                        $includedEntryIds,
                        static fn (
                            mixed $value
                        ): bool =>
                            $value !== null
                    )
                ),

            'funds' =>
                array_values($funds),

            'unit_totals' =>
                array_values($unitTotals),

            'included_entry_ids' =>
                array_values(
                    array_filter(
                        $includedEntryIds,
                        static fn (
                            mixed $value
                        ): bool =>
                            $value !== null
                            && $value !== ''
                    )
                ),

            'effective_from' =>
                $this->normalizeDate(
                    $options[
                        'effective_from'
                    ]
                ),

            'effective_to' =>
                $this->normalizeDate(
                    $options[
                        'effective_to'
                    ]
                ),
        ];
    }

    /**
     * Produce a DAD contribution and restricted-fund report.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function dadContributionReport(
        array $entries,
        array $options = []
    ): array {
        $options = array_replace(
            [
                'program_id' => 'dad',
                'unit' => 'CAD',
                'effective_from' => null,
                'effective_to' => null,
                'include_pending' => true,
                'include_anonymous' => true,
                'daily_target' => 1.0,
            ],
            $options
        );

        $programId = trim(
            (string)$options['program_id']
        );

        if ($programId === '') {
            $programId = 'dad';
        }

        $unit = $this->normalizeUnit(
            (string)$options['unit']
        );

        $contributions = [];
        $contributors = [];
        $statusTotals = [];
        $dailyTotals = [];
        $sourceTotals = [];
        $amountTotal = 0.0;
        $settledTotal = 0.0;
        $pendingTotal = 0.0;
        $anonymousTotal = 0.0;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (
                $this->normalizeEntryType(
                    (string)(
                        $entry['entry_type']
                            ?? 'informational'
                    )
                ) !== 'contribution'
            ) {
                continue;
            }

            if (
                trim(
                    (string)(
                        $entry['program_id']
                            ?? ''
                    )
                ) !== $programId
            ) {
                continue;
            }

            if (
                $this->normalizeUnit(
                    (string)(
                        $entry['unit']
                            ?? 'none'
                    )
                ) !== $unit
            ) {
                continue;
            }

            if (
                !$this->entryWithinDateRange(
                    $entry,
                    $options[
                        'effective_from'
                    ],
                    $options[
                        'effective_to'
                    ]
                )
            ) {
                continue;
            }

            $status = $this->normalizeStatus(
                (string)(
                    $entry['status']
                        ?? 'draft'
                )
            );

            if (
                $status === 'pending'
                && !$options[
                    'include_pending'
                ]
            ) {
                continue;
            }

            if (
                !in_array(
                    $status,
                    [
                        'pending',
                        'posted',
                        'settled',
                        'disputed',
                        'held',
                    ],
                    true
                )
            ) {
                continue;
            }

            $amount = $this->normalizeAmount(
                $entry['credit']
                    ?? $entry['quantity']
                    ?? 0,
                $unit
            );

            $metadata = is_array(
                $entry['metadata']
                    ?? null
            )
                ? $entry['metadata']
                : [];

            $contributionMetadata =
                is_array(
                    $metadata[
                        'contribution'
                    ] ?? null
                )
                    ? $metadata[
                        'contribution'
                    ]
                    : [];

            $anonymous = (bool)(
                $contributionMetadata[
                    'anonymous'
                ]
                ?? false
            );

            if (
                $anonymous
                && !$options[
                    'include_anonymous'
                ]
            ) {
                continue;
            }

            $contributorId = trim(
                (string)(
                    $contributionMetadata[
                        'contributor_id'
                    ]
                    ?? $entry['subject_id']
                    ?? ''
                )
            );

            if ($contributorId === '') {
                $contributorId =
                    $anonymous
                        ? 'anonymous'
                        : 'unattributed';
            }

            $effectiveAt = trim(
                (string)(
                    $entry['effective_at']
                        ?? $entry['created_at']
                        ?? ''
                )
            );

            $day = $effectiveAt !== ''
                ? gmdate(
                    'Y-m-d',
                    strtotime($effectiveAt)
                        ?: time()
                )
                : gmdate('Y-m-d');

            $source = trim(
                (string)(
                    $entry['metadata'][
                        'payment_source'
                    ]
                    ?? $entry['metadata'][
                        'source'
                    ]
                    ?? $entry[
                        'source_reference'
                    ]
                    ?? 'unspecified'
                )
            );

            if ($source === '') {
                $source = 'unspecified';
            }

            $amountTotal += $amount;

            if ($status === 'settled') {
                $settledTotal += $amount;
            }

            if ($status === 'pending') {
                $pendingTotal += $amount;
            }

            if ($anonymous) {
                $anonymousTotal += $amount;
            }

            $statusTotals[$status] =
                ($statusTotals[$status]
                    ?? 0.0) + $amount;

            $dailyTotals[$day] =
                ($dailyTotals[$day]
                    ?? 0.0) + $amount;

            $sourceTotals[$source] =
                ($sourceTotals[$source]
                    ?? 0.0) + $amount;

            if (!isset($contributors[$contributorId])) {
                $contributors[$contributorId] = [
                    'contributor_id' =>
                        $contributorId,

                    'anonymous' =>
                        $anonymous,

                    'contribution_count' =>
                        0,

                    'amount_total' =>
                        0.0,

                    'first_contribution_at' =>
                        null,

                    'last_contribution_at' =>
                        null,
                ];
            }

            $contributors[$contributorId][
                'contribution_count'
            ]++;

            $contributors[$contributorId][
                'amount_total'
            ] += $amount;

            if (
                $contributors[$contributorId][
                    'first_contribution_at'
                ] === null
                || strcmp(
                    $effectiveAt,
                    (string)$contributors[
                        $contributorId
                    ][
                        'first_contribution_at'
                    ]
                ) < 0
            ) {
                $contributors[$contributorId][
                    'first_contribution_at'
                ] = $effectiveAt;
            }

            if (
                $contributors[$contributorId][
                    'last_contribution_at'
                ] === null
                || strcmp(
                    $effectiveAt,
                    (string)$contributors[
                        $contributorId
                    ][
                        'last_contribution_at'
                    ]
                ) > 0
            ) {
                $contributors[$contributorId][
                    'last_contribution_at'
                ] = $effectiveAt;
            }

            $contributions[] = [
                'ledger_entry_id' =>
                    $entry[
                        'ledger_entry_id'
                    ] ?? null,

                'contribution_id' =>
                    $entry[
                        'contribution_id'
                    ] ?? null,

                'contributor_id' =>
                    $contributorId,

                'anonymous' =>
                    $anonymous,

                'amount' =>
                    $amount,

                'unit' =>
                    $unit,

                'status' =>
                    $status,

                'effective_at' =>
                    $effectiveAt,

                'day' =>
                    $day,

                'source' =>
                    $source,

                'external_reference' =>
                    $entry[
                        'external_reference'
                    ] ?? null,
            ];
        }

        foreach ($contributors as &$contributor) {
            $contributor['amount_total'] =
                $this->normalizeAmount(
                    $contributor[
                        'amount_total'
                    ],
                    $unit
                );
        }

        unset($contributor);

        foreach (
            [
                &$statusTotals,
                &$dailyTotals,
                &$sourceTotals,
            ]
            as &$totals
        ) {
            foreach ($totals as $key => $value) {
                $totals[$key] =
                    $this->normalizeAmount(
                        $value,
                        $unit
                    );
            }

            ksort($totals);
        }

        unset($totals);

        usort(
            $contributions,
            static fn (
                array $left,
                array $right
            ): int =>
                strcmp(
                    (string)(
                        $left['effective_at']
                            ?? ''
                    ),
                    (string)(
                        $right['effective_at']
                            ?? ''
                    )
                )
        );

        uasort(
            $contributors,
            static fn (
                array $left,
                array $right
            ): int =>
                (
                    (float)(
                        $right[
                            'amount_total'
                        ] ?? 0
                    )
                ) <=> (
                    (float)(
                        $left[
                            'amount_total'
                        ] ?? 0
                    )
                )
        );

        $dailyTarget =
            $this->normalizeAmount(
                $options['daily_target'],
                $unit
            );

        $equivalentContributionDays =
            $dailyTarget > 0
                ? round(
                    $amountTotal
                    / $dailyTarget,
                    2
                )
                : null;

        $restrictedFund =
            $this->restrictedFundReport(
                $entries,
                $programId,
                [
                    'include_pending' =>
                        (bool)$options[
                            'include_pending'
                        ],

                    'effective_from' =>
                        $options[
                            'effective_from'
                        ],

                    'effective_to' =>
                        $options[
                            'effective_to'
                        ],

                    'unit' =>
                        $unit,
                ]
            );

        return [
            'generated_at' =>
                gmdate('c'),

            'program_id' =>
                $programId,

            'unit' =>
                $unit,

            'contribution_count' =>
                count($contributions),

            'contributor_count' =>
                count($contributors),

            'amount_total' =>
                $this->normalizeAmount(
                    $amountTotal,
                    $unit
                ),

            'settled_total' =>
                $this->normalizeAmount(
                    $settledTotal,
                    $unit
                ),

            'pending_total' =>
                $this->normalizeAmount(
                    $pendingTotal,
                    $unit
                ),

            'anonymous_total' =>
                $this->normalizeAmount(
                    $anonymousTotal,
                    $unit
                ),

            'daily_target' =>
                $dailyTarget,

            'equivalent_contribution_days' =>
                $equivalentContributionDays,

            'status_totals' =>
                $statusTotals,

            'daily_totals' =>
                $dailyTotals,

            'source_totals' =>
                $sourceTotals,

            'contributors' =>
                array_values($contributors),

            'contributions' =>
                $contributions,

            'restricted_fund' =>
                $restrictedFund,

            'effective_from' =>
                $this->normalizeDate(
                    $options[
                        'effective_from'
                    ]
                ),

            'effective_to' =>
                $this->normalizeDate(
                    $options[
                        'effective_to'
                    ]
                ),
        ];
    }

    /**
     * Track allocations, commitments, obligations, releases, and spending.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function allocationReport(
        array $entries,
        array $options = []
    ): array {
        $options = array_replace(
            [
                'program_id' => '',
                'subject_id' => '',
                'unit' => '',
                'effective_from' => null,
                'effective_to' => null,
                'include_pending' => false,
            ],
            $options
        );

        $records = [];
        $groups = [];
        $statuses = [
            'posted',
            'settled',
            'disputed',
            'held',
        ];

        if ($options['include_pending']) {
            $statuses[] = 'pending';
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entryType =
                $this->normalizeEntryType(
                    (string)(
                        $entry['entry_type']
                            ?? 'informational'
                    )
                );

            if (
                !in_array(
                    $entryType,
                    [
                        'allocation',
                        'commitment',
                        'obligation',
                        'release',
                        'expense',
                        'payment',
                        'refund',
                        'adjustment',
                        'reversal',
                    ],
                    true
                )
            ) {
                continue;
            }

            $status = $this->normalizeStatus(
                (string)(
                    $entry['status']
                        ?? 'draft'
                )
            );

            if (
                !in_array(
                    $status,
                    $statuses,
                    true
                )
            ) {
                continue;
            }

            foreach (
                [
                    'program_id',
                    'subject_id',
                ]
                as $filterField
            ) {
                $expected = trim(
                    (string)(
                        $options[
                            $filterField
                        ] ?? ''
                    )
                );

                if (
                    $expected !== ''
                    && trim(
                        (string)(
                            $entry[
                                $filterField
                            ] ?? ''
                        )
                    ) !== $expected
                ) {
                    continue 2;
                }
            }

            if (
                !$this->entryWithinDateRange(
                    $entry,
                    $options[
                        'effective_from'
                    ],
                    $options[
                        'effective_to'
                    ]
                )
            ) {
                continue;
            }

            $unit = $this->normalizeUnit(
                (string)(
                    $entry['unit']
                        ?? 'none'
                )
            );

            if (
                trim(
                    (string)$options['unit']
                ) !== ''
                && $unit !==
                    $this->normalizeUnit(
                        (string)$options[
                            'unit'
                        ]
                    )
            ) {
                continue;
            }

            $programId = trim(
                (string)(
                    $entry['program_id']
                        ?? 'unassigned'
                )
            );

            $subjectId = trim(
                (string)(
                    $entry['subject_id']
                        ?? 'unassigned'
                )
            );

            $key = $programId
                . '|'
                . $subjectId
                . '|'
                . $unit;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'program_id' =>
                        $programId,

                    'subject_id' =>
                        $subjectId,

                    'unit' =>
                        $unit,

                    'allocated' =>
                        0.0,

                    'committed' =>
                        0.0,

                    'obligated' =>
                        0.0,

                    'released' =>
                        0.0,

                    'spent' =>
                        0.0,

                    'refunded' =>
                        0.0,

                    'adjusted' =>
                        0.0,

                    'remaining' =>
                        0.0,

                    'entry_count' =>
                        0,
                ];
            }

            $debit = $this->normalizeAmount(
                $entry['debit']
                    ?? 0,
                $unit
            );

            $credit = $this->normalizeAmount(
                $entry['credit']
                    ?? 0,
                $unit
            );

            switch ($entryType) {
                case 'allocation':
                    $groups[$key]['allocated'] +=
                        max($debit, $credit);
                    break;

                case 'commitment':
                    $groups[$key]['committed'] +=
                        max($debit, $credit);
                    break;

                case 'obligation':
                    $groups[$key]['obligated'] +=
                        max($debit, $credit);
                    break;

                case 'release':
                    $groups[$key]['released'] +=
                        max($debit, $credit);
                    break;

                case 'expense':
                case 'payment':
                    $groups[$key]['spent'] +=
                        $debit;
                    break;

                case 'refund':
                    $groups[$key]['refunded'] +=
                        $credit;
                    break;

                case 'adjustment':
                case 'reversal':
                    $groups[$key]['adjusted'] +=
                        $credit - $debit;
                    break;
            }

            $groups[$key]['entry_count']++;

            $records[] = [
                'ledger_entry_id' =>
                    $entry[
                        'ledger_entry_id'
                    ] ?? null,

                'program_id' =>
                    $programId,

                'subject_id' =>
                    $subjectId,

                'entry_type' =>
                    $entryType,

                'status' =>
                    $status,

                'debit' =>
                    $debit,

                'credit' =>
                    $credit,

                'unit' =>
                    $unit,

                'effective_at' =>
                    $entry['effective_at']
                        ?? null,
            ];
        }

        foreach ($groups as &$group) {
            $unit = (string)$group['unit'];

            $group['remaining'] =
                $group['allocated']
                + $group['released']
                + $group['refunded']
                + $group['adjusted']
                - $group['committed']
                - $group['obligated']
                - $group['spent'];

            foreach (
                [
                    'allocated',
                    'committed',
                    'obligated',
                    'released',
                    'spent',
                    'refunded',
                    'adjusted',
                    'remaining',
                ]
                as $field
            ) {
                $group[$field] =
                    $this->normalizeAmount(
                        $group[$field],
                        $unit
                    );
            }
        }

        unset($group);

        ksort($groups);

        return [
            'generated_at' =>
                gmdate('c'),

            'group_count' =>
                count($groups),

            'entry_count' =>
                count($records),

            'groups' =>
                array_values($groups),

            'entries' =>
                $records,

            'filters' => [
                'program_id' =>
                    trim(
                        (string)$options[
                            'program_id'
                        ]
                    ),

                'subject_id' =>
                    trim(
                        (string)$options[
                            'subject_id'
                        ]
                    ),

                'unit' =>
                    trim(
                        (string)$options[
                            'unit'
                        ]
                    ),

                'effective_from' =>
                    $this->normalizeDate(
                        $options[
                            'effective_from'
                        ]
                    ),

                'effective_to' =>
                    $this->normalizeDate(
                        $options[
                            'effective_to'
                        ]
                    ),
            ],
        ];
    }

    /**
     * Detect ledger exceptions requiring review.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function detectExceptions(
        array $entries,
        array $options = []
    ): array {
        $options = array_replace(
            [
                'large_amount_threshold' =>
                    10000.0,

                'stale_pending_days' =>
                    7,

                'duplicate_window_seconds' =>
                    300,

                'require_provenance_for_posted' =>
                    true,
            ],
            $options
        );

        $exceptions = [];
        $fingerprints = [];
        $now = time();

        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                $exceptions[] = [
                    'severity' =>
                        'error',

                    'type' =>
                        'invalid_entry',

                    'index' =>
                        $index,

                    'message' =>
                        'Ledger collection contains a non-array entry.',
                ];

                continue;
            }

            $entryId = $entry[
                'ledger_entry_id'
            ] ?? null;

            $validation =
                $this->validateEntry(
                    $entry
                );

            if (
                ($validation['valid'] ?? false)
                !== true
            ) {
                $exceptions[] = [
                    'severity' =>
                        'error',

                    'type' =>
                        'validation_failure',

                    'ledger_entry_id' =>
                        $entryId,

                    'message' =>
                        'Ledger entry validation failed.',

                    'details' =>
                        $validation['errors']
                            ?? [],
                ];
            }

            $unit = $this->normalizeUnit(
                (string)(
                    $entry['unit']
                        ?? 'none'
                )
            );

            $magnitude = max(
                abs(
                    (float)(
                        $entry['debit']
                            ?? 0
                    )
                ),
                abs(
                    (float)(
                        $entry['credit']
                            ?? 0
                    )
                ),
                abs(
                    (float)(
                        $entry['quantity']
                            ?? 0
                    )
                )
            );

            if (
                $magnitude >=
                (float)$options[
                    'large_amount_threshold'
                ]
            ) {
                $exceptions[] = [
                    'severity' =>
                        'warning',

                    'type' =>
                        'large_amount',

                    'ledger_entry_id' =>
                        $entryId,

                    'amount' =>
                        $this->normalizeAmount(
                            $magnitude,
                            $unit
                        ),

                    'unit' =>
                        $unit,

                    'message' =>
                        'Ledger entry exceeds the configured large-amount threshold.',
                ];
            }

            $status = $this->normalizeStatus(
                (string)(
                    $entry['status']
                        ?? 'draft'
                )
            );

            if ($status === 'pending') {
                $createdAt = strtotime(
                    (string)(
                        $entry['created_at']
                            ?? ''
                    )
                );

                if (
                    $createdAt !== false
                    && (
                        $now - $createdAt
                    ) > (
                        (int)$options[
                            'stale_pending_days'
                        ] * 86400
                    )
                ) {
                    $exceptions[] = [
                        'severity' =>
                            'warning',

                        'type' =>
                            'stale_pending',

                        'ledger_entry_id' =>
                            $entryId,

                        'age_days' =>
                            round(
                                (
                                    $now
                                    - $createdAt
                                ) / 86400,
                                2
                            ),

                        'message' =>
                            'Pending ledger entry exceeds the configured age.',
                    ];
                }
            }

            if (
                $options[
                    'require_provenance_for_posted'
                ]
                && in_array(
                    $status,
                    [
                        'posted',
                        'settled',
                    ],
                    true
                )
                && trim(
                    (string)(
                        $entry[
                            'provenance_id'
                        ] ?? ''
                    )
                ) === ''
                && trim(
                    (string)(
                        $entry[
                            'source_reference'
                        ] ?? ''
                    )
                ) === ''
            ) {
                $exceptions[] = [
                    'severity' =>
                        'warning',

                    'type' =>
                        'missing_provenance',

                    'ledger_entry_id' =>
                        $entryId,

                    'message' =>
                        'Posted ledger entry lacks provenance.',
                ];
            }

            if (
                trim(
                    (string)(
                        $entry['decision_id']
                            ?? ''
                    )
                ) === ''
                && in_array(
                    $entry['entry_type']
                        ?? '',
                    [
                        'allocation',
                        'write_off',
                        'valuation',
                        'reversal',
                    ],
                    true
                )
            ) {
                $exceptions[] = [
                    'severity' =>
                        'warning',

                    'type' =>
                        'missing_decision',

                    'ledger_entry_id' =>
                        $entryId,

                    'message' =>
                        'Consequential ledger entry lacks a decision reference.',
                ];
            }

            $effectiveTimestamp = strtotime(
                (string)(
                    $entry['effective_at']
                        ?? ''
                )
            );

            $roundedTime = $effectiveTimestamp
                !== false
                    ? (int)(
                        floor(
                            $effectiveTimestamp
                            / max(
                                1,
                                (int)$options[
                                    'duplicate_window_seconds'
                                ]
                            )
                        )
                    )
                    : 0;

            $fingerprint = hash(
                'sha256',
                implode(
                    '|',
                    [
                        trim(
                            (string)(
                                $entry[
                                    'account_id'
                                ] ?? ''
                            )
                        ),

                        trim(
                            (string)(
                                $entry[
                                    'external_reference'
                                ] ?? ''
                            )
                        ),

                        $unit,

                        (string)$this
                            ->normalizeAmount(
                                $entry['debit']
                                    ?? 0,
                                $unit
                            ),

                        (string)$this
                            ->normalizeAmount(
                                $entry['credit']
                                    ?? 0,
                                $unit
                            ),

                        (string)$roundedTime,
                    ]
                )
            );

            if (
                isset(
                    $fingerprints[
                        $fingerprint
                    ]
                )
            ) {
                $exceptions[] = [
                    'severity' =>
                        'warning',

                    'type' =>
                        'possible_duplicate',

                    'ledger_entry_id' =>
                        $entryId,

                    'possible_duplicate_of' =>
                        $fingerprints[
                            $fingerprint
                        ],

                    'message' =>
                        'Ledger entry resembles another entry within the duplicate window.',
                ];
            } else {
                $fingerprints[
                    $fingerprint
                ] = $entryId;
            }
        }

        $severityCounts = [];
        $typeCounts = [];

        foreach ($exceptions as $exception) {
            $severity = (string)(
                $exception['severity']
                    ?? 'warning'
            );

            $type = (string)(
                $exception['type']
                    ?? 'unknown'
            );

            $severityCounts[$severity] =
                ($severityCounts[$severity]
                    ?? 0) + 1;

            $typeCounts[$type] =
                ($typeCounts[$type]
                    ?? 0) + 1;
        }

        ksort($severityCounts);
        ksort($typeCounts);

        return [
            'generated_at' =>
                gmdate('c'),

            'entry_count' =>
                count($entries),

            'exception_count' =>
                count($exceptions),

            'clean' =>
                $exceptions === [],

            'severity_counts' =>
                $severityCounts,

            'type_counts' =>
                $typeCounts,

            'exceptions' =>
                $exceptions,
        ];
    }

    /**
     * Compare one ledger entry to one external reconciliation record.
     *
     * @return array<string,mixed>
     */
    private function compareReconciliationRecord(
        array $entry,
        array $externalRecord,
        array $options
    ): array {
        $reasons = [];
        $differences = [];
        $score = 0.0;

        $entryUnit = $this->normalizeUnit(
            (string)(
                $entry['unit']
                    ?? 'none'
            )
        );

        $externalUnit =
            $this->normalizeUnit(
                (string)(
                    $externalRecord['unit']
                        ?? $entryUnit
                )
            );

        if (
            $options['require_unit_match']
            && $entryUnit !== $externalUnit
        ) {
            return [
                'match' => false,
                'score' => 0.0,
                'reasons' => [],
                'differences' => [
                    'unit' => [
                        'ledger' =>
                            $entryUnit,

                        'external' =>
                            $externalUnit,
                    ],
                ],
            ];
        }

        if ($entryUnit === $externalUnit) {
            $score += 20.0;
            $reasons[] = 'unit_match';
        }

        $entryAmount = $this->normalizeAmount(
            abs(
                (float)(
                    $entry['net_amount']
                        ?? (
                            (
                                (float)(
                                    $entry['credit']
                                        ?? 0
                                )
                            )
                            -
                            (
                                (float)(
                                    $entry['debit']
                                        ?? 0
                                )
                            )
                        )
                )
            ),
            $entryUnit
        );

        $externalAmount =
            $this->normalizeAmount(
                abs(
                    (float)(
                        $externalRecord[
                            'amount'
                        ]
                        ?? (
                            (
                                (float)(
                                    $externalRecord[
                                        'credit'
                                    ] ?? 0
                                )
                            )
                            -
                            (
                                (float)(
                                    $externalRecord[
                                        'debit'
                                    ] ?? 0
                                )
                            )
                        )
                    )
                ),
                $externalUnit
            );

        $tolerance =
            $options['amount_tolerance']
            !== null
                ? abs(
                    (float)$options[
                        'amount_tolerance'
                    ]
                )
                : $this->tolerance(
                    $entryUnit
                );

        $amountDifference = abs(
            $entryAmount - $externalAmount
        );

        if ($amountDifference <= $tolerance) {
            $score += 40.0;
            $reasons[] = 'amount_match';
        } else {
            $differences['amount'] = [
                'ledger' =>
                    $entryAmount,

                'external' =>
                    $externalAmount,

                'difference' =>
                    $amountDifference,
            ];
        }

        $entryReference = trim(
            (string)(
                $entry[
                    'external_reference'
                ]
                ?? $entry[
                    'source_reference'
                ]
                ?? ''
            )
        );

        $externalReference = trim(
            (string)(
                $externalRecord[
                    'external_reference'
                ]
                ?? $externalRecord[
                    'reference'
                ]
                ?? ''
            )
        );

        if (
            $entryReference !== ''
            && $externalReference !== ''
        ) {
            if (
                strcasecmp(
                    $entryReference,
                    $externalReference
                ) === 0
            ) {
                $score += 30.0;
                $reasons[] =
                    'reference_match';
            } else {
                $differences['reference'] = [
                    'ledger' =>
                        $entryReference,

                    'external' =>
                        $externalReference,
                ];
            }
        } elseif (
            $options[
                'require_reference_match'
            ]
        ) {
            return [
                'match' => false,
                'score' => $score,
                'reasons' => $reasons,
                'differences' => [
                    'reference' => [
                        'ledger' =>
                            $entryReference,

                        'external' =>
                            $externalReference,
                    ],
                ],
            ];
        }

        $entryTimestamp = strtotime(
            (string)(
                $entry['effective_at']
                    ?? ''
            )
        );

        $externalTimestamp = strtotime(
            (string)(
                $externalRecord[
                    'effective_at'
                ]
                ?? $externalRecord['date']
                ?? ''
            )
        );

        if (
            $entryTimestamp !== false
            && $externalTimestamp !== false
        ) {
            $dayDifference = abs(
                $entryTimestamp
                - $externalTimestamp
            ) / 86400;

            if (
                $dayDifference <=
                (int)$options[
                    'date_tolerance_days'
                ]
            ) {
                $score += 10.0;
                $reasons[] = 'date_match';
            } else {
                $differences['effective_at'] = [
                    'ledger' =>
                        $entry[
                            'effective_at'
                        ] ?? null,

                    'external' =>
                        $externalRecord[
                            'effective_at'
                        ]
                        ?? $externalRecord[
                            'date'
                        ]
                        ?? null,

                    'difference_days' =>
                        round(
                            $dayDifference,
                            2
                        ),
                ];
            }
        }

        if ($options['match_account_id']) {
            $ledgerAccount = trim(
                (string)(
                    $entry['account_id']
                        ?? ''
                )
            );

            $externalAccount = trim(
                (string)(
                    $externalRecord[
                        'account_id'
                    ] ?? ''
                )
            );

            if (
                $ledgerAccount === ''
                || $externalAccount === ''
                || $ledgerAccount
                    !== $externalAccount
            ) {
                return [
                    'match' => false,
                    'score' => $score,
                    'reasons' => $reasons,
                    'differences' => [
                        'account_id' => [
                            'ledger' =>
                                $ledgerAccount,

                            'external' =>
                                $externalAccount,
                        ],
                    ],
                ];
            }

            $score += 10.0;
            $reasons[] = 'account_match';
        }

        if ($options['match_subject_id']) {
            $ledgerSubject = trim(
                (string)(
                    $entry['subject_id']
                        ?? ''
                )
            );

            $externalSubject = trim(
                (string)(
                    $externalRecord[
                        'subject_id'
                    ] ?? ''
                )
            );

            if (
                $ledgerSubject === ''
                || $externalSubject === ''
                || $ledgerSubject
                    !== $externalSubject
            ) {
                return [
                    'match' => false,
                    'score' => $score,
                    'reasons' => $reasons,
                    'differences' => [
                        'subject_id' => [
                            'ledger' =>
                                $ledgerSubject,

                            'external' =>
                                $externalSubject,
                        ],
                    ],
                ];
            }

            $score += 10.0;
            $reasons[] = 'subject_match';
        }

        $match =
            $amountDifference <= $tolerance
            && (
                !$options[
                    'require_reference_match'
                ]
                || in_array(
                    'reference_match',
                    $reasons,
                    true
                )
            );

        return [
            'match' =>
                $match,

            'score' =>
                round($score, 4),

            'reasons' =>
                $reasons,

            'differences' =>
                $differences,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | PART 3C CONTINUES WITH GRAPH CONVERSION, INSPECTION, AND LEDGER SUMMARIES
    |--------------------------------------------------------------------------
    |
    | Do not close the class yet.
    |
    */
    /**
     * Convert one ledger entry into canonical graph-entity form.
     *
     * @param array<string,mixed> $entry
     *
     * @return array<string,mixed>
     */
    public function toGraphEntity(
        array $entry
    ): array {
        $this->assertEntry($entry);

        return array_merge(
            $entry,
            [
                'entity_id' =>
                    $entry['ledger_entry_id'],

                'entity_type' =>
                    'ledger_entry',

                'graph_label' =>
                    $entry['description']
                    ?? $entry['ledger_entry_id'],

                'graph_status' =>
                    $entry['status']
                    ?? 'draft',

                'graph_scope' =>
                    $entry['scope']
                    ?? 'global',
            ]
        );
    }

    /**
     * Create a relationship from a ledger entry to its subject.
     *
     * @param array<string,mixed> $entry
     *
     * @return array<string,mixed>
     */
    public function subjectRelationship(
        array $entry,
        string $actorId
    ): array {
        $this->assertEntry($entry);

        $subjectId = trim(
            (string)(
                $entry['subject_id']
                ?? ''
            )
        );

        if ($subjectId === '') {
            throw new RuntimeException(
                'Ledger entry has no subject identifier.'
            );
        }

        return $this->relationships->create(
            [
                'source_id' =>
                    $entry['ledger_entry_id'],

                'source_type' =>
                    'ledger_entry',

                'target_id' =>
                    $subjectId,

                'target_type' =>
                    $this->normalizeMachineKey(
                        (string)(
                            $entry['subject_type']
                            ?? 'entity'
                        )
                    ),

                'relationship_type' =>
                    'records_activity_for',

                'status' =>
                    in_array(
                        $entry['status']
                        ?? '',
                        [
                            'posted',
                            'settled',
                        ],
                        true
                    )
                        ? 'verified'
                        : 'proposed',

                'confidence' => 100,
                'weight' => 1,
                'strength' => 1,
                'created_by' => trim($actorId),

                'provenance_id' =>
                    $entry['provenance_id']
                    ?? '',

                'metadata' => [
                    'transaction_id' =>
                        $entry['transaction_id']
                        ?? null,

                    'entry_type' =>
                        $entry['entry_type']
                        ?? null,
                ],
            ]
        );
    }

    /**
     * Create relationships from a ledger entry to linked domain records.
     *
     * @param array<string,mixed> $entry
     *
     * @return array<int,array<string,mixed>>
     */
    public function linkedRelationships(
        array $entry,
        string $actorId
    ): array {
        $this->assertEntry($entry);

        $links = [
            'decision_id' => [
                'target_type' => 'decision',
                'relationship_type' => 'authorized_by',
            ],
            'workflow_id' => [
                'target_type' => 'workflow',
                'relationship_type' => 'recorded_during',
            ],
            'idea_id' => [
                'target_type' => 'idea',
                'relationship_type' => 'accounts_for',
            ],
            'asset_id' => [
                'target_type' => 'asset',
                'relationship_type' => 'accounts_for',
            ],
            'relationship_id' => [
                'target_type' => 'relationship',
                'relationship_type' => 'accounts_for',
            ],
            'contribution_id' => [
                'target_type' => 'contribution',
                'relationship_type' => 'records',
            ],
            'program_id' => [
                'target_type' => 'program',
                'relationship_type' => 'allocated_to',
            ],
        ];

        $relationships = [];

        foreach ($links as $field => $definition) {
            $targetId = trim(
                (string)(
                    $entry[$field]
                    ?? ''
                )
            );

            if ($targetId === '') {
                continue;
            }

            $relationships[] =
                $this->relationships->create(
                    [
                        'source_id' =>
                            $entry['ledger_entry_id'],

                        'source_type' =>
                            'ledger_entry',

                        'target_id' =>
                            $targetId,

                        'target_type' =>
                            $definition['target_type'],

                        'relationship_type' =>
                            $definition[
                                'relationship_type'
                            ],

                        'status' =>
                            in_array(
                                $entry['status']
                                ?? '',
                                [
                                    'posted',
                                    'settled',
                                ],
                                true
                            )
                                ? 'verified'
                                : 'proposed',

                        'confidence' => 100,
                        'weight' => 1,
                        'strength' => 1,
                        'created_by' => trim($actorId),

                        'provenance_id' =>
                            $entry['provenance_id']
                            ?? '',

                        'metadata' => [
                            'transaction_id' =>
                                $entry['transaction_id']
                                ?? null,
                        ],
                    ]
                );
        }

        return $relationships;
    }

    /**
     * Inspect one ledger entry and expose integrity and lifecycle state.
     *
     * @param array<string,mixed> $entry
     *
     * @return array<string,mixed>
     */
    public function inspectEntry(
        array $entry
    ): array {
        $this->assertEntry($entry);

        $validation = $this->validateEntry(
            $entry
        );

        $storedChecksum = trim(
            (string)(
                $entry['checksum']
                ?? ''
            )
        );

        $storedEntryHash = trim(
            (string)(
                $entry['entry_hash']
                ?? ''
            )
        );

        $status = $this->normalizeStatus(
            (string)(
                $entry['status']
                ?? 'draft'
            )
        );

        return [
            'ledger_entry_id' =>
                $entry['ledger_entry_id'],

            'transaction_id' =>
                $entry['transaction_id']
                ?? null,

            'generated_at' =>
                gmdate('c'),

            'validation' =>
                $validation,

            'checksum_valid' =>
                $storedChecksum !== ''
                && hash_equals(
                    $storedChecksum,
                    $this->calculateChecksum(
                        $entry
                    )
                ),

            'entry_hash_valid' =>
                $storedEntryHash !== ''
                && hash_equals(
                    $storedEntryHash,
                    $this->calculateEntryHash(
                        $entry
                    )
                ),

            'status' =>
                $status,

            'available_transitions' =>
                $this->transitions[$status]
                ?? [],

            'posted' =>
                in_array(
                    $status,
                    [
                        'posted',
                        'settled',
                        'disputed',
                        'held',
                        'reversed',
                        'archived',
                    ],
                    true
                ),

            'settled' =>
                $status === 'settled',

            'reversed' =>
                $status === 'reversed',

            'has_provenance' =>
                trim(
                    (string)(
                        $entry['provenance_id']
                        ?? $entry['source_reference']
                        ?? ''
                    )
                ) !== '',

            'has_decision' =>
                trim(
                    (string)(
                        $entry['decision_id']
                        ?? ''
                    )
                ) !== '',

            'graph_entity' =>
                $this->toGraphEntity(
                    $entry
                ),
        ];
    }

    /**
     * Return a compact summary of one ledger entry.
     *
     * @param array<string,mixed> $entry
     *
     * @return array<string,mixed>
     */
    public function summarizeEntry(
        array $entry
    ): array {
        $this->assertEntry($entry);

        return [
            'ledger_entry_id' =>
                $entry['ledger_entry_id'],

            'transaction_id' =>
                $entry['transaction_id']
                ?? null,

            'sequence' =>
                (int)(
                    $entry['sequence']
                    ?? 0
                ),

            'entry_type' =>
                $entry['entry_type']
                ?? 'informational',

            'transaction_class' =>
                $entry['transaction_class']
                ?? 'memorandum',

            'scope' =>
                $entry['scope']
                ?? 'global',

            'subject_id' =>
                $entry['subject_id']
                ?? null,

            'subject_type' =>
                $entry['subject_type']
                ?? 'entity',

            'account_id' =>
                $entry['account_id']
                ?? null,

            'account_class' =>
                $entry['account_class']
                ?? 'memorandum',

            'debit' =>
                (float)(
                    $entry['debit']
                    ?? 0
                ),

            'credit' =>
                (float)(
                    $entry['credit']
                    ?? 0
                ),

            'net_amount' =>
                (float)(
                    $entry['net_amount']
                    ?? 0
                ),

            'unit' =>
                $entry['unit']
                ?? 'none',

            'status' =>
                $entry['status']
                ?? 'draft',

            'effective_at' =>
                $entry['effective_at']
                ?? null,

            'posted_at' =>
                $entry['posted_at']
                ?? null,

            'settled_at' =>
                $entry['settled_at']
                ?? null,

            'created_by' =>
                $entry['created_by']
                ?? null,

            'created_at' =>
                $entry['created_at']
                ?? null,

            'checksum' =>
                $entry['checksum']
                ?? null,

            'entry_hash' =>
                $entry['entry_hash']
                ?? null,
        ];
    }

    /**
     * Summarize activity for one account across all units.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function accountSummary(
        array $entries,
        string $accountId,
        array $options = []
    ): array {
        $accountId = trim($accountId);

        if ($accountId === '') {
            throw new InvalidArgumentException(
                'Account summary requires account identifier.'
            );
        }

        $balanceReport = $this->accountBalance(
            $entries,
            $accountId,
            (string)(
                $options['unit']
                ?? ''
            ),
            $options
        );

        $matchingEntries = [];
        $statuses = [];
        $entryTypes = [];
        $transactionIds = [];
        $subjects = [];
        $firstEffectiveAt = null;
        $lastEffectiveAt = null;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (
                trim(
                    (string)(
                        $entry['account_id']
                        ?? ''
                    )
                ) !== $accountId
            ) {
                continue;
            }

            if (
                !$this->entryWithinDateRange(
                    $entry,
                    $options['effective_from']
                    ?? null,
                    $options['effective_to']
                    ?? null
                )
            ) {
                continue;
            }

            $requestedUnit = trim(
                (string)(
                    $options['unit']
                    ?? ''
                )
            );

            if (
                $requestedUnit !== ''
                && $this->normalizeUnit(
                    (string)(
                        $entry['unit']
                        ?? 'none'
                    )
                ) !== $this->normalizeUnit(
                    $requestedUnit
                )
            ) {
                continue;
            }

            $matchingEntries[] =
                $this->summarizeEntry(
                    $entry
                );

            $status = trim(
                (string)(
                    $entry['status']
                    ?? ''
                )
            );

            if ($status !== '') {
                $statuses[$status] =
                    ($statuses[$status]
                        ?? 0) + 1;
            }

            $entryType = trim(
                (string)(
                    $entry['entry_type']
                    ?? ''
                )
            );

            if ($entryType !== '') {
                $entryTypes[$entryType] =
                    ($entryTypes[$entryType]
                        ?? 0) + 1;
            }

            $transactionId = trim(
                (string)(
                    $entry['transaction_id']
                    ?? ''
                )
            );

            if ($transactionId !== '') {
                $transactionIds[$transactionId] =
                    true;
            }

            $subjectId = trim(
                (string)(
                    $entry['subject_id']
                    ?? ''
                )
            );

            if ($subjectId !== '') {
                $subjectType = trim(
                    (string)(
                        $entry['subject_type']
                        ?? 'entity'
                    )
                );

                $subjects[
                    $subjectType
                    . ':'
                    . $subjectId
                ] = [
                    'subject_id' =>
                        $subjectId,

                    'subject_type' =>
                        $subjectType,
                ];
            }

            $effectiveAt = trim(
                (string)(
                    $entry['effective_at']
                    ?? ''
                )
            );

            if ($effectiveAt !== '') {
                if (
                    $firstEffectiveAt === null
                    || strcmp(
                        $effectiveAt,
                        $firstEffectiveAt
                    ) < 0
                ) {
                    $firstEffectiveAt =
                        $effectiveAt;
                }

                if (
                    $lastEffectiveAt === null
                    || strcmp(
                        $effectiveAt,
                        $lastEffectiveAt
                    ) > 0
                ) {
                    $lastEffectiveAt =
                        $effectiveAt;
                }
            }
        }

        ksort($statuses);
        ksort($entryTypes);
        ksort($transactionIds);
        ksort($subjects);

        return [
            'generated_at' =>
                gmdate('c'),

            'account_id' =>
                $accountId,

            'entry_count' =>
                count($matchingEntries),

            'transaction_count' =>
                count($transactionIds),

            'subject_count' =>
                count($subjects),

            'first_effective_at' =>
                $firstEffectiveAt,

            'last_effective_at' =>
                $lastEffectiveAt,

            'statuses' =>
                $statuses,

            'entry_types' =>
                $entryTypes,

            'transaction_ids' =>
                array_keys($transactionIds),

            'subjects' =>
                array_values($subjects),

            'balances' =>
                $balanceReport['balances']
                ?? [],

            'entries' =>
                $matchingEntries,
        ];
    }

    /**
     * Summarize an entire ledger collection.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function ledgerSummary(
        array $entries,
        array $options = []
    ): array {
        $statuses = [];
        $entryTypes = [];
        $transactionClasses = [];
        $accountClasses = [];
        $scopes = [];
        $units = [];
        $accounts = [];
        $transactions = [];
        $subjects = [];
        $programs = [];
        $validEntries = 0;
        $invalidEntries = 0;
        $warningCount = 0;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                $invalidEntries++;
                continue;
            }

            $validation = $this->validateEntry(
                $entry
            );

            if (
                ($validation['valid']
                    ?? false) === true
            ) {
                $validEntries++;
            } else {
                $invalidEntries++;
            }

            $warningCount += (int)(
                $validation['warning_count']
                ?? 0
            );

            foreach (
                [
                    'status' => &$statuses,
                    'entry_type' => &$entryTypes,
                    'transaction_class' =>
                        &$transactionClasses,
                    'account_class' =>
                        &$accountClasses,
                    'scope' => &$scopes,
                    'unit' => &$units,
                ]
                as $field => &$collection
            ) {
                $value = trim(
                    (string)(
                        $entry[$field]
                        ?? ''
                    )
                );

                if ($value !== '') {
                    $collection[$value] =
                        ($collection[$value]
                            ?? 0) + 1;
                }
            }

            unset($collection);

            $accountId = trim(
                (string)(
                    $entry['account_id']
                    ?? ''
                )
            );

            if ($accountId !== '') {
                $accounts[$accountId] = true;
            }

            $transactionId = trim(
                (string)(
                    $entry['transaction_id']
                    ?? ''
                )
            );

            if ($transactionId !== '') {
                $transactions[$transactionId] =
                    true;
            }

            $subjectId = trim(
                (string)(
                    $entry['subject_id']
                    ?? ''
                )
            );

            if ($subjectId !== '') {
                $subjects[
                    trim(
                        (string)(
                            $entry['subject_type']
                            ?? 'entity'
                        )
                    )
                    . ':'
                    . $subjectId
                ] = true;
            }

            $programId = trim(
                (string)(
                    $entry['program_id']
                    ?? ''
                )
            );

            if ($programId !== '') {
                $programs[$programId] = true;
            }
        }

        foreach (
            [
                &$statuses,
                &$entryTypes,
                &$transactionClasses,
                &$accountClasses,
                &$scopes,
                &$units,
            ]
            as &$collection
        ) {
            ksort($collection);
        }

        unset($collection);

        $balances = $this->calculateBalances(
            $entries,
            $options
        );

        $groupedTransactions =
            $this->groupTransactions(
                $entries,
                $options
            );

        $exceptions = $this->detectExceptions(
            $entries,
            $options['exception_options']
            ?? []
        );

        $integrity = $this->integrityReport(
            $entries
        );

        return [
            'generated_at' =>
                gmdate('c'),

            'entry_count' =>
                count($entries),

            'valid_entry_count' =>
                $validEntries,

            'invalid_entry_count' =>
                $invalidEntries,

            'warning_count' =>
                $warningCount,

            'transaction_count' =>
                count($transactions),

            'account_count' =>
                count($accounts),

            'subject_count' =>
                count($subjects),

            'program_count' =>
                count($programs),

            'statuses' =>
                $statuses,

            'entry_types' =>
                $entryTypes,

            'transaction_classes' =>
                $transactionClasses,

            'account_classes' =>
                $accountClasses,

            'scopes' =>
                $scopes,

            'units' =>
                $units,

            'balances' =>
                $balances,

            'transactions' => [
                'count' =>
                    $groupedTransactions[
                        'transaction_count'
                    ] ?? 0,

                'valid_count' =>
                    $groupedTransactions[
                        'valid_transaction_count'
                    ] ?? 0,

                'balanced_count' =>
                    $groupedTransactions[
                        'balanced_transaction_count'
                    ] ?? 0,
            ],

            'exceptions' =>
                $exceptions,

            'integrity' =>
                $integrity,
        ];
    }

    /**
     * Produce ledger-wide checksum, hash-chain, sequence, and transaction tests.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function integrityReport(
        array $entries
    ): array {
        $entryResults = [];
        $validChecksums = 0;
        $validEntryHashes = 0;
        $invalidEntries = 0;

        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                $invalidEntries++;

                $entryResults[] = [
                    'index' => $index,
                    'valid' => false,
                    'reason' =>
                        'invalid_entry',
                ];

                continue;
            }

            $validation = $this->validateEntry(
                $entry
            );

            $checksumValid =
                trim(
                    (string)(
                        $entry['checksum']
                        ?? ''
                    )
                ) !== ''
                && hash_equals(
                    (string)$entry['checksum'],
                    $this->calculateChecksum(
                        $entry
                    )
                );

            $entryHashValid =
                trim(
                    (string)(
                        $entry['entry_hash']
                        ?? ''
                    )
                ) !== ''
                && hash_equals(
                    (string)$entry['entry_hash'],
                    $this->calculateEntryHash(
                        $entry
                    )
                );

            if ($checksumValid) {
                $validChecksums++;
            }

            if ($entryHashValid) {
                $validEntryHashes++;
            }

            if (
                ($validation['valid']
                    ?? false) !== true
            ) {
                $invalidEntries++;
            }

            $entryResults[] = [
                'index' =>
                    $index,

                'ledger_entry_id' =>
                    $entry['ledger_entry_id']
                    ?? null,

                'transaction_id' =>
                    $entry['transaction_id']
                    ?? null,

                'valid' =>
                    ($validation['valid']
                        ?? false) === true
                    && $checksumValid
                    && $entryHashValid,

                'checksum_valid' =>
                    $checksumValid,

                'entry_hash_valid' =>
                    $entryHashValid,

                'validation' =>
                    $validation,
            ];
        }

        $transactionGroups =
            $this->groupTransactions(
                $entries
            );

        $transactionResults = [];

        foreach (
            $transactionGroups['transactions']
            ?? []
            as $transaction
        ) {
            $transactionResults[] = [
                'transaction_id' =>
                    $transaction[
                        'transaction_id'
                    ] ?? null,

                'valid' =>
                    ($transaction['valid']
                        ?? false) === true,

                'balanced' =>
                    ($transaction['balanced']
                        ?? false) === true,

                'chain_valid' =>
                    ($transaction['chain_valid']
                        ?? false) === true,

                'sequence_valid' =>
                    ($transaction[
                        'sequence_valid'
                    ] ?? false) === true,
            ];
        }

        $transactionFailures = count(
            array_filter(
                $transactionResults,
                static fn (
                    array $transaction
                ): bool =>
                    ($transaction['valid']
                        ?? false) !== true
                    || ($transaction['balanced']
                        ?? false) !== true
                    || ($transaction['chain_valid']
                        ?? false) !== true
                    || ($transaction[
                        'sequence_valid'
                    ] ?? false) !== true
            )
        );

        return [
            'generated_at' =>
                gmdate('c'),

            'entry_count' =>
                count($entries),

            'invalid_entry_count' =>
                $invalidEntries,

            'valid_checksum_count' =>
                $validChecksums,

            'valid_entry_hash_count' =>
                $validEntryHashes,

            'transaction_count' =>
                count($transactionResults),

            'transaction_failure_count' =>
                $transactionFailures,

            'valid' =>
                $invalidEntries === 0
                && $validChecksums
                    === count($entries)
                && $validEntryHashes
                    === count($entries)
                && $transactionFailures === 0,

            'entries' =>
                $entryResults,

            'transactions' =>
                $transactionResults,

            'collection_checksum' =>
                $this->collectionChecksum(
                    $entries
                ),
        ];
    }

    /**
     * Create an immutable audit snapshot of a ledger collection.
     *
     * @param array<int,array<string,mixed>> $entries
     *
     * @return array<string,mixed>
     */
    public function auditSnapshot(
        array $entries,
        string $actorId,
        string $reason = ''
    ): array {
        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Ledger audit snapshot requires actor attribution.'
            );
        }

        $integrity = $this->integrityReport(
            $entries
        );

        $summary = $this->ledgerSummary(
            $entries,
            [
                'exception_options' => [
                    'require_provenance_for_posted' =>
                        true,
                ],
            ]
        );

        $snapshotId = 'AUD-LED-'
            . gmdate('Ymd-His')
            . '-'
            . $this->randomToken(5);

        $snapshot = [
            'snapshot_id' =>
                $snapshotId,

            'entity_id' =>
                $snapshotId,

            'entity_type' =>
                'ledger_audit_snapshot',

            'created_by' =>
                $actorId,

            'created_at' =>
                gmdate('c'),

            'reason' =>
                trim($reason),

            'entry_count' =>
                count($entries),

            'collection_checksum' =>
                $integrity[
                    'collection_checksum'
                ],

            'integrity' =>
                $integrity,

            'summary' => [
                'valid_entry_count' =>
                    $summary[
                        'valid_entry_count'
                    ],

                'invalid_entry_count' =>
                    $summary[
                        'invalid_entry_count'
                    ],

                'transaction_count' =>
                    $summary[
                        'transaction_count'
                    ],

                'account_count' =>
                    $summary[
                        'account_count'
                    ],

                'exception_count' =>
                    $summary['exceptions'][
                        'exception_count'
                    ] ?? 0,
            ],

            'checksum' => '',
        ];

        $snapshot['checksum'] = hash(
            'sha256',
            json_encode(
                $this->normalizeForHash(
                    $snapshot
                ),
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
            ) ?: ''
        );

        return $snapshot;
    }

    /**
     * Normalize one mutable field.
     *
     * @param array<string,mixed> $entry
     */
    private function normalizeFieldValue(
        string $field,
        mixed $value,
        array $entry = []
    ): mixed {
        $unit = $this->normalizeUnit(
            (string)(
                $field === 'unit'
                    ? $value
                    : (
                        $entry['unit']
                        ?? 'none'
                    )
            )
        );

        return match ($field) {
            'status' =>
                $this->normalizeStatus(
                    (string)$value
                ),

            'entry_type' =>
                $this->normalizeEntryType(
                    (string)$value
                ),

            'transaction_class' =>
                $this->normalizeTransactionClass(
                    (string)$value
                ),

            'account_class' =>
                $this->normalizeAccountClass(
                    (string)$value
                ),

            'scope' =>
                $this->normalizeScope(
                    (string)$value
                ),

            'unit' =>
                $unit,

            'quantity',
            'debit',
            'credit',
            'net_amount',
            'unit_value',
            'base_value' =>
                $this->normalizeAmount(
                    $value,
                    $unit
                ),

            'sequence' =>
                max(1, (int)$value),

            'effective_at',
            'posted_at',
            'settled_at',
            'disputed_at',
            'held_at',
            'reversed_at',
            'voided_at',
            'archived_at' =>
                $this->normalizeDate(
                    $value
                ),

            'evidence' =>
                $this->normalizeEvidence(
                    $value
                ),

            'restrictions',
            'tags' =>
                $this->normalizeStringList(
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
     * Preserve additional fields without overriding canonical fields.
     *
     * @return array<string,mixed>
     */
    private function mergeAdditionalFields(
        array $entry,
        array $input
    ): array {
        foreach ($input as $field => $value) {
            if (!array_key_exists($field, $entry)) {
                $entry[$field] = $value;
            }
        }

        return $entry;
    }

    /**
     * Normalize evidence records.
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
                    ?? ''
                )
            );

            $sourceReference = trim(
                (string)(
                    $item['source_reference']
                    ?? $item['url']
                    ?? ''
                )
            );

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
                                json_encode(
                                    $this->normalizeForHash(
                                        $item
                                    ),
                                    JSON_UNESCAPED_SLASHES
                                    | JSON_UNESCAPED_UNICODE
                                ) ?: uniqid('', true)
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
                    $sourceReference,

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

                'metadata' =>
                    is_array(
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
     * Assert canonical ledger-entry identity.
     */
    private function assertEntry(
        array $entry
    ): void {
        if (
            trim(
                (string)(
                    $entry['ledger_entry_id']
                    ?? ''
                )
            ) === ''
        ) {
            throw new InvalidArgumentException(
                'Ledger entry requires ledger_entry_id.'
            );
        }
    }

    /**
     * Normalize ledger status.
     */
    private function normalizeStatus(
        string $status
    ): string {
        $status = $this->normalizeMachineKey(
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
                    'Unsupported ledger status "%s".',
                    $status
                )
            );
        }

        return $status;
    }

    /**
     * Normalize entry type.
     */
    private function normalizeEntryType(
        string $entryType
    ): string {
        $entryType = $this->normalizeMachineKey(
            $entryType
        );

        if (
            !in_array(
                $entryType,
                $this->entryTypes,
                true
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported ledger entry type "%s".',
                    $entryType
                )
            );
        }

        return $entryType;
    }

    /**
     * Normalize account class.
     */
    private function normalizeAccountClass(
        string $accountClass
    ): string {
        $accountClass =
            $this->normalizeMachineKey(
                $accountClass
            );

        if (
            !in_array(
                $accountClass,
                $this->accountClasses,
                true
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported ledger account class "%s".',
                    $accountClass
                )
            );
        }

        return $accountClass;
    }

    /**
     * Normalize transaction class.
     */
    private function normalizeTransactionClass(
        string $transactionClass
    ): string {
        $transactionClass =
            $this->normalizeMachineKey(
                $transactionClass
            );

        if (
            !in_array(
                $transactionClass,
                $this->transactionClasses,
                true
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsupported transaction class "%s".',
                    $transactionClass
                )
            );
        }

        return $transactionClass;
    }

    /**
     * Normalize ledger scope.
     */
    private function normalizeScope(
        string $scope
    ): string {
        $scope = $this->normalizeMachineKey(
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
                    'Unsupported ledger scope "%s".',
                    $scope
                )
            );
        }

        return $scope;
    }

    /**
     * Normalize one supported unit while preserving currency casing.
     */
    private function normalizeUnit(
        string $unit
    ): string {
        $unit = trim($unit);

        if ($unit === '') {
            $unit = 'none';
        }

        $currencyCandidate = strtoupper($unit);

        if (
            in_array(
                $currencyCandidate,
                $this->units,
                true
            )
        ) {
            return $currencyCandidate;
        }

        $machineCandidate =
            $this->normalizeMachineKey(
                $unit
            );

        if (
            in_array(
                $machineCandidate,
                $this->units,
                true
            )
        ) {
            return $machineCandidate;
        }

        throw new InvalidArgumentException(
            sprintf(
                'Unsupported ledger unit "%s".',
                $unit
            )
        );
    }

    /**
     * Normalize amount using configured unit precision.
     */
    private function normalizeAmount(
        mixed $amount,
        string $unit
    ): float {
        if (
            !is_numeric($amount)
            && !is_string($amount)
        ) {
            throw new InvalidArgumentException(
                'Ledger amount must be numeric.'
            );
        }

        if (is_string($amount)) {
            $amount = str_replace(
                [
                    ',',
                    '$',
                    '€',
                    '£',
                ],
                '',
                trim($amount)
            );
        }

        if (!is_numeric($amount)) {
            throw new InvalidArgumentException(
                'Ledger amount must be numeric.'
            );
        }

        $unit = $this->normalizeUnit($unit);

        return round(
            (float)$amount,
            $this->unitPrecision[$unit]
            ?? 6
        );
    }

    /**
     * Return comparison tolerance for one unit.
     */
    private function tolerance(
        string $unit
    ): float {
        $unit = $this->normalizeUnit($unit);

        $precision = $this->unitPrecision[$unit]
            ?? 6;

        return 0.5 * (10 ** (-$precision));
    }

    /**
     * Normalize one date into UTC ISO-8601 form.
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

            $value = trim((string)$value);

            if ($value !== '') {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    /**
     * Normalize a machine-readable key.
     */
    private function normalizeMachineKey(
        string $value
    ): string {
        $value = strtolower(trim($value));

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
     * Determine default transaction class for an entry type.
     */
    private function defaultTransactionClass(
        string $entryType
    ): string {
        return match ($entryType) {
            'contribution' =>
                'contribution',

            'allocation',
            'commitment',
            'obligation',
            'release' =>
                'allocation',

            'valuation',
            'recognition',
            'attribution',
            'royalty',
            'license' =>
                'intellectual_property',

            'informational' =>
                'memorandum',

            default =>
                'financial',
        };
    }

    /**
     * Generate a default entry description.
     */
    private function defaultDescription(
        string $entryType,
        string $subjectId,
        float $quantity,
        string $unit
    ): string {
        $label = ucwords(
            str_replace(
                '_',
                ' ',
                $entryType
            )
        );

        if ($subjectId !== '') {
            return sprintf(
                '%s entry for %s: %s %s.',
                $label,
                $subjectId,
                $this->normalizeAmount(
                    $quantity,
                    $unit
                ),
                $unit
            );
        }

        return sprintf(
            '%s ledger entry: %s %s.',
            $label,
            $this->normalizeAmount(
                $quantity,
                $unit
            ),
            $unit
        );
    }

    /**
     * Calculate deterministic content checksum.
     */
    private function calculateChecksum(
        array $entry
    ): string {
        $copy = $entry;

        foreach (
            $this->checksumExcludedFields
            as $field
        ) {
            unset($copy[$field]);
        }

        $json = json_encode(
            $this->normalizeForHash($copy),
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
        );

        if ($json === false) {
            throw new RuntimeException(
                'Unable to calculate ledger checksum.'
            );
        }

        return hash('sha256', $json);
    }

    /**
     * Calculate tamper-evident entry-chain hash.
     */
    private function calculateEntryHash(
        array $entry
    ): string {
        $copy = $entry;

        unset($copy['entry_hash']);

        $contentChecksum = trim(
            (string)(
                $copy['checksum']
                ?? ''
            )
        );

        if ($contentChecksum === '') {
            $contentChecksum =
                $this->calculateChecksum(
                    $copy
                );
        }

        return hash(
            'sha256',
            implode(
                '|',
                [
                    trim(
                        (string)(
                            $copy[
                                'previous_entry_hash'
                            ] ?? ''
                        )
                    ),
                    trim(
                        (string)(
                            $copy['transaction_id']
                            ?? ''
                        )
                    ),
                    (string)(
                        $copy['sequence']
                        ?? 0
                    ),
                    trim(
                        (string)(
                            $copy['ledger_entry_id']
                            ?? ''
                        )
                    ),
                    $contentChecksum,
                ]
            )
        );
    }

    /**
     * Calculate deterministic collection checksum.
     *
     * @param array<int,array<string,mixed>> $entries
     */
    private function collectionChecksum(
        array $entries
    ): string {
        $hashes = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $hashes[] = trim(
                (string)(
                    $entry['entry_hash']
                    ?? $this->calculateEntryHash(
                        $entry
                    )
                )
            );
        }

        sort($hashes);

        return hash(
            'sha256',
            implode('|', $hashes)
        );
    }

    /**
     * Generate a transaction identifier.
     */
    private function generateTransactionId(
        string $entryType,
        string $subjectId = ''
    ): string {
        $prefix = strtoupper(
            substr(
                preg_replace(
                    '/[^A-Za-z0-9]+/',
                    '',
                    $entryType
                ) ?: 'TXN',
                0,
                3
            )
        );

        return 'TXN-'
            . $prefix
            . '-'
            . gmdate('Ymd-His')
            . '-'
            . strtoupper(
                substr(
                    hash(
                        'sha256',
                        $subjectId
                        . '|'
                        . microtime(true)
                        . '|'
                        . $this->randomToken(4)
                    ),
                    0,
                    10
                )
            );
    }

    /**
     * Generate a ledger-entry identifier.
     */
    private function generateLedgerEntryId(
        string $transactionId,
        int $sequence
    ): string {
        return 'LED-'
            . strtoupper(
                substr(
                    hash(
                        'sha256',
                        $transactionId
                        . '|'
                        . $sequence
                        . '|'
                        . microtime(true)
                    ),
                    0,
                    16
                )
            );
    }

    /**
     * Generate a secure uppercase token.
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
