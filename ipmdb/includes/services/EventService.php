<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/EventService.php
|--------------------------------------------------------------------------
| IPMdb Event Service
|--------------------------------------------------------------------------
|
| Creates, validates, records, filters, and summarizes system events.
|
| Events describe what happened.
| Provenance explains where it came from.
| Versions preserve what changed.
|
| This service performs no database operations.
| Repository classes will persist the event records produced here.
|
| Every action leaves evidence.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once dirname(__DIR__) . '/core/Entity.php';
require_once dirname(__DIR__) . '/core/EntityCollection.php';

final class EventService extends Service
{
    /**
     * @var array<int,string>
     */
    private array $eventTypes = [
        'entity_created',
        'entity_viewed',
        'entity_updated',
        'entity_verified',
        'entity_locked',
        'entity_unlocked',
        'entity_archived',
        'entity_restored',
        'entity_deleted',

        'relationship_created',
        'relationship_updated',
        'relationship_verified',
        'relationship_deleted',

        'version_created',
        'version_restored',

        'translation_created',
        'translation_reviewed',
        'translation_approved',
        'translation_disputed',

        'provenance_created',
        'provenance_verified',

        'import_started',
        'import_completed',
        'import_failed',

        'export_started',
        'export_completed',
        'export_failed',

        'search_performed',

        'decision_recorded',
        'implementation_started',
        'implementation_completed',

        'authentication_succeeded',
        'authentication_failed',

        'service_started',
        'service_completed',
        'service_failed',

        'system_warning',
        'system_error',
        'system_event',
    ];

    /**
     * @var array<int,string>
     */
    private array $actorTypes = [
        'person',
        'organization',
        'ai',
        'community',
        'system',
        'anonymous',
        'unknown',
    ];

    /**
     * @var array<int,string>
     */
    private array $severityLevels = [
        'debug',
        'info',
        'notice',
        'warning',
        'error',
        'critical',
    ];

    /**
     * @var array<int,string>
     */
    private array $statuses = [
        'recorded',
        'pending',
        'completed',
        'failed',
        'cancelled',
        'disputed',
    ];

