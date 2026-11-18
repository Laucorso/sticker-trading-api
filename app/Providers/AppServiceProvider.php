<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Sustituye a un token real (Sanctum/JWT). El resto del código solo ve
        // $request->user(), así que cambiarlo no toca nada más.
        Auth::viaRequest('user-id-header', function (Request $request): ?User {
            $id = $request->header('X-User-Id');

            return is_string($id) && ctype_digit($id) ? User::find((int) $id) : null;
        });
    }
}
