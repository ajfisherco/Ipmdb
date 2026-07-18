<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/RelationshipService.php
|--------------------------------------------------------------------------
| IPMdb Relationship Service
|--------------------------------------------------------------------------
|
| The Relationship Service is the heart of the IPMdb Knowledge Graph.
|
| Entities contain information.
| Relationships create knowledge.
|
| Every relationship is:
|
| • Typed
| • Directional
| • Weighted
| • Versioned
| • Provenanced
| • Searchable
| • Traceable
| • Explainable
|
| This service performs NO database operations.
|
| Repository classes persist relationships.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/ValidationService.php';
require_once __DIR__ . '/VersionService.php';
require_once __DIR__ . '/ProvenanceService.php';
require_once __DIR__ . '/EventService.php';

require_once dirname(__DIR__) . '/core/Entity.php';
require_once dirname(__DIR__) . '/core/EntityCollection.php';

final class RelationshipService extends Service
{
    private ValidationService $validator;

    private VersionService $versions;

    private ProvenanceService $provenance;

    private EventService $events;

    /**
     * Canonical relationship vocabulary.
     *
     * This list intentionally remains expandable.
     *
     * @var array<int,string>
     */
    private array $relationshipTypes = [

        /*
        |--------------------------------------------------------------------------
        | Structural
        |--------------------------------------------------------------------------
        */

        'contains',
        'contained_by',
        'parent_of',
        'child_of',
        'member_of',
        'has_member',
        'belongs_to',

        /*
        |--------------------------------------------------------------------------
        | Knowledge
        |--------------------------------------------------------------------------
        */

        'related_to',
        'references',
        'referenced_by',
        'supports',
        'supported_by',
        'contradicts',
        'extends',
        'derived_from',
        'derives',
        'explains',
        'documents',
        'implements',

        /*
        |--------------------------------------------------------------------------
        | Provenance
        |--------------------------------------------------------------------------
        */

        'created_by',
        'edited_by',
        'verified_by',
        'approved_by',
        'reviewed_by',
        'originated_from',

        /*
        |--------------------------------------------------------------------------
        | Decision
        |--------------------------------------------------------------------------
        */

        'depends_on',
        'required_by',
        'enables',
        'blocks',
        'replaces',
        'supersedes',

        /*
        |--------------------------------------------------------------------------
        | DAD
        |--------------------------------------------------------------------------
        */

        'funds',
        'funded_by',
        'benefits',
        'sponsors',

        /*
        |--------------------------------------------------------------------------
        | Government Alignment
        |--------------------------------------------------------------------------
        */

        'aligns_with',
        'administered_by',
        'regulated_by',
        'eligible_for',

        /*
        |--------------------------------------------------------------------------
        | Graph
        |--------------------------------------------------------------------------
        */

        'same_as',
        'inverse_of',
        'duplicate_of',

        /*
        |--------------------------------------------------------------------------
        | Future
        |--------------------------------------------------------------------------
        */

        'mission_of',
        'objective_of',
        'evidence_for',
        'evidence_against'
    ];

    /**
     * Relationship inverse lookup.
     *
     * @var array<string,string>
     */
    private array $inverse = [

        'contains'        => 'contained_by',
        'contained_by'    => 'contains',

        'parent_of'       => 'child_of',
        'child_of'        => 'parent_of',

        'member_of'       => 'has_member',
        'has_member'      => 'member_of',

        'supports'        => 'supported_by',
        'supported_by'    => 'supports',

        'references'      => 'referenced_by',
        'referenced_by'   => 'references',

        'funds'           => 'funded_by',
        'funded_by'       => 'funds',

        'derived_from'    => 'derives',
        'derives'         => 'derived_from'
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?ValidationService $validator = null,
        ?VersionService $versions = null,
        ?ProvenanceService $provenance = null,
        ?EventService $events = null
    ) {
        parent::__construct($config, $context);

        $this->validator = $validator
            ?? new ValidationService();

        $this->versions = $versions
            ?? new VersionService();

        $this->provenance = $provenance
            ?? new ProvenanceService();

        $this->events = $events
            ?? new EventService();
    }    /**
     * Create an immutable relationship record.
     *
     * A relationship is an edge connecting two entities.
     *
     * Relationships are first-class assets.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $this->reset();

        $relationshipType = $this->normalizeRelationshipType(
            (string)($input['relationship_type'] ?? 'related_to')
        );

        $record = [

            /*
            |--------------------------------------------------------------------------
            | Identity
            |--------------------------------------------------------------------------
            */

