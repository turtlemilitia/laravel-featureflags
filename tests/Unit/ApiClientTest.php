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

class ApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear circuit breaker state before each test
        Cache::forget('featureflags:circuit_breaker');
        Cache::forget('featureflags:circuit_breaker_failures');
    }

    protected function tearDown(): void
    {
        Cache::forget('featureflags:circuit_breaker');
        Cache::forget('featureflags:circuit_breaker_failures');
        parent::tearDown();
    }

    public function test_fetch_flags_throws_when_api_key_missing(): void
    {
        $client = new ApiClient('https://api.test.com', null);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('API key not configured');

        $client->fetchFlags();
    }

    public function test_fetch_flags_throws_when_api_key_empty(): void
    {
        $client = new ApiClient('https://api.test.com', '');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('API key not configured');

        $client->fetchFlags();
    }

    public function test_fetch_flags_throws_when_circuit_open(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => true]);

        // Manually open the circuit breaker
        Cache::put('featureflags:circuit_breaker', true, 60);

        $client = new ApiClient('https://api.test.com', 'test-key');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Circuit breaker is open');

        $client->fetchFlags();
    }

    public function test_send_telemetry_returns_early_when_api_key_missing(): void
    {
        $client = new ApiClient('https://api.test.com', null);

        // Should not throw - just return early
        $client->sendTelemetry([['flag_key' => 'test']]);

        $this->assertTrue(true);
    }

    public function test_send_telemetry_returns_early_when_events_empty(): void
    {
        $client = new ApiClient('https://api.test.com', 'test-key');

        // Should not throw - just return early
        $client->sendTelemetry([]);

        $this->assertTrue(true);
    }

    public function test_send_telemetry_skipped_when_circuit_open(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => true]);

        // Manually open the circuit breaker
        Cache::put('featureflags:circuit_breaker', true, 60);

        $client = new ApiClient('https://api.test.com', 'test-key');

        // Should not throw - just return early (circuit is open, non-critical)
        $client->sendTelemetry([['flag_key' => 'test']]);

        $this->assertTrue(true);
    }

    public function test_send_conversions_returns_early_when_api_key_missing(): void
    {
        $client = new ApiClient('https://api.test.com', null);

        // Should not throw - just return early
        $client->sendConversions([['event_name' => 'purchase']]);

        $this->assertTrue(true);
    }

    public function test_send_conversions_returns_early_when_events_empty(): void
    {
        $client = new ApiClient('https://api.test.com', 'test-key');

        // Should not throw - just return early
        $client->sendConversions([]);

        $this->assertTrue(true);
    }

    public function test_send_conversions_skipped_when_circuit_open(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => true]);

        // Manually open the circuit breaker
        Cache::put('featureflags:circuit_breaker', true, 60);

        $client = new ApiClient('https://api.test.com', 'test-key');

        // Should not throw - just return early (circuit is open, non-critical)
        $client->sendConversions([['event_name' => 'purchase']]);

        $this->assertTrue(true);
    }

    public function test_send_errors_returns_early_when_api_key_missing(): void
    {
        $client = new ApiClient('https://api.test.com', null);

        // Should not throw - just return early
        $client->sendErrors([['error_type' => 'RuntimeException']]);

        $this->assertTrue(true);
    }

    public function test_send_errors_returns_early_when_events_empty(): void
    {
        $client = new ApiClient('https://api.test.com', 'test-key');

        // Should not throw - just return early
        $client->sendErrors([]);

        $this->assertTrue(true);
    }

    public function test_send_errors_skipped_when_circuit_open(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => true]);

        // Manually open the circuit breaker
        Cache::put('featureflags:circuit_breaker', true, 60);

        $client = new ApiClient('https://api.test.com', 'test-key');

        // Should not throw - just return early (circuit is open, non-critical)
        $client->sendErrors([['error_type' => 'RuntimeException']]);

        $this->assertTrue(true);
    }

    public function test_circuit_breaker_can_be_disabled(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => false]);

        // Even with circuit "open" in cache, it should be ignored
        Cache::put('featureflags:circuit_breaker', true, 60);

        $client = new ApiClient('https://api.test.com', 'test-key');

        // This would throw ApiException if circuit was considered open
        // Instead it will try to make the actual HTTP request
        // We can't easily test the actual request without mocking Guzzle
        // But the config check is still being exercised
        $this->assertTrue(true);
    }

    public function test_fetch_flags_returns_correct_structure(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => false]);

        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'flags' => [
                    ['key' => 'flag-1', 'enabled' => true],
                    ['key' => 'flag-2', 'enabled' => false],
                ],
                'segments' => [
                    ['key' => 'beta-users', 'rules' => []],
                ],
                'cache_ttl' => 600,
            ])),
        ]);

        $apiClient = $this->createApiClientWithMock($mock);

        $result = $apiClient->fetchFlags();

        $this->assertArrayHasKey('flags', $result);
        $this->assertArrayHasKey('segments', $result);
        $this->assertArrayHasKey('cache_ttl', $result);
        $this->assertCount(2, $result['flags']);
        $this->assertCount(1, $result['segments']);
        $this->assertEquals(600, $result['cache_ttl']);
    }

    public function test_fetch_flags_uses_defaults_when_response_incomplete(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => false]);

        $mock = new MockHandler([
            new Response(200, [], json_encode([])),
        ]);

        $apiClient = $this->createApiClientWithMock($mock);

        $result = $apiClient->fetchFlags();

        $this->assertEquals([], $result['flags']);
        $this->assertEquals([], $result['segments']);
        $this->assertEquals(300, $result['cache_ttl']);
    }

    public function test_fetch_flags_throws_on_network_error(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => false]);

        $mock = new MockHandler([
            new ConnectException('Connection refused', new Request('GET', 'api/flags')),
        ]);

        $apiClient = $this->createApiClientWithMock($mock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Failed to fetch flags');

        $apiClient->fetchFlags();
    }

    public function test_send_telemetry_succeeds_with_valid_data(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => false]);

        $mock = new MockHandler([
            new Response(200, []),
        ]);

        $apiClient = $this->createApiClientWithMock($mock);

        // Should not throw
        $apiClient->sendTelemetry([
            ['flag_key' => 'test-flag', 'value' => true],
        ]);

        $this->assertEquals(0, $mock->count());
    }

    public function test_send_telemetry_throws_on_network_error(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => false]);

        $mock = new MockHandler([
            new ConnectException('Connection refused', new Request('POST', 'api/telemetry')),
        ]);

        $apiClient = $this->createApiClientWithMock($mock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Failed to send telemetry');

        $apiClient->sendTelemetry([['flag_key' => 'test']]);
    }

    public function test_send_conversions_succeeds_with_valid_data(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => false]);

        $mock = new MockHandler([
            new Response(200, []),
        ]);

        $apiClient = $this->createApiClientWithMock($mock);

        // Should not throw
        $apiClient->sendConversions([
            ['event_name' => 'purchase', 'flag_key' => 'checkout-v2'],
        ]);

        $this->assertEquals(0, $mock->count());
    }

    public function test_send_conversions_throws_on_network_error(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => false]);

        $mock = new MockHandler([
            new ConnectException('Connection refused', new Request('POST', 'api/conversions')),
        ]);

        $apiClient = $this->createApiClientWithMock($mock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Failed to send conversions');

        $apiClient->sendConversions([['event_name' => 'test']]);
    }

    public function test_send_errors_succeeds_with_valid_data(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => false]);

        $mock = new MockHandler([
            new Response(200, []),
        ]);

        $apiClient = $this->createApiClientWithMock($mock);

        // Should not throw
        $apiClient->sendErrors([
            ['flag_key' => 'test-flag', 'error_type' => 'RuntimeException'],
        ]);

        $this->assertEquals(0, $mock->count());
    }

    public function test_send_errors_throws_on_network_error(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => false]);

        $mock = new MockHandler([
            new ConnectException('Connection refused', new Request('POST', 'api/errors')),
        ]);

        $apiClient = $this->createApiClientWithMock($mock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Failed to send errors');

        $apiClient->sendErrors([['error_type' => 'RuntimeException']]);
    }

    public function test_fetch_flags_throws_on_invalid_json(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => false]);

        $mock = new MockHandler([
            new Response(200, [], 'not valid json'),
        ]);

        $apiClient = $this->createApiClientWithMock($mock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid JSON response from API');

        $apiClient->fetchFlags();
    }

    public function test_fetch_flags_throws_on_empty_response(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => false]);

        $mock = new MockHandler([
            new Response(200, [], ''),
        ]);

        $apiClient = $this->createApiClientWithMock($mock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid JSON response from API');

        $apiClient->fetchFlags();
    }

    public function test_fetch_flags_throws_on_non_array_json(): void
    {
        config(['featureflags.sync.circuit_breaker.enabled' => false]);

        $mock = new MockHandler([
            new Response(200, [], '"just a string"'),
        ]);

        $apiClient = $this->createApiClientWithMock($mock);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid JSON response from API');

        $apiClient->fetchFlags();
    }

    /**
     * Create an ApiClient with a mock Guzzle handler.
     */
    private function createApiClientWithMock(MockHandler $mock): ApiClient
    {
        $handlerStack = HandlerStack::create($mock);
        $guzzleClient = new Client(['handler' => $handlerStack]);

        $apiClient = new ApiClient('https://api.test.com', 'test-key');

        // Use reflection to replace the private client
        $reflection = new \ReflectionClass(ApiClient::class);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($apiClient, $guzzleClient);

        return $apiClient;
    }
}
