<?php

namespace CsnMonitor\Support;

use CsnMonitor\Jobs\ReportErrorToMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Throwable;

class MonitorReporter
{
    public static function shouldHandle(): bool
    {
        return (bool) config('monitor.enabled') && config('monitor.url') && config('monitor.project_key');
    }

    public static function isExceptPath(string $path): bool
    {
        foreach ((array) config('monitor.except_paths', []) as $except) {
            $except = trim((string) $except, '/');

            if ($path === $except || strpos($path, $except.'/') === 0) {
                return true;
            }
        }

        return false;
    }

    public static function sanitize(array $data): array
    {
        $except = array_map('strtolower', (array) config('monitor.except_fields', []));

        array_walk($data, function (&$value, $key) use ($except): void {
            if (in_array(strtolower((string) $key), $except, true)) {
                $value = '***redacted***';
            } elseif (is_array($value)) {
                $value = self::sanitize($value);
            }
        });

        return $data;
    }

    public static function reportException(Throwable $e, ?Request $request = null): void
    {
        if (! self::shouldHandle() || ($request && self::isExceptPath($request->path()))) {
            return;
        }

        $packageRoot = dirname(__DIR__, 2).DIRECTORY_SEPARATOR;

        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file']) && strpos($frame['file'], $packageRoot) === 0) {
                return;
            }
        }

        try {
            Bus::dispatch(new ReportErrorToMonitor([
                'exception_class' => get_class($e),
                'message' => $e->getMessage() ?: '(no message)',
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'frames' => self::frames($e),
                'url' => $request ? self::sanitizedUrl($request) : null,
                'method' => $request ? $request->method() : null,
                'request_data' => $request ? self::sanitize($request->all()) : null,
                'occurred_at' => now()->toIso8601String(),
            ]));
        } catch (Throwable) {
            // Monitoring must never replace or break the application's original failure.
        }
    }

    public static function sanitizedUrl(Request $request): string
    {
        $query = http_build_query(self::sanitize($request->query()));

        return $request->url().($query !== '' ? '?'.$query : '');
    }

    private static function frames(Throwable $e): array
    {
        $frames = [[
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'class' => get_class($e),
            'function' => null,
        ]];

        foreach (array_slice($e->getTrace(), 0, 49) as $frame) {
            $frames[] = [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'class' => $frame['class'] ?? null,
                'function' => $frame['function'] ?? null,
            ];
        }

        return $frames;
    }
}
