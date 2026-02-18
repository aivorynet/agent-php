<?php

declare(strict_types=1);

namespace AIVory\Monitor\Breakpoint;

/**
 * Represents a registered breakpoint.
 */
class BreakpointInfo
{
    public string $id;
    public string $filePath;
    public int $lineNumber;
    public ?string $condition;
    public int $maxHits;
    public int $hitCount = 0;
    public float $createdAt;

    public function __construct(
        string $id,
        string $filePath,
        int $lineNumber,
        ?string $condition = null,
        int $maxHits = 1
    ) {
        $this->id = $id;
        $this->filePath = $filePath;
        $this->lineNumber = $lineNumber;
        $this->condition = $condition;
        $this->maxHits = max(1, min($maxHits, 50));
        $this->createdAt = microtime(true);
    }
}
