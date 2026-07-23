<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Konsolen-Command `index:mail:test` – verschickt eine Testmail über den
 * KONFIGURIERTEN Mailer (MAILER_DSN aus `.env.local`) an eine angegebene
 * Adresse. Nutzt denselben Absender wie die echten Admin-Mails (MAILER_FROM
 * bzw. Parameter `default_from`), damit der Test die Zustellung inklusive
 * Absender-/SPF-Prüfung realistisch abbildet.
 *
 * Gedacht zum Verifizieren der Mail-Konfiguration auf dem Zielhosting (z. B.
 * ALL-INKL-SMTP), BEVOR Einladungen scharf verschickt werden. Da kein
 * Messenger-Async-Routing existiert, sendet der Mailer synchron – ein
 * Transportfehler (Auth, Verbindung, abgelehnter Absender) landet direkt in
 * der TransportException und wird hier verständlich ausgegeben.
 */
#[AsCommand(name: 'index:mail:test', description: 'Verschickt eine Testmail über den konfigurierten Mailer')]
final class MailTestCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(default:default_from:MAILER_FROM)%')] private readonly string $from = 'admin@gestura.eu',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('to', InputArgument::REQUIRED, 'Empfänger-Adresse der Testmail');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $to = (string) $input->getArgument('to');

        $io->writeln(sprintf('Sende Testmail von <info>%s</info> an <info>%s</info> …', $this->from, $to));

        $email = (new Email())
            ->from($this->from)
            ->to($to)
            ->subject('Gestura-Index – Mailer-Test')
            ->text(
                "Dies ist eine Testmail von »index:mail:test«.\n\n"
                . "Kommt sie an, ist der konfigurierte MAILER_DSN funktionsfähig\n"
                . "und der Absender wird vom Zielhosting akzeptiert.\n"
            );

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $io->error(sprintf('Versand fehlgeschlagen (%s): %s', $e::class, $e->getMessage()));

            return Command::FAILURE;
        }

        // Erfolg heißt: der Transport hat die Mail angenommen. Bei SMTP ist das
        // eine echte Server-Bestätigung, bei sendmail/native nur die Übergabe an
        // den lokalen MTA – deshalb immer zusätzlich das Postfach prüfen.
        $io->success(sprintf('Vom Transport angenommen. Bitte Postfach %s prüfen (auch Spam).', $to));

        return Command::SUCCESS;
    }
}
