<?php

namespace App\Account;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

final class AccountPasswordChangeMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly AccountPasswordChangeManager $accountPasswordChangeManager,
        #[Autowire('%env(string:PASSWORD_CHANGE_CODE_SENDER_EMAIL)%')]
        private readonly string $senderEmail,
        #[Autowire('%env(string:PASSWORD_CHANGE_CODE_SENDER_NAME)%')]
        private readonly string $senderName,
    ) {
    }

    public function sendCode(User $user, string $verificationCode): void
    {
        $normalizedSenderEmail = trim($this->senderEmail);
        if ($normalizedSenderEmail === '') {
            throw new \RuntimeException('Configure PASSWORD_CHANGE_CODE_SENDER_EMAIL para enviar o código de alteração de senha.');
        }

        $normalizedSenderName = trim($this->senderName);
        $emailMessage = (new TemplatedEmail())
            ->from(new Address($normalizedSenderEmail, $normalizedSenderName === '' ? 'OctoFlow' : $normalizedSenderName))
            ->to($user->getEmail())
            ->subject('Código para alterar sua senha - OctoFlow')
            ->htmlTemplate('emails/password_change_code.html.twig')
            ->context([
                'userEmail' => $user->getEmail(),
                'verificationCode' => $verificationCode,
                'expiresInMinutes' => $this->accountPasswordChangeManager->getCodeTtlInMinutes(),
            ]);

        $this->mailer->send($emailMessage);
    }
}
