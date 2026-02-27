<?php

declare(strict_types=1);

namespace AIVory\Monitor;

use AIVory\Monitor\Breakpoint\BreakpointManager;
use AIVory\Monitor\Capture\ExceptionCapture;
use AIVory\Monitor\Transport\BackendConnection;

/**
 * AIVory Monitor PHP Agent
 *
 * Usage:
 * ```php
 * // At the beginning of your application
 * use AIVory\Monitor\Agent;
 *
 * Agent::init([
 *     'apiKey' => 'your-api-key',
 *     'environment' => 'production'
 * ]);
 * ```
 *
 * Or using environment variables:
 * ```php
 * Agent::init();  // Uses AIVORY_* environment variables
 * ```
 */
class Agent
{
    private static ?Agent $instance = null;
    private static bool $initialized = false;

    private Config $config;
    private BackendConnection $connection;
    private ExceptionCapture $exceptionCapture;
    private ?BreakpointManager $breakpointManager = null;
    /** @var array<string, mixed> */
    private array $customContext = [];
    /** @var array<string, mixed>|null */
    private ?array $user = null;

    private function __construct(Config $config)
    {
        $this->config = $config;
        $this->connection = new BackendConnection($config);
        $this->exceptionCapture = new ExceptionCapture($config, $this->connection);
    }

    /**
     * Initializes the AIVory Monitor agent.
     *
     * @param array<string, mixed> $options
     */
    public static function init(array $options = []): void
    {
        if (self::$initialized) {
            echo "[AIVory Monitor] Agent already initialized\n";
            return;
        }

        // Build config from options or environment
        if (empty($options)) {
            $config = Config::fromEnvironment();
        } else {
            $config = new Config(
                apiKey: $options['apiKey'] ?? getenv('AIVORY_API_KEY') ?: '',
                backendUrl: $options['backendUrl'] ?? getenv('AIVORY_BACKEND_URL') ?: 'wss://api.aivory.net/monitor/agent',
                environment: $options['environment'] ?? getenv('AIVORY_ENVIRONMENT') ?: 'production',
                applicationName: $options['applicationName'] ?? getenv('AIVORY_APP_NAME') ?: null,
                samplingRate: (float)($options['samplingRate'] ?? getenv('AIVORY_SAMPLING_RATE') ?: 1.0),
                maxVariableDepth: (int)($options['maxVariableDepth'] ?? getenv('AIVORY_MAX_DEPTH') ?: 3),
                debug: (bool)($options['debug'] ?? (strtolower(getenv('AIVORY_DEBUG') ?: 'false') === 'true')),
                enableBreakpoints: (bool)($options['enableBreakpoints'] ?? (strtolower(getenv('AIVORY_ENABLE_BREAKPOINTS') ?: 'true') === 'true'))
            );
        }

        try {
            $config->validate();
        } catch (\InvalidArgumentException $e) {
            echo "[AIVory Monitor] Configuration error: " . $e->getMessage() . "\n";
            return;
        }

        echo "[AIVory Monitor] Initializing agent v1.0.0\n";
        echo "[AIVory Monitor] Environment: {$config->environment}\n";

        self::$instance = new self($config);

        // Install exception handlers
        self::$instance->exceptionCapture->install();

        // Initialize breakpoint support
        if ($config->enableBreakpoints) {
            self::$instance->breakpointManager = new BreakpointManager($config, self::$instance->connection);
            self::$instance->connection->on('set_breakpoint', function (array $payload) {
                self::$instance->breakpointManager?->handleCommand('set', $payload);
            });
            self::$instance->connection->on('remove_breakpoint', function (array $payload) {
                self::$instance->breakpointManager?->handleCommand('remove', $payload);
            });
        }

        // Connect to backend
        self::$instance->connection->connect();

        // Register shutdown handler
        register_shutdown_function([self::class, 'shutdown']);

        self::$initialized = true;
        echo "[AIVory Monitor] Agent initialized successfully\n";
    }

