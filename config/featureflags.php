<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Feature Flags API Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the connection to your Feature Flags service.
    |
    */

    'api_url' => env('FEATUREFLAGS_API_URL', 'https://api.turtlemilitia.com/v1'),

    'api_key' => env('FEATUREFLAGS_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Flags are cached locally to avoid API calls on every check.
    | The cache TTL from the API response will be used, but you can
    | set a fallback here.
    |
    */

    'cache' => [
        'enabled' => env('FEATUREFLAGS_CACHE_ENABLED', true),

        'store' => env('FEATUREFLAGS_CACHE_STORE', null), // null = default cache store

        'prefix' => 'featureflags',

        'ttl' => env('FEATUREFLAGS_CACHE_TTL', 300), // fallback TTL in seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback Behavior
    |--------------------------------------------------------------------------
    |
    | Configure what happens when the API is unreachable.
    |
    | Options:
    | - 'cache': Use last known cached values indefinitely
    | - 'default': Fall back to the default_value below
    | - 'exception': Throw an exception so the app can handle explicitly
    |
    */

    'fallback' => [
        'behavior' => env('FEATUREFLAGS_FALLBACK', 'cache'),

        'default_value' => false, // Used when behavior is 'default'
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Auto-Resolution
    |--------------------------------------------------------------------------
    |
    | Automatically resolve context from the authenticated user when no
    | context is explicitly passed to Feature::active().
    |
    | To enable, implement HasFeatureFlagContext on your User model:
    |
    |     use FeatureFlags\Contracts\HasFeatureFlagContext;
    |
    |     class User extends Authenticatable implements HasFeatureFlagContext
    |     {
    |         public function toFeatureFlagContext(): array
    |         {
    |             return [
    |                 'id' => $this->id,
    |                 'email' => $this->email,
    |                 'plan' => $this->subscription?->plan,
    |             ];
    |         }
    |     }
    |
    */

    'context' => [
        'auto_resolve' => env('FEATUREFLAGS_AUTO_CONTEXT', true),

        /*
        |--------------------------------------------------------------------------
        | Version Resolution for Semver Targeting
        |--------------------------------------------------------------------------
        |
        | When your Laravel app serves as an API backend for mobile apps or
        | versioned frontends, you can target features based on client version
        | using semver operators (semver_gt, semver_gte, semver_lt, semver_lte).
        |
        | Create a class implementing FeatureFlags\Contracts\ResolvesVersion
        | that returns an array of trait names mapped to version strings:
        |
        |     use FeatureFlags\Contracts\ResolvesVersion;
        |
        |     class VersionResolver implements ResolvesVersion
        |     {
        |         public function resolve(): array
        |         {
        |             return [
        |                 // From client request header (mobile apps, SPAs)
        |                 'client_version' => request()->header('X-App-Version'),
        |
        |                 // From config/environment
        |                 'api_version' => config('app.api_version'),
        |
        |                 // With fallback logic
        |                 'app_version' => request()->header('X-App-Version')
        |                     ?? config('app.version'),
        |             ];
        |         }
        |     }
        |
        | Each trait can be used independently in targeting rules. Null values
        | won't match semver operators, so use fallback logic if needed.
        |
        | The resolved traits are automatically merged into every context.
        | Set to null to disable automatic version resolution.
        |
        */

        'version_resolver' => null, // e.g., \App\FeatureFlags\VersionResolver::class
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Enable webhook endpoint to receive instant cache invalidation
    | when flags are updated in the dashboard.
    |
    */

    'webhook' => [
        'enabled' => env('FEATUREFLAGS_WEBHOOK_ENABLED', false),

        'path' => '/webhooks/feature-flags',

        'secret' => env('FEATUREFLAGS_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry Configuration
    |--------------------------------------------------------------------------
    |
    | Send flag evaluation data back to the dashboard for analytics.
    | This enables features like evaluation counts, user lookup, and
    | rollout percentage validation.
    |
    */

    'telemetry' => [
        'enabled' => env('FEATUREFLAGS_TELEMETRY_ENABLED', false),

        'batch_size' => 100, // Flush evaluations after this many events

        'error_batch_size' => 10, // Flush errors after this many events

        'retry_on_failure' => false, // Re-queue events if send fails

        /*
        |----------------------------------------------------------------------
        | Sampling Rate
        |----------------------------------------------------------------------
        |
        | Record only a percentage of evaluations to reduce telemetry volume.
        | Value between 0.0 (record nothing) and 1.0 (record everything).
        | At 0.1, only 10% of evaluations are recorded.
        |
        */
        'sample_rate' => env('FEATUREFLAGS_TELEMETRY_SAMPLE_RATE', 1.0),

        /*
        |----------------------------------------------------------------------
        | Rate Limiting
        |----------------------------------------------------------------------
        |
        | Limit the number of telemetry flushes per minute to prevent
        | overwhelming the API on high-traffic sites.
        |
        */
        'rate_limit' => [
            'enabled' => env('FEATUREFLAGS_TELEMETRY_RATE_LIMIT', false),
            'max_flushes_per_minute' => 60, // Max API calls per minute
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic Error Tracking
    |--------------------------------------------------------------------------
    |
    | When enabled, errors that occur during requests are automatically
    | correlated with all feature flags that were evaluated. This provides
    | similar functionality to LaunchDarkly's error tracking without
    | requiring manual Feature::trackError() calls.
    |
    | To enable automatic tracking:
    | 1. Set 'enabled' to true
    | 2. Register the ErrorTrackingServiceProvider in config/app.php
    |
    */

    'error_tracking' => [
        'enabled' => env('FEATUREFLAGS_ERROR_TRACKING_ENABLED', true),

        // Exception classes to skip (not worth correlating with flags)
        'skip_exceptions' => [
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
            \Symfony\Component\HttpKernel\Exception\HttpException::class,
            \Illuminate\Validation\ValidationException::class,
            \Illuminate\Auth\AuthenticationException::class,
            \Illuminate\Auth\Access\AuthorizationException::class,
            \Illuminate\Session\TokenMismatchException::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how flags are synced from the API.
    |
    */

    'sync' => [
        'on_boot' => env('FEATUREFLAGS_SYNC_ON_BOOT', false),

        'timeout' => 5, // HTTP timeout in seconds

        'verify_ssl' => true,

        // Circuit breaker prevents hammering the API when it's down
        'circuit_breaker' => [
            'enabled' => true,
            'failure_threshold' => 5,
            'cooldown_seconds' => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Local Development Mode
    |--------------------------------------------------------------------------
    |
    | Enable local mode to define flag values directly in config without
    | needing a connection to the Feature Flags API. Useful for local
    | development and testing.
    |
    | When enabled, the API is never called and all flag values come from
    | the 'flags' array below.
    |
    */

    'local' => [
        'enabled' => env('FEATUREFLAGS_LOCAL_MODE', false),

        // Define your flags here when local mode is enabled
        // Boolean flags: 'flag-key' => true,
        // String/number flags: 'flag-key' => 'value',
        // With rollout: 'flag-key' => ['value' => true, 'rollout' => 50],
        'flags' => [
            // 'new-checkout' => true,
            // 'welcome-message' => 'Hello, World!',
            // 'max-items' => 100,
            // 'beta-features' => ['value' => true, 'rollout' => 25],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability Events
    |--------------------------------------------------------------------------
    |
    | Dispatch Laravel events for flag evaluations, syncs, and telemetry
    | flushes. Useful for APM integration (Datadog, New Relic), debugging,
    | and Laravel Telescope.
    |
    | Events dispatched:
    | - FlagEvaluated: Every flag check (can be high volume!)
    | - FlagSyncCompleted: When flags are synced from API
    | - TelemetryFlushed: When telemetry is sent to the dashboard
    |
    */

    'events' => [
        'enabled' => env('FEATUREFLAGS_EVENTS_ENABLED', false),

        // Individually toggle which events to dispatch
        'dispatch' => [
            'flag_evaluated' => true,
            'flag_sync_completed' => true,
            'telemetry_flushed' => true,
        ],
    ],
];
