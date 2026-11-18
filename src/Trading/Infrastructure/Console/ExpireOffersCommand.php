<?php

declare(strict_types=1);

namespace Trading\Infrastructure\Console;

use Illuminate\Console\Command;
use Trading\Application\ExpireDueOffers;

final class ExpireOffersCommand extends Command
{
    protected $signature = 'offers:expire {--batch=500 : Ofertas por ejecución}';

    protected $description = 'Caduca las ofertas abiertas con más de 48 h y libera el cromo reservado';

    public function handle(ExpireDueOffers $expireDueOffers): int
    {
        $count = $expireDueOffers((int) $this->option('batch'));
        $this->info("Ofertas caducadas: {$count}");

        return self::SUCCESS;
    }
}
