<?php

declare(strict_types=1);

namespace FeatureFlags\Exceptions;

use RuntimeException;

class CircularDependencyException extends RuntimeException
{
    /** @param array<string, mixed>|null $flag */
    public function __construct(
        public readonly ?array $flag = null,
        string $message = 'Circular dependency detected',
    ) {
        parent::__construct($message);
    }
}
