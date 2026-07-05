<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Commerce\Orders\ExpiryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:orders:expire', description: 'Expire stale pending-payment commerce orders')]
final class OrdersExpireCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expired = app($this->getContext(), ExpiryService::class)->expireStale($this->getContext());
        $this->info("Expired {$expired} order(s).");

        return self::SUCCESS;
    }
}
