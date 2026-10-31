<?php

namespace App\Events;

use App\Models\Vehicle;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Optional: with Laravel Reverb installed the map updates instantly; otherwise the page polls. */
class VehicleLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Vehicle $vehicle) {}

    public function broadcastOn(): array { return [new PrivateChannel('dispatch')]; }
    public function broadcastAs(): string { return 'vehicle.moved'; }
}
