<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/VersionService.php
|--------------------------------------------------------------------------
| IPMdb Version Service
|--------------------------------------------------------------------------
|
| Creates, compares, restores, and verifies immutable entity snapshots.
|
| Every revision has history.
| Every change has attribution.
| Every restoration creates another version.
|
| This service performs no database operations.
| Repository classes will persist the version records produced here.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/ValidationService.php';
require_once __DIR__ . '/ProvenanceService.php';
require_once dirname(__DIR__) . '/core/Entity.php';
require_once dirname(__DIR__) . '/core/EntityCollection.php';

final class VersionService extends Service
{
    private ValidationService $validator;

    private ProvenanceService $provenance;

    /**
     * @var array<int,string>
     */
    private array $changeTypes = [
        'created',
        'edited',
        'corrected',
        'expanded',
        'reduced',
        'translated',
        'imported',
        'merged',
        'split',
        'classified',
        'verified',
        'approved',
        'implemented',
        'archived',
        'restored',
        'system',
    ];

    /**
     * Fields excluded from ordinary content comparison.
     *
     * @var array<int,string>
     */
    private array $comparisonExclusions = [
        'updated_at',
        'created_at',
        'verified_at',
        'approved_at',
        'archived_at',
        'checksum',
        'content_hash',
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?ValidationService $validator = null,
        ?ProvenanceService $provenance = null
    ) {
        parent::__construct($config, $context);

        $this->validator = $validator
            ?? new ValidationService();

        $this->provenance = $provenance
            ?? new ProvenanceService();
    }

