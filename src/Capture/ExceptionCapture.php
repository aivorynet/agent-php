<?php

declare(strict_types=1);

namespace AIVory\Monitor\Capture;

use AIVory\Monitor\Config;
use AIVory\Monitor\Models\ExceptionData;
use AIVory\Monitor\Models\StackFrameData;
use AIVory\Monitor\Models\VariableData;
use AIVory\Monitor\Transport\BackendConnection;

/**
 * Captures exceptions and their context.
 */
class ExceptionCapture
{
    private Config $config;
    private BackendConnection $connection;
    /** @var array<string, bool> */
    private array $capturedFingerprints = [];
    private ?\Throwable $previousExceptionHandler = null;
    private ?callable $previousErrorHandler = null;

    public function __construct(Config $config, BackendConnection $connection)
    {
        $this->config = $config;
        $this->connection = $connection;
    }

    /**
     * Installs exception and error handlers.
     */
    public function install(): void
    {
        // Install exception handler
        $previousHandler = set_exception_handler([$this, 'handleException']);
        if ($previousHandler !== null) {
            $this->previousExceptionHandler = $previousHandler;
        }

        // Install error handler
        $previousErrorHandler = set_error_handler([$this, 'handleError']);
        if ($previousErrorHandler !== null) {
            $this->previousErrorHandler = $previousErrorHandler;
        }

        // Register shutdown function for fatal errors
        register_shutdown_function([$this, 'handleShutdown']);

        if ($this->config->debug) {
            echo "[AIVory Monitor] Exception handlers installed\n";
        }
    }

    /**
     * Uninstalls exception handlers.
     */
    public function uninstall(): void
    {
        restore_exception_handler();
        restore_error_handler();

        if ($this->config->debug) {
            echo "[AIVory Monitor] Exception handlers uninstalled\n";
        }
    }

    /**
     * Manually captures an exception.
     *
     * @param \Throwable $exception
     * @param array<string, mixed>|null $context
     */
    public function capture(\Throwable $exception, ?array $context = null): void
    {
        $this->captureException($exception, 'error', $context);
    }

    /**
     * Exception handler callback.
     */
    public function handleException(\Throwable $exception): void
    {
        $this->captureException($exception, 'critical');

        // Call previous handler if exists
        if ($this->previousExceptionHandler !== null) {
            call_user_func($this->previousExceptionHandler, $exception);
        }
    }

    /**
     * Error handler callback.
     */
    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        // Check sampling rate
        if ($this->config->samplingRate < 1.0 && mt_rand() / mt_getrandmax() > $this->config->samplingRate) {
            return false;
        }

        $severity = $this->errorLevelToSeverity($errno);

