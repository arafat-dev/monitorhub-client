<?php

namespace CsnMonitor\Support;

use CsnMonitor\Jobs\ReportAccessLogToMonitor;
use CsnMonitor\Jobs\ReportErrorToMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use SplFileObject;
use Throwable;

class MonitorReporter
{
    public static function shouldHandle(): bool
    {
        return (bool) config('monitor.enabled') && config('monitor.url') && config('monitor.project_key');
    }

    public static function isExceptPath(string $path): bool
    {
        foreach ((array) config('monitor.except_paths', []) as $except) {
            $except = trim((string) $except, '/');

            if ($path === $except || strpos($path, $except.'/') === 0) {
                return true;
            }
        }

        return false;
    }

    public static function sanitize(array $data): array
    {
        $except = array_map('strtolower', (array) config('monitor.except_fields', []));

        array_walk($data, function (&$value, $key) use ($except): void {
            if (in_array(strtolower((string) $key), $except, true)) {
                $value = '***redacted***';
            } elseif (is_array($value)) {
                $value = self::sanitize($value);
            }
        });

        return $data;
    }

    public static function reportException(Throwable $e, ?Request $request = null): void
    {
        if (! self::shouldHandle() || ($request && self::isExceptPath($request->path()))) {
            return;
        }

        foreach ($e->getTrace() as $frame) {
            if (in_array($frame['class'] ?? null, [
                ReportAccessLogToMonitor::class,
                ReportErrorToMonitor::class,
            ], true)) {
                return;
            }
        }

        try {
            Bus::dispatch(new ReportErrorToMonitor([
                'exception_class' => get_class($e),
                'message' => $e->getMessage() ?: '(no message)',
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'frames' => self::frames($e),
                'url' => $request ? self::sanitizedUrl($request) : null,
                'method' => $request ? $request->method() : null,
                'request_data' => $request ? self::sanitize($request->all()) : null,
                'context' => $request ? self::requestContext($request) : null,
                'occurred_at' => now()->toIso8601String(),
            ]));
        } catch (Throwable $throwable) {
            // Monitoring must never replace or break the application's original failure.
        }
    }

    public static function sanitizedUrl(Request $request): string
    {
        $query = http_build_query(self::sanitize($request->query()));

        return $request->url().($query !== '' ? '?'.$query : '');
    }

    public static function requestContext(Request $request): array
    {
        $userAgent = self::truncate((string) $request->userAgent(), 1024);

        return [
            'ip' => $request->ip(),
            'referer' => self::sanitizedReferer((string) $request->headers->get('referer', '')),
            'accept_language' => self::truncate((string) $request->headers->get('accept-language', ''), 255),
            'client' => self::clientInfo($userAgent, $request),
        ];
    }

    private static function frames(Throwable $e): array
    {
        $frames = [[
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'class' => get_class($e),
            'function' => null,
            'context' => self::sourceContext($e->getFile(), $e->getLine()),
        ]];

        $contextFrameLimit = max(1, min(10, (int) config('monitor.source_context_frames', 5)));

        foreach (array_slice($e->getTrace(), 0, 49) as $index => $frame) {
            $frames[] = [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'class' => $frame['class'] ?? null,
                'function' => $frame['function'] ?? null,
                'context' => $index < $contextFrameLimit - 1
                    ? self::sourceContext($frame['file'] ?? null, $frame['line'] ?? null)
                    : null,
            ];
        }

        return $frames;
    }

