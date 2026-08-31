<?php

namespace App\Services\ControlPlane;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class ControlPlaneClient
{
    private string $baseUrl;
    private string $serviceToken;
    private int $timeoutSeconds;

    public function __construct(
        ?string $baseUrl = null,
        ?string $serviceToken = null,
        ?int $timeoutSeconds = null
    ) {
        $this->baseUrl = rtrim($baseUrl ?? config('vpn.control_plane.base_url', 'http://127.0.0.1:8081'), '/');
        $this->serviceToken = $serviceToken ?? config('vpn.control_plane.service_token', 'dev-only-change-me');
        $this->timeoutSeconds = $timeoutSeconds ?? (int) config('vpn.control_plane.timeout_seconds', 5);
    }

    private function client(?string $requestId = null): PendingRequest
    {
        $reqId = $requestId ?? request()?->header('X-Request-ID') ?? (string) Str::uuid();

        return Http::baseUrl($this->baseUrl)
            ->timeout($this->timeoutSeconds)
            ->withToken($this->serviceToken)
            ->withHeaders([
                'X-Request-ID' => $reqId,
                'Accept' => 'application/json',
            ]);
    }

    /**
     * Add a peer to a node via control-plane.
     *
     * @param array<int, string> $allowedIps
     * @return array<string, mixed>
     */
    public function addPeer(
        string $nodeId,
        string $peerId,
        string $publicKey,
        string $assignedIp,
        array $allowedIps = ['0.0.0.0/0', '::/0'],
        ?string $requestId = null
    ): array {
        try {
            $response = $this->client($requestId)->post('/internal/v1/peers', [
                'node_id' => $nodeId,
                'peer_id' => $peerId,
                'public_key' => $publicKey,
                'assigned_ip' => $assignedIp,
                'allowed_ips' => $allowedIps,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            $errorData = $response->json('error') ?? [];
            $code = $errorData['code'] ?? 'CONTROL_PLANE_ERROR';
            $message = $errorData['message'] ?? 'Control plane failed to add peer';

            throw new RuntimeException($message . " ({$code})");
        } catch (Exception $e) {
            Log::error('ControlPlaneClient addPeer failed', [
                'node_id' => $nodeId,
                'peer_id' => $peerId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Remove a peer from control-plane / node.
     *
     * @return array<string, mixed>
     */
    public function removePeer(string $peerId, ?string $requestId = null): array
    {
        try {
            $response = $this->client($requestId)->delete("/internal/v1/peers/{$peerId}");

            if ($response->successful() || $response->status() === 404) {
                return $response->json() ?? ['data' => ['removed' => true]];
            }

            $errorData = $response->json('error') ?? [];
            $message = $errorData['message'] ?? 'Control plane failed to remove peer';

            throw new RuntimeException($message);
        } catch (Exception $e) {
            Log::error('ControlPlaneClient removePeer failed', [
                'peer_id' => $peerId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get peer state from control plane.
     *
     * @return array<string, mixed>|null
     */
    public function getPeer(string $peerId, ?string $requestId = null): ?array
    {
        try {
            $response = $this->client($requestId)->get("/internal/v1/peers/{$peerId}");
            if ($response->status() === 404) {
                return null;
            }

            if ($response->successful()) {
                return $response->json('data');
            }

            return null;
        } catch (Exception $e) {
            Log::warning('ControlPlaneClient getPeer error', ['peer_id' => $peerId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * List peers from control plane.
     *
     * @return array<string, mixed>
     */
    public function listPeers(?string $nodeId = null, ?string $requestId = null): array
    {
        try {
            $params = $nodeId !== null ? ['node_id' => $nodeId] : [];
            $response = $this->client($requestId)->get('/internal/v1/peers', $params);
            if ($response->successful()) {
                return $response->json('data') ?? [];
            }
            return [];
        } catch (Exception $e) {
            Log::warning('ControlPlaneClient listPeers error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * List nodes from control plane.
     *
     * @return array<string, mixed>
     */
    public function listNodes(?string $requestId = null): array
    {
        try {
            $response = $this->client($requestId)->get('/internal/v1/nodes');
            if ($response->successful()) {
                return $response->json('data') ?? [];
            }
            return [];
        } catch (Exception $e) {
            Log::warning('ControlPlaneClient listNodes error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get node state from control plane.
     *
     * @return array<string, mixed>|null
     */
    public function getNode(string $nodeId, ?string $requestId = null): ?array
    {
        try {
            $response = $this->client($requestId)->get("/internal/v1/nodes/{$nodeId}");
            if ($response->successful()) {
                return $response->json('data');
            }
            return null;
        } catch (Exception $e) {
            Log::warning('ControlPlaneClient getNode error', ['node_id' => $nodeId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Set node drain status.
     *
     * @return array<string, mixed>
     */
    public function drainNode(string $nodeId, bool $drain = true, ?string $requestId = null): array
    {
        $response = $this->client($requestId)->post("/internal/v1/nodes/{$nodeId}/drain", [
            'drain' => $drain,
        ]);

        return $response->json() ?? [];
    }

    /**
     * Set node maintenance status.
     *
     * @return array<string, mixed>
     */
    public function setNodeMaintenance(string $nodeId, bool $maintenance = true, ?string $requestId = null): array
    {
        $response = $this->client($requestId)->post("/internal/v1/nodes/{$nodeId}/maintenance", [
            'maintenance' => $maintenance,
        ]);

        return $response->json() ?? [];
    }

    /**
     * Register a remote node adapter with the control plane.
     *
     * @return array<string, mixed>
     */
    public function registerNode(
        string $nodeId,
        string $endpoint,
        string $adapterType = 'remote',
        bool $mtlsEnabled = false,
        ?string $requestId = null
    ): array {
        try {
            $response = $this->client($requestId)->post('/internal/v1/nodes/register', [
                'node_id' => $nodeId,
                'endpoint' => $endpoint,
                'adapter_type' => $adapterType,
                'mtls_enabled' => $mtlsEnabled,
            ]);

            return $response->json() ?? [];
        } catch (Exception $e) {
            Log::warning('ControlPlaneClient registerNode error', ['node_id' => $nodeId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Inject a test failure into control-plane fake adapter.
     *
     * @return array<string, mixed>
     */
    public function injectFailure(string $action, string $failureType, ?string $requestId = null): array
    {
        $response = $this->client($requestId)->post('/internal/v1/test/inject-failure', [
            'action' => $action,
            'failure_type' => $failureType,
        ]);

        return $response->json() ?? [];
    }
}
