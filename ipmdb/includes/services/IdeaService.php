<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/IdeaService.php
|--------------------------------------------------------------------------
| IPMdb Idea Service
|--------------------------------------------------------------------------
|
| Coordinates the application-level lifecycle of an idea before it becomes
| an intellectual asset.
|
| Responsibilities:
| - Create canonical idea records.
| - Normalize and validate idea input.
| - Lock ideas with attributable ownership.
| - Maintain provenance, contributors, tags, evidence, and classifications.
| - Manage lifecycle transitions from intake through acceptance.
| - Reject, dispute, archive, restore, and convert ideas.
| - Detect duplicate or substantially similar ideas.
| - Produce graph-ready idea entities.
| - Calculate deterministic checksums, completeness, and conversion readiness.
|
| Ideas enter.
| Evidence accumulates.
| Decisions remain attributable.
| Accepted ideas become assets.
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
require_once __DIR__ . '/SimilarityService.php';
require_once __DIR__ . '/AssetService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class IdeaService extends Service
{
    use GraphUtilities;

    private ValidationService $validation;

    private ProvenanceService $provenance;

    private VersionService $versions;

    private EventService $events;

    private RelationshipService $relationships;

    private SimilarityService $similarity;

    private AssetService $assets;

    /**
     * Supported idea lifecycle states.
     *
     * @var array<int,string>
     */
    private array $statuses = [
        'draft',
        'locked',
        'submitted',
        'under_review',
        'accepted',
        'converted',
        'disputed',
        'blocked',
        'rejected',
        'archived',
    ];

    /**
     * Allowed lifecycle transitions.
     *
     * @var array<string,array<int,string>>
     */
    private array $transitions = [
        'draft' => [
            'locked',
            'submitted',
            'rejected',
            'archived',
        ],

        'locked' => [
            'draft',
            'submitted',
            'under_review',
            'rejected',
            'archived',
        ],

        'submitted' => [
            'locked',
            'under_review',
            'accepted',
            'disputed',
            'blocked',
            'rejected',
            'archived',
        ],

        'under_review' => [
            'submitted',
            'accepted',
            'disputed',
            'blocked',
            'rejected',
            'archived',
        ],

        'accepted' => [
            'under_review',
            'converted',
            'disputed',
            'blocked',
            'archived',
        ],

        'converted' => [
            'accepted',
            'archived',
        ],

        'disputed' => [
            'draft',
            'submitted',
            'under_review',
            'accepted',
            'rejected',
            'archived',
        ],

        'blocked' => [
            'draft',
            'submitted',
            'under_review',
            'accepted',
            'rejected',
            'archived',
        ],

        'rejected' => [
            'draft',
            'submitted',
            'archived',
        ],

        'archived' => [
            'draft',
            'locked',
            'submitted',
        ],
    ];

    /**
     * Common idea types.
     *
     * @var array<int,string>
     */
    private array $ideaTypes = [
        'idea',
        'concept',
        'observation',
        'problem',
        'opportunity',
        'proposal',
        'solution',
        'design',
        'invention',
        'process',
        'method',
        'system',
        'software',
        'policy',
        'program',
        'mission',
        'objective',
        'decision',
        'research',
        'media',
        'brand',
        'dataset',
        'other',
    ];

    /**
     * Immutable fields after creation.
     *
     * @var array<int,string>
     */
    private array $immutableFields = [
        'idea_id',
        'entity_id',
        'entity_type',
        'created_at',
        'created_by',
        'originator_id',
        'originator_email',
    ];

    /**
     * Fields excluded from checksum calculations.
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
        'similarity_cache',
    ];

    /**
     * Completeness contribution weights.
     *
     * @var array<string,float>
     */
    private array $completenessWeights = [
        'title' => 0.15,
        'idea' => 0.15,
        'description' => 0.12,
        'idea_type' => 0.07,
        'originator_id' => 0.10,
        'provenance_id' => 0.10,
        'purpose' => 0.07,
        'problem' => 0.06,
        'proposed_outcome' => 0.06,
        'tags' => 0.04,
        'keywords' => 0.04,
        'evidence' => 0.04,
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?ValidationService $validation = null,
        ?ProvenanceService $provenance = null,
        ?VersionService $versions = null,
        ?EventService $events = null,
        ?RelationshipService $relationships = null,
        ?SimilarityService $similarity = null,
        ?AssetService $assets = null
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

        $this->similarity = $similarity
            ?? new SimilarityService();

        $this->assets = $assets
            ?? new AssetService();

        if (
            isset($config['idea_types'])
            && is_array($config['idea_types'])
        ) {
            $this->ideaTypes = $this->normalizeStringList(
                array_merge(
                    $this->ideaTypes,
                    $config['idea_types']
                )
            );
        }
    }

    /**
     * Create one canonical idea.
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
                    ?? $input['originator_id']
                    ?? ''
                )
        );

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Idea creation requires actor attribution.'
            );
        }

        $ideaText = trim(
            (string)(
                $input['idea']
                ?? $input['content']
                ?? $input['title']
                ?? ''
            )
        );

        if ($ideaText === '') {
            throw new InvalidArgumentException(
                'Idea content is required.'
            );
        }

        $title = trim(
            (string)(
                $input['title']
                ?? $this->titleFromIdea(
                    $ideaText
                )
            )
        );

        $ideaType = $this->normalizeIdeaType(
            (string)(
                $input['idea_type']
                ?? $input['type']
                ?? 'idea'
            )
        );

        $ideaId = trim(
            (string)(
                $input['idea_id']
                ?? ''
            )
        );

        if ($ideaId === '') {
            $ideaId = $this->generateIdeaId(
                $title,
                $ideaText
            );
        }

        $now = gmdate('c');

        $originatorId = trim(
            (string)(
                $input['originator_id']
                ?? $actorId
            )
        );

        $metadata = is_array(
            $input['metadata']
                ?? null
        )
            ? $input['metadata']
            : [];

        $metadata['idea_service'] = array_merge(
            is_array(
                $metadata['idea_service']
                    ?? null
            )
                ? $metadata['idea_service']
                : [],
            [
                'created_by_service' =>
                    static::class,

                'created_at' => $now,
            ]
        );

        $idea = [
            'idea_id' => $ideaId,

            'entity_id' => $ideaId,

            'entity_type' => 'idea',

            'title' => $title,

            'name' => trim(
                (string)(
                    $input['name']
                    ?? $title
                )
            ),

            'idea' => $ideaText,

            'description' => trim(
                (string)(
                    $input['description']
                    ?? ''
                )
            ),

            'summary' => trim(
                (string)(
                    $input['summary']
                    ?? ''
                )
            ),

            'idea_type' => $ideaType,

            'category' => $this->normalizeMachineKey(
                (string)(
                    $input['category']
                    ?? ''
                )
            ),

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

            'language' => $this->normalizeLanguage(
                (string)(
                    $input['language']
                    ?? 'en'
                )
            ),

            'purpose' => trim(
                (string)(
                    $input['purpose']
                    ?? ''
                )
            ),

            'problem' => trim(
                (string)(
                    $input['problem']
                    ?? ''
                )
            ),

            'opportunity' => trim(
                (string)(
                    $input['opportunity']
                    ?? ''
                )
            ),

            'proposed_outcome' => trim(
                (string)(
                    $input['proposed_outcome']
                    ?? $input['objective']
                    ?? ''
                )
            ),

            'objective' => trim(
                (string)(
                    $input['objective']
                    ?? ''
                )
            ),

            'mission' => trim(
                (string)(
                    $input['mission']
                    ?? ''
                )
            ),

            'originator_id' =>
                $originatorId,

            'originator_email' => strtolower(
                trim(
                    (string)(
                        $input['originator_email']
                        ?? ''
                    )
                )
            ),

            'created_by' => $actorId,

            'updated_by' => $actorId,

            'contributors' =>
                $this->normalizeContributors(
                    $input['contributors']
                    ?? []
                ),

            'tags' =>
                $this->normalizeStringList(
                    $input['tags']
                    ?? []
                ),

            'keywords' =>
                $this->normalizeStringList(
                    $input['keywords']
                    ?? []
                ),

            'classifications' =>
                $this->normalizeStringList(
                    $input['classifications']
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

            'license' => trim(
                (string)(
                    $input['license']
                    ?? 'public-domain'
                )
            ),

            'locked' => false,

            'locked_by' => null,

            'locked_at' => null,

            'submitted_by' => null,

            'submitted_at' => null,

            'reviewed_by' => null,

            'reviewed_at' => null,

            'accepted_by' => null,

            'accepted_at' => null,

            'converted_by' => null,

            'converted_at' => null,

            'asset_id' => null,

            'dispute_reason' => null,

            'block_reason' => null,

            'rejection_reason' => null,

            'archived_by' => null,

            'archived_at' => null,

            'created_at' => $now,

            'updated_at' => $now,

            'metadata' => $metadata,

            'checksum' => '',
        ];

        $idea = $this->mergeAdditionalFields(
            $idea,
            $input
        );

        if (
            ($input['locked'] ?? false)
            === true
        ) {
            $idea['locked'] = true;
            $idea['locked_by'] = $actorId;
            $idea['locked_at'] = $now;
            $idea['status'] = 'locked';
        }

        $idea['checksum'] =
            $this->calculateChecksum($idea);

        $idea['completeness'] =
            $this->calculateCompleteness(
                $idea
            );

        $idea['conversion_readiness'] =
            $this->calculateConversionReadiness(
                $idea
            );

        $validation = $this->validate(
            $idea
        );

        if (
            ($validation['valid'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Idea validation failed: '
                . implode(
                    ' ',
                    $validation['errors']
                    ?? []
                )
            );
        }

        $this->addMessage(
            'Idea created.',
            [
                'idea_id' => $ideaId,
                'idea_type' => $ideaType,
                'status' => $idea['status'],
            ]
        );

        return $idea;
    }

    /**
     * Update one idea.
     *
     * @param array<string,mixed> $idea
     * @param array<string,mixed> $changes
     *
     * @return array<string,mixed>
     */
    public function update(
        array $idea,
        array $changes,
        string $actorId,
        string $reason = ''
    ): array {
        $this->assertIdea($idea);

        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Idea update requires actor attribution.'
            );
        }

        if (
            ($idea['locked'] ?? false)
            === true
            && trim(
                (string)(
                    $idea['locked_by']
                    ?? ''
                )
            ) !== $actorId
        ) {
            throw new RuntimeException(
                'Idea is locked by another actor.'
            );
        }

        if (
            ($idea['status'] ?? '')
            === 'converted'
        ) {
            throw new RuntimeException(
                'Converted ideas require a new version or linked revision.'
            );
        }

        $updated = $idea;

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
                    $idea['version']
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
            'changed_by' => $actorId,
            'changed_at' => gmdate('c'),
            'reason' => trim($reason),
            'fields' => array_values(
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

        $updated['completeness'] =
            $this->calculateCompleteness(
                $updated
            );

        $updated['conversion_readiness'] =
            $this->calculateConversionReadiness(
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
                'Updated idea is invalid: '
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
     * Transition idea lifecycle status.
     *
     * @param array<string,mixed> $idea
     *
     * @return array<string,mixed>
     */
    public function transition(
        array $idea,
        string $newStatus,
        string $actorId,
        string $reason = ''
    ): array {
        $this->assertIdea($idea);

        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Idea transition requires actor attribution.'
            );
        }

        $currentStatus = $this->normalizeStatus(
            (string)(
                $idea['status']
                ?? 'draft'
            )
        );

        $newStatus = $this->normalizeStatus(
            $newStatus
        );

        if ($currentStatus === $newStatus) {
            return $idea;
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
                    'Idea status cannot transition from "%s" to "%s".',
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
            case 'locked':
                $changes['locked'] = true;
                $changes['locked_by'] =
                    $actorId;
                $changes['locked_at'] =
                    $now;
                break;

            case 'submitted':
                $changes['submitted_by'] =
                    $actorId;
                $changes['submitted_at'] =
                    $now;
                break;

            case 'under_review':
                $changes['reviewed_by'] =
                    $actorId;
                $changes['reviewed_at'] =
                    $now;
                break;

            case 'accepted':
                $changes['accepted_by'] =
                    $actorId;
                $changes['accepted_at'] =
                    $now;
                break;

            case 'converted':
                $changes['converted_by'] =
                    $actorId;
                $changes['converted_at'] =
                    $now;
                break;

            case 'disputed':
                $changes['dispute_reason'] =
                    trim($reason);
                break;

            case 'blocked':
                $changes['block_reason'] =
                    trim($reason);
                break;

            case 'rejected':
                $changes['rejection_reason'] =
                    trim($reason);
                break;

            case 'archived':
                $changes['archived_by'] =
                    $actorId;
                $changes['archived_at'] =
                    $now;
                break;

            case 'draft':
                if ($currentStatus === 'locked') {
                    $changes['locked'] = false;
                    $changes['locked_by'] = null;
                    $changes['locked_at'] = null;
                }
                break;
        }

        return $this->update(
            $idea,
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
     * Lock one idea.
     */
    public function lock(
        array $idea,
        string $actorId,
        string $reason = ''
    ): array {
        $this->assertIdea($idea);

        if (
            ($idea['locked'] ?? false)
            === true
        ) {
            if (
                trim(
                    (string)(
                        $idea['locked_by']
                        ?? ''
                    )
                ) === trim($actorId)
            ) {
                return $idea;
            }

            throw new RuntimeException(
                'Idea is already locked.'
            );
        }

        return $this->transition(
            $idea,
            'locked',
            $actorId,
            $reason !== ''
                ? $reason
                : 'Idea locked.'
        );
    }

    /**
     * Unlock one idea.
     */
    public function unlock(
        array $idea,
        string $actorId,
        bool $force = false,
        string $reason = ''
    ): array {
        $this->assertIdea($idea);

        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Idea unlock requires actor attribution.'
            );
        }

        if (
            ($idea['locked'] ?? false)
            !== true
        ) {
            return $idea;
        }

        $lockedBy = trim(
            (string)(
                $idea['locked_by']
                ?? ''
            )
        );

        if (
            !$force
            && $lockedBy !== ''
            && $lockedBy !== $actorId
        ) {
            throw new RuntimeException(
                'Only the locking actor may unlock this idea.'
            );
        }

        $updated = $idea;

        $updated['locked'] = false;
        $updated['locked_by'] = null;
        $updated['locked_at'] = null;

        if (
            ($updated['status'] ?? '')
            === 'locked'
        ) {
            $updated['status'] = 'draft';
        }

        $updated['updated_by'] =
            $actorId;

        $updated['updated_at'] =
            gmdate('c');

        $updated['metadata'] = is_array(
            $updated['metadata']
                ?? null
        )
            ? $updated['metadata']
            : [];

        $updated['metadata']['last_unlock'] = [
            'unlocked_by' => $actorId,
            'unlocked_at' => gmdate('c'),
            'forced' => $force,
            'reason' => trim($reason),
        ];

        $updated['checksum'] =
            $this->calculateChecksum(
                $updated
            );

        $updated['completeness'] =
            $this->calculateCompleteness(
                $updated
            );

        $updated['conversion_readiness'] =
            $this->calculateConversionReadiness(
                $updated
            );

        return $updated;
    }

    /**
     * Submit one idea for review.
     */
    public function submit(
        array $idea,
        string $actorId,
        string $reason = ''
    ): array {
        return $this->transition(
            $idea,
            'submitted',
            $actorId,
            $reason
        );
    }

    /**
     * Begin review.
     */
    public function beginReview(
        array $idea,
        string $actorId,
        string $reason = ''
    ): array {
        return $this->transition(
            $idea,
            'under_review',
            $actorId,
            $reason
        );
    }

    /**
     * Accept one idea.
     */
    public function accept(
        array $idea,
        string $actorId,
        string $reason = ''
    ): array {
        $readiness =
            $this->calculateConversionReadiness(
                $idea
            );

        if (
            ($readiness['acceptable'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Idea acceptance requirements are incomplete.'
            );
        }

        return $this->transition(
            $idea,
            'accepted',
            $actorId,
            $reason
        );
    }

    /**
     * Reject one idea.
     */
    public function reject(
        array $idea,
        string $actorId,
        string $reason
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Idea rejection requires a reason.'
            );
        }

        return $this->transition(
            $idea,
            'rejected',
            $actorId,
            $reason
        );
    }

    /**
     * Archive one idea.
     */
    public function archive(
        array $idea,
        string $actorId,
        string $reason = ''
    ): array {
        return $this->transition(
            $idea,
            'archived',
            $actorId,
            $reason
        );
    }

    /**
     * Restore an archived idea.
     */
    public function restore(
        array $idea,
        string $actorId,
        string $status = 'draft',
        string $reason = ''
    ): array {
        if (
            ($idea['status'] ?? '')
            !== 'archived'
        ) {
            throw new RuntimeException(
                'Only archived ideas may be restored.'
            );
        }

        return $this->transition(
            $idea,
            $status,
            $actorId,
            $reason
        );
    }

    /**
     * Convert one accepted idea into an asset.
     *
     * @param array<string,mixed> $idea
     *
     * @return array<string,mixed>
     */
    public function convertToAsset(
        array $idea,
        string $actorId,
        array $overrides = []
    ): array {
        $this->assertIdea($idea);

        if (
            ($idea['status'] ?? '')
            !== 'accepted'
        ) {
            throw new RuntimeException(
                'Only accepted ideas may be converted into assets.'
            );
        }

        $readiness =
            $this->calculateConversionReadiness(
                $idea
            );

        if (
            ($readiness['convertible'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Idea conversion requirements are incomplete.'
            );
        }

        $asset = $this->assets->createFromIdea(
            $idea,
            $actorId,
            $overrides
        );

        $convertedIdea = $this->update(
            $idea,
            [
                'status' => 'converted',

                'converted_by' =>
                    $actorId,

                'converted_at' =>
                    gmdate('c'),

                'asset_id' =>
                    $asset['asset_id'],
            ],
            $actorId,
            'Idea converted to asset.'
        );

        return [
            'idea' => $convertedIdea,

            'asset' => $asset,

            'relationship' =>
                $this->relationships->create(
                    [
                        'source_id' =>
                            $idea['idea_id'],

                        'source_type' =>
                            'idea',

                        'target_id' =>
                            $asset['asset_id'],

                        'target_type' =>
                            'asset',

                        'relationship_type' =>
                            'became',

                        'status' =>
                            'verified',

                        'confidence' => 100,

                        'weight' => 1,

                        'strength' => 1,

                        'created_by' =>
                            $actorId,

                        'provenance_id' =>
                            $idea['provenance_id']
                            ?? '',

                        'metadata' => [
                            'conversion' => [
                                'converted_at' =>
                                    gmdate('c'),

                                'converted_by' =>
                                    $actorId,
                            ],
                        ],
                    ]
                ),
        ];
    }

    /**
     * Add one contributor.
     *
     * @param array<string,mixed> $contributor
     */
    public function addContributor(
        array $idea,
        array $contributor,
        string $actorId
    ): array {
        $normalized =
            $this->normalizeContributor(
                $contributor
            );

        $key = $this->contributorKey(
            $normalized
        );

        if ($key === '') {
            throw new InvalidArgumentException(
                'Contributor requires an identifier or email.'
            );
        }

        $contributors =
            $this->normalizeContributors(
                $idea['contributors']
                ?? []
            );

        $indexed = [];

        foreach ($contributors as $item) {
            $indexed[
                $this->contributorKey($item)
            ] = $item;
        }

        $indexed[$key] = $normalized;

        return $this->update(
            $idea,
            [
                'contributors' =>
                    array_values($indexed),
            ],
            $actorId,
            'Contributor added.'
        );
    }

    /**
     * Add tags.
     */
    public function addTags(
        array $idea,
        array|string $tags,
        string $actorId
    ): array {
        $current = $this->normalizeStringList(
            $idea['tags']
                ?? []
        );

        $incoming = $this->normalizeStringList(
            $tags
        );

        return $this->update(
            $idea,
            [
                'tags' => array_values(
                    array_unique(
                        array_merge(
                            $current,
                            $incoming
                        )
                    )
                ),
            ],
            $actorId,
            'Idea tags updated.'
        );
    }

    /**
     * Add evidence.
     */
    public function addEvidence(
        array $idea,
        array $evidence,
        string $actorId
    ): array {
        $existing = $this->normalizeEvidence(
            $idea['evidence']
                ?? []
        );

        $incoming = $this->normalizeEvidence(
            $evidence
        );

        return $this->update(
            $idea,
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
            'Idea evidence updated.'
        );
    }

    /**
     * Attach provenance.
     */
    public function attachProvenance(
        array $idea,
        string $provenanceId,
        string $actorId,
        string $sourceReference = ''
    ): array {
        $provenanceId = trim(
            $provenanceId
        );

        if ($provenanceId === '') {
            throw new InvalidArgumentException(
                'Provenance identifier is required.'
            );
        }

        return $this->update(
            $idea,
            [
                'provenance_id' =>
                    $provenanceId,

                'source_reference' =>
                    trim($sourceReference),
            ],
            $actorId,
            'Provenance attached.'
        );
    }

    /**
     * Compare one idea against candidate ideas.
     *
     * @param array<int,array<string,mixed>> $candidates
     * @param array<int,array<string,mixed>> $relationships
     *
     * @return array<string,mixed>
     */
    public function findSimilar(
        array $idea,
        array $candidates,
        array $relationships = [],
        array $options = []
    ): array {
        $this->assertIdea($idea);

        return $this->similarity->rank(
            $idea,
            $candidates,
            $relationships,
            array_merge(
                [
                    'minimum_score' => 45.0,
                    'limit' => 25,
                    'exclude_same_identifier' =>
                        true,
                ],
                $options
            )
        );
    }

    /**
     * Detect duplicate idea pairs.
     *
     * @param array<int,array<string,mixed>> $ideas
     */
    public function detectDuplicates(
        array $ideas,
        array $options = []
    ): array {
        return $this->similarity->detectDuplicates(
            $ideas,
            [],
            array_merge(
                [
                    'threshold' => 82.0,
                    'require_same_type' =>
                        false,
                ],
                $options
            )
        );
    }

    /**
     * Create a relationship from one idea to another entity.
     *
     * @param array<string,mixed> $target
     */
    public function createRelationship(
        array $idea,
        array $target,
        string $relationshipType,
        string $actorId,
        array $options = []
    ): array {
        $this->assertIdea($idea);

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
                        $idea['idea_id'],

                    'source_type' =>
                        'idea',

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

                    'confidence' => 100,

                    'weight' => 1,

                    'strength' => 1,

                    'created_by' =>
                        $actorId,

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
     * Convert idea into graph entity form.
     */
    public function toGraphEntity(
        array $idea
    ): array {
        $this->assertIdea($idea);

        return array_merge(
            $idea,
            [
                'entity_id' =>
                    $idea['idea_id'],

                'entity_type' =>
                    'idea',

                'graph_label' =>
                    $idea['title']
                    ?? $idea['idea_id'],

                'graph_status' =>
                    $idea['status']
                    ?? 'draft',
            ]
        );
    }

    /**
     * Validate one idea.
     *
     * @return array<string,mixed>
     */
    public function validate(
        array $idea
    ): array {
        $errors = [];
        $warnings = [];

        foreach (
            [
                'idea_id',
                'entity_id',
                'entity_type',
                'title',
                'idea',
                'idea_type',
                'status',
                'version',
                'created_by',
                'created_at',
                'updated_at',
            ]
            as $field
        ) {
            if (
                $this->valueIsEmpty(
                    $idea[$field]
                    ?? null
                )
            ) {
                $errors[] = sprintf(
                    'Idea field "%s" is required.',
                    $field
                );
            }
        }

        if (
            isset($idea['entity_type'])
            && $idea['entity_type']
                !== 'idea'
        ) {
            $errors[] =
                'Idea entity type must be "idea".';
        }

        try {
            $status = $this->normalizeStatus(
                (string)(
                    $idea['status']
                    ?? 'draft'
                )
            );
        } catch (Throwable $exception) {
            $status = '';

            $errors[] =
                $exception->getMessage();
        }

        if (
            $status === 'converted'
            && trim(
                (string)(
                    $idea['asset_id']
                    ?? ''
                )
            ) === ''
        ) {
            $errors[] =
                'Converted idea requires an asset identifier.';
        }

        if (
            ($idea['locked'] ?? false)
            === true
            && trim(
                (string)(
                    $idea['locked_by']
                    ?? ''
                )
            ) === ''
        ) {
            $errors[] =
                'Locked idea requires locking actor attribution.';
        }

        if (
            in_array(
                $status,
                [
                    'accepted',
                    'converted',
                ],
                true
            )
            && trim(
                (string)(
                    $idea['originator_id']
                    ?? ''
                )
            ) === ''
        ) {
            $errors[] =
                'Accepted idea requires originator attribution.';
        }

        if (
            trim(
                (string)(
                    $idea['description']
                    ?? ''
                )
            ) === ''
        ) {
            $warnings[] =
                'Idea description is empty.';
        }

        if (
            (
                $idea['tags']
                ?? []
            ) === []
        ) {
            $warnings[] =
                'Idea has no tags.';
        }

        $storedChecksum = trim(
            (string)(
                $idea['checksum']
                ?? ''
            )
        );

        if (
            $storedChecksum !== ''
            && !hash_equals(
                $storedChecksum,
                $this->calculateChecksum(
                    $idea
                )
            )
        ) {
            $errors[] =
                'Idea checksum does not match content.';
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
     * Inspect one idea.
     *
     * @return array<string,mixed>
     */
    public function inspect(
        array $idea
    ): array {
        $this->assertIdea($idea);

        return [
            'idea_id' =>
                $idea['idea_id'],

            'generated_at' =>
                gmdate('c'),

            'validation' =>
                $this->validate($idea),

            'completeness' =>
                $this->calculateCompleteness(
                    $idea
                ),

            'conversion_readiness' =>
                $this->calculateConversionReadiness(
                    $idea
                ),

            'checksum_valid' =>
                isset($idea['checksum'])
                && hash_equals(
                    (string)$idea['checksum'],
                    $this->calculateChecksum(
                        $idea
                    )
                ),

            'locked' =>
                (bool)(
                    $idea['locked']
                    ?? false
                ),

            'status' =>
                $idea['status']
                ?? 'draft',

            'available_transitions' =>
                $this->availableTransitions(
                    $idea
                ),
        ];
    }

    /**
     * Return available transitions.
     *
     * @return array<int,string>
     */
    public function availableTransitions(
        array $idea
    ): array {
        $status = $this->normalizeStatus(
            (string)(
                $idea['status']
                ?? 'draft'
            )
        );

        return $this->transitions[$status]
            ?? [];
    }

    /**
     * Return compact idea summary.
     *
     * @return array<string,mixed>
     */
    public function summarize(
        array $idea
    ): array {
        $this->assertIdea($idea);

        return [
            'idea_id' =>
                $idea['idea_id'],

            'title' =>
                $idea['title']
                ?? '',

            'idea_type' =>
                $idea['idea_type']
                ?? 'idea',

            'status' =>
                $idea['status']
                ?? 'draft',

            'version' =>
                $idea['version']
                ?? '1.0',

            'originator_id' =>
                $idea['originator_id']
                ?? null,

            'contributor_count' =>
                count(
                    $idea['contributors']
                    ?? []
                ),

            'tag_count' =>
                count(
                    $idea['tags']
                    ?? []
                ),

            'evidence_count' =>
                count(
                    $idea['evidence']
                    ?? []
                ),

            'locked' =>
                (bool)(
                    $idea['locked']
                    ?? false
                ),

            'asset_id' =>
                $idea['asset_id']
                ?? null,

            'completeness' =>
                $this->calculateCompleteness(
                    $idea
                ),

            'conversion_readiness' =>
                $this->calculateConversionReadiness(
                    $idea
                ),

            'created_at' =>
                $idea['created_at']
                ?? null,

            'updated_at' =>
                $idea['updated_at']
                ?? null,

            'checksum' =>
                $idea['checksum']
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

                'idea_types' =>
                    $this->ideaTypes,

                'immutable_fields' =>
                    $this->immutableFields,

                'completeness_weights' =>
                    $this->completenessWeights,

                'duplicate_detection_supported' =>
                    true,

                'asset_conversion_supported' =>
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
     * Calculate completeness score.
     */
    private function calculateCompleteness(
        array $idea
    ): float {
        $score = 0.0;

        foreach (
            $this->completenessWeights
            as $field => $weight
        ) {
            if (
                !$this->valueIsEmpty(
                    $idea[$field]
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
     * Calculate conversion readiness.
     *
     * @return array<string,mixed>
     */
    private function calculateConversionReadiness(
        array $idea
    ): array {
        $requirements = [
            'title' =>
                trim(
                    (string)(
                        $idea['title']
                        ?? ''
                    )
                ) !== '',

            'idea' =>
                trim(
                    (string)(
                        $idea['idea']
                        ?? ''
                    )
                ) !== '',

            'description' =>
                trim(
                    (string)(
                        $idea['description']
                        ?? ''
                    )
                ) !== '',

            'attribution' =>
                trim(
                    (string)(
                        $idea['originator_id']
                        ?? $idea['created_by']
                        ?? ''
                    )
                ) !== '',

            'classification' =>
                trim(
                    (string)(
                        $idea['idea_type']
                        ?? ''
                    )
                ) !== '',

            'status_for_acceptance' =>
                in_array(
                    (string)(
                        $idea['status']
                        ?? ''
                    ),
                    [
                        'submitted',
                        'under_review',
                        'accepted',
                    ],
                    true
                ),

            'status_for_conversion' =>
                (
                    $idea['status']
                    ?? ''
                ) === 'accepted',

            'checksum' =>
                trim(
                    (string)(
                        $idea['checksum']
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

            'acceptable' =>
                $requirements['title']
                && $requirements['idea']
                && $requirements[
                    'attribution'
                ]
                && $requirements[
                    'classification'
                ]
                && $requirements[
                    'status_for_acceptance'
                ],

            'convertible' =>
                $requirements['title']
                && $requirements['idea']
                && $requirements[
                    'description'
                ]
                && $requirements[
                    'attribution'
                ]
                && $requirements[
                    'classification'
                ]
                && $requirements[
                    'status_for_conversion'
                ]
                && $requirements['checksum'],

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
     * Normalize update value.
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

            'idea_type' =>
                $this->normalizeIdeaType(
                    (string)$value
                ),

            'category' =>
                $this->normalizeMachineKey(
                    (string)$value
                ),

            'language' =>
                $this->normalizeLanguage(
                    (string)$value
                ),

            'tags',
            'keywords',
            'classifications' =>
                $this->normalizeStringList(
                    $value
                ),

            'contributors' =>
                $this->normalizeContributors(
                    $value
                ),

            'evidence' =>
                $this->normalizeEvidence(
                    $value
                ),

            'locked' =>
                (bool)$value,

            'metadata' =>
                is_array($value)
                    ? $value
                    : [],

            default => $value,
        };
    }

    /**
     * Merge non-canonical fields.
     */
    private function mergeAdditionalFields(
        array $idea,
        array $input
    ): array {
        foreach ($input as $field => $value) {
            if (
                !array_key_exists(
                    $field,
                    $idea
                )
            ) {
                $idea[$field] = $value;
            }
        }

        return $idea;
    }

    /**
     * Normalize contributors.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeContributors(
        mixed $contributors
    ): array {
        if (is_string($contributors)) {
            $contributors = preg_split(
                '/[\r\n,;]+/',
                $contributors
            ) ?: [];
        }

        if (!is_array($contributors)) {
            return [];
        }

        $normalized = [];

        foreach ($contributors as $contributor) {
            if (is_string($contributor)) {
                $contributor = [
                    'contributor_id' =>
                        trim($contributor),
                ];
            }

            if (!is_array($contributor)) {
                continue;
            }

            $item = $this->normalizeContributor(
                $contributor
            );

            $key = $this->contributorKey(
                $item
            );

            if ($key !== '') {
                $normalized[$key] = $item;
            }
        }

        return array_values($normalized);
    }

    /**
     * Normalize one contributor.
     *
     * @return array<string,mixed>
     */
    private function normalizeContributor(
        array $contributor
    ): array {
        return [
            'contributor_id' => trim(
                (string)(
                    $contributor[
                        'contributor_id'
                    ]
                    ?? $contributor['id']
                    ?? ''
                )
            ),

            'name' => trim(
                (string)(
                    $contributor['name']
                    ?? ''
                )
            ),

            'email' => strtolower(
                trim(
                    (string)(
                        $contributor['email']
                        ?? ''
                    )
                )
            ),

            'role' =>
                $this->normalizeMachineKey(
                    (string)(
                        $contributor['role']
                        ?? 'contributor'
                    )
                ),

            'contribution' => trim(
                (string)(
                    $contributor[
                        'contribution'
                    ] ?? ''
                )
            ),

            'attributed_at' =>
                $contributor[
                    'attributed_at'
                ] ?? gmdate('c'),

            'metadata' => is_array(
                $contributor['metadata']
                    ?? null
            )
                ? $contributor['metadata']
                : [],
        ];
    }

    /**
     * Build contributor key.
     */
    private function contributorKey(
        array $contributor
    ): string {
        $id = trim(
            (string)(
                $contributor[
                    'contributor_id'
                ] ?? ''
            )
        );

        if ($id !== '') {
            return 'id:' . $id;
        }

        $email = strtolower(
            trim(
                (string)(
                    $contributor['email']
                    ?? ''
                )
            )
        );

        return $email !== ''
            ? 'email:' . $email
            : '';
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
                        $item['source_reference']
                        ?? $item['url']
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
                    $item['created_at']
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
     * Resolve generic record ID.
     */
    private function resolveRecordId(
        array $record
    ): string {
        foreach (
            [
                'entity_id',
                'idea_id',
                'asset_id',
                'program_id',
                'document_id',
                'organization_id',
                'person_id',
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
     * Assert canonical idea record.
     */
    private function assertIdea(
        array $idea
    ): void {
        if (
            trim(
                (string)(
                    $idea['idea_id']
                    ?? ''
                )
            ) === ''
        ) {
            throw new InvalidArgumentException(
                'Idea record requires idea_id.'
            );
        }
    }

    /**
     * Normalize idea type.
     */
    private function normalizeIdeaType(
        string $ideaType
    ): string {
        $ideaType =
            $this->normalizeMachineKey(
                $ideaType
            );

        return $ideaType !== ''
            ? $ideaType
            : 'other';
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
                    'Unsupported idea status "%s".',
                    $status
                )
            );
        }

        return $status;
    }

    /**
     * Normalize language.
     */
    private function normalizeLanguage(
        string $language
    ): string {
        $language = strtolower(
            trim($language)
        );

        $language = str_replace(
            '_',
            '-',
            $language
        );

        return preg_replace(
            '/[^a-z0-9-]+/',
            '',
            $language
        ) ?: 'en';
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
     * Build title from idea text.
     */
    private function titleFromIdea(
        string $idea
    ): string {
        $idea = trim(
            preg_replace(
                '/\s+/',
                ' ',
                $idea
            ) ?? $idea
        );

        if ($idea === '') {
            return 'Untitled idea';
        }

        $maximum = 90;

        if (
            function_exists('mb_strlen')
            && mb_strlen(
                $idea,
                'UTF-8'
            ) > $maximum
        ) {
            return rtrim(
                mb_substr(
                    $idea,
                    0,
                    $maximum,
                    'UTF-8'
                )
            ) . '…';
        }

        if (strlen($idea) > $maximum) {
            return rtrim(
                substr(
                    $idea,
                    0,
                    $maximum
                )
            ) . '…';
        }

        return $idea;
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
     * Calculate deterministic checksum.
     */
    private function calculateChecksum(
        array $idea
    ): string {
        $copy = $idea;

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
                'Unable to calculate idea checksum.'
            );
        }

        return hash('sha256', $json);
    }

    /**
     * Generate idea identifier.
     */
    private function generateIdeaId(
        string $title,
        string $idea
    ): string {
        $slug = strtoupper(
            substr(
                preg_replace(
                    '/[^A-Za-z0-9]+/',
                    '',
                    $title
                ) ?: 'IDE',
                0,
                3
            )
        );

        if ($slug === '') {
            $slug = 'IDE';
        }

        $hash = strtoupper(
            substr(
                hash(
                    'sha256',
                    $title
                    . '|'
                    . $idea
                    . '|'
                    . microtime(true)
                ),
                0,
                10
            )
        );

        return 'IDE-'
            . $slug
            . '-'
            . gmdate('Ymd-His')
            . '-'
            . $hash;
    }

    /**
     * Generate evidence ID.
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