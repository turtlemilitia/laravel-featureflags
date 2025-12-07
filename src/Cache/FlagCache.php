<?php

declare(strict_types=1);

namespace FeatureFlags\Cache;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

class FlagCache
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $flagsIndex = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $segmentsIndex = null;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $prefix,
        private readonly int $defaultTtl,
    ) {}

    /** @return array<int, array<string, mixed>>|null */
    public function all(): ?array
    {
        /** @var array<int, array<string, mixed>>|null $flags */
        $flags = $this->cache->get($this->key('flags'));
        return $flags;
    }

    /** @return array<string, mixed>|null */
    public function get(string $flagKey): ?array
    {
        $this->ensureFlagsIndexed();

        return $this->flagsIndex[$flagKey] ?? null;
    }

    /** @param array<int, array<string, mixed>> $flags */
    public function put(array $flags, ?int $ttl = null): void
    {
        $this->cache->put(
            $this->key('flags'),
            $flags,
            $ttl ?? $this->defaultTtl,
        );

        $this->flagsIndex = null;
        $this->ensureFlagsIndexed();
    }

    /** @return array<int, array<string, mixed>>|null */
    public function allSegments(): ?array
    {
        /** @var array<int, array<string, mixed>>|null $segments */
        $segments = $this->cache->get($this->key('segments'));
        return $segments;
    }

    /** @return array<string, mixed>|null */
    public function getSegment(string $segmentKey): ?array
    {
        $this->ensureSegmentsIndexed();

        return $this->segmentsIndex[$segmentKey] ?? null;
    }

    /** @param array<int, array<string, mixed>> $segments */
    public function putSegments(array $segments, ?int $ttl = null): void
    {
        $this->cache->put(
            $this->key('segments'),
            $segments,
            $ttl ?? $this->defaultTtl,
        );

        $this->segmentsIndex = null;
        $this->ensureSegmentsIndexed();
    }

    public function has(): bool
    {
        return $this->cache->has($this->key('flags'));
    }

    public function flush(): void
    {
        $this->cache->forget($this->key('flags'));
        $this->cache->forget($this->key('segments'));
        $this->flagsIndex = null;
        $this->segmentsIndex = null;
    }

    private function key(string $suffix): string
    {
        return $this->prefix . ':' . $suffix;
    }

    /**
     * Build the flags index for O(1) lookups.
     */
    private function ensureFlagsIndexed(): void
    {
        if ($this->flagsIndex !== null) {
            return;
        }

        $this->flagsIndex = [];
        $flags = $this->all();

        if ($flags === null) {
            return;
        }

        foreach ($flags as $flag) {
            if (isset($flag['key']) && is_string($flag['key'])) {
                $this->flagsIndex[$flag['key']] = $flag;
            }
        }
    }

    /**
     * Build the segments index for O(1) lookups.
     */
    private function ensureSegmentsIndexed(): void
    {
        if ($this->segmentsIndex !== null) {
            return;
        }

        $this->segmentsIndex = [];
        $segments = $this->allSegments();

        if ($segments === null) {
            return;
        }

        foreach ($segments as $segment) {
            if (isset($segment['key']) && is_string($segment['key'])) {
                $this->segmentsIndex[$segment['key']] = $segment;
            }
        }
    }
}
