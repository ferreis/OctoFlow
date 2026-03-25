<?php

namespace App\Command\Finance;

use App\Finance\FinanceRecurringService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'finance:recurrence:sync',
    description: 'Generate recurring finance entries for upcoming months with idempotency.',
)]
final class FinanceRecurrenceSyncCommand extends Command
{
    public function __construct(
        private readonly FinanceRecurringService $financeRecurringService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('months-ahead', null, InputOption::VALUE_OPTIONAL, 'How many months ahead to generate.', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $monthsAhead = max(1, min(12, (int) $input->getOption('months-ahead')));
        $generatedCount = $this->financeRecurringService->generateDailySync($monthsAhead);

        $io->success(sprintf('Recurring sync completed. Generated entries: %d', $generatedCount));

        return Command::SUCCESS;
    }
}
