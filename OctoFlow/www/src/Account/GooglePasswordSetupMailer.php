<?php

namespace App\Account;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

final class GooglePasswordSetupMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(string:GOOGLE_PASSWORD_SETUP_CODE_SENDER_EMAIL)%')]
        private readonly string $senderEmail,
        #[Autowire('%env(string:GOOGLE_PASSWORD_SETUP_CODE_SENDER_NAME)%')]
        private readonly string $senderName,
        private readonly GooglePasswordSetupManager $googlePasswordSetupManager,
    ) {
    }

    public function sendCode(User $user, string $verificationCode): void
    {
        $fromAddress = trim($this->senderEmail);
        if ($fromAddress === '') {
            throw new \RuntimeException('Configure GOOGLE_PASSWORD_SETUP_CODE_SENDER_EMAIL para enviar o código de validação.');
        }

        $senderDisplayName = trim($this->senderName);
        $message = (new TemplatedEmail())
            ->from(new Address($fromAddress, $senderDisplayName === '' ? 'OctoFlow' : $senderDisplayName))
            ->to($user->getEmail())
            ->subject('Código de validação para criar sua senha - OctoFlow')
            ->htmlTemplate('emails/google_password_setup_code.html.twig')
            ->context([
                'userEmail' => $user->getEmail(),
                'verificationCode' => $verificationCode,
                'expiresInMinutes' => $this->googlePasswordSetupManager->getCodeTtlInMinutes(),
            ]);

        $this->mailer->send($message);
    }
}
