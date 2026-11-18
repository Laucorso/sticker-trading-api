<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Cada usuario solo puede escuchar su propio canal de avisos.
Broadcast::channel('users.{id}', fn (User $user, int $id) => $user->id === $id);
