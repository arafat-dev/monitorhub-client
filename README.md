# csn/monitorhub-client

Drop this into any Laravel project (Helpdesk, CRM, CSN Pay, etc.) to report
its errors and access logs into the central MonitorHub app. Installing it is
the only step needed — no edits to `Handler.php`, `Kernel.php`, or
`bootstrap/app.php`.

## Install (private package, not on Packagist)

Put this folder somewhere shared, e.g. `packages/monitorhub-client/`, then in
the client project's `composer.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "packages/monitorhub-client" }
    ],
    "require": {
        "csn/monitorhub-client": "*"
    }
}
```

```bash
composer update csn/monitorhub-client
```

(Once things stabilize you can instead push this to a private GitHub repo
and use a `vcs` repository, or your own Composer/Satis registry, so every
project pulls the same versioned copy.)

## Configure

Add to `.env`:

```
MONITOR_URL=https://monitor.csnbd.com
MONITOR_PROJECT_KEY=the-api-key-from-the-projects-table
MONITOR_ENABLED=true
```

That's it. On the next request:
- Any reportable exception is captured (with the rendered error-page HTML
  when `APP_DEBUG=true`, used server-side for the screenshot) and queued
  off to the central app.
- Every request/response is logged (IP, params/body, response, status,
  duration) and queued off to append to today's log file for this project.

Optionally publish the config to tweak excluded fields/paths:

```bash
php artisan vendor:publish --tag=monitor-config
```

## Requirements
- A queue worker running in the client project (`php artisan queue:work`),
  since both exception and access-log reporting happen via queued jobs so
  they never add latency to real requests. If no queue worker is running,
  switch `QUEUE_CONNECTION=sync` temporarily only for testing.
