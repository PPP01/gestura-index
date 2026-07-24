<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\RatingRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Konsolen-Command `index:comments:queue` – listet Bewertungen mit wartendem
 * Kommentar (Moderations-Warteschlange), älteste zuerst.
 */
#[AsCommand(name: 'index:comments:queue', description: 'Zeigt wartende Kommentare')]
final class CommentQueueCommand extends Command
{
    public function __construct(private readonly RatingRepository $ratings)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rows = [];
        foreach ($this->ratings->pendingComments() as $r) {
            $rows[] = [$r->id, $r->entry->formatId, $r->stars, mb_substr((string) $r->comment, 0, 60), $r->createdAt->format('Y-m-d H:i')];
        }
        $rows === [] ? $io->text('leer') : $io->table(['id', 'formatId', 'Sterne', 'Kommentar', 'erstellt'], $rows);

        return Command::SUCCESS;
    }
}
