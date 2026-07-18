<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| IPMdb Core Entity
|--------------------------------------------------------------------------
|
| Base runtime object for schema-driven IPMdb entities.
|
| Responsibilities:
| - Hold entity data.
| - Load and apply schema definitions.
| - Enforce required fields.
| - Apply defaults.
| - Track changes.
| - Preserve provenance.
| - Export consistent arrays and JSON.
|
| Database persistence belongs in repository and service classes.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once dirname(__DIR__) . '/schema/schema_registry.php';

final class EntityValidationException extends RuntimeException
{
    private array $errors;

    public function __construct(array $errors)
    {
        $this->errors = $errors;

        parent::__construct(
            $errors !== []
                ? implode(' ', $errors)
                : 'Entity validation failed.'
        );
    }

    public function errors(): array
    {
        return $this->errors;
    }
}

class Entity implements JsonSerializable
{
    protected string $entityType;

    protected array $schema = [];

    protected array $data = [];

    protected array $originalData = [];

    protected array $errors = [];

    protected bool $exists = false;

    protected bool $locked = false;

    public function __construct(
        string $entityType,
        array $data = [],
        bool $exists = false
    ) {
        $entityType = $this->normalizeEntityType($entityType);

        if ($entityType === '') {
            throw new InvalidArgumentException('Entity type is required.');
        }

        $schema = SchemaRegistry::load($entityType);

        if ($schema === null) {
            throw new InvalidArgumentException(
                sprintf('Schema is unavailable for entity type "%s".', $entityType)
            );
        }

        $this->entityType = $entityType;
        $this->schema = $schema;
        $this->exists = $exists;

        $this->data = $this->defaultData();
        $this->fill($data, true);

        $this->originalData = $this->data;
        $this->locked = (bool)($this->data['locked'] ?? false);
    }

    public static function make(string $entityType, array $data = []): static
    {
        return new static($entityType, $data, false);
    }

    public static function hydrate(string $entityType, array $data): static
    {
        return new static($entityType, $data, true);
    }

    public function entityType(): string
    {
        return $this->entityType;
    }

    public function schema(): array
    {
        return $this->schema;
    }

    public function table(): string
    {
        return trim((string)($this->schema['table'] ?? ''));
    }

    public function primaryKey(): string
    {
        return trim((string)($this->schema['primary_key'] ?? 'id'));
    }

    public function assetKey(): string
    {
        return trim((string)($this->schema['asset_key'] ?? 'asset_id'));
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function markPersisted(bool $persisted = true): static
    {
        $this->exists = $persisted;
        $this->originalData = $this->data;

        return $this;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function lock(): static
    {
        $this->locked = true;

        if ($this->fieldIsKnown('locked')) {
            $this->data['locked'] = true;
        }

        return $this;
    }

    public function unlock(): static
    {
        $this->locked = false;

        if ($this->fieldIsKnown('locked')) {
            $this->data['locked'] = false;
        }

        return $this;
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    public function get(string $field, mixed $default = null): mixed
    {
        return $this->data[$field] ?? $default;
    }

    public function set(string $field, mixed $value): static
    {
        $field = trim($field);

        if ($field === '') {
            throw new InvalidArgumentException('Field name is required.');
        }

        if ($this->locked && $field !== 'locked') {
            throw new RuntimeException(
                sprintf('Entity "%s" is locked.', $this->entityType)
            );
        }

        if (!$this->fieldIsKnown($field)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Field "%s" is not defined for entity "%s".',
                    $field,
                    $this->entityType
                )
            );
        }

        $this->data[$field] = $this->normalizeValue($field, $value);

        if ($field === 'locked') {
            $this->locked = (bool)$this->data[$field];
        }

        return $this;
    }

    public function fill(array $data, bool $ignoreUnknown = false): static
    {
        foreach ($data as $field => $value) {
            $field = (string)$field;

            if (!$this->fieldIsKnown($field)) {
                if ($ignoreUnknown) {
                    continue;
                }

                throw new InvalidArgumentException(
                    sprintf(
                        'Field "%s" is not defined for entity "%s".',
                        $field,
                        $this->entityType
                    )
                );
            }

            $this->setWithoutLockCheck($field, $value);
        }

        return $this;
    }

    public function remove(string $field): static
    {
        if ($this->locked) {
            throw new RuntimeException(
                sprintf('Entity "%s" is locked.', $this->entityType)
            );
        }

        if (!$this->fieldIsKnown($field)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Field "%s" is not defined for entity "%s".',
                    $field,
                    $this->entityType
                )
            );
        }

