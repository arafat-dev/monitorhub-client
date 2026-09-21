<?php

namespace CsnMonitor\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use WeakMap;

/**
 * Wraps the app's real exception handler so every exception is captured
 * for the monitor with zero changes needed in the client app's own
 * Handler.php / bootstrap/app.php. Bound in MonitorClientServiceProvider
 * via $app->extend(ExceptionHandler::class, ...).
 */
class ExceptionHandlerDecorator implements ExceptionHandler
{
    /** Tracks exceptions already sent to the monitor, so report()+render() don't double-send. */
    private static ?WeakMap $reported = null;

    public function __construct(private ExceptionHandler $handler) {}

    public function report(Throwable $e): void
    {
        $this->handler->report($e);

        if ($this->handler->shouldReport($e) && ! $this->alreadyReported($e)) {
            MonitorReporter::reportException($e, app()->runningInConsole() ? null : request());
            $this->markReported($e);
        }
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->handler->shouldReport($e);
    }

    public function render($request, Throwable $e): Response
    {
        return $this->handler->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        $this->handler->renderForConsole($output, $e);
    }

    private function alreadyReported(Throwable $e): bool
    {
        self::$reported ??= new WeakMap;

        return isset(self::$reported[$e]);
    }

    private function markReported(Throwable $e): void
    {
        self::$reported ??= new WeakMap;
        self::$reported[$e] = true;
    }
}
