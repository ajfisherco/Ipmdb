<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/ProvenanceService.php
|--------------------------------------------------------------------------
| IPMdb Provenance Service
|--------------------------------------------------------------------------
|
| Builds, validates, compares, and preserves provenance records.
|
| Provenance answers:
| - Who contributed?
| - What was contributed?
| - Where did it come from?
| - When did it enter the system?
| - How was it transformed?
| - What evidence supports it?
| - Which entity, version, or source preceded it?
|
| This service performs no database operations.
| Repositories persist the records produced here.
|
| From memory to evidence.
| From conversation to asset.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/ValidationService.php';
require_once dirname(__DIR__) . '/core/Entity.php';
require_once dirname(__DIR__) . '/core/EntityCollection.php';

final class ProvenanceService extends Service
{
    private ValidationService $validator;

    /**
     * Canonical contributor types.
     *
     * @var array<int,string>
     */
    private array $contributorTypes = [
        'person',
        'organization',
        'ai',
        'community',
        'system',
        'anonymous',
        'unknown',
    ];

    /**
     * Canonical source types.
     *
     * @var array<int,string>
     */
    private array $sourceTypes = [
        'original',
        'conversation',
        'document',
        'url',
        'website',
        'email',
        'database',
        'api',
        'feed',
        'image',
        'video',
        'audio',
        'code',
        'dataset',
        'publication',
        'government_record',
        'legal_record',
        'observation',
        'memory',
        'import',
        'generated',
        'derived',
        'translated',
        'unknown',
    ];

    /**
     * Canonical transformation types.
     *
     * @var array<int,string>
     */
    private array $transformationTypes = [
        'created',
        'imported',
        'extracted',
        'transcribed',
        'translated',
        'summarized',
        'classified',
        'normalized',
        'merged',
        'split',
        'edited',
        'revised',
        'verified',
        'approved',
        'rejected',
        'archived',
        'restored',
        'generated',
        'derived',
        'related',
        'implemented',
    ];

    /**
     * Canonical verification states.
     *
     * @var array<int,string>
     */
    private array $verificationStates = [
        'unverified',
        'machine_checked',
        'human_checked',
        'source_confirmed',
        'verified',
        'disputed',
        'rejected',
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?ValidationService $validator = null
    ) {
        parent::__construct($config, $context);

        $this->validator = $validator
            ?? new ValidationService();
    }

