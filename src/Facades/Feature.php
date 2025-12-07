<?php

declare(strict_types=1);

namespace FeatureFlags\Facades;

use FeatureFlags\Context;
use FeatureFlags\FeatureFlags;
use FeatureFlags\Telemetry\FlagStateTracker;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool active(string $key, Context|array<string, mixed>|null $context = null)
 * @method static bool|int|float|string|array<string, mixed>|null value(string $key, Context|array<string, mixed>|null $context = null)
 * @method static array<int, array<string, mixed>> all()
 * @method static void sync()
 * @method static void flush()
 * @method static void trackConversion(string $eventName, Context|array<string, mixed>|null $context = null, array<string, mixed> $properties = [], ?string $flagKey = null)
 * @method static void trackConversionWithValue(string $eventName, string $flagKey, bool|int|float|string|array<string, mixed>|null $flagValue, Context|array<string, mixed>|null $context = null, array<string, mixed> $properties = [])
 * @method static void flushConversions()
 * @method static mixed monitor(string $flagKey, callable $callback, Context|array<string, mixed>|null $context = null)
 * @method static void trackError(string $flagKey, \Throwable $exception, array<string, mixed> $metadata = [])
 * @method static void flushErrors()
 * @method static bool isLocalMode()
 * @method static array<string, bool|int|float|string|array<string, mixed>|null> getEvaluatedFlags()
 * @method static array{flags: array<string, bool|int|float|string|array<string, mixed>|null>, count: int, request_id: string|null} getErrorContext()
 * @method static FlagStateTracker getStateTracker()
 * @method static void resetStateTracker()
 * @method static void flushAllTelemetryAndReset()
 *
 * @see FeatureFlags
 */
class Feature extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'featureflags';
    }
}