    private static function sourceContext(?string $file, ?int $line): ?array
    {
        $radius = max(0, min(20, (int) config('monitor.source_context_lines', 15)));

        if ($radius === 0 || ! $file || ! $line || $line < 1) {
            return null;
        }

        $realFile = realpath($file);
        $applicationPath = realpath(base_path());

        if (! $realFile || ! $applicationPath || ! is_file($realFile) || ! is_readable($realFile)) {
            return null;
        }

        $applicationPrefix = rtrim($applicationPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (strpos($realFile, $applicationPrefix) !== 0 || strtolower((string) pathinfo($realFile, PATHINFO_EXTENSION)) !== 'php') {
            return null;
        }

        $relativePath = str_replace('\\', '/', substr($realFile, strlen($applicationPrefix)));
        foreach (['vendor/', 'storage/', 'bootstrap/cache/'] as $excludedPath) {
            if (strpos($relativePath, $excludedPath) === 0) {
                return null;
            }
        }

        $startLine = max(1, $line - $radius);

        try {
            $source = new SplFileObject($realFile, 'r');
            $pre = [];
            $focus = '';
            $post = [];

            for ($lineNumber = $startLine; $lineNumber <= $line + $radius; $lineNumber++) {
                $source->seek($lineNumber - 1);
                if ($source->eof()) {
                    break;
                }

                $sourceLine = self::truncate(rtrim((string) $source->current(), "\r\n"), 1000);
                if ($lineNumber < $line) {
                    $pre[] = $sourceLine;
                } elseif ($lineNumber === $line) {
                    $focus = $sourceLine;
                } else {
                    $post[] = $sourceLine;
                }
            }

            return [
                'start_line' => $startLine,
                'pre' => $pre,
                'line' => $focus,
                'post' => $post,
            ];
        } catch (Throwable $throwable) {
            return null;
        }
    }

    private static function clientInfo(string $userAgent, Request $request): array
    {
        $browser = null;
        $browserVersion = null;
        $browserPatterns = [
            'Edge' => '/Edg(?:A|iOS)?\/([\d.]+)/i',
            'Opera' => '/OPR\/([\d.]+)/i',
            'Chrome' => '/(?:Chrome|CriOS)\/([\d.]+)/i',
            'Firefox' => '/(?:Firefox|FxiOS)\/([\d.]+)/i',
            'Safari' => '/Version\/([\d.]+).*Safari/i',
        ];

        foreach ($browserPatterns as $name => $pattern) {
            if (preg_match($pattern, $userAgent, $matches) === 1) {
                $browser = $name;
                $browserVersion = $matches[1] ?? null;
                break;
            }
        }

        $platform = trim((string) $request->headers->get('sec-ch-ua-platform', ''), ' "');
        if ($platform === '') {
            if (preg_match('/Android/i', $userAgent) === 1) {
                $platform = 'Android';
            } elseif (preg_match('/iPhone|iPad|iPod/i', $userAgent) === 1) {
                $platform = 'iOS';
            } elseif (preg_match('/Windows/i', $userAgent) === 1) {
                $platform = 'Windows';
            } elseif (preg_match('/Macintosh|Mac OS X/i', $userAgent) === 1) {
                $platform = 'macOS';
            } elseif (preg_match('/Linux/i', $userAgent) === 1) {
                $platform = 'Linux';
            } else {
                $platform = null;
            }
        }

        $mobileHint = (string) $request->headers->get('sec-ch-ua-mobile', '');
        if (preg_match('/iPad|Tablet/i', $userAgent) === 1) {
            $deviceType = 'Tablet';
        } elseif ($mobileHint === '?1' || preg_match('/Mobile|iPhone|Android/i', $userAgent) === 1) {
            $deviceType = 'Mobile';
        } else {
            $deviceType = $userAgent !== '' ? 'Desktop' : null;
        }

        return [
            'browser' => $browser,
            'browser_version' => $browserVersion,
            'platform' => $platform,
            'device_type' => $deviceType,
            'user_agent' => $userAgent !== '' ? $userAgent : null,
        ];
    }

    private static function sanitizedReferer(string $referer): ?string
    {
        if ($referer === '') {
            return null;
        }

        $parts = parse_url($referer);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $url = ($parts['scheme'] ?? 'https').'://'.$parts['host'];
        if (isset($parts['port'])) {
            $url .= ':'.$parts['port'];
        }

        return self::truncate($url.($parts['path'] ?? ''), 2048);
    }

    private static function truncate(string $value, int $length): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }

        return function_exists('mb_strcut')
            ? mb_strcut($value, 0, $length, 'UTF-8')
            : substr($value, 0, $length);
    }
}
