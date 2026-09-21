<?php

return [
    // Central monitor app base URL, e.g. https://monitor.csnbd.com
    'url' => env('MONITOR_URL'),

    // API key issued for THIS project by the central app (Project::api_key)
    'project_key' => env('MONITOR_PROJECT_KEY'),

    // Master switch — set MONITOR_ENABLED=false to silence everything (e.g. locally)
    'enabled' => env('MONITOR_ENABLED', true),

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
        'up', 'health', '_debugbar',
    ],

    // Cap response body size stored per access-log line (bytes) to keep the day-wise files small.
    'max_response_length' => 2000,

];
