<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Commerce\Support\DiagnosticsReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:diagnose', description: 'Diagnose Commerce extension bindings and database state')]
final class DiagnoseCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = DiagnosticsReport::build($this->getContext());
        $this->line('<info>Commerce contracts</info>');
        $rows = [];
        foreach ($report['contracts'] as $name => $binding) {
            $rows[] = [$name, $binding['source'], $binding['class'] ?? 'none'];
        }
        $this->table(['Contract', 'Source', 'Class'], $rows);
        $this->line('');
        $this->line('Tenancy enabled: ' . ($report['tenancy']['enabled'] ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
