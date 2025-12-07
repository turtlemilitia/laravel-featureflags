<?php

declare(strict_types=1);

namespace FeatureFlags\Tests\Feature;

use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Client\ApiClient;
use FeatureFlags\FeatureFlags;
use FeatureFlags\FeatureFlagsConfig;
use FeatureFlags\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;

abstract class FeatureTestCase extends TestCase
{
    protected MockHandler $mockHandler;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('featureflags.cache.enabled', true);
        $app['config']->set('featureflags.local.enabled', false);
    }

    /**
     * Seed flags directly into the cache for testing.
     *
     * @param array<int, array<string, mixed>> $flags
     * @param array<int, array<string, mixed>> $segments
     */
    protected function seedFlags(array $flags, array $segments = []): void
    {
        /** @var FlagCache $cache */
        $cache = $this->app->make(FlagCache::class);
        $cache->put($flags, 300);

        if (!empty($segments)) {
            $cache->putSegments($segments, 300);
        }
    }

    /**
     * Create a mock API response for flag sync.
     *
     * @param array<int, array<string, mixed>> $flags
     * @param array<int, array<string, mixed>> $segments
     */
    protected function mockApiResponse(array $flags, array $segments = [], int $ttl = 300): void
    {
        $responseBody = json_encode([
            'flags' => $flags,
            'segments' => $segments,
            'cache_ttl' => $ttl,
        ]) ?: '{}';

        // Queue multiple responses in case sync is called multiple times
        $this->mockHandler = new MockHandler([
            new Response(200, [], $responseBody),
            new Response(200, [], $responseBody),
            new Response(200, [], $responseBody),
        ]);

        $handlerStack = HandlerStack::create($this->mockHandler);
        $client = new Client(['handler' => $handlerStack]);

        // Forget any existing instances that depend on ApiClient
        $this->app->forgetInstance(ApiClient::class);
        $this->app->forgetInstance(FeatureFlagsConfig::class);
        $this->app->forgetInstance(FeatureFlags::class);

        $this->app->singleton(ApiClient::class, function () use ($client) {
            return new class ($client) extends ApiClient {
                private Client $httpClient;

                public function __construct(Client $client)
                {
                    $this->httpClient = $client;
                }

                public function fetchFlags(): array
                {
                    $response = $this->httpClient->get('api/flags');
                    /** @var array{flags: array<int, array<string, mixed>>, segments: array<int, array<string, mixed>>, cache_ttl: int} $data */
                    $data = json_decode($response->getBody()->getContents(), true);

                    return [
                        'flags' => $data['flags'] ?? [],
                        'segments' => $data['segments'] ?? [],
                        'cache_ttl' => $data['cache_ttl'] ?? 300,
                    ];
                }

                public function sendTelemetry(array $events): void {}
                public function sendConversions(array $events): void {}
                public function sendErrors(array $errors): void {}
            };
        });
    }

    /**
     * Get a simple test flag configuration.
     *
     * @return array<string, mixed>
     */
    protected function simpleFlag(string $key, bool $enabled = true, mixed $defaultValue = true): array
    {
        return [
            'key' => $key,
            'enabled' => $enabled,
            'default_value' => $defaultValue,
            'rules' => [],
        ];
    }

    /**
     * Get a flag with targeting rules.
     *
     * @param array<int, array<string, mixed>> $rules
     * @return array<string, mixed>
     */
    protected function flagWithRules(string $key, array $rules, mixed $defaultValue = false): array
    {
        return [
            'key' => $key,
            'enabled' => true,
            'default_value' => $defaultValue,
            'rules' => $rules,
        ];
    }
}
