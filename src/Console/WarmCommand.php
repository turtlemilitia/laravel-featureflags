<?php

declare(strict_types=1);

namespace FeatureFlags\Console;

use FeatureFlags\Exceptions\FlagSyncException;
use FeatureFlags\FeatureFlags;
use Illuminate\Console\Command;

class WarmCommand extends Command
{
    /** @var string */
    protected $signature = 'featureflags:warm
                            {--retry=3 : Number of retry attempts on failure}
                            {--retry-delay=2 : Seconds between retries (doubles each attempt)}';

    /** @var string */
    protected $description = 'Pre-warm the feature flags cache (run during deployment)';

    public function handle(FeatureFlags $featureFlags): int
    {
        if ($featureFlags->isLocalMode()) {
            $this->info('Local mode enabled - cache warming not needed.');
            return self::SUCCESS;
        }

        $maxRetries = max(1, (int) $this->option('retry'));
        $retryDelay = max(1, (int) $this->option('retry-delay'));
        $attempts = 0;

        $this->info('Warming feature flags cache...');

        while ($attempts < $maxRetries) {
            $attempts++;

            try {
                $startTime = microtime(true);

                $featureFlags->flush();
                $featureFlags->sync();

                $flags = $featureFlags->all();
                $count = count($flags);
                $durationMs = round((microtime(true) - $startTime) * 1000);

                $this->info("✓ Cached {$count} flag(s) in {$durationMs}ms");

                if ($this->option('verbose')) {
                    $this->newLine();
                    $this->line('Flags:');
                    foreach ($flags as $flag) {
                        /** @var bool $enabled */
                        $enabled = $flag['enabled'] ?? false;
                        $status = $enabled ? '<fg=green>enabled</>' : '<fg=yellow>disabled</>';
                        /** @var string $key */
                        $key = $flag['key'] ?? 'unknown';
                        $this->line("  • {$key} ({$status})");
                    }
                }

                return self::SUCCESS;
            } catch (FlagSyncException $e) {
                $this->warn("Attempt {$attempts}/{$maxRetries} failed: {$e->getMessage()}");

                if ($attempts < $maxRetries) {
                    $delay = $retryDelay * (2 ** ($attempts - 1)); // Exponential backoff
                    $this->line("  Retrying in {$delay} second(s)...");
                    sleep($delay);
                }
            } catch (\Throwable $e) {
                $this->error("Unexpected error: {$e->getMessage()}");
                return self::FAILURE;
            }
        }

        $this->error("✗ Failed to warm cache after {$maxRetries} attempt(s)");

        return self::FAILURE;
    }
}
