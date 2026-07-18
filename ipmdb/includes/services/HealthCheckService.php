<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/HealthCheckService.php
|--------------------------------------------------------------------------
| IPMdb non-destructive system health and deployment-readiness service.
| Checks inspect only. They do not repair, deploy, or write application data.
*/

require_once __DIR__ . '/Service.php';
require_once dirname(__DIR__) . '/core/GraphUtilities.php';

final class HealthCheckService extends Service
{
    use GraphUtilities;

    private array $healthConfig = [];
    private array $checks = [];
    private array $results = [];
    private array $files = [];
    private array $classes = [];
    private array $traits = [];
    private array $services = [];
    private ?array $projectConfig = null;

    private array $statuses = ['pass', 'warn', 'fail', 'skip', 'error'];
    private array $categories = [
        'environment', 'configuration', 'filesystem', 'core', 'schema',
        'service', 'database', 'graph', 'workflow', 'ledger', 'audit',
        'deployment', 'security', 'route', 'custom',
    ];
    private array $requiredExtensions = ['json', 'pdo', 'filter', 'hash', 'session'];
    private array $optionalExtensions = ['pdo_mysql', 'mbstring', 'openssl', 'curl', 'intl', 'fileinfo'];

    public function __construct(array $config = [], array $context = [])
    {
        parent::__construct($config, $context);
        $this->healthConfig = $config;

        if (isset($config['required_extensions']) && is_array($config['required_extensions'])) {
            $this->requiredExtensions = $this->stringList(array_merge($this->requiredExtensions, $config['required_extensions']));
        }
        if (isset($config['optional_extensions']) && is_array($config['optional_extensions'])) {
            $this->optionalExtensions = $this->stringList(array_merge($this->optionalExtensions, $config['optional_extensions']));
        }

        $this->registerDefaultInventory();
        $this->registerConfiguredInventory();
        $this->registerDefaultChecks();
    }

    public function run(array $options = []): array
    {
        $this->results = [];
        $started = microtime(true);
        $options = array_replace([
            'categories' => [],
            'check_ids' => [],
            'include_optional' => true,
            'stop_on_error' => false,
            'environment' => true,
            'configuration' => true,
            'filesystem' => true,
            'services' => true,
            'database' => true,
            'instantiate_services' => true,
            'instantiate_optional_services' => false,
        ], $options);

        $categoryFilter = $this->machineList($options['categories']);
        $checkFilter = $this->machineList($options['check_ids']);

        foreach ($this->checks as $check) {
            $id = (string)$check['check_id'];
            $category = (string)$check['category'];

            if ($checkFilter !== [] && !in_array($id, $checkFilter, true)) {
                continue;
            }
            if ($categoryFilter !== [] && !in_array($category, $categoryFilter, true)) {
                continue;
            }
            if (!$this->categoryEnabled($category, $options)) {
                $this->results[] = $this->result($check, 'skip', 'Check category disabled by runtime options.');
                continue;
            }
            if (($check['optional'] ?? false) && !$options['include_optional']) {
                $this->results[] = $this->result($check, 'skip', 'Optional checks are disabled.');
                continue;
            }

            try {
                $result = $this->execute($check, $options);
            } catch (Throwable $e) {
                $result = $this->result($check, 'error', get_class($e) . ': ' . $e->getMessage(), [
                    'exception_class' => get_class($e),
                    'exception_code' => $e->getCode(),
                ]);
            }

            $this->results[] = $result;
            if ($options['stop_on_error'] && in_array($result['status'], ['fail', 'error'], true)) {
                break;
            }
        }

        $report = $this->buildReport(round((microtime(true) - $started) * 1000, 3), $options);
        $report['checksum'] = $this->checksum($report);
        return $report;
    }

    public function runCheck(string $checkId, array $options = []): array
    {
        $checkId = $this->machineKey($checkId);
        if ($checkId === '' || !isset($this->checks[$checkId])) {
            throw new InvalidArgumentException('Registered health check was not found.');
        }
        try {
            return $this->execute($this->checks[$checkId], $options);
        } catch (Throwable $e) {
            return $this->result($this->checks[$checkId], 'error', get_class($e) . ': ' . $e->getMessage());
        }
    }

    public function registerCheck(
        string $checkId,
        string $title,
        string $category,
        callable $handler,
        array $options = []
    ): self {
        $checkId = $this->machineKey($checkId);
        $title = trim($title);
        if ($checkId === '' || $title === '') {
            throw new InvalidArgumentException('Health check identifier and title are required.');
        }
        $this->checks[$checkId] = [
            'check_id' => $checkId,
            'title' => $title,
            'description' => trim((string)($options['description'] ?? '')),
            'category' => $this->category($category),
            'required' => (bool)($options['required'] ?? true),
            'optional' => (bool)($options['optional'] ?? false),
            'handler' => $handler,
            'metadata' => is_array($options['metadata'] ?? null) ? $options['metadata'] : [],
        ];
        return $this;
    }

    public function unregisterCheck(string $checkId): self
    {
        unset($this->checks[$this->machineKey($checkId)]);
        return $this;
    }

    public function registerFile(string $id, string $path, bool $required = true, array $metadata = []): self
    {
        $id = $this->machineKey($id);
        $path = $this->path($path);
        if ($id === '' || $path === '') {
            throw new InvalidArgumentException('File inventory requires an identifier and path.');
        }
        $this->files[$id] = compact('id', 'path', 'required', 'metadata');
        return $this;
    }

    public function registerClass(
        string $class,
        string $path,
        bool $required = true,
        bool $instantiate = false,
        array $constructorArguments = []
    ): self {
        $class = trim($class);
        if ($class === '') {
            throw new InvalidArgumentException('Class name is required.');
        }
        $this->classes[$class] = [
            'class' => $class,
            'path' => $this->path($path),
            'required' => $required,
            'instantiate' => $instantiate,
            'constructor_arguments' => $constructorArguments,
        ];
        return $this;
    }

