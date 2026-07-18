<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/ValidationService.php
|--------------------------------------------------------------------------
| IPMdb Validation Service
|--------------------------------------------------------------------------
|
| Central validation layer for entities, collections, schemas, and data.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once dirname(__DIR__) . '/core/Entity.php';
require_once dirname(__DIR__) . '/core/EntityCollection.php';
require_once dirname(__DIR__) . '/schema/schema_registry.php';

final class ValidationService extends Service
{
    /**
     * @var array<string,array<int,callable>>
     */
    private array $rules = [];

    public function validateEntity(Entity $entity): bool
    {
        $this->reset();

        if (!$entity->validate()) {
            foreach ($entity->errors() as $error) {
                $this->addError(
                    (string)$error,
                    [
                        'entity_type' => $entity->entityType(),
                    ]
                );
            }
        }

        $this->applyCustomRules($entity);

        if ($this->succeeded()) {
            $this->addMessage(
                'Entity validation passed.',
                [
                    'entity_type' => $entity->entityType(),
                ]
            );
        }

        return $this->succeeded();
    }

    public function validateEntityOrFail(Entity $entity): Entity
    {
        if (!$this->validateEntity($entity)) {
            throw new EntityValidationException(
                $this->errorMessages()
            );
        }

        return $entity;
    }

    public function validateCollection(
        EntityCollection $collection
    ): bool {
        $this->reset();

        foreach ($collection as $index => $entity) {
            if (!$entity instanceof Entity) {
                $this->addError(
                    'Collection contains an invalid item.',
                    [
                        'index' => $index,
                    ]
                );

                continue;
            }

            if (!$entity->validate()) {
                foreach ($entity->errors() as $error) {
                    $this->addError(
                        (string)$error,
                        [
                            'index' => $index,
                            'entity_type' => $entity->entityType(),
                        ]
                    );
                }
            }

            $this->applyCustomRules(
                $entity,
                [
                    'index' => $index,
                ]
            );
        }

        if ($this->succeeded()) {
            $this->addMessage(
                'Collection validation passed.',
                [
                    'count' => $collection->count(),
                    'entity_type' => $collection->entityType(),
                ]
            );
        }

        return $this->succeeded();
    }

    public function validateCollectionOrFail(
        EntityCollection $collection
    ): EntityCollection {
        if (!$this->validateCollection($collection)) {
            throw new EntityValidationException(
                $this->errorMessages()
            );
        }

        return $collection;
    }

    public function validateSchema(
        array $schema,
        ?string $expectedEntityType = null
    ): bool {
        $this->reset();

        $errors = SchemaRegistry::validateSchema(
            $schema,
            $expectedEntityType
        );

        foreach ($errors as $error) {
            $this->addError(
                (string)$error,
                [
                    'expected_entity_type' => $expectedEntityType,
                ]
            );
        }

        if ($this->succeeded()) {
            $this->addMessage(
                'Schema validation passed.',
                [
                    'entity_type' =>
                        $schema['entity_type']
                        ?? $expectedEntityType,
                ]
            );
        }

        return $this->succeeded();
    }

    public function validateSchemaOrFail(
        array $schema,
        ?string $expectedEntityType = null
    ): array {
        if (
            !$this->validateSchema(
                $schema,
                $expectedEntityType
            )
        ) {
            throw new RuntimeException(
                implode(' ', $this->errorMessages())
            );
        }

        return $schema;
    }

    public function validateData(
        string $entityType,
        array $data,
        bool $exists = false
    ): bool {
        try {
            $entity = $exists
                ? Entity::hydrate($entityType, $data)
                : Entity::make($entityType, $data);
        } catch (Throwable $exception) {
            $this->reset();

            $this->addError(
                $exception->getMessage(),
                [
                    'entity_type' => $entityType,
                ]
            );

            return false;
        }

        return $this->validateEntity($entity);
    }

    public function addRule(
        string $entityType,
        callable $rule
    ): static {
        $entityType = $this->normalizeKey($entityType);

        if ($entityType === '') {
            throw new InvalidArgumentException(
                'Custom validation rule requires an entity type.'
            );
        }

        $this->rules[$entityType] ??= [];
        $this->rules[$entityType][] = $rule;

        return $this;
    }

