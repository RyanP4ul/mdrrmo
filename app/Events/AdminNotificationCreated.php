<?php

namespace App\Events;

use App\Models\AdminNotification;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class AdminNotificationCreated implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    public function __construct(public AdminNotification $notification) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin.notifications');
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return $this->notification->payload();
    }
}
