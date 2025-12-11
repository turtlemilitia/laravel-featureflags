<?php

declare(strict_types=1);

namespace FeatureFlags;

use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Client\ApiClient;
use FeatureFlags\Config\ConfigHelper;
use FeatureFlags\Contracts\FeatureFlagsInterface;
use FeatureFlags\Evaluation\ContextNormalizer;
use FeatureFlags\Evaluation\FlagEvaluator;
use FeatureFlags\Evaluation\OperatorEvaluator;
use FeatureFlags\Http\Controllers\WebhookController;
use FeatureFlags\Http\Middleware\FlushTelemetry;
use FeatureFlags\Telemetry\ConversionCollector;
use FeatureFlags\Telemetry\ErrorCollector;
use FeatureFlags\Telemetry\FlagStateTracker;
use FeatureFlags\Telemetry\TelemetryCollector;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FeatureFlagsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/featureflags.php', 'featureflags');

        $this->app->singleton(ApiClient::class, fn(): ApiClient => new ApiClient(
            ConfigHelper::string('featureflags.api_url', 'https://api.turtlemilitia.com/v1'),
            ConfigHelper::stringOrNull('featureflags.api_key'),
            ConfigHelper::int('featureflags.sync.timeout', 5),
        ));

        $this->app->singleton(FlagCache::class, function (Application $app): FlagCache {
            /** @var CacheFactory $cacheFactory */
            $cacheFactory = $app->make(CacheFactory::class);

            return new FlagCache(
                $cacheFactory->store(ConfigHelper::stringOrNull('featureflags.cache.store')),
                ConfigHelper::string('featureflags.cache.prefix', 'featureflags'),
                ConfigHelper::int('featureflags.cache.ttl', 300),
            );
        });

        $this->app->singleton(ContextResolver::class);

        $this->app->singleton(TelemetryCollector::class, function (Application $app): TelemetryCollector {
            return new TelemetryCollector($app->make(ApiClient::class));
        });

        $this->app->singleton(FlagStateTracker::class, fn(): FlagStateTracker => new FlagStateTracker());

        $this->app->singleton(ConversionCollector::class, function (Application $app): ConversionCollector {
            return new ConversionCollector(
                $app->make(ApiClient::class),
                $app->make(FlagStateTracker::class),
            );
        });

        $this->app->singleton(ErrorCollector::class, function (Application $app): ErrorCollector {
            return new ErrorCollector(
                $app->make(ApiClient::class),
                $app->make(FlagStateTracker::class),
            );
        });

        $this->app->singleton(OperatorEvaluator::class, fn(): OperatorEvaluator => new OperatorEvaluator());

        $this->app->singleton(ContextNormalizer::class, function (Application $app): ContextNormalizer {
            return new ContextNormalizer($app->make(ContextResolver::class));
        });

        $this->app->singleton(FlagEvaluator::class, function (Application $app): FlagEvaluator {
            return new FlagEvaluator(
                $app->make(FlagCache::class),
                $app->make(OperatorEvaluator::class),
            );
        });

        $this->app->singleton(FlagService::class, fn(Application $app): FlagService => new FlagService(
            $app->make(FlagCache::class),
            $app->make(ApiClient::class),
            $app->make(FlagEvaluator::class),
            $app->make(ContextNormalizer::class),
            $app->make(TelemetryCollector::class),
            $app->make(FlagStateTracker::class),
            ConfigHelper::bool('featureflags.cache.enabled', true),
        ));

        $this->app->singleton(FeatureFlags::class, function (Application $app): FeatureFlags {
            return new FeatureFlags(
                $app->make(FlagService::class),
                $app->make(ConversionCollector::class),
                $app->make(ErrorCollector::class),
            );
        });

        $this->app->bind(FeatureFlagsInterface::class, FeatureFlags::class);

        $this->app->alias(FeatureFlags::class, 'featureflags');
    }

    public function boot(): void
    {
        $this->registerBladeDirectives();
        $this->registerWebhookRoute();
        $this->registerTelemetryMiddleware();
        $this->registerQueueListeners();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/featureflags.php' => config_path('featureflags.php'),
            ], 'featureflags-config');

            $this->commands([
                Console\SyncCommand::class,
                Console\DumpCommand::class,
                Console\WarmCommand::class,
            ]);
        }

        if (config('featureflags.sync.on_boot') && config('featureflags.api_key')) {
            $this->app->make(FeatureFlags::class)->sync();
        }
    }

    private function registerBladeDirectives(): void
    {
        Blade::if('feature', function (string $key, mixed $context = null): bool {
            if ($context !== null
                && !($context instanceof Context)
                && !($context instanceof Contracts\HasFeatureFlagContext)
                && !is_array($context)
            ) {
                return false;
            }

            /** @var Context|Contracts\HasFeatureFlagContext|array<string, mixed>|null $typedContext */
            $typedContext = $context;

            return $this->app->make(FeatureFlags::class)->active($key, $typedContext);
        });
    }

    private function registerWebhookRoute(): void
    {
        if (!config('featureflags.webhook.enabled')) {
            return;
        }

        if (!config('featureflags.webhook.secret')) {
            Log::warning(
                'Feature flags webhook is enabled but no secret is configured. '
                . 'All webhook requests will be rejected. '
                . 'Set FEATUREFLAGS_WEBHOOK_SECRET in your environment.',
            );
        }

        Route::post(ConfigHelper::string('featureflags.webhook.path', '/webhooks/feature-flags'), WebhookController::class)
            ->middleware('api')
            ->name('featureflags.webhook');
    }

    private function registerTelemetryMiddleware(): void
    {
        if (!config('featureflags.telemetry.enabled')) {
            return;
        }

        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $kernel->pushMiddleware(FlushTelemetry::class);
    }

    private function registerQueueListeners(): void
    {
        try {
            Queue::after(function (): void {
                $this->flushTelemetryAndReset();
            });

            Queue::failing(function (): void {
                $this->flushTelemetryAndReset();
            });

            Queue::looping(function (): void {
                $this->flushTelemetryAndReset();
            });
        } catch (\Throwable $e) {
            Log::warning('Feature Flags queue listeners could not be registered (queue not available).', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function flushTelemetryAndReset(): void
    {
        try {
            $this->app->make(FeatureFlags::class)->flushAllTelemetryAndReset();
        } catch (\Throwable $e) {
            Log::warning('Feature Flags telemetry flush failed in queue context; continuing.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