    public function clearRules(
        ?string $entityType = null
    ): static {
        if ($entityType === null) {
            $this->rules = [];

            return $this;
        }

        $entityType = $this->normalizeKey($entityType);

        unset($this->rules[$entityType]);

        return $this;
    }

    public function ruleCount(
        ?string $entityType = null
    ): int {
        if ($entityType === null) {
            return array_sum(
                array_map(
                    'count',
                    $this->rules
                )
            );
        }

        $entityType = $this->normalizeKey($entityType);

        return count(
            $this->rules[$entityType] ?? []
        );
    }

    public function errorMessages(): array
    {
        return array_values(
            array_filter(
                array_map(
                    static fn (array $error): string =>
                        trim(
                            (string)($error['message'] ?? '')
                        ),
                    $this->errors()
                ),
                static fn (string $message): bool =>
                    $message !== ''
            )
        );
    }

    public function messageText(): array
    {
        return array_values(
            array_filter(
                array_map(
                    static fn (array $message): string =>
                        trim(
                            (string)($message['message'] ?? '')
                        ),
                    $this->messages()
                ),
                static fn (string $message): bool =>
                    $message !== ''
            )
        );
    }

    public function validateRequiredFields(
        array $data,
        array $requiredFields
    ): bool {
        $this->reset();

        foreach ($requiredFields as $field) {
            $field = trim((string)$field);

            if ($field === '') {
                continue;
            }

            $value = $data[$field] ?? null;

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

        return $this->succeeded();
    }

    public function validateEmail(
        mixed $value,
        string $field = 'email'
    ): bool {
        $email = trim((string)$value);

        if (
            $email === ''
            || filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            ) === false
        ) {
            $this->addError(
                sprintf(
                    '%s must contain a valid email address.',
                    ucwords(
                        str_replace('_', ' ', $field)
                    )
                ),
                [
                    'field' => $field,
                ]
            );

            return false;
        }

        return true;
    }

    public function validateUrl(
        mixed $value,
        string $field = 'url'
    ): bool {
        $url = trim((string)$value);

        if (
            $url === ''
            || filter_var(
                $url,
                FILTER_VALIDATE_URL
            ) === false
        ) {
            $this->addError(
                sprintf(
                    '%s must contain a valid URL.',
                    ucwords(
                        str_replace('_', ' ', $field)
                    )
                ),
                [
                    'field' => $field,
                ]
            );

            return false;
        }

        return true;
    }

    protected function applyCustomRules(
        Entity $entity,
        array $context = []
    ): void {
        $entityType = $entity->entityType();

        $rules = array_merge(
            $this->rules['entity'] ?? [],
            $this->rules[$entityType] ?? []
        );

        foreach ($rules as $index => $rule) {
            try {
                $result = $rule(
                    $entity,
                    $this
                );

                if ($result === true || $result === null) {
                    continue;
                }

                if (is_string($result)) {
                    $this->addError(
                        $result,
                        array_merge(
                            $context,
                            [
                                'entity_type' => $entityType,
                                'rule_index' => $index,
                            ]
                        )
                    );

                    continue;
                }

                if (is_array($result)) {
                    foreach ($result as $error) {
                        $error = trim((string)$error);

                        if ($error !== '') {
                            $this->addError(
                                $error,
                                array_merge(
                                    $context,
                                    [
                                        'entity_type' => $entityType,
                                        'rule_index' => $index,
                                    ]
                                )
                            );
                        }
                    }

                    continue;
                }

                if ($result === false) {
                    $this->addError(
                        'Custom validation rule failed.',
                        array_merge(
                            $context,
                            [
                                'entity_type' => $entityType,
                                'rule_index' => $index,
                            ]
                        )
                    );
                }
            } catch (Throwable $exception) {
                $this->addError(
                    $exception->getMessage(),
                    array_merge(
                        $context,
                        [
                            'entity_type' => $entityType,
                            'rule_index' => $index,
                        ]
                    )
                );
            }
        }
    }

    protected function isEmpty(mixed $value): bool
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
                'custom_rule_count' =>
                    $this->ruleCount(),
                'custom_rule_entities' =>
                    array_keys($this->rules),
            ]
        );
    }
}