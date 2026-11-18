<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Trading\Application\AcceptOffer;
use Trading\Application\PublishOffer;
use Trading\Application\Query\TradingReadModel;
use Trading\Application\WithdrawOffer;

/** Adaptador HTTP: valida forma, extrae el usuario y delega en el caso de uso. */
final class OfferController
{
    public function index(Request $request, TradingReadModel $readModel): JsonResponse
    {
        $filters = $request->validate([
            'offered_sticker_id' => ['nullable', 'integer'],
            'requested_sticker_id' => ['nullable', 'integer'],
            'author_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ]);

        return response()->json($readModel->openOffers(
            offeredStickerId: isset($filters['offered_sticker_id']) ? (int) $filters['offered_sticker_id'] : null,
            requestedStickerId: isset($filters['requested_sticker_id']) ? (int) $filters['requested_sticker_id'] : null,
            authorId: isset($filters['author_id']) ? (int) $filters['author_id'] : null,
            perPage: (int) ($filters['per_page'] ?? 20),
            cursor: $filters['cursor'] ?? null,
        ));
    }

    public function store(Request $request, PublishOffer $publishOffer): JsonResponse
    {
        $data = $request->validate([
            'offered_sticker_id' => ['required', 'integer', 'exists:stickers,id'],
            'requested_sticker_id' => ['required', 'integer', 'exists:stickers,id'],
        ]);

        $offer = $publishOffer(
            authorId: (int) $request->user()->id,
            offeredStickerId: (int) $data['offered_sticker_id'],
            requestedStickerId: (int) $data['requested_sticker_id'],
        );

        return response()->json(['data' => OfferPresenter::toArray($offer)], 201);
    }

    public function accept(int $offer, Request $request, AcceptOffer $acceptOffer): JsonResponse
    {
        $accepted = $acceptOffer($offer, (int) $request->user()->id);

        return response()->json(['data' => OfferPresenter::toArray($accepted)]);
    }

    public function withdraw(int $offer, Request $request, WithdrawOffer $withdrawOffer): JsonResponse
    {
        $withdrawn = $withdrawOffer($offer, (int) $request->user()->id);

        return response()->json(['data' => OfferPresenter::toArray($withdrawn)]);
    }
}
