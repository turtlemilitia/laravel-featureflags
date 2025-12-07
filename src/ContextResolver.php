<?php

declare(strict_types=1);

namespace FeatureFlags;

use FeatureFlags\Contracts\HasFeatureFlagContext;
use FeatureFlags\Contracts\ResolvesVersion;
use Illuminate\Support\Facades\Auth;

class ContextResolver
{
    private ?ResolvesVersion $versionResolver = null;
    private bool $versionResolverInitialized = false;

    public function resolve(): ?Context
    {
        /** @var bool $autoResolve */
        $autoResolve = config('featureflags.context.auto_resolve', true);
        if (!$autoResolve) {
            return null;
        }

        $user = Auth::user();

        if (!$user instanceof HasFeatureFlagContext) {
            return null;
        }

        return $this->fromInterface($user);
    }

    /**
     * Create context from an object implementing HasFeatureFlagContext.
     */
    public function fromInterface(HasFeatureFlagContext $model): Context
    {
        $traits = $model->toFeatureFlagContext();

        /** @var string|int $id */
        $id = $traits['id'] ?? (method_exists($model, 'getAuthIdentifier')
            ? $model->getAuthIdentifier()
            : spl_object_id($model));

        unset($traits['id']);

        $traits = $this->mergeVersionTraits($traits);

        return new Context($id, $traits);
    }

    /**
     * Merge version traits into an existing traits array.
     *
     * @param array<string, mixed> $traits
     * @return array<string, mixed>
     */
    public function mergeVersionTraits(array $traits): array
    {
        $versionTraits = $this->resolveVersionTraits();

        if (empty($versionTraits)) {
            return $traits;
        }

        // Version traits are merged with lower priority - existing traits take precedence
        return array_merge($versionTraits, $traits);
    }

    /**
     * Resolve version traits from the configured resolver.
     *
     * @return array<string, string|null>
     */
    public function resolveVersionTraits(): array
    {
        $resolver = $this->getVersionResolver();

        if ($resolver === null) {
            return [];
        }

        return $resolver->resolve();
    }

    private function getVersionResolver(): ?ResolvesVersion
    {
        if ($this->versionResolverInitialized) {
            return $this->versionResolver;
        }

        $this->versionResolverInitialized = true;

        /** @var class-string<ResolvesVersion>|null $resolverClass */
        $resolverClass = config('featureflags.context.version_resolver');

        if ($resolverClass === null) {
            return null;
        }

        $resolver = app($resolverClass);

        if (!$resolver instanceof ResolvesVersion) {
            return null;
        }

        $this->versionResolver = $resolver;

        return $this->versionResolver;
    }
}
