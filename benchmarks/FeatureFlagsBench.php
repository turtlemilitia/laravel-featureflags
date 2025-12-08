<?php

declare(strict_types=1);

namespace FeatureFlags\Benchmarks;

use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Client\ApiClient;
use FeatureFlags\Context;
use FeatureFlags\ContextResolver;
use FeatureFlags\FeatureFlags;
use FeatureFlags\Telemetry\ConversionCollector;
use FeatureFlags\Telemetry\ErrorCollector;
use FeatureFlags\Telemetry\TelemetryCollector;
use FeatureFlags\Tests\CreatesFeatureFlags;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use PhpBench\Attributes as Bench;

#[Bench\BeforeMethods('setUp')]
class FeatureFlagsBench
{
    use CreatesFeatureFlags;
    private FeatureFlags $featureFlags;
    private FeatureFlags $featureFlagsWithTelemetry;
    private FeatureFlags $featureFlagsWithFileCache;
    private FeatureFlags $featureFlagsWithAutoContext;
    private FlagCache $cache;
    private FlagCache $fileCache;
    private Context $context;
    private Container $app;
    /** @var array<string, mixed> */
    private array $mockApiResponse;

    public function setUp(): void
    {
        $this->bootstrapLaravel();

        $cacheStore = new Repository(new ArrayStore());
        $this->cache = new FlagCache($cacheStore, 'bench', 300);

        $fileCacheDir = sys_get_temp_dir() . '/featureflags-bench-' . uniqid();
        @mkdir($fileCacheDir, 0777, true);
        $fileCacheStore = new Repository(new FileStore(new Filesystem(), $fileCacheDir));
        $this->fileCache = new FlagCache($fileCacheStore, 'bench', 300);

        $this->mockApiResponse = $this->buildMockApiResponse();
        $this->seedFlags();

        $this->featureFlags = $this->createFeatureFlags($this->cache);

        $this->featureFlagsWithTelemetry = $this->createFeatureFlags(
            $this->cache,
            telemetry: $this->createEnabledTelemetry(),
        );

        $this->seedFileCache();
        $this->featureFlagsWithFileCache = $this->createFeatureFlags($this->fileCache);

        $this->setupMockAuth();
        $autoContextResolver = new ContextResolver([
            'auto_resolve' => true,
            'user_traits' => [
                'id' => 'id',
                'email' => 'email',
                'plan' => 'plan',
            ],
        ]);
        $this->featureFlagsWithAutoContext = $this->createFeatureFlags(
            $this->cache,
            contextResolver: $autoContextResolver,
        );

        $this->context = new Context('user-123', [
            'plan' => 'pro',
            'email' => 'user@company.com',
            'country' => 'US',
            'age' => 25,
            'app_version' => '2.5.0',
            'created_at' => '2025-01-15',
        ]);
    }

    private function bootstrapLaravel(): void
    {
        $this->app = new Container();
        Container::setInstance($this->app);

        $this->app->singleton('config', fn() => new ConfigRepository([
            'featureflags' => [
                'local' => ['enabled' => false, 'flags' => []],
                'fallback' => ['behavior' => 'cache', 'default_value' => false],
                'telemetry' => ['enabled' => false, 'batch_size' => 100],
            ],
        ]));

        Facade::setFacadeApplication($this->app);
    }

