<?php

declare(strict_types=1);

namespace FeatureFlags\Console;

use FeatureFlags\FeatureFlags;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    /** @var string */
    protected $signature = 'featureflags:sync';

    /** @var string */
    protected $description = 'Sync feature flags from the API';

    public function handle(FeatureFlags $featureFlags): int
    {
        $this->info('Syncing feature flags...');

        $featureFlags->sync();

        $flags = $featureFlags->all();
        $count = count($flags);

        $this->info("Synced {$count} flag(s).");

        if ($this->option('verbose')) {
            foreach ($flags as $flag) {
                /** @var bool $enabled */
                $enabled = $flag['enabled'] ?? false;
                $status = $enabled ? 'enabled' : 'disabled';
                /** @var string $key */
                $key = $flag['key'] ?? 'unknown';
                $this->line("  - {$key} ({$status})");
            }
        }

        return self::SUCCESS;
    }
}
