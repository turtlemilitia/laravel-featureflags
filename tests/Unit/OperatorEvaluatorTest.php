<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Evaluation\OperatorEvaluator;
use FeatureFlags\Tests\TestCase;

class OperatorEvaluatorTest extends TestCase
{
    private OperatorEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new OperatorEvaluator();
    }

    public function test_equals_operator(): void
    {
        $this->assertTrue($this->evaluator->evaluate('foo', 'equals', 'foo'));
        $this->assertTrue($this->evaluator->evaluate(123, 'equals', 123));
        $this->assertTrue($this->evaluator->evaluate(123, 'equals', '123')); // loose comparison
        $this->assertFalse($this->evaluator->evaluate('foo', 'equals', 'bar'));
    }

    public function test_not_equals_operator(): void
    {
        $this->assertTrue($this->evaluator->evaluate('foo', 'not_equals', 'bar'));
        $this->assertFalse($this->evaluator->evaluate('foo', 'not_equals', 'foo'));
    }

    public function test_contains_operator(): void
    {
        $this->assertTrue($this->evaluator->evaluate('hello world', 'contains', 'world'));
        $this->assertFalse($this->evaluator->evaluate('hello world', 'contains', 'foo'));
        $this->assertFalse($this->evaluator->evaluate(123, 'contains', '1')); // non-string
    }

    public function test_not_contains_operator(): void
    {
        $this->assertTrue($this->evaluator->evaluate('hello world', 'not_contains', 'foo'));
        $this->assertFalse($this->evaluator->evaluate('hello world', 'not_contains', 'world'));
    }

    public function test_matches_regex_operator(): void
    {
        $this->assertTrue($this->evaluator->evaluate('user@example.com', 'matches_regex', '/^[^@]+@[^@]+$/'));
        $this->assertFalse($this->evaluator->evaluate('invalid-email', 'matches_regex', '/^[^@]+@[^@]+$/'));
        $this->assertFalse($this->evaluator->evaluate(123, 'matches_regex', '/\d+/')); // non-string actual
    }

    public function test_matches_regex_handles_invalid_pattern(): void
    {
        // Invalid regex pattern should return false without throwing
        $this->assertFalse($this->evaluator->evaluate('test', 'matches_regex', '/invalid[/'));
    }

    public function test_gt_operator(): void
    {
        $this->assertTrue($this->evaluator->evaluate(10, 'gt', 5));
        $this->assertFalse($this->evaluator->evaluate(5, 'gt', 10));
        $this->assertFalse($this->evaluator->evaluate(5, 'gt', 5));
        $this->assertFalse($this->evaluator->evaluate('abc', 'gt', 5)); // non-numeric
    }

    public function test_gte_operator(): void
    {
        $this->assertTrue($this->evaluator->evaluate(10, 'gte', 5));
        $this->assertTrue($this->evaluator->evaluate(5, 'gte', 5));
        $this->assertFalse($this->evaluator->evaluate(4, 'gte', 5));
    }

    public function test_lt_operator(): void
    {
        $this->assertTrue($this->evaluator->evaluate(5, 'lt', 10));
        $this->assertFalse($this->evaluator->evaluate(10, 'lt', 5));
        $this->assertFalse($this->evaluator->evaluate(5, 'lt', 5));
    }

    public function test_lte_operator(): void
    {
        $this->assertTrue($this->evaluator->evaluate(5, 'lte', 10));
        $this->assertTrue($this->evaluator->evaluate(5, 'lte', 5));
        $this->assertFalse($this->evaluator->evaluate(10, 'lte', 5));
    }

    public function test_in_operator(): void
    {
        $this->assertTrue($this->evaluator->evaluate('a', 'in', ['a', 'b', 'c']));
        $this->assertFalse($this->evaluator->evaluate('d', 'in', ['a', 'b', 'c']));
        $this->assertFalse($this->evaluator->evaluate('a', 'in', 'not-an-array'));
    }

    public function test_not_in_operator(): void
    {
        $this->assertTrue($this->evaluator->evaluate('d', 'not_in', ['a', 'b', 'c']));
        $this->assertFalse($this->evaluator->evaluate('a', 'not_in', ['a', 'b', 'c']));
    }

    public function test_semver_gt_operator(): void
    {
        $this->assertTrue($this->evaluator->evaluate('2.0.0', 'semver_gt', '1.0.0'));
        $this->assertTrue($this->evaluator->evaluate('1.1.0', 'semver_gt', '1.0.0'));
        $this->assertFalse($this->evaluator->evaluate('1.0.0', 'semver_gt', '2.0.0'));
        $this->assertFalse($this->evaluator->evaluate(123, 'semver_gt', '1.0.0')); // non-string
    }

    public function test_semver_gte_operator(): void
    {
        $this->assertTrue($this->evaluator->evaluate('2.0.0', 'semver_gte', '1.0.0'));
        $this->assertTrue($this->evaluator->evaluate('1.0.0', 'semver_gte', '1.0.0'));
        $this->assertFalse($this->evaluator->evaluate('0.9.0', 'semver_gte', '1.0.0'));
    }

    public function test_semver_lt_operator(): void
    {
        $this->assertTrue($this->evaluator->evaluate('1.0.0', 'semver_lt', '2.0.0'));
        $this->assertFalse($this->evaluator->evaluate('2.0.0', 'semver_lt', '1.0.0'));
    }

    public function test_semver_lte_operator(): void
    {
        $this->assertTrue($this->evaluator->evaluate('1.0.0', 'semver_lte', '2.0.0'));
        $this->assertTrue($this->evaluator->evaluate('1.0.0', 'semver_lte', '1.0.0'));
        $this->assertFalse($this->evaluator->evaluate('2.0.0', 'semver_lte', '1.0.0'));
    }

    public function test_before_date_operator(): void
    {
        $this->assertTrue($this->evaluator->evaluate('2023-01-01', 'before_date', '2024-01-01'));
        $this->assertFalse($this->evaluator->evaluate('2024-01-01', 'before_date', '2023-01-01'));
    }

    public function test_after_date_operator(): void
    {
        $this->assertTrue($this->evaluator->evaluate('2024-01-01', 'after_date', '2023-01-01'));
        $this->assertFalse($this->evaluator->evaluate('2023-01-01', 'after_date', '2024-01-01'));
    }

    public function test_date_comparison_with_datetime_objects(): void
    {
        $earlier = new \DateTime('2023-01-01');
        $later = new \DateTime('2024-01-01');

        $this->assertTrue($this->evaluator->evaluate($earlier, 'before_date', $later));
        $this->assertTrue($this->evaluator->evaluate($later, 'after_date', $earlier));
    }

    public function test_date_comparison_with_timestamps(): void
    {
        $earlier = strtotime('2023-01-01');
        $later = strtotime('2024-01-01');

        $this->assertTrue($this->evaluator->evaluate($earlier, 'before_date', $later));
    }

    public function test_date_comparison_with_invalid_dates(): void
    {
        $this->assertFalse($this->evaluator->evaluate('not-a-date', 'before_date', '2024-01-01'));
        $this->assertFalse($this->evaluator->evaluate('2024-01-01', 'before_date', 'not-a-date'));
    }

    public function test_percentage_of_operator(): void
    {
        // With 100%, should always return true
        $this->assertTrue($this->evaluator->evaluate('user-123', 'percentage_of', 100, 'test-flag'));

        // With 0%, should always return false
        $this->assertFalse($this->evaluator->evaluate('user-123', 'percentage_of', 0, 'test-flag'));

        // With null value, should return false
        $this->assertFalse($this->evaluator->evaluate(null, 'percentage_of', 50, 'test-flag'));
    }

    public function test_percentage_of_is_deterministic(): void
    {
        // Same user + flag should always get same result
        $result1 = $this->evaluator->evaluate('user-123', 'percentage_of', 50, 'test-flag');
        $result2 = $this->evaluator->evaluate('user-123', 'percentage_of', 50, 'test-flag');

        $this->assertSame($result1, $result2);
    }

    public function test_unknown_operator_returns_false(): void
    {
        $this->assertFalse($this->evaluator->evaluate('foo', 'unknown_operator', 'bar'));
    }
}
