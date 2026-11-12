<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class CommunicationCallUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public string $id) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('dispatch')];
    }

    public function broadcastAs(): string
    {
        return 'call.updated';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->id];
    }
}
