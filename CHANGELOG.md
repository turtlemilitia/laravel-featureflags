# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.2] - 2025-12-12

### Fixed

- `ConfigHelper::int()` now casts numeric strings from env variables to integers (fixes cache TTL being ignored)

## [0.2.1] - 2025-12-12

### Fixed

- Cache TTL now respects `FEATUREFLAGS_CACHE_TTL` config instead of ignoring it

## [0.2.0] - 2025-12-12

### Added

- Async telemetry mode - dispatch telemetry to queue jobs instead of blocking requests

## Fixed

- `syncIfNeeded()` method to check cache validity before API calls on boot

## [0.1.1] - 2025-12-12

### Fixed

- Endpoint paths

## [0.1.0] - 2025-12-12

Initial release.

### Added

- Local flag evaluation with caching
- Targeting rules with 19 operators (equals, contains, gt/gte/lt/lte, in/not_in, regex, semver, date, percentage_of)
- Percentage rollouts with MurmurHash3 bucketing
- Experiment variant assignment with holdout group support
- Segment evaluation
- `HasFeatureFlagContext` interface for automatic context resolution
- `ResolvesVersion` contract for version-based targeting
- Dot notation for nested context attributes
- Telemetry collection with sampling and rate limiting
- Conversion tracking with automatic flag attribution
- Error tracking integration (Sentry, Bugsnag, Flare, Nightwatch)
- GDPR consent handling (`grantConsent()`, `revokeConsent()`, `discardHeldTelemetry()`)
- Device ID cookie for consistent bucketing
- `@feature` Blade directive
- Webhook endpoint with HMAC signature verification
- Fallback behavior configuration (cache, default, exception)
- Circuit breaker for API failures
- Local mode for offline development
- `featureflags:warm`, `featureflags:sync`, `featureflags:dump` commands
- `Feature::monitor()` wrapper for error correlation
- `FlagEvaluated`, `FlagSyncCompleted`, `TelemetryFlushed` events
