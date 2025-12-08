<?php

declare(strict_types=1);

namespace FeatureFlags\Evaluation;

use FeatureFlags\Context;
use FeatureFlags\ContextResolver;
use FeatureFlags\Contracts\HasFeatureFlagContext;

readonly class ContextNormalizer
{
    public function __construct(
        private ContextResolver $contextResolver,
    ) {}

    /**
     * @param Context|HasFeatureFlagContext|array<string, mixed>|null $context
     */
    public function normalize(Context|HasFeatureFlagContext|array|null $context): ?Context
    {
        if ($context === null) {
            return $this->contextResolver->resolve();
        }

        if ($context instanceof Context) {
            return $this->enrichWithVersionTraits($context);
        }

        if ($context instanceof HasFeatureFlagContext) {
            return $this->contextResolver->fromInterface($context);
        }

        return $this->normalizeArray($context);
    }

    private function enrichWithVersionTraits(Context $context): Context
    {
        $versionTraits = $this->contextResolver->resolveVersionTraits();

        if (empty($versionTraits)) {
            return $context;
        }

        $mergedTraits = array_merge($versionTraits, $context->traits);

        return new Context($context->id, $mergedTraits);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function normalizeArray(array $context): ?Context
    {
        if (!isset($context['id'])) {
            return null;
        }

        /** @var string|int $id */
        $id = $context['id'];
        /** @var array<string, mixed> $traits */
        $traits = $context['traits'] ?? array_diff_key($context, ['id' => true]);
        $traits = $this->contextResolver->mergeVersionTraits($traits);

        return new Context($id, $traits);
    }
}
