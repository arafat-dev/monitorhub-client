# MonitorHub Client

Composer package for sending reportable Laravel exceptions and HTTP access logs
to a central MonitorHub installation. The package supports Laravel 10, 11, 12,
and 13 and is registered through Laravel package discovery.

## Install

### Private GitHub repository

Add the repository once in the consumer application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/arafat-dev/monitorhub-client.git"
        }
    ]
}
```

Until a stable version is tagged, install the main branch:

```bash
composer require monitorhub/client:dev-main
```

After creating a `v1.0.0` Git tag, consumers can install a stable constraint:

```bash
composer require monitorhub/client:^1.0
```

If the repository is submitted to Packagist or a private Composer registry, the
`repositories` entry is not needed.

## Configure

Create a project in MonitorHub and add its generated settings to the consumer
application's `.env`:

```dotenv
MONITOR_URL=https://monitor.example.com
MONITOR_PROJECT_KEY=generated-project-api-key
MONITOR_ENABLED=true
```

Laravel discovers `CsnMonitor\MonitorClientServiceProvider` automatically. No
manual provider, exception handler, or middleware registration is required.

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

## Captured Data

- Reportable exceptions, stack frames, request URL, method, and sanitized input
- Request IP, method, sanitized URL/input, response status/body, and duration
- Client-side timestamps with timezone offsets

Sensitive fields listed in `monitor.except_fields` are recursively redacted.
Binary responses are not captured, and text responses are truncated to the
configured maximum length.

## Updating

```bash
composer update monitorhub/client
```
