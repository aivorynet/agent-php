<?php
/**
 * AIVory PHP Agent Test Application
 *
 * Generates various exception types to test exception capture and local variable extraction.
 *
 * Usage:
 *   cd monitor-agents/agent-php
 *   composer install
 *   AIVORY_API_KEY=test-key-123 AIVORY_BACKEND_URL=ws://localhost:19999/api/monitor/agent/v1 AIVORY_DEBUG=true php test_app.php
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use AIVory\Monitor\Agent;

class UserContext
{
    public function __construct(
        public readonly string $userId,
        public readonly string $email,
        public readonly bool $active = true
    ) {}

    public function __toString(): string
    {
        return "UserContext(userId='{$this->userId}', email='{$this->email}', active=" . ($this->active ? 'true' : 'false') . ")";
    }
}

function triggerException(int $iteration): void
{
    // Create some local variables to capture
    $testVar = "test-value-{$iteration}";
    $count = $iteration * 10;
    $items = ['apple', 'banana', 'cherry'];
    $metadata = [
        'iteration' => $iteration,
        'timestamp' => time(),
        'nested' => ['key' => 'value', 'count' => $count]
    ];
    $user = new UserContext("user-{$iteration}", 'test@example.com');

    switch ($iteration) {
        case 0:
            // TypeError (like NullPointerException)
            echo "Triggering TypeError...\n";
            $nullValue = null;
            $nullValue->someMethod(); // TypeError here
            break;

        case 1:
            // InvalidArgumentException
            echo "Triggering InvalidArgumentException...\n";
            throw new InvalidArgumentException("Invalid argument: testVar={$testVar}");

        case 2:
            // OutOfBoundsException
            echo "Triggering OutOfBoundsException...\n";
            $arr = [1, 2, 3];
            $val = $arr[10]; // Warning in PHP, but let's use array access
            throw new OutOfBoundsException("Array index out of bounds");

        default:
            throw new RuntimeException("Unknown iteration: {$iteration}");
    }
}

echo "===========================================\n";
echo "AIVory PHP Agent Test Application\n";
echo "===========================================\n";

// Initialize the agent
Agent::init();

// Set user context
Agent::setUser([
    'id' => 'test-user-001',
    'email' => 'tester@example.com',
    'username' => 'tester'
]);

// Wait for agent to connect
echo "Waiting for agent to connect...\n";
sleep(3);
echo "Starting exception tests...\n\n";

// Generate test exceptions
for ($i = 0; $i < 3; $i++) {
    echo "--- Test " . ($i + 1) . " ---\n";
    try {
        triggerException($i);
    } catch (Throwable $e) {
        echo "Caught: " . get_class($e) . " - " . $e->getMessage() . "\n";
        // Also manually capture for testing
        Agent::captureException($e, ['test_iteration' => $i]);
    }
    echo "\n";

    // Send heartbeat and process messages
    Agent::heartbeat();
    Agent::processMessages();

    sleep(3);
}

echo "===========================================\n";
echo "Test complete. Check database for exceptions.\n";
echo "===========================================\n";

// Keep running briefly to allow final messages to send
sleep(2);

// Shutdown cleanly
Agent::shutdown();
