<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Trading\Application\AcceptOffer;
use Trading\Application\PublishOffer;
use Trading\Application\Query\TradingReadModel;
use Trading\Application\WithdrawOffer;
use Trading\Infrastructure\Http\Requests\ListOffersRequest;
use Trading\Infrastructure\Http\Requests\PublishOfferRequest;

/** Adaptador HTTP: la forma la validan las FormRequest; aquí solo se delega en el caso de uso. */
final class OfferController
{
    public function index(ListOffersRequest $request, TradingReadModel $readModel): JsonResponse
    {
        return response()->json($readModel->openOffers($request->toQuery()));
    }

    public function store(PublishOfferRequest $request, PublishOffer $publishOffer): JsonResponse
    {
        $offer = $publishOffer(
            authorId: $request->authorId(),
            offeredStickerId: $request->offeredStickerId(),
            requestedStickerId: $request->requestedStickerId(),
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
