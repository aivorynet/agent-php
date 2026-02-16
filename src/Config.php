<?php

declare(strict_types=1);

namespace AIVory\Monitor;

/**
 * Configuration for the AIVory Monitor agent.
 */
class Config
{
    public string $apiKey;
    public string $backendUrl;
    public string $environment;
    public ?string $applicationName;
    public float $samplingRate;
    public int $maxVariableDepth;
    public bool $debug;
    public bool $enableBreakpoints;
    public int $heartbeatIntervalMs;
    public int $maxReconnectAttempts;

    public function __construct(
        string $apiKey = '',
        string $backendUrl = 'wss://api.aivory.net/ws/monitor/agent',
        string $environment = 'production',
        ?string $applicationName = null,
        float $samplingRate = 1.0,
        int $maxVariableDepth = 10,
        bool $debug = false,
        bool $enableBreakpoints = true,
        int $heartbeatIntervalMs = 30000,
        int $maxReconnectAttempts = 10
    ) {
        $this->apiKey = $apiKey;
        $this->backendUrl = $backendUrl;
        $this->environment = $environment;
        $this->applicationName = $applicationName;
        $this->samplingRate = $samplingRate;
        $this->maxVariableDepth = $maxVariableDepth;
        $this->debug = $debug;
        $this->enableBreakpoints = $enableBreakpoints;
        $this->heartbeatIntervalMs = $heartbeatIntervalMs;
        $this->maxReconnectAttempts = $maxReconnectAttempts;
    }

    /**
     * Creates configuration from environment variables.
     */
    public static function fromEnvironment(): self
    {
        return new self(
            apiKey: getenv('AIVORY_API_KEY') ?: '',
            backendUrl: getenv('AIVORY_BACKEND_URL') ?: 'wss://api.aivory.net/ws/monitor/agent',
            environment: getenv('AIVORY_ENVIRONMENT') ?: 'production',
            applicationName: getenv('AIVORY_APP_NAME') ?: null,
            samplingRate: (float)(getenv('AIVORY_SAMPLING_RATE') ?: 1.0),
            maxVariableDepth: (int)(getenv('AIVORY_MAX_DEPTH') ?: 10),
            debug: strtolower(getenv('AIVORY_DEBUG') ?: 'false') === 'true',
            enableBreakpoints: strtolower(getenv('AIVORY_ENABLE_BREAKPOINTS') ?: 'true') === 'true'
        );
    }

    /**
     * Validates the configuration.
     *
     * @throws \InvalidArgumentException If configuration is invalid.
     */
    public function validate(): void
    {
        if (empty($this->apiKey)) {
            throw new \InvalidArgumentException('AIVORY_API_KEY environment variable is required');
        }

        if ($this->samplingRate < 0 || $this->samplingRate > 1) {
            throw new \InvalidArgumentException('Sampling rate must be between 0.0 and 1.0');
        }

        if ($this->maxVariableDepth < 0 || $this->maxVariableDepth > 10) {
            throw new \InvalidArgumentException('Max variable depth must be between 0 and 10');
        }
    }
}
