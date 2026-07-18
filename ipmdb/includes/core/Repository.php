<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/core/Repository.php
|--------------------------------------------------------------------------
| IPMdb Core Repository
|--------------------------------------------------------------------------
|
| Schema-driven persistence for IPMdb Entity objects.
|
| Responsibilities:
| - Connect entities to PDO-backed storage.
| - Create, read, update, archive, restore, and delete records.
| - Validate data before persistence.
| - Generate safe SQL from registered schemas.
| - Filter, search, sort, count, and paginate.
| - Provide transaction control.
|
| Repositories persist.
| Services perform application behaviour.
| Entities hold validated data.
| Schemas define structure.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Entity.php';
require_once __DIR__ . '/EntityCollection.php';
require_once dirname(__DIR__) . '/schema/schema_registry.php';

class Repository
{
    protected PDO $pdo;

    protected string $entityType;

    /**
     * @var array<string,mixed>
     */
    protected array $schema;

    protected string $table;

    protected string $primaryKey;

    protected string $publicKey;

    /**
     * @var array<string,array<string,mixed>>
     */
    protected array $fields = [];

    /**
     * @var array<int,string>
     */
    protected array $selectableFields = [];

    /**
     * @var array<int,string>
     */
    protected array $writableFields = [];

    /**
     * @var array<int,string>
     */
    protected array $searchableFields = [];

    /**
     * @var array<int,string>
     */
    protected array $sortableFields = [];

    protected bool $timestamps = true;

    public function __construct(
        PDO $pdo,
        string $entityType
    ) {
        $this->pdo = $pdo;

        $this->entityType = $this->normalizeEntityType(
            $entityType
        );

        if ($this->entityType === '') {
            throw new InvalidArgumentException(
                'Repository entity type is required.'
            );
        }

        $this->schema = SchemaRegistry::load(
            $this->entityType
        );

        $this->table = trim(
            (string)($this->schema['table'] ?? '')
        );

        if ($this->table === '') {
            throw new RuntimeException(
                sprintf(
                    'Schema "%s" does not define a database table.',
                    $this->entityType
                )
            );
        }

        $this->primaryKey = trim(
            (string)(
                $this->schema['primary_key']
                ?? 'id'
            )
        );

        $this->publicKey = trim(
            (string)(
                $this->schema['public_key']
                ?? 'entity_id'
            )
        );

        $fields = $this->schema['fields'] ?? [];

        if (!is_array($fields) || $fields === []) {
            throw new RuntimeException(
                sprintf(
                    'Schema "%s" contains no fields.',
                    $this->entityType
                )
            );
        }

        $this->fields = $fields;

        $this->configureFieldLists();

        $this->pdo->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        $this->pdo->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::FETCH_ASSOC
        );

        $this->pdo->setAttribute(
            PDO::ATTR_EMULATE_PREPARES,
            false
        );
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function entityType(): string
    {
        return $this->entityType;
    }

    /**
     * @return array<string,mixed>
     */
    public function schema(): array
    {
        return $this->schema;
    }

    public function table(): string
    {
        return $this->table;
    }

    public function primaryKey(): string
    {
        return $this->primaryKey;
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Insert or update an entity.
     */
    public function save(Entity $entity): Entity
    {
        $this->assertEntityType($entity);

        return $entity->exists()
            ? $this->update($entity)
            : $this->insert($entity);
    }

    /**
     * Insert a new entity.
     */
    public function insert(Entity $entity): Entity
    {
        $this->assertEntityType($entity);

        if ($entity->exists()) {
            throw new RuntimeException(
                'Persisted entities must be updated, not inserted.'
            );
        }

        $entity->validateOrFail();

        $data = $this->prepareWriteData(
            $entity,
            false
        );

        if ($data === []) {
            throw new RuntimeException(
                'Entity contains no insertable fields.'
            );
        }

        $columns = array_keys($data);

        $columnSql = implode(
            ', ',
            array_map(
                fn (string $field): string =>
                    $this->quoteIdentifier($field),
                $columns
            )
        );

        $placeholderSql = implode(
            ', ',
            array_map(
                static fn (string $field): string =>
                    ':' . $field,
                $columns
            )
        );

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($this->table),
            $columnSql,
            $placeholderSql
        );

        $statement = $this->pdo->prepare($sql);

        $statement->execute(
            $this->prepareBindings($data)
        );

        if (
            $entity->has($this->primaryKey)
            && $this->isGeneratedField(
                $this->primaryKey
            )
            && $this->isEmpty(
                $entity->get($this->primaryKey)
            )
        ) {
            $insertId = $this->pdo->lastInsertId();

            if ($insertId !== '0' && $insertId !== '') {
                $entity->set(
                    $this->primaryKey,
                    (int)$insertId
                );
            }
        }

        $entity->markPersisted(true);

        return $entity;
    }

