<?php

declare(strict_types=1);

namespace AIVory\Monitor\Breakpoint;

use AIVory\Monitor\Config;
use AIVory\Monitor\Transport\BackendConnection;

/**
 * Manages non-breaking breakpoints for the PHP agent.
 *
 * Provides a manual API: developers place Agent::breakpoint('id') calls
 * at locations of interest, and the backend enables/disables them remotely.
 */
class BreakpointManager
{
    private const MAX_CAPTURES_PER_SECOND = 50;

    private Config $config;
    private BackendConnection $connection;
    /** @var array<string, BreakpointInfo> */
    private array $breakpoints = [];
    private int $captureCount = 0;
    private float $captureWindowStart;

    public function __construct(Config $config, BackendConnection $connection)
    {
        $this->config = $config;
        $this->connection = $connection;
        $this->captureWindowStart = microtime(true);
    }

    /**
     * Sets a breakpoint.
     */
    public function setBreakpoint(
        string $id,
        string $filePath,
        int $lineNumber,
        ?string $condition = null,
        int $maxHits = 1
    ): void {
        $this->breakpoints[$id] = new BreakpointInfo($id, $filePath, $lineNumber, $condition, $maxHits);

        if ($this->config->debug) {
            echo "[AIVory Monitor] Breakpoint set: {$id} at {$filePath}:{$lineNumber}\n";
        }
    }

    /**
     * Removes a breakpoint.
     */
    public function removeBreakpoint(string $id): void
    {
        unset($this->breakpoints[$id]);

        if ($this->config->debug) {
            echo "[AIVory Monitor] Breakpoint removed: {$id}\n";
        }
    }

    /**
     * Called from user code to trigger a breakpoint capture.
     * Only captures if the breakpoint ID is registered and active.
     */
    public function hit(string $id): void
    {
        if (!isset($this->breakpoints[$id])) {
            return;
        }

        $bp = $this->breakpoints[$id];

        if ($bp->hitCount >= $bp->maxHits) {
            return;
        }

        if (!$this->rateLimitOk()) {
            return;
        }

        $bp->hitCount++;

        if ($this->config->debug) {
            echo "[AIVory Monitor] Breakpoint hit: {$id}\n";
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 20);
        $stackTrace = $this->buildStackTrace($trace);

        // Capture local variables from the caller frame
        $localVariables = [];
        if (isset($trace[1]) && isset($trace[1]['args'])) {
            $localVariables = $this->captureArgs($trace[1]);
        }

        $this->connection->sendBreakpointHit($bp->id, [
            'captured_at' => (int)(microtime(true) * 1000),
            'file_path' => $bp->filePath,
            'line_number' => $bp->lineNumber,
            'stack_trace' => $stackTrace,
            'local_variables' => $localVariables,
            'hit_count' => $bp->hitCount,
        ]);
    }

    /**
     * Handles a breakpoint command from the backend.
     */
    public function handleCommand(string $command, array $payload): void
    {
        switch ($command) {
            case 'set':
                $this->setBreakpoint(
                    $payload['id'] ?? '',
                    $payload['file_path'] ?? $payload['file'] ?? '',
                    (int)($payload['line_number'] ?? $payload['line'] ?? 0),
                    $payload['condition'] ?? null,
                    (int)($payload['max_hits'] ?? 1)
                );
                break;
            case 'remove':
                $this->removeBreakpoint($payload['id'] ?? '');
                break;
        }
    }

    private function rateLimitOk(): bool
    {
        $now = microtime(true);
        if ($now - $this->captureWindowStart >= 1.0) {
            $this->captureCount = 0;
            $this->captureWindowStart = $now;
        }

        if ($this->captureCount >= self::MAX_CAPTURES_PER_SECOND) {
            if ($this->config->debug) {
                echo "[AIVory Monitor] Rate limit reached, skipping capture\n";
            }
            return false;
        }

        $this->captureCount++;
        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $trace
     * @return array<int, array<string, mixed>>
     */
    private function buildStackTrace(array $trace): array
    {
        $frames = [];

        // Skip first frame (this method) and second frame (hit method)
        for ($i = 2; $i < count($trace); $i++) {
            $frame = $trace[$i];
            $filePath = $frame['file'] ?? null;
            $fileName = $filePath !== null ? basename($filePath) : null;

            $frames[] = [
                'method_name' => $frame['function'] ?? null,
                'class_name' => $frame['class'] ?? null,
                'file_path' => $filePath,
                'file_name' => $fileName,
                'line_number' => $frame['line'] ?? 0,
                'is_native' => $filePath === null || str_starts_with($filePath, 'php://'),
            ];
        }

        return $frames;
    }

    /**
     * Captures function arguments from a backtrace frame.
     *
     * @param array<string, mixed> $frame
     * @return array<string, array<string, mixed>>
     */
    private function captureArgs(array $frame): array
    {
        $variables = [];

        if (!isset($frame['args'])) {
            return $variables;
        }

        foreach ($frame['args'] as $i => $value) {
            $name = "arg{$i}";
            $variables[$name] = $this->captureVariable($name, $value, 0);
        }

        return $variables;
    }

    /**
     * Captures a variable value recursively.
     *
     * @return array<string, mixed>
     */
    private function captureVariable(string $name, mixed $value, int $depth): array
    {
        $result = ['name' => $name, 'type' => get_debug_type($value)];

        if ($depth > $this->config->maxVariableDepth) {
            $result['value'] = '<max depth exceeded>';
            $result['is_truncated'] = true;
            return $result;
        }

        if ($value === null) {
            $result['value'] = 'null';
            $result['is_null'] = true;
        } elseif (is_bool($value)) {
            $result['value'] = $value ? 'true' : 'false';
        } elseif (is_int($value) || is_float($value)) {
            $result['value'] = (string)$value;
        } elseif (is_string($value)) {
            if (strlen($value) > 500) {
                $result['value'] = substr($value, 0, 500);
                $result['is_truncated'] = true;
            } else {
                $result['value'] = $value;
            }
        } elseif (is_array($value)) {
            $count = count($value);
            $result['value'] = "array({$count})";
            if ($depth < $this->config->maxVariableDepth && $count <= 10) {
                $children = [];
                foreach ($value as $k => $v) {
                    $key = (string)$k;
                    $children[$key] = $this->captureVariable($key, $v, $depth + 1);
                }
                $result['children'] = $children;
            }
        } elseif (is_object($value)) {
            $result['value'] = get_class($value);
        } else {
            $result['value'] = get_debug_type($value);
        }

        return $result;
    }
}
