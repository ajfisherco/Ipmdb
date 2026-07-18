<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/core/EntityCollection.php
|--------------------------------------------------------------------------
| IPMdb Core Entity Collection
|--------------------------------------------------------------------------
|
| Typed container for groups of IPMdb Entity objects.
|
| Responsibilities:
| - Hold entities.
| - Enforce optional entity-type consistency.
| - Filter, search, sort, group, and paginate.
| - Export consistent arrays and JSON.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Entity.php';

class EntityCollection implements
    Countable,
    IteratorAggregate,
    ArrayAccess,
    JsonSerializable
{
    /**
     * @var array<int,Entity>
     */
    protected array $items = [];

    protected ?string $entityType = null;

    protected array $meta = [];

    /**
     * @param iterable<Entity|array<string,mixed>> $items
     */
    public function __construct(
        iterable $items = [],
        ?string $entityType = null,
        array $meta = []
    ) {
        $this->entityType = $this->normalizeEntityType($entityType);
        $this->meta = $meta;

        foreach ($items as $item) {
            $this->add($item);
        }
    }

    /**
     * @param iterable<Entity|array<string,mixed>> $items
     */
    public static function make(
        iterable $items = [],
        ?string $entityType = null,
        array $meta = []
    ): static {
        return new static($items, $entityType, $meta);
    }

    /**
     * @param iterable<array<string,mixed>> $records
     */
    public static function hydrate(
        string $entityType,
        iterable $records,
        array $meta = []
    ): static {
        $collection = new static([], $entityType, $meta);

        foreach ($records as $record) {
            $collection->add(
                Entity::hydrate($entityType, $record)
            );
        }

        return $collection;
    }

    public function entityType(): ?string
    {
        return $this->entityType;
    }

    public function enforceEntityType(?string $entityType): static
    {
        $normalized = $this->normalizeEntityType($entityType);

        if ($normalized !== null) {
            foreach ($this->items as $item) {
                if ($item->entityType() !== $normalized) {
                    throw new InvalidArgumentException(
                        sprintf(
                            'Collection contains "%s"; expected "%s".',
                            $item->entityType(),
                            $normalized
                        )
                    );
                }
            }
        }

        $this->entityType = $normalized;

        return $this;
    }

    public function add(Entity|array $item): static
    {
        $entity = $this->normalizeItem($item);
        $this->assertEntityType($entity);

        $this->items[] = $entity;

        return $this;
    }

    public function prepend(Entity|array $item): static
    {
        $entity = $this->normalizeItem($item);
        $this->assertEntityType($entity);

        array_unshift($this->items, $entity);

        return $this;
    }

    public function replace(int $index, Entity|array $item): static
    {
        if (!array_key_exists($index, $this->items)) {
            throw new OutOfBoundsException(
                sprintf('Collection index %d does not exist.', $index)
            );
        }

        $entity = $this->normalizeItem($item);
        $this->assertEntityType($entity);

        $this->items[$index] = $entity;

        return $this;
    }

    public function remove(int $index): static
    {
        if (!array_key_exists($index, $this->items)) {
            return $this;
        }

        unset($this->items[$index]);

        $this->items = array_values($this->items);

        return $this;
    }

    public function clear(): static
    {
        $this->items = [];

        return $this;
    }

    public function first(): ?Entity
    {
        return $this->items[0] ?? null;
    }

    public function last(): ?Entity
    {
        if ($this->items === []) {
            return null;
        }

        return $this->items[array_key_last($this->items)] ?? null;
    }

    public function get(int $index): ?Entity
    {
        return $this->items[$index] ?? null;
    }

    public function find(
        string $field,
        mixed $value,
        bool $strict = true
    ): ?Entity {
        foreach ($this->items as $entity) {
            $current = $entity->get($field);

            $matches = $strict
                ? $current === $value
                : $current == $value;

            if ($matches) {
                return $entity;
            }
        }

        return null;
    }

    public function contains(
        string $field,
        mixed $value,
        bool $strict = true
    ): bool {
        return $this->find($field, $value, $strict) !== null;
    }

    public function validate(): bool
    {
        foreach ($this->items as $entity) {
            if (!$entity->validate()) {
                return false;
            }
        }

        return true;
    }

    public function validateOrFail(): static
    {
        $errors = [];

        foreach ($this->items as $index => $entity) {
            if ($entity->validate()) {
                continue;
            }

            foreach ($entity->errors() as $error) {
                $errors[] = sprintf(
                    'Item %d: %s',
                    $index,
                    $error
                );
            }
        }

        if ($errors !== []) {
            throw new EntityValidationException($errors);
        }

        return $this;
    }

    public function hasDirtyEntities(): bool
    {
        foreach ($this->items as $entity) {
            if ($entity->isDirty()) {
                return true;
            }
        }

        return false;
    }

    public function filter(callable $callback): static
    {
        $filtered = [];

        foreach ($this->items as $index => $entity) {
            if ($callback($entity, $index) === true) {
                $filtered[] = $entity;
            }
        }

        return new static(
            $filtered,
            $this->entityType,
            $this->meta
        );
    }

    public function where(
        string $field,
        mixed $value,
        bool $strict = true
    ): static {
        return $this->filter(
            static function (Entity $entity) use (
                $field,
                $value,
                $strict
            ): bool {
                $current = $entity->get($field);

                return $strict
                    ? $current === $value
                    : $current == $value;
            }
        );
    }

    public function whereIn(
        string $field,
        array $values,
        bool $strict = true
    ): static {
        return $this->filter(
            static function (Entity $entity) use (
                $field,
                $values,
                $strict
            ): bool {
                return in_array(
                    $entity->get($field),
                    $values,
                    $strict
                );
            }
        );
    }

    public function search(
        string $query,
        array $fields = []
    ): static {
        $query = $this->lower(trim($query));

        if ($query === '') {
            return new static(
                $this->items,
                $this->entityType,
                $this->meta
            );
        }

        return $this->filter(
            function (Entity $entity) use ($query, $fields): bool {
                $data = $entity->toArray();

                $searchFields = $fields !== []
                    ? $fields
                    : array_keys($data);

                foreach ($searchFields as $field) {
                    $value = $data[(string)$field] ?? null;

                    if (is_array($value)) {
                        $value = json_encode(
                            $value,
                            JSON_UNESCAPED_SLASHES
                            | JSON_UNESCAPED_UNICODE
                        );
                    }

                    if (!is_scalar($value) && $value !== null) {
                        continue;
                    }

                    if (
                        str_contains(
                            $this->lower((string)$value),
                            $query
                        )
                    ) {
                        return true;
                    }
                }

                return false;
            }
        );
    }

    public function sortBy(
        string $field,
        string $direction = 'asc'
    ): static {
        $items = $this->items;
        $direction = strtolower(trim($direction));

        usort(
            $items,
            static function (Entity $left, Entity $right) use (
                $field,
                $direction
            ): int {
                $comparison = $left->get($field)
                    <=> $right->get($field);

                return $direction === 'desc'
                    ? -$comparison
                    : $comparison;
            }
        );

        return new static(
            $items,
            $this->entityType,
            $this->meta
        );
    }

    public function reverse(): static
    {
        return new static(
            array_reverse($this->items),
            $this->entityType,
            $this->meta
        );
    }

    public function slice(
        int $offset,
        ?int $length = null
    ): static {
        return new static(
            array_slice($this->items, $offset, $length),
            $this->entityType,
            $this->meta
        );
    }

    public function take(int $limit): static
    {
        if ($limit === 0) {
            return new static(
                [],
                $this->entityType,
                $this->meta
            );
        }

        return $limit > 0
            ? $this->slice(0, $limit)
            : $this->slice($limit);
    }

    public function paginate(
        int $page = 1,
        int $perPage = 20
    ): static {
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));

        $total = $this->count();
        $lastPage = max(1, (int)ceil($total / $perPage));

        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        $meta = array_merge(
            $this->meta,
            [
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => $lastPage,
                    'from' => $total === 0 ? 0 : $offset + 1,
                    'to' => min($offset + $perPage, $total),
                ],
            ]
        );

        return new static(
            array_slice($this->items, $offset, $perPage),
            $this->entityType,
            $meta
        );
    }

    public function map(callable $callback): array
    {
        $mapped = [];

        foreach ($this->items as $index => $entity) {
            $mapped[] = $callback($entity, $index);
        }

        return $mapped;
    }

    public function each(callable $callback): static
    {
        foreach ($this->items as $index => $entity) {
            $callback($entity, $index);
        }

        return $this;
    }

    public function pluck(
        string $field,
        ?string $keyField = null
    ): array {
        $result = [];

        foreach ($this->items as $entity) {
            $value = $entity->get($field);

            if ($keyField === null) {
                $result[] = $value;
                continue;
            }

            $key = $entity->get($keyField);

            if (is_int($key) || is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function unique(?string $field = null): static
    {
        $seen = [];
        $unique = [];

        foreach ($this->items as $entity) {
            $value = $field === null
                ? $entity->toArray()
                : $entity->get($field);

            $key = $this->stableKey($value);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $entity;
        }

        return new static(
            $unique,
            $this->entityType,
            $this->meta
        );
    }

    public function groupBy(string $field): array
    {
        $groups = [];

        foreach ($this->items as $entity) {
            $value = $entity->get($field);

            if (is_bool($value)) {
                $key = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $key = 'null';
            } elseif (is_scalar($value)) {
                $key = (string)$value;
            } else {
                $key = $this->stableKey($value);
            }

            if (!isset($groups[$key])) {
                $groups[$key] = new static(
                    [],
                    $this->entityType,
                    $this->meta
                );
            }

            $groups[$key]->add($entity);
        }

        return $groups;
    }

    public function merge(
        EntityCollection $collection,
        bool $unique = false
    ): static {
        if (
            $this->entityType !== null
            && $collection->entityType() !== null
            && $this->entityType !== $collection->entityType()
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Cannot merge collection type "%s" with "%s".',
                    $this->entityType,
                    $collection->entityType()
                )
            );
        }

        $merged = new static(
            array_merge(
                $this->items,
                $collection->all()
            ),
            $this->entityType ?? $collection->entityType(),
            array_merge(
                $this->meta,
                $collection->meta()
            )
        );

        return $unique
            ? $merged->unique()
            : $merged;
    }

    /**
     * @return array<int,Entity>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function meta(
        ?string $key = null,
        mixed $default = null
    ): mixed {
        if ($key === null) {
            return $this->meta;
        }

        return $this->meta[$key] ?? $default;
    }

    public function setMeta(
        string $key,
        mixed $value
    ): static {
        $key = trim($key);

        if ($key === '') {
            throw new InvalidArgumentException(
                'Metadata key is required.'
            );
        }

        $this->meta[$key] = $value;

        return $this;
    }

    public function mergeMeta(array $meta): static
    {
        $this->meta = array_merge(
            $this->meta,
            $meta
        );

        return $this;
    }

    public function toArray(): array
    {
        return array_map(
            static fn (Entity $entity): array =>
                $entity->toArray(),
            $this->items
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'entity_type' => $this->entityType,
            'count' => $this->count(),
            'meta' => $this->meta,
            'items' => $this->items,
        ];
    }

    public function toJson(int $flags = 0): string
    {
        $json = json_encode(
            $this,
            $flags
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException(
                'Unable to encode collection as JSON: '
                . json_last_error_msg()
            );
        }

        return $json;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset)
            && array_key_exists($offset, $this->items);
    }

    public function offsetGet(mixed $offset): ?Entity
    {
        if (!is_int($offset)) {
            return null;
        }

        return $this->items[$offset] ?? null;
    }

    public function offsetSet(
        mixed $offset,
        mixed $value
    ): void {
        if (
            !$value instanceof Entity
            && !is_array($value)
        ) {
            throw new InvalidArgumentException(
                'Collection values must be Entity objects or arrays.'
            );
        }

        $entity = $this->normalizeItem($value);
        $this->assertEntityType($entity);

        if ($offset === null) {
            $this->items[] = $entity;
            return;
        }

        if (!is_int($offset)) {
            throw new InvalidArgumentException(
                'Collection offsets must be integers.'
            );
        }

        $this->items[$offset] = $entity;

        ksort($this->items);

        $this->items = array_values($this->items);
    }

    public function offsetUnset(mixed $offset): void
    {
        if (is_int($offset)) {
            $this->remove($offset);
        }
    }

    protected function normalizeItem(
        Entity|array $item
    ): Entity {
        if ($item instanceof Entity) {
            return $item;
        }

        if ($this->entityType === null) {
            throw new InvalidArgumentException(
                'Entity type is required when adding raw array data.'
            );
        }

        return Entity::hydrate(
            $this->entityType,
            $item
        );
    }

    protected function assertEntityType(
        Entity $entity
    ): void {
        if ($this->entityType === null) {
            $this->entityType = $entity->entityType();
            return;
        }

        if ($entity->entityType() !== $this->entityType) {
            throw new InvalidArgumentException(
                sprintf(
                    'Entity type "%s" cannot be added to "%s".',
                    $entity->entityType(),
                    $this->entityType
                )
            );
        }
    }

    protected function normalizeEntityType(
        ?string $entityType
    ): ?string {
        if ($entityType === null) {
            return null;
        }

        $entityType = strtolower(trim($entityType));

        if ($entityType === '') {
            return null;
        }

        $entityType = preg_replace(
            '/[^a-z0-9_]+/',
            '',
            $entityType
        ) ?? '';

        return $entityType !== ''
            ? $entityType
            : null;
    }

    protected function stableKey(mixed $value): string
    {
        if (is_object($value)) {
            return 'object:' . spl_object_hash($value);
        }

        if (is_array($value)) {
            $encoded = json_encode(
                $value,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            );

            return 'array:' . hash(
                'sha256',
                $encoded !== false
                    ? $encoded
                    : serialize($value)
            );
        }

        return get_debug_type($value)
            . ':'
            . serialize($value);
    }

    protected function lower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value)
            : strtolower($value);
    }
}