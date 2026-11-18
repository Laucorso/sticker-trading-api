<?php

declare(strict_types=1);

namespace Trading\Domain\Offer;

enum OfferStatus: string
{
    case Open = 'open';
    case Accepted = 'accepted';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
}