    private function seedFlags(): void
    {
        $flags = [
            ['key' => 'simple-boolean', 'enabled' => true, 'default_value' => true, 'rules' => []],
            ['key' => 'disabled-flag', 'enabled' => false, 'default_value' => false, 'rules' => []],
            ['key' => 'string-value', 'enabled' => true, 'default_value' => 'variant-a', 'rules' => []],
            [
                'key' => 'single-rule-equals',
                'enabled' => true,
                'default_value' => false,
                'rules' => [[
                    'priority' => 1,
                    'conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro']],
                    'value' => true,
                ]],
            ],
            [
                'key' => 'multi-condition-rule',
                'enabled' => true,
                'default_value' => false,
                'rules' => [[
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
                        ['trait' => 'country', 'operator' => 'in', 'value' => ['US', 'CA', 'UK']],
                        ['trait' => 'age', 'operator' => 'gte', 'value' => 18],
                    ],
                    'value' => true,
                ]],
            ],
            [
                'key' => 'multi-rule-priority',
                'enabled' => true,
                'default_value' => 'default',
                'rules' => [
                    ['priority' => 3, 'conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'free']], 'value' => 'free-value'],
                    ['priority' => 2, 'conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'basic']], 'value' => 'basic-value'],
                    ['priority' => 1, 'conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro']], 'value' => 'pro-value'],
                ],
            ],
            [
                'key' => 'contains-rule',
                'enabled' => true,
                'default_value' => false,
                'rules' => [[
                    'priority' => 1,
                    'conditions' => [['trait' => 'email', 'operator' => 'contains', 'value' => '@company.com']],
                    'value' => true,
                ]],
            ],
            [
                'key' => 'regex-rule',
                'enabled' => true,
                'default_value' => false,
                'rules' => [[
                    'priority' => 1,
                    'conditions' => [['trait' => 'email', 'operator' => 'matches_regex', 'value' => '/.*@company\.com$/']],
                    'value' => true,
                ]],
            ],
            [
                'key' => 'semver-rule',
                'enabled' => true,
                'default_value' => false,
                'rules' => [[
                    'priority' => 1,
                    'conditions' => [['trait' => 'app_version', 'operator' => 'semver_gte', 'value' => '2.0.0']],
                    'value' => true,
                ]],
            ],
            [
                'key' => 'date-rule',
                'enabled' => true,
                'default_value' => false,
                'rules' => [[
                    'priority' => 1,
                    'conditions' => [['trait' => 'created_at', 'operator' => 'after_date', 'value' => '2025-01-01']],
                    'value' => true,
                ]],
            ],
            [
                'key' => 'percentage-rule',
                'enabled' => true,
                'default_value' => false,
                'rules' => [[
                    'priority' => 1,
                    'conditions' => [['trait' => 'id', 'operator' => 'percentage_of', 'value' => 50]],
                    'value' => true,
                ]],
            ],
            ['key' => 'rollout-flag', 'enabled' => true, 'default_value' => true, 'rollout_percentage' => 50, 'rules' => []],
            [
                'key' => 'segment-flag',
                'enabled' => true,
                'default_value' => false,
                'rules' => [[
                    'priority' => 1,
                    'conditions' => [['type' => 'segment', 'segment' => 'pro-users']],
                    'value' => true,
                ]],
            ],
            [
                'key' => 'dependent-flag',
                'enabled' => true,
                'default_value' => 'fallback',
                'dependencies' => [['flag_key' => 'simple-boolean', 'required_value' => true]],
                'rules' => [],
            ],
            [
                'key' => 'complex-rules',
                'enabled' => true,
                'default_value' => 'default',
                'rules' => array_map(fn($i) => [
                    'priority' => $i,
                    'conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => "plan-{$i}"]],
                    'value' => "value-{$i}",
                ], range(1, 20)),
            ],
        ];

        $segments = [[
            'key' => 'pro-users',
            'name' => 'Pro Users',
            'rules' => [['conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro']]]],
        ]];

        $this->cache->put($flags, 300);
        $this->cache->putSegments($segments, 300);
    }

    private function createMockApiClient(): ApiClient
    {
        return new class extends ApiClient {
            public function __construct() {}
            public function fetchFlags(): array
            {
                return ['flags' => [], 'segments' => [], 'cache_ttl' => 300];
            }
            public function sendTelemetry(array $events): void {}
            public function sendConversions(array $events): void {}
            public function sendErrors(array $errors): void {}
        };
    }

    private function createMockTelemetry(): TelemetryCollector
    {
        return new class extends TelemetryCollector {
            public function __construct()
            {
                $this->enabled = false;
            }
            protected function send(array $events): void {}
            protected function getFailureMessage(): string
            {
                return '';
            }
        };
    }

    private function createEnabledTelemetry(): TelemetryCollector
    {
        return new class extends TelemetryCollector {
            public function __construct()
            {
                $this->enabled = true;
            }
            protected function send(array $events): void {}
            protected function getFailureMessage(): string
            {
                return '';
            }
            protected function getBatchSize(): int
            {
                return 100000;
            }
        };
    }

    private function createMockConversions(): ConversionCollector
    {
        return new class extends ConversionCollector {
            public function __construct()
            {
                $this->enabled = false;
            }
            protected function send(array $events): void {}
            protected function getFailureMessage(): string
            {
                return '';
            }
        };
    }

    private function createMockErrors(): ErrorCollector
    {
        return new class extends ErrorCollector {
            public function __construct()
            {
                $this->enabled = false;
            }
            protected function send(array $events): void {}
            protected function getFailureMessage(): string
            {
                return '';
            }
        };
    }

    /** @return array<string, mixed> */
    private function buildMockApiResponse(): array
    {
        return [
            'flags' => [
                ['key' => 'simple-boolean', 'enabled' => true, 'default_value' => true, 'rules' => []],
                ['key' => 'disabled-flag', 'enabled' => false, 'default_value' => false, 'rules' => []],
                ['key' => 'string-value', 'enabled' => true, 'default_value' => 'variant-a', 'rules' => []],
                [
                    'key' => 'single-rule-equals',
                    'enabled' => true,
                    'default_value' => false,
                    'rules' => [[
                        'priority' => 1,
                        'conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro']],
                        'value' => true,
                    ]],
                ],
                ['key' => 'rollout-flag', 'enabled' => true, 'default_value' => true, 'rollout_percentage' => 50, 'rules' => []],
            ],
            'segments' => [[
                'key' => 'pro-users',
                'name' => 'Pro Users',
                'rules' => [['conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro']]]],
            ]],
            'cache_ttl' => 300,
        ];
    }

    private function seedFileCache(): void
    {
        $this->fileCache->put($this->mockApiResponse['flags'], 300);
        $this->fileCache->putSegments($this->mockApiResponse['segments'], 300);
    }

    private function setupMockAuth(): void
    {
        $mockUser = new class implements Authenticatable {
            public string $id = 'user-123';
            public string $email = 'user@company.com';
            public string $plan = 'pro';

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }
            public function getAuthIdentifier(): mixed
            {
                return $this->id;
            }
            public function getAuthPasswordName(): string
            {
                return 'password';
            }
            public function getAuthPassword(): string
            {
                return '';
            }
            public function getRememberToken(): ?string
            {
                return null;
            }
            public function setRememberToken($value): void {}
            public function getRememberTokenName(): string
            {
                return '';
            }
        };

        $this->app->singleton('auth', function () use ($mockUser) {
            return new class ($mockUser) {
                public function __construct(private Authenticatable $user) {}
                public function user(): ?Authenticatable
                {
                    return $this->user;
                }
                public function check(): bool
                {
                    return true;
                }
            };
        });
    }

    private function createSyncApiClient(): ApiClient
    {
        $response = $this->mockApiResponse;
        return new class ($response) extends ApiClient {
            /** @param array<string, mixed> $response */
            public function __construct(private array $response) {}
            public function fetchFlags(): array
            {
                return $this->response;
            }
            public function sendTelemetry(array $events): void {}
            public function sendConversions(array $events): void {}
            public function sendErrors(array $errors): void {}
        };
    }

    // =========================================================================
    // CORE BENCHMARKS WITH REGRESSION ASSERTIONS
    // These have strict thresholds to catch performance regressions
    // =========================================================================

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 15 microseconds')]
    public function benchSimpleBooleanFlagNoRules(): void
    {
        $this->featureFlags->active('simple-boolean', $this->context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 15 microseconds')]
    public function benchSimpleBooleanFlagWithContext(): void
    {
        $this->featureFlags->active('simple-boolean', $this->context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 15 microseconds')]
    public function benchDisabledFlag(): void
    {
        $this->featureFlags->active('disabled-flag', $this->context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 15 microseconds')]
    public function benchStringValue(): void
    {
        $this->featureFlags->value('string-value', $this->context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 20 microseconds')]
    public function benchUnknownFlag(): void
    {
        $this->featureFlags->active('nonexistent-flag', $this->context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 20 microseconds')]
    public function benchSingleRuleEquals(): void
    {
        $this->featureFlags->active('single-rule-equals', $this->context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 20 microseconds')]
    public function benchMultiConditionRule(): void
    {
        $this->featureFlags->active('multi-condition-rule', $this->context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 20 microseconds')]
    public function benchMultiRulePriority(): void
    {
        $this->featureFlags->value('multi-rule-priority', $this->context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 20 microseconds')]
    public function benchContainsOperator(): void
    {
        $this->featureFlags->active('contains-rule', $this->context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 20 microseconds')]
    public function benchRegexOperator(): void
    {
        $this->featureFlags->active('regex-rule', $this->context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 20 microseconds')]
    public function benchSemverOperator(): void
    {
        $this->featureFlags->active('semver-rule', $this->context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 25 microseconds')]
    public function benchDateOperator(): void
    {
        $this->featureFlags->active('date-rule', $this->context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 20 microseconds')]
    public function benchPercentageOfOperator(): void
    {
        $context = new Context('user-123', ['id' => 'user-123']);
        $this->featureFlags->active('percentage-rule', $context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 15 microseconds')]
    public function benchRolloutPercentage(): void
    {
        $this->featureFlags->active('rollout-flag', $this->context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 25 microseconds')]
    public function benchSegmentEvaluation(): void
    {
        $this->featureFlags->active('segment-flag', $this->context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 30 microseconds')]
    public function benchDependencyEvaluation(): void
    {
        $this->featureFlags->value('dependent-flag', $this->context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 50 microseconds')]
    public function benchComplexRulesNoMatch(): void
    {
        $this->featureFlags->value('complex-rules', $this->context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    public function benchContextCreation(): void
    {
        new Context('user-456', [
            'plan' => 'enterprise',
            'email' => 'admin@bigcorp.com',
            'country' => 'DE',
            'age' => 35,
        ]);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    public function benchArrayContextNormalization(): void
    {
        $this->featureFlags->active('simple-boolean', ['id' => 'user-789', 'plan' => 'basic']);
    }

    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    public function benchAllFlags(): void
    {
        $this->featureFlags->all();
    }

    // =========================================================================
    // REAL-WORLD SCENARIO BENCHMARKS WITH ASSERTIONS
    // =========================================================================

    #[Bench\Revs(5000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchTypicalRequestFiveFlags(): void
    {
        $this->featureFlags->active('simple-boolean', $this->context);
        $this->featureFlags->active('single-rule-equals', $this->context);
        $this->featureFlags->value('string-value', $this->context);
        $this->featureFlags->active('rollout-flag', $this->context);
        $this->featureFlags->active('segment-flag', $this->context);
    }

    #[Bench\Revs(2000)]
    #[Bench\Iterations(5)]
    #[Bench\Assert('mode(variant.time.avg) < 200 microseconds')]
    public function benchHeavyRequestTenFlags(): void
    {
        $this->featureFlags->active('simple-boolean', $this->context);
        $this->featureFlags->active('disabled-flag', $this->context);
        $this->featureFlags->active('single-rule-equals', $this->context);
        $this->featureFlags->active('multi-condition-rule', $this->context);
        $this->featureFlags->value('multi-rule-priority', $this->context);
        $this->featureFlags->active('regex-rule', $this->context);
        $this->featureFlags->active('semver-rule', $this->context);
        $this->featureFlags->active('rollout-flag', $this->context);
        $this->featureFlags->active('segment-flag', $this->context);
        $this->featureFlags->value('dependent-flag', $this->context);
    }

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    public function benchWithTelemetrySimpleFlag(): void
    {
        $this->featureFlagsWithTelemetry->active('simple-boolean', $this->context);
    }

    #[Bench\Revs(5000)]
    #[Bench\Iterations(5)]
    public function benchWithTelemetryFiveFlags(): void
    {
        $this->featureFlagsWithTelemetry->active('simple-boolean', $this->context);
        $this->featureFlagsWithTelemetry->active('single-rule-equals', $this->context);
        $this->featureFlagsWithTelemetry->value('string-value', $this->context);
        $this->featureFlagsWithTelemetry->active('rollout-flag', $this->context);
        $this->featureFlagsWithTelemetry->active('segment-flag', $this->context);
    }

    // =========================================================================
    // FILE CACHE BENCHMARKS - Simulates disk I/O overhead
    // =========================================================================

    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    public function benchFileCacheSimpleFlag(): void
    {
        $this->featureFlagsWithFileCache->active('simple-boolean', $this->context);
    }

    #[Bench\Revs(500)]
    #[Bench\Iterations(5)]
    public function benchFileCacheFiveFlags(): void
    {
        $this->featureFlagsWithFileCache->active('simple-boolean', $this->context);
        $this->featureFlagsWithFileCache->active('single-rule-equals', $this->context);
        $this->featureFlagsWithFileCache->value('string-value', $this->context);
        $this->featureFlagsWithFileCache->active('rollout-flag', $this->context);
        $this->featureFlagsWithFileCache->active('disabled-flag', $this->context);
    }

    // =========================================================================
    // AUTO-CONTEXT RESOLUTION BENCHMARKS - Auth::user() overhead
    // =========================================================================

    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    public function benchAutoContextResolution(): void
    {
        $this->featureFlagsWithAutoContext->active('simple-boolean');
    }

    #[Bench\Revs(5000)]
    #[Bench\Iterations(5)]
    public function benchAutoContextFiveFlags(): void
    {
        $this->featureFlagsWithAutoContext->active('simple-boolean');
        $this->featureFlagsWithAutoContext->active('single-rule-equals');
        $this->featureFlagsWithAutoContext->value('string-value');
        $this->featureFlagsWithAutoContext->active('rollout-flag');
        $this->featureFlagsWithAutoContext->active('disabled-flag');
    }

    // =========================================================================
    // SYNC OPERATION BENCHMARKS - API response parsing & caching
    // =========================================================================

    #[Bench\Revs(500)]
    #[Bench\Iterations(5)]
    public function benchSyncFromApi(): void
    {
        $tempCache = new FlagCache(
            new Repository(new ArrayStore()),
            'sync-bench',
            300,
        );

        $ff = $this->createFeatureFlags($tempCache, apiClient: $this->createSyncApiClient());
        $ff->sync();
    }

    #[Bench\Revs(500)]
    #[Bench\Iterations(5)]
    public function benchColdStartSyncAndEvaluate(): void
    {
        $tempCache = new FlagCache(
            new Repository(new ArrayStore()),
            'cold-bench',
            300,
        );

        $ff = $this->createFeatureFlags($tempCache, apiClient: $this->createSyncApiClient());
        $ff->sync();
        $ff->active('simple-boolean', $this->context);
        $ff->active('single-rule-equals', $this->context);
        $ff->value('string-value', $this->context);
    }

    // =========================================================================
    // CACHE INDEX REBUILD - Measures index reconstruction overhead
    // =========================================================================

    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    public function benchCacheIndexRebuild(): void
    {
        $tempCache = new FlagCache(
            new Repository(new ArrayStore()),
            'index-bench',
            300,
        );
        $tempCache->put($this->mockApiResponse['flags'], 300);
        $tempCache->putSegments($this->mockApiResponse['segments'], 300);

        $tempCache->get('simple-boolean');
    }

    private function createFeatureFlags(
        FlagCache $cache,
        ?ApiClient $apiClient = null,
        ?ContextResolver $contextResolver = null,
        ?TelemetryCollector $telemetry = null,
    ): FeatureFlags {
        return $this->createFeatureFlagsInstance(
            apiClient: $apiClient ?? $this->createMockApiClient(),
            cache: $cache,
            contextResolver: $contextResolver ?? new ContextResolver(['auto_resolve' => false, 'user_traits' => []]),
            telemetry: $telemetry ?? $this->createMockTelemetry(),
            conversions: $this->createMockConversions(),
            errors: $this->createMockErrors(),
        );
    }
}