    public function registerTrait(string $trait, string $path, bool $required = true): self
    {
        $trait = trim($trait);
        if ($trait === '') {
            throw new InvalidArgumentException('Trait name is required.');
        }
        $this->traits[$trait] = [
            'trait' => $trait,
            'path' => $this->path($path),
            'required' => $required,
        ];
        return $this;
    }

    public function registerService(
        string $class,
        string $path,
        bool $required = true,
        bool $instantiate = true,
        array $constructorArguments = []
    ): self {
        $class = trim($class);
        if ($class === '') {
            throw new InvalidArgumentException('Service class name is required.');
        }
        $this->services[$class] = [
            'class' => $class,
            'path' => $this->path($path),
            'required' => $required,
            'instantiate' => $instantiate,
            'constructor_arguments' => $constructorArguments,
        ];
        return $this;
    }

    public function registeredChecks(): array
    {
        return array_values(array_map(static function (array $check): array {
            unset($check['handler']);
            return $check;
        }, $this->checks));
    }

    public function fileInventory(): array { return array_values($this->files); }
    public function classInventory(): array { return array_values($this->classes); }
    public function traitInventory(): array { return array_values($this->traits); }
    public function serviceInventory(): array { return array_values($this->services); }
    public function results(): array { return $this->results; }

    public function overallStatus(?array $results = null): string
    {
        $results ??= $this->results;
        $severity = ['pass' => 0, 'skip' => 0, 'warn' => 1, 'fail' => 2, 'error' => 3];
        $highest = 0;
        $overall = 'pass';
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $status = $this->status((string)($result['status'] ?? 'error'));
            if (($severity[$status] ?? 3) > $highest) {
                $highest = $severity[$status] ?? 3;
                $overall = $status;
            }
        }
        return $overall;
    }

    public function isDeployable(?array $report = null): bool
    {
        $report ??= $this->buildReport(0.0, []);
        return ($report['deployment_readiness']['ready'] ?? false) === true;
    }

    public function diagnostics(): array
    {
        return [
            'service' => static::class,
            'statuses' => $this->statuses,
            'categories' => $this->categories,
            'required_extensions' => $this->requiredExtensions,
            'optional_extensions' => $this->optionalExtensions,
            'registered_check_count' => count($this->checks),
            'registered_file_count' => count($this->files),
            'registered_class_count' => count($this->classes),
            'registered_trait_count' => count($this->traits),
            'registered_service_count' => count($this->services),
            'filesystem_writes' => false,
            'database_writes' => false,
            'automatic_repairs' => false,
            'automatic_deployment' => false,
        ];
    }

    public function renderJson(?array $report = null, bool $pretty = true): string
    {
        $report ??= $this->run();
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $json = json_encode($report, $flags);
        if ($json === false) {
            throw new RuntimeException('Health report JSON encoding failed: ' . json_last_error_msg());
        }
        return $json;
    }

    public function renderHtml(?array $report = null): string
    {
        $report ??= $this->run();
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $readiness = is_array($report['deployment_readiness'] ?? null) ? $report['deployment_readiness'] : [];
        $rows = '';

        foreach (($report['results'] ?? []) as $result) {
            if (!is_array($result)) {
                continue;
            }
            $status = $this->status((string)($result['status'] ?? 'error'));
            $rows .= '<tr><td><span class="badge ' . $this->h($status) . '">' . $this->h(strtoupper($status)) . '</span></td>'
                . '<td>' . $this->h((string)($result['category'] ?? 'custom')) . '</td>'
                . '<td><strong>' . $this->h((string)($result['title'] ?? '')) . '</strong><small>'
                . $this->h((string)($result['check_id'] ?? '')) . '</small></td>'
                . '<td>' . $this->h((string)($result['message'] ?? '')) . '</td>'
                . '<td class="num">' . number_format((float)($result['duration_ms'] ?? 0), 3) . '</td></tr>';
        }

        $ready = ($readiness['ready'] ?? false) === true;
        $metrics = '';
        foreach (['total', 'pass', 'warn', 'fail', 'skip', 'error'] as $key) {
            $metrics .= '<div class="metric"><b>' . (int)($summary[$key] ?? 0) . '</b>' . $this->h(ucfirst($key)) . '</div>';
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>IPMdb System Health</title><style>'
            . ':root{color-scheme:dark;font-family:system-ui,-apple-system,sans-serif;background:#07111f;color:#eaf3ff}'
            . '*{box-sizing:border-box}body{margin:0;padding:24px;background:linear-gradient(145deg,#07111f,#0d2340);min-height:100vh}'
            . '.wrap{max-width:1280px;margin:auto}.top{display:grid;grid-template-columns:2fr 1fr;gap:16px}.card{background:#0a1b31e6;border:1px solid #29496e;border-radius:16px;padding:20px}'
            . 'h1{margin:0 0 8px;font-size:clamp(28px,5vw,52px)}h2{margin:0 0 12px}.state{font-size:22px;font-weight:800;letter-spacing:.08em}'
            . '.grid{display:grid;grid-template-columns:repeat(6,minmax(90px,1fr));gap:10px;margin:16px 0}.metric{text-align:center;background:#0a1b31;border:1px solid #29496e;border-radius:12px;padding:12px}.metric b{display:block;font-size:24px}'
            . '.table{overflow:auto}table{width:100%;border-collapse:collapse;min-width:850px}th,td{text-align:left;padding:12px;border-bottom:1px solid #203d60;vertical-align:top}th{background:#0b1e37}.num{text-align:right}'
            . 'small{display:block;color:#9fb5cf;margin-top:4px}.badge{display:inline-block;min-width:64px;text-align:center;padding:5px 8px;border-radius:999px;font-size:12px;font-weight:800}'
            . '.pass{background:#123d2d;color:#8ff0bd}.warn{background:#493812;color:#ffd979}.fail,.error{background:#4c1e28;color:#ff9aad}.skip{background:#26364a;color:#b8cbe2}.ready{color:#8ff0bd}.blocked{color:#ff9aad}'
            . '@media(max-width:800px){body{padding:12px}.top{grid-template-columns:1fr}.grid{grid-template-columns:repeat(2,1fr)}}'
            . '</style></head><body><main class="wrap"><section class="top"><div class="card"><h1>IPMdb System Health</h1><div class="state ' . $this->h((string)($report['status'] ?? 'error')) . '">' . $this->h(strtoupper((string)($report['status'] ?? 'error'))) . '</div><p>Generated ' . $this->h((string)($report['generated_at'] ?? '')) . '</p></div>'
            . '<div class="card"><h2>Deployment</h2><div class="state ' . ($ready ? 'ready' : 'blocked') . '">' . ($ready ? 'READY' : 'BLOCKED') . '</div><p>' . $this->h((string)($readiness['disposition'] ?? 'unknown')) . '</p><p>Score: ' . number_format((float)($readiness['score'] ?? 0), 2) . '%</p></div></section>'
            . '<section class="grid">' . $metrics . '</section><section class="card table"><table><thead><tr><th>Status</th><th>Category</th><th>Check</th><th>Message</th><th>ms</th></tr></thead><tbody>' . $rows . '</tbody></table></section>'
            . '<p><small>Checksum: ' . $this->h((string)($report['checksum'] ?? '')) . '</small></p></main></body></html>';
    }

    private function registerDefaultInventory(): void
    {
        $includes = dirname(__DIR__);
        $project = dirname($includes);
        $core = $includes . '/core';
        $schema = $includes . '/schema';
        $services = $includes . '/services';

        foreach ([
            'service_base' => [$services . '/Service.php', true],
            'graph_utilities' => [$core . '/GraphUtilities.php', true],
            'entity_core' => [$core . '/Entity.php', true],
            'entity_collection_core' => [$core . '/EntityCollection.php', true],
            'repository_core' => [$core . '/Repository.php', true],
            'entity_schema' => [$schema . '/entity_schema.php', true],
            'schema_registry' => [$schema . '/schema_registry.php', true],
            'translation_schema' => [$schema . '/translation_schema.php', true],
            'project_config' => [$project . '/config.php', false],
        ] as $id => [$path, $required]) {
            $this->registerFile($id, $path, $required);
        }

        $this->registerTrait('GraphUtilities', $core . '/GraphUtilities.php', true);
        $this->registerClass('Entity', $core . '/Entity.php', true);
        $this->registerClass('EntityCollection', $core . '/EntityCollection.php', true);
        $this->registerClass('Repository', $core . '/Repository.php', true);

        $required = [
            'ValidationService', 'RelationshipService', 'EventService', 'DecisionService',
            'WorkflowService', 'AuditService', 'LedgerService', 'DeploymentService',
        ];
        foreach ($required as $class) {
            $this->registerService($class, $services . '/' . $class . '.php', true, true);
        }

        if (is_dir($services)) {
            foreach (scandir($services) ?: [] as $entry) {
                if (!preg_match('/^([A-Za-z][A-Za-z0-9_]*)Service\.php$/', $entry, $match)) {
                    continue;
                }
                $class = $match[1] . 'Service';
                if ($class === 'HealthCheckService' || $class === 'ServiceService' || isset($this->services[$class])) {
                    continue;
                }
                $this->registerService($class, $services . '/' . $entry, false, true);
            }
        }
    }

    private function registerConfiguredInventory(): void
    {
        foreach (($this->healthConfig['files'] ?? []) as $id => $definition) {
            if (is_string($definition)) {
                $this->registerFile((string)$id, $definition, true);
            } elseif (is_array($definition)) {
                $this->registerFile(
                    (string)($definition['id'] ?? $id),
                    (string)($definition['path'] ?? ''),
                    (bool)($definition['required'] ?? true),
                    is_array($definition['metadata'] ?? null) ? $definition['metadata'] : []
                );
            }
        }
        foreach (($this->healthConfig['classes'] ?? []) as $definition) {
            if (is_array($definition)) {
                $this->registerClass(
                    (string)($definition['class'] ?? ''),
                    (string)($definition['path'] ?? ''),
                    (bool)($definition['required'] ?? true),
                    (bool)($definition['instantiate'] ?? false),
                    is_array($definition['constructor_arguments'] ?? null) ? $definition['constructor_arguments'] : []
                );
            }
        }
        foreach (($this->healthConfig['traits'] ?? []) as $definition) {
            if (is_array($definition)) {
                $this->registerTrait(
                    (string)($definition['trait'] ?? ''),
                    (string)($definition['path'] ?? ''),
                    (bool)($definition['required'] ?? true)
                );
            }
        }
        foreach (($this->healthConfig['services'] ?? []) as $definition) {
            if (is_array($definition)) {
                $this->registerService(
                    (string)($definition['class'] ?? ''),
                    (string)($definition['path'] ?? ''),
                    (bool)($definition['required'] ?? true),
                    (bool)($definition['instantiate'] ?? true),
                    is_array($definition['constructor_arguments'] ?? null) ? $definition['constructor_arguments'] : []
                );
            }
        }
    }

    private function registerDefaultChecks(): void
    {
        $this->registerCheck('php_runtime', 'PHP runtime', 'environment', fn (self $s): array => $s->checkPhpRuntime());
        $this->registerCheck('required_extensions', 'Required PHP extensions', 'environment', fn (self $s): array => $s->checkExtensions($s->requiredExtensions, true));
        $this->registerCheck('optional_extensions', 'Optional PHP extensions', 'environment', fn (self $s): array => $s->checkExtensions($s->optionalExtensions, false), ['required' => false, 'optional' => true]);
        $this->registerCheck('runtime_limits', 'PHP runtime limits', 'environment', fn (self $s): array => $s->checkRuntimeLimits(), ['required' => false]);
        $this->registerCheck('project_configuration', 'Project configuration', 'configuration', fn (self $s): array => $s->checkConfiguration());
        $this->registerCheck('required_files', 'Required files', 'filesystem', fn (self $s): array => $s->checkFiles(true));
        $this->registerCheck('optional_files', 'Optional files', 'filesystem', fn (self $s): array => $s->checkFiles(false), ['required' => false, 'optional' => true]);
        $this->registerCheck('filesystem_access', 'Filesystem access', 'filesystem', fn (self $s): array => $s->checkFilesystem());
        $this->registerCheck('trait_loading', 'Trait loading', 'core', fn (self $s): array => $s->checkTraits());
        $this->registerCheck('class_loading', 'Core class loading', 'core', fn (self $s): array => $s->checkClasses());
        $this->registerCheck('schema_files', 'Schema files', 'schema', fn (self $s): array => $s->checkSchemas());
        $this->registerCheck('service_loading', 'Service loading and constructors', 'service', fn (self $s, array $c, array $o): array => $s->checkServices($o));
        $this->registerCheck('database_connection', 'Database connection', 'database', fn (self $s): array => $s->checkDatabase(), ['required' => false, 'optional' => true]);
        $this->registerCheck('database_schema', 'Database schema', 'database', fn (self $s): array => $s->checkDatabaseSchema(), ['required' => false, 'optional' => true]);
        $this->registerCheck('graph_layer', 'Graph layer', 'graph', fn (self $s): array => $s->checkGraph());
        $this->registerCheck('workflow_layer', 'Workflow layer', 'workflow', fn (self $s): array => $s->checkDomainService('WorkflowService'));
        $this->registerCheck('ledger_layer', 'Ledger layer', 'ledger', fn (self $s): array => $s->checkDomainService('LedgerService'));
        $this->registerCheck('audit_layer', 'Audit layer', 'audit', fn (self $s): array => $s->checkDomainService('AuditService'));
        $this->registerCheck('deployment_layer', 'Deployment layer', 'deployment', fn (self $s): array => $s->checkDomainService('DeploymentService'));
        $this->registerCheck('route_files', 'Configured route files', 'route', fn (self $s): array => $s->checkRoutes(), ['required' => false, 'optional' => true]);
        $this->registerCheck('security_configuration', 'Security configuration', 'security', fn (self $s): array => $s->checkSecurity(), ['required' => false]);
    }

    private function checkPhpRuntime(): array
    {
        $minimum = (string)($this->healthConfig['minimum_php_version'] ?? '8.1.0');
        $ok = version_compare(PHP_VERSION, $minimum, '>=');
        return $this->raw($ok ? 'pass' : 'fail', $ok ? 'PHP runtime meets the configured minimum.' : 'PHP runtime is below the configured minimum.', [
            'current_version' => PHP_VERSION,
            'minimum_version' => $minimum,
            'sapi' => PHP_SAPI,
            'os_family' => PHP_OS_FAMILY,
            'architecture_bits' => PHP_INT_SIZE * 8,
        ]);
    }

    private function checkExtensions(array $extensions, bool $required): array
    {
        $loaded = [];
        $missing = [];
        foreach ($extensions as $extension) {
            if (extension_loaded($extension)) {
                $loaded[] = $extension;
            } else {
                $missing[] = $extension;
            }
        }
        return $this->raw(
            $missing === [] ? 'pass' : ($required ? 'fail' : 'warn'),
            $missing === [] ? 'All extensions in this inventory are loaded.' : 'Missing extensions: ' . implode(', ', $missing) . '.',
            compact('required', 'loaded', 'missing')
        );
    }

    private function checkRuntimeLimits(): array
    {
        $memoryRaw = (string)ini_get('memory_limit');
        $memory = $this->iniBytes($memoryRaw);
        $minimum = (int)($this->healthConfig['minimum_memory_bytes'] ?? 134217728);
        $warnings = [];
        if ($memory > 0 && $memory < $minimum) {
            $warnings[] = 'Memory limit is below the configured minimum.';
        }
        if ((int)ini_get('max_execution_time') > 0 && (int)ini_get('max_execution_time') < 30) {
            $warnings[] = 'Maximum execution time is below 30 seconds.';
        }
        return $this->raw($warnings === [] ? 'pass' : 'warn', $warnings === [] ? 'Runtime limits are suitable for health checks.' : implode(' ', $warnings), [
            'memory_limit' => $memoryRaw,
            'memory_limit_bytes' => $memory,
            'minimum_memory_bytes' => $minimum,
            'max_execution_time' => (int)ini_get('max_execution_time'),
        ]);
    }

    private function checkConfiguration(): array
    {
        $project = $this->projectConfig();
        $path = dirname(dirname(__DIR__)) . '/config.php';
        if ($project === [] && $this->healthConfig === []) {
            return $this->raw('warn', 'No readable project configuration array was found.', ['path' => $path, 'file_exists' => is_file($path)]);
        }
        return $this->raw('pass', $project !== [] ? 'Project configuration loaded.' : 'Health configuration was supplied directly.', [
            'path' => $path,
            'project_config_keys' => array_keys($project),
            'direct_config_keys' => array_keys($this->healthConfig),
        ]);
    }

    private function checkFiles(bool $required): array
    {
        $found = [];
        $missing = [];
        foreach ($this->files as $file) {
            if ((bool)$file['required'] !== $required) {
                continue;
            }
            if (is_file($file['path']) && is_readable($file['path'])) {
                $found[] = [
                    'id' => $file['id'],
                    'path' => $file['path'],
                    'size_bytes' => filesize($file['path']) ?: 0,
                    'readable' => true,
                ];
            } else {
                $missing[] = [
                    'id' => $file['id'],
                    'path' => $file['path'],
                    'exists' => is_file($file['path']),
                    'readable' => is_readable($file['path']),
                ];
            }
        }
        return $this->raw($missing === [] ? 'pass' : ($required ? 'fail' : 'warn'), $missing === [] ? 'All files in this inventory are present.' : count($missing) . ' file(s) are missing.', compact('required', 'found', 'missing'));
    }

    private function checkFilesystem(): array
    {
        $includes = dirname(__DIR__);
        $paths = [dirname($includes), $includes, $includes . '/core', $includes . '/schema', $includes . '/services'];
        $paths = array_values(array_unique(array_merge($paths, $this->stringList($this->healthConfig['readable_paths'] ?? []))));
        $inspected = [];
        $failed = [];
        foreach ($paths as $path) {
            $exists = file_exists($path);
            $readable = $exists && is_readable($path);
            $inspected[] = ['path' => $path, 'exists' => $exists, 'readable' => $readable, 'writable' => $exists && is_writable($path)];
            if (!$readable) {
                $failed[] = $path;
            }
        }
        foreach ($this->stringList($this->healthConfig['writable_paths'] ?? []) as $path) {
            if (!file_exists($path) || !is_writable($path)) {
                $failed[] = $path;
            }
        }
        $failed = array_values(array_unique($failed));
        return $this->raw($failed === [] ? 'pass' : 'fail', $failed === [] ? 'Configured filesystem paths are accessible.' : 'Filesystem access failed for: ' . implode(', ', $failed) . '.', [
            'inspected' => $inspected,
            'open_basedir' => (string)ini_get('open_basedir'),
            'temp_directory' => sys_get_temp_dir(),
            'temp_directory_writable' => is_writable(sys_get_temp_dir()),
        ]);
    }

    private function checkTraits(): array
    {
        $loaded = [];
        $failed = [];
        foreach ($this->traits as $definition) {
            try {
                if (!trait_exists($definition['trait'], false)) {
                    $this->requirePath($definition['path']);
                }
                if (!trait_exists($definition['trait'], false)) {
                    throw new RuntimeException('Trait was not declared.');
                }
                $loaded[] = $definition['trait'];
            } catch (Throwable $e) {
                $failed[] = ['trait' => $definition['trait'], 'path' => $definition['path'], 'required' => $definition['required'], 'reason' => $e->getMessage()];
            }
        }
        return $this->inventoryRaw($loaded, $failed, 'trait');
    }

    private function checkClasses(): array
    {
        $loaded = [];
        $failed = [];
        foreach ($this->classes as $definition) {
            try {
                if (!class_exists($definition['class'], false)) {
                    $this->requirePath($definition['path']);
                }
                if (!class_exists($definition['class'], false)) {
                    throw new RuntimeException('Class was not declared.');
                }
                $reflection = new ReflectionClass($definition['class']);
                if ($definition['instantiate'] && !$reflection->isAbstract()) {
                    $reflection->newInstanceArgs($definition['constructor_arguments']);
                }
                $loaded[] = ['class' => $definition['class'], 'abstract' => $reflection->isAbstract(), 'instantiated' => $definition['instantiate'] && !$reflection->isAbstract()];
            } catch (Throwable $e) {
                $failed[] = ['class' => $definition['class'], 'path' => $definition['path'], 'required' => $definition['required'], 'reason' => get_class($e) . ': ' . $e->getMessage()];
            }
        }
        return $this->inventoryRaw($loaded, $failed, 'class');
    }

    private function checkSchemas(): array
    {
        $root = dirname(__DIR__) . '/schema';
        $valid = [];
        $invalid = [];
        foreach (['entity_schema.php', 'schema_registry.php', 'translation_schema.php'] as $file) {
            $path = $root . '/' . $file;
            if (!is_file($path) || !is_readable($path) || filesize($path) === 0) {
                $invalid[] = ['path' => $path, 'reason' => 'Missing, unreadable, or empty.'];
            } else {
                $valid[] = ['path' => $path, 'size_bytes' => filesize($path) ?: 0];
            }
        }
        return $this->raw($invalid === [] ? 'pass' : 'fail', $invalid === [] ? 'Required schema files are present and readable.' : count($invalid) . ' schema file(s) failed validation.', compact('valid', 'invalid'));
    }

    private function checkServices(array $options): array
    {
        $loaded = [];
        $failed = [];
        $skipped = [];
        $instantiate = ($options['instantiate_services'] ?? true) === true;
        $instantiateOptional = ($options['instantiate_optional_services'] ?? false) === true;

        foreach ($this->orderedServices() as $definition) {
            $class = $definition['class'];
            try {
                if (!class_exists($class, false)) {
                    $this->requirePath($definition['path']);
                }
                if (!class_exists($class, false)) {
                    throw new RuntimeException('Service class was not declared.');
                }
                $reflection = new ReflectionClass($class);
                if (!$reflection->isSubclassOf(Service::class)) {
                    throw new RuntimeException('Registered service does not extend Service.');
                }
                $didInstantiate = false;
                $shouldInstantiate = $definition['instantiate'] && $instantiate && ($definition['required'] || $instantiateOptional) && !$reflection->isAbstract();
                if ($shouldInstantiate) {
                    $args = $definition['constructor_arguments'];
                    $constructor = $reflection->getConstructor();
                    if ($args === [] && $constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                        $reason = 'Constructor requires arguments and no health-check constructor arguments were configured.';
                        if ($definition['required']) {
                            throw new RuntimeException($reason);
                        }
                        $skipped[] = ['class' => $class, 'reason' => $reason];
                    } else {
                        $reflection->newInstanceArgs($args);
                        $didInstantiate = true;
                    }
                }
                $loaded[] = ['class' => $class, 'required' => $definition['required'], 'abstract' => $reflection->isAbstract(), 'instantiated' => $didInstantiate, 'path' => $definition['path']];
            } catch (Throwable $e) {
                $failed[] = ['class' => $class, 'path' => $definition['path'], 'required' => $definition['required'], 'reason' => get_class($e) . ': ' . $e->getMessage()];
            }
        }

        $requiredFailures = array_values(array_filter($failed, static fn (array $f): bool => ($f['required'] ?? false) === true));
        return $this->raw(
            $requiredFailures !== [] ? 'fail' : ($failed === [] ? 'pass' : 'warn'),
            $failed === [] ? count($loaded) . ' service class(es) loaded.' : count($loaded) . ' service class(es) loaded; ' . count($failed) . ' failed.',
            ['loaded' => $loaded, 'failed' => $failed, 'required_failure_count' => count($requiredFailures), 'skipped_instantiation' => $skipped]
        );
    }

    private function checkDatabase(): array
    {
        $database = $this->databaseConfig();
        if ($database === []) {
            return $this->raw('skip', 'No database configuration was supplied.');
        }
        $pdo = $this->connect($database);
        return $this->raw('pass', 'Database connection succeeded.', [
            'driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            'server_version' => $this->pdoAttribute($pdo, PDO::ATTR_SERVER_VERSION),
            'client_version' => $this->pdoAttribute($pdo, PDO::ATTR_CLIENT_VERSION),
        ]);
    }

    private function checkDatabaseSchema(): array
    {
        $database = $this->databaseConfig();
        $required = $this->stringList($database['required_tables'] ?? $this->mergedConfig()['required_tables'] ?? []);
        if ($database === [] || $required === []) {
            return $this->raw('skip', 'No required database tables were configured.', ['required_tables' => $required]);
        }
        $pdo = $this->connect($database);
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $present = [];
        $missing = [];
        foreach ($required as $table) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                $missing[] = ['table' => $table, 'reason' => 'Unsafe table identifier.'];
                continue;
            }
            $identifier = $driver === 'mysql' ? '`' . $table . '`' : '"' . $table . '"';
            try {
                if ($pdo->query('SELECT 1 FROM ' . $identifier . ' WHERE 1 = 0') === false) {
                    throw new RuntimeException('Schema probe returned false.');
                }
                $present[] = $table;
            } catch (Throwable $e) {
                $missing[] = ['table' => $table, 'reason' => $e->getMessage()];
            }
        }
        return $this->raw($missing === [] ? 'pass' : 'fail', $missing === [] ? 'All configured database tables are available.' : count($missing) . ' configured table(s) are unavailable.', compact('driver', 'present', 'missing'));
    }

    private function checkGraph(): array
    {
        if (!trait_exists('GraphUtilities', false)) {
            $this->requirePath(dirname(__DIR__) . '/core/GraphUtilities.php');
        }
        $uses = class_uses($this) ?: [];
        $attached = in_array('GraphUtilities', array_values($uses), true);
        return $this->raw(trait_exists('GraphUtilities', false) && $attached ? 'pass' : 'fail', trait_exists('GraphUtilities', false) && $attached ? 'Graph utilities are loaded and attached.' : 'Graph utilities are unavailable or unattached.', [
            'trait_loaded' => trait_exists('GraphUtilities', false),
            'trait_attached' => $attached,
            'traits_used' => array_values($uses),
        ]);
    }

    private function checkDomainService(string $class): array
    {
        $definition = $this->services[$class] ?? null;
        if (!is_array($definition)) {
            return $this->raw('fail', $class . ' is not registered.');
        }
        if (!class_exists($class, false)) {
            $this->requirePath($definition['path']);
        }
        if (!class_exists($class, false)) {
            return $this->raw('fail', $class . ' did not load.', ['path' => $definition['path']]);
        }
        $reflection = new ReflectionClass($class);
        return $this->raw('pass', $class . ' is available.', ['class' => $class, 'path' => $definition['path'], 'instantiable' => $reflection->isInstantiable()]);
    }

    private function checkRoutes(): array
    {
        $routes = $this->mergedConfig()['routes'] ?? [];
        if (!is_array($routes) || $routes === []) {
            return $this->raw('skip', 'No route inventory was configured.');
        }
        $available = [];
        $missing = [];
        foreach ($routes as $name => $definition) {
            if (is_string($definition)) {
                $definition = ['path' => $definition, 'required' => true];
            }
            if (!is_array($definition)) {
                continue;
            }
            $path = $this->path((string)($definition['path'] ?? ''));
            $required = (bool)($definition['required'] ?? true);
            if ($path !== '' && is_file($path) && is_readable($path)) {
                $available[] = compact('name', 'path', 'required');
            } else {
                $missing[] = compact('name', 'path', 'required');
            }
        }
        $requiredMissing = array_filter($missing, static fn (array $r): bool => ($r['required'] ?? false) === true);
        return $this->raw($requiredMissing !== [] ? 'fail' : ($missing === [] ? 'pass' : 'warn'), $missing === [] ? 'All configured route files are available.' : count($missing) . ' configured route file(s) are unavailable.', compact('available', 'missing'));
    }

    private function checkSecurity(): array
    {
        $config = $this->mergedConfig();
        $environment = strtolower(trim((string)($config['environment'] ?? $config['app_env'] ?? 'development')));
        $warnings = [];
        if (in_array($environment, ['production', 'prod'], true) && filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOL)) {
            $warnings[] = 'display_errors is enabled in production.';
        }
        if (PHP_SAPI !== 'cli' && (string)ini_get('session.cookie_httponly') !== '1') {
            $warnings[] = 'session.cookie_httponly is disabled.';
        }
        if (PHP_SAPI !== 'cli' && in_array($environment, ['production', 'prod'], true) && (string)ini_get('session.cookie_secure') !== '1') {
            $warnings[] = 'session.cookie_secure is disabled in production.';
        }
        return $this->raw($warnings === [] ? 'pass' : 'warn', $warnings === [] ? 'Security configuration has no detected warnings.' : implode(' ', $warnings), [
            'environment' => $environment,
            'display_errors' => (string)ini_get('display_errors'),
            'log_errors' => (string)ini_get('log_errors'),
            'session_cookie_httponly' => (string)ini_get('session.cookie_httponly'),
            'session_cookie_secure' => (string)ini_get('session.cookie_secure'),
            'session_cookie_samesite' => (string)ini_get('session.cookie_samesite'),
            'open_basedir' => (string)ini_get('open_basedir'),
        ]);
    }

    private function execute(array $check, array $options): array
    {
        $handler = $check['handler'] ?? null;
        if (!is_callable($handler)) {
            return $this->result($check, 'error', 'Health check handler is not callable.');
        }
        $started = microtime(true);
        $raw = $handler($this, $check, $options);
        $duration = round((microtime(true) - $started) * 1000, 3);

        if (is_bool($raw)) {
            return $this->result($check, $raw ? 'pass' : 'fail', $raw ? 'Check passed.' : 'Check failed.', [], $duration);
        }
        if (is_string($raw)) {
            $status = $this->status($raw);
            return $this->result($check, $status, ucfirst($status) . '.', [], $duration);
        }
        if (!is_array($raw)) {
            return $this->result($check, 'error', 'Health check returned an unsupported result.', ['returned_type' => get_debug_type($raw)], $duration);
        }
        return $this->result(
            $check,
            (string)($raw['status'] ?? 'error'),
            (string)($raw['message'] ?? 'Check completed.'),
            is_array($raw['details'] ?? null) ? $raw['details'] : [],
            $duration,
            is_array($raw['evidence'] ?? null) ? $raw['evidence'] : []
        );
    }

    private function result(array $check, string $status, string $message, array $details = [], float $duration = 0.0, array $evidence = []): array
    {
        $status = $this->status($status);
        return [
            'result_id' => 'health_result_' . substr(hash('sha256', ($check['check_id'] ?? 'check') . '|' . $status . '|' . $message . '|' . microtime(true)), 0, 24),
            'check_id' => $check['check_id'] ?? null,
            'title' => $check['title'] ?? null,
            'description' => $check['description'] ?? '',
            'category' => $check['category'] ?? 'custom',
            'required' => (bool)($check['required'] ?? true),
            'optional' => (bool)($check['optional'] ?? false),
            'status' => $status,
            'passed' => $status === 'pass',
            'message' => trim($message),
            'details' => $details,
            'evidence' => $evidence,
            'duration_ms' => round(max(0.0, $duration), 3),
            'checked_at' => gmdate('c'),
        ];
    }

    private function buildReport(float $duration, array $options): array
    {
        $summary = $this->summary($this->results);
        return [
            'health_report_id' => 'health_report_' . gmdate('Ymd_His') . '_' . substr(hash('sha256', microtime(true) . '|' . random_int(1, PHP_INT_MAX)), 0, 12),
            'entity_id' => null,
            'entity_type' => 'health_report',
            'generated_at' => gmdate('c'),
            'status' => $this->overallStatus($this->results),
            'healthy' => $summary['fail'] === 0 && $summary['error'] === 0,
            'summary' => $summary,
            'category_summary' => $this->categorySummary($this->results),
            'deployment_readiness' => $this->readiness($this->results),
            'results' => $this->results,
            'registered_check_count' => count($this->checks),
            'executed_check_count' => count($this->results),
            'duration_ms' => round(max(0.0, $duration), 3),
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'os' => PHP_OS_FAMILY,
                'architecture' => PHP_INT_SIZE * 8,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'timezone' => date_default_timezone_get(),
            ],
            'options' => $options,
            'checksum' => '',
        ];
    }

    private function summary(array $results): array
    {
        $summary = ['total' => 0, 'pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0, 'error' => 0, 'required_failures' => 0];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $status = $this->status((string)($result['status'] ?? 'error'));
            $summary['total']++;
            $summary[$status]++;
            if (($result['required'] ?? true) && in_array($status, ['fail', 'error'], true)) {
                $summary['required_failures']++;
            }
        }
        return $summary;
    }

    private function categorySummary(array $results): array
    {
        $summary = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $category = $this->category((string)($result['category'] ?? 'custom'));
            $summary[$category] ??= ['category' => $category, 'total' => 0, 'pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0, 'error' => 0];
            $status = $this->status((string)($result['status'] ?? 'error'));
            $summary[$category]['total']++;
            $summary[$category][$status]++;
        }
        ksort($summary);
        return $summary;
    }

    private function readiness(array $results): array
    {
        $failures = [];
        $incomplete = [];
        $warnings = [];
        $required = 0;
        $passes = 0;
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $status = $this->status((string)($result['status'] ?? 'error'));
            if (($result['required'] ?? true) === true) {
                $required++;
                if ($status === 'pass') {
                    $passes++;
                }
                if (in_array($status, ['fail', 'error'], true)) {
                    $failures[] = ['check_id' => $result['check_id'] ?? null, 'title' => $result['title'] ?? null, 'status' => $status, 'message' => $result['message'] ?? ''];
                } elseif ($status === 'skip') {
                    $incomplete[] = ['check_id' => $result['check_id'] ?? null, 'title' => $result['title'] ?? null, 'status' => $status, 'message' => $result['message'] ?? ''];
                }
            }
            if ($status === 'warn') {
                $warnings[] = ['check_id' => $result['check_id'] ?? null, 'title' => $result['title'] ?? null, 'message' => $result['message'] ?? ''];
            }
        }
        return [
            'ready' => $failures === [] && $incomplete === [],
            'disposition' => $failures !== [] ? 'blocked' : ($incomplete !== [] ? 'incomplete' : ($warnings === [] ? 'promotable' : 'promotable_with_warnings')),
            'score' => $required > 0 ? round(($passes / $required) * 100, 2) : 0.0,
            'required_check_count' => $required,
            'required_pass_count' => $passes,
            'required_failure_count' => count($failures),
            'required_incomplete_count' => count($incomplete),
            'warning_count' => count($warnings),
            'required_failures' => $failures,
            'required_incomplete' => $incomplete,
            'warnings' => $warnings,
        ];
    }

    private function raw(string $status, string $message, array $details = [], array $evidence = []): array
    {
        return compact('status', 'message', 'details', 'evidence');
    }

    private function inventoryRaw(array $loaded, array $failed, string $label): array
    {
        $requiredFailures = array_filter($failed, static fn (array $f): bool => ($f['required'] ?? false) === true);
        return $this->raw(
            $requiredFailures !== [] ? 'fail' : ($failed === [] ? 'pass' : 'warn'),
            $failed === [] ? 'All registered ' . $label . ' entries loaded.' : count($failed) . ' ' . $label . ' entry or entries failed to load.',
            compact('loaded', 'failed')
        );
    }

    private function categoryEnabled(string $category, array $options): bool
    {
        return match ($category) {
            'environment' => (bool)($options['environment'] ?? true),
            'configuration' => (bool)($options['configuration'] ?? true),
            'filesystem', 'core', 'schema', 'route', 'security' => (bool)($options['filesystem'] ?? true),
            'service', 'graph', 'workflow', 'ledger', 'audit', 'deployment' => (bool)($options['services'] ?? true),
            'database' => (bool)($options['database'] ?? true),
            default => true,
        };
    }

    private function orderedServices(): array
    {
        $preferred = [
            'ValidationService', 'TranslationService', 'ProvenanceService', 'VersionService', 'EventService',
            'RelationshipService', 'GraphTraversalService', 'PathService', 'GraphAnalyticsService', 'GraphRepairService',
            'GraphSearchService', 'RelationshipSuggestionService', 'InferenceService', 'ConsistencyService', 'RuleEngineService',
            'SimilarityService', 'RecommendationService', 'KnowledgeGraphService', 'GraphImportService', 'GraphExportService',
            'AssetService', 'IdeaService', 'DecisionService', 'WorkflowService', 'AuditService', 'LedgerService', 'DeploymentService',
        ];
        $ordered = [];
        $used = [];
        foreach ($preferred as $class) {
            if (isset($this->services[$class])) {
                $ordered[] = $this->services[$class];
                $used[$class] = true;
            }
        }
        foreach ($this->services as $class => $definition) {
            if (!isset($used[$class])) {
                $ordered[] = $definition;
            }
        }
        return $ordered;
    }

    private function requirePath(string $path): void
    {
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('Required source file was not found: ' . $path);
        }
        if (!is_readable($path)) {
            throw new RuntimeException('Required source file is unreadable: ' . $path);
        }
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            require_once $path;
        } finally {
            restore_error_handler();
        }
    }

    private function projectConfig(): array
    {
        if ($this->projectConfig !== null) {
            return $this->projectConfig;
        }
        $path = dirname(dirname(__DIR__)) . '/config.php';
        if (!is_file($path) || !is_readable($path)) {
            return $this->projectConfig = [];
        }
        try {
            $loaded = (static fn (string $file): mixed => require $file)($path);
            return $this->projectConfig = is_array($loaded) ? $loaded : [];
        } catch (Throwable) {
            return $this->projectConfig = [];
        }
    }

    private function mergedConfig(): array
    {
        return array_replace_recursive($this->projectConfig(), $this->healthConfig);
    }

    private function databaseConfig(): array
    {
        $config = $this->mergedConfig();
        $database = $config['db'] ?? $config['database'] ?? [];
        return is_array($database) ? $database : [];
    }

    private function connect(array $database): PDO
    {
        $dsn = trim((string)($database['dsn'] ?? ''));
        if ($dsn === '') {
            $host = trim((string)($database['host'] ?? 'localhost'));
            $name = trim((string)($database['name'] ?? $database['database'] ?? ''));
            $charset = trim((string)($database['charset'] ?? 'utf8mb4'));
            $port = (int)($database['port'] ?? 3306);
            if ($name === '') {
                throw new RuntimeException('Database DSN or database name is required.');
            }
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
        }
        $user = (string)($database['user'] ?? $database['username'] ?? '');
        $pass = (string)($database['pass'] ?? $database['password'] ?? '');
        $options = is_array($database['options'] ?? null) ? $database['options'] : [];
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $options[PDO::ATTR_DEFAULT_FETCH_MODE] = PDO::FETCH_ASSOC;
        return new PDO($dsn, $user, $pass, $options);
    }

    private function pdoAttribute(PDO $pdo, int $attribute): mixed
    {
        try {
            return $pdo->getAttribute($attribute);
        } catch (Throwable) {
            return null;
        }
    }

    private function status(string $status): string
    {
        $status = $this->machineKey($status);
        $status = ['passed' => 'pass', 'warning' => 'warn', 'failed' => 'fail', 'skipped' => 'skip'][$status] ?? $status;
        return in_array($status, $this->statuses, true) ? $status : 'error';
    }

    private function category(string $category): string
    {
        $category = $this->machineKey($category);
        return in_array($category, $this->categories, true) ? $category : 'custom';
    }

    private function machineKey(mixed $value): string
    {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        return trim($value, '_');
    }

    private function machineList(mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];
        $output = [];
        foreach ($values as $value) {
            $key = $this->machineKey($value);
            if ($key !== '') {
                $output[] = $key;
            }
        }
        return array_values(array_unique($output));
    }

    private function stringList(mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];
        $output = [];
        foreach ($values as $value) {
            if (is_scalar($value) || $value instanceof Stringable) {
                $value = trim((string)$value);
                if ($value !== '') {
                    $output[] = $value;
                }
            }
        }
        return array_values(array_unique($output));
    }

    private function path(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function checksum(array $report): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) {
                return is_object($value) ? get_class($value) : (is_resource($value) ? get_resource_type($value) : $value);
            }
            $output = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && in_array($key, ['checksum', 'generated_at', 'checked_at', 'duration_ms', 'runtime'], true)) {
                    continue;
                }
                $output[$key] = $normalize($item);
            }
            if (array_keys($output) !== range(0, count($output) - 1)) {
                ksort($output);
            }
            return $output;
        };
        $json = json_encode($normalize($report), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new RuntimeException('Health report checksum encoding failed.');
        }
        return hash('sha256', $json);
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float)$value;
        return match ($unit) {
            'g' => (int)round($number * 1073741824),
            'm' => (int)round($number * 1048576),
            'k' => (int)round($number * 1024),
            default => (int)$number,
        };
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
