<?php

declare(strict_types=1);

namespace FeatureFlags\Contracts;

interface ResolvesVersion
{
    /**
     * Resolve version traits to merge into the context.
     *
     * This is used for semver targeting rules. Return an associative array
     * where keys are trait names and values are version strings.
     *
     * Example:
     *     return [
     *         'app_version' => request()->header('X-App-Version'),
     *         'api_version' => config('app.version'),
     *     ];
     *
     * @return array<string, string|null> Trait names mapped to version strings
     */
    public function resolve(): array;
}
