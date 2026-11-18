<?php

declare(strict_types=1);

namespace Trading\Application\Query;

/** Filtros y paginación del listado de ofertas abiertas. */
final readonly class OpenOffersQuery
{
    public const DEFAULT_PER_PAGE = 20;

    public function __construct(
        public ?int $offeredStickerId = null,
        public ?int $requestedStickerId = null,
        public ?int $authorId = null,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public ?string $cursor = null,
    ) {}
}
