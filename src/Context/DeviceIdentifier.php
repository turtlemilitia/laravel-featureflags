<?php

declare(strict_types=1);

namespace FeatureFlags\Context;

use Illuminate\Support\Str;

class DeviceIdentifier
{
    private const COOKIE_NAME = 'ff_device_id';
    private const CONSENT_COOKIE_NAME = 'ff_telemetry_consent';
    private const DEFAULT_COOKIE_TTL_DAYS = 365;

    protected static ?string $deviceId = null;
    protected static ?string $customDeviceId = null;
    protected static ?bool $consentGranted = null;

    public static function get(): string
    {
        if (static::$customDeviceId !== null) {
            return static::$customDeviceId;
        }

        if (static::$deviceId !== null) {
            return static::$deviceId;
        }

        if (app()->runningInConsole()) {
            return static::$deviceId = (string) Str::uuid();
        }

        /** @var string|null $deviceId */
        $deviceId = request()->cookie(self::COOKIE_NAME);

        if (!$deviceId) {
            $deviceId = (string) Str::uuid();
            static::queueCookie($deviceId);
        }

        static::$deviceId = $deviceId;

        return $deviceId;
    }

    public static function setDeviceId(string $deviceId): void
    {
        static::$customDeviceId = $deviceId;
        static::$deviceId = $deviceId;
    }

    public static function hasConsent(): bool
    {
        if (static::$consentGranted !== null) {
            return static::$consentGranted;
        }

        if (app()->runningInConsole()) {
            return false;
        }

        return (bool) request()->cookie(self::CONSENT_COOKIE_NAME);
    }

    public static function grantConsent(): void
    {
        static::$consentGranted = true;

        if (!app()->runningInConsole()) {
            $ttlMinutes = static::getConsentTtlDays() * 24 * 60;
            cookie()->queue(
                self::CONSENT_COOKIE_NAME,
                '1',
                $ttlMinutes,
                '/',
                null,
                true,
                true,
            );
        }
    }

    protected static function getConsentTtlDays(): int
    {
        /** @var int $ttl */
        $ttl = config('featureflags.telemetry.consent_ttl_days', self::DEFAULT_COOKIE_TTL_DAYS);

        return $ttl;
    }

    public static function revokeConsent(): void
    {
        static::$consentGranted = false;

        if (!app()->runningInConsole()) {
            cookie()->queue(cookie()->forget(self::CONSENT_COOKIE_NAME));
        }
    }

    public static function reset(): void
    {
        static::$deviceId = null;
        static::$customDeviceId = null;
        static::$consentGranted = null;
    }

    protected static function queueCookie(string $deviceId): void
    {
        cookie()->queue(
            self::COOKIE_NAME,
            $deviceId,
            self::DEFAULT_COOKIE_TTL_DAYS * 24 * 60,
            '/',
            null,
            true,
            true,
        );
    }
}
