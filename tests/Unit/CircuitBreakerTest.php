<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Client\ApiClient;
use FeatureFlags\Exceptions\ApiException;
use FeatureFlags\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;

class CircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_circuit_opens_after_threshold_failures(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => true]);
        config(['featureflags.sync.circuit_breaker.failure_threshold' => 3]);
        config(['featureflags.sync.circuit_breaker.cooldown_seconds' => 30]);

        $mock = new MockHandler([
            new ConnectException('Connection failed', new Request('GET', 'api/flags')),
            new ConnectException('Connection failed', new Request('GET', 'api/flags')),
            new ConnectException('Connection failed', new Request('GET', 'api/flags')),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $guzzleClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClientWithMockableClient(
            'https://api.test.com',
            'test-key',
            5,
            true,
            $guzzleClient,
        );

        // First 3 failures should accumulate
        for ($i = 0; $i < 3; $i++) {
            try {
                $apiClient->fetchFlags();
            } catch (ApiException) {
                // Expected
            }
        }

        // Circuit should now be open
        $this->assertTrue(Cache::get('featureflags:circuit_breaker', false));
    }

    public function test_circuit_open_prevents_api_calls(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => true]);
        config(['featureflags.sync.circuit_breaker.cooldown_seconds' => 30]);

        // Manually open the circuit
        Cache::put('featureflags:circuit_breaker', true, 30);

        $mock = new MockHandler([
            new Response(200, [], json_encode(['flags' => [], 'cache_ttl' => 300])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $guzzleClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClientWithMockableClient(
            'https://api.test.com',
            'test-key',
            5,
            true,
            $guzzleClient,
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Circuit breaker is open');

        $apiClient->fetchFlags();
    }

    public function test_successful_call_resets_circuit(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => true]);
        config(['featureflags.sync.circuit_breaker.failure_threshold' => 3]);

        // Set some failure state
        Cache::put('featureflags:circuit_breaker_failures', 2, 60);

        $mock = new MockHandler([
            new Response(200, [], json_encode(['flags' => [], 'cache_ttl' => 300])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $guzzleClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClientWithMockableClient(
            'https://api.test.com',
            'test-key',
            5,
            true,
            $guzzleClient,
        );

        $apiClient->fetchFlags();

        // Circuit breaker state should be cleared
        $this->assertFalse(Cache::get('featureflags:circuit_breaker', false));
        $this->assertEquals(0, Cache::get('featureflags:circuit_breaker_failures', 0));
    }

    public function test_circuit_breaker_can_be_disabled(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => false]);
        config(['featureflags.sync.circuit_breaker.failure_threshold' => 1]);

        $mock = new MockHandler([
            new ConnectException('Connection failed', new Request('GET', 'api/flags')),
            new Response(200, [], json_encode(['flags' => [], 'cache_ttl' => 300])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $guzzleClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClientWithMockableClient(
            'https://api.test.com',
            'test-key',
            5,
            true,
            $guzzleClient,
        );

        // First call fails but shouldn't open circuit
        try {
            $apiClient->fetchFlags();
        } catch (ApiException) {
            // Expected
        }

        // Circuit should NOT be open
        $this->assertFalse(Cache::get('featureflags:circuit_breaker', false));

        // Second call should work (circuit breaker disabled)
        $result = $apiClient->fetchFlags();
        $this->assertArrayHasKey('flags', $result);
    }

    public function test_telemetry_skipped_when_circuit_open(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => true]);

        // Open the circuit
        Cache::put('featureflags:circuit_breaker', true, 30);

        $mock = new MockHandler([
            // Should never be called
            new Response(200, []),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $guzzleClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClientWithMockableClient(
            'https://api.test.com',
            'test-key',
            5,
            true,
            $guzzleClient,
        );

        // Telemetry should silently skip when circuit is open
        $apiClient->sendTelemetry([['event' => 'test']]);

        // Verify no request was made (the mock would throw if accessed)
        $this->assertEquals(1, $mock->count());
    }

    public function test_errors_skipped_when_circuit_open(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => true]);

        Cache::put('featureflags:circuit_breaker', true, 30);

        $mock = new MockHandler([
            new Response(200, []),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $guzzleClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClientWithMockableClient(
            'https://api.test.com',
            'test-key',
            5,
            true,
            $guzzleClient,
        );

        // Errors should silently skip when circuit is open
        $apiClient->sendErrors([['error' => 'test']]);

        // Verify no request was made
        $this->assertEquals(1, $mock->count());
    }

    public function test_conversions_skipped_when_circuit_open(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => true]);

        Cache::put('featureflags:circuit_breaker', true, 30);

        $mock = new MockHandler([
            new Response(200, []),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $guzzleClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClientWithMockableClient(
            'https://api.test.com',
            'test-key',
            5,
            true,
            $guzzleClient,
        );

        // Conversions should silently skip when circuit is open
        $apiClient->sendConversions([['conversion' => 'test']]);

        // Verify no request was made
        $this->assertEquals(1, $mock->count());
    }

    public function test_failure_count_increments_on_each_failure(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => true]);
        config(['featureflags.sync.circuit_breaker.failure_threshold' => 5]);

        $mock = new MockHandler([
            new ConnectException('Connection failed', new Request('GET', 'api/flags')),
            new ConnectException('Connection failed', new Request('GET', 'api/flags')),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $guzzleClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClientWithMockableClient(
            'https://api.test.com',
            'test-key',
            5,
            true,
            $guzzleClient,
        );

        try {
            $apiClient->fetchFlags();
        } catch (ApiException) {
        }

        $this->assertEquals(1, Cache::get('featureflags:circuit_breaker_failures'));

        try {
            $apiClient->fetchFlags();
        } catch (ApiException) {
        }

        $this->assertEquals(2, Cache::get('featureflags:circuit_breaker_failures'));

        // Circuit should still be closed (threshold is 5)
        $this->assertFalse(Cache::get('featureflags:circuit_breaker', false));
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}

/**
 * Extended ApiClient that allows injecting a mock Guzzle client.
 */
class ApiClientWithMockableClient extends ApiClient
{
    private Client $mockClient;

    public function __construct(
        string $apiUrl,
        ?string $apiKey,
        int $timeout = 5,
        bool $verifySsl = true,
        ?Client $mockClient = null,
    ) {
        parent::__construct($apiUrl, $apiKey, $timeout, $verifySsl);

        if ($mockClient !== null) {
            $this->mockClient = $mockClient;
            // Use reflection to replace the private client
            $reflection = new \ReflectionClass(ApiClient::class);
            $property = $reflection->getProperty('client');
            $property->setAccessible(true);
            $property->setValue($this, $mockClient);
        }
    }
}
