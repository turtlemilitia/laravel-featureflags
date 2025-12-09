# Laravel Feature Flags

A Laravel package for feature flag evaluation with local caching. Designed to work with
the [Turtle Militia](https://turtlemilitia.com) feature flags dashboard.

**Requirements:** PHP 8.2+ and Laravel 11 or 12.

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Usage](#usage)
- [Targeting Rules](#targeting-rules)
- [Percentage Rollouts](#percentage-rollouts)
- [Segments](#segments)
- [Webhooks](#webhooks)
- [Fallback Behavior](#fallback-behavior)
- [Conversion Tracking](#conversion-tracking)
- [Error Tracking](#error-tracking)
- [Local Development & Testing](#local-development--testing)
- [Observability](#observability)
- [Performance](#performance)
- [GDPR Compliance](#gdpr-compliance)

## Features

- **Local evaluation** - Flags are evaluated locally, no API call per check
- **Smart caching** - Syncs flag configuration and caches locally
- **Targeting rules** - User-based targeting with flexible conditions
- **Percentage rollouts** - Gradual rollouts with sticky bucketing
- **Segments** - Reusable user groups
- **Telemetry** - Evaluation tracking sent to dashboard
- **Error tracking** - Correlate errors with feature flags (Sentry, Bugsnag, Flare)
- **Conversion tracking** - A/B test analysis
- **Blade directives** - `@feature` directive for templates
- **Auto-context** - Automatically resolves authenticated user context
- **Cache warming** - `featureflags:warm` command for deployments
- **Events** - Optional Laravel events for monitoring
- **GDPR compliant** - Hold telemetry until user consent

## Installation

```bash
composer require turtlemilitia/laravel-featureflags
```

## Quick Start

Add your API credentials to `.env`:

```env
FEATUREFLAGS_API_URL=https://api.turtlemilitia.com/v1
FEATUREFLAGS_API_KEY=your-environment-api-key
```

Check a flag:

```php
use FeatureFlags\Facades\Feature;

if (Feature::active('dark-mode')) {
    // Show dark mode UI
}
```

## Configuration

Most settings work via environment variables:

| Variable                         | Description                        |
|----------------------------------|------------------------------------|
| `FEATUREFLAGS_API_URL`           | API endpoint                       |
| `FEATUREFLAGS_API_KEY`           | Your environment API key           |
| `FEATUREFLAGS_WEBHOOK_ENABLED`   | Enable webhook endpoint            |
| `FEATUREFLAGS_WEBHOOK_SECRET`    | Webhook signature secret           |
| `FEATUREFLAGS_TELEMETRY_ENABLED` | Send evaluation data to dashboard  |
| `FEATUREFLAGS_LOCAL_MODE`        | Use locally-defined flags          |
| `FEATUREFLAGS_HOLD_UNTIL_CONSENT`| Hold telemetry until user consents |
| `FEATUREFLAGS_CONSENT_TTL_DAYS`  | Days before consent expires (365)  |

## Usage

```php
use FeatureFlags\Facades\Feature;

// Check if a flag is active (boolean)
if (Feature::active('dark-mode')) {
    // Show dark mode UI
}

// Get flag value (supports string, number, JSON)
$limit = Feature::value('api-rate-limit');

// Get all flags
$flags = Feature::all();

// Monitor critical code paths (tracks errors automatically)
$result = Feature::monitor('new-payment-flow', fn ($enabled) =>
    $enabled ? $this->newPayment() : $this->legacyPayment()
);

// Track conversions for A/B analysis
Feature::trackConversion('purchase', $user, ['revenue' => 99.99]);
```

In Blade templates:

```blade
@feature('new-dashboard')
    <x-new-dashboard />
@else
    <x-old-dashboard />
@endfeature

@feature('premium-feature', $team)
    ...
@endfeature
```

### Context

Context is resolved automatically from the authenticated user. Implement `HasFeatureFlagContext` on your User model:

```php
use FeatureFlags\Contracts\HasFeatureFlagContext;

class User extends Authenticatable implements HasFeatureFlagContext
{
    public function toFeatureFlagContext(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'plan' => $this->subscription?->plan,
        ];
    }
}
```

Any model can implement the interface and be passed as context:

```php
// Team, Organization, etc.
Feature::active('premium-feature', $team);
```

You can also pass context explicitly:

```php
// Array shorthand
Feature::active('new-checkout', [
    'id' => 'user-123',
    'plan' => 'pro',
]);

// Context object
$context = new Context('user-123', ['plan' => 'pro']);
Feature::active('new-checkout', $context);
```

### Nested Traits

Context supports nested arrays with dot notation in targeting rules. This is useful for targeting based on
relationships:

```php
public function toFeatureFlagContext(): array
{
    return [
        'id' => $this->id,
        'subscription' => [
            'plan' => [
                'name' => $this->subscription?->plan?->name,
                'tier' => $this->subscription?->plan?->tier,
            ],
            'status' => $this->subscription?->status,
        ],
    ];
}
```

Then target with dot notation in your rules: `subscription.plan.name equals "pro"` or
`subscription.status equals "active"`.

## Targeting Rules

The dashboard supports these operators for targeting rules:

| Operator        | Description                    | Example                                  |
|-----------------|--------------------------------|------------------------------------------|
| `equals`        | Exact match                    | `plan equals "pro"`                      |
| `not_equals`    | Not equal                      | `plan not_equals "free"`                 |
| `contains`      | String contains                | `email contains "@company.com"`          |
| `not_contains`  | String does not contain        | `email not_contains "test"`              |
| `gt`            | Greater than                   | `age gt 18`                              |
| `gte`           | Greater than or equal          | `orders gte 10`                          |
| `lt`            | Less than                      | `balance lt 100`                         |
| `lte`           | Less than or equal             | `balance lte 1000`                       |
| `in`            | Value in array                 | `country in ["US","GB","CA"]`            |
| `not_in`        | Value not in array             | `country not_in ["CN","RU"]`             |
| `matches_regex` | Regex pattern match            | `email matches_regex ".*@company\.com$"` |
| `semver_gt`     | Version greater than           | `app_version semver_gt "2.0.0"`          |
| `semver_gte`    | Version greater or equal       | `app_version semver_gte "2.0.0"`         |
| `semver_lt`     | Version less than              | `app_version semver_lt "3.0.0"`          |
| `semver_lte`    | Version less or equal          | `app_version semver_lte "2.5.0"`         |
| `before_date`   | Date is before                 | `created_at before_date "2025-01-01"`    |
| `after_date`    | Date is after                  | `trial_end after_date "2025-01-01"`      |
| `percentage_of` | Percentage of attribute values | `id percentage_of 50`                    |

Example rule (configured in dashboard):

```json
{
  "priority": 1,
  "conditions": [
    {
      "trait": "plan",
      "operator": "equals",
      "value": "pro"
    }
  ],
  "value": true
}
```

### Version-Based Targeting

Semver operators are useful when your Laravel app serves as an API backend for mobile apps or versioned frontends. To
use them, create a version resolver that provides version traits to the context.

Create a class implementing `ResolvesVersion`:

```php
<?php

namespace App\FeatureFlags;

use FeatureFlags\Contracts\ResolvesVersion;

class VersionResolver implements ResolvesVersion
{
    public function resolve(): array
    {
        return [
            // From client request header
            'client_version' => request()->header('X-App-Version'),

            // With fallback logic
            'app_version' => request()->header('X-App-Version')
                ?? config('app.version'),
        ];
    }
}
```

Register it in your config:

```php
// config/featureflags.php
'context' => [
    'version_resolver' => \App\FeatureFlags\VersionResolver::class,
],
```

The resolved traits are automatically merged into every context, so you can create rules like
`app_version semver_gte "2.0.0"` in your dashboard without passing the version explicitly.

## Percentage Rollouts

Rollouts use sticky bucketing based on the flag key and context ID. The same context will always get the same result for
a given flag.

### Attribute-Based Rollouts

The `percentage_of` operator enables attribute-based percentage rollouts within targeting rules. Unlike global
percentage rollouts, this lets you target a percentage of users based on any attribute:

```json
{
  "conditions": [
    {
      "trait": "plan",
      "operator": "equals",
      "value": "pro"
    },
    {
      "trait": "id",
      "operator": "percentage_of",
      "value": 50
    }
  ],
  "value": true
}
```

This rule matches **50% of pro users**:

- **Sticky bucketing**: Same user always gets the same result for the same flag
- **Per-flag bucketing**: Users may be in different buckets for different flags
- **Combinable**: Use with other conditions for precise targeting (e.g., "25% of enterprise users in Europe")

## Segments

Segments are reusable user groups defined in the dashboard:

```json
{
  "conditions": [
    {
      "type": "segment",
      "segment": "beta-users"
    }
  ],
  "value": true
}
```

Segments support all the same operators as regular trait conditions.

## Webhooks

Configure your dashboard to send webhooks to `/webhooks/feature-flags`. The package automatically invalidates and
refreshes the cache when flags change.

Set `FEATUREFLAGS_WEBHOOK_SECRET` in your `.env` for signature verification. **The secret is required** - requests are
rejected with 401 if unset.

To sync manually:

```bash
php artisan featureflags:sync
```

Or programmatically:

```php
Feature::sync();
Feature::flush(); // Clear cache
```

## Fallback Behavior

When the API is unavailable:

- **`cache`** (default): Use cached flags, fail silently
- **`default`**: Return configured default value for unknown flags
- **`exception`**: Throw `FlagSyncException`

Configure in `config/featureflags.php`:

```php
'fallback' => [
    'behavior' => 'cache', // 'cache', 'default', or 'exception'
    'default_value' => false, // Used when behavior is 'default'
],
```

## Conversion Tracking

Track conversions for A/B test analysis:

```php
Feature::trackConversion('purchase', $user, ['revenue' => 99.99]);
```

The dashboard automatically correlates conversions with flag evaluations based on context ID and session - no need to
specify which flags are involved.

## Error Tracking

Errors are automatically correlated with evaluated feature flags, helping identify if a new feature is causing issues.
Requires `FEATUREFLAGS_TELEMETRY_ENABLED=true`.

### Setup

Register the service provider:

```php
// Laravel 11+ (bootstrap/providers.php)
return [
    // ...
    FeatureFlags\Integrations\ErrorTrackingServiceProvider::class,
];

// Laravel 10 (config/app.php)
'providers' => [
    // ...
    FeatureFlags\Integrations\ErrorTrackingServiceProvider::class,
],
```

Once registered:

1. Every flag evaluation is tracked during the request
2. When an exception occurs, all evaluated flags are attached
3. Third-party error trackers (Sentry, Bugsnag, Flare) receive the flag context via Laravel's Context facade
4. Your dashboard shows error rates correlated by flag value

### What Gets Tracked

```json
{
  "feature_flags": {
    "new-checkout": true,
    "dark-mode": false,
    "api-v2": true
  },
  "feature_flags_count": 3,
  "feature_flags_request_id": "01J5X..."
}
```

### Monitor Wrapper

For critical code paths:

```php
$result = Feature::monitor('new-payment-processor', function ($isEnabled) {
    if ($isEnabled) {
        return $this->processWithStripe();
    }
    return $this->processWithLegacy();
});
```

If the callback throws, the error is tracked with the exact flag value before global exception handling.

### Manual Tracking

```php
try {
    if (Feature::active('risky-feature')) {
        $this->riskyOperation();
    }
} catch (\Exception $e) {
    Feature::trackError('risky-feature', $e, ['custom' => 'metadata']);
    throw $e;
}
```

### Configuration

```php
'error_tracking' => [
    'enabled' => true,
    'skip_exceptions' => [
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Illuminate\Validation\ValidationException::class,
        \Illuminate\Auth\AuthenticationException::class,
    ],
],
```

### Supported Error Trackers

| Provider               | Package                   | Automatic |
|------------------------|---------------------------|-----------|
| **Laravel Nightwatch** | `laravel/nightwatch`      | ✅         |
| **Sentry**             | `sentry/sentry-laravel`   | ✅         |
| **Bugsnag**            | `bugsnag/bugsnag-laravel` | ✅         |
| **Flare**              | `spatie/laravel-ignition` | ✅         |

Automatic integration requires Laravel 11+ (uses the `Context` facade). On Laravel 10, use
`Feature::getEvaluatedFlags()` to wire up your error tracker manually.

## Local Development & Testing

Enable local mode for offline development and testing:

```env
FEATUREFLAGS_LOCAL_MODE=true
```

Define flags in `config/featureflags.php` under `local.flags`:

```php
'local' => [
    'enabled' => true,
    'flags' => [
        'new-checkout' => true,
        'dark-mode' => false,
        'api-rate-limit' => 100,
        'beta-features' => ['value' => true, 'rollout' => 25],
    ],
],
```

Export current flags from the API for offline work:

```bash
php artisan featureflags:dump --format=php|json|yaml --output=flags.php
```

### Mocking the Facade

For unit tests, mock specific flag checks:

```php
use FeatureFlags\Facades\Feature;

// Simple boolean flag
Feature::shouldReceive('active')
    ->with('my-flag')
    ->andReturn(true);

// Flag with context
Feature::shouldReceive('active')
    ->with('premium-feature', Mockery::any())
    ->andReturn(false);

// Value flags
Feature::shouldReceive('value')
    ->with('api-rate-limit')
    ->andReturn(100);
```

### Testing with Context

To test that rules evaluate correctly for different contexts:

```php
public function test_pro_users_see_feature(): void
{
    config(['featureflags.local.enabled' => true]);
    config(['featureflags.local.flags' => [
        'new-dashboard' => [
            'value' => false,
            'rules' => [
                [
                    'conditions' => [
                        ['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
                    ],
                    'value' => true,
                ],
            ],
        ],
    ]]);

    $proUser = new Context('user-1', ['plan' => 'pro']);
    $freeUser = new Context('user-2', ['plan' => 'free']);

    $this->assertTrue(Feature::active('new-dashboard', $proUser));
    $this->assertFalse(Feature::active('new-dashboard', $freeUser));
}
```

### Testing Percentage Rollouts

Rollouts are deterministic - the same context ID always gets the same result for a given flag:

```php
public function test_rollout_is_consistent(): void
{
    $context = new Context('user-123', []);

    $first = Feature::active('gradual-rollout', $context);
    $second = Feature::active('gradual-rollout', $context);

    // Same context always gets same result
    $this->assertEquals($first, $second);
}
```

## Observability

### Events

Optional Laravel events for monitoring. Disabled by default.

```env
FEATUREFLAGS_EVENTS_ENABLED=true
```

Or per-event in `config/featureflags.php`:

```php
'events' => [
    'enabled' => true,
    'dispatch' => [
        'flag_evaluated' => true,
        'flag_sync_completed' => true,
        'telemetry_flushed' => true,
    ],
],
```

| Event               | Payload                                        |
|---------------------|------------------------------------------------|
| `FlagEvaluated`     | `flagKey`, `value`, `contextId`, `matchReason` |
| `FlagSyncCompleted` | `flagCount`, `segmentCount`, `durationMs`      |
| `TelemetryFlushed`  | `type`, `eventCount`, `success`, `durationMs`  |

## Performance

### Cache Warming

Pre-warm the cache on deployment:

```bash
php artisan featureflags:warm --retry=3 --retry-delay=2
```

### Sampling

Reduce telemetry volume for high-traffic sites:

```php
'telemetry' => [
    'sample_rate' => 0.1, // Track 10% of evaluations
],
```

### Flush Rate Limiting

Queue telemetry flushes to avoid hitting your plan's rate limit:

```php
'telemetry' => [
    'rate_limit' => [
        'enabled' => true,
        'max_flushes_per_minute' => 60,
    ],
],
```

## GDPR Compliance

For GDPR-compliant telemetry, enable "hold until consent" mode. Feature flag evaluation works immediately, but telemetry is queued until the user consents.

```env
FEATUREFLAGS_HOLD_UNTIL_CONSENT=true
```

### Usage

```php
use FeatureFlags\Facades\Feature;

// Flags work immediately (bucketing happens, telemetry is held)
if (Feature::active('new-checkout')) {
    // Show new checkout
}

// When user accepts analytics/cookies:
Feature::grantConsent();  // Flushes held events, sets consent cookie

// If user declines:
Feature::discardHeldTelemetry();  // Clears queued events without sending

// To revoke consent later:
Feature::revokeConsent();  // Future telemetry will be held again

// Check current state:
Feature::isHoldingTelemetry();  // true if holding without consent
```

### How It Works

1. **First visit**: Device ID cookie (`ff_device_id`) is set for consistent bucketing
2. **Flag checks**: Evaluation works normally, telemetry events are queued
3. **User consents**: `grantConsent()` flushes queued events and sets consent cookie (`ff_telemetry_consent`)
4. **Subsequent visits**: Consent cookie is detected, telemetry flows normally

### Configuration

```php
'telemetry' => [
    'hold_until_consent' => env('FEATUREFLAGS_HOLD_UNTIL_CONSENT', false),
    'consent_ttl_days' => env('FEATUREFLAGS_CONSENT_TTL_DAYS', 365),
],
```

After `consent_ttl_days`, the consent cookie expires and the user will need to re-consent.

## License

MIT

## Support

- Dashboard & docs: https://turtlemilitia.com
- Issues: https://github.com/turtlemilitia/laravel-featureflags/issues