    /**
     * Create the first immutable snapshot for an entity.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function initialize(
        Entity $entity,
        string $contributorId,
        array $context = []
    ): array {
        return $this->snapshot(
            $entity,
            $contributorId,
            'created',
            $context['change_summary']
                ?? 'Initial entity version.',
            array_merge(
                $context,
                [
                    'parent_version_id' => null,
                    'previous_version' => null,
                ]
            )
        );
    }

    /**
     * Create an immutable snapshot of the entity's current state.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function snapshot(
        Entity $entity,
        string $contributorId,
        string $changeType = 'edited',
        string $changeSummary = '',
        array $context = []
    ): array {
        $this->reset();

        $contributorId = trim($contributorId);

        if ($contributorId === '') {
            throw new InvalidArgumentException(
                'Version contributor attribution is required.'
            );
        }

        $this->validator->validateEntityOrFail($entity);

        $entityId = $this->resolveEntityId($entity);

        if ($entityId === '') {
            throw new RuntimeException(
                'Entity requires a public identifier before versioning.'
            );
        }

        $changeType = $this->normalizeChangeType(
            $changeType
        );

        $entityVersion = $this->normalizeVersion(
            (string)$entity->get('version', '1.0')
        );

        $snapshotData = $this->normalizeForStorage(
            $entity->toArray()
        );

        $contentHash = $this->hashSnapshot(
            $snapshotData
        );

        $record = [
            'version_id' => $this->generateVersionId(),

            'entity_id' => $entityId,

            'entity_type' => $entity->entityType(),

            'entity_version' => $entityVersion,

            'parent_version_id' => $this->nullableString(
                $context['parent_version_id']
                ?? null
            ),

            'previous_version' => $this->nullableString(
                $context['previous_version']
                ?? null
            ),

            'change_type' => $changeType,

            'change_summary' => trim(
                $changeSummary
            ),

            'change_reason' => trim(
                (string)(
                    $context['change_reason']
                    ?? ''
                )
            ),

            'contributor_id' => $contributorId,

            'contributor_type' => $this->normalizeKey(
                (string)(
                    $context['contributor_type']
                    ?? 'person'
                )
            ),

            'contributor_role' => $this->normalizeKey(
                (string)(
                    $context['contributor_role']
                    ?? 'editor'
                )
            ),

            'snapshot' => $snapshotData,

            'changed_fields' => $this->normalizeStringList(
                $context['changed_fields']
                ?? array_keys($entity->changes())
            ),

            'content_hash' => $contentHash,

            'checksum' => '',

            'source_reference' => trim(
                (string)(
                    $context['source_reference']
                    ?? $entity->get(
                        'source_reference',
                        ''
                    )
                )
            ),

            'provenance_id' => trim(
                (string)(
                    $context['provenance_id']
                    ?? ''
                )
            ),

            'status' => $this->normalizeStatus(
                (string)(
                    $context['status']
                    ?? 'active'
                )
            ),

            'locked' => true,

            'created_at' => trim(
                (string)(
                    $context['created_at']
                    ?? $this->now()
                )
            ),
        ];

        if ($record['provenance_id'] === '') {
            $provenance = $this->provenance->fromEntity(
                $entity,
                [
                    'contributor_id' => $contributorId,
                    'contributor_type' =>
                        $record['contributor_type'],
                    'contributor_role' =>
                        $record['contributor_role'],
                    'transformation_type' =>
                        $this->provenanceTransformation(
                            $changeType
                        ),
                    'source_reference' =>
                        $record['source_reference'],
                ]
            );

            $record['provenance_id'] =
                (string)$provenance['provenance_id'];
        }

        $record['checksum'] = $this->checksum(
            $record
        );

        $this->validateRecordOrFail($record);

        $this->addMessage(
            'Entity version snapshot created.',
            [
                'version_id' => $record['version_id'],
                'entity_id' => $entityId,
                'entity_type' => $entity->entityType(),
                'entity_version' => $entityVersion,
                'change_type' => $changeType,
            ]
        );

        return $record;
    }

    /**
     * Create the next version from a previous snapshot and current entity.
     *
     * @param array<string,mixed> $previousRecord
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function revise(
        array $previousRecord,
        Entity $entity,
        string $contributorId,
        string $changeType = 'edited',
        string $changeSummary = '',
        array $context = []
    ): array {
        $this->validateRecordOrFail(
            $previousRecord
        );

        $previousEntityId = trim(
            (string)(
                $previousRecord['entity_id']
                ?? ''
            )
        );

        $currentEntityId = $this->resolveEntityId(
            $entity
        );

        if ($previousEntityId !== $currentEntityId) {
            throw new InvalidArgumentException(
                'Previous version and entity identifiers do not match.'
            );
        }

        $previousSnapshot = is_array(
            $previousRecord['snapshot']
            ?? null
        )
            ? $previousRecord['snapshot']
            : [];

        $currentSnapshot = $entity->toArray();

        $comparison = $this->compareSnapshots(
            $previousSnapshot,
            $currentSnapshot
        );

        if (
            $comparison['difference_count'] === 0
            && !($context['allow_unchanged'] ?? false)
        ) {
            throw new RuntimeException(
                'The entity contains no versionable changes.'
            );
        }

        $previousVersion = $this->normalizeVersion(
            (string)(
                $previousRecord['entity_version']
                ?? '1.0'
            )
        );

        $requestedVersion = trim(
            (string)(
                $context['next_version']
                ?? ''
            )
        );

        $nextVersion = $requestedVersion !== ''
            ? $this->normalizeVersion($requestedVersion)
            : $this->incrementVersion(
                $previousVersion,
                (string)(
                    $context['increment']
                    ?? 'patch'
                )
            );

        if ($entity->has('version')) {
            $entity->set(
                'version',
                $nextVersion
            );
        }

        return $this->snapshot(
            $entity,
            $contributorId,
            $changeType,
            $changeSummary,
            array_merge(
                $context,
                [
                    'parent_version_id' =>
                        $previousRecord['version_id']
                        ?? null,

                    'previous_version' =>
                        $previousVersion,

                    'changed_fields' =>
                        array_keys(
                            $comparison['differences']
                        ),
                ]
            )
        );
    }

    /**
     * Restore an earlier version into a new editable Entity.
     *
     * The historical record remains immutable.
     */
    public function restoreEntity(
        array $versionRecord
    ): Entity {
        $this->validateRecordOrFail(
            $versionRecord
        );

        $entityType = $this->normalizeKey(
            (string)(
                $versionRecord['entity_type']
                ?? ''
            )
        );

        $snapshot = $versionRecord['snapshot']
            ?? null;

        if (!is_array($snapshot)) {
            throw new RuntimeException(
                'Version snapshot is unavailable.'
            );
        }

        $entity = Entity::hydrate(
            $entityType,
            $snapshot
        );

        if ($entity->isLocked()) {
            $entity->unlock();
        }

        $entity->markPersisted(true);

        $this->addMessage(
            'Entity restored from version snapshot.',
            [
                'version_id' =>
                    $versionRecord['version_id']
                    ?? null,
                'entity_id' =>
                    $versionRecord['entity_id']
                    ?? null,
            ]
        );

        return $entity;
    }