    /**
     * Build a complete provenance record.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $this->reset();

        $record = [
            'provenance_id' => $this->normalizeIdentifier(
                (string)($input['provenance_id'] ?? '')
            ),

            'entity_id' => trim(
                (string)($input['entity_id'] ?? '')
            ),

            'entity_type' => $this->normalizeKey(
                (string)($input['entity_type'] ?? 'entity')
            ),

            'entity_version' => trim(
                (string)($input['entity_version'] ?? '1.0')
            ),

            'contributor_id' => trim(
                (string)($input['contributor_id'] ?? '')
            ),

            'contributor_type' => $this->normalizeContributorType(
                (string)($input['contributor_type'] ?? 'unknown')
            ),

            'contributor_role' => $this->normalizeKey(
                (string)($input['contributor_role'] ?? 'originator')
            ),

            'source_type' => $this->normalizeSourceType(
                (string)($input['source_type'] ?? 'original')
            ),

            'source_reference' => trim(
                (string)($input['source_reference'] ?? '')
            ),

            'source_entity_id' => trim(
                (string)($input['source_entity_id'] ?? '')
            ),

            'source_entity_type' => $this->normalizeKey(
                (string)($input['source_entity_type'] ?? '')
            ),

            'source_version' => trim(
                (string)($input['source_version'] ?? '')
            ),

            'source_language' => trim(
                (string)($input['source_language'] ?? '')
            ),

            'transformation_type' => $this->normalizeTransformationType(
                (string)($input['transformation_type'] ?? 'created')
            ),

            'transformation_description' => trim(
                (string)($input['transformation_description'] ?? '')
            ),

            'tool_name' => trim(
                (string)($input['tool_name'] ?? '')
            ),

            'tool_version' => trim(
                (string)($input['tool_version'] ?? '')
            ),

            'provider' => trim(
                (string)($input['provider'] ?? '')
            ),

            'model' => trim(
                (string)($input['model'] ?? '')
            ),

            'prompt_reference' => trim(
                (string)($input['prompt_reference'] ?? '')
            ),

            'evidence' => $this->normalizeEvidence(
                $input['evidence'] ?? []
            ),

            'citations' => $this->normalizeStringList(
                $input['citations'] ?? []
            ),

            'license' => trim(
                (string)($input['license'] ?? '')
            ),

            'rights_holder' => trim(
                (string)($input['rights_holder'] ?? '')
            ),

            'verification_status' => $this->normalizeVerificationStatus(
                (string)($input['verification_status'] ?? 'unverified')
            ),

            'verified_by' => trim(
                (string)($input['verified_by'] ?? '')
            ),

            'confidence' => $this->normalizeConfidence(
                $input['confidence'] ?? null
            ),

            'checksum' => trim(
                (string)($input['checksum'] ?? '')
            ),

            'content_hash' => trim(
                (string)($input['content_hash'] ?? '')
            ),

            'notes' => trim(
                (string)($input['notes'] ?? '')
            ),

            'created_at' => trim(
                (string)($input['created_at'] ?? $this->now())
            ),

            'verified_at' => trim(
                (string)($input['verified_at'] ?? '')
            ),
        ];

        if ($record['provenance_id'] === '') {
            $record['provenance_id'] =
                $this->generateProvenanceId();
        }

        if (
            $record['content_hash'] === ''
            && array_key_exists('content', $input)
        ) {
            $record['content_hash'] = $this->hashContent(
                $input['content']
            );
        }

        if ($record['checksum'] === '') {
            $record['checksum'] = $this->checksum($record);
        }

        $this->validateOrFail($record);

        $this->addMessage(
            'Provenance record created.',
            [
                'provenance_id' => $record['provenance_id'],
                'entity_id' => $record['entity_id'],
                'entity_type' => $record['entity_type'],
                'transformation_type' =>
                    $record['transformation_type'],
            ]
        );

        return $record;
    }

    /**
     * Create provenance directly from an Entity.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function fromEntity(
        Entity $entity,
        array $context = []
    ): array {
        $data = $entity->toArray();

        return $this->create(
            array_merge(
                [
                    'entity_id' =>
                        $entity->get(
                            $entity->assetKey(),
                            $entity->get(
                                'entity_id',
                                ''
                            )
                        ),

                    'entity_type' =>
                        $entity->entityType(),

                    'entity_version' =>
                        $entity->get('version', '1.0'),

                    'contributor_id' =>
                        $entity->get(
                            'originator_id',
                            $context['contributor_id'] ?? ''
                        ),

                    'contributor_type' =>
                        $context['contributor_type']
                        ?? 'unknown',

                    'contributor_role' =>
                        $context['contributor_role']
                        ?? 'originator',

                    'source_type' =>
                        $entity->get(
                            'source_type',
                            $context['source_type'] ?? 'original'
                        ),

                    'source_reference' =>
                        $entity->get(
                            'source_reference',
                            $context['source_reference'] ?? ''
                        ),

                    'license' =>
                        $entity->get(
                            'license',
                            $context['license'] ?? ''
                        ),

                    'confidence' =>
                        $entity->get(
                            'confidence',
                            $context['confidence'] ?? null
                        ),

                    'content' => $data,

                    'transformation_type' =>
                        $context['transformation_type']
                        ?? (
                            $entity->exists()
                                ? 'revised'
                                : 'created'
                        ),
                ],
                $context
            )
        );
    }

    /**
     * Build provenance for a derived entity.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function derive(
        Entity $source,
        Entity $derived,
        string $transformationType,
        array $context = []
    ): array {
        $sourceId = $this->resolveEntityId($source);
        $derivedId = $this->resolveEntityId($derived);

        return $this->create(
            array_merge(
                [
                    'entity_id' => $derivedId,
                    'entity_type' => $derived->entityType(),
                    'entity_version' =>
                        $derived->get('version', '1.0'),

                    'source_entity_id' => $sourceId,
                    'source_entity_type' =>
                        $source->entityType(),
                    'source_version' =>
                        $source->get('version', '1.0'),

                    'source_type' => 'derived',

                    'transformation_type' =>
                        $transformationType,

                    'contributor_id' =>
                        $context['contributor_id']
                        ?? $derived->get(
                            'originator_id',
                            ''
                        ),

                    'contributor_type' =>
                        $context['contributor_type']
                        ?? 'unknown',

                    'contributor_role' =>
                        $context['contributor_role']
                        ?? 'contributor',

                    'content' => $derived->toArray(),
                ],
                $context
            )
        );
    }

    /**
     * Build provenance for imported external material.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function import(
        Entity $entity,
        string $sourceReference,
        string $sourceType = 'import',
        array $context = []
    ): array {
        $sourceReference = trim($sourceReference);

        if ($sourceReference === '') {
            throw new InvalidArgumentException(
                'Imported provenance requires a source reference.'
            );
        }

        return $this->fromEntity(
            $entity,
            array_merge(
                [
                    'source_type' =>
                        $this->normalizeSourceType($sourceType),

                    'source_reference' => $sourceReference,

                    'transformation_type' => 'imported',

                    'contributor_role' => 'importer',
                ],
                $context
            )
        );
    }

    /**
     * Build provenance for an AI-assisted transformation.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function aiAssisted(
        Entity $source,
        Entity $result,
        string $transformationType,
        string $provider,
        string $model,
        array $context = []
    ): array {
        $provider = trim($provider);
        $model = trim($model);

        if ($provider === '') {
            throw new InvalidArgumentException(
                'AI-assisted provenance requires a provider.'
            );
        }

        if ($model === '') {
            throw new InvalidArgumentException(
                'AI-assisted provenance requires a model.'
            );
        }

        return $this->derive(
            $source,
            $result,
            $transformationType,
            array_merge(
                [
                    'contributor_id' =>
                        $context['contributor_id']
                        ?? 'sq',

                    'contributor_type' => 'ai',

                    'contributor_role' =>
                        $context['contributor_role']
                        ?? 'assistant',

                    'provider' => $provider,

                    'model' => $model,

                    'tool_name' =>
                        $context['tool_name']
                        ?? 'IPMdb AI Assist',
                ],
                $context
            )
        );
    }

    /**
     * Validate a provenance record.
     *
     * @param array<string,mixed> $record
     */
    public function validate(array $record): bool
    {
        $this->reset();

        $required = [
            'provenance_id',
            'entity_id',
            'entity_type',
            'entity_version',
            'contributor_id',
            'contributor_type',
            'contributor_role',
            'source_type',
            'transformation_type',
            'verification_status',
            'created_at',
        ];

        foreach ($required as $field) {
            $value = $record[$field] ?? null;

            if ($this->isEmpty($value)) {
                $this->addError(
                    sprintf(
                        '%s is required.',
                        ucwords(
                            str_replace('_', ' ', $field)
                        )
                    ),
                    [
                        'field' => $field,
                    ]
                );
            }
        }

        if (
            isset($record['contributor_type'])
            && !in_array(
                (string)$record['contributor_type'],
                $this->contributorTypes,
                true
            )
        ) {
            $this->addError(
                'Contributor type is unsupported.'
            );
        }

        if (
            isset($record['source_type'])
            && !in_array(
                (string)$record['source_type'],
                $this->sourceTypes,
                true
            )
        ) {
            $this->addError(
                'Source type is unsupported.'
            );
        }

        if (
            isset($record['transformation_type'])
            && !in_array(
                (string)$record['transformation_type'],
                $this->transformationTypes,
                true
            )
        ) {
            $this->addError(
                'Transformation type is unsupported.'
            );
        }

        if (
            isset($record['verification_status'])
            && !in_array(
                (string)$record['verification_status'],
                $this->verificationStates,
                true
            )
        ) {
            $this->addError(
                'Verification status is unsupported.'
            );
        }

        $confidence = $record['confidence'] ?? null;

        if (
            $confidence !== null
            && $confidence !== ''
            && (
                !is_numeric($confidence)
                || (float)$confidence < 0
                || (float)$confidence > 100
            )
        ) {
            $this->addError(
                'Confidence must be between 0 and 100.'
            );
        }

        $verificationStatus = (string)(
            $record['verification_status']
            ?? ''
        );

        if (
            in_array(
                $verificationStatus,
                [
                    'human_checked',
                    'source_confirmed',
                    'verified',
                    'disputed',
                    'rejected',
                ],
                true
            )
            && trim(
                (string)($record['verified_by'] ?? '')
            ) === ''
        ) {
            $this->addError(
                'Verified or disputed provenance requires verifier attribution.'
            );
        }

        if (
            $verificationStatus === 'verified'
            && trim(
                (string)($record['verified_at'] ?? '')
            ) === ''
        ) {
            $this->addError(
                'Verified provenance requires a verification timestamp.'
            );
        }

        $sourceType = (string)(
            $record['source_type']
            ?? ''
        );

        if (
            !in_array(
                $sourceType,
                [
                    'original',
                    'memory',
                    'observation',
                    'generated',
                    'unknown',
                ],
                true
            )
            && trim(
                (string)($record['source_reference'] ?? '')
            ) === ''
            && trim(
                (string)($record['source_entity_id'] ?? '')
            ) === ''
        ) {
            $this->addError(
                'External or derived provenance requires a source reference or source entity.'
            );
        }

        $contributorType = (string)(
            $record['contributor_type']
            ?? ''
        );

        if (
            $contributorType === 'ai'
            && trim(
                (string)($record['model'] ?? '')
            ) === ''
        ) {
            $this->addError(
                'AI provenance requires a model identifier.'
            );
        }

        if (
            $contributorType === 'ai'
            && trim(
                (string)($record['provider'] ?? '')
            ) === ''
        ) {
            $this->addError(
                'AI provenance requires a provider.'
            );
        }

        if ($this->succeeded()) {
            $this->addMessage(
                'Provenance validation passed.',
                [
                    'provenance_id' =>
                        $record['provenance_id']
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
     * Validate or throw.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function validateOrFail(array $record): array
    {
        if (!$this->validate($record)) {
            $messages = array_map(
                static fn (array $error): string =>
                    trim(
                        (string)($error['message'] ?? '')
                    ),
                $this->errors()
            );

            throw new RuntimeException(
                implode(
                    ' ',
                    array_values(
                        array_filter($messages)
                    )
                )
            );
        }

        return $record;
    }

    /**
     * Mark a provenance record as verified.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function verify(
        array $record,
        string $verifiedBy,
        string $status = 'verified',
        ?float $confidence = null,
        string $notes = ''
    ): array {
        $verifiedBy = trim($verifiedBy);

        if ($verifiedBy === '') {
            throw new InvalidArgumentException(
                'Verifier attribution is required.'
            );
        }

        $status = $this->normalizeVerificationStatus(
            $status
        );

        if (
            !in_array(
                $status,
                [
                    'machine_checked',
                    'human_checked',
                    'source_confirmed',
                    'verified',
                    'disputed',
                    'rejected',
                ],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Unsupported verification status.'
            );
        }

        $record['verification_status'] = $status;
        $record['verified_by'] = $verifiedBy;
        $record['verified_at'] = $this->now();

        if ($confidence !== null) {
            $record['confidence'] =
                $this->normalizeConfidence($confidence);
        }

        if (trim($notes) !== '') {
            $existing = trim(
                (string)($record['notes'] ?? '')
            );

            $record['notes'] = trim(
                $existing
                . ($existing !== '' ? "\n\n" : '')
                . trim($notes)
            );
        }

        $record['checksum'] = $this->checksum($record);

        $this->validateOrFail($record);

        $this->addMessage(
            'Provenance verification recorded.',
            [
                'provenance_id' =>
                    $record['provenance_id']
                    ?? null,
                'verification_status' => $status,
                'verified_by' => $verifiedBy,
            ]
        );

        return $record;
    }

    /**
     * Compare two provenance records.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
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
            $leftValue = $left[$field] ?? null;
            $rightValue = $right[$field] ?? null;

            if ($leftValue !== $rightValue) {
                $differences[$field] = [
                    'left' => $leftValue,
                    'right' => $rightValue,
                ];
            }
        }

        return [
            'left_provenance_id' =>
                $left['provenance_id'] ?? null,

            'right_provenance_id' =>
                $right['provenance_id'] ?? null,

            'same_entity' =>
                ($left['entity_id'] ?? null)
                === ($right['entity_id'] ?? null),

            'same_source' =>
                ($left['source_reference'] ?? null)
                === ($right['source_reference'] ?? null)
                && ($left['source_entity_id'] ?? null)
                === ($right['source_entity_id'] ?? null),

            'same_contributor' =>
                ($left['contributor_id'] ?? null)
                === ($right['contributor_id'] ?? null),

            'same_checksum' =>
                ($left['checksum'] ?? null)
                === ($right['checksum'] ?? null),

            'difference_count' =>
                count($differences),

            'differences' => $differences,
        ];
    }

    /**
     * Return the chain of provenance records leading to one entity.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    public function lineage(
        array $records,
        string $entityId
    ): array {
        $entityId = trim($entityId);

        if ($entityId === '') {
            return [];
        }

        $byEntity = [];

        foreach ($records as $record) {
            $recordEntityId = trim(
                (string)($record['entity_id'] ?? '')
            );

            if ($recordEntityId !== '') {
                $byEntity[$recordEntityId][] = $record;
            }
        }

        $lineage = [];
        $visited = [];
        $queue = [$entityId];

        while ($queue !== []) {
            $currentEntityId = array_shift($queue);

            if (
                $currentEntityId === null
                || isset($visited[$currentEntityId])
            ) {
                continue;
            }

            $visited[$currentEntityId] = true;

            foreach (
                $byEntity[$currentEntityId] ?? []
                as $record
            ) {
                $lineage[] = $record;

                $sourceEntityId = trim(
                    (string)(
                        $record['source_entity_id']
                        ?? ''
                    )
                );

                if (
                    $sourceEntityId !== ''
                    && !isset($visited[$sourceEntityId])
                ) {
                    $queue[] = $sourceEntityId;
                }
            }
        }

        usort(
            $lineage,
            static function (
                array $left,
                array $right
            ): int {
                return strcmp(
                    (string)($left['created_at'] ?? ''),
                    (string)($right['created_at'] ?? '')
                );
            }
        );

        return $lineage;
    }

    /**
     * Summarize provenance records.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<string,mixed>
     */
    public function summarize(array $records): array
    {
        $contributors = [];
        $sources = [];
        $transformations = [];
        $verification = [];
        $entities = [];

        foreach ($records as $record) {
            $contributor = trim(
                (string)($record['contributor_id'] ?? '')
            );

            if ($contributor !== '') {
                $contributors[$contributor] =
                    ($contributors[$contributor] ?? 0) + 1;
            }

            $sourceType = trim(
                (string)($record['source_type'] ?? '')
            );

            if ($sourceType !== '') {
                $sources[$sourceType] =
                    ($sources[$sourceType] ?? 0) + 1;
            }

            $transformation = trim(
                (string)(
                    $record['transformation_type']
                    ?? ''
                )
            );

            if ($transformation !== '') {
                $transformations[$transformation] =
                    ($transformations[$transformation] ?? 0) + 1;
            }

            $verificationStatus = trim(
                (string)(
                    $record['verification_status']
                    ?? ''
                )
            );

            if ($verificationStatus !== '') {
                $verification[$verificationStatus] =
                    ($verification[$verificationStatus] ?? 0) + 1;
            }

            $entityId = trim(
                (string)($record['entity_id'] ?? '')
            );

            if ($entityId !== '') {
                $entities[$entityId] = true;
            }
        }

        arsort($contributors);
        arsort($sources);
        arsort($transformations);
        arsort($verification);

        return [
            'record_count' => count($records),
            'entity_count' => count($entities),
            'contributor_count' => count($contributors),
            'contributors' => $contributors,
            'sources' => $sources,
            'transformations' => $transformations,
            'verification' => $verification,
        ];
    }

    /**
     * Produce a deterministic checksum for the record.
     *
     * @param array<string,mixed> $record
     */
    public function checksum(array $record): string
    {
        $copy = $record;

        unset($copy['checksum']);

        $normalized = $this->normalizeForHash(
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
                'Unable to encode provenance for checksum.'
            );
        }

        return hash('sha256', $json);
    }

    /**
     * Hash arbitrary content.
     */
    public function hashContent(mixed $content): string
    {
        if (is_string($content)) {
            $value = $content;
        } else {
            $value = json_encode(
                $this->normalizeForHash($content),
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
            );

            if ($value === false) {
                $value = serialize($content);
            }
        }

        return hash('sha256', $value);
    }

    /**
     * Determine whether a record checksum remains valid.
     *
     * @param array<string,mixed> $record
     */
    public function checksumMatches(array $record): bool
    {
        $stored = trim(
            (string)($record['checksum'] ?? '')
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
     * Return only the core provenance fields from an entity.
     *
     * @return array<string,mixed>
     */
    public function extract(Entity $entity): array
    {
        $fields = [
            'originator_id',
            'source_type',
            'source_reference',
            'license',
            'confidence',
            'created_at',
            'updated_at',
        ];

        $result = [
            'entity_id' => $this->resolveEntityId($entity),
            'entity_type' => $entity->entityType(),
            'entity_version' =>
                $entity->get('version', '1.0'),
        ];

        foreach ($fields as $field) {
            if ($entity->has($field)) {
                $result[$field] =
                    $entity->get($field);
            }
        }

        return $result;
    }

    /**
     * Return provenance records for one entity.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    public function forEntity(
        array $records,
        string $entityId
    ): array {
        $entityId = trim($entityId);

        return array_values(
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
    }

    /**
     * Return provenance records from one contributor.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    public function byContributor(
        array $records,
        string $contributorId
    ): array {
        $contributorId = trim($contributorId);

        return array_values(
            array_filter(
                $records,
                static function (
                    array $record
                ) use ($contributorId): bool {
                    return trim(
                        (string)(
                            $record['contributor_id']
                            ?? ''
                        )
                    ) === $contributorId;
                }
            )
        );
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
                (string)$entity->get($key, '')
            );

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeContributorType(
        string $type
    ): string {
        $type = $this->normalizeKey($type);

        return in_array(
            $type,
            $this->contributorTypes,
            true
        )
            ? $type
            : 'unknown';
    }

    private function normalizeSourceType(
        string $type
    ): string {
        $type = $this->normalizeKey($type);

        return in_array(
            $type,
            $this->sourceTypes,
            true
        )
            ? $type
            : 'unknown';
    }

    private function normalizeTransformationType(
        string $type
    ): string {
        $type = $this->normalizeKey($type);

        return in_array(
            $type,
            $this->transformationTypes,
            true
        )
            ? $type
            : 'derived';
    }

    private function normalizeVerificationStatus(
        string $status
    ): string {
        $status = $this->normalizeKey($status);

        return in_array(
            $status,
            $this->verificationStates,
            true
        )
            ? $status
            : 'unverified';
    }

    private function normalizeConfidence(
        mixed $confidence
    ): ?float {
        if (
            $confidence === null
            || $confidence === ''
        ) {
            return null;
        }

        if (!is_numeric($confidence)) {
            throw new InvalidArgumentException(
                'Confidence must be numeric.'
            );
        }

        $confidence = (float)$confidence;

        if (
            $confidence < 0
            || $confidence > 100
        ) {
            throw new InvalidArgumentException(
                'Confidence must be between 0 and 100.'
            );
        }

        return round($confidence, 2);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeEvidence(
        mixed $evidence
    ): array {
        if (is_string($evidence)) {
            $evidence = trim($evidence);

            return $evidence === ''
                ? []
                : [
                    [
                        'type' => 'note',
                        'value' => $evidence,
                    ],
                ];
        }

        if (!is_array($evidence)) {
            return [];
        }

        $normalized = [];

        foreach ($evidence as $item) {
            if (is_string($item)) {
                $item = trim($item);

                if ($item !== '') {
                    $normalized[] = [
                        'type' => 'reference',
                        'value' => $item,
                    ];
                }

                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            $type = $this->normalizeKey(
                (string)($item['type'] ?? 'reference')
            );

            $value = trim(
                (string)(
                    $item['value']
                    ?? $item['reference']
                    ?? $item['url']
                    ?? ''
                )
            );

            if ($value === '') {
                continue;
            }

            $normalized[] = [
                'type' => $type !== ''
                    ? $type
                    : 'reference',

                'value' => $value,

                'label' => trim(
                    (string)($item['label'] ?? '')
                ),

                'checksum' => trim(
                    (string)($item['checksum'] ?? '')
                ),

                'verified' => (bool)(
                    $item['verified']
                    ?? false
                ),
            ];
        }

        return $normalized;
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
            $value = trim((string)$value);

            if ($value !== '') {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    private function normalizeIdentifier(
        string $identifier
    ): string {
        $identifier = strtoupper(
            trim($identifier)
        );

        return preg_replace(
            '/[^A-Z0-9\-_.]+/',
            '',
            $identifier
        ) ?? '';
    }

    private function generateProvenanceId(): string
    {
        try {
            $random = strtoupper(
                bin2hex(random_bytes(6))
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

        return 'PRV-'
            . gmdate('Ymd-His')
            . '-'
            . $random;
    }

    private function normalizeForHash(
        mixed $value
    ): mixed {
        if (!is_array($value)) {
            if (is_object($value)) {
                return method_exists($value, 'toArray')
                    ? $this->normalizeForHash(
                        $value->toArray()
                    )
                    : get_object_vars($value);
            }

            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed =>
                    $this->normalizeForHash($item),
                $value
            );
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] =
                $this->normalizeForHash($item);
        }

        return $value;
    }

    private function isEmpty(mixed $value): bool
    {
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
                'contributor_types' =>
                    $this->contributorTypes,

                'source_types' =>
                    $this->sourceTypes,

                'transformation_types' =>
                    $this->transformationTypes,

                'verification_states' =>
                    $this->verificationStates,

                'checksum_algorithm' =>
                    'sha256',
            ]
        );
    }
}