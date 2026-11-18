<?php

declare(strict_types=1);

namespace Trading\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Shared\Application\EventPublisher;
use Shared\Application\TransactionManager;
use Shared\Domain\Clock;
use Shared\Infrastructure\CarbonClock;
use Shared\Infrastructure\DatabaseTransactionManager;
use Trading\Application\Port\Accounts;
use Trading\Application\Port\UserLock;
use Trading\Application\Query\TradingReadModel;
use Trading\Domain\Collection\CollectionRepository;
use Trading\Domain\Offer\OfferRepository;
use Trading\Infrastructure\Broadcasting\LaravelEventPublisher;
use Trading\Infrastructure\Console\ExpireOffersCommand;
use Trading\Infrastructure\Persistence\DbAccounts;
use Trading\Infrastructure\Persistence\DbCollectionRepository;
use Trading\Infrastructure\Persistence\DbOfferRepository;
use Trading\Infrastructure\Persistence\DbTradingReadModel;
use Trading\Infrastructure\Persistence\DbUserLock;

/** Único punto donde se decide qué adaptador implementa cada puerto. */
final class TradingServiceProvider extends ServiceProvider
{
    public array $bindings = [
        Clock::class => CarbonClock::class,
        TransactionManager::class => DatabaseTransactionManager::class,
        EventPublisher::class => LaravelEventPublisher::class,
        UserLock::class => DbUserLock::class,
        Accounts::class => DbAccounts::class,
        OfferRepository::class => DbOfferRepository::class,
        CollectionRepository::class => DbCollectionRepository::class,
        TradingReadModel::class => DbTradingReadModel::class,
    ];

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireOffersCommand::class]);
        }
    }
}
