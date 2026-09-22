# MonitorHub Client

Composer package for sending reportable Laravel exceptions and HTTP access logs
to a central MonitorHub installation. The package supports Laravel 7 through
14 and PHP 7.2.5 through PHP 8.x. It is registered through Laravel package
discovery.

## Install

Install the latest stable release from Packagist:

```bash
composer require monitorhub/client
```

## Configure

Create a project in MonitorHub and add its generated settings to the consumer
application's `.env`:

```dotenv
MONITOR_URL=https://monitor.example.com
MONITOR_PROJECT_KEY=generated-project-api-key
MONITOR_ENABLED=true
MONITOR_CAPTURE_ACCESS_LOGS=true
```

Laravel discovers `CsnMonitor\MonitorClientServiceProvider` automatically. No
manual provider, exception handler, or middleware registration is required.

After installing or updating the package, clear cached bootstrap data and
restart long-running queue workers:

```bash
php artisan optimize:clear
php artisan queue:restart
```

Publish the configuration only when the defaults need to be customized:

```bash
php artisan vendor:publish --tag=monitor-config
```

## Queue

Exception and access-log payloads are queued so telemetry does not add latency
to the monitored request. Run a queue worker in production:

```bash
php artisan queue:work
```

Use `QUEUE_CONNECTION=sync` only for local testing when no worker is running.

The package dispatches one access-log job for each actual HTTP request. A
request that throws an exception also dispatches one separate error job. A
single browser action may make multiple HTTP requests, but duplicate middleware
execution for the same request is ignored.

Set `MONITOR_CAPTURE_ACCESS_LOGS=false` to collect exceptions only and avoid an
access-log job for every request.

## Captured Data

- Reportable exceptions, stack frames, request URL, method, and sanitized input
- 15 source lines before and after the failing application stack frames
- Request IP, browser, platform, device type, sanitized URL/input, response status/body, and duration
- Client-side timestamps with timezone offsets

MonitorHub automatically enriches public IP addresses with cached country,
region, city, timezone, and ISP details. No client environment variables are
required. IP geolocation is approximate and cannot provide a verified street
or home address.

Sensitive fields listed in `monitor.except_fields` are recursively redacted.
Binary responses are not captured, and text responses are truncated to the
configured maximum length.

## Manual Capture (Sentry-style)

Report a handled exception from a `catch` block without breaking the app.
Reporting never throws, so the original flow continues:

```php
use CsnMonitor\Monitor;
use Throwable;

try {
    Http::post("{$socketUrl}/event-notify", [...]);
} catch (Throwable $e) {
    Monitor::captureException($e, Monitor::LEVEL_WARNING);
}
```

Levels: `critical` (system-breaking/unhandled), `error` (codebase failure),
`warning`, `info`. Unhandled exceptions captured automatically are `critical`;
manual captures default to `error` when no level is given.

## Updating

```bash
composer update monitorhub/client
```
