<?php

namespace App\Command\Finance;

use App\Finance\FinanceExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'finance:exports:process',
    description: 'Process queued finance export jobs.',
)]
final class FinanceExportsProcessCommand extends Command
{
    public function __construct(
        private readonly FinanceExportService $financeExportService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum number of jobs to process.', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = max(1, min(100, (int) $input->getOption('limit')));
        $result = $this->financeExportService->processQueuedJobs($limit);

        $io->success(sprintf(
            'Finance export processing completed. Processed: %d | Failed: %d',
            (int) ($result['processed'] ?? 0),
            (int) ($result['failed'] ?? 0),
        ));

        return Command::SUCCESS;
    }
}
