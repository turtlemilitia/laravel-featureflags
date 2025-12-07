<?php

declare(strict_types=1);

namespace FeatureFlags\Context;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RequestContext
{
    protected static ?string $sessionId = null;
    protected static ?string $requestId = null;
    protected static bool $initialized = false;

    public static function initialize(): void
    {
        static::$requestId = (string) Str::ulid();

        // Try to get the session ID if session is available
        try {
            if (function_exists('session')) {
                $session = session();
                if ($session->isStarted()) {
                    static::$sessionId = $session->getId();
                } else {
                    // Fall back to request ID for sessionless requests (API, queue, etc.)
                    static::$sessionId = static::$requestId;
                }
            } else {
                static::$sessionId = static::$requestId;
            }
        } catch (\Throwable $e) {
            // Session not available (console, queue, etc.)
            Log::debug('Feature flags: Session not available, using request ID as session ID', [
                'error' => $e->getMessage(),
            ]);
            static::$sessionId = static::$requestId;
        }

        static::$initialized = true;
    }

    public static function getSessionId(): ?string
    {
        return static::$sessionId;
    }

    public static function getRequestId(): ?string
    {
        return static::$requestId;
    }

    public static function setSessionId(?string $sessionId): void
    {
        static::$sessionId = $sessionId;
    }

    public static function setRequestId(?string $requestId): void
    {
        static::$requestId = $requestId;
    }

    /**
     * MUST be called at end of each request for Octane compatibility.
     * FlushTelemetry middleware calls this automatically.
     */
    public static function reset(): void
    {
        static::$sessionId = null;
        static::$requestId = null;
        static::$initialized = false;
    }

    public static function isInitialized(): bool
    {
        return static::$initialized;
    }

    /** @return array{session_id: string|null, request_id: string|null} */
    public static function toArray(): array
    {
        return [
            'session_id' => static::$sessionId,
            'request_id' => static::$requestId,
        ];
    }
}