    /**
     * Manually captures an exception.
     *
     * @param array<string, mixed>|null $context
     */
    public static function captureException(\Throwable $exception, ?array $context = null): void
    {
        if (!self::$initialized || self::$instance === null) {
            echo "[AIVory Monitor] Agent not initialized\n";
            return;
        }

        $mergedContext = array_merge(
            self::$instance->customContext,
            $context ?? []
        );

        if (self::$instance->user !== null) {
            $mergedContext['user'] = self::$instance->user;
        }

        self::$instance->exceptionCapture->capture($exception, $mergedContext);
    }

    /**
     * Sets custom context that will be sent with all captures.
     *
     * @param array<string, mixed> $context
     */
    public static function setContext(array $context): void
    {
        if (!self::$initialized || self::$instance === null) {
            echo "[AIVory Monitor] Agent not initialized\n";
            return;
        }

        self::$instance->customContext = array_merge(
            self::$instance->customContext,
            $context
        );
    }

    /**
     * Sets the current user for context.
     *
     * @param array{id?: string, email?: string, username?: string} $user
     */
    public static function setUser(array $user): void
    {
        if (!self::$initialized || self::$instance === null) {
            echo "[AIVory Monitor] Agent not initialized\n";
            return;
        }

        self::$instance->user = $user;
    }

    /**
     * Sends a heartbeat to keep the connection alive.
     * Call this periodically in long-running scripts.
     */
    public static function heartbeat(): void
    {
        if (!self::$initialized || self::$instance === null) {
            return;
        }

        self::$instance->connection->sendHeartbeat();
    }

    /**
     * Processes incoming WebSocket messages.
     * Call this periodically in long-running scripts.
     */
    public static function processMessages(): void
    {
        if (!self::$initialized || self::$instance === null) {
            return;
        }

        self::$instance->connection->processMessages();
    }

    /**
     * Triggers a non-breaking breakpoint capture.
     * Only captures if the breakpoint ID has been registered by the backend.
     * Place this call at locations where you want to capture context.
     */
    public static function breakpoint(string $id): void
    {
        if (!self::$initialized || self::$instance === null) {
            return;
        }

        self::$instance->breakpointManager?->hit($id);
    }

    /**
     * Shuts down the agent gracefully.
     */
    public static function shutdown(): void
    {
        if (!self::$initialized || self::$instance === null) {
            return;
        }

        if (self::$instance->config->debug) {
            echo "[AIVory Monitor] Shutting down agent\n";
        }

        self::$instance->exceptionCapture->uninstall();
        self::$instance->connection->disconnect();

        self::$initialized = false;
        self::$instance = null;
    }

    /**
     * Checks if the agent is initialized.
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * Checks if connected to the backend.
     */
    public static function isConnected(): bool
    {
        if (!self::$initialized || self::$instance === null) {
            return false;
        }

        return self::$instance->connection->isConnected();
    }

    /**
     * Gets the current config (for testing).
     */
    public static function getConfig(): ?Config
    {
        return self::$instance?->config;
    }
}

/**
 * Laravel integration via service provider.
 *
 * Add to config/app.php providers:
 * AIVory\Monitor\Laravel\AIVoryServiceProvider::class
 */
namespace AIVory\Monitor\Laravel;

use AIVory\Monitor\Agent;

if (class_exists('Illuminate\Support\ServiceProvider')) {
    class AIVoryServiceProvider extends \Illuminate\Support\ServiceProvider
    {
        public function boot(): void
        {
            Agent::init();
        }
    }
}

/**
 * Symfony integration via bundle.
 */
namespace AIVory\Monitor\Symfony;

use AIVory\Monitor\Agent;

if (class_exists('Symfony\Component\HttpKernel\Bundle\Bundle')) {
    class AIVoryMonitorBundle extends \Symfony\Component\HttpKernel\Bundle\Bundle
    {
        public function boot(): void
        {
            Agent::init();
        }
    }
}
