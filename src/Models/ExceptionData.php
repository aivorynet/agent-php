<?php

declare(strict_types=1);

namespace AIVory\Monitor\Models;

/**
 * Data model for captured exceptions.
 */
class ExceptionData implements \JsonSerializable
{
    public string $exceptionType;
    public ?string $message;
    public ?string $filePath;
    public int $lineNumber;
    public ?string $methodName;
    public ?string $className;
    public string $severity;
    public string $runtime = 'php';
    public ?string $runtimeVersion;
    /** @var StackFrameData[] */
    public array $stackTrace = [];
    /** @var array<string, mixed>|null */
    public ?array $localVariables = null;
    /** @var array<string, mixed>|null */
    public ?array $requestContext = null;

    public function jsonSerialize(): array
    {
        return [
            'exception_type' => $this->exceptionType,
            'message' => $this->message,
            'file_path' => $this->filePath,
            'line_number' => $this->lineNumber,
            'method_name' => $this->methodName,
            'class_name' => $this->className,
            'severity' => $this->severity,
            'runtime' => $this->runtime,
            'runtime_version' => $this->runtimeVersion,
            'stack_trace' => array_map(fn($f) => $f->jsonSerialize(), $this->stackTrace),
            'local_variables' => $this->localVariables,
            'request_context' => $this->requestContext,
        ];
    }
}

/**
 * Data model for a stack frame.
 */
class StackFrameData implements \JsonSerializable
{
    public ?string $className = null;
    public ?string $methodName = null;
    public ?string $filePath = null;
    public ?string $fileName = null;
    public int $lineNumber = 0;
    public int $columnNumber = 0;
    public bool $isNative = false;
    /** @var array<string, VariableData>|null */
    public ?array $localVariables = null;

    public function jsonSerialize(): array
    {
        return [
            'class_name' => $this->className,
            'method_name' => $this->methodName,
            'file_path' => $this->filePath,
            'file_name' => $this->fileName,
            'line_number' => $this->lineNumber,
            'column_number' => $this->columnNumber,
            'is_native' => $this->isNative,
            'local_variables' => $this->localVariables !== null
                ? array_map(fn($v) => $v->jsonSerialize(), $this->localVariables)
                : null,
        ];
    }
}

/**
 * Data model for a captured variable.
 */
class VariableData implements \JsonSerializable
{
    public string $name;
    public ?string $type = null;
    public ?string $value = null;
    public bool $isNull = false;
    public bool $isTruncated = false;
    /** @var array<string, VariableData>|null */
    public ?array $children = null;

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'value' => $this->value,
            'is_null' => $this->isNull,
            'is_truncated' => $this->isTruncated,
            'children' => $this->children !== null
                ? array_map(fn($v) => $v->jsonSerialize(), $this->children)
                : null,
        ];
    }
}

/**
 * Data model for captured snapshots.
 */
class SnapshotData implements \JsonSerializable
{
    public ?string $breakpointId = null;
    public ?string $exceptionId = null;
    public ?string $filePath = null;
    public int $lineNumber = 0;
    public ?string $methodName = null;
    public ?string $className = null;
    /** @var StackFrameData[] */
    public array $stackTrace = [];
    /** @var array<string, VariableData>|null */
    public ?array $localVariables = null;
    /** @var array<string, mixed>|null */
    public ?array $requestContext = null;

    public function jsonSerialize(): array
    {
        return [
            'breakpoint_id' => $this->breakpointId,
            'exception_id' => $this->exceptionId,
            'file_path' => $this->filePath,
            'line_number' => $this->lineNumber,
            'method_name' => $this->methodName,
            'class_name' => $this->className,
            'stack_trace' => array_map(fn($f) => $f->jsonSerialize(), $this->stackTrace),
            'local_variables' => $this->localVariables !== null
                ? array_map(fn($v) => $v->jsonSerialize(), $this->localVariables)
                : null,
            'request_context' => $this->requestContext,
        ];
    }
}