    /**
     * Create one canonical event record.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(array $input): array
    {
        $this->reset();

        $eventType = $this->normalizeEventType(
            (string)($input['event_type'] ?? 'system_event')
        );

        $record = [
            'event_id' => $this->normalizeIdentifier(
                (string)($input['event_id'] ?? '')
            ),

            'event_type' => $eventType,

            'event_name' => trim(
                (string)(
                    $input['event_name']
                    ?? $this->eventLabel($eventType)
                )
            ),

            'description' => trim(
                (string)($input['description'] ?? '')
            ),

            'entity_id' => trim(
                (string)($input['entity_id'] ?? '')
            ),

            'entity_type' => $this->normalizeKey(
                (string)($input['entity_type'] ?? '')
            ),

            'entity_version' => trim(
                (string)($input['entity_version'] ?? '')
            ),

            'relationship_id' => trim(
                (string)($input['relationship_id'] ?? '')
            ),

            'version_id' => trim(
                (string)($input['version_id'] ?? '')
            ),

            'provenance_id' => trim(
                (string)($input['provenance_id'] ?? '')
            ),

            'translation_id' => trim(
                (string)($input['translation_id'] ?? '')
            ),

            'actor_id' => trim(
                (string)($input['actor_id'] ?? '')
            ),

            'actor_type' => $this->normalizeActorType(
                (string)($input['actor_type'] ?? 'unknown')
            ),

            'actor_role' => $this->normalizeKey(
                (string)($input['actor_role'] ?? '')
            ),

            'service_name' => trim(
                (string)($input['service_name'] ?? '')
            ),

            'request_id' => trim(
                (string)($input['request_id'] ?? '')
            ),

            'session_id' => trim(
                (string)($input['session_id'] ?? '')
            ),

            'correlation_id' => trim(
                (string)($input['correlation_id'] ?? '')
            ),

            'source' => trim(
                (string)($input['source'] ?? '')
            ),

            'source_reference' => trim(
                (string)($input['source_reference'] ?? '')
            ),

            'severity' => $this->normalizeSeverity(
                (string)($input['severity'] ?? 'info')
            ),

            'status' => $this->normalizeStatus(
                (string)($input['status'] ?? 'recorded')
            ),

            'success' => $this->normalizeSuccess(
                $input['success'] ?? null
            ),

            'message' => trim(
                (string)($input['message'] ?? '')
            ),

            'error_code' => trim(
                (string)($input['error_code'] ?? '')
            ),

            'error_message' => trim(
                (string)($input['error_message'] ?? '')
            ),

            'before' => $this->normalizePayload(
                $input['before'] ?? null
            ),

            'after' => $this->normalizePayload(
                $input['after'] ?? null
            ),

            'changes' => $this->normalizePayload(
                $input['changes'] ?? []
            ),

            'metadata' => $this->normalizePayload(
                $input['metadata'] ?? []
            ),

            'tags' => $this->normalizeStringList(
                $input['tags'] ?? []
            ),

            'ip_address_hash' => trim(
                (string)($input['ip_address_hash'] ?? '')
            ),

            'user_agent' => trim(
                (string)($input['user_agent'] ?? '')
            ),

            'occurred_at' => trim(
                (string)($input['occurred_at'] ?? $this->now())
            ),

            'recorded_at' => trim(
                (string)($input['recorded_at'] ?? $this->now())
            ),

            'checksum' => '',
        ];

        if ($record['event_id'] === '') {
            $record['event_id'] = $this->generateEventId();
        }

        if ($record['success'] === null) {
            $record['success'] = !in_array(
                $record['status'],
                ['failed', 'cancelled'],
                true
            );
        }

        if (
            $record['status'] === 'failed'
            && $record['severity'] === 'info'
        ) {
            $record['severity'] = 'error';
        }

        $record['checksum'] = $this->checksum($record);

        $this->validateOrFail($record);

        $this->addMessage(
            'Event record created.',
            [
                'event_id' => $record['event_id'],
                'event_type' => $record['event_type'],
                'entity_id' => $record['entity_id'],
                'status' => $record['status'],
            ]
        );

        return $record;
    }

    /**
     * Create an event from one entity.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function fromEntity(
        Entity $entity,
        string $eventType,
        string $actorId,
        array $context = []
    ): array {
        return $this->create(
            array_merge(
                [
                    'event_type' => $eventType,
                    'entity_id' => $this->resolveEntityId($entity),
                    'entity_type' => $entity->entityType(),
                    'entity_version' =>
                        $entity->get('version', ''),
                    'actor_id' => $actorId,
                    'actor_type' =>
                        $context['actor_type']
                        ?? 'person',
                    'actor_role' =>
                        $context['actor_role']
                        ?? 'contributor',
                    'after' => $entity->toArray(),
                ],
                $context
            )
        );
    }

    /**
     * Record an entity change with before and after state.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function entityChanged(
        Entity $entity,
        array $before,
        string $actorId,
        array $context = []
    ): array {
        $after = $entity->toArray();

        return $this->fromEntity(
            $entity,
            'entity_updated',
            $actorId,
            array_merge(
                [
                    'before' => $before,
                    'after' => $after,
                    'changes' => $this->diff($before, $after),
                    'status' => 'completed',
                    'success' => true,
                ],
                $context
            )
        );
    }

    /**
     * Create a successful service event.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function serviceCompleted(
        string $serviceName,
        string $actorId = 'system',
        array $context = []
    ): array {
        return $this->create(
            array_merge(
                [
                    'event_type' => 'service_completed',
                    'service_name' => trim($serviceName),
                    'actor_id' => trim($actorId),
                    'actor_type' =>
                        $context['actor_type']
                        ?? 'system',
                    'severity' => 'info',
                    'status' => 'completed',
                    'success' => true,
                ],
                $context
            )
        );
    }

    /**
     * Create a failed service event.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function serviceFailed(
        string $serviceName,
        Throwable|string $error,
        string $actorId = 'system',
        array $context = []
    ): array {
        $errorMessage = $error instanceof Throwable
            ? $error->getMessage()
            : trim($error);

        $errorCode = $error instanceof Throwable
            ? (string)$error->getCode()
            : trim(
                (string)($context['error_code'] ?? '')
            );

        return $this->create(
            array_merge(
                [
                    'event_type' => 'service_failed',
                    'service_name' => trim($serviceName),
                    'actor_id' => trim($actorId),
                    'actor_type' =>
                        $context['actor_type']
                        ?? 'system',
                    'severity' => 'error',
                    'status' => 'failed',
                    'success' => false,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
                ],
                $context
            )
        );
    }

    /**
     * Create a system warning event.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function warning(
        string $message,
        array $context = []
    ): array {
        return $this->create(
            array_merge(
                [
                    'event_type' => 'system_warning',
                    'actor_id' => 'system',
                    'actor_type' => 'system',
                    'severity' => 'warning',
                    'status' => 'recorded',
                    'success' => true,
                    'message' => trim($message),
                ],
                $context
            )
        );
    }

    /**
     * Create a system error event.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function error(
        Throwable|string $error,
        array $context = []
    ): array {
        $message = $error instanceof Throwable
            ? $error->getMessage()
            : trim($error);

        $code = $error instanceof Throwable
            ? (string)$error->getCode()
            : trim(
                (string)($context['error_code'] ?? '')
            );

        return $this->create(
            array_merge(
                [
                    'event_type' => 'system_error',
                    'actor_id' => 'system',
                    'actor_type' => 'system',
                    'severity' => 'error',
                    'status' => 'failed',
                    'success' => false,
                    'error_code' => $code,
                    'error_message' => $message,
                ],
                $context
            )
        );
    }

    /**
     * Validate an event record.
     *
     * @param array<string,mixed> $record
     */
    public function validate(array $record): bool
    {
        $this->reset();

        $required = [
            'event_id',
            'event_type',
            'event_name',
            'actor_id',
            'actor_type',
            'severity',
            'status',
            'occurred_at',
            'recorded_at',
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

        if (
            isset($record['event_type'])
            && !in_array(
                (string)$record['event_type'],
                $this->eventTypes,
                true
            )
        ) {
            $this->addError(
                'Event type is unsupported.'
            );
        }

        if (
            isset($record['actor_type'])
            && !in_array(
                (string)$record['actor_type'],
                $this->actorTypes,
                true
            )
        ) {
            $this->addError(
                'Actor type is unsupported.'
            );
        }

        if (
            isset($record['severity'])
            && !in_array(
                (string)$record['severity'],
                $this->severityLevels,
                true
            )
        ) {
            $this->addError(
                'Event severity is unsupported.'
            );
        }

        if (
            isset($record['status'])
            && !in_array(
                (string)$record['status'],
                $this->statuses,
                true
            )
        ) {
            $this->addError(
                'Event status is unsupported.'
            );
        }

        if (
            isset($record['success'])
            && !is_bool($record['success'])
        ) {
            $this->addError(
                'Event success must be boolean.'
            );
        }

        if (
            ($record['status'] ?? '') === 'failed'
            && trim(
                (string)($record['error_message'] ?? '')
            ) === ''
        ) {
            $this->addError(
                'Failed events require an error message.'
            );
        }

        if (
            trim(
                (string)($record['checksum'] ?? '')
            ) !== ''
            && !$this->checksumMatches($record)
        ) {
            $this->addError(
                'Event checksum does not match the record.'
            );
        }

        if ($this->succeeded()) {
            $this->addMessage(
                'Event validation passed.',
                [
                    'event_id' =>
                        $record['event_id']
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
     * Calculate a deterministic event checksum.
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
                'Unable to encode event for checksum.'
            );
        }

        return hash('sha256', $json);
    }

    /**
     * Verify the event checksum.
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
     * Return events for one entity.
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
     * Return events for one actor.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    public function byActor(
        array $records,
        string $actorId
    ): array {
        $actorId = trim($actorId);

        return array_values(
            array_filter(
                $records,
                static function (
                    array $record
                ) use ($actorId): bool {
                    return trim(
                        (string)(
                            $record['actor_id']
                            ?? ''
                        )
                    ) === $actorId;
                }
            )
        );
    }

    /**
     * Return events matching one type.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    public function byType(
        array $records,
        string $eventType
    ): array {
        $eventType = $this->normalizeEventType(
            $eventType
        );

        return array_values(
            array_filter(
                $records,
                static function (
                    array $record
                ) use ($eventType): bool {
                    return (
                        $record['event_type']
                        ?? ''
                    ) === $eventType;
                }
            )
        );
    }

    /**
     * Return events matching one correlation ID.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    public function correlated(
        array $records,
        string $correlationId
    ): array {
        $correlationId = trim($correlationId);

        return array_values(
            array_filter(
                $records,
                static function (
                    array $record
                ) use ($correlationId): bool {
                    return trim(
                        (string)(
                            $record['correlation_id']
                            ?? ''
                        )
                    ) === $correlationId;
                }
            )
        );
    }

    /**
     * Return events occurring between two timestamps.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    public function between(
        array $records,
        string $from,
        string $to
    ): array {
        $fromTime = strtotime($from);
        $toTime = strtotime($to);

        if ($fromTime === false || $toTime === false) {
            throw new InvalidArgumentException(
                'Event date range is invalid.'
            );
        }

        if ($fromTime > $toTime) {
            [$fromTime, $toTime] = [$toTime, $fromTime];
        }

        return array_values(
            array_filter(
                $records,
                static function (
                    array $record
                ) use ($fromTime, $toTime): bool {
                    $occurred = strtotime(
                        (string)(
                            $record['occurred_at']
                            ?? ''
                        )
                    );

                    return $occurred !== false
                        && $occurred >= $fromTime
                        && $occurred <= $toTime;
                }
            )
        );
    }

    /**
     * Sort events chronologically.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    public function sort(
        array $records,
        string $direction = 'desc'
    ): array {
        usort(
            $records,
            static function (
                array $left,
                array $right
            ): int {
                $date = strcmp(
                    (string)(
                        $left['occurred_at']
                        ?? ''
                    ),
                    (string)(
                        $right['occurred_at']
                        ?? ''
                    )
                );

                if ($date !== 0) {
                    return $date;
                }

                return strcmp(
                    (string)(
                        $left['event_id']
                        ?? ''
                    ),
                    (string)(
                        $right['event_id']
                        ?? ''
                    )
                );
            }
        );

        if (strtolower(trim($direction)) === 'desc') {
            $records = array_reverse($records);
        }

        return $records;
    }

    /**
     * Summarize event records.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<string,mixed>
     */
    public function summarize(array $records): array
    {
        $types = [];
        $actors = [];
        $severities = [];
        $statuses = [];
        $entities = [];
        $success = 0;
        $failed = 0;
        $earliest = null;
        $latest = null;

        foreach ($records as $record) {
            $eventType = trim(
                (string)($record['event_type'] ?? '')
            );

            if ($eventType !== '') {
                $types[$eventType] =
                    ($types[$eventType] ?? 0) + 1;
            }

            $actorId = trim(
                (string)($record['actor_id'] ?? '')
            );

            if ($actorId !== '') {
                $actors[$actorId] =
                    ($actors[$actorId] ?? 0) + 1;
            }

            $severity = trim(
                (string)($record['severity'] ?? '')
            );

            if ($severity !== '') {
                $severities[$severity] =
                    ($severities[$severity] ?? 0) + 1;
            }

            $status = trim(
                (string)($record['status'] ?? '')
            );

            if ($status !== '') {
                $statuses[$status] =
                    ($statuses[$status] ?? 0) + 1;
            }

            $entityId = trim(
                (string)($record['entity_id'] ?? '')
            );

            if ($entityId !== '') {
                $entities[$entityId] = true;
            }

            if (($record['success'] ?? false) === true) {
                $success++;
            } else {
                $failed++;
            }

            $occurredAt = trim(
                (string)($record['occurred_at'] ?? '')
            );

            if ($occurredAt !== '') {
                if (
                    $earliest === null
                    || strcmp($occurredAt, $earliest) < 0
                ) {
                    $earliest = $occurredAt;
                }

                if (
                    $latest === null
                    || strcmp($occurredAt, $latest) > 0
                ) {
                    $latest = $occurredAt;
                }
            }
        }

        arsort($types);
        arsort($actors);
        arsort($severities);
        arsort($statuses);

        return [
            'event_count' => count($records),
            'entity_count' => count($entities),
            'actor_count' => count($actors),
            'success_count' => $success,
            'failure_count' => $failed,
            'success_rate' => count($records) > 0
                ? round(
                    ($success / count($records)) * 100,
                    2
                )
                : 0.0,
            'event_types' => $types,
            'actors' => $actors,
            'severities' => $severities,
            'statuses' => $statuses,
            'earliest_event_at' => $earliest,
            'latest_event_at' => $latest,
        ];
    }

    /**
     * Compare two arrays and return changed fields.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,array<string,mixed>>
     */
    public function diff(
        array $before,
        array $after
    ): array {
        $fields = array_unique(
            array_merge(
                array_keys($before),
                array_keys($after)
            )
        );

        $changes = [];

        foreach ($fields as $field) {
            $left = $before[$field] ?? null;
            $right = $after[$field] ?? null;

            if (
                $this->normalizeForHash($left)
                === $this->normalizeForHash($right)
            ) {
                continue;
            }

            $changes[$field] = [
                'before' => $left,
                'after' => $right,
            ];
        }

        return $changes;
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

    private function normalizeEventType(
        string $eventType
    ): string {
        $eventType = $this->normalizeKey(
            $eventType
        );

        return in_array(
            $eventType,
            $this->eventTypes,
            true
        )
            ? $eventType
            : 'system_event';
    }

    private function normalizeActorType(
        string $actorType
    ): string {
        $actorType = $this->normalizeKey(
            $actorType
        );

        return in_array(
            $actorType,
            $this->actorTypes,
            true
        )
            ? $actorType
            : 'unknown';
    }

    private function normalizeSeverity(
        string $severity
    ): string {
        $severity = $this->normalizeKey(
            $severity
        );

        return in_array(
            $severity,
            $this->severityLevels,
            true
        )
            ? $severity
            : 'info';
    }

    private function normalizeStatus(
        string $status
    ): string {
        $status = $this->normalizeKey(
            $status
        );

        return in_array(
            $status,
            $this->statuses,
            true
        )
            ? $status
            : 'recorded';
    }

    private function normalizeSuccess(
        mixed $value
    ): ?bool {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var(
            $value,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
    }

    private function eventLabel(
        string $eventType
    ): string {
        return ucwords(
            str_replace('_', ' ', $eventType)
        );
    }

    /**
     * @return array<string,mixed>|array<int,mixed>|null
     */
    private function normalizePayload(
        mixed $payload
    ): array|null {
        if ($payload === null || $payload === '') {
            return null;
        }

        if (is_array($payload)) {
            return $payload;
        }

        if (is_object($payload)) {
            if ($payload instanceof JsonSerializable) {
                $serialized = $payload->jsonSerialize();

                return is_array($serialized)
                    ? $serialized
                    : ['value' => $serialized];
            }

            if (method_exists($payload, 'toArray')) {
                $array = $payload->toArray();

                return is_array($array)
                    ? $array
                    : ['value' => $array];
            }

            return get_object_vars($payload);
        }

        if (is_string($payload)) {
            $decoded = json_decode(
                $payload,
                true
            );

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'value' => $payload,
        ];
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

    private function generateEventId(): string
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

        return 'EVT-'
            . gmdate('Ymd-His')
            . '-'
            . $random;
    }

    private function normalizeForHash(
        mixed $value
    ): mixed {
        if (!is_array($value)) {
            if (is_object($value)) {
                if ($value instanceof JsonSerializable) {
                    return $this->normalizeForHash(
                        $value->jsonSerialize()
                    );
                }

                if (method_exists($value, 'toArray')) {
                    return $this->normalizeForHash(
                        $value->toArray()
                    );
                }

                return $this->normalizeForHash(
                    get_object_vars($value)
                );
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
                'event_types' => $this->eventTypes,
                'actor_types' => $this->actorTypes,
                'severity_levels' =>
                    $this->severityLevels,
                'statuses' => $this->statuses,
                'checksum_algorithm' => 'sha256',
            ]
        );
    }
}