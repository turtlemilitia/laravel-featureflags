<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Evaluation\BucketCalculator;
use FeatureFlags\Tests\TestCase;

class BucketCalculatorTest extends TestCase
{
    public function test_returns_value_between_0_and_99(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $bucket = BucketCalculator::calculate("seed-{$i}");
            $this->assertGreaterThanOrEqual(0, $bucket);
            $this->assertLessThan(100, $bucket);
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
            $decile = (int) floor($bucket / 10);
            $buckets[$decile]++;
        }

        foreach ($buckets as $count) {
            $this->assertGreaterThan(800, $count);
            $this->assertLessThan(1200, $count);
        }
    }
}
