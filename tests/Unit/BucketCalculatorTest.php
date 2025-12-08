<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Evaluation\BucketCalculator;
use FeatureFlags\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class BucketCalculatorTest extends TestCase
{
    public function test_returns_value_between_0_and_9999(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $bucket = BucketCalculator::calculate("seed-{$i}");
            $this->assertGreaterThanOrEqual(0, $bucket);
            $this->assertLessThan(10000, $bucket);
        }
    }

    public function test_returns_consistent_value_for_same_seed(): void
    {
        $seed = 'user-123:feature-flag';
        $bucket1 = BucketCalculator::calculate($seed);
        $bucket2 = BucketCalculator::calculate($seed);

        $this->assertSame($bucket1, $bucket2);
    }

    public function test_returns_different_values_for_different_seeds(): void
    {
        $bucket1 = BucketCalculator::calculate('seed-a');
        $bucket2 = BucketCalculator::calculate('seed-b');

        $this->assertNotSame($bucket1, $bucket2);
    }

    public function test_distribution_is_roughly_uniform(): void
    {
        $buckets = array_fill(0, 10, 0);

        for ($i = 0; $i < 10000; $i++) {
            $bucket = BucketCalculator::calculate("test-seed-{$i}");
            // Group into deciles (0-999 = 0, 1000-1999 = 1, etc.)
            $decile = (int) floor($bucket / 1000);
            $buckets[$decile]++;
        }

        foreach ($buckets as $count) {
            $this->assertGreaterThan(800, $count);
            $this->assertLessThan(1200, $count);
        }
    }

    #[DataProvider('percentageProvider')]
    public function test_distribution_at_percentage(int $targetPercentage, float $tolerance): void
    {
        $sampleSize = 100000;
        $inBucket = 0;
        // Convert percentage to threshold (1% = 100, 50% = 5000, etc.)
        $threshold = $targetPercentage * 100;

        for ($i = 0; $i < $sampleSize; $i++) {
            $bucket = BucketCalculator::calculate("test-flag:user_{$i}");
            if ($bucket < $threshold) {
                $inBucket++;
            }
        }

        $actualPercentage = ($inBucket / $sampleSize) * 100;
        $deviation = abs($actualPercentage - $targetPercentage);

        $this->assertLessThan(
            $tolerance,
            $deviation,
            "Distribution at {$targetPercentage}% deviated by {$deviation}%"
        );
    }

    /** @return array<string, array{int, float}> */
    public static function percentageProvider(): array
    {
        return [
            '1% rollout' => [1, 0.05],
            '5% rollout' => [5, 0.10],
            '10% rollout' => [10, 0.10],
            '50% rollout' => [50, 0.20],
            '99% rollout' => [99, 0.05],
        ];
    }

    public function test_chi_square_uniformity(): void
    {
        $sampleSize = 100000;
        // Group into 100 buckets (each covering 100 values: 0-99, 100-199, etc.)
        $buckets = array_fill(0, 100, 0);

        for ($i = 0; $i < $sampleSize; $i++) {
            $bucket = BucketCalculator::calculate("test-flag:user_{$i}");
            $group = (int) floor($bucket / 100);
            $buckets[$group]++;
        }

        $expected = $sampleSize / 100;
        $chiSquare = 0.0;

        foreach ($buckets as $observed) {
            $chiSquare += pow($observed - $expected, 2) / $expected;
        }

        // Critical value for df=99, alpha=0.05 is ~123.23
        $this->assertLessThan(
            123.23,
            $chiSquare,
            "Chi-square value {$chiSquare} exceeds critical value, distribution is not uniform"
        );
    }
}
