<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationChannel;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class NotificationRouter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly MailerInterface $mailer,
        private readonly string $mailerFromAddress,
        private readonly string $mailerFromName,
    ) {
    }

    public function notify(
        User $recipient,
        string $subject,
        string $body,
        array $payload = [],
        NotificationChannel $channel = NotificationChannel::IN_APP,
    ): Notification {
        // Always persist an in-app notification record regardless of channel.
        $notification = (new Notification())
            ->setRecipient($recipient)
            ->setChannel($channel)
            ->setSubject($subject)
            ->setBody($body)
            ->setPayload($payload);

        $this->entityManager->persist($notification);

        // Mark in-app notifications as sent immediately (they are delivered on next page load).
        if ($channel === NotificationChannel::IN_APP) {
            $notification->markSent();

            $this->logger->info('In-app notification queued.', [
                'recipient' => $recipient->getUserIdentifier(),
                'subject'   => $subject,
            ]);

            return $notification;
        }

        // For email channel, attempt delivery via Symfony Mailer.
        if ($channel === NotificationChannel::EMAIL) {
            $this->sendEmail($recipient, $subject, $body, $payload, $notification);
        } else {
            $this->logger->info('External notification channel not yet connected.', [
                'channel'   => $channel->value,
                'recipient' => $recipient->getUserIdentifier(),
                'subject'   => $subject,
            ]);
        }

        return $notification;
    }

    /**
     * Sends an email to the recipient and marks the notification as sent on success.
     * On failure, logs the error and leaves the notification un-sent for potential retry.
     */
    private function sendEmail(
        User $recipient,
        string $subject,
        string $body,
        array $payload,
        Notification $notification,
    ): void {
        $recipientEmail = $recipient->getUserIdentifier(); // email is the identifier
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $this->logger->warning('Skipping email notification — recipient has no valid email address.', [
                'recipient_id' => $recipient->getId()?->toRfc4122(),
            ]);
            return;
        }

        try {
            $textBody = $body;
            $htmlBody = $this->buildHtmlBody($subject, $body, $payload);

            $email = (new Email())
                ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
                ->to(new Address($recipientEmail, $recipient->getFullName()))
                ->subject('[Mudi SACCO Support] ' . $subject)
                ->text($textBody)
                ->html($htmlBody);

            $this->mailer->send($email);
            $notification->markSent();
            $this->logger->info('Email notification sent.', [
                'recipient' => $recipientEmail,
                'subject'   => $subject,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send email notification.', [
                'recipient' => $recipientEmail,
                'subject'   => $subject,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Builds a minimal HTML email body with event context.
     * Intentionally plain so it renders in all email clients.
     */
    private function buildHtmlBody(string $subject, string $body, array $payload): string
    {
        $escapedSubject = htmlspecialchars($subject, ENT_QUOTES);
        $escapedBody    = nl2br(htmlspecialchars($body, ENT_QUOTES));
        $reference      = isset($payload['reference']) ? htmlspecialchars((string) $payload['reference'], ENT_QUOTES) : null;
        $refLine        = $reference ? '<p style="color:#666;font-size:.9em;">Ticket reference: <strong>' . $reference . '</strong></p>' : '';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="utf-8"><title>{$escapedSubject}</title></head>
        <body style="font-family:Arial,sans-serif;max-width:560px;margin:40px auto;color:#333;">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="background:#1a3c5e;padding:18px 24px;border-radius:6px 6px 0 0;">
                        <span style="color:#fff;font-weight:700;font-size:1.1em;">Mudi SACCO Support</span>
                    </td>
                </tr>
                <tr>
                    <td style="background:#f8f9fa;padding:28px 24px;border-radius:0 0 6px 6px;border:1px solid #dee2e6;">
                        <h2 style="margin:0 0 12px;font-size:1.1em;">{$escapedSubject}</h2>
                        <p>{$escapedBody}</p>
                        {$refLine}
                        <hr style="border:none;border-top:1px solid #dee2e6;margin:20px 0;">
                        <p style="font-size:.8em;color:#888;">
                            This is an automated message from Mudi SACCO Support.
                            Please do not reply to this email.
                        </p>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        HTML;
    }

    /**
     * Sends an email directly to a recipient email address without requiring a persisted User entity.
     * Useful for member welcome emails, staff invitations, and password reset links.
     */
    public function sendDirectEmail(
        string $toEmail,
        string $toName,
        string $subject,
        string $body,
        array $payload = []
    ): bool {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $this->logger->warning('Skipping direct email — invalid recipient email.', [
                'recipient' => $toEmail,
            ]);
            return false;
        }

        try {
            $htmlBody = $this->buildHtmlBody($subject, $body, $payload);

            $email = (new Email())
                ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
                ->to(new Address($toEmail, $toName !== '' ? $toName : $toEmail))
                ->subject('[' . $this->mailerFromName . '] ' . $subject)
                ->text($body)
                ->html($htmlBody);

            $this->mailer->send($email);
            $this->logger->info('Direct email sent successfully.', [
                'recipient' => $toEmail,
                'subject'   => $subject,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send direct email.', [
                'recipient' => $toEmail,
                'subject'   => $subject,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * @deprecated Kept for backward compatibility — use notify() with EMAIL channel instead.
     */
    public function route(NotificationChannel $channel, string $recipient, string $subject): void
    {
        $this->logger->info('NotificationRouter::route() is deprecated. Use notify() with the EMAIL channel.', [
            'channel'   => $channel->value,
            'recipient' => $recipient,
            'subject'   => $subject,
        ]);
    }
}
