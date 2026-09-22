<?php

namespace CsnMonitor\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use SplObjectStorage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Wraps the app's real exception handler so every exception is captured
 * for the monitor with zero changes needed in the client app's own
 * Handler.php / bootstrap/app.php. Bound in MonitorClientServiceProvider
 * via $app->extend(ExceptionHandler::class, ...).
 */
class ExceptionHandlerDecorator implements ExceptionHandler
{
    /** Tracks exceptions already sent to the monitor, so report()+render() don't double-send. */
    private static $reported;

    private $handler;

    public function __construct(ExceptionHandler $handler)
    {
        $this->handler = $handler;
    }

    public function report(Throwable $e): void
    {
        $this->handler->report($e);

        if ($this->handler->shouldReport($e) && ! $this->alreadyReported($e)) {
            MonitorReporter::reportException(
                $e,
                app()->runningInConsole() ? null : request(),
                MonitorReporter::LEVEL_CRITICAL
            );
            $this->markReported($e);
        }
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->handler->shouldReport($e);
    }

    public function render($request, Throwable $e): Response
    {
        try {
            return $this->handler->render($request, $e);
        } finally {
            $this->forgetReported($e);
        }
    }

    public function renderForConsole($output, Throwable $e): void
    {
        try {
            $this->handler->renderForConsole($output, $e);
        } finally {
            $this->forgetReported($e);
        }
    }

    private function alreadyReported(Throwable $e): bool
    {
        if (! self::$reported) {
            self::$reported = new SplObjectStorage;
        }

        return self::$reported->contains($e);
    }

    private function markReported(Throwable $e): void
    {
        if (! self::$reported) {
            self::$reported = new SplObjectStorage;
        }

        self::$reported->attach($e);
    }

    private function forgetReported(Throwable $e): void
    {
        if (self::$reported && self::$reported->contains($e)) {
            self::$reported->detach($e);
        }
    }
}
