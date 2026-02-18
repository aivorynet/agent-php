<?php

declare(strict_types=1);

namespace AIVory\Monitor\Transport;

use AIVory\Monitor\Config;
use AIVory\Monitor\Models\ExceptionData;
use AIVory\Monitor\Models\SnapshotData;

/**
 * WebSocket connection to the AIVory backend.
 */
class BackendConnection
{
    private Config $config;
    private $socket = null;
    private bool $connected = false;
    private bool $authenticated = false;
    private int $reconnectAttempts = 0;
    private string $agentId = '';
    /** @var array<string, callable> */
    private array $eventHandlers = [];
    /** @var string[] */
    private array $messageQueue = [];

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->agentId = $this->generateAgentId();
    }

    /**
     * Connects to the backend.
     */
    public function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        try {
            $urlParts = parse_url($this->config->backendUrl);
            $scheme = $urlParts['scheme'] ?? 'wss';
            $host = $urlParts['host'] ?? 'api.aivory.net';
            $port = $urlParts['port'] ?? ($scheme === 'wss' ? 443 : 80);
            $path = $urlParts['path'] ?? '/ws/monitor/agent';

            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ]
            ]);

            $address = ($scheme === 'wss' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
            $this->socket = @stream_socket_client($address, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);

            if ($this->socket === false) {
                if ($this->config->debug) {
                    echo "[AIVory Monitor] Connection failed: {$errstr} ({$errno})\n";
                }
                $this->scheduleReconnect();
                return false;
            }

            // Perform WebSocket handshake
            $key = base64_encode(random_bytes(16));
            $headers = [
                "GET {$path} HTTP/1.1",
                "Host: {$host}",
                "Upgrade: websocket",
                "Connection: Upgrade",
                "Sec-WebSocket-Key: {$key}",
                "Sec-WebSocket-Version: 13",
                "Authorization: Bearer {$this->config->apiKey}",
            ];

            fwrite($this->socket, implode("\r\n", $headers) . "\r\n\r\n");

            $response = fread($this->socket, 1024);
            if (strpos($response, '101') === false) {
                if ($this->config->debug) {
                    echo "[AIVory Monitor] WebSocket handshake failed\n";
                }
                fclose($this->socket);
                $this->socket = null;
                $this->scheduleReconnect();
                return false;
            }

            stream_set_blocking($this->socket, false);
            $this->connected = true;
            $this->reconnectAttempts = 0;

            if ($this->config->debug) {
                echo "[AIVory Monitor] WebSocket connected\n";
            }

            $this->authenticate();
            return true;

        } catch (\Throwable $e) {
            if ($this->config->debug) {
                echo "[AIVory Monitor] Connection error: " . $e->getMessage() . "\n";
            }
            $this->scheduleReconnect();
            return false;
        }
    }

    /**
     * Disconnects from the backend.
     */
    public function disconnect(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }

        $this->connected = false;
        $this->authenticated = false;

        if ($this->config->debug) {
            echo "[AIVory Monitor] Disconnected\n";
        }
    }

    /**
     * Sends an exception to the backend.
     */
    public function sendException(ExceptionData $data): void
    {
        $payload = $data->jsonSerialize();
        $payload['agent_id'] = $this->agentId;
        $payload['environment'] = $this->config->environment;
        $payload['hostname'] = gethostname();

        $this->send('exception', $payload);
    }

    /**
     * Sends a snapshot to the backend.
     */
    public function sendSnapshot(SnapshotData $data): void
    {
        $payload = $data->jsonSerialize();
        $payload['agent_id'] = $this->agentId;

        $this->send('snapshot', $payload);
    }

    /**
     * Sends a breakpoint hit to the backend.
     *
     * @param array<string, mixed> $payload
     */
    public function sendBreakpointHit(string $breakpointId, array $payload): void
    {
        $payload['breakpoint_id'] = $breakpointId;
        $payload['agent_id'] = $this->agentId;

        $this->send('breakpoint_hit', $payload);
    }

    /**
     * Sends a heartbeat to keep the connection alive.
     */
    public function sendHeartbeat(): void
    {
        $this->send('heartbeat', [
            'timestamp' => time() * 1000,
            'agent_id' => $this->agentId,
            'metrics' => [
                'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ]
        ]);
    }

    /**
     * Processes incoming messages (call periodically).
     */
    public function processMessages(): void
    {
        if (!$this->connected || $this->socket === null) {
            return;
        }

        $data = @fread($this->socket, 65535);
        if ($data === false || $data === '') {
            return;
        }

        $decoded = $this->decodeWebSocketFrame($data);
        if ($decoded === null) {
            return;
        }

        $this->handleMessage($decoded);
    }

    /**
     * Registers an event handler.
     */
    public function on(string $event, callable $handler): void
    {
        $this->eventHandlers[$event] = $handler;
    }

    /**
     * Checks if connected and authenticated.
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->authenticated;
    }

    private function send(string $type, array $payload): void
    {
        $message = [
            'type' => $type,
            'payload' => $payload,
            'timestamp' => time() * 1000,
        ];

        $json = json_encode($message);
        if ($json === false) {
            return;
        }

        if ($this->connected && $this->socket !== null && $this->authenticated) {
            $frame = $this->encodeWebSocketFrame($json);
            @fwrite($this->socket, $frame);
        } else {
            $this->messageQueue[] = $json;
            if (count($this->messageQueue) > 100) {
                array_shift($this->messageQueue);
            }
        }
    }

    private function authenticate(): void
    {
        $payload = [
            'api_key' => $this->config->apiKey,
            'agent_id' => $this->agentId,
            'hostname' => gethostname(),
            'environment' => $this->config->environment,
            'runtime' => 'php',
            'runtime_version' => PHP_VERSION,
            'agent_version' => '1.0.0',
        ];

        if ($this->config->applicationName !== null) {
            $payload['application_name'] = $this->config->applicationName;
        }

        $message = [
            'type' => 'register',
            'payload' => $payload,
            'timestamp' => time() * 1000,
        ];

        $json = json_encode($message);
        if ($json !== false && $this->socket !== null) {
            $frame = $this->encodeWebSocketFrame($json);
            @fwrite($this->socket, $frame);
        }
    }

    private function handleMessage(string $data): void
    {
        try {
            $message = json_decode($data, true);
            if (!is_array($message) || !isset($message['type'])) {
                return;
            }

            $type = $message['type'];
            $payload = $message['payload'] ?? [];

            if ($this->config->debug) {
                echo "[AIVory Monitor] Received: {$type}\n";
            }

            switch ($type) {
                case 'registered':
                    $this->handleRegistered($payload);
                    break;
                case 'error':
                    $this->handleError($payload);
                    break;
                case 'set_breakpoint':
                    $this->emit('set_breakpoint', $payload);
                    break;
                case 'remove_breakpoint':
                    $this->emit('remove_breakpoint', $payload);
                    break;
            }
        } catch (\Throwable $e) {
            if ($this->config->debug) {
                echo "[AIVory Monitor] Error parsing message: " . $e->getMessage() . "\n";
            }
        }
    }

    private function handleRegistered(array $payload): void
    {
        $this->authenticated = true;

        if (isset($payload['agent_id'])) {
            $this->agentId = $payload['agent_id'];
        }

        // Flush queued messages
        while (count($this->messageQueue) > 0) {
            $msg = array_shift($this->messageQueue);
            if ($msg !== null && $this->socket !== null) {
                $frame = $this->encodeWebSocketFrame($msg);
                @fwrite($this->socket, $frame);
            }
        }

        if ($this->config->debug) {
            echo "[AIVory Monitor] Agent registered\n";
        }
    }

    private function handleError(array $payload): void
    {
        $code = $payload['code'] ?? 'unknown';
        $message = $payload['message'] ?? 'Unknown error';

        echo "[AIVory Monitor] Backend error: {$code} - {$message}\n";

        if ($code === 'auth_error' || $code === 'invalid_api_key') {
            echo "[AIVory Monitor] Authentication failed, disabling reconnect\n";
            $this->config->maxReconnectAttempts = 0;
            $this->disconnect();
        }
    }

    private function emit(string $event, mixed $data): void
    {
        if (isset($this->eventHandlers[$event])) {
            call_user_func($this->eventHandlers[$event], $data);
        }
    }

    private function scheduleReconnect(): void
    {
        if ($this->reconnectAttempts >= $this->config->maxReconnectAttempts) {
            echo "[AIVory Monitor] Max reconnect attempts reached\n";
            return;
        }

        $this->reconnectAttempts++;
        $delay = min(1000 * pow(2, $this->reconnectAttempts - 1), 60000);

        if ($this->config->debug) {
            echo "[AIVory Monitor] Reconnecting in {$delay}ms (attempt {$this->reconnectAttempts})\n";
        }
    }

    private function generateAgentId(): string
    {
        return sprintf(
            '%s-%s-%d',
            gethostname(),
            bin2hex(random_bytes(4)),
            getmypid()
        );
    }

    private function encodeWebSocketFrame(string $payload): string
    {
        $length = strlen($payload);
        $frame = chr(0x81); // Text frame, FIN bit set

        if ($length <= 125) {
            $frame .= chr($length | 0x80); // Mask bit set
        } elseif ($length <= 65535) {
            $frame .= chr(126 | 0x80);
            $frame .= pack('n', $length);
        } else {
            $frame .= chr(127 | 0x80);
            $frame .= pack('J', $length);
        }

        // Generate masking key
        $mask = random_bytes(4);
        $frame .= $mask;

        // Apply mask to payload
        for ($i = 0; $i < $length; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }

        return $frame;
    }

    private function decodeWebSocketFrame(string $data): ?string
    {
        if (strlen($data) < 2) {
            return null;
        }

        $byte1 = ord($data[0]);
        $byte2 = ord($data[1]);

        $opcode = $byte1 & 0x0f;
        $masked = ($byte2 & 0x80) !== 0;
        $length = $byte2 & 0x7f;

        $offset = 2;

        if ($length === 126) {
            if (strlen($data) < 4) {
                return null;
            }
            $length = unpack('n', substr($data, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($data) < 10) {
                return null;
            }
            $length = unpack('J', substr($data, 2, 8))[1];
            $offset = 10;
        }

        if ($masked) {
            $mask = substr($data, $offset, 4);
            $offset += 4;
            $payload = substr($data, $offset, $length);

            $decoded = '';
            for ($i = 0; $i < strlen($payload); $i++) {
                $decoded .= $payload[$i] ^ $mask[$i % 4];
            }
            return $decoded;
        }

        return substr($data, $offset, $length);
    }
}