            'relationship_id' => $this->normalizeIdentifier(
                (string)($input['relationship_id'] ?? '')
            ),

            'relationship_type' => $relationshipType,

            /*
            |--------------------------------------------------------------------------
            | Graph
            |--------------------------------------------------------------------------
            */

            'source_id' => trim(
                (string)($input['source_id'] ?? '')
            ),

            'source_type' => $this->normalizeKey(
                (string)($input['source_type'] ?? '')
            ),

            'target_id' => trim(
                (string)($input['target_id'] ?? '')
            ),

            'target_type' => $this->normalizeKey(
                (string)($input['target_type'] ?? '')
            ),

            /*
            |--------------------------------------------------------------------------
            | Direction
            |--------------------------------------------------------------------------
            */

            'directional' => array_key_exists(
                'directional',
                $input
            )
                ? (bool)$input['directional']
                : true,

            'inverse_type' => $this->inverseType(
                $relationshipType
            ),

            /*
            |--------------------------------------------------------------------------
            | Meaning
            |--------------------------------------------------------------------------
            */

            'label' => trim(
                (string)(
                    $input['label']
                    ?? ucwords(
                        str_replace(
                            '_',
                            ' ',
                            $relationshipType
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

            /*
            |--------------------------------------------------------------------------
            | Evidence
            |--------------------------------------------------------------------------
            */

            'confidence' => $this->normalizeConfidence(
                $input['confidence'] ?? 100
            ),

            'weight' => $this->normalizeWeight(
                $input['weight'] ?? 1.0
            ),

            'strength' => $this->normalizeWeight(
                $input['strength'] ?? 1.0
            ),

            /*
            |--------------------------------------------------------------------------
            | Lifecycle
            |--------------------------------------------------------------------------
            */

            'status' => $this->normalizeStatus(
                (string)(
                    $input['status']
                    ?? 'active'
                )
            ),

            'valid_from' => trim(
                (string)(
                    $input['valid_from']
                    ?? $this->now()
                )
            ),

            'valid_to' => trim(
                (string)(
                    $input['valid_to']
                    ?? ''
                )
            ),

            /*
            |--------------------------------------------------------------------------
            | Attribution
            |--------------------------------------------------------------------------
            */

            'created_by' => trim(
                (string)(
                    $input['created_by']
                    ?? ''
                )
            ),

            'provenance_id' => trim(
                (string)(
                    $input['provenance_id']
                    ?? ''
                )
            ),

            'version_id' => trim(
                (string)(
                    $input['version_id']
                    ?? ''
                )
            ),

            /*
            |--------------------------------------------------------------------------
            | AI
            |--------------------------------------------------------------------------
            */

            'suggested_by_ai' => array_key_exists(
                'suggested_by_ai',
                $input
            )
                ? (bool)$input['suggested_by_ai']
                : false,

            'accepted_by_human' => array_key_exists(
                'accepted_by_human',
                $input
            )
                ? (bool)$input['accepted_by_human']
                : false,

            /*
            |--------------------------------------------------------------------------
            | Metadata
            |--------------------------------------------------------------------------
            */

            'metadata' => is_array(
                $input['metadata'] ?? null
            )
                ? $input['metadata']
                : [],

            'tags' => is_array(
                $input['tags'] ?? null
            )
                ? array_values(
                    array_unique(
                        array_map(
                            'strval',
                            $input['tags']
                        )
                    )
                )
                : [],

            /*
            |--------------------------------------------------------------------------
            | Time
            |--------------------------------------------------------------------------
            */

            'created_at' => trim(
                (string)(
                    $input['created_at']
                    ?? $this->now()
                )
            ),

            'updated_at' => trim(
                (string)(
                    $input['updated_at']
                    ?? $this->now()
                )
            ),

            /*
            |--------------------------------------------------------------------------
            | Integrity
            |--------------------------------------------------------------------------
            */

            'checksum' => '',
        ];

        if ($record['relationship_id'] === '') {
            $record['relationship_id']
                = $this->generateRelationshipId();
        }

        $record['checksum']
            = $this->checksum($record);

        $this->validateOrFail($record);

        $this->addMessage(
            'Relationship created.',
            [
                'relationship_id'
                    => $record['relationship_id'],

                'relationship_type'
                    => $relationshipType,

                'source'
                    => $record['source_id'],

                'target'
                    => $record['target_id'],
            ]
        );

        return $record;
    }    /**
     * Validate one relationship record.
     *
     * @param array<string,mixed> $record
     */
    public function validate(array $record): bool
    {
        $this->reset();

        $required = [
            'relationship_id',
            'relationship_type',
            'source_id',
            'source_type',
            'target_id',
            'target_type',
            'status',
            'created_by',
            'created_at',
            'updated_at',
            'checksum',
        ];

        foreach ($required as $field) {
            if ($this->isEmpty($record[$field] ?? null)) {
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

        $relationshipType = $this->normalizeRelationshipType(
            (string)(
                $record['relationship_type']
                ?? ''
            )
        );

        if (
            $relationshipType === ''
            || !in_array(
                $relationshipType,
                $this->relationshipTypes,
                true
            )
        ) {
            $this->addError(
                'Relationship type is unsupported.',
                [
                    'relationship_type' =>
                        $record['relationship_type']
                        ?? null,
                ]
            );
        }

        $sourceId = trim(
            (string)(
                $record['source_id']
                ?? ''
            )
        );

        $targetId = trim(
            (string)(
                $record['target_id']
                ?? ''
            )
        );

        $sourceType = $this->normalizeKey(
            (string)(
                $record['source_type']
                ?? ''
            )
        );

        $targetType = $this->normalizeKey(
            (string)(
                $record['target_type']
                ?? ''
            )
        );

        if ($sourceType === '') {
            $this->addError(
                'Source entity type is required.'
            );
        }

        if ($targetType === '') {
            $this->addError(
                'Target entity type is required.'
            );
        }

        if (
            $sourceId !== ''
            && $targetId !== ''
            && $sourceId === $targetId
            && $sourceType === $targetType
            && !$this->allowsSelfRelationship(
                $relationshipType
            )
        ) {
            $this->addError(
                'An entity cannot have this relationship with itself.',
                [
                    'entity_id' => $sourceId,
                    'entity_type' => $sourceType,
                    'relationship_type' =>
                        $relationshipType,
                ]
            );
        }

        if (
            isset($record['directional'])
            && !is_bool($record['directional'])
        ) {
            $this->addError(
                'Directional must be boolean.'
            );
        }

        if (
            isset($record['suggested_by_ai'])
            && !is_bool(
                $record['suggested_by_ai']
            )
        ) {
            $this->addError(
                'Suggested by AI must be boolean.'
            );
        }

        if (
            isset($record['accepted_by_human'])
            && !is_bool(
                $record['accepted_by_human']
            )
        ) {
            $this->addError(
                'Accepted by human must be boolean.'
            );
        }

        if (
            ($record['suggested_by_ai'] ?? false) === true
            && ($record['accepted_by_human'] ?? false) === false
            && ($record['status'] ?? '') === 'verified'
        ) {
            $this->addError(
                'An AI-suggested relationship requires human acceptance before verification.'
            );
        }

        $confidence = $record['confidence'] ?? null;

        if (
            $confidence === null
            || !is_numeric($confidence)
            || (float)$confidence < 0
            || (float)$confidence > 100
        ) {
            $this->addError(
                'Relationship confidence must be between 0 and 100.'
            );
        }

        foreach (['weight', 'strength'] as $field) {
            $value = $record[$field] ?? null;

            if (
                $value === null
                || !is_numeric($value)
                || (float)$value < 0
                || (float)$value > 1
            ) {
                $this->addError(
                    sprintf(
                        '%s must be between 0 and 1.',
                        ucfirst($field)
                    ),
                    [
                        'field' => $field,
                    ]
                );
            }
        }

        $status = $this->normalizeStatus(
            (string)(
                $record['status']
                ?? ''
            )
        );

        if (
            !in_array(
                $status,
                [
                    'proposed',
                    'active',
                    'verified',
                    'disputed',
                    'rejected',
                    'expired',
                    'archived',
                ],
                true
            )
        ) {
            $this->addError(
                'Relationship status is unsupported.'
            );
        }

        $validFrom = trim(
            (string)(
                $record['valid_from']
                ?? ''
            )
        );

        $validTo = trim(
            (string)(
                $record['valid_to']
                ?? ''
            )
        );

        if (
            $validFrom !== ''
            && strtotime($validFrom) === false
        ) {
            $this->addError(
                'Relationship valid-from timestamp is invalid.'
            );
        }

        if (
            $validTo !== ''
            && strtotime($validTo) === false
        ) {
            $this->addError(
                'Relationship valid-to timestamp is invalid.'
            );
        }

        if (
            $validFrom !== ''
            && $validTo !== ''
            && strtotime($validFrom) !== false
            && strtotime($validTo) !== false
            && strtotime($validTo) < strtotime($validFrom)
        ) {
            $this->addError(
                'Relationship valid-to timestamp cannot precede valid-from.'
            );
        }

        if (
            isset($record['metadata'])
            && !is_array($record['metadata'])
        ) {
            $this->addError(
                'Relationship metadata must be an array.'
            );
        }

        if (
            isset($record['tags'])
            && !is_array($record['tags'])
        ) {
            $this->addError(
                'Relationship tags must be an array.'
            );
        }

        $inverseType = trim(
            (string)(
                $record['inverse_type']
                ?? ''
            )
        );

        $expectedInverse = $this->inverseType(
            $relationshipType
        );

        if (
            $inverseType !== ''
            && $expectedInverse !== null
            && $inverseType !== $expectedInverse
        ) {
            $this->addError(
                'Relationship inverse type does not match the canonical inverse.',
                [
                    'relationship_type' =>
                        $relationshipType,
                    'provided_inverse' =>
                        $inverseType,
                    'expected_inverse' =>
                        $expectedInverse,
                ]
            );
        }

        $checksum = trim(
            (string)(
                $record['checksum']
                ?? ''
            )
        );

        if (
            $checksum !== ''
            && !$this->checksumMatches($record)
        ) {
            $this->addError(
                'Relationship checksum does not match the record.'
            );
        }

        if ($this->succeeded()) {
            $this->addMessage(
                'Relationship validation passed.',
                [
                    'relationship_id' =>
                        $record['relationship_id']
                        ?? null,
                    'relationship_type' =>
                        $relationshipType,
                ]
            );
        }

        return $this->succeeded();
    }

    /**
     * Validate one relationship or throw.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function validateOrFail(
        array $record
    ): array {
        if (!$this->validate($record)) {
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
     * Validate a collection of relationship records.
     *
     * @param array<int,array<string,mixed>> $records
     */
    public function validateGraph(
        array $records
    ): bool {
        $this->reset();

        $relationshipIds = [];
        $edgeKeys = [];

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                $this->addError(
                    'Graph contains a relationship that is not an array.',
                    [
                        'index' => $index,
                    ]
                );

                continue;
            }

            $recordErrors = $this->relationshipErrors(
                $record
            );

            foreach ($recordErrors as $message) {
                $this->addError(
                    $message,
                    [
                        'index' => $index,
                        'relationship_id' =>
                            $record['relationship_id']
                            ?? null,
                    ]
                );
            }

            $relationshipId = trim(
                (string)(
                    $record['relationship_id']
                    ?? ''
                )
            );

            if ($relationshipId !== '') {
                if (isset($relationshipIds[$relationshipId])) {
                    $this->addError(
                        'Duplicate relationship ID detected.',
                        [
                            'relationship_id' =>
                                $relationshipId,
                            'first_index' =>
                                $relationshipIds[
                                    $relationshipId
                                ],
                            'duplicate_index' =>
                                $index,
                        ]
                    );
                } else {
                    $relationshipIds[$relationshipId] =
                        $index;
                }
            }

            $edgeKey = $this->edgeKey($record);

            if ($edgeKey !== '') {
                if (isset($edgeKeys[$edgeKey])) {
                    $this->addError(
                        'Duplicate relationship edge detected.',
                        [
                            'relationship_id' =>
                                $relationshipId,
                            'first_index' =>
                                $edgeKeys[$edgeKey],
                            'duplicate_index' =>
                                $index,
                            'edge_key' =>
                                $edgeKey,
                        ]
                    );
                } else {
                    $edgeKeys[$edgeKey] = $index;
                }
            }
        }

        if ($this->succeeded()) {
            $this->addMessage(
                'Relationship graph validation passed.',
                [
                    'relationship_count' =>
                        count($records),
                ]
            );
        }

        return $this->succeeded();
    }

    /**
     * Validate a relationship without overwriting current service messages.
     *
     * @param array<string,mixed> $record
     * @return array<int,string>
     */
    private function relationshipErrors(
        array $record
    ): array {
        $service = new self(
            $this->config,
            $this->context,
            $this->validator,
            $this->versions,
            $this->provenance,
            $this->events
        );

        if ($service->validate($record)) {
            return [];
        }

        return array_values(
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
                    $service->errors()
                )
            )
        );
    }