    /**
     * Create a new version representing restoration of an older snapshot.
     *
     * @param array<string,mixed> $versionRecord
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function restoreAsNewVersion(
        array $versionRecord,
        string $contributorId,
        array $context = []
    ): array {
        $restored = $this->restoreEntity(
            $versionRecord
        );

        $currentVersion = $this->normalizeVersion(
            (string)(
                $context['current_version']
                ?? $versionRecord['entity_version']
                ?? '1.0'
            )
        );

        $nextVersion = $this->incrementVersion(
            $currentVersion,
            (string)(
                $context['increment']
                ?? 'patch'
            )
        );

        if ($restored->has('version')) {
            $restored->set(
                'version',
                $nextVersion
            );
        }

        if ($restored->has('updated_at')) {
            $restored->set(
                'updated_at',
                $this->now()
            );
        }

        return $this->snapshot(
            $restored,
            $contributorId,
            'restored',
            trim(
                (string)(
                    $context['change_summary']
                    ?? sprintf(
                        'Restored from version %s.',
                        $versionRecord['entity_version']
                        ?? 'unknown'
                    )
                )
            ),
            array_merge(
                $context,
                [
                    'parent_version_id' =>
                        $context['parent_version_id']
                        ?? $versionRecord['version_id']
                        ?? null,

                    'previous_version' =>
                        $currentVersion,

                    'source_reference' =>
                        $context['source_reference']
                        ?? (
                            'version:'
                            . (
                                $versionRecord['version_id']
                                ?? ''
                            )
                        ),
                ]
            )
        );
    }

    /**
     * Compare two Entity objects.
     *
     * @return array<string,mixed>
     */
    public function compareEntities(
        Entity $left,
        Entity $right
    ): array {
        return array_merge(
            [
                'left_entity_type' =>
                    $left->entityType(),

                'right_entity_type' =>
                    $right->entityType(),

                'same_entity_type' =>
                    $left->entityType()
                    === $right->entityType(),

                'left_entity_id' =>
                    $this->resolveEntityId($left),

                'right_entity_id' =>
                    $this->resolveEntityId($right),
            ],
            $this->compareSnapshots(
                $left->toArray(),
                $right->toArray()
            )
        );
    }

    /**
     * Compare two version records.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return array<string,mixed>
     */
    public function compareVersions(
        array $left,
        array $right
    ): array {
        $this->validateRecordOrFail($left);
        $this->validateRecordOrFail($right);

        $comparison = $this->compareSnapshots(
            is_array($left['snapshot'] ?? null)
                ? $left['snapshot']
                : [],

            is_array($right['snapshot'] ?? null)
                ? $right['snapshot']
                : []
        );

        return array_merge(
            [
                'left_version_id' =>
                    $left['version_id'] ?? null,

                'right_version_id' =>
                    $right['version_id'] ?? null,

                'left_version' =>
                    $left['entity_version'] ?? null,

                'right_version' =>
                    $right['entity_version'] ?? null,

                'same_entity' =>
                    ($left['entity_id'] ?? null)
                    === ($right['entity_id'] ?? null),

                'same_checksum' =>
                    ($left['checksum'] ?? null)
                    === ($right['checksum'] ?? null),

                'same_content_hash' =>
                    ($left['content_hash'] ?? null)
                    === ($right['content_hash'] ?? null),
            ],
            $comparison
        );
    }