    /**
     * Update an existing entity.
     */
    public function update(Entity $entity): Entity
    {
        $this->assertEntityType($entity);

        if (!$entity->exists()) {
            throw new RuntimeException(
                'Unpersisted entities must be inserted, not updated.'
            );
        }

        $entity->validateOrFail();

        $identifier = $this->resolveIdentifier(
            $entity
        );

        if ($identifier['value'] === null) {
            throw new RuntimeException(
                'Entity requires a primary or public identifier before updating.'
            );
        }

        $changes = $entity->changes();

        if ($changes === []) {
            return $entity;
        }

        $data = $this->prepareWriteData(
            $entity,
            true
        );

        $changedData = [];

        foreach ($data as $field => $value) {
            if (array_key_exists($field, $changes)) {
                $changedData[$field] = $value;
            }
        }

        if (
            $this->timestamps
            && isset($this->fields['updated_at'])
        ) {
            $updatedAt = gmdate('Y-m-d H:i:s');

            if ($entity->has('updated_at')) {
                $entity->set(
                    'updated_at',
                    $updatedAt
                );
            }

            $changedData['updated_at'] = $updatedAt;
        }

        unset(
            $changedData[$this->primaryKey],
            $changedData[$this->publicKey]
        );

        if ($changedData === []) {
            $entity->resetChanges();

            return $entity;
        }

        $setSql = implode(
            ', ',
            array_map(
                fn (string $field): string =>
                    $this->quoteIdentifier($field)
                    . ' = :set_'
                    . $field,
                array_keys($changedData)
            )
        );

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = :repository_identifier LIMIT 1',
            $this->quoteIdentifier($this->table),
            $setSql,
            $this->quoteIdentifier(
                $identifier['field']
            )
        );

        $bindings = [
            'repository_identifier' =>
                $identifier['value'],
        ];

        foreach ($changedData as $field => $value) {
            $bindings['set_' . $field] =
                $this->prepareBindingValue($value);
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        $entity->markPersisted(true);

        return $entity;
    }

    /**
     * Find by primary or public identifier.
     */
    public function find(
        int|string $identifier
    ): ?Entity {
        $identifier = trim(
            (string)$identifier
        );

        if ($identifier === '') {
            return null;
        }

        $field = ctype_digit($identifier)
            ? $this->primaryKey
            : $this->publicKey;

        return $this->findBy(
            $field,
            $identifier
        );
    }

    /**
     * Find by public identifier.
     */
    public function findPublic(
        string $identifier
    ): ?Entity {
        return $this->findBy(
            $this->publicKey,
            $identifier
        );
    }

    /**
     * Find the first entity matching one field.
     */
    public function findBy(
        string $field,
        mixed $value
    ): ?Entity {
        $this->assertKnownField($field);

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s = :value LIMIT 1',
            $this->selectFieldSql(),
            $this->quoteIdentifier($this->table),
            $this->quoteIdentifier($field)
        );

        $statement = $this->pdo->prepare($sql);

        $statement->execute([
            'value' => $this->prepareBindingValue(
                $value
            ),
        ]);

        $record = $statement->fetch();

