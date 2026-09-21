<?php

namespace CsnMonitor\Support;

use CsnMonitor\Jobs\ReportErrorToMonitor;
use Illuminate\Http\Request;
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
            if ($path === $except || str_starts_with($path, trim($except, '/'))) {
                return true;
            }
        }

        return false;
    }

    /** Remove sensitive fields before anything leaves this server. */
    public static function sanitize(array $data): array
    {
        $except = array_map('strtolower', (array) config('monitor.except_fields', []));

        array_walk($data, function (&$value, $key) use ($except, &$data) {
            if (in_array(strtolower((string) $key), $except, true)) {
                $value = '***redacted***';
            } elseif (is_array($value)) {
                $value = self::sanitize($value);
            }
        });

        return $data;
    }

    public static function reportException(Throwable $e, ?Request $request = null, ?string $html = null): void
    {
        if (! self::shouldHandle()) {
            return;
        }

        if ($request && self::isExceptPath($request->path())) {
            return;
        }

        $captureHtml = $html && (! config('monitor.capture_html_only_in_debug') || config('app.debug'));

        ReportErrorToMonitor::dispatch([
            'exception_class' => get_class($e),
            'message' => $e->getMessage() ?: '(no message)',
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'url' => $request?->fullUrl(),
            'method' => $request?->method(),
            'request_data' => $request ? self::sanitize($request->except(['password', 'password_confirmation'])) : null,
            'occurred_at' => now()->toDateTimeString(),
            'html' => $captureHtml ? $html : null,
        ]);
    }
}