    /**
     * Compare arbitrary snapshots.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return array<string,mixed>
     */
    public function compareSnapshots(
        array $left,
        array $right,
        array $excludedFields = []
    ): array {
        $excluded = array_fill_keys(
            array_unique(
                array_merge(
                    $this->comparisonExclusions,
                    array_map(
                        'strval',
                        $excludedFields
                    )
                )
            ),
            true
        );

        $fields = array_unique(
            array_merge(
                array_keys($left),
                array_keys($right)
            )
        );

        $differences = [];

        foreach ($fields as $field) {
            if (isset($excluded[$field])) {
                continue;
            }

            $before = $left[$field] ?? null;
            $after = $right[$field] ?? null;

            if (
                $this->normalizeForComparison($before)
                === $this->normalizeForComparison($after)
            ) {
                continue;
            }

            $differences[$field] = [
                'before' => $before,
                'after' => $after,
                'change' => $this->describeChange(
                    $before,
                    $after
                ),
            ];
        }

        return [
            'identical' => $differences === [],
            'difference_count' => count($differences),
            'changed_fields' => array_keys($differences),
            'differences' => $differences,
        ];
    }

    /**
     * Return records for one entity in chronological order.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    public function history(
        array $records,
        string $entityId,
        string $direction = 'asc'
    ): array {
        $entityId = trim($entityId);

        $history = array_values(
            array_filter(
                $records,
                static function (
                    array $record
                ) use ($entityId): bool {
                    return trim(
                        (string)(
                            $record['entity_id']
                            ?? ''
                        )
                    ) === $entityId;
                }
            )
        );

        usort(
            $history,
            static function (
                array $left,
                array $right
            ): int {
                $dateComparison = strcmp(
                    (string)(
                        $left['created_at']
                        ?? ''
                    ),
                    (string)(
                        $right['created_at']
                        ?? ''
                    )
                );

                if ($dateComparison !== 0) {
                    return $dateComparison;
                }

                return version_compare(
                    (string)(
                        $left['entity_version']
                        ?? '0'
                    ),
                    (string)(
                        $right['entity_version']
                        ?? '0'
                    )
                );
            }
        );

        if (strtolower(trim($direction)) === 'desc') {
            $history = array_reverse(
                $history
            );
        }

        return $history;
    }

    /**
     * Return the newest record for one entity.
     *
     * @param array<int,array<string,mixed>> $records
     */
    public function latest(
        array $records,
        string $entityId
    ): ?array {
        $history = $this->history(
            $records,
            $entityId,
            'desc'
        );

        return $history[0] ?? null;
    }

