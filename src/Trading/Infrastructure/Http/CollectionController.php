<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Http;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Trading\Application\Query\TradingReadModel;

final class CollectionController
{
    public function __invoke(int $user, TradingReadModel $readModel): JsonResponse
    {
        abort_unless(User::whereKey($user)->exists(), 404, 'Usuario no encontrado');

        return response()->json(['data' => $readModel->collectionOf($user)]);
    }
}
