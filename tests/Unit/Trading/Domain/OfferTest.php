<?php

declare(strict_types=1);

namespace Tests\Unit\Trading\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Trading\Domain\Exception\CannotAcceptOwnOffer;
use Trading\Domain\Exception\NotOfferAuthor;
use Trading\Domain\Exception\OfferAlreadyExpired;
use Trading\Domain\Exception\OfferNotOpen;
use Trading\Domain\Exception\SameStickerOffer;
use Trading\Domain\Offer\Offer;
use Trading\Domain\Offer\OfferStatus;

final class OfferTest extends TestCase
{
    private const AUTHOR = 1;

    private const OTHER = 2;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-11-18 12:00:00');
    }

    #[Test]
    public function it_is_published_open_and_expires_in_48_hours(): void
    {
        $offer = Offer::publish(self::AUTHOR, 10, 20, $this->now);

        $this->assertSame(OfferStatus::Open, $offer->status());
        $this->assertEquals(new DateTimeImmutable('2026-11-20 12:00:00'), $offer->expiresAt());
    }

    #[Test]
    public function it_cannot_offer_and_request_the_same_sticker(): void
    {
        $this->expectException(SameStickerOffer::class);

        Offer::publish(self::AUTHOR, 10, 10, $this->now);
    }

    #[Test]
    public function another_user_can_accept_it(): void
    {
        $offer = $this->persistedOffer();

        $event = $offer->accept(self::OTHER, $this->now);

        $this->assertSame(OfferStatus::Accepted, $offer->status());
        $this->assertSame(self::OTHER, $offer->acceptedBy());
        $this->assertSame(self::AUTHOR, $event->authorId);
        $this->assertSame(self::OTHER, $event->acceptorId);
    }

    #[Test]
    public function the_author_cannot_accept_it(): void
    {
        $this->expectException(CannotAcceptOwnOffer::class);

        $this->persistedOffer()->accept(self::AUTHOR, $this->now);
    }

    #[Test]
    public function it_can_only_be_accepted_once(): void
    {
        $offer = $this->persistedOffer();
        $offer->accept(self::OTHER, $this->now);

        $this->expectException(OfferNotOpen::class);

        $offer->accept(3, $this->now);
    }

    #[Test]
    public function it_cannot_be_accepted_once_expired_even_if_not_swept_yet(): void
    {
        $offer = $this->persistedOffer();

        $this->expectException(OfferAlreadyExpired::class);

        $offer->accept(self::OTHER, $this->now->modify('+48 hours'));
    }

    #[Test]
    public function only_the_author_can_withdraw_it(): void
    {
        $this->expectException(NotOfferAuthor::class);

        $this->persistedOffer()->withdraw(self::OTHER, $this->now);
    }

    #[Test]
    public function a_withdrawn_offer_cannot_be_accepted(): void
    {
        $offer = $this->persistedOffer();
        $offer->withdraw(self::AUTHOR, $this->now);

        $this->assertSame(OfferStatus::Withdrawn, $offer->status());
        $this->expectException(OfferNotOpen::class);

        $offer->accept(self::OTHER, $this->now);
    }

    #[Test]
    public function expiring_is_idempotent_and_only_applies_after_ttl(): void
    {
        $offer = $this->persistedOffer();

        $this->assertFalse($offer->expire($this->now->modify('+47 hours')));
        $this->assertTrue($offer->expire($this->now->modify('+48 hours')));
        $this->assertFalse($offer->expire($this->now->modify('+49 hours')));
        $this->assertSame(OfferStatus::Expired, $offer->status());
    }

    #[Test]
    public function an_accepted_offer_never_expires(): void
    {
        $offer = $this->persistedOffer();
        $offer->accept(self::OTHER, $this->now);

        $this->assertFalse($offer->expire($this->now->modify('+3 days')));
        $this->assertSame(OfferStatus::Accepted, $offer->status());
    }

    private function persistedOffer(): Offer
    {
        $new = Offer::publish(self::AUTHOR, 10, 20, $this->now);

        return Offer::reconstitute(1, $new->authorId(), 10, 20, $new->status(), $new->createdAt(), $new->expiresAt(), null, null);
    }
}