    /**
     * Determine whether a relationship type permits a self-edge.
     */
    public function allowsSelfRelationship(
        string $relationshipType
    ): bool {
        $relationshipType =
            $this->normalizeRelationshipType(
                $relationshipType
            );

        return in_array(
            $relationshipType,
            [
                'same_as',
                'duplicate_of',
            ],
            true
        );
    }

    /**
     * Return a deterministic identity key for a relationship edge.
     *
     * @param array<string,mixed> $record
     */
    public function edgeKey(
        array $record
    ): string {
        $sourceType = $this->normalizeKey(
            (string)(
                $record['source_type']
                ?? ''
            )
        );

        $sourceId = trim(
            (string)(
                $record['source_id']
                ?? ''
            )
        );

        $relationshipType =
            $this->normalizeRelationshipType(
                (string)(
                    $record['relationship_type']
                    ?? ''
                )
            );

        $targetType = $this->normalizeKey(
            (string)(
                $record['target_type']
                ?? ''
            )
        );

        $targetId = trim(
            (string)(
                $record['target_id']
                ?? ''
            )
        );

        if (
            $sourceType === ''
            || $sourceId === ''
            || $relationshipType === ''
            || $targetType === ''
            || $targetId === ''
        ) {
            return '';
        }

        if (
            ($record['directional'] ?? true) === false
        ) {
            $left = $sourceType . ':' . $sourceId;
            $right = $targetType . ':' . $targetId;

            if (strcmp($left, $right) > 0) {
                [$left, $right] = [$right, $left];
            }

            return hash(
                'sha256',
                implode(
                    '|',
                    [
                        $left,
                        $relationshipType,
                        $right,
                        'undirected',
                    ]
                )
            );
        }

        return hash(
            'sha256',
            implode(
                '|',
                [
                    $sourceType . ':' . $sourceId,
                    $relationshipType,
                    $targetType . ':' . $targetId,
                    'directed',
                ]
            )
        );
    }

