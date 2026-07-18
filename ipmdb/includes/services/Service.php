<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/Service.php
|--------------------------------------------------------------------------
| IPMdb Base Service
|--------------------------------------------------------------------------
|
| Shared foundation for IPMdb service classes.
|
| Services contain application behaviour.
| Repositories handle persistence.
| Entities hold validated data.
| Schemas define structure.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

abstract class Service
{
    protected string $serviceName;

    protected array $config = [];

    protected array $context = [];

    protected array $messages = [];

    protected array $errors = [];

    public function __construct(
        array $config = [],
        array $context = []
    ) {
        $this->serviceName = static::class;
        $this->config = $config;
        $this->context = $context;
    }

    public function name(): string
    {
        return $this->serviceName;
    }

    public function config(
        ?string $key = null,
        mixed $default = null
    ): mixed {
        if ($key === null) {
            return $this->config;
        }

        return $this->config[$key] ?? $default;
    }

    public function setConfig(
        string $key,
        mixed $value
    ): static {
        $key = trim($key);

        if ($key === '') {
            throw new InvalidArgumentException(
                'Configuration key is required.'
            );
        }

        $this->config[$key] = $value;

        return $this;
    }

    public function mergeConfig(array $config): static
    {
        $this->config = array_replace_recursive(
            $this->config,
            $config
        );

        return $this;
    }

    public function context(
        ?string $key = null,
        mixed $default = null
    ): mixed {
        if ($key === null) {
            return $this->context;
        }

        return $this->context[$key] ?? $default;
    }

    public function setContext(
        string $key,
        mixed $value
    ): static {
        $key = trim($key);

        if ($key === '') {
            throw new InvalidArgumentException(
                'Context key is required.'
            );
        }

        $this->context[$key] = $value;

        return $this;
    }

    public function mergeContext(array $context): static
    {
        $this->context = array_replace_recursive(
            $this->context,
            $context
        );

        return $this;
    }

    public function messages(): array
    {
        return $this->messages;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function hasMessages(): bool
    {
        return $this->messages !== [];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function succeeded(): bool
    {
        return !$this->hasErrors();
    }

    public function failed(): bool
    {
        return $this->hasErrors();
    }

    public function clearMessages(): static
    {
        $this->messages = [];

        return $this;
    }

    public function clearErrors(): static
    {
        $this->errors = [];

        return $this;
    }

    public function reset(): static
    {
        $this->messages = [];
        $this->errors = [];

        return $this;
    }

    protected function addMessage(
        string $message,
        array $context = []
    ): void {
        $message = trim($message);

        if ($message === '') {
            return;
        }

        $this->messages[] = [
            'message' => $message,
            'context' => $context,
            'created_at' => gmdate('c'),
        ];
    }

    protected function addError(
        string $message,
        array $context = []
    ): void {
        $message = trim($message);

        if ($message === '') {
            return;
        }

        $this->errors[] = [
            'message' => $message,
            'context' => $context,
            'created_at' => gmdate('c'),
        ];
    }

    protected function requireContext(
        string $key
    ): mixed {
        $key = trim($key);

        if ($key === '') {
            throw new InvalidArgumentException(
                'Context key is required.'
            );
        }

        if (!array_key_exists($key, $this->context)) {
            throw new RuntimeException(
                sprintf(
                    'Required service context "%s" is missing.',
                    $key
                )
            );
        }

        return $this->context[$key];
    }

    protected function requireConfig(
        string $key
    ): mixed {
        $key = trim($key);

        if ($key === '') {
            throw new InvalidArgumentException(
                'Configuration key is required.'
            );
        }

        if (!array_key_exists($key, $this->config)) {
            throw new RuntimeException(
                sprintf(
                    'Required service configuration "%s" is missing.',
                    $key
                )
            );
        }

        return $this->config[$key];
    }

    protected function normalizeString(
        mixed $value
    ): string {
        return trim((string)$value);
    }

    protected function normalizeKey(
        string $value
    ): string {
        $value = strtolower(trim($value));

        return preg_replace(
            '/[^a-z0-9_]+/',
            '_',
            $value
        ) ?? '';
    }

    protected function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public function diagnostics(): array
    {
        return [
            'service' => $this->serviceName,
            'config_keys' => array_keys($this->config),
            'context_keys' => array_keys($this->context),
            'message_count' => count($this->messages),
            'error_count' => count($this->errors),
            'status' => $this->succeeded()
                ? 'ready'
                : 'error',
        ];
    }
}