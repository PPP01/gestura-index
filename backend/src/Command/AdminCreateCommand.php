<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AdminInvite;
use App\Entity\AdminUser;
use App\Enum\AdminRole;
use App\Repository\AdminUserRepository;
use App\Service\AuditLogger;
use App\Service\InviteMailer;
use App\Service\InviteTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Konsolen-Command `index:admin:create` – legt den (typischerweise ersten)
 * Admin-Nutzer per Bootstrap an: erzeugt einen `invited`-AdminUser samt
 * AdminInvite (72h gültig), verschickt die Einladungsmail und protokolliert
 * die Aktion im Audit-Log. Der Registrierungslink wird zusätzlich auf der
 * Konsole ausgegeben, falls die Mail nicht ankommt.
 */
#[AsCommand(name: 'index:admin:create', description: 'Legt einen eingeladenen Admin an und verschickt die Einladung')]
final class AdminCreateCommand extends Command
{
    /**
     * Nimmt Repository, EntityManager und die Invite-/Audit-Services per Dependency Injection entgegen.
     */
    public function __construct(
        private readonly AdminUserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly InviteTokenService $tokens,
        private readonly InviteMailer $mailer,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct();
    }

    /**
     * Registriert die Pflichtargumente `displayName`/`email` sowie die Option `--role` (Default `admin`).
     */
    protected function configure(): void
    {
        $this
            ->addArgument('displayName', InputArgument::REQUIRED, 'Anzeigename des neuen Admins')
            ->addArgument('email', InputArgument::REQUIRED, 'E-Mail-Adresse des neuen Admins')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Rolle: admin|moderator', 'admin');
    }

    /**
     * Legt den invited-AdminUser samt AdminInvite an, verschickt die Einladungsmail,
     * protokolliert die Aktion und druckt den Fallback-Registrierungslink.
     * Gibt Command::FAILURE zurück, wenn die E-Mail bereits vergeben oder die
     * Rolle ungültig ist – sonst Command::SUCCESS.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getArgument('email');
        if ($this->users->findOneByEmail($email) !== null) {
            $io->error('E-Mail existiert bereits.');

            return Command::FAILURE;
        }

        $role = AdminRole::tryFrom((string) $input->getOption('role'));
        if ($role === null) {
            $io->error('Ungültige Rolle.');

            return Command::FAILURE;
        }

        $user = new AdminUser((string) $input->getArgument('displayName'), $email, $role);
        $this->em->persist($user);

        $gen = $this->tokens->generate();
        $expiresAt = new \DateTimeImmutable('+72 hours');
        $this->em->persist(new AdminInvite($gen->selector, $gen->hash, $user, $role, $expiresAt));
        $this->em->flush();

        $this->mailer->send($email, $gen->token, $expiresAt);
        $this->audit->log(null, 'user.invite', 'admin_user', (string) $user->id, ['role' => $role->value, 'via' => 'cli']);

        $io->success('Einladung verschickt.');
        $output->writeln('Fallback-Link: https://gestura.eu/admin/register?token=' . $gen->token);

        return Command::SUCCESS;
    }
}
