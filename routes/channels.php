<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('dispatch', function (User $user): bool {
    return $user->status === 'active' && $user->hasRole('dispatcher');
});

Broadcast::channel('drivers.{id}', function (User $user, string $id): bool {
    return $user->status === 'active' && $user->hasRole('driver/responder') && (string) $user->id === $id;
}, ['guards' => ['sanctum']]);
