<?php

declare(strict_types=1);

namespace FeatureFlags\Tests\Feature;

use FeatureFlags\Client\ApiClient;
use FeatureFlags\Exceptions\ApiException;
use FeatureFlags\Exceptions\FlagSyncException;
use FeatureFlags\FeatureFlags;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Cache;

class ErrorScenariosTest extends FeatureTestCase
{
    public function test_api_failure_uses_cached_flags_by_default(): void
    {
        // Pre-seed cache with flags
        $this->seedFlags([
            $this->simpleFlag('cached-flag', true, 'cached-value'),
        ]);

        // Mock API failure
        $this->mockApiFailure('Connection refused');

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);

        // Should still work with cached flags
        $value = $ff->value('cached-flag');

        $this->assertEquals('cached-value', $value);
    }

    public function test_api_failure_with_exception_fallback_throws(): void
    {
        $this->app['config']->set('featureflags.fallback.behavior', 'exception');

        // No cached flags
        Cache::flush();

        // Mock API failure
        $this->mockApiFailure('Connection refused');

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);

        $this->expectException(FlagSyncException::class);
        $ff->sync();
    }

    public function test_api_failure_with_default_fallback_returns_default_value(): void
    {
        $this->app['config']->set('featureflags.fallback.behavior', 'default');
        $this->app['config']->set('featureflags.fallback.default_value', 'fallback-default');

        // No cached flags
        Cache::flush();

        // Don't mock API - let it fail naturally (no connection)

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);

        // Should return the configured default
        $value = $ff->value('unknown-flag');

        $this->assertEquals('fallback-default', $value);
    }

    public function test_circuit_breaker_opens_after_failures(): void
    {
        $this->app['config']->set('featureflags.sync.circuit_breaker.enabled', true);
        $this->app['config']->set('featureflags.sync.circuit_breaker.failure_threshold', 2);
        $this->app['config']->set('featureflags.sync.circuit_breaker.cooldown_seconds', 30);

        // Create mock handler with multiple failures
        $mockHandler = new MockHandler([
            new ConnectException('Connection refused', new Request('GET', 'test')),
            new ConnectException('Connection refused', new Request('GET', 'test')),
            new ConnectException('Connection refused', new Request('GET', 'test')),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $client = new Client(['handler' => $handlerStack]);

        $this->app->singleton(ApiClient::class, function () use ($client) {
            return new class ($client) extends ApiClient {
                private Client $httpClient;
                private int $callCount = 0;

                public function __construct(Client $client)
                {
                    $this->httpClient = $client;
                }

                public function fetchFlags(): array
                {
                    $this->callCount++;
                    if ($this->callCount <= 2) {
                        // Record failures to trigger circuit breaker
                        Cache::increment('featureflags:circuit_breaker_failures');
                        if ($this->callCount >= 2) {
                            Cache::put('featureflags:circuit_breaker', true, 30);
                        }
                        throw new ApiException('Connection refused');
                    }
                    // Third call - circuit should be open
                    throw new ApiException('Circuit breaker is open');
                }

                public function sendTelemetry(array $events): void {}
                public function sendConversions(array $events): void {}
                public function sendErrors(array $errors): void {}
            };
        });

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);

        // First two calls fail normally
        try {
            $ff->sync();
        } catch (FlagSyncException $e) {
            // Expected
        }

        try {
            $ff->sync();
        } catch (FlagSyncException $e) {
            // Expected, circuit should now be open
        }

        // Circuit should now be open
        $this->assertTrue(Cache::get('featureflags:circuit_breaker', false));
    }

    public function test_invalid_flag_key_returns_false(): void
    {
        $this->seedFlags([
            $this->simpleFlag('valid-flag', true),
        ]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);

        $this->assertFalse($ff->active('completely-invalid-flag'));
        $this->assertFalse($ff->value('another-invalid'));
    }

    public function test_invalid_regex_in_rule_returns_false_gracefully(): void
    {
        $this->seedFlags([
            $this->flagWithRules('regex-flag', [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'email', 'operator' => 'matches_regex', 'value' => '/invalid[regex/'],
                    ],
                    'value' => true,
                ],
            ]),
        ]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);

        // Should not throw, should return default
        $result = $ff->active('regex-flag', ['id' => 'user-1', 'email' => 'test@example.com']);

        $this->assertFalse($result);
    }

    public function test_invalid_date_in_rule_returns_false_gracefully(): void
    {
        $this->seedFlags([
            $this->flagWithRules('date-flag', [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'created_at', 'operator' => 'after_date', 'value' => 'not-a-valid-date'],
                    ],
                    'value' => true,
                ],
            ]),
        ]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);

        // Should not throw
        $result = $ff->active('date-flag', ['id' => 'user-1', 'created_at' => '2025-01-01']);

        $this->assertFalse($result);
    }

    public function test_circular_dependency_returns_default(): void
    {
        $this->seedFlags([
            [
                'key' => 'flag-a',
                'enabled' => true,
                'default_value' => 'a-default',
                'dependencies' => [
                    ['flag_key' => 'flag-b', 'required_value' => true],
                ],
                'rules' => [],
            ],
            [
                'key' => 'flag-b',
                'enabled' => true,
                'default_value' => 'b-default',
                'dependencies' => [
                    ['flag_key' => 'flag-a', 'required_value' => true],
                ],
                'rules' => [],
            ],
        ]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);

        // Should not hang, should return default due to circular dependency detection
        $result = $ff->value('flag-a');

        $this->assertEquals('a-default', $result);
    }

    public function test_missing_segment_returns_false(): void
    {
        $this->seedFlags([
            $this->flagWithRules('segment-flag', [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['type' => 'segment', 'segment' => 'nonexistent-segment'],
                    ],
                    'value' => true,
                ],
            ]),
        ]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);

        $result = $ff->active('segment-flag', ['id' => 'user-1']);

        $this->assertFalse($result);
    }

    public function test_null_context_with_rollout_returns_false(): void
    {
        $this->seedFlags([
            [
                'key' => 'rollout-flag',
                'enabled' => true,
                'default_value' => true,
                'rollout_percentage' => 50,
                'rules' => [],
            ],
        ]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);

        // Without context, rollout can't be evaluated
        $result = $ff->active('rollout-flag');

        // Should return false because we can't bucket without a context ID
        $this->assertFalse($result);
    }

    public function test_empty_rules_uses_default_value(): void
    {
        $this->seedFlags([
            [
                'key' => 'empty-rules',
                'enabled' => true,
                'default_value' => 'the-default',
                'rules' => [],
            ],
        ]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);

        $result = $ff->value('empty-rules', ['id' => 'user-1']);

        $this->assertEquals('the-default', $result);
    }

    public function test_missing_required_trait_in_condition_returns_false(): void
    {
        $this->seedFlags([
            $this->flagWithRules('trait-flag', [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'required_trait', 'operator' => 'equals', 'value' => 'expected'],
                    ],
                    'value' => true,
                ],
            ]),
        ]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);

        // Context doesn't have 'required_trait'
        $result = $ff->active('trait-flag', ['id' => 'user-1', 'other_trait' => 'value']);

        $this->assertFalse($result);
    }

    public function test_sync_clears_stale_segments_when_api_returns_empty(): void
    {
        // Seed initial segments
        $this->seedFlags(
            [$this->simpleFlag('test-flag', true)],
            [['key' => 'beta-users', 'conditions' => []]],
        );

        // Verify segment is cached
        $cache = $this->app->make(\FeatureFlags\Cache\FlagCache::class);
        $this->assertNotNull($cache->getSegment('beta-users'));

        // Mock API returning empty segments
        $this->mockApiResponse(
            [$this->simpleFlag('test-flag', true)],
            [], // Empty segments
        );

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);
        $ff->sync();

        // Segment should be cleared
        $this->assertNull($cache->getSegment('beta-users'));
    }

    /**
     * Helper to mock API failure.
     */
    private function mockApiFailure(string $message): void
    {
        $this->app->singleton(ApiClient::class, function () use ($message) {
            return new class ($message) extends ApiClient {
                private string $errorMessage;

                public function __construct(string $message)
                {
                    $this->errorMessage = $message;
                }

                public function fetchFlags(): array
                {
                    throw new ApiException($this->errorMessage);
                }

                public function sendTelemetry(array $events): void {}
                public function sendConversions(array $events): void {}
                public function sendErrors(array $errors): void {}
            };
        });
    }
}
