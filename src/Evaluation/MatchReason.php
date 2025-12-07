<?php

declare(strict_types=1);

namespace FeatureFlags\Evaluation;

enum MatchReason: string
{
    case Disabled = 'disabled';
    case Dependency = 'dependency';
    case Rule = 'rule';
    case Rollout = 'rollout';
    case RolloutMiss = 'rollout_miss';
    case Default = 'default';
    case NotFound = 'not_found';
}
