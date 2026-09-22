<?php

namespace CsnMonitor;

use CsnMonitor\Support\MonitorReporter;
use Illuminate\Http\Request;
use Throwable;

class Monitor
{
    const LEVEL_CRITICAL = 'critical';

    const LEVEL_ERROR = 'error';

    const LEVEL_WARNING = 'warning';

    const LEVEL_INFO = 'info';

    public static function levels(): array
    {
        return MonitorReporter::levels();
    }

    /**
     * Capture a handled exception without breaking the app.
     *
     * try {
     *     Http::post($socketUrl.'/event-notify', [...]);
     * } catch (Throwable $e) {
     *     Monitor::captureException($e, Monitor::LEVEL_WARNING);
     * }
     *
     * Levels: critical (system-breaking/unhandled), error (codebase failure),
     * warning, info. Unhandled exceptions reported automatically are critical.
     *
     * @param  mixed  $level
     */
    public static function captureException(Throwable $e, $level = null, ?Request $request = null): void
    {
        MonitorReporter::captureException($e, $level, $request);
    }
}
