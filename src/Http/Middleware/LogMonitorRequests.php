<?php

namespace CsnMonitor\Http\Middleware;

use Closure;
use CsnMonitor\Jobs\ReportAccessLogToMonitor;
use CsnMonitor\Support\MonitorReporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogMonitorRequests
{
    private const STARTED_AT = 'monitor.started_at';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::STARTED_AT, microtime(true));

        return $next($request);
    }

    /**
     * Runs after the response has been sent to the browser (see "terminable
     * middleware" in Laravel docs) — logging never adds latency for the user.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! MonitorReporter::shouldHandle() || MonitorReporter::isExceptPath($request->path())) {
            return;
        }

        $contentType = (string) $response->headers->get('Content-Type');
        $capturesBody = strpos($contentType, 'text/') !== false
            || strpos($contentType, 'json') !== false
            || strpos($contentType, 'xml') !== false
            || strpos($contentType, 'javascript') !== false;
        $content = $response->getContent();
        $body = $capturesBody && is_string($content) ? $content : null;
        $maxLen = (int) config('monitor.max_response_length', 2000);
        if (is_string($body) && mb_strlen($body, '8bit') > $maxLen) {
            $body = mb_strcut($body, 0, max(0, $maxLen - 14), 'UTF-8').'...(truncated)';
        }

        $startedAt = (float) $request->attributes->get(self::STARTED_AT, microtime(true));

        try {
            Bus::dispatch(new ReportAccessLogToMonitor([
                'ip' => $request->ip(),
                'method' => $request->method(),
                'url' => MonitorReporter::sanitizedUrl($request),
                'request_data' => MonitorReporter::sanitize($request->all()),
                'response' => $body,
                'status' => $response->getStatusCode(),
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'logged_at' => now()->toIso8601String(),
            ]));
        } catch (Throwable) {
            // Telemetry dispatch failures must not affect the monitored response.
        }
    }
}
