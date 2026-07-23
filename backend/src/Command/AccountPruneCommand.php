<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Account;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Konsolen-Command `index:account:prune` – löscht anonyme End-Nutzer-Konten,
 * deren letzte Aktivität (lastSeenAt) länger als die angegebene Frist zurückliegt
 * (Default 365 Tage). Datensparsamkeit. Sobald der Settings-Sync existiert,
 * müssen zugehörige Sync-Blobs hier mitgelöscht werden (dort umzusetzen).
 */
#[AsCommand(name: 'index:account:prune', description: 'Löscht Konten, die länger als N Tage inaktiv sind')]
final class AccountPruneCommand extends Command
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('days', InputArgument::OPTIONAL, 'Inaktivitätsschwelle in Tagen', '365');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, (int) $input->getArgument('days'));
        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $days));

        /** @var list<Account> $stale */
        $stale = $this->accounts->createQueryBuilder('a')
            ->where('a.lastSeenAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        foreach ($stale as $account) {
            $this->em->remove($account);
        }
        $this->em->flush();

        $io->success(sprintf('%d inaktive Konten gelöscht (älter als %d Tage).', count($stale), $days));

        return Command::SUCCESS;
    }
}
