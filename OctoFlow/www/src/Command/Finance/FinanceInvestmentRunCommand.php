<?php

namespace App\Command\Finance;

use App\Finance\FinanceInvestmentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'finance:investment:run',
    description: 'Generate monthly investment plan entries.',
)]
final class FinanceInvestmentRunCommand extends Command
{
    public function __construct(
        private readonly FinanceInvestmentService $financeInvestmentService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('months-ahead', null, InputOption::VALUE_OPTIONAL, 'How many months ahead to generate.', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $monthsAhead = max(0, min(6, (int) $input->getOption('months-ahead')));
        $generatedRunsCount = $this->financeInvestmentService->runMonthlyPlans($monthsAhead);

        $io->success(sprintf('Investment plan run completed. Generated runs: %d', $generatedRunsCount));

        return Command::SUCCESS;
    }
}
