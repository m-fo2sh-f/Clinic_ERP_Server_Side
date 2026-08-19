<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QueueReordered implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $branchId;

    public function __construct(string $branchId)
    {
        $this->branchId = $branchId;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('live-queue.' . $this->branchId),
            new Channel('live-queue.' . $this->branchId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'QueueReordered';
    }
}