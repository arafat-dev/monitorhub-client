<?php

namespace CsnMonitor\Support;

use DateTimeInterface;
use Throwable;

/**
 * In-memory N+1 query detector.
 *
 * Framework-agnostic on purpose so the grouping logic stays testable without
 * booting Laravel. The service provider wires Laravel's query events into
 * record() and flushes from the terminating middleware.
 *
 * Overhead per query is one array increment plus (once per unique query
 * shape) a bounded backtrace. Nothing is sent unless a group reaches the
 * configured threshold, and flushing happens after the response is sent.
 */
class QueryIssueCollector
{
    private static $threshold = 15;

    private static $maxIssues = 5;

    private static $maxSamples = 2;

    private static $basePath = null;

    private static $groups = [];

    private static $callerCache = [];

    /**
     * @param  mixed  $threshold
     * @param  mixed  $maxIssues
     * @param  mixed  $maxSamples
     * @param  mixed  $basePath
     */
    public static function configure($threshold = 15, $maxIssues = 5, $maxSamples = 2, $basePath = null): void
    {
        self::$threshold = max(2, (int) $threshold);
        self::$maxIssues = max(1, min(20, (int) $maxIssues));
        self::$maxSamples = max(0, min(5, (int) $maxSamples));
        self::$basePath = $basePath !== null && $basePath !== ''
            ? rtrim((string) $basePath, '/\\')
            : null;
    }

    public static function reset(): void
    {
        self::$groups = [];
        self::$callerCache = [];
    }

    /**
     * @param  array  $bindings
     * @param  mixed  $timeMs
     */
    public static function record(string $sql, array $bindings = [], $timeMs = 0.0): void
    {
        try {
            $fingerprint = self::fingerprint($sql);

            if ($fingerprint === '') {
                return;
            }

            if (! isset(self::$callerCache[$fingerprint])) {
                self::$callerCache[$fingerprint] = self::caller();
            }

            $caller = self::$callerCache[$fingerprint];
            $key = strtolower($fingerprint)."\n".$caller['file'].':'.$caller['line'];

            if (! isset(self::$groups[$key])) {
                self::$groups[$key] = [
                    'query' => self::truncate($fingerprint, 500),
                    'count' => 0,
                    'total_time_ms' => 0.0,
                    'file' => $caller['file'],
                    'line' => $caller['line'],
                    'samples' => [],
                ];
            }

            self::$groups[$key]['count']++;
            self::$groups[$key]['total_time_ms'] += (float) $timeMs;

            if (! empty($bindings)
                && count(self::$groups[$key]['samples']) < self::$maxSamples
            ) {
                self::$groups[$key]['samples'][] = self::truncate(self::flattenBindings($bindings), 200);
            }
        } catch (Throwable $throwable) {
            // Telemetry must never break the monitored application.
        }
    }

    public static function flush(): array
    {
        try {
            $issues = [];

            foreach (self::$groups as $group) {
                if ($group['count'] < self::$threshold) {
                    continue;
                }

                $count = $group['count'];
                $total = round($group['total_time_ms'], 2);
                $location = $group['file'] !== '' ? ' from '.$group['file'].($group['line'] > 0 ? ':'.$group['line'] : '') : '';

                $issues[] = [
                    'query' => $group['query'],
                    'count' => $count,
                    'total_time_ms' => $total,
                    'avg_time_ms' => $count > 0 ? round($group['total_time_ms'] / $count, 2) : 0,
                    'file' => $group['file'],
                    'line' => $group['line'],
                    'sample_bindings' => array_values($group['samples']),
                    'suggestion' => 'Possible N+1: this query ran '.$count.' times'.$location.'. Consider eager loading with with().',
                ];
            }

            usort($issues, function ($a, $b) {
                if ($a['count'] === $b['count']) {
                    return 0;
                }

                return $a['count'] < $b['count'] ? 1 : -1;
            });

            $issues = array_slice($issues, 0, self::$maxIssues);

            self::reset();

            return $issues;
        } catch (Throwable $throwable) {
            self::reset();

            return [];
        }
    }

    private static function fingerprint(string $sql): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($sql));

        return is_string($collapsed) ? $collapsed : '';
    }

    private static function caller(): array
    {
        $fallback = ['file' => '', 'line' => 0];

        try {
            $packageDir = dirname(__DIR__);
            $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);

            foreach ($frames as $frame) {
                if (! isset($frame['file'])) {
                    continue;
                }

                $file = $frame['file'];
                $line = isset($frame['line']) ? (int) $frame['line'] : 0;

                if ($file === __FILE__ || strpos($file, $packageDir) !== false) {
                    continue;
                }

                $path = str_replace('\\', '/', $file);

                if (strpos($path, '/vendor/') !== false) {
                    if ($fallback['file'] === '') {
                        $fallback = ['file' => self::relativePath($file), 'line' => $line];
                    }

                    continue;
                }

                if (self::$basePath !== null && strpos($file, self::$basePath) !== 0) {
                    if ($fallback['file'] === '') {
                        $fallback = ['file' => self::relativePath($file), 'line' => $line];
                    }

                    continue;
                }

                return ['file' => self::relativePath($file), 'line' => $line];
            }
        } catch (Throwable $throwable) {
            // Fall through to the empty fallback below.
        }

        return $fallback;
    }

    private static function relativePath(string $file): string
    {
        if (self::$basePath !== null && strpos($file, self::$basePath) === 0) {
            return ltrim(substr($file, strlen(self::$basePath)), '/\\');
        }

        return self::truncate($file, 300);
    }

    private static function flattenBindings(array $bindings): string
    {
        $parts = [];

        foreach (array_slice($bindings, 0, 10) as $binding) {
            if (is_null($binding)) {
                $parts[] = 'null';
            } elseif (is_bool($binding)) {
                $parts[] = $binding ? 'true' : 'false';
            } elseif (is_scalar($binding)) {
                $parts[] = self::truncate((string) $binding, 50);
            } elseif ($binding instanceof DateTimeInterface) {
                $parts[] = $binding->format('Y-m-d H:i:s');
            } else {
                $parts[] = '[complex]';
            }
        }

        return implode(', ', $parts);
    }

    private static function truncate(string $value, int $length): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }

        return function_exists('mb_strcut')
            ? mb_strcut($value, 0, $length, 'UTF-8')
            : substr($value, 0, $length);
    }
}
