<?php

declare(strict_types=1);

namespace FeatureFlags\Console;

use FeatureFlags\Contracts\FeatureFlagsInterface;
use Illuminate\Console\Command;

class DumpCommand extends Command
{
    protected $signature = 'featureflags:dump
                            {--format=php : Output format (php, json, yaml)}
                            {--output= : Output file path (defaults to stdout)}';

    protected $description = 'Export current flags to local config format for offline development';

    public function handle(FeatureFlagsInterface $featureFlags): int
    {
        if ($featureFlags->isLocalMode()) {
            $this->error('Cannot dump flags while in local mode. Disable FEATUREFLAGS_LOCAL_MODE first.');
            return self::FAILURE;
        }

        $this->info('Fetching flags from API...');

        $featureFlags->sync();
        $flags = $featureFlags->all();

        if (empty($flags)) {
            $this->warn('No flags found.');
            return self::SUCCESS;
        }

        /** @var string $format */
        $format = $this->option('format') ?? 'php';
        /** @var string|null $output */
        $output = $this->option('output');

        $localFlags = $this->transformToLocalFormat($flags);

        $content = match ($format) {
            'json' => $this->formatAsJson($localFlags),
            'yaml' => $this->formatAsYaml($localFlags),
            default => $this->formatAsPhp($localFlags),
        };

        if ($output !== null && $output !== '') {
            if (!$this->writeToFile($output, $content)) {
                return self::FAILURE;
            }
            $this->info("Exported " . count($flags) . " flag(s) to {$output}");
        } else {
            $this->line('');
            $this->line($content);
        }

        $this->newLine();
        $this->info('To use these flags locally:');
        $this->line('1. Set FEATUREFLAGS_LOCAL_MODE=true in your .env');
        $this->line('2. Copy the flags above to your config/featureflags.php under local.flags');

        return self::SUCCESS;
    }

    /**
     * @param array<int, array<string, mixed>> $flags
     * @return array<string, mixed>
     */
    private function transformToLocalFormat(array $flags): array
    {
        /** @var array<string, mixed> $local */
        $local = [];

        foreach ($flags as $flag) {
            /** @var string $key */
            $key = $flag['key'] ?? '';
            if ($key === '') {
                continue;
            }

            /** @var mixed $value */
            $value = $flag['default_value'] ?? true;
            /** @var int|null $rollout */
            $rollout = $flag['rollout_percentage'] ?? null;
            /** @var bool $enabled */
            $enabled = $flag['enabled'] ?? false;

            // If flag is disabled, set to false
            if (!$enabled) {
                $local[$key] = false;
                continue;
            }

            // If there's a rollout percentage, include it
            if ($rollout !== null && $rollout < 100) {
                $local[$key] = [
                    'value' => $value,
                    'rollout' => $rollout,
                ];
                continue;
            }

            // Simple value
            $local[$key] = $value;
        }

        return $local;
    }

    /**
     * @param array<string, mixed> $flags
     */
    private function formatAsPhp(array $flags): string
    {
        $output = "// Add these to config/featureflags.php under 'local' => ['flags' => [...]]\n";
        $output .= "'flags' => [\n";

        foreach ($flags as $key => $value) {
            $output .= "    '{$key}' => " . $this->varExport($value) . ",\n";
        }

        $output .= "],";

        return $output;
    }

    /**
     * @param array<string, mixed> $flags
     */
    private function formatAsJson(array $flags): string
    {
        $result = json_encode($flags, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return $result !== false ? $result : '{}';
    }

    /**
     * @param array<string, mixed> $flags
     */
    private function formatAsYaml(array $flags): string
    {
        $output = "# Feature flags for local development\n";
        $output .= "# Copy to config/featureflags.php local.flags section\n\n";

        foreach ($flags as $key => $value) {
            if (is_array($value)) {
                $output .= "{$key}:\n";
                /** @var mixed $innerValue */
                $innerValue = $value['value'] ?? null;
                /** @var int|null $rollout */
                $rollout = $value['rollout'] ?? null;
                $output .= "  value: " . $this->yamlValue($innerValue) . "\n";
                if ($rollout !== null) {
                    $output .= "  rollout: {$rollout}\n";
                }
            } else {
                $output .= "{$key}: " . $this->yamlValue($value) . "\n";
            }
        }

        return $output;
    }

    private function varExport(mixed $value): string
    {
        return var_export($value, true);
    }

    private function yamlValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            return "'{$value}'";
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $k => $v) {
                $parts[] = "  {$k}: " . $this->yamlValue($v);
            }
            return "\n" . implode("\n", $parts);
        }

        return 'null';
    }

    private function writeToFile(string $path, string $content): bool
    {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                $this->error("Failed to create directory: {$directory}");
                return false;
            }
        }

        if (!is_writable($directory)) {
            $this->error("Directory is not writable: {$directory}");
            return false;
        }

        $result = file_put_contents($path, $content);

        if ($result === false) {
            $this->error("Failed to write to file: {$path}");
            return false;
        }

        return true;
    }
}
