<?php

namespace CsnMonitor;

use CsnMonitor\Http\Middleware\LogMonitorRequests;
use CsnMonitor\Support\ExceptionHandlerDecorator;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;

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
    }
}
