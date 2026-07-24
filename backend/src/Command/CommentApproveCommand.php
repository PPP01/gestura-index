<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\RatingRepository;
use App\Service\ModerationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Konsolen-Command `index:comments:approve` – gibt einen wartenden Kommentar frei.
 */
#[AsCommand(name: 'index:comments:approve', description: 'Gibt einen wartenden Kommentar frei')]
final class CommentApproveCommand extends Command
{
    public function __construct(
        private readonly RatingRepository $ratings,
        private readonly ModerationService $moderation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Rating-ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rating = $this->ratings->find((int) $input->getArgument('id'));
        if ($rating === null) {
            $io->error('Unbekannte Rating-ID');

            return Command::FAILURE;
        }

        try {
            $this->moderation->approveComment($rating);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success('Kommentar freigegeben');

        return Command::SUCCESS;
    }
}
