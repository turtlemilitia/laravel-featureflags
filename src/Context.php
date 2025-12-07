<?php

declare(strict_types=1);

namespace FeatureFlags;

use Illuminate\Support\Arr;

class Context
{
    /**
     * @param array<string, mixed> $traits
     */
    public function __construct(
        public readonly string|int $id,
        public readonly array $traits = [],
    ) {}

    /**
     * @param string|int $id
     * @param array<string, mixed> $traits
     */
    public static function make(string|int $id, array $traits = []): self
    {
        return new self($id, $traits);
    }

    /**
     * Get a trait value, supporting dot notation for nested access.
     *
     * Examples:
     *   $context->get('plan')           // returns 'pro'
     *   $context->get('plan.name')      // returns nested value
     *   $context->get('plan.features')  // returns nested array
     */
    public function get(string $trait, mixed $default = null): mixed
    {
        return Arr::get($this->traits, $trait, $default);
    }

    /**
     * Check if a trait exists, supporting dot notation for nested access.
     */
    public function has(string $trait): bool
    {
        return Arr::has($this->traits, $trait);
    }

    /**
     * @return array{id: string|int, traits: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'traits' => $this->traits,
        ];
    }
}