    /**
     * Locate one record by public version ID.
     *
     * @param array<int,array<string,mixed>> $records
     */
    public function findVersion(
        array $records,
        string $versionId
    ): ?array {
        $versionId = trim($versionId);

        foreach ($records as $record) {
            if (
                trim(
                    (string)(
                        $record['version_id']
                        ?? ''
                    )
                ) === $versionId
            ) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Validate an immutable version record.
     *
     * @param array<string,mixed> $record
     */
    public function validateRecord(
        array $record
    ): bool {
        $this->reset();

        $required = [
            'version_id',
            'entity_id',
            'entity_type',
            'entity_version',
            'change_type',
            'contributor_id',
            'contributor_type',
            'contributor_role',
            'snapshot',
            'content_hash',
            'checksum',
            'status',
            'created_at',
        ];

        foreach ($required as $field) {
            $value = $record[$field] ?? null;

            if ($this->isEmpty($value)) {
                $this->addError(
                    sprintf(
                        '%s is required.',
                        ucwords(
                            str_replace(
                                '_',
                                ' ',
                                $field
                            )
                        )
                    ),
                    [
                        'field' => $field,
                    ]
                );
            }
        }

        if (
            isset($record['snapshot'])
            && !is_array($record['snapshot'])
        ) {
            $this->addError(
                'Version snapshot must be an array.'
            );
        }

        $changeType = $this->normalizeKey(
            (string)(
                $record['change_type']
                ?? ''
            )
        );

        if (
            $changeType !== ''
            && !in_array(
                $changeType,
                $this->changeTypes,
                true
            )
        ) {
            $this->addError(
                'Version change type is unsupported.'
            );
        }

        $entityVersion = trim(
            (string)(
                $record['entity_version']
                ?? ''
            )
        );

        if (
            $entityVersion !== ''
            && !preg_match(
                '/^\d+(?:\.\d+){0,2}(?:[-+][A-Za-z0-9.-]+)?$/',
                $entityVersion
            )
        ) {
            $this->addError(
                'Entity version format is invalid.'
            );
        }

        if (
            isset($record['locked'])
            && $record['locked'] !== true
        ) {
            $this->addError(
                'Historical version records must remain locked.'
            );
        }

        if (
            trim(
                (string)(
                    $record['checksum']
                    ?? ''
                )
            ) !== ''
            && !$this->checksumMatches($record)
        ) {
            $this->addError(
                'Version checksum does not match the record.'
            );
        }

        if (
            is_array($record['snapshot'] ?? null)
        ) {
            $expectedHash = $this->hashSnapshot(
                $record['snapshot']
            );

            $storedHash = trim(
                (string)(
                    $record['content_hash']
                    ?? ''
                )
            );

            if (
                $storedHash !== ''
                && !hash_equals(
                    $storedHash,
                    $expectedHash
                )
            ) {
                $this->addError(
                    'Version content hash does not match the snapshot.'
                );
            }
        }

        if ($this->succeeded()) {
            $this->addMessage(
                'Version record validation passed.',
                [
                    'version_id' =>
                        $record['version_id']
                        ?? null,
                    'entity_id' =>
                        $record['entity_id']
                        ?? null,
                ]
            );
        }

        return $this->succeeded();
    }

    /**
     * Validate a version record or throw.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function validateRecordOrFail(
        array $record
    ): array {
        if (!$this->validateRecord($record)) {
            $messages = array_values(
                array_filter(
                    array_map(
                        static fn (
                            array $error
                        ): string => trim(
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

        return $record;
    }

    /**
     * Increment a semantic-style version number.
     */
    public function incrementVersion(
        string $version,
        string $level = 'patch'
    ): string {
        $version = $this->normalizeVersion(
            $version
        );

        $level = strtolower(
            trim($level)
        );

        $core = preg_split(
            '/[-+]/',
            $version,
            2
        )[0] ?? '1.0.0';

        $parts = array_map(
            'intval',
            explode('.', $core)
        );

        while (count($parts) < 3) {
            $parts[] = 0;
        }

        [$major, $minor, $patch] = array_slice(
            $parts,
            0,
            3
        );

        switch ($level) {
            case 'major':
                $major++;
                $minor = 0;
                $patch = 0;
                break;

            case 'minor':
                $minor++;
                $patch = 0;
                break;

            case 'patch':
            default:
                $patch++;
                break;
        }

        return sprintf(
            '%d.%d.%d',
            $major,
            $minor,
            $patch
        );
    }

    /**
     * Normalize a version into a usable format.
     */
    public function normalizeVersion(
        string $version
    ): string {
        $version = trim($version);

        if ($version === '') {
            return '1.0.0';
        }

        $version = ltrim(
            $version,
            "vV \t\n\r\0\x0B"
        );

        if (
            !preg_match(
                '/^\d+(?:\.\d+){0,2}(?:[-+][A-Za-z0-9.-]+)?$/',
                $version
            )
        ) {
            throw new InvalidArgumentException(
                'Version must use a numeric semantic format.'
            );
        }

        $suffix = '';

        if (
            preg_match(
                '/^([0-9.]+)(.*)$/',
                $version,
                $matches
            )
        ) {
            $core = $matches[1];
            $suffix = $matches[2] ?? '';
        } else {
            $core = $version;
        }

        $parts = explode(
            '.',
            $core
        );

        while (count($parts) < 3) {
            $parts[] = '0';
        }

        $parts = array_slice(
            $parts,
            0,
            3
        );

        $parts = array_map(
            static fn (string $part): string =>
                (string)(int)$part,
            $parts
        );

        return implode('.', $parts)
            . $suffix;
    }

    /**
     * Calculate a stable hash for snapshot content.
     *
     * @param array<string,mixed> $snapshot
     */
    public function hashSnapshot(
        array $snapshot
    ): string {
        $normalized = $this->normalizeForStorage(
            $snapshot
        );

        $json = json_encode(
            $normalized,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
        );

        if ($json === false) {
            throw new RuntimeException(
                'Unable to encode version snapshot.'
            );
        }

        return hash(
            'sha256',
            $json
        );
    }

    /**
     * Calculate a stable checksum for the complete version record.
     *
     * @param array<string,mixed> $record
     */
    public function checksum(
        array $record
    ): string {
        $copy = $record;

        unset($copy['checksum']);

        $normalized = $this->normalizeForStorage(
            $copy
        );

        $json = json_encode(
            $normalized,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
        );

        if ($json === false) {
            throw new RuntimeException(
                'Unable to encode version record.'
            );
        }

        return hash(
            'sha256',
            $json
        );
    }

    /**
     * Verify the stored version-record checksum.
     *
     * @param array<string,mixed> $record
     */
    public function checksumMatches(
        array $record
    ): bool {
        $stored = trim(
            (string)(
                $record['checksum']
                ?? ''
            )
        );

        if ($stored === '') {
            return false;
        }

        return hash_equals(
            $stored,
            $this->checksum($record)
        );
    }

    /**
     * Produce a compact history summary.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<string,mixed>
     */
    public function summarize(
        array $records
    ): array {
        $entities = [];
        $contributors = [];
        $changeTypes = [];
        $statuses = [];
        $earliest = null;
        $latest = null;

        foreach ($records as $record) {
            $entityId = trim(
                (string)(
                    $record['entity_id']
                    ?? ''
                )
            );

            if ($entityId !== '') {
                $entities[$entityId] = true;
            }

            $contributor = trim(
                (string)(
                    $record['contributor_id']
                    ?? ''
                )
            );

            if ($contributor !== '') {
                $contributors[$contributor] =
                    ($contributors[$contributor] ?? 0)
                    + 1;
            }

            $changeType = trim(
                (string)(
                    $record['change_type']
                    ?? ''
                )
            );

            if ($changeType !== '') {
                $changeTypes[$changeType] =
                    ($changeTypes[$changeType] ?? 0)
                    + 1;
            }

            $status = trim(
                (string)(
                    $record['status']
                    ?? ''
                )
            );

            if ($status !== '') {
                $statuses[$status] =
                    ($statuses[$status] ?? 0)
                    + 1;
            }

            $createdAt = trim(
                (string)(
                    $record['created_at']
                    ?? ''
                )
            );

            if ($createdAt !== '') {
                if (
                    $earliest === null
                    || strcmp(
                        $createdAt,
                        $earliest
                    ) < 0
                ) {
                    $earliest = $createdAt;
                }

                if (
                    $latest === null
                    || strcmp(
                        $createdAt,
                        $latest
                    ) > 0
                ) {
                    $latest = $createdAt;
                }
            }
        }

        arsort($contributors);
        arsort($changeTypes);
        arsort($statuses);

        return [
            'version_count' => count($records),
            'entity_count' => count($entities),
            'contributor_count' =>
                count($contributors),
            'contributors' => $contributors,
            'change_types' => $changeTypes,
            'statuses' => $statuses,
            'earliest_version_at' => $earliest,
            'latest_version_at' => $latest,
        ];
    }

    private function resolveEntityId(
        Entity $entity
    ): string {
        $keys = array_unique(
            array_filter(
                [
                    $entity->assetKey(),
                    'entity_id',
                    'asset_id',
                    'translation_id',
                    'relationship_id',
                    'mission_id',
                    'decision_id',
                    'url_id',
                    'document_id',
                    'id',
                ]
            )
        );

        foreach ($keys as $key) {
            $value = trim(
                (string)$entity->get(
                    $key,
                    ''
                )
            );

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeChangeType(
        string $changeType
    ): string {
        $changeType = $this->normalizeKey(
            $changeType
        );

        return in_array(
            $changeType,
            $this->changeTypes,
            true
        )
            ? $changeType
            : 'edited';
    }

    private function normalizeStatus(
        string $status
    ): string {
        $status = $this->normalizeKey(
            $status
        );

        $allowed = [
            'active',
            'verified',
            'superseded',
            'archived',
            'disputed',
        ];

        return in_array(
            $status,
            $allowed,
            true
        )
            ? $status
            : 'active';
    }

    private function provenanceTransformation(
        string $changeType
    ): string {
        $map = [
            'created' => 'created',
            'edited' => 'revised',
            'corrected' => 'revised',
            'expanded' => 'revised',
            'reduced' => 'revised',
            'translated' => 'translated',
            'imported' => 'imported',
            'merged' => 'merged',
            'split' => 'split',
            'classified' => 'classified',
            'verified' => 'verified',
            'approved' => 'approved',
            'implemented' => 'implemented',
            'archived' => 'archived',
            'restored' => 'restored',
            'system' => 'revised',
        ];

        return $map[$changeType]
            ?? 'revised';
    }

    private function describeChange(
        mixed $before,
        mixed $after
    ): string {
        if (
            $before === null
            || $before === ''
            || $before === []
        ) {
            return 'added';
        }

        if (
            $after === null
            || $after === ''
            || $after === []
        ) {
            return 'removed';
        }

        if (
            is_array($before)
            || is_array($after)
        ) {
            return 'structure_changed';
        }

        if (
            is_string($before)
            && is_string($after)
        ) {
            $beforeLength = function_exists('mb_strlen')
                ? mb_strlen($before)
                : strlen($before);

            $afterLength = function_exists('mb_strlen')
                ? mb_strlen($after)
                : strlen($after);

            if ($afterLength > $beforeLength) {
                return 'expanded';
            }

            if ($afterLength < $beforeLength) {
                return 'reduced';
            }
        }

        return 'changed';
    }

    private function normalizeForComparison(
        mixed $value
    ): mixed {
        if (is_string($value)) {
            return trim(
                preg_replace(
                    '/\s+/u',
                    ' ',
                    $value
                ) ?? $value
            );
        }

        if (!is_array($value)) {
            if (is_object($value)) {
                return method_exists(
                    $value,
                    'toArray'
                )
                    ? $this->normalizeForComparison(
                        $value->toArray()
                    )
                    : get_object_vars($value);
            }

            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed =>
                    $this->normalizeForComparison(
                        $item
                    ),
                $value
            );
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] =
                $this->normalizeForComparison(
                    $item
                );
        }

        return $value;
    }

    private function normalizeForStorage(
        mixed $value
    ): mixed {
        if (!is_array($value)) {
            if (is_object($value)) {
                if ($value instanceof JsonSerializable) {
                    return $this->normalizeForStorage(
                        $value->jsonSerialize()
                    );
                }

                if (method_exists($value, 'toArray')) {
                    return $this->normalizeForStorage(
                        $value->toArray()
                    );
                }

                return $this->normalizeForStorage(
                    get_object_vars($value)
                );
            }

            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed =>
                    $this->normalizeForStorage(
                        $item
                    ),
                $value
            );
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] =
                $this->normalizeForStorage(
                    $item
                );
        }

        return $value;
    }

    /**
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
            $value = trim(
                (string)$value
            );

            if ($value !== '') {
                $normalized[$value] = $value;
            }
        }

        return array_values(
            $normalized
        );
    }

    private function nullableString(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim(
            (string)$value
        );

        return $value !== ''
            ? $value
            : null;
    }

    private function generateVersionId(): string
    {
        try {
            $random = strtoupper(
                bin2hex(
                    random_bytes(6)
                )
            );
        } catch (Throwable) {
            $random = strtoupper(
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

        return 'VER-'
            . gmdate('Ymd-His')
            . '-'
            . $random;
    }

    private function isEmpty(
        mixed $value
    ): bool {
        return $value === null
            || $value === ''
            || (
                is_array($value)
                && $value === []
            );
    }

    public function diagnostics(): array
    {
        return array_merge(
            parent::diagnostics(),
            [
                'change_types' =>
                    $this->changeTypes,

                'comparison_exclusions' =>
                    $this->comparisonExclusions,

                'hash_algorithm' =>
                    'sha256',

                'historical_records_mutable' =>
                    false,

                'restoration_creates_version' =>
                    true,
            ]
        );
    }
}