        return is_array($record)
            ? Entity::hydrate(
                $this->entityType,
                $this->decodeRecord($record)
            )
            : null;
    }

    /**
     * Confirm whether an identifier exists.
     */
    public function exists(
        int|string $identifier
    ): bool {
        $identifier = trim(
            (string)$identifier
        );

        if ($identifier === '') {
            return false;
        }

        $field = ctype_digit($identifier)
            ? $this->primaryKey
            : $this->publicKey;

        return $this->existsBy(
            $field,
            $identifier
        );
    }

    /**
     * Confirm whether a field value exists.
     */
    public function existsBy(
        string $field,
        mixed $value
    ): bool {
        $this->assertKnownField($field);

        $sql = sprintf(
            'SELECT 1 FROM %s WHERE %s = :value LIMIT 1',
            $this->quoteIdentifier($this->table),
            $this->quoteIdentifier($field)
        );

        $statement = $this->pdo->prepare($sql);

        $statement->execute([
            'value' => $this->prepareBindingValue(
                $value
            ),
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Return all entities.
     */
    public function all(
        string $orderBy = '',
        string $direction = 'asc',
        int $limit = 500
    ): EntityCollection {
        $limit = max(
            1,
            min(5000, $limit)
        );

        $orderBy = $orderBy !== ''
            ? $orderBy
            : (
                isset($this->fields['created_at'])
                    ? 'created_at'
                    : $this->primaryKey
            );

        $this->assertSortableField($orderBy);

        $direction = $this->normalizeDirection(
            $direction
        );

        $sql = sprintf(
            'SELECT %s FROM %s ORDER BY %s %s LIMIT %d',
            $this->selectFieldSql(),
            $this->quoteIdentifier($this->table),
            $this->quoteIdentifier($orderBy),
            $direction,
            $limit
        );

        $records = $this->pdo
            ->query($sql)
            ->fetchAll();

        return EntityCollection::hydrate(
            $this->entityType,
            array_map(
                fn (array $record): array =>
                    $this->decodeRecord($record),
                $records ?: []
            ),
            [
                'repository' => static::class,
                'table' => $this->table,
                'limit' => $limit,
            ]
        );
    }

    /**
     * Query using exact-match filters.
     *
     * @param array<string,mixed> $filters
     */
    public function where(
        array $filters,
        string $orderBy = '',
        string $direction = 'asc',
        int $limit = 500,
        int $offset = 0
    ): EntityCollection {
        $limit = max(
            1,
            min(5000, $limit)
        );

        $offset = max(0, $offset);

        $conditions = [];
        $bindings = [];

        foreach ($filters as $field => $value) {
            $field = (string)$field;

            $this->assertKnownField($field);

            if ($value === null) {
                $conditions[] =
                    $this->quoteIdentifier($field)
                    . ' IS NULL';

                continue;
            }

            if (is_array($value)) {
                if ($value === []) {
                    $conditions[] = '1 = 0';
                    continue;
                }

                $placeholders = [];

                foreach (
                    array_values($value)
                    as $index => $item
                ) {
                    $placeholder =
                        'filter_'
                        . $field
                        . '_'
                        . $index;

                    $placeholders[] =
                        ':' . $placeholder;

                    $bindings[$placeholder] =
                        $this->prepareBindingValue(
                            $item
                        );
                }

                $conditions[] = sprintf(
                    '%s IN (%s)',
                    $this->quoteIdentifier($field),
                    implode(', ', $placeholders)
                );

                continue;
            }

            $placeholder =
                'filter_' . $field;

            $conditions[] = sprintf(
                '%s = :%s',
                $this->quoteIdentifier($field),
                $placeholder
            );

            $bindings[$placeholder] =
                $this->prepareBindingValue($value);
        }

        $orderBy = $orderBy !== ''
            ? $orderBy
            : (
                isset($this->fields['created_at'])
                    ? 'created_at'
                    : $this->primaryKey
            );

        $this->assertSortableField($orderBy);

        $direction = $this->normalizeDirection(
            $direction
        );

        $sql = sprintf(
            'SELECT %s FROM %s%s ORDER BY %s %s LIMIT %d OFFSET %d',
            $this->selectFieldSql(),
            $this->quoteIdentifier($this->table),
            $conditions !== []
                ? ' WHERE ' . implode(
                    ' AND ',
                    $conditions
                )
                : '',
            $this->quoteIdentifier($orderBy),
            $direction,
            $limit,
            $offset
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        $records = $statement->fetchAll();

        return EntityCollection::hydrate(
            $this->entityType,
            array_map(
                fn (array $record): array =>
                    $this->decodeRecord($record),
                $records ?: []
            ),
            [
                'repository' => static::class,
                'filters' => $filters,
                'limit' => $limit,
                'offset' => $offset,
            ]
        );
    }

    /**
     * Search across schema fields marked searchable.
     */
    public function search(
        string $query,
        int $limit = 100,
        int $offset = 0
    ): EntityCollection {
        $query = trim($query);

        if ($query === '') {
            return $this->all(
                limit: $limit
            );
        }

        if ($this->searchableFields === []) {
            throw new RuntimeException(
                sprintf(
                    'Schema "%s" defines no searchable fields.',
                    $this->entityType
                )
            );
        }

        $limit = max(
            1,
            min(1000, $limit)
        );

        $offset = max(0, $offset);

        $conditions = [];
        $bindings = [];

        foreach (
            $this->searchableFields
            as $index => $field
        ) {
            $placeholder = 'search_' . $index;

            $conditions[] = sprintf(
                '%s LIKE :%s',
                $this->quoteIdentifier($field),
                $placeholder
            );

            $bindings[$placeholder] =
                '%' . $query . '%';
        }

        $orderBy = isset(
            $this->fields['updated_at']
        )
            ? 'updated_at'
            : (
                isset($this->fields['created_at'])
                    ? 'created_at'
                    : $this->primaryKey
            );

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s ORDER BY %s DESC LIMIT %d OFFSET %d',
            $this->selectFieldSql(),
            $this->quoteIdentifier($this->table),
            implode(' OR ', $conditions),
            $this->quoteIdentifier($orderBy),
            $limit,
            $offset
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        $records = $statement->fetchAll();

        return EntityCollection::hydrate(
            $this->entityType,
            array_map(
                fn (array $record): array =>
                    $this->decodeRecord($record),
                $records ?: []
            ),
            [
                'repository' => static::class,
                'query' => $query,
                'limit' => $limit,
                'offset' => $offset,
            ]
        );
    }

    /**
     * Return one page of records.
     *
     * @param array<string,mixed> $filters
     */
    public function paginate(
        int $page = 1,
        int $perPage = 20,
        array $filters = [],
        string $orderBy = '',
        string $direction = 'desc'
    ): EntityCollection {
        $page = max(1, $page);

        $perPage = max(
            1,
            min(500, $perPage)
        );

        $total = $this->count($filters);

        $lastPage = max(
            1,
            (int)ceil(
                $total / $perPage
            )
        );

        $page = min(
            $page,
            $lastPage
        );

        $offset = (
            $page - 1
        ) * $perPage;

        $collection = $this->where(
            $filters,
            $orderBy,
            $direction,
            $perPage,
            $offset
        );

        return $collection->mergeMeta([
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0
                    ? 0
                    : $offset + 1,
                'to' => min(
                    $offset + $perPage,
                    $total
                ),
            ],
        ]);
    }

    /**
     * Count records matching exact filters.
     *
     * @param array<string,mixed> $filters
     */
    public function count(
        array $filters = []
    ): int {
        $conditions = [];
        $bindings = [];

        foreach ($filters as $field => $value) {
            $field = (string)$field;

            $this->assertKnownField($field);

            if ($value === null) {
                $conditions[] =
                    $this->quoteIdentifier($field)
                    . ' IS NULL';

                continue;
            }

            $placeholder =
                'count_' . $field;

            $conditions[] = sprintf(
                '%s = :%s',
                $this->quoteIdentifier($field),
                $placeholder
            );

            $bindings[$placeholder] =
                $this->prepareBindingValue($value);
        }

        $sql = sprintf(
            'SELECT COUNT(*) FROM %s%s',
            $this->quoteIdentifier($this->table),
            $conditions !== []
                ? ' WHERE '
                    . implode(
                        ' AND ',
                        $conditions
                    )
                : ''
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return (int)$statement->fetchColumn();
    }

    /**
     * Archive an entity without destroying history.
     */
    public function archive(
        Entity $entity
    ): Entity {
        $this->assertEntityType($entity);

        if (!$entity->exists()) {
            throw new RuntimeException(
                'Entity must be persisted before archival.'
            );
        }

        if ($entity->isLocked()) {
            $entity->unlock();
        }

        if ($entity->has('status')) {
            $entity->set(
                'status',
                'archived'
            );
        }

        if ($entity->has('archived_at')) {
            $entity->set(
                'archived_at',
                gmdate('Y-m-d H:i:s')
            );
        }

        return $this->update($entity);
    }

    /**
     * Restore an archived entity.
     */
    public function restore(
        Entity $entity,
        string $status = 'active'
    ): Entity {
        $this->assertEntityType($entity);

        if (!$entity->exists()) {
            throw new RuntimeException(
                'Entity must be persisted before restoration.'
            );
        }

        if ($entity->isLocked()) {
            $entity->unlock();
        }

        if ($entity->has('status')) {
            $entity->set(
                'status',
                trim($status) !== ''
                    ? trim($status)
                    : 'active'
            );
        }

        if ($entity->has('archived_at')) {
            $entity->set(
                'archived_at',
                null
            );
        }

        return $this->update($entity);
    }

    /**
     * Permanently delete an entity.
     *
     * Use archive() unless permanent destruction is explicitly required.
     */
    public function delete(
        Entity|int|string $entity
    ): bool {
        if ($entity instanceof Entity) {
            $this->assertEntityType($entity);

            $identifier = $this->resolveIdentifier(
                $entity
            );
        } else {
            $value = trim(
                (string)$entity
            );

            $identifier = [
                'field' => ctype_digit($value)
                    ? $this->primaryKey
                    : $this->publicKey,
                'value' => $value,
            ];
        }

        if (
            $identifier['value'] === null
            || $identifier['value'] === ''
        ) {
            return false;
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s = :identifier LIMIT 1',
            $this->quoteIdentifier($this->table),
            $this->quoteIdentifier(
                $identifier['field']
            )
        );

        $statement = $this->pdo->prepare($sql);

        $statement->execute([
            'identifier' =>
                $identifier['value'],
        ]);

        if ($entity instanceof Entity) {
            $entity->markPersisted(false);
        }

        return $statement->rowCount() > 0;
    }

    /**
     * Execute work inside a transaction.
     */
    public function transaction(
        callable $callback
    ): mixed {
        $ownsTransaction =
            !$this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $callback(
                $this,
                $this->pdo
            );

            if (
                $ownsTransaction
                && $this->pdo->inTransaction()
            ) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $this->pdo->inTransaction()
            ) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Begin a manual transaction.
     */
    public function begin(): bool
    {
        return $this->pdo->inTransaction()
            ? false
            : $this->pdo->beginTransaction();
    }

    /**
     * Commit a manual transaction.
     */
    public function commit(): bool
    {
        return $this->pdo->inTransaction()
            ? $this->pdo->commit()
            : false;
    }

    /**
     * Roll back a manual transaction.
     */
    public function rollback(): bool
    {
        return $this->pdo->inTransaction()
            ? $this->pdo->rollBack()
            : false;
    }

    /**
     * Return repository diagnostics.
     *
     * @return array<string,mixed>
     */
    public function diagnostics(): array
    {
        return [
            'repository' => static::class,
            'entity_type' => $this->entityType,
            'table' => $this->table,
            'primary_key' => $this->primaryKey,
            'public_key' => $this->publicKey,
            'field_count' => count(
                $this->fields
            ),
            'writable_field_count' => count(
                $this->writableFields
            ),
            'searchable_fields' =>
                $this->searchableFields,
            'sortable_fields' =>
                $this->sortableFields,
            'transaction_active' =>
                $this->pdo->inTransaction(),
            'driver' =>
                $this->pdo->getAttribute(
                    PDO::ATTR_DRIVER_NAME
                ),
        ];
    }

    /**
     * Configure internal field groups.
     */
    protected function configureFieldLists(): void
    {
        foreach (
            $this->fields
            as $field => $definition
        ) {
            $field = (string)$field;

            if (!is_array($definition)) {
                continue;
            }

            $this->selectableFields[] = $field;
            $this->sortableFields[] = $field;

            $generated = (
                $definition['generated']
                ?? false
            ) === true;

            $editable = (
                $definition['editable']
                ?? true
            ) !== false;

            if (!$generated && $editable) {
                $this->writableFields[] = $field;
            }

            if (
                ($definition['searchable'] ?? false)
                === true
            ) {
                $this->searchableFields[] =
                    $field;
            }
        }

        foreach (
            [
                $this->primaryKey,
                $this->publicKey,
            ]
            as $requiredField
        ) {
            if (
                isset($this->fields[$requiredField])
                && !in_array(
                    $requiredField,
                    $this->selectableFields,
                    true
                )
            ) {
                $this->selectableFields[] =
                    $requiredField;
            }
        }

        $this->selectableFields =
            array_values(
                array_unique(
                    $this->selectableFields
                )
            );

        $this->writableFields =
            array_values(
                array_unique(
                    $this->writableFields
                )
            );

        $this->searchableFields =
            array_values(
                array_unique(
                    $this->searchableFields
                )
            );

        $this->sortableFields =
            array_values(
                array_unique(
                    $this->sortableFields
                )
            );
    }

    /**
     * Prepare schema-safe entity data for writing.
     *
     * @return array<string,mixed>
     */
    protected function prepareWriteData(
        Entity $entity,
        bool $updating
    ): array {
        $data = [];

        foreach (
            $this->writableFields
            as $field
        ) {
            if (!$entity->has($field)) {
                continue;
            }

            if (
                $updating
                && in_array(
                    $field,
                    [
                        $this->primaryKey,
                        $this->publicKey,
                        'created_at',
                    ],
                    true
                )
            ) {
                continue;
            }

            $data[$field] = $this->encodeFieldValue(
                $field,
                $entity->get($field)
            );
        }

        if (!$updating) {
            if (
                isset($this->fields['created_at'])
                && !$entity->has('created_at')
            ) {
                $data['created_at'] =
                    gmdate('Y-m-d H:i:s');
            }

            if (
                isset($this->fields['updated_at'])
                && !$entity->has('updated_at')
            ) {
                $data['updated_at'] =
                    gmdate('Y-m-d H:i:s');
            }
        }

        return $data;
    }

    /**
     * Encode field values for SQL storage.
     */
    protected function encodeFieldValue(
        string $field,
        mixed $value
    ): mixed {
        $definition = $this->fields[$field]
            ?? [];

        $type = strtolower(
            trim(
                (string)(
                    $definition['type']
                    ?? ''
                )
            )
        );

        if (
            in_array(
                $type,
                ['array', 'json'],
                true
            )
        ) {
            if ($value === null) {
                return null;
            }

            $json = json_encode(
                $value,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
            );

            if ($json === false) {
                throw new RuntimeException(
                    sprintf(
                        'Unable to encode field "%s" as JSON.',
                        $field
                    )
                );
            }

            return $json;
        }

        if (
            in_array(
                $type,
                ['boolean', 'bool'],
                true
            )
        ) {
            return $value === null
                ? null
                : (
                    (bool)$value
                        ? 1
                        : 0
                );
        }

        return $value;
    }

    /**
     * Decode database values according to schema.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    protected function decodeRecord(
        array $record
    ): array {
        foreach (
            $record
            as $field => $value
        ) {
            $definition = $this->fields[$field]
                ?? [];

            $type = strtolower(
                trim(
                    (string)(
                        $definition['type']
                        ?? ''
                    )
                )
            );

            if (
                in_array(
                    $type,
                    ['array', 'json'],
                    true
                )
            ) {
                if (
                    $value === null
                    || $value === ''
                ) {
                    $record[$field] = [];
                    continue;
                }

                $decoded = json_decode(
                    (string)$value,
                    true
                );

                $record[$field] =
                    is_array($decoded)
                        ? $decoded
                        : [];

                continue;
            }

            if (
                in_array(
                    $type,
                    ['boolean', 'bool'],
                    true
                )
            ) {
                $record[$field] =
                    $value === null
                        ? null
                        : (bool)$value;

                continue;
            }

            if (
                in_array(
                    $type,
                    ['integer', 'int'],
                    true
                )
                && $value !== null
                && $value !== ''
            ) {
                $record[$field] =
                    (int)$value;

                continue;
            }

            if (
                in_array(
                    $type,
                    [
                        'decimal',
                        'float',
                        'double',
                        'number',
                    ],
                    true
                )
                && $value !== null
                && $value !== ''
            ) {
                $record[$field] =
                    (float)$value;
            }
        }

        return $record;
    }

    /**
     * Prepare values for PDO.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    protected function prepareBindings(
        array $data
    ): array {
        $bindings = [];

        foreach ($data as $field => $value) {
            $bindings[$field] =
                $this->prepareBindingValue($value);
        }

        return $bindings;
    }

    protected function prepareBindingValue(
        mixed $value
    ): mixed {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_array($value)) {
            $json = json_encode(
                $value,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
            );

            if ($json === false) {
                throw new RuntimeException(
                    'Unable to encode array binding.'
                );
            }

            return $json;
        }

        return $value;
    }

    /**
     * Resolve the best identifier available on an entity.
     *
     * @return array{field:string,value:mixed}
     */
    protected function resolveIdentifier(
        Entity $entity
    ): array {
        $primaryValue = $entity->get(
            $this->primaryKey
        );

        if (!$this->isEmpty($primaryValue)) {
            return [
                'field' => $this->primaryKey,
                'value' => $primaryValue,
            ];
        }

        $publicValue = $entity->get(
            $this->publicKey
        );

        return [
            'field' => $this->publicKey,
            'value' => $this->isEmpty(
                $publicValue
            )
                ? null
                : $publicValue,
        ];
    }

    protected function selectFieldSql(): string
    {
        return implode(
            ', ',
            array_map(
                fn (string $field): string =>
                    $this->quoteIdentifier($field),
                $this->selectableFields
            )
        );
    }

    protected function assertEntityType(
        Entity $entity
    ): void {
        if (
            $entity->entityType()
            !== $this->entityType
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Repository for "%s" cannot persist entity type "%s".',
                    $this->entityType,
                    $entity->entityType()
                )
            );
        }
    }

    protected function assertKnownField(
        string $field
    ): void {
        if (!array_key_exists($field, $this->fields)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Field "%s" is undefined for entity type "%s".',
                    $field,
                    $this->entityType
                )
            );
        }
    }

    protected function assertSortableField(
        string $field
    ): void {
        if (
            !in_array(
                $field,
                $this->sortableFields,
                true
            )
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Field "%s" cannot be used for sorting.',
                    $field
                )
            );
        }
    }

    protected function isGeneratedField(
        string $field
    ): bool {
        return (
            $this->fields[$field]['generated']
            ?? false
        ) === true;
    }

    protected function normalizeDirection(
        string $direction
    ): string {
        return strtolower(
            trim($direction)
        ) === 'asc'
            ? 'ASC'
            : 'DESC';
    }

    protected function normalizeEntityType(
        string $entityType
    ): string {
        $entityType = strtolower(
            trim($entityType)
        );

        return preg_replace(
            '/[^a-z0-9_]+/',
            '',
            $entityType
        ) ?? '';
    }

    protected function quoteIdentifier(
        string $identifier
    ): string {
        if (
            preg_match(
                '/^[A-Za-z_][A-Za-z0-9_]*$/',
                $identifier
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unsafe SQL identifier "%s".',
                    $identifier
                )
            );
        }

        return '`' . $identifier . '`';
    }

    protected function isEmpty(
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