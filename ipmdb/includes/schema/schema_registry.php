<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/schema/schema_registry.php
|--------------------------------------------------------------------------
| IPMdb Schema Registry
|--------------------------------------------------------------------------
|
| Discovers, registers, loads, and validates IPMdb entity schemas.
|
| Every registered entity has one canonical schema.
| Every schema remains independently loadable.
| Missing or malformed schemas fail clearly.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

final class SchemaRegistry
{
    /**
     * Canonical schema map.
     *
     * Additional schemas may be registered at runtime.
     *
     * @var array<string,string>
     */
    private static array $schemas = [
        'entity' => 'entity_schema.php',
    ];

    /**
     * Loaded schema cache.
     *
     * @var array<string,array<string,mixed>>
     */
    private static array $cache = [];

    /**
     * Return all registered entity names.
     *
     * @return array<int,string>
     */
    public static function names(): array
    {
        return array_keys(self::$schemas);
    }

    /**
     * Return the complete entity-to-file registry.
     *
     * @return array<string,string>
     */
    public static function all(): array
    {
        return self::$schemas;
    }

    /**
     * Determine whether an entity is registered.
     */
    public static function exists(string $entityType): bool
    {
        $entityType = self::normalizeEntityType($entityType);

        return $entityType !== ''
            && array_key_exists($entityType, self::$schemas);
    }

    /**
     * Return the registered filename for an entity.
     */
    public static function filename(string $entityType): ?string
    {
        $entityType = self::normalizeEntityType($entityType);

        if ($entityType === '') {
            return null;
        }

        return self::$schemas[$entityType] ?? null;
    }

