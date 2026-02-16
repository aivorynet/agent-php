# AIVory Monitor PHP Agent

Production exception monitoring and debugging for PHP applications. Capture full context on exceptions and stream to AIVory Monitor for AI-powered analysis and auto-fix.

## Requirements

- PHP 8.0 or higher
- `ext-json` (required)
- `ext-sockets` (recommended)
- `textalk/websocket` (recommended for WebSocket transport)

## Installation

Install via Composer:

```bash
composer require aivory/monitor
```

## Usage

### Basic Initialization

Initialize the agent at the start of your application:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use AIVory\Monitor\Agent;

// Initialize with API key
Agent::init([
    'api_key' => 'your-api-key-here',
    'environment' => 'production',
]);

// Your application code
```

### Manual Exception Capture

Capture exceptions manually in try-catch blocks:

```php
use AIVory\Monitor\Agent;

try {
    riskyOperation();
} catch (\Exception $e) {
    Agent::captureException($e);
    // Handle exception...
}
```

### Laravel Integration

Add to `bootstrap/app.php` or create a service provider:

```php
// app/Providers/MonitorServiceProvider.php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use AIVory\Monitor\Agent;

class MonitorServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Agent::init([
            'api_key' => env('AIVORY_API_KEY'),
            'environment' => env('APP_ENV'),
        ]);
    }
}
```

Register in `config/app.php`:

```php
'providers' => [
    // ...
    App\Providers\MonitorServiceProvider::class,
],
```

### Symfony Integration

Add to `config/services.yaml`:

```yaml
services:
    aivory.monitor:
        class: AIVory\Monitor\Agent
        factory: ['AIVory\Monitor\Agent', 'init']
        arguments:
            - api_key: '%env(AIVORY_API_KEY)%'
              environment: '%kernel.environment%'
```

Or initialize in `public/index.php`:

```php
use AIVory\Monitor\Agent;

Agent::init([
    'api_key' => $_ENV['AIVORY_API_KEY'] ?? null,
    'environment' => $_ENV['APP_ENV'] ?? 'production',
]);

// Symfony kernel bootstrap...
```

## Configuration

Configure the agent using environment variables or initialization options:

| Variable | Description | Default |
|----------|-------------|---------|
| `AIVORY_API_KEY` | Agent authentication key | Required |
| `AIVORY_BACKEND_URL` | Backend WebSocket URL | `wss://api.aivory.net/monitor/agent/v1` |
| `AIVORY_ENVIRONMENT` | Environment name (e.g., production, staging) | `production` |
| `AIVORY_SAMPLING_RATE` | Exception sampling rate (0.0 to 1.0) | `1.0` |
| `AIVORY_MAX_DEPTH` | Maximum depth for variable capture | `3` |

### Configuration via Code

```php
Agent::init([
    'api_key' => 'your-key',
    'backend_url' => 'wss://custom-backend.example.com',
    'environment' => 'staging',
    'sampling_rate' => 0.5,  // Capture 50% of exceptions
    'max_depth' => 5,        // Deeper variable inspection
]);
```

## Building from Source

Clone the repository and install dependencies:

```bash
git clone https://github.com/aivory/monitor.git
cd monitor/monitor-agents/agent-php
composer install
```

Run tests:

```bash
composer test
```

Run static analysis:

```bash
composer phpstan
```

## How It Works

The PHP agent uses native PHP error handling mechanisms:

1. **Global Exception Handler**: Registers `set_exception_handler()` to catch uncaught exceptions
2. **Shutdown Function**: Registers `register_shutdown_function()` to catch fatal errors
3. **Context Capture**: Captures stack traces, local variables (up to configured depth), and request context
4. **WebSocket Transport**: Streams exception data to AIVory Monitor backend in real-time
5. **Sampling**: Applies sampling rate to reduce overhead in high-traffic applications

The agent automatically captures:
- Exception type and message
- Full stack trace with file paths and line numbers
- Local variables at each frame (respecting max depth)
- Request context (HTTP method, URL, headers, query params, POST data)
- PHP runtime information (version, memory usage, loaded extensions)

## Framework Support

### Laravel

The agent integrates seamlessly with Laravel's exception handler:

- Automatic capture of all unhandled exceptions
- Preserves Laravel's existing exception handling
- Access to request context (route, controller, user)
- Compatible with Laravel 8.x, 9.x, 10.x, 11.x

### Symfony

The agent works with Symfony's error handling:

- Captures exceptions before Symfony's error renderer
- Preserves debug mode behavior in development
- Compatible with Symfony 5.x, 6.x, 7.x

### Custom Frameworks

For custom frameworks or plain PHP applications:

```php
// Initialize early in bootstrap
Agent::init(['api_key' => getenv('AIVORY_API_KEY')]);

// Optionally set custom error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (error_reporting() & $errno) {
        $exception = new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        Agent::captureException($exception);
    }
    return false; // Let PHP's default handler run
});
```

## Troubleshooting

### Agent Not Capturing Exceptions

1. Verify API key is set: `echo getenv('AIVORY_API_KEY');`
2. Check that `Agent::init()` is called before any exceptions occur
3. Ensure `ext-json` is installed: `php -m | grep json`
4. Check PHP error logs for agent initialization errors

### WebSocket Connection Issues

1. Verify backend URL is accessible: `curl -I https://api.aivory.net`
2. Check firewall rules allow outbound WebSocket connections
3. Install recommended extensions: `composer require textalk/websocket`
4. Enable debug logging by setting `AIVORY_DEBUG=true`

### High Memory Usage

1. Reduce `max_depth` to limit variable capture depth:
   ```php
   Agent::init(['max_depth' => 2]);
   ```
2. Enable sampling to reduce captured exceptions:
   ```php
   Agent::init(['sampling_rate' => 0.1]); // 10%
   ```
3. Exclude large objects from capture (configure in backend)

### Integration with Existing Error Handlers

If your application already uses `set_exception_handler()`:

```php
// Store existing handler
$previousHandler = set_exception_handler(null);
restore_exception_handler();

// Initialize AIVory Monitor
Agent::init(['api_key' => $apiKey]);

// Chain handlers
set_exception_handler(function($exception) use ($previousHandler) {
    Agent::captureException($exception);
    if ($previousHandler) {
        $previousHandler($exception);
    }
});
```

## License

MIT License - see LICENSE file for details.

## Support

- Documentation: https://aivory.net/monitor/
- GitHub Issues: https://github.com/aivory/monitor/issues
- Email: support@aivory.net
