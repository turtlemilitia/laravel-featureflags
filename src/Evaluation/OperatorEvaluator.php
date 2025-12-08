<?php

declare(strict_types=1);

namespace FeatureFlags\Evaluation;

use Illuminate\Support\Facades\Log;

class OperatorEvaluator
{
    /** @var array<string, true> */
    private static array $loggedBadPatterns = [];

    public function evaluate(mixed $actual, string $operator, mixed $expected, string $flagKey = ''): bool
    {
        return match ($operator) {
            'equals' => $this->equals($actual, $expected),
            'not_equals' => $this->notEquals($actual, $expected),
            'contains' => $this->contains($actual, $expected),
            'not_contains' => $this->notContains($actual, $expected),
            'starts_with' => $this->startsWith($actual, $expected),
            'ends_with' => $this->endsWith($actual, $expected),
            'matches_regex' => $this->matchesRegex($actual, $expected),
            'gt' => $this->greaterThan($actual, $expected),
            'gte' => $this->greaterThanOrEqual($actual, $expected),
            'lt' => $this->lessThan($actual, $expected),
            'lte' => $this->lessThanOrEqual($actual, $expected),
            'in' => $this->in($actual, $expected),
            'not_in' => $this->notIn($actual, $expected),
            'semver_gt' => $this->semverGreaterThan($actual, $expected),
            'semver_gte' => $this->semverGreaterThanOrEqual($actual, $expected),
            'semver_lt' => $this->semverLessThan($actual, $expected),
            'semver_lte' => $this->semverLessThanOrEqual($actual, $expected),
            'before_date' => $this->beforeDate($actual, $expected),
            'after_date' => $this->afterDate($actual, $expected),
            'percentage_of' => $this->isInPercentage($actual, $expected, $flagKey),
            default => false,
        };
    }

    private function equals(mixed $actual, mixed $expected): bool
    {
        return $actual == $expected;
    }

    private function notEquals(mixed $actual, mixed $expected): bool
    {
        return $actual != $expected;
    }

    private function contains(mixed $actual, mixed $expected): bool
    {
        return is_string($actual) && is_string($expected) && str_contains($actual, $expected);
    }

    private function notContains(mixed $actual, mixed $expected): bool
    {
        return is_string($actual) && is_string($expected) && !str_contains($actual, $expected);
    }

    private function startsWith(mixed $actual, mixed $expected): bool
    {
        return is_string($actual) && is_string($expected) && str_starts_with($actual, $expected);
    }

    private function endsWith(mixed $actual, mixed $expected): bool
    {
        return is_string($actual) && is_string($expected) && str_ends_with($actual, $expected);
    }

    private function matchesRegex(mixed $actual, mixed $pattern): bool
    {
        if (!is_string($actual) || !is_string($pattern)) {
            return false;
        }

        try {
            return preg_match($pattern, $actual) === 1;
        } catch (\Throwable $e) {
            if (!isset(self::$loggedBadPatterns[$pattern])) {
                self::$loggedBadPatterns[$pattern] = true;
                Log::warning('Feature flag regex evaluation failed', [
                    'pattern' => $pattern,
                    'error' => $e->getMessage(),
                ]);
            }
            return false;
        }
    }

    private function greaterThan(mixed $actual, mixed $expected): bool
    {
        return is_numeric($actual) && is_numeric($expected) && $actual > $expected;
    }

    private function greaterThanOrEqual(mixed $actual, mixed $expected): bool
    {
        return is_numeric($actual) && is_numeric($expected) && $actual >= $expected;
    }

    private function lessThan(mixed $actual, mixed $expected): bool
    {
        return is_numeric($actual) && is_numeric($expected) && $actual < $expected;
    }

    private function lessThanOrEqual(mixed $actual, mixed $expected): bool
    {
        return is_numeric($actual) && is_numeric($expected) && $actual <= $expected;
    }

    private function in(mixed $actual, mixed $expected): bool
    {
        return is_array($expected) && in_array($actual, $expected, false);
    }

    private function notIn(mixed $actual, mixed $expected): bool
    {
        return is_array($expected) && !in_array($actual, $expected, false);
    }

    private function semverGreaterThan(mixed $actual, mixed $expected): bool
    {
        return is_string($actual) && is_string($expected) && version_compare($actual, $expected, '>');
    }

    private function semverGreaterThanOrEqual(mixed $actual, mixed $expected): bool
    {
        return is_string($actual) && is_string($expected) && version_compare($actual, $expected, '>=');
    }

    private function semverLessThan(mixed $actual, mixed $expected): bool
    {
        return is_string($actual) && is_string($expected) && version_compare($actual, $expected, '<');
    }

    private function semverLessThanOrEqual(mixed $actual, mixed $expected): bool
    {
        return is_string($actual) && is_string($expected) && version_compare($actual, $expected, '<=');
    }

    private function beforeDate(mixed $actual, mixed $expected): bool
    {
        return $this->compareDates($actual, $expected, '<');
    }

    private function afterDate(mixed $actual, mixed $expected): bool
    {
        return $this->compareDates($actual, $expected, '>');
    }

    private function compareDates(mixed $actual, mixed $expected, string $operator): bool
    {
        $actualDate = $this->toDate($actual);
        $expectedDate = $this->toDate($expected);

        if ($actualDate === null || $expectedDate === null) {
            return false;
        }

        return match ($operator) {
            '<' => $actualDate < $expectedDate,
            '>' => $actualDate > $expectedDate,
            '<=' => $actualDate <= $expectedDate,
            '>=' => $actualDate >= $expectedDate,
            default => false,
        };
    }

    private function toDate(mixed $value): ?\DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        try {
            if (is_int($value)) {
                return new \DateTime("@$value");
            }

            if (is_string($value) && $value !== '') {
                return new \DateTime($value);
            }

            return null;
        } catch (\Exception) {
            return null;
        }
    }

    private function isInPercentage(mixed $attributeValue, mixed $percentage, string $flagKey): bool
    {
        if (!is_numeric($percentage)) {
            return false;
        }

        $pct = (int) $percentage;
        if ($pct <= 0) {
            return false;
        }
        if ($pct >= 100) {
            return true;
        }

        if ($attributeValue === null) {
            return false;
        }

        $valueString = is_scalar($attributeValue) ? (string) $attributeValue : json_encode($attributeValue);
        if ($valueString === false) {
            return false;
        }

        return BucketCalculator::calculate($flagKey . ':' . $valueString) < ($pct * 100);
    }
}
