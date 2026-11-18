<?php

use Illuminate\Support\Facades\Route;
use Trading\Application\Query\TradingReadModel;
use Trading\Infrastructure\Http\AccountController;
use Trading\Infrastructure\Http\CollectionController;
use Trading\Infrastructure\Http\OfferController;

Route::middleware('auth:api')->group(function (): void {
    Route::get('/offers', [OfferController::class, 'index']);
    Route::post('/offers', [OfferController::class, 'store']);
    Route::post('/offers/{offer}/accept', [OfferController::class, 'accept'])->whereNumber('offer');
    Route::post('/offers/{offer}/withdraw', [OfferController::class, 'withdraw'])->whereNumber('offer');

    Route::delete('/me', AccountController::class);

    Route::get('/users/{user}/collection', CollectionController::class)->whereNumber('user');
    Route::get('/stickers', fn (TradingReadModel $readModel) => ['data' => $readModel->stickers()]);
});
