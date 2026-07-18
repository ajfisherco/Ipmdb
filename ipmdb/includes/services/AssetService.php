<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/AssetService.php
|--------------------------------------------------------------------------
| IPMdb Asset Service
|--------------------------------------------------------------------------
|
| Coordinates the application-level lifecycle of an intellectual asset.
|
| Responsibilities:
| - Create canonical asset records from accepted ideas.
| - Normalize and validate asset input.
| - Maintain asset identity, status, version, provenance, and attribution.
| - Update asset content without losing revision context.
| - Manage lifecycle transitions.
| - Lock, unlock, verify, approve, deploy, archive, and restore assets.
| - Attach contributors, tags, classifications, evidence, and metadata.
| - Generate relationship-ready graph entities.
| - Calculate deterministic checksums and completeness measurements.
| - Produce explainable asset summaries and diagnostics.
|
| AssetService contains domain logic.
| Repository persists.
| VersionService records history.
| ProvenanceService records origin.
| EventService records activity.
| KnowledgeGraphService connects assets.
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
require_once __DIR__ . '/KnowledgeGraphService.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class AssetService extends Service
{
    use GraphUtilities;

    private ValidationService $validation;

    private ProvenanceService $provenance;

    private VersionService $versions;

    private EventService $events;

    private RelationshipService $relationships;

    private KnowledgeGraphService $graph;

    /**
     * Supported lifecycle states.
     *
     * @var array<int,string>
     */
    private array $statuses = [
        'draft',
        'proposed',
        'active',
        'verified',
        'approved',
        'deployed',
        'implemented',
        'completed',
        'disputed',
        'blocked',
        'rejected',
        'archived',
    ];

    /**
     * Allowed status transitions.
     *
     * @var array<string,array<int,string>>
     */
    private array $transitions = [
        'draft' => [
            'proposed',
            'active',
            'rejected',
            'archived',
        ],

        'proposed' => [
            'draft',
            'active',
            'verified',
            'rejected',
            'blocked',
            'archived',
        ],

        'active' => [
            'draft',
            'verified',
            'approved',
            'deployed',
            'disputed',
            'blocked',
            'rejected',
            'archived',
        ],

        'verified' => [
            'active',
            'approved',
            'deployed',
            'disputed',
            'blocked',
            'archived',
        ],

        'approved' => [
            'active',
            'verified',
            'deployed',
            'implemented',
            'blocked',
            'archived',
        ],

        'deployed' => [
            'implemented',
            'completed',
            'blocked',
            'archived',
        ],

        'implemented' => [
            'deployed',
            'completed',
            'blocked',
            'archived',
        ],

        'completed' => [
            'implemented',
            'archived',
        ],

        'disputed' => [
            'draft',
            'active',
            'verified',
            'rejected',
            'archived',
        ],

        'blocked' => [
            'draft',
            'proposed',
            'active',
            'approved',
            'rejected',
            'archived',
        ],

        'rejected' => [
            'draft',
            'proposed',
            'archived',
        ],

        'archived' => [
            'draft',
            'active',
        ],
    ];

    /**
     * Common asset types.
     *
     * @var array<int,string>
     */
    private array $assetTypes = [
        'idea',
        'concept',
        'proposal',
        'design',
        'invention',
        'process',
        'method',
        'system',
        'software',
        'document',
        'research',
        'model',
        'framework',
        'policy',
        'program',
        'mission',
        'objective',
        'decision',
        'media',
        'brand',
        'dataset',
        'translation',
        'other',
    ];

    /**
     * Fields protected after initial creation.
     *
     * @var array<int,string>
     */
    private array $immutableFields = [
        'asset_id',
        'entity_id',
        'entity_type',
        'created_at',
        'created_by',
        'originator_id',
        'originator_email',
        'idea_id',
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
     * Fields used to calculate asset completeness.
     *
     * @var array<string,float>
     */
    private array $completenessWeights = [
        'title' => 0.12,
        'description' => 0.14,
        'asset_type' => 0.08,
        'status' => 0.05,
        'originator_id' => 0.08,
        'provenance_id' => 0.12,
        'tags' => 0.06,
        'keywords' => 0.06,
        'category' => 0.05,
        'purpose' => 0.08,
        'objective' => 0.07,
        'evidence' => 0.05,
        'contributors' => 0.04,
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?ValidationService $validation = null,
        ?ProvenanceService $provenance = null,
        ?VersionService $versions = null,
        ?EventService $events = null,
        ?RelationshipService $relationships = null,
        ?KnowledgeGraphService $graph = null
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

        $this->graph = $graph
            ?? new KnowledgeGraphService();

        if (
            isset($config['asset_types'])
            && is_array($config['asset_types'])
        ) {
            $this->assetTypes = $this->normalizeStringList(
                array_merge(
                    $this->assetTypes,
                    $config['asset_types']
                )
            );
        }
    }

    /**
     * Create one canonical asset.
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
                'Asset creation requires actor attribution.'
            );
        }

        $title = trim(
            (string)(
                $input['title']
                ?? $input['name']
                ?? $input['idea']
                ?? ''
            )
        );

        if ($title === '') {
            throw new InvalidArgumentException(
                'Asset title is required.'
            );
        }

        $assetType = $this->normalizeAssetType(
            (string)(
                $input['asset_type']
                ?? $input['type']
                ?? 'idea'
            )
        );

        $assetId = trim(
            (string)(
                $input['asset_id']
                ?? ''
            )
        );

        if ($assetId === '') {
            $assetId = $this->generateAssetId(
                $title,
                $assetType
            );
        }

        $now = gmdate('c');

        $originatorId = trim(
            (string)(
                $input['originator_id']
                ?? $actorId
            )
        );

        $originatorEmail = trim(
            (string)(
                $input['originator_email']
                ?? ''
            )
        );

        $metadata = is_array(
            $input['metadata']
                ?? null
        )
            ? $input['metadata']
            : [];

        $metadata['asset_service'] = array_merge(
            is_array(
                $metadata['asset_service']
                    ?? null
            )
                ? $metadata['asset_service']
                : [],
            [
                'created_by_service' =>
                    static::class,

                'created_at' => $now,
            ]
        );

        $asset = [
            'asset_id' => $assetId,

            'entity_id' => $assetId,

            'entity_type' => 'asset',

            'idea_id' => trim(
                (string)(
                    $input['idea_id']
                    ?? ''
                )
            ),

            'title' => $title,

            'name' => trim(
                (string)(
                    $input['name']
                    ?? $title
                )
            ),

            'description' => trim(
                (string)(
                    $input['description']
                    ?? $input['summary']
                    ?? ''
                )
            ),

            'summary' => trim(
                (string)(
                    $input['summary']
                    ?? ''
                )
            ),

            'content' =>
                $input['content']
                ?? $input['idea']
                ?? '',

            'asset_type' => $assetType,

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

            'originator_email' =>
                $originatorEmail,

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

            'locked' => (bool)(
                $input['locked']
                ?? false
            ),

            'locked_by' => null,

            'locked_at' => null,

            'verified_by' => null,

            'verified_at' => null,

            'approved_by' => null,

            'approved_at' => null,

            'deployed_by' => null,

            'deployed_at' => null,

            'implemented_at' => null,

            'completed_at' => null,

            'archived_by' => null,

            'archived_at' => null,

            'rejection_reason' => null,

            'block_reason' => null,

            'dispute_reason' => null,

            'created_at' => $now,

            'updated_at' => $now,

            'metadata' => $metadata,

            'checksum' => '',
        ];

        $asset = $this->mergeAdditionalFields(
            $asset,
            $input
        );

        $asset['checksum'] =
            $this->calculateChecksum($asset);

        $asset['completeness'] =
            $this->calculateCompleteness(
                $asset
            );

        $asset['readiness'] =
            $this->calculateReadiness(
                $asset
            );

        $validation = $this->validate(
            $asset
        );

        if (
            ($validation['valid'] ?? false)
            !== true
        ) {
            throw new RuntimeException(
                'Asset validation failed: '
                . implode(
                    ' ',
                    $validation['errors']
                    ?? []
                )
            );
        }

        $this->addMessage(
            'Asset created.',
            [
                'asset_id' => $assetId,
                'asset_type' => $assetType,
                'status' => $asset['status'],
            ]
        );

        return $asset;
    }

    /**
     * Create an asset from an accepted idea record.
     *
     * @param array<string,mixed> $idea
     *
     * @return array<string,mixed>
     */
    public function createFromIdea(
        array $idea,
        string $actorId,
        array $overrides = []
    ): array {
        $ideaId = trim(
            (string)(
                $idea['idea_id']
                ?? $idea['entity_id']
                ?? $idea['id']
                ?? ''
            )
        );

        if ($ideaId === '') {
            throw new InvalidArgumentException(
                'Source idea requires an identifier.'
            );
        }

        $asset = $this->create(
            array_merge(
                [
                    'idea_id' => $ideaId,

                    'title' =>
                        $idea['title']
                        ?? $idea['idea']
                        ?? 'Untitled asset',

                    'description' =>
                        $idea['description']
                        ?? '',

                    'summary' =>
                        $idea['summary']
                        ?? '',

                    'content' =>
                        $idea['content']
                        ?? $idea['idea']
                        ?? '',

                    'asset_type' =>
                        $idea['asset_type']
                        ?? 'idea',

                    'category' =>
                        $idea['category']
                        ?? '',

                    'status' => 'draft',

                    'language' =>
                        $idea['language']
                        ?? 'en',

                    'originator_id' =>
                        $idea['originator_id']
                        ?? $actorId,

                    'originator_email' =>
                        $idea['originator_email']
                        ?? '',

                    'contributors' =>
                        $idea['contributors']
                        ?? [],

                    'tags' =>
                        $idea['tags']
                        ?? [],

                    'keywords' =>
                        $idea['keywords']
                        ?? [],

                    'provenance_id' =>
                        $idea['provenance_id']
                        ?? '',

                    'source_reference' =>
                        $idea['source_reference']
                        ?? '',

                    'created_by' =>
                        $actorId,

                    'metadata' => [
                        'created_from_idea' => [
                            'idea_id' => $ideaId,

                            'idea_checksum' =>
                                $idea['checksum']
                                ?? null,

                            'converted_at' =>
                                gmdate('c'),

                            'converted_by' =>
                                $actorId,
                        ],
                    ],
                ],
                $overrides
            ),
            $actorId
        );

        return $asset;
    }

    /**
     * Update one asset while preserving protected identity fields.
     *
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $changes
     *
     * @return array<string,mixed>
     */
    public function update(
        array $asset,
        array $changes,
        string $actorId,
        string $reason = ''
    ): array {
        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Asset update requires actor attribution.'
            );
        }

        $this->assertAsset($asset);

        if (
            ($asset['locked'] ?? false)
            === true
            && trim(
                (string)(
                    $asset['locked_by']
                    ?? ''
                )
            ) !== $actorId
        ) {
            throw new RuntimeException(
                'Asset is locked by another actor.'
            );
        }

        $updated = $asset;

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

            $updated[$field] = $this->normalizeFieldValue(
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
                    $asset['version']
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
                'Updated asset is invalid: '
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
     * Transition asset status.
     *
     * @param array<string,mixed> $asset
     *
     * @return array<string,mixed>
     */
    public function transition(
        array $asset,
        string $newStatus,
        string $actorId,
        string $reason = ''
    ): array {
        $this->assertAsset($asset);

        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Asset transition requires actor attribution.'
            );
        }

        $currentStatus = $this->normalizeStatus(
            (string)(
                $asset['status']
                ?? 'draft'
            )
        );

        $newStatus = $this->normalizeStatus(
            $newStatus
        );

        if ($currentStatus === $newStatus) {
            return $asset;
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
                    'Asset status cannot transition from "%s" to "%s".',
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
            case 'verified':
                $changes['verified_by'] =
                    $actorId;

                $changes['verified_at'] =
                    $now;
                break;

            case 'approved':
                $changes['approved_by'] =
                    $actorId;

                $changes['approved_at'] =
                    $now;
                break;

            case 'deployed':
                $changes['deployed_by'] =
                    $actorId;

                $changes['deployed_at'] =
                    $now;
                break;

            case 'implemented':
                $changes['implemented_at'] =
                    $now;
                break;

            case 'completed':
                $changes['completed_at'] =
                    $now;
                break;

            case 'archived':
                $changes['archived_by'] =
                    $actorId;

                $changes['archived_at'] =
                    $now;
                break;

            case 'rejected':
                $changes['rejection_reason'] =
                    trim($reason);
                break;

            case 'blocked':
                $changes['block_reason'] =
                    trim($reason);
                break;

            case 'disputed':
                $changes['dispute_reason'] =
                    trim($reason);
                break;
        }

        return $this->update(
            $asset,
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
     * Lock one asset.
     *
     * @param array<string,mixed> $asset
     *
     * @return array<string,mixed>
     */
    public function lock(
        array $asset,
        string $actorId,
        string $reason = ''
    ): array {
        $this->assertAsset($asset);

        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Asset lock requires actor attribution.'
            );
        }

        if (
            ($asset['locked'] ?? false)
            === true
        ) {
            $existingActor = trim(
                (string)(
                    $asset['locked_by']
                    ?? ''
                )
            );

            if ($existingActor === $actorId) {
                return $asset;
            }

            throw new RuntimeException(
                'Asset is already locked.'
            );
        }

        return $this->update(
            $asset,
            [
                'locked' => true,

                'locked_by' =>
                    $actorId,

                'locked_at' =>
                    gmdate('c'),
            ],
            $actorId,
            $reason !== ''
                ? $reason
                : 'Asset locked.'
        );
    }

    /**
     * Unlock one asset.
     *
     * @param array<string,mixed> $asset
     *
     * @return array<string,mixed>
     */
    public function unlock(
        array $asset,
        string $actorId,
        bool $force = false,
        string $reason = ''
    ): array {
        $this->assertAsset($asset);

        $actorId = trim($actorId);

        if ($actorId === '') {
            throw new InvalidArgumentException(
                'Asset unlock requires actor attribution.'
            );
        }

        if (
            ($asset['locked'] ?? false)
            !== true
        ) {
            return $asset;
        }

        $lockedBy = trim(
            (string)(
                $asset['locked_by']
                ?? ''
            )
        );

        if (
            !$force
            && $lockedBy !== ''
            && $lockedBy !== $actorId
        ) {
            throw new RuntimeException(
                'Only the locking actor may unlock this asset.'
            );
        }

        $updated = $asset;

        $updated['locked'] = false;
        $updated['locked_by'] = null;
        $updated['locked_at'] = null;
        $updated['updated_by'] = $actorId;
        $updated['updated_at'] = gmdate('c');

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

        return $updated;
    }

    /**
     * Verify one asset.
     */
    public function verify(
        array $asset,
        string $actorId,
        string $reason = ''
    ): array {
        if (
            trim(
                (string)(
                    $asset['provenance_id']
                    ?? ''
                )
            ) === ''
            && trim(
                (string)(
                    $asset['source_reference']
                    ?? ''
                )
            ) === ''
        ) {
            throw new RuntimeException(
                'Asset verification requires provenance.'
            );
        }

        return $this->transition(
            $asset,
            'verified',
            $actorId,
            $reason
        );
    }

    /**
     * Approve one asset.
     */
    public function approve(
        array $asset,
        string $actorId,
        string $reason = ''
    ): array {
        if (
            !in_array(
                (string)(
                    $asset['status']
                    ?? ''
                ),
                [
                    'active',
                    'verified',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Asset must be active or verified before approval.'
            );
        }

        return $this->transition(
            $asset,
            'approved',
            $actorId,
            $reason
        );
    }

    /**
     * Deploy one asset.
     */
    public function deploy(
        array $asset,
        string $actorId,
        string $reason = ''
    ): array {
        if (
            !in_array(
                (string)(
                    $asset['status']
                    ?? ''
                ),
                [
                    'active',
                    'verified',
                    'approved',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Asset is not ready for deployment.'
            );
        }

        if (
            (
                $asset['readiness']['ready']
                ?? false
            ) !== true
        ) {
            throw new RuntimeException(
                'Asset readiness requirements are incomplete.'
            );
        }

        return $this->transition(
            $asset,
            'deployed',
            $actorId,
            $reason
        );
    }

    /**
     * Archive one asset.
     */
    public function archive(
        array $asset,
        string $actorId,
        string $reason = ''
    ): array {
        return $this->transition(
            $asset,
            'archived',
            $actorId,
            $reason
        );
    }

    /**
     * Restore one archived asset.
     */
    public function restore(
        array $asset,
        string $actorId,
        string $status = 'draft',
        string $reason = ''
    ): array {
        if (
            ($asset['status'] ?? '')
            !== 'archived'
        ) {
            throw new RuntimeException(
                'Only archived assets may be restored.'
            );
        }

        return $this->transition(
            $asset,
            $status,
            $actorId,
            $reason
        );
    }

    /**
     * Add one contributor.
     *
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $contributor
     *
     * @return array<string,mixed>
     */
    public function addContributor(
        array $asset,
        array $contributor,
        string $actorId
    ): array {
        $normalized =
            $this->normalizeContributor(
                $contributor
            );

        if (
            ($normalized['contributor_id'] ?? '')
            === ''
            && ($normalized['email'] ?? '')
            === ''
        ) {
            throw new InvalidArgumentException(
                'Contributor requires an identifier or email.'
            );
        }

        $contributors =
            $this->normalizeContributors(
                $asset['contributors']
                ?? []
            );

        $key = $this->contributorKey(
            $normalized
        );

        $indexed = [];

        foreach ($contributors as $item) {
            $indexed[
                $this->contributorKey($item)
            ] = $item;
        }

        $indexed[$key] = $normalized;

        return $this->update(
            $asset,
            [
                'contributors' =>
                    array_values($indexed),
            ],
            $actorId,
            'Contributor added.'
        );
    }

    /**
     * Remove one contributor.
     *
     * @param array<string,mixed> $asset
     *
     * @return array<string,mixed>
     */
    public function removeContributor(
        array $asset,
        string $contributorId,
        string $actorId
    ): array {
        $contributorId = trim(
            $contributorId
        );

        $contributors = array_values(
            array_filter(
                $this->normalizeContributors(
                    $asset['contributors']
                    ?? []
                ),
                static function (
                    array $contributor
                ) use ($contributorId): bool {
                    return trim(
                        (string)(
                            $contributor[
                                'contributor_id'
                            ]
                            ?? $contributor['email']
                            ?? ''
                        )
                    ) !== $contributorId;
                }
            )
        );

        return $this->update(
            $asset,
            [
                'contributors' =>
                    $contributors,
            ],
            $actorId,
            'Contributor removed.'
        );
    }

    /**
     * Add tags without duplication.
     */
    public function addTags(
        array $asset,
        array|string $tags,
        string $actorId
    ): array {
        $current = $this->normalizeStringList(
            $asset['tags']
                ?? []
        );

        $incoming = $this->normalizeStringList(
            $tags
        );

        return $this->update(
            $asset,
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
            'Asset tags updated.'
        );
    }

    /**
     * Add evidence records.
     */
    public function addEvidence(
        array $asset,
        array $evidence,
        string $actorId
    ): array {
        $existing = $this->normalizeEvidence(
            $asset['evidence']
                ?? []
        );

        $incoming = $this->normalizeEvidence(
            $evidence
        );

        return $this->update(
            $asset,
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
            'Asset evidence updated.'
        );
    }

    /**
     * Attach provenance reference.
     */
    public function attachProvenance(
        array $asset,
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
            $asset,
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
     * Create a relationship from this asset to another entity.
     *
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $target
     *
     * @return array<string,mixed>
     */
    public function createRelationship(
        array $asset,
        array $target,
        string $relationshipType,
        string $actorId,
        array $options = []
    ): array {
        $this->assertAsset($asset);

        $targetId = $this->resolveRecordId(
            $target
        );

        if ($targetId === '') {
            throw new InvalidArgumentException(
                'Relationship target requires an identifier.'
            );
        }

        $targetType = $this->normalizeMachineKey(
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
                        $asset['asset_id'],

                    'source_type' =>
                        'asset',

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

                    'created_by' =>
                        $actorId,

                    'confidence' =>
                        100,

                    'weight' => 1,

                    'strength' => 1,

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
     * Convert an asset into graph entity form.
     *
     * @param array<string,mixed> $asset
     *
     * @return array<string,mixed>
     */
    public function toGraphEntity(
        array $asset
    ): array {
        $this->assertAsset($asset);

        return array_merge(
            $asset,
            [
                'entity_id' =>
                    $asset['asset_id'],

                'entity_type' =>
                    'asset',

                'graph_label' =>
                    $asset['title']
                    ?? $asset['asset_id'],

                'graph_status' =>
                    $asset['status']
                    ?? 'draft',
            ]
        );
    }

    /**
     * Validate one asset.
     *
     * @param array<string,mixed> $asset
     *
     * @return array<string,mixed>
     */
    public function validate(
        array $asset
    ): array {
        $errors = [];
        $warnings = [];

        foreach (
            [
                'asset_id',
                'entity_id',
                'entity_type',
                'title',
                'asset_type',
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
                    $asset[$field]
                    ?? null
                )
            ) {
                $errors[] = sprintf(
                    'Asset field "%s" is required.',
                    $field
                );
            }
        }

        if (
            isset($asset['entity_type'])
            && $asset['entity_type']
                !== 'asset'
        ) {
            $errors[] =
                'Asset entity type must be "asset".';
        }

        $status = $this->normalizeStatus(
            (string)(
                $asset['status']
                ?? 'draft'
            )
        );

        if (
            !in_array(
                $status,
                $this->statuses,
                true
            )
        ) {
            $errors[] =
                'Asset status is unsupported.';
        }

        $assetType = $this->normalizeAssetType(
            (string)(
                $asset['asset_type']
                ?? 'other'
            )
        );

        if (
            !in_array(
                $assetType,
                $this->assetTypes,
                true
            )
        ) {
            $warnings[] =
                'Asset type is custom.';
        }

        if (
            in_array(
                $status,
                [
                    'verified',
                    'approved',
                    'deployed',
                    'implemented',
                    'completed',
                ],
                true
            )
            && trim(
                (string)(
                    $asset['provenance_id']
                    ?? ''
                )
            ) === ''
            && trim(
                (string)(
                    $asset['source_reference']
                    ?? ''
                )
            ) === ''
        ) {
            $errors[] =
                'Trusted asset status requires provenance.';
        }

        if (
            ($asset['locked'] ?? false)
            === true
            && trim(
                (string)(
                    $asset['locked_by']
                    ?? ''
                )
            ) === ''
        ) {
            $errors[] =
                'Locked asset requires locking actor attribution.';
        }

        $storedChecksum = trim(
            (string)(
                $asset['checksum']
                ?? ''
            )
        );

        if (
            $storedChecksum !== ''
            && !hash_equals(
                $storedChecksum,
                $this->calculateChecksum(
                    $asset
                )
            )
        ) {
            $errors[] =
                'Asset checksum does not match content.';
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
     * Inspect asset readiness.
     *
     * @param array<string,mixed> $asset
     *
     * @return array<string,mixed>
     */
    public function inspect(
        array $asset
    ): array {
        $this->assertAsset($asset);

        $validation = $this->validate(
            $asset
        );

        $completeness =
            $this->calculateCompleteness(
                $asset
            );

        $readiness =
            $this->calculateReadiness(
                $asset
            );

        return [
            'asset_id' =>
                $asset['asset_id'],

            'generated_at' =>
                gmdate('c'),

            'validation' =>
                $validation,

            'completeness' =>
                $completeness,

            'readiness' =>
                $readiness,

            'checksum_valid' =>
                isset($asset['checksum'])
                && hash_equals(
                    (string)$asset[
                        'checksum'
                    ],
                    $this->calculateChecksum(
                        $asset
                    )
                ),

            'locked' =>
                (bool)(
                    $asset['locked']
                    ?? false
                ),

            'status' =>
                $asset['status']
                ?? 'draft',

            'available_transitions' =>
                $this->availableTransitions(
                    $asset
                ),
        ];
    }

    /**
     * Return available lifecycle transitions.
     *
     * @param array<string,mixed> $asset
     *
     * @return array<int,string>
     */
    public function availableTransitions(
        array $asset
    ): array {
        $status = $this->normalizeStatus(
            (string)(
                $asset['status']
                ?? 'draft'
            )
        );

        return $this->transitions[$status]
            ?? [];
    }

    /**
     * Compare asset revisions.
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
        $fields = array_unique(
            array_merge(
                array_keys($left),
                array_keys($right)
            )
        );

        $differences = [];

        foreach ($fields as $field) {
            $leftValue =
                $left[$field]
                ?? null;

            $rightValue =
                $right[$field]
                ?? null;

            if (
                $this->normalizeForHash(
                    $leftValue
                )
                ===
                $this->normalizeForHash(
                    $rightValue
                )
            ) {
                continue;
            }

            $differences[$field] = [
                'before' => $leftValue,
                'after' => $rightValue,
            ];
        }

        return [
            'identical' =>
                $differences === [],

            'difference_count' =>
                count($differences),

            'differences' =>
                $differences,

            'left_checksum' =>
                $this->calculateChecksum(
                    $left
                ),

            'right_checksum' =>
                $this->calculateChecksum(
                    $right
                ),
        ];
    }

    /**
     * Return compact asset summary.
     *
     * @param array<string,mixed> $asset
     *
     * @return array<string,mixed>
     */
    public function summarize(
        array $asset
    ): array {
        $this->assertAsset($asset);

        return [
            'asset_id' =>
                $asset['asset_id'],

            'title' =>
                $asset['title']
                ?? '',

            'asset_type' =>
                $asset['asset_type']
                ?? 'other',

            'status' =>
                $asset['status']
                ?? 'draft',

            'version' =>
                $asset['version']
                ?? '1.0',

            'originator_id' =>
                $asset['originator_id']
                ?? null,

            'contributor_count' =>
                count(
                    $asset['contributors']
                    ?? []
                ),

            'tag_count' =>
                count(
                    $asset['tags']
                    ?? []
                ),

            'evidence_count' =>
                count(
                    $asset['evidence']
                    ?? []
                ),

            'has_provenance' =>
                trim(
                    (string)(
                        $asset['provenance_id']
                        ?? $asset[
                            'source_reference'
                        ]
                        ?? ''
                    )
                ) !== '',

            'locked' =>
                (bool)(
                    $asset['locked']
                    ?? false
                ),

            'completeness' =>
                $this->calculateCompleteness(
                    $asset
                ),

            'readiness' =>
                $this->calculateReadiness(
                    $asset
                ),

            'created_at' =>
                $asset['created_at']
                ?? null,

            'updated_at' =>
                $asset['updated_at']
                ?? null,

            'checksum' =>
                $asset['checksum']
                ?? null,
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

                'transitions' =>
                    $this->transitions,

                'asset_types' =>
                    $this->assetTypes,

                'immutable_fields' =>
                    $this->immutableFields,

                'completeness_weights' =>
                    $this->completenessWeights,

                'database_operations' =>
                    false,

                'automatic_persistence' =>
                    false,

                'human_attribution_required' =>
                    true,

                'graph_entity_supported' =>
                    true,

                'graph_utilities' =>
                    $this->graphUtilityDiagnostics(),
            ]
        );
    }

    /**
     * Calculate completeness.
     *
     * @param array<string,mixed> $asset
     */
    private function calculateCompleteness(
        array $asset
    ): float {
        $score = 0.0;

        foreach (
            $this->completenessWeights
            as $field => $weight
        ) {
            if (
                !$this->valueIsEmpty(
                    $asset[$field]
                    ?? null
                )
            ) {
                $score += $weight;
            }
        }

        return round(
            min(1.0, $score)
            * 100,
            2
        );
    }

    /**
     * Calculate operational readiness.
     *
     * @param array<string,mixed> $asset
     *
     * @return array<string,mixed>
     */
    private function calculateReadiness(
        array $asset
    ): array {
        $requirements = [
            'title' =>
                trim(
                    (string)(
                        $asset['title']
                        ?? ''
                    )
                ) !== '',

            'description' =>
                trim(
                    (string)(
                        $asset['description']
                        ?? ''
                    )
                ) !== '',

            'attribution' =>
                trim(
                    (string)(
                        $asset['originator_id']
                        ?? $asset[
                            'created_by'
                        ]
                        ?? ''
                    )
                ) !== '',

            'provenance' =>
                trim(
                    (string)(
                        $asset['provenance_id']
                        ?? $asset[
                            'source_reference'
                        ]
                        ?? ''
                    )
                ) !== '',

            'classification' =>
                trim(
                    (string)(
                        $asset['asset_type']
                        ?? ''
                    )
                ) !== '',

            'status' =>
                in_array(
                    (string)(
                        $asset['status']
                        ?? ''
                    ),
                    [
                        'active',
                        'verified',
                        'approved',
                        'deployed',
                        'implemented',
                        'completed',
                    ],
                    true
                ),

            'checksum' =>
                trim(
                    (string)(
                        $asset['checksum']
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
                ($passed / $total)
                * 100,
                2
            )
            : 0.0;

        return [
            'ready' =>
                $score >= 85
                && $requirements[
                    'provenance'
                ]
                && $requirements[
                    'attribution'
                ]
                && $requirements[
                    'checksum'
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
        ];
    }

    /**
     * Normalize field value during update.
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

            'asset_type' =>
                $this->normalizeAssetType(
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
     * Preserve additional fields without overriding canonical fields.
     *
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    private function mergeAdditionalFields(
        array $asset,
        array $input
    ): array {
        foreach ($input as $field => $value) {
            if (
                !array_key_exists(
                    $field,
                    $asset
                )
            ) {
                $asset[$field] = $value;
            }
        }

        return $asset;
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
     * @param array<string,mixed> $contributor
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

            'role' => $this->normalizeMachineKey(
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
     *
     * @param array<string,mixed> $contributor
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
     * @param array<int,array<string,mixed>> $evidence
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
     * Resolve generic record identifier.
     *
     * @param array<string,mixed> $record
     */
    private function resolveRecordId(
        array $record
    ): string {
        foreach (
            [
                'entity_id',
                'asset_id',
                'idea_id',
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
     * Assert canonical asset structure.
     *
     * @param array<string,mixed> $asset
     */
    private function assertAsset(
        array $asset
    ): void {
        if (
            trim(
                (string)(
                    $asset['asset_id']
                    ?? ''
                )
            ) === ''
        ) {
            throw new InvalidArgumentException(
                'Asset record requires asset_id.'
            );
        }
    }

    /**
     * Normalize asset type.
     */
    private function normalizeAssetType(
        string $assetType
    ): string {
        $assetType = $this->normalizeMachineKey(
            $assetType
        );

        return $assetType !== ''
            ? $assetType
            : 'other';
    }

    /**
     * Normalize asset status.
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
                    'Unsupported asset status "%s".',
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
            $patch = isset($matches[3])
                ? (int)$matches[3]
                : null;

            if ($patch !== null) {
                return sprintf(
                    '%d.%d.%d',
                    $major,
                    $minor,
                    $patch + 1
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
     *
     * @param array<string,mixed> $asset
     */
    private function calculateChecksum(
        array $asset
    ): string {
        $copy = $asset;

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
                'Unable to calculate asset checksum.'
            );
        }

        return hash('sha256', $json);
    }

    /**
     * Generate public asset identifier.
     */
    private function generateAssetId(
        string $title,
        string $assetType
    ): string {
        $prefix = strtoupper(
            substr(
                preg_replace(
                    '/[^A-Za-z0-9]+/',
                    '',
                    $assetType
                ) ?: 'AST',
                0,
                3
            )
        );

        if ($prefix === '') {
            $prefix = 'AST';
        }

        return 'AST-'
            . $prefix
            . '-'
            . gmdate('Ymd-His')
            . '-'
            . $this->randomToken(5);
    }

    /**
     * Generate evidence identifier.
     *
     * @param array<string,mixed> $evidence
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