<?php

namespace CsnMonitor\Http\Middleware;

use Closure;
use CsnMonitor\Jobs\ReportAccessLogToMonitor;
use CsnMonitor\Support\MonitorReporter;
use CsnMonitor\Support\QueryIssueCollector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogMonitorRequests
{
    private const STARTED_AT = 'monitor.started_at';

    private const DISPATCHED = 'monitor.access_log_dispatched';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::STARTED_AT, microtime(true));

        // Fresh per-request state so long-lived workers (Octane, queue)
        // never leak query counts from a previous execution.
        QueryIssueCollector::reset();

        return $next($request);
    }

    /**
     * Runs after the response has been sent to the browser (see "terminable
     * middleware" in Laravel docs) — logging never adds latency for the user.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! MonitorReporter::shouldHandle()
            || ! config('monitor.capture_access_logs', true)
            || MonitorReporter::isExceptPath($request->path())) {
            return;
        }

        if ($request->attributes->get(self::DISPATCHED, false)) {
            return;
        }

        $request->attributes->set(self::DISPATCHED, true);

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

        $queryIssues = [];
        try {
            if (config('monitor.capture_query_issues', true)) {
                $queryIssues = QueryIssueCollector::flush();
            } else {
                QueryIssueCollector::reset();
            }
        } catch (Throwable $throwable) {
            $queryIssues = [];
        }

        try {
            Bus::dispatch(new ReportAccessLogToMonitor([
                'ip' => $request->ip(),
                'method' => $request->method(),
                'url' => MonitorReporter::sanitizedUrl($request),
                'request_data' => MonitorReporter::sanitize($request->all()),
                'response' => $body,
                'status' => $response->getStatusCode(),
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'context' => MonitorReporter::requestContext($request),
                'query_issues' => $queryIssues !== [] ? $queryIssues : null,
                'logged_at' => now()->toIso8601String(),
            ]));
        } catch (Throwable $throwable) {
            // Telemetry dispatch failures must not affect the monitored response.
        }
    }
}
