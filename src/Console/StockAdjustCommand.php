<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Commerce\Inventory\InventoryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:stock:adjust', description: 'Adjust commerce stock for a variant')]
final class StockAdjustCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('variant-uuid', InputArgument::REQUIRED, 'Variant uuid');
        $this->addArgument('delta', InputArgument::REQUIRED, 'Positive or negative stock delta');
        $this->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Movement reason', 'manual');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $variantUuid = (string) $input->getArgument('variant-uuid');
        $delta = (int) $input->getArgument('delta');
        $reason = (string) $input->getOption('reason');
        $quantity = app($this->getContext(), InventoryService::class)
            ->adjust($this->getContext(), $variantUuid, $delta, $reason);

        $this->info("Stock for {$variantUuid} is now {$quantity}.");

        return self::SUCCESS;
    }
}
