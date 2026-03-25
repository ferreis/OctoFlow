<?php

namespace App\Command\Finance;

use App\Finance\FinanceEntryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'finance:entries:refresh-status',
    description: 'Refresh overdue status for open finance entries.',
)]
final class FinanceEntryStatusRefreshCommand extends Command
{
    public function __construct(
        private readonly FinanceEntryService $financeEntryService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $updatedEntriesCount = $this->financeEntryService->refreshOverdueStatusesForAllUsers();

        $io->success(sprintf('Overdue status refresh completed. Updated entries: %d', $updatedEntriesCount));

        return Command::SUCCESS;
    }
}
