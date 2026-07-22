<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Verschickt die Einladungs-E-Mail für neue Admin-Nutzer: schlichte
 * deutsche Text-Mail mit dem Registrierungslink (Klartext-Token) und
 * dem Ablaufzeitpunkt der Einladung.
 */
final class InviteMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(default:default_from:MAILER_FROM)%')] private readonly string $from = 'admin@gestura.eu',
    ) {
    }

    public function send(string $to, string $token, \DateTimeImmutable $expiresAt): void
    {
        $link = 'https://gestura.eu/admin/register?token=' . rawurlencode($token);
        $body = "Du wurdest ins Gestura-Index-Admin eingeladen.\n\n"
            . "Registrierung (Passkey anlegen):\n{$link}\n\n"
            . 'Der Link läuft ab am ' . $expiresAt->format('d.m.Y H:i') . " Uhr.\n";

        $this->mailer->send(
            (new Email())
                ->from($this->from)
                ->to($to)
                ->subject('Einladung ins Gestura-Index-Admin')
                ->text($body)
        );
    }
}