    /**
     * Return the absolute schema path for an entity.
     */
    public static function path(string $entityType): ?string
    {
        $filename = self::filename($entityType);

        if ($filename === null) {
            return null;
        }

        return __DIR__ . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Register or replace an entity schema.
     */
    public static function register(
        string $entityType,
        string $filename
    ): void {
        $entityType = self::normalizeEntityType($entityType);
        $filename = trim($filename);

        if ($entityType === '') {
            throw new InvalidArgumentException(
                'Schema entity type is required.'
            );
        }

        if ($filename === '') {
            throw new InvalidArgumentException(
                'Schema filename is required.'
            );
        }

        if (basename($filename) !== $filename) {
            throw new InvalidArgumentException(
                'Schema filename must not contain a directory path.'
            );
        }

        if (!str_ends_with(strtolower($filename), '.php')) {
            throw new InvalidArgumentException(
                'Schema filename must end with .php.'
            );
        }

        self::$schemas[$entityType] = $filename;

        unset(self::$cache[$entityType]);
    }

    /**
     * Remove an entity from the registry.
     */
    public static function unregister(string $entityType): void
    {
        $entityType = self::normalizeEntityType($entityType);

        if ($entityType === '') {
            return;
        }

        unset(
            self::$schemas[$entityType],
            self::$cache[$entityType]
        );
    }

    /**
     * Load one canonical schema.
     *
     * @return array<string,mixed>
     */
    public static function load(string $entityType): array
    {
        $entityType = self::normalizeEntityType($entityType);

        if ($entityType === '') {
            throw new InvalidArgumentException(
                'Schema entity type is required.'
            );
        }

        if (isset(self::$cache[$entityType])) {
            return self::$cache[$entityType];
        }

        if (!self::exists($entityType)) {
            throw new RuntimeException(
                sprintf(
                    'Schema "%s" is not registered.',
                    $entityType
                )
            );
        }

        $path = self::path($entityType);

        if ($path === null || !is_file($path)) {
            throw new RuntimeException(
                sprintf(
                    'Schema file for "%s" was not found.',
                    $entityType
                )
            );
        }

        $schema = require $path;

        if (!is_array($schema)) {
            throw new RuntimeException(
                sprintf(
                    'Schema "%s" must return an array.',
                    $entityType
                )
            );
        }

        $errors = self::validateSchema(
            $schema,
            $entityType
        );

        if ($errors !== []) {
            throw new RuntimeException(
                sprintf(
                    'Schema "%s" is invalid: %s',
                    $entityType,
                    implode(' ', $errors)
                )
            );
        }

        self::$cache[$entityType] = $schema;

        return $schema;
    }

    /**
     * Load all available registered schemas.
     *
     * Missing files are omitted unless strict mode is enabled.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function loadAll(bool $strict = false): array
    {
        $loaded = [];

        foreach (self::$schemas as $entityType => $filename) {
            $path = __DIR__ . DIRECTORY_SEPARATOR . $filename;

            if (!is_file($path)) {
                if ($strict) {
                    throw new RuntimeException(
                        sprintf(
                            'Registered schema file "%s" is missing.',
                            $filename
                        )
                    );
                }

                continue;
            }

            $loaded[$entityType] = self::load($entityType);
        }

        return $loaded;
    }

    /**
     * Discover schema files in this directory.
     *
     * Files must end with "_schema.php".
     *
     * @return array<string,string>
     */
    public static function discover(bool $register = true): array
    {
        $files = glob(
            __DIR__ . DIRECTORY_SEPARATOR . '*_schema.php'
        ) ?: [];

        $discovered = [];

        foreach ($files as $path) {
            $filename = basename($path);

            if ($filename === 'schema_registry.php') {
                continue;
            }

            $entityType = substr(
                $filename,
                0,
                -strlen('_schema.php')
            );

            $entityType = self::normalizeEntityType(
                $entityType
            );

            if ($entityType === '') {
                continue;
            }

            $discovered[$entityType] = $filename;

            if ($register) {
                self::register(
                    $entityType,
                    $filename
                );
            }
        }

        ksort($discovered);

        return $discovered;
    }

    /**
     * Validate one schema definition.
     *
     * @param array<string,mixed> $schema
     * @return array<int,string>
     */
    public static function validateSchema(
        array $schema,
        ?string $expectedEntityType = null
    ): array {
        $errors = [];

        $requiredKeys = [
            'schema_version',
            'platform',
            'entity_type',
            'display_name',
            'primary_key',
            'public_key',
            'fields',
        ];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $schema)) {
                $errors[] = sprintf(
                    'Missing required key "%s".',
                    $key
                );
            }
        }

        if (
            isset($schema['fields'])
            && !is_array($schema['fields'])
        ) {
            $errors[] = '"fields" must be an array.';
        }

        $schemaEntityType = self::normalizeEntityType(
            (string)($schema['entity_type'] ?? '')
        );

        if ($schemaEntityType === '') {
            $errors[] = '"entity_type" must be valid.';
        }

        if ($expectedEntityType !== null) {
            $expectedEntityType = self::normalizeEntityType(
                $expectedEntityType
            );

            if (
                $expectedEntityType !== ''
                && $schemaEntityType !== $expectedEntityType
            ) {
                $errors[] = sprintf(
                    'Schema entity type "%s" does not match registry key "%s".',
                    $schemaEntityType,
                    $expectedEntityType
                );
            }
        }

        $primaryKey = trim(
            (string)($schema['primary_key'] ?? '')
        );

        $publicKey = trim(
            (string)($schema['public_key'] ?? '')
        );

        if ($primaryKey === '') {
            $errors[] = '"primary_key" cannot be empty.';
        }

        if ($publicKey === '') {
            $errors[] = '"public_key" cannot be empty.';
        }

        $fields = $schema['fields'] ?? [];

        if (is_array($fields)) {
            if (
                $primaryKey !== ''
                && !array_key_exists($primaryKey, $fields)
            ) {
                $errors[] = sprintf(
                    'Primary key field "%s" is undefined.',
                    $primaryKey
                );
            }

            if (
                $publicKey !== ''
                && !array_key_exists($publicKey, $fields)
            ) {
                $errors[] = sprintf(
                    'Public key field "%s" is undefined.',
                    $publicKey
                );
            }

            foreach ($fields as $field => $definition) {
                if (!is_string($field) || trim($field) === '') {
                    $errors[] = 'Every field requires a valid name.';
                    continue;
                }

                if (!is_array($definition)) {
                    $errors[] = sprintf(
                        'Field "%s" must contain an array definition.',
                        $field
                    );
                    continue;
                }

                $type = trim(
                    (string)($definition['type'] ?? '')
                );

                if ($type === '') {
                    $errors[] = sprintf(
                        'Field "%s" requires a type.',
                        $field
                    );
                }

                if (
                    isset($definition['values'])
                    && !is_array($definition['values'])
                ) {
                    $errors[] = sprintf(
                        'Field "%s" values must be an array.',
                        $field
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * Clear one cached schema or the entire cache.
     */
    public static function clearCache(
        ?string $entityType = null
    ): void {
        if ($entityType === null) {
            self::$cache = [];
            return;
        }

        $entityType = self::normalizeEntityType(
            $entityType
        );

        unset(self::$cache[$entityType]);
    }

    /**
     * Return registry diagnostics.
     *
     * @return array<string,mixed>
     */
    public static function diagnostics(): array
    {
        $registered = [];
        $available = [];
        $missing = [];

        foreach (self::$schemas as $entityType => $filename) {
            $registered[$entityType] = $filename;

            $path = __DIR__
                . DIRECTORY_SEPARATOR
                . $filename;

            if (is_file($path)) {
                $available[] = $entityType;
            } else {
                $missing[] = $entityType;
            }
        }

        return [
            'platform' => 'IPMdb.ai',
            'registry_version' => '1.0.0',
            'directory' => __DIR__,
            'registered_count' => count($registered),
            'available_count' => count($available),
            'missing_count' => count($missing),
            'registered' => $registered,
            'available' => $available,
            'missing' => $missing,
            'cached' => array_keys(self::$cache),
        ];
    }

    /**
     * Normalize a registry entity key.
     */
    private static function normalizeEntityType(
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
}