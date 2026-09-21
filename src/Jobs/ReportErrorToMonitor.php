<?php

namespace CsnMonitor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class ReportErrorToMonitor implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = 10;

    public $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $url = rtrim((string) config('monitor.url'), '/');
        if (! config('monitor.enabled') || ! $url || ! config('monitor.project_key')) {
            return;
        }

        Http::withHeaders(['X-Monitor-Key' => config('monitor.project_key')])
            ->timeout(5)
            ->post("{$url}/api/monitor/errors", $this->payload)
            ->throw();
    }
}
