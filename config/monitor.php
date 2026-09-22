<?php

return [
    // Central monitor app base URL, e.g. https://monitor.csnbd.com
    'url' => env('MONITOR_URL'),

    // API key issued for THIS project by the central app (Project::api_key)
    'project_key' => env('MONITOR_PROJECT_KEY'),

    // Master switch — set MONITOR_ENABLED=false to silence everything (e.g. locally)
    'enabled' => env('MONITOR_ENABLED', true),

    // Disable request/response logging while keeping exception reporting active.
    'capture_access_logs' => env('MONITOR_CAPTURE_ACCESS_LOGS', true),

    // Automatically push the request-logging middleware onto these route
    // middleware groups. Set to [] and register LogMonitorRequests manually
    // if you want finer control.
    'auto_middleware_groups' => ['web', 'api'],

    // Request/response fields never sent to the central app.
    'except_fields' => [
        'password', 'password_confirmation', 'token', 'api_key',
        'secret', 'card_number', 'cvv', '_token',
    ],

    // URL paths (start-with match) that are never logged or reported —
    // avoid noise from health checks and (if hosted on same domain) monitor's own callback.
    'except_paths' => [
        'up', 'health', '_debugbar', 'favicon.ico', 'robots.txt',
    ],

    // Cap response body size stored per access-log line (bytes) to keep the day-wise files small.
    'max_response_length' => 2000,

    // Source lines captured before and after an application stack frame.
    'source_context_lines' => 15,

    // Limit source capture to the first application frames to keep queued payloads bounded.
    'source_context_frames' => env('MONITOR_SOURCE_CONTEXT_FRAMES', 5),

];
