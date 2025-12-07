<?php

declare(strict_types=1);

namespace FeatureFlags\Client;

use FeatureFlags\Exceptions\ApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;

class ApiClient
{
    private const CIRCUIT_BREAKER_KEY = 'featureflags:circuit_breaker';
    private const CIRCUIT_BREAKER_FAILURES_KEY = 'featureflags:circuit_breaker_failures';

    private Client $client;
    private readonly bool $circuitBreakerEnabled;
    private readonly int $circuitBreakerThreshold;
    private readonly int $circuitBreakerCooldown;

    public function __construct(
        private readonly string $apiUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout = 5,
        private readonly bool $verifySsl = true,
    ) {
        $this->client = new Client([
            'base_uri' => rtrim($this->apiUrl, '/') . '/',
            'timeout' => $this->timeout,
            'verify' => $this->verifySsl,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . ($this->apiKey ?? ''),
            ],
        ]);

        $enabled = config('featureflags.sync.circuit_breaker.enabled', true);
        $this->circuitBreakerEnabled = is_bool($enabled) ? $enabled : true;

        $threshold = config('featureflags.sync.circuit_breaker.failure_threshold', 5);
        $this->circuitBreakerThreshold = is_int($threshold) ? $threshold : 5;

        $cooldown = config('featureflags.sync.circuit_breaker.cooldown_seconds', 30);
        $this->circuitBreakerCooldown = is_int($cooldown) ? $cooldown : 30;
    }

    /**
     * @return array{flags: array<int, array<string, mixed>>, segments: array<int, array<string, mixed>>, cache_ttl: int}
     * @throws ApiException
     */
    public function fetchFlags(): array
    {
        if (empty($this->apiKey)) {
            throw new ApiException('API key not configured');
        }

        if ($this->isCircuitOpen()) {
            throw new ApiException('Circuit breaker is open - API calls temporarily disabled');
        }

        try {
            $response = $this->client->get('api/flags');
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if (!is_array($data)) {
                $this->recordFailure();
                $error = json_last_error_msg();
                throw new ApiException("Invalid JSON response from API: {$error}");
            }

            $this->recordSuccess();

            /** @var array{flags?: array<int, array<string, mixed>>, segments?: array<int, array<string, mixed>>, cache_ttl?: int} $data */
            return [
                'flags' => $data['flags'] ?? [],
                'segments' => $data['segments'] ?? [],
                'cache_ttl' => $data['cache_ttl'] ?? 300,
            ];
        } catch (GuzzleException $e) {
            $this->recordFailure();
            throw new ApiException('Failed to fetch flags: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @throws ApiException
     */
    public function sendTelemetry(array $events): void
    {
        if (empty($this->apiKey) || empty($events)) {
            return;
        }

        // Skip telemetry if circuit is open (non-critical)
        if ($this->isCircuitOpen()) {
            return;
        }

        try {
            $this->client->post('api/telemetry', [
                'json' => ['events' => $events],
            ]);
            $this->recordSuccess();
        } catch (GuzzleException $e) {
            $this->recordFailure();
            throw new ApiException('Failed to send telemetry: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @throws ApiException
     */
    public function sendConversions(array $events): void
    {
        if (empty($this->apiKey) || empty($events)) {
            return;
        }

        // Skip conversions if circuit is open (non-critical)
        if ($this->isCircuitOpen()) {
            return;
        }

        try {
            $this->client->post('api/conversions', [
                'json' => ['events' => $events],
            ]);
            $this->recordSuccess();
        } catch (GuzzleException $e) {
            $this->recordFailure();
            throw new ApiException('Failed to send conversions: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $errors
     * @throws ApiException
     */
    public function sendErrors(array $errors): void
    {
        if (empty($this->apiKey) || empty($errors)) {
            return;
        }

        // Skip errors if circuit is open (non-critical)
        if ($this->isCircuitOpen()) {
            return;
        }

        try {
            $this->client->post('api/errors', [
                'json' => ['errors' => $errors],
            ]);
            $this->recordSuccess();
        } catch (GuzzleException $e) {
            $this->recordFailure();
            throw new ApiException('Failed to send errors: ' . $e->getMessage(), 0, $e);
        }
    }

    private function isCircuitOpen(): bool
    {
        if (!$this->circuitBreakerEnabled) {
            return false;
        }

        return Cache::get(self::CIRCUIT_BREAKER_KEY, false) === true;
    }

    private function recordSuccess(): void
    {
        if (!$this->circuitBreakerEnabled) {
            return;
        }

        Cache::forget(self::CIRCUIT_BREAKER_KEY);
        Cache::forget(self::CIRCUIT_BREAKER_FAILURES_KEY);
    }

    private function recordFailure(): void
    {
        if (!$this->circuitBreakerEnabled) {
            return;
        }

        if (!Cache::has(self::CIRCUIT_BREAKER_FAILURES_KEY)) {
            Cache::put(self::CIRCUIT_BREAKER_FAILURES_KEY, 0, $this->circuitBreakerCooldown * 2);
        }

        /** @var int|bool $failures */
        $failures = Cache::increment(self::CIRCUIT_BREAKER_FAILURES_KEY);

        if ($failures === false) {
            $failures = $this->circuitBreakerThreshold;
        }

        if ($failures >= $this->circuitBreakerThreshold) {
            Cache::put(self::CIRCUIT_BREAKER_KEY, true, $this->circuitBreakerCooldown);
        }
    }
}
