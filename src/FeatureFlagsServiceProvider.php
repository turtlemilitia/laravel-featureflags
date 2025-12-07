<?php

declare(strict_types=1);

namespace FeatureFlags;

use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Client\ApiClient;
use FeatureFlags\Contracts\FeatureFlagsInterface;
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
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
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

        $this->app->singleton(ApiClient::class, function (): ApiClient {
            $url = config('featureflags.api_url', 'https://api.turtlemilitia.com/v1');
            $key = config('featureflags.api_key');
            $timeout = config('featureflags.sync.timeout', 5);
            $verifySsl = config('featureflags.sync.verify_ssl', true);

            return new ApiClient(
                is_string($url) ? $url : 'https://api.turtlemilitia.com/v1',
                is_string($key) ? $key : null,
                is_int($timeout) ? $timeout : 5,
                is_bool($verifySsl) ? $verifySsl : true,
            );
        });

        $this->app->singleton(FlagCache::class, function (Application $app): FlagCache {
            $store = config('featureflags.cache.store');
            $prefix = config('featureflags.cache.prefix', 'featureflags');
            $ttl = config('featureflags.cache.ttl', 300);

            /** @var CacheFactory $cacheFactory */
            $cacheFactory = $app->make(CacheFactory::class);

            return new FlagCache(
                $cacheFactory->store(is_string($store) ? $store : null),
                is_string($prefix) ? $prefix : 'featureflags',
                is_int($ttl) ? $ttl : 300,
            );
        });

        $this->app->singleton(ContextResolver::class);

        $this->app->singleton(TelemetryCollector::class, function (Application $app): TelemetryCollector {
            return new TelemetryCollector($app->make(ApiClient::class));
        });

        $this->app->singleton(ConversionCollector::class, function (Application $app): ConversionCollector {
            return new ConversionCollector($app->make(ApiClient::class));
        });

        $this->app->singleton(ErrorCollector::class, function (Application $app): ErrorCollector {
            return new ErrorCollector($app->make(ApiClient::class));
        });

        $this->app->singleton(FlagStateTracker::class, fn(): FlagStateTracker => new FlagStateTracker());

        $this->app->singleton(OperatorEvaluator::class, fn(): OperatorEvaluator => new OperatorEvaluator());

        $this->app->singleton(FeatureFlagsConfig::class, function (Application $app): FeatureFlagsConfig {
            $cacheEnabled = config('featureflags.cache.enabled', true);

            return new FeatureFlagsConfig(
                $app->make(ApiClient::class),
                $app->make(FlagCache::class),
                $app->make(ContextResolver::class),
                $app->make(TelemetryCollector::class),
                $app->make(ConversionCollector::class),
                $app->make(ErrorCollector::class),
                $app->make(FlagStateTracker::class),
                $app->make(OperatorEvaluator::class),
                (bool) $cacheEnabled,
            );
        });

        $this->app->singleton(FeatureFlags::class, function (Application $app): FeatureFlags {
            return new FeatureFlags($app->make(FeatureFlagsConfig::class));
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

        $path = config('featureflags.webhook.path', '/webhooks/feature-flags');

        Route::post(is_string($path) ? $path : '/webhooks/feature-flags', WebhookController::class)
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
            Queue::after(function (JobProcessed $event): void {
                $this->flushTelemetryAndReset();
            });

            Queue::failing(function (JobFailed $event): void {
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
