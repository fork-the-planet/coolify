<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CleanupRedis extends Command
{
    protected $signature = 'cleanup:redis {--dry-run : Show what would be deleted without actually deleting} {--skip-overlapping : Skip overlapping queue cleanup} {--clear-locks : Clear stale WithoutOverlapping locks}';

    protected $description = 'Cleanup Redis (Horizon jobs, metrics, overlapping queues, cache locks, and related data)';

    public function handle()
    {
        $redis = Redis::connection('horizon');
        $prefix = config('horizon.prefix');
        $dryRun = $this->option('dry-run');
        $skipOverlapping = $this->option('skip-overlapping');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No data will be deleted');
        }

        $deletedCount = 0;
        $totalKeys = 0;

        // Get all keys with the horizon prefix
        $keys = $redis->keys('*');
        $totalKeys = count($keys);

        $this->info("Scanning {$totalKeys} keys for cleanup...");

        foreach ($keys as $key) {
            $keyWithoutPrefix = str_replace($prefix, '', $key);
            $type = $redis->command('type', [$keyWithoutPrefix]);

            // Handle hash-type keys (individual jobs)
            if ($type === 5) {
                if ($this->shouldDeleteHashKey($redis, $keyWithoutPrefix, $dryRun)) {
                    $deletedCount++;
                }
            }
            // Handle other key types (metrics, lists, etc.)
            else {
                if ($this->shouldDeleteOtherKey($redis, $keyWithoutPrefix, $key, $dryRun)) {
                    $deletedCount++;
                }
            }
        }

        // Clean up overlapping queues if not skipped
        if (! $skipOverlapping) {
            $this->info('Cleaning up overlapping queues...');
            $overlappingCleaned = $this->cleanupOverlappingQueues($redis, $prefix, $dryRun);
            $deletedCount += $overlappingCleaned;
        }

        // Clean up stale cache locks (WithoutOverlapping middleware)
        if ($this->option('clear-locks')) {
            $this->info('Cleaning up stale cache locks...');
            $locksCleaned = $this->cleanupCacheLocks($dryRun);
            $deletedCount += $locksCleaned;
        }

        if ($dryRun) {
            $this->info("DRY RUN: Would delete {$deletedCount} out of {$totalKeys} keys");
        } else {
            $this->info("Deleted {$deletedCount} out of {$totalKeys} keys");
        }
    }

    private function shouldDeleteHashKey($redis, $keyWithoutPrefix, $dryRun)
    {
        $data = $redis->command('hgetall', [$keyWithoutPrefix]);
        $status = data_get($data, 'status');

        // Delete completed and failed jobs
        if (in_array($status, ['completed', 'failed'])) {
            if ($dryRun) {
                $this->line("Would delete job: {$keyWithoutPrefix} (status: {$status})");
            } else {
                $redis->command('del', [$keyWithoutPrefix]);
                $this->line("Deleted job: {$keyWithoutPrefix} (status: {$status})");
            }

            return true;
        }

        return false;
    }

    private function shouldDeleteOtherKey($redis, $keyWithoutPrefix, $fullKey, $dryRun)
    {
        // Clean up various Horizon data structures
        $patterns = [
            'recent_jobs' => 'Recent jobs list',
            'failed_jobs' => 'Failed jobs list',
            'completed_jobs' => 'Completed jobs list',
            'job_classes' => 'Job classes metrics',
            'queues' => 'Queue metrics',
            'processes' => 'Process metrics',
            'supervisors' => 'Supervisor data',
            'metrics' => 'General metrics',
            'workload' => 'Workload data',
        ];

        foreach ($patterns as $pattern => $description) {
            if (str_contains($keyWithoutPrefix, $pattern)) {
                if ($dryRun) {
                    $this->line("Would delete {$description}: {$keyWithoutPrefix}");
                } else {
                    $redis->command('del', [$keyWithoutPrefix]);
                    $this->line("Deleted {$description}: {$keyWithoutPrefix}");
                }

                return true;
            }
        }

        // Clean up old timestamped data (older than 7 days)
        if (preg_match('/(\d{10})/', $keyWithoutPrefix, $matches)) {
            $timestamp = (int) $matches[1];
            $weekAgo = now()->subDays(7)->timestamp;

            if ($timestamp < $weekAgo) {
                if ($dryRun) {
                    $this->line("Would delete old timestamped data: {$keyWithoutPrefix}");
                } else {
                    $redis->command('del', [$keyWithoutPrefix]);
                    $this->line("Deleted old timestamped data: {$keyWithoutPrefix}");
                }

                return true;
            }
        }

        return false;
    }

    private function cleanupOverlappingQueues($redis, $prefix, $dryRun)
    {
        $cleanedCount = 0;
        $queueKeys = [];

        // Find all queue-related keys
        $allKeys = $redis->keys('*');
        foreach ($allKeys as $key) {
            $keyWithoutPrefix = str_replace($prefix, '', $key);
            if (str_contains($keyWithoutPrefix, 'queue:') || preg_match('/queues?[:\-]/', $keyWithoutPrefix)) {
                $queueKeys[] = $keyWithoutPrefix;
            }
        }

        $this->info('Found '.count($queueKeys).' queue-related keys');

        // Group queues by name pattern to find duplicates
        $queueGroups = [];
        foreach ($queueKeys as $queueKey) {
            // Extract queue name (remove timestamps, suffixes)
            $baseName = preg_replace('/[:\-]\d+$/', '', $queueKey);
            $baseName = preg_replace('/[:\-](pending|reserved|delayed|processing)$/', '', $baseName);

            if (! isset($queueGroups[$baseName])) {
                $queueGroups[$baseName] = [];
            }
            $queueGroups[$baseName][] = $queueKey;
        }

        // Process each group for overlaps
        foreach ($queueGroups as $baseName => $keys) {
            if (count($keys) > 1) {
                $cleanedCount += $this->deduplicateQueueGroup($redis, $baseName, $keys, $dryRun);
            }

            // Also check for duplicate jobs within individual queues
            foreach ($keys as $queueKey) {
                $cleanedCount += $this->deduplicateQueueContents($redis, $queueKey, $dryRun);
            }
        }

        return $cleanedCount;
    }

    private function deduplicateQueueGroup($redis, $baseName, $keys, $dryRun)
    {
        $cleanedCount = 0;
        $this->line("Processing queue group: {$baseName} (".count($keys).' keys)');

        // Sort keys to keep the most recent one
        usort($keys, function ($a, $b) {
            // Prefer keys without timestamps (they're usually the main queue)
            $aHasTimestamp = preg_match('/\d{10}/', $a);
            $bHasTimestamp = preg_match('/\d{10}/', $b);

            if ($aHasTimestamp && ! $bHasTimestamp) {
                return 1;
            }
            if (! $aHasTimestamp && $bHasTimestamp) {
                return -1;
            }

            // If both have timestamps, prefer the newer one
            if ($aHasTimestamp && $bHasTimestamp) {
                preg_match('/(\d{10})/', $a, $aMatches);
                preg_match('/(\d{10})/', $b, $bMatches);

                return ($bMatches[1] ?? 0) <=> ($aMatches[1] ?? 0);
            }

            return strcmp($a, $b);
        });

        // Keep the first (preferred) key, remove others that are empty or redundant
        $keepKey = array_shift($keys);

        foreach ($keys as $redundantKey) {
            $type = $redis->command('type', [$redundantKey]);
            $shouldDelete = false;

            if ($type === 1) { // LIST type
                $length = $redis->command('llen', [$redundantKey]);
                if ($length == 0) {
                    $shouldDelete = true;
                }
            } elseif ($type === 3) { // SET type
                $count = $redis->command('scard', [$redundantKey]);
                if ($count == 0) {
                    $shouldDelete = true;
                }
            } elseif ($type === 4) { // ZSET type
                $count = $redis->command('zcard', [$redundantKey]);
                if ($count == 0) {
                    $shouldDelete = true;
                }
            }

            if ($shouldDelete) {
                if ($dryRun) {
                    $this->line("  Would delete empty queue: {$redundantKey}");
                } else {
                    $redis->command('del', [$redundantKey]);
                    $this->line("  Deleted empty queue: {$redundantKey}");
                }
                $cleanedCount++;
            }
        }

        return $cleanedCount;
    }

    private function deduplicateQueueContents($redis, $queueKey, $dryRun)
    {
        $cleanedCount = 0;
        $type = $redis->command('type', [$queueKey]);

        if ($type === 1) { // LIST type - common for job queues
            $length = $redis->command('llen', [$queueKey]);
            if ($length > 1) {
                $items = $redis->command('lrange', [$queueKey, 0, -1]);
                $uniqueItems = array_unique($items);

                if (count($uniqueItems) < count($items)) {
                    $duplicates = count($items) - count($uniqueItems);

                    if ($dryRun) {
                        $this->line("  Would remove {$duplicates} duplicate jobs from queue: {$queueKey}");
                    } else {
                        // Rebuild the list with unique items
                        $redis->command('del', [$queueKey]);
                        foreach (array_reverse($uniqueItems) as $item) {
                            $redis->command('lpush', [$queueKey, $item]);
                        }
                        $this->line("  Removed {$duplicates} duplicate jobs from queue: {$queueKey}");
                    }
                    $cleanedCount += $duplicates;
                }
            }
        }

        return $cleanedCount;
    }

    private function cleanupCacheLocks(bool $dryRun): int
    {
        $cleanedCount = 0;

        // Use the default Redis connection (database 0) where cache locks are stored
        $redis = Redis::connection('default');

        // Get all keys matching WithoutOverlapping lock pattern
        $allKeys = $redis->keys('*');
        $lockKeys = [];

        foreach ($allKeys as $key) {
            // Match cache lock keys: they contain 'laravel-queue-overlap'
            if (str_contains($key, 'laravel-queue-overlap')) {
                $lockKeys[] = $key;
            }
        }

        if (empty($lockKeys)) {
            $this->info('  No cache locks found.');

            return 0;
        }

        $this->info('  Found '.count($lockKeys).' cache lock(s)');

        foreach ($lockKeys as $lockKey) {
            // Check TTL to identify stale locks
            $ttl = $redis->ttl($lockKey);

            // TTL = -1 means no expiration (stale lock!)
            // TTL = -2 means key doesn't exist
            // TTL > 0 means lock is valid and will expire
            if ($ttl === -1) {
                if ($dryRun) {
                    $this->warn("  Would delete STALE lock (no expiration): {$lockKey}");
                } else {
                    $redis->del($lockKey);
                    $this->info("  ✓ Deleted STALE lock: {$lockKey}");
                }
                $cleanedCount++;
            } elseif ($ttl > 0) {
                $this->line("  Skipping active lock (expires in {$ttl}s): {$lockKey}");
            }
        }

        if ($cleanedCount === 0) {
            $this->info('  No stale locks found (all locks have expiration set)');
        }

        return $cleanedCount;
    }
}