    /**
     * Determine whether two relationship records represent the same edge.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    public function isDuplicate(
        array $left,
        array $right
    ): bool {
        $leftKey = $this->edgeKey($left);
        $rightKey = $this->edgeKey($right);

        return $leftKey !== ''
            && $rightKey !== ''
            && hash_equals(
                $leftKey,
                $rightKey
            );
    }

    /**
     * Locate duplicates within a graph.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    public function duplicates(
        array $records
    ): array {
        $seen = [];
        $duplicates = [];

        foreach ($records as $index => $record) {
            if (!is_array($record)) {
                continue;
            }

            $key = $this->edgeKey($record);

            if ($key === '') {
                continue;
            }

            if (!isset($seen[$key])) {
                $seen[$key] = [
                    'index' => $index,
                    'record' => $record,
                ];

                continue;
            }

            $duplicates[] = [
                'edge_key' => $key,
                'original_index' =>
                    $seen[$key]['index'],
                'duplicate_index' => $index,
                'original' =>
                    $seen[$key]['record'],
                'duplicate' => $record,
            ];
        }

        return $duplicates;
    }

    /**
     * Calculate a deterministic checksum for a relationship.
     *
     * @param array<string,mixed> $record
     */
    public function checksum(
        array $record
    ): string {
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
                'Unable to encode relationship for checksum.'
            );
        }

        return hash(
            'sha256',
            $json
        );
    }

    /**
     * Confirm that a relationship checksum remains valid.
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
    }    /**
     * Activate a proposed relationship.
     *
     * @param array<string,mixed> $relationship
     * @return array<string,mixed>
     */
    public function activate(
        array $relationship,
        string $actorId
    ): array
    {
        return $this->transition(
            $relationship,
            'active',
            $actorId,
            'Relationship activated.'
        );
    }

    /**
     * Verify a relationship.
     *
     * Verification represents human confirmation that the
     * relationship accurately reflects reality.
     *
     * @param array<string,mixed> $relationship
     * @return array<string,mixed>
     */
    public function verify(
        array $relationship,
        string $actorId
    ): array
    {
        if (
            ($relationship['suggested_by_ai'] ?? false)
            && !($relationship['accepted_by_human'] ?? false)
        ) {
            throw new RuntimeException(
                'AI suggested relationships must be accepted before verification.'
            );
        }

        return $this->transition(
            $relationship,
            'verified',
            $actorId,
            'Relationship verified.'
        );
    }

    /**
     * Mark a relationship as disputed.
     *
     * @param array<string,mixed> $relationship
     * @return array<string,mixed>
     */
    public function dispute(
        array $relationship,
        string $actorId,
        string $reason = ''
    ): array
    {
        $relationship = $this->transition(
            $relationship,
            'disputed',
            $actorId,
            'Relationship disputed.'
        );

        if ($reason !== '') {
            $relationship['metadata']['dispute_reason']
                = trim($reason);
        }

        return $relationship;
    }

    /**
     * Archive a relationship.
     *
     * @param array<string,mixed> $relationship
     * @return array<string,mixed>
     */
    public function archive(
        array $relationship,
        string $actorId
    ): array
    {
        return $this->transition(
            $relationship,
            'archived',
            $actorId,
            'Relationship archived.'
        );
    }

    /**
     * Expire a relationship.
     *
     * Expired relationships remain historically valid but are
     * no longer considered active.
     *
     * @param array<string,mixed> $relationship
     * @return array<string,mixed>
     */
    public function expire(
        array $relationship,
        string $actorId,
        ?string $expiredAt = null
    ): array
    {
        $relationship = $this->transition(
            $relationship,
            'expired',
            $actorId,
            'Relationship expired.'
        );

        $relationship['valid_to']
            = $expiredAt ?? $this->now();

        return $relationship;
    }

    /**
     * Replace one relationship with another.
     *
     * The original relationship is archived.
     * A new relationship record is returned.
     *
     * @param array<string,mixed> $relationship
     * @param array<string,mixed> $replacement
     * @return array<string,mixed>
     */
    public function replace(
        array $relationship,
        array $replacement,
        string $actorId
    ): array
    {
        $relationship = $this->archive(
            $relationship,
            $actorId
        );

        $replacement['metadata']['replaces']
            = $relationship['relationship_id'];

        return $this->create($replacement);
    }

    /**
     * Transition a relationship to a new lifecycle state.
     *
     * @param array<string,mixed> $relationship
     * @return array<string,mixed>
     */
    protected function transition(
        array $relationship,
        string $status,
        string $actorId,
        string $event
    ): array
    {
        $this->validateOrFail($relationship);

        $relationship['status'] = $status;
        $relationship['updated_at'] = $this->now();

        $relationship['metadata']['last_actor']
            = trim($actorId);

        $relationship['metadata']['last_event']
            = $event;

        $relationship['checksum']
            = $this->checksum($relationship);

        return $relationship;
    }    /**
     * Create the canonical inverse relationship.
     *
     * The inverse is a separate immutable edge.
     *
     * Example:
     *
     *   Asset A  supports  Asset B
     *
     * automatically produces
     *
     *   Asset B  supported_by  Asset A
     *
     * @param array<string,mixed> $relationship
     * @return array<string,mixed>|null
     */
    public function createInverse(
        array $relationship
    ): ?array
    {
        $this->validateOrFail($relationship);

        if (
            !($relationship['directional'] ?? true)
        ) {
            return null;
        }

        $inverseType = $this->inverseType(
            (string)$relationship['relationship_type']
        );

        if ($inverseType === null) {
            return null;
        }

        $inverse = $relationship;

        $inverse['relationship_id']
            = $this->generateRelationshipId();

        $inverse['relationship_type']
            = $inverseType;

        $inverse['source_id']
            = $relationship['target_id'];

        $inverse['source_type']
            = $relationship['target_type'];

        $inverse['target_id']
            = $relationship['source_id'];

        $inverse['target_type']
            = $relationship['source_type'];

        $inverse['inverse_type']
            = $relationship['relationship_type'];

        $inverse['created_at']
            = $this->now();

        $inverse['updated_at']
            = $this->now();

        $inverse['checksum']
            = $this->checksum($inverse);

        return $inverse;
    }

    /**
     * Determine the canonical inverse.
     */
    public function inverseType(
        string $relationshipType
    ): ?string
    {
        $relationshipType =
            $this->normalizeRelationshipType(
                $relationshipType
            );

        return $this->inverse[$relationshipType]
            ?? null;
    }

    /**
     * Determine whether a relationship has an inverse.
     */
    public function hasInverse(
        string $relationshipType
    ): bool
    {
        return $this->inverseType(
            $relationshipType
        ) !== null;
    }

    /**
     * Determine whether two edges are inverses.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    public function areInverse(
        array $left,
        array $right
    ): bool
    {
        $expected = $this->inverseType(
            (string)$left['relationship_type']
        );

        if ($expected === null) {
            return false;
        }

        return

            $expected
                === ($right['relationship_type'] ?? null)

            &&

            ($left['source_id'] ?? null)
                === ($right['target_id'] ?? null)

            &&

            ($left['target_id'] ?? null)
                === ($right['source_id'] ?? null)

            &&

            ($left['source_type'] ?? null)
                === ($right['target_type'] ?? null)

            &&

            ($left['target_type'] ?? null)
                === ($right['source_type'] ?? null);
    }

    /**
     * Synchronize metadata between an edge
     * and its inverse.
     *
     * Identity never changes.
     *
     * @param array<string,mixed> $relationship
     * @param array<string,mixed> $inverse
     *
     * @return array<string,mixed>
     */
    public function synchronizeInverse(
        array $relationship,
        array $inverse
    ): array
    {
        if (
            !$this->areInverse(
                $relationship,
                $inverse
            )
        ) {
            throw new RuntimeException(
                'Relationships are not inverses.'
            );
        }

        $inverse['confidence']
            = $relationship['confidence'];

        $inverse['weight']
            = $relationship['weight'];

        $inverse['strength']
            = $relationship['strength'];

        $inverse['status']
            = $relationship['status'];

        $inverse['valid_from']
            = $relationship['valid_from'];

        $inverse['valid_to']
            = $relationship['valid_to'];

        $inverse['metadata']
            = $relationship['metadata'];

        $inverse['tags']
            = $relationship['tags'];

        $inverse['updated_at']
            = $this->now();

        $inverse['checksum']
            = $this->checksum($inverse);

        return $inverse;
    }

    /**
     * Determine whether a relationship
     * is currently active.
     *
     * @param array<string,mixed> $relationship
     */
    public function isActive(
        array $relationship
    ): bool
    {
        if (
            ($relationship['status'] ?? '')
            !== 'active'
            &&
            ($relationship['status'] ?? '')
            !== 'verified'
        ) {
            return false;
        }

        if (
            !empty($relationship['valid_to'])
        ) {
            return
                strtotime(
                    (string)$relationship['valid_to']
                )
                >=
                time();
        }

        return true;
    }

    /**
     * Determine whether two entities
     * are directly connected.
     *
     * @param array<int,array<string,mixed>> $relationships
     */
    public function connected(
        array $relationships,
        string $sourceId,
        string $targetId
    ): bool
    {
        foreach ($relationships as $edge) {

            if (

                ($edge['source_id'] ?? null)
                    === $sourceId

                &&

                ($edge['target_id'] ?? null)
                    === $targetId

            ) {
                return true;
            }
        }

        return false;
    }    /**
     * Register a relationship change with the supporting services.
     *
     * This is the single integration point used by all lifecycle
     * operations. Future queueing, indexing and notifications should
     * be added here rather than scattered throughout the class.
     *
     * @param array<string,mixed> $relationship
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    protected function registerRelationshipChange(
        array $relationship,
        string $changeType,
        array $options = []
    ): array
    {
        $this->validateOrFail($relationship);

        $actorId = trim(
            (string)(
                $options['actor_id']
                ?? $relationship['created_by']
                ?? 'system'
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Event
        |--------------------------------------------------------------------------
        */

        $event = $this->events->create([
            'event_type' => 'relationship_updated',

            'entity_id'
                => $relationship['relationship_id'],

            'entity_type'
                => 'relationship',

            'actor_id'
                => $actorId,

            'actor_type'
                => $options['actor_type']
                ?? 'person',

            'status'
                => 'completed',

            'success'
                => true,

            'metadata' => [
                'change_type'
                    => $changeType,

                'relationship_type'
                    => $relationship['relationship_type'],
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Provenance
        |--------------------------------------------------------------------------
        */

        if (
            empty($relationship['provenance_id'])
        ) {

            $relationship['provenance_id']
                = $event['event_id'];

        }

        /*
        |--------------------------------------------------------------------------
        | Version
        |--------------------------------------------------------------------------
        */

        if (
            empty($relationship['version_id'])
        ) {

            $relationship['version_id']
                = 'REL-'
                . gmdate('YmdHis')
                . '-'
                . strtoupper(
                    substr(
                        hash(
                            'sha256',
                            microtime(true)
                            . random_int(1000,9999)
                        ),
                        0,
                        10
                    )
                );

        }

        /*
        |--------------------------------------------------------------------------
        | Metadata
        |--------------------------------------------------------------------------
        */

        $relationship['metadata']['last_change']
            = $changeType;

        $relationship['metadata']['last_event']
            = $event['event_id'];

        $relationship['metadata']['registered_at']
            = $this->now();

        $relationship['updated_at']
            = $this->now();

        /*
        |--------------------------------------------------------------------------
        | Future hooks
        |--------------------------------------------------------------------------
        */

        $relationship['metadata']['graph_refresh_required']
            = true;

        $relationship['metadata']['analytics_pending']
            = true;

        $relationship['metadata']['ai_review_pending']
            = true;

        $relationship['checksum']
            = $this->checksum(
                $relationship
            );

        return $relationship;
    }

    /**
     * Register a newly created relationship.
     *
     * @param array<string,mixed> $relationship
     * @return array<string,mixed>
     */
    protected function registerCreate(
        array $relationship
    ): array
    {
        return $this->registerRelationshipChange(
            $relationship,
            'created'
        );
    }

    /**
     * Register an updated relationship.
     *
     * @param array<string,mixed> $relationship
     * @return array<string,mixed>
     */
    protected function registerUpdate(
        array $relationship
    ): array
    {
        return $this->registerRelationshipChange(
            $relationship,
            'updated'
        );
    }

    /**
     * Register verification.
     *
     * @param array<string,mixed> $relationship
     * @return array<string,mixed>
     */
    protected function registerVerification(
        array $relationship
    ): array
    {
        return $this->registerRelationshipChange(
            $relationship,
            'verified'
        );
    }

    /**
     * Register archival.
     *
     * @param array<string,mixed> $relationship
     * @return array<string,mixed>
     */
    protected function registerArchive(
        array $relationship
    ): array
    {
        return $this->registerRelationshipChange(
            $relationship,
            'archived'
        );
    }

    /**
     * Register expiry.
     *
     * @param array<string,mixed> $relationship
     * @return array<string,mixed>
     */
    protected function registerExpiry(
        array $relationship
    ): array
    {
        return $this->registerRelationshipChange(
            $relationship,
            'expired'
        );
    }

    /**
     * Register a dispute.
     *
     * @param array<string,mixed> $relationship
     * @return array<string,mixed>
     */
    protected function registerDispute(
        array $relationship
    ): array
    {
        return $this->registerRelationshipChange(
            $relationship,
            'disputed'
        );
    }
}
