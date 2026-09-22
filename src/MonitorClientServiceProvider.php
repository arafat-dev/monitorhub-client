<?php

namespace CsnMonitor;

use CsnMonitor\Http\Middleware\LogMonitorRequests;
use CsnMonitor\Support\ExceptionHandlerDecorator;
use CsnMonitor\Support\QueryIssueCollector;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Throwable;

class MonitorClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/monitor.php', 'monitor');

        // Wrap Laravel's real exception handler so every reportable exception
        // is captured automatically — no changes needed in the app's own Handler.
        $this->app->extend(ExceptionHandler::class, function (ExceptionHandler $handler) {
            return new ExceptionHandlerDecorator($handler);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/monitor.php' => config_path('monitor.php'),
        ], 'monitor-config');

        // Auto-attach the request logger to the configured route middleware groups
        // (default: web + api) so installing the package is enough on its own.
        $kernel = $this->app->make(Kernel::class);
        foreach ((array) config('monitor.auto_middleware_groups', []) as $group) {
            $kernel->appendMiddlewareToGroup($group, LogMonitorRequests::class);
        }

        $this->registerQueryListener();
    }

    private function registerQueryListener(): void
    {
        try {
            QueryIssueCollector::configure(
                config('monitor.query_issue_threshold', 15),
                config('monitor.query_issue_max_issues', 5),
                config('monitor.query_issue_max_samples', 2),
                function_exists('base_path') ? base_path() : null
            );

            if (! config('monitor.capture_query_issues', true)) {
                return;
            }

            if (! class_exists(DB::class)) {
                return;
            }

            DB::listen(function ($query): void {
                try {
                    QueryIssueCollector::record(
                        isset($query->sql) ? (string) $query->sql : '',
                        isset($query->bindings) && is_array($query->bindings) ? $query->bindings : [],
                        isset($query->time) ? (float) $query->time : 0.0
                    );
                } catch (Throwable $throwable) {
                    // Query telemetry must never break the monitored application.
                }
            });
        } catch (Throwable $throwable) {
            // Query telemetry must never break the monitored application.
        }
    }
}
