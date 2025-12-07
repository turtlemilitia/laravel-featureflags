<?php

declare(strict_types=1);

namespace FeatureFlags\Contracts;

interface HasFeatureFlagContext
{
    /**
     * Get the feature flag context for this model.
     *
     * @return array<string, mixed> Must include 'id' key
     */
    public function toFeatureFlagContext(): array;
}
