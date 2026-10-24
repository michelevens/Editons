<?php

namespace App\Growth\Jobs;

use App\Growth\Broadcasts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One minute's worth of a broadcast (growth.broadcasts.per_minute). Safe to
 * retry: each recipient row is claimed before it is sent.
 */
class SendBroadcastBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @param  list<int>  $recipientIds */
    public function __construct(public int $broadcastId, public array $recipientIds) {}

    public function handle(Broadcasts $broadcasts): void
    {
        $broadcasts->sendBatch($this->broadcastId, $this->recipientIds);
    }
}
