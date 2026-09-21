<?php

namespace CsnMonitor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class ReportAccessLogToMonitor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(public array $payload) {}

    public function handle(): void
    {
        $url = rtrim((string) config('monitor.url'), '/');
        if (! $url || ! config('monitor.project_key')) {
            return;
        }

        Http::withHeaders(['X-Monitor-Key' => config('monitor.project_key')])
            ->timeout(5)
            ->post("{$url}/api/monitor/logs", $this->payload);
    }
}
