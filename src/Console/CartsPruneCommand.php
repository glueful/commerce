<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Commerce\Cart\CartPruner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:carts:prune', description: 'Mark expired active commerce carts as abandoned')]
final class CartsPruneCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pruned = app($this->getContext(), CartPruner::class)->prune($this->getContext());
        $this->info("Pruned {$pruned} cart(s).");

        return self::SUCCESS;
    }
}
