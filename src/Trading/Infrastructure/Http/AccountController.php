<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Trading\Application\DeleteAccount;

final class AccountController
{
    /** DELETE /api/me: el usuario borra su propia cuenta. */
    public function __invoke(Request $request, DeleteAccount $deleteAccount): Response
    {
        $deleteAccount((int) $request->user()->id);

        return response()->noContent();
    }
}
