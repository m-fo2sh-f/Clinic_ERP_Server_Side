<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// 🎯 قناة طابور الانتظار الحي الخاصة بالفرع (Live Queue Private Channel)
Broadcast::channel('live-queue.{branchId}', function (User $user, string $branchId) {
    if ($user->hasRole('clinic_owner')) {
        return true;
    }
    return $user->branches()->where('branches.id', $branchId)->exists();
});

// 🎯 قناة التحديثات العامة للفرع (Branch Private Channel)
Broadcast::channel('branch.{branchId}', function (User $user, string $branchId) {
    if ($user->hasRole('clinic_owner')) {
        return true;
    }
    return $user->branches()->where('branches.id', $branchId)->exists();
});

Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});