        unset($this->data[$field]);

        return $this;
    }

    public function validate(): bool
    {
        $this->errors = [];

        foreach ($this->fieldDefinitions() as $field => $definition) {
            $value = $this->data[$field] ?? null;

            if (($definition['required'] ?? false) === true && $this->isEmpty($value)) {
                $this->errors[] = sprintf('%s is required.', $this->fieldLabel($field));
                continue;
            }

            if ($this->isEmpty($value)) {
                continue;
            }

            $this->validateType($field, $value, $definition);
            $this->validateLength($field, $value, $definition);
            $this->validateAllowedValues($field, $value, $definition);
        }

        return $this->errors === [];
    }

    public function validateOrFail(): static
    {
        if (!$this->validate()) {
            throw new EntityValidationException($this->errors);
        }

        return $this;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function isDirty(?string $field = null): bool
    {
        if ($field !== null) {
            return ($this->data[$field] ?? null)
                !== ($this->originalData[$field] ?? null);
        }

        return $this->data !== $this->originalData;
    }

    public function changes(): array
    {
        $changes = [];

        $fields = array_unique(
            array_merge(
                array_keys($this->originalData),
                array_keys($this->data)
            )
        );

        foreach ($fields as $field) {
            $before = $this->originalData[$field] ?? null;
            $after = $this->data[$field] ?? null;

            if ($before !== $after) {
                $changes[$field] = [
                    'before' => $before,
                    'after' => $after,
                ];
            }
        }

        return $changes;
    }

    public function resetChanges(): static
    {
        $this->originalData = $this->data;

        return $this;
    }

    public function restoreOriginal(): static
    {
        $this->data = $this->originalData;
        $this->locked = (bool)($this->data['locked'] ?? false);

        return $this;
    }

    public function provenance(): array
    {
        $fields = $this->schema['provenance'] ?? [];

        if (!is_array($fields)) {
            return [];
        }

        return $this->only($fields);
    }

    public function identity(): array
    {
        $fields = $this->schema['identity'] ?? [];

        if (!is_array($fields)) {
            return [];
        }

        return $this->only($fields);
    }

    public function lifecycle(): array
    {
        $fields = $this->schema['lifecycle'] ?? [];

        if (!is_array($fields)) {
            return [];
        }

        return $this->only($fields);
    }

    public function knowledge(): array
    {
        $fields = $this->schema['knowledge'] ?? [];

        if (!is_array($fields)) {
            return [];
        }

        return $this->only($fields);
    }

    public function only(array $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            $field = (string)$field;

            if (array_key_exists($field, $this->data)) {
                $result[$field] = $this->data[$field];
            }
        }

        return $result;
    }

    public function except(array $fields): array
    {
        return array_diff_key(
            $this->data,
            array_fill_keys(array_map('strval', $fields), true)
        );
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return [
            'entity_type' => $this->entityType,
            'exists' => $this->exists,
            'locked' => $this->locked,
            'data' => $this->data,
        ];
    }

    public function toJson(int $flags = 0): string
    {
        $json = json_encode(
            $this,
            $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException(
                'Unable to encode entity as JSON: ' . json_last_error_msg()
            );
        }

        return $json;
    }

    protected function defaultData(): array
    {
        $defaults = [];

        foreach ($this->fieldDefinitions() as $field => $definition) {
            if (array_key_exists('default', $definition)) {
                $defaults[$field] = $definition['default'];
            }
        }

        return $defaults;
    }

    protected function fieldDefinitions(): array
    {
        $fields = $this->schema['fields'] ?? [];

        return is_array($fields) ? $fields : [];
    }

    protected function fieldIsKnown(string $field): bool
    {
        if (array_key_exists($field, $this->fieldDefinitions())) {
            return true;
        }

        foreach (
            [
                'identity',
                'provenance',
                'lifecycle',
                'knowledge',
                'governance',
                'ai',
            ] as $group
        ) {
            $fields = $this->schema[$group] ?? [];

            if (is_array($fields) && in_array($field, $fields, true)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeValue(string $field, mixed $value): mixed
    {
        $definition = $this->fieldDefinitions()[$field] ?? [];
        $type = strtolower(trim((string)($definition['type'] ?? '')));

        return match ($type) {
            'int', 'integer' => $value === null || $value === ''
                ? null
                : (int)$value,

            'float', 'double', 'decimal', 'number' => $value === null || $value === ''
                ? null
                : (float)$value,

            'bool', 'boolean' => filter_var(
                $value,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) ?? false,

            'array', 'json' => $this->normalizeArrayValue($value),

            'string', 'text', 'enum', 'datetime', 'date', 'url', 'email' =>
                $value === null ? null : trim((string)$value),

            default => $value,
        };
    }

    protected function normalizeArrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    protected function setWithoutLockCheck(string $field, mixed $value): void
    {
        $this->data[$field] = $this->normalizeValue($field, $value);

        if ($field === 'locked') {
            $this->locked = (bool)$this->data[$field];
        }
    }

    protected function validateType(
        string $field,
        mixed $value,
        array $definition
    ): void {
        $type = strtolower(trim((string)($definition['type'] ?? '')));

        $valid = match ($type) {
            '', 'mixed' => true,

            'int', 'integer' => is_int($value),

            'float', 'double', 'decimal', 'number' =>
                is_float($value) || is_int($value),

            'bool', 'boolean' => is_bool($value),

            'array', 'json' => is_array($value),

            'string', 'text', 'enum', 'datetime', 'date', 'url', 'email' =>
                is_string($value),

            default => true,
        };

        if (!$valid) {
            $this->errors[] = sprintf(
                '%s must be of type %s.',
                $this->fieldLabel($field),
                $type
            );

            return;
        }

        if ($type === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[] = sprintf(
                '%s must contain a valid email address.',
                $this->fieldLabel($field)
            );
        }

        if ($type === 'url' && filter_var($value, FILTER_VALIDATE_URL) === false) {
            $this->errors[] = sprintf(
                '%s must contain a valid URL.',
                $this->fieldLabel($field)
            );
        }
    }

    protected function validateLength(
        string $field,
        mixed $value,
        array $definition
    ): void {
        if (!is_string($value)) {
            return;
        }

        $length = $definition['length'] ?? null;

        if (!is_numeric($length)) {
            return;
        }

        $maximum = (int)$length;

        if ($maximum < 1) {
            return;
        }

        $actual = function_exists('mb_strlen')
            ? mb_strlen($value)
            : strlen($value);

        if ($actual > $maximum) {
            $this->errors[] = sprintf(
                '%s may contain no more than %d characters.',
                $this->fieldLabel($field),
                $maximum
            );
        }
    }

    protected function validateAllowedValues(
        string $field,
        mixed $value,
        array $definition
    ): void {
        $values = $definition['values'] ?? null;

        if (!is_array($values) || $values === []) {
            return;
        }

        if (!in_array($value, $values, true)) {
            $this->errors[] = sprintf(
                '%s contains an unsupported value.',
                $this->fieldLabel($field)
            );
        }
    }

    protected function fieldLabel(string $field): string
    {
        $definition = $this->fieldDefinitions()[$field] ?? [];

        $label = trim((string)($definition['label'] ?? ''));

        if ($label !== '') {
            return $label;
        }

        return ucwords(str_replace('_', ' ', $field));
    }

    protected function isEmpty(mixed $value): bool
    {
        return $value === null
            || $value === ''
            || (is_array($value) && $value === []);
    }

    protected function normalizeEntityType(string $entityType): string
    {
        $entityType = strtolower(trim($entityType));

        return preg_replace('/[^a-z0-9_]+/', '', $entityType) ?? '';
    }
}