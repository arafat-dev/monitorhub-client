<?php

namespace CsnMonitor\Http\Middleware;

use Closure;
use CsnMonitor\Jobs\ReportAccessLogToMonitor;
use CsnMonitor\Support\MonitorReporter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogMonitorRequests
{
    private float $startedAt;

    public function handle(Request $request, Closure $next): Response
    {
        $this->startedAt = microtime(true);

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

        $body = $response->getContent();
        $maxLen = (int) config('monitor.max_response_length', 2000);
        if (is_string($body) && strlen($body) > $maxLen) {
            $body = substr($body, 0, $maxLen).'...(truncated)';
        }

        ReportAccessLogToMonitor::dispatch([
            'ip' => $request->ip(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'request_data' => MonitorReporter::sanitize($request->except(['password', 'password_confirmation'])),
            'response' => $body,
            'status' => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $this->startedAt) * 1000, 2),
        ]);
    }
}