        $exceptionData = new ExceptionData();
        $exceptionData->exceptionType = $this->errorLevelToString($errno);
        $exceptionData->message = $errstr;
        $exceptionData->filePath = $errfile;
        $exceptionData->lineNumber = $errline;
        $exceptionData->severity = $severity;
        $exceptionData->runtimeVersion = PHP_VERSION;
        $exceptionData->stackTrace = $this->buildStackFrames(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));

        $this->connection->sendException($exceptionData);

        // Call previous handler if exists
        if ($this->previousErrorHandler !== null) {
            return call_user_func($this->previousErrorHandler, $errno, $errstr, $errfile, $errline);
        }

        return false;
    }

    /**
     * Shutdown handler for fatal errors.
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $exceptionData = new ExceptionData();
            $exceptionData->exceptionType = $this->errorLevelToString($error['type']);
            $exceptionData->message = $error['message'];
            $exceptionData->filePath = $error['file'];
            $exceptionData->lineNumber = $error['line'];
            $exceptionData->severity = 'critical';
            $exceptionData->runtimeVersion = PHP_VERSION;

            $this->connection->sendException($exceptionData);
        }
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function captureException(\Throwable $exception, string $severity, ?array $context = null): void
    {
        try {
            // Compute fingerprint for deduplication
            $fingerprint = $this->computeFingerprint($exception);

            // Skip if already captured
            if (isset($this->capturedFingerprints[$fingerprint])) {
                return;
            }
            $this->capturedFingerprints[$fingerprint] = true;

            // Keep set from growing too large
            if (count($this->capturedFingerprints) > 1000) {
                $this->capturedFingerprints = [];
            }

            $exceptionData = $this->buildExceptionData($exception, $severity, $context);
            $this->connection->sendException($exceptionData);

            if ($this->config->debug) {
                echo "[AIVory Monitor] Captured exception: " . get_class($exception) . "\n";
            }
        } catch (\Throwable $e) {
            if ($this->config->debug) {
                echo "[AIVory Monitor] Error capturing exception: " . $e->getMessage() . "\n";
            }
        }
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function buildExceptionData(\Throwable $exception, string $severity, ?array $context): ExceptionData
    {
        $data = new ExceptionData();
        $data->exceptionType = get_class($exception);
        $data->message = $exception->getMessage();
        $data->filePath = $exception->getFile();
        $data->lineNumber = $exception->getLine();
        $data->severity = $severity;
        $data->runtimeVersion = PHP_VERSION;
        $data->stackTrace = $this->buildStackFrames($exception->getTrace());
        $data->requestContext = $context ?? $this->buildRequestContext();

        // Capture exception properties as local variables
        $data->localVariables = $this->captureExceptionAsVariables($exception);

        // Extract class and method from first frame
        if (!empty($data->stackTrace)) {
            $topFrame = $data->stackTrace[0];
            $data->className = $topFrame->className;
            $data->methodName = $topFrame->methodName;
        }

        return $data;
    }

    /**
     * Captures exception properties and inner exceptions as local variables.
     *
     * @return array<string, VariableData>
     */
    private function captureExceptionAsVariables(\Throwable $exception, int $depth = 0): array
    {
        $variables = [];

        if ($depth > $this->config->maxVariableDepth) {
            return $variables;
        }

        // Capture message
        $variables['message'] = new VariableData();
        $variables['message']->name = 'message';
        $variables['message']->type = 'string';
        $msg = $exception->getMessage();
        if (strlen($msg) > 500) {
            $variables['message']->value = substr($msg, 0, 500);
            $variables['message']->isTruncated = true;
        } else {
            $variables['message']->value = $msg;
        }

        // Capture code
        $variables['code'] = new VariableData();
        $variables['code']->name = 'code';
        $variables['code']->type = is_int($exception->getCode()) ? 'int' : 'string';
        $variables['code']->value = (string) $exception->getCode();

        // Capture file and line
        $variables['file'] = new VariableData();
        $variables['file']->name = 'file';
        $variables['file']->type = 'string';
        $variables['file']->value = $exception->getFile();

        $variables['line'] = new VariableData();
        $variables['line']->name = 'line';
        $variables['line']->type = 'int';
        $variables['line']->value = (string) $exception->getLine();

        // Capture custom exception properties via reflection
        try {
            $reflection = new \ReflectionClass($exception);
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if (in_array($property->getName(), ['message', 'code', 'file', 'line', 'trace', 'previous'])) {
                    continue;
                }

                try {
                    $value = $property->getValue($exception);
                    $varName = 'prop:' . $property->getName();
                    $variables[$varName] = $this->captureVariable($property->getName(), $value, $depth + 1);
                } catch (\Throwable) {
                    // Skip properties that throw
                }
            }
        } catch (\Throwable) {
            // Skip if reflection fails
        }

        // Capture previous exception
        $previous = $exception->getPrevious();
        if ($previous !== null) {
            $innerVars = $this->captureExceptionAsVariables($previous, $depth + 1);

            $variables['previous'] = new VariableData();
            $variables['previous']->name = 'previous';
            $variables['previous']->type = get_class($previous);
            $prevMsg = $previous->getMessage();
            if (strlen($prevMsg) > 200) {
                $variables['previous']->value = substr($prevMsg, 0, 200);
                $variables['previous']->isTruncated = true;
            } else {
                $variables['previous']->value = $prevMsg;
            }
            $variables['previous']->children = $innerVars;
        }

        // In web context, capture relevant superglobals
        if (PHP_SAPI !== 'cli') {
            // Capture GET params (sanitized)
            if (!empty($_GET)) {
                $variables['$_GET'] = new VariableData();
                $variables['$_GET']->name = '$_GET';
                $variables['$_GET']->type = 'array';
                $variables['$_GET']->value = 'Array(' . count($_GET) . ')';
                if (count($_GET) <= 20) {
                    $variables['$_GET']->children = $this->captureVariables($_GET, $depth + 1);
                }
            }

            // Capture POST params (without sensitive data)
            if (!empty($_POST)) {
                $sanitizedPost = $this->sanitizeVariables($_POST);
                $variables['$_POST'] = new VariableData();
                $variables['$_POST']->name = '$_POST';
                $variables['$_POST']->type = 'array';
                $variables['$_POST']->value = 'Array(' . count($_POST) . ')';
                if (count($sanitizedPost) <= 20) {
                    $variables['$_POST']->children = $this->captureVariables($sanitizedPost, $depth + 1);
                }
            }

            // Capture session data (without sensitive data)
            if (isset($_SESSION) && !empty($_SESSION)) {
                $sanitizedSession = $this->sanitizeVariables($_SESSION);
                $variables['$_SESSION'] = new VariableData();
                $variables['$_SESSION']->name = '$_SESSION';
                $variables['$_SESSION']->type = 'array';
                $variables['$_SESSION']->value = 'Array(' . count($_SESSION) . ')';
                if (count($sanitizedSession) <= 10) {
                    $variables['$_SESSION']->children = $this->captureVariables($sanitizedSession, $depth + 1);
                }
            }
        }

        return $variables;
    }

    /**
     * Removes sensitive data from captured variables.
     *
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private function sanitizeVariables(array $vars): array
    {
        $sensitiveKeys = ['password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
                          'auth', 'authorization', 'credit_card', 'creditcard', 'cvv',
                          'ssn', 'private_key', 'privatekey'];

        $result = [];
        foreach ($vars as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSensitive = false;

            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($lowerKey, $sensitiveKey)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = $this->sanitizeVariables($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $trace
     * @return StackFrameData[]
     */
    private function buildStackFrames(array $trace): array
    {
        $frames = [];

        foreach ($trace as $frame) {
            $frameData = new StackFrameData();
            $frameData->className = $frame['class'] ?? null;
            $frameData->methodName = $frame['function'] ?? null;
            $frameData->filePath = $frame['file'] ?? null;
            $frameData->fileName = isset($frame['file']) ? basename($frame['file']) : null;
            $frameData->lineNumber = $frame['line'] ?? 0;
            $frameData->isNative = !isset($frame['file']);

            // Capture arguments if available and depth allows
            if ($this->config->maxVariableDepth > 0 && isset($frame['args'])) {
                $frameData->localVariables = $this->captureVariables($frame['args'], 0);
            }

            $frames[] = $frameData;
        }

        return $frames;
    }

    /**
     * @param array<mixed> $vars
     * @return array<string, VariableData>
     */
    private function captureVariables(array $vars, int $depth): array
    {
        $result = [];
        $index = 0;

        foreach ($vars as $key => $value) {
            $name = is_string($key) ? $key : "arg{$index}";
            $result[$name] = $this->captureVariable($name, $value, $depth);
            $index++;
        }

        return $result;
    }

    private function captureVariable(string $name, mixed $value, int $depth): VariableData
    {
        $var = new VariableData();
        $var->name = $name;
        $var->type = gettype($value);

        if ($value === null) {
            $var->isNull = true;
            $var->value = 'null';
        } elseif (is_scalar($value)) {
            $stringValue = (string) $value;
            if (strlen($stringValue) > 200) {
                $var->value = substr($stringValue, 0, 200) . '...';
                $var->isTruncated = true;
            } else {
                $var->value = $stringValue;
            }
        } elseif (is_array($value)) {
            $var->type = 'array';
            $var->value = 'Array(' . count($value) . ')';
            if ($depth < $this->config->maxVariableDepth && count($value) <= 10) {
                $var->children = $this->captureVariables($value, $depth + 1);
            }
        } elseif (is_object($value)) {
            $var->type = get_class($value);
            $var->value = get_class($value);
        } else {
            $var->value = '[' . $var->type . ']';
        }

        return $var;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestContext(): array
    {
        $context = [];

        if (PHP_SAPI !== 'cli' && isset($_SERVER)) {
            $context['http_method'] = $_SERVER['REQUEST_METHOD'] ?? null;
            $context['http_path'] = $_SERVER['REQUEST_URI'] ?? null;
            $context['http_host'] = $_SERVER['HTTP_HOST'] ?? null;
            $context['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $context['remote_ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
            $context['request_id'] = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        }

        return $context;
    }

    private function computeFingerprint(\Throwable $exception): string
    {
        $trace = $exception->getTrace();
        $topFrames = array_slice($trace, 0, 3);

        $parts = [get_class($exception)];
        foreach ($topFrames as $frame) {
            $parts[] = ($frame['class'] ?? '') . '::' . ($frame['function'] ?? '');
        }

        return hash('sha256', implode(':', $parts));
    }

    private function errorLevelToSeverity(int $level): string
    {
        return match ($level) {
            E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR => 'critical',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'warning',
            E_NOTICE, E_USER_NOTICE, E_STRICT, E_DEPRECATED, E_USER_DEPRECATED => 'info',
            default => 'error',
        };
    }

    private function errorLevelToString(int $level): string
    {
        return match ($level) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'E_UNKNOWN',
        };
    }
}
