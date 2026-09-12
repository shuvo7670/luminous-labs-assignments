<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Sent synchronously, not queued, so the alert does not depend on a queue worker being healthy.
 */
class UnresolvedWebhookFailures extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        public readonly int $unresolvedCount,
        public readonly CarbonInterface $oldestFailedAt,
        public readonly string $latestError,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject("Payment webhooks: {$this->unresolvedCount} unresolved failure(s)")
            ->line("{$this->unresolvedCount} payment webhook delivery(ies) failed and have not succeeded on a retry, so matching orders may be missing.")
            ->line('Oldest unresolved failure: '.$this->oldestFailedAt->toDateTimeString().' UTC.')
            ->line('Most recent error: '.Str::limit($this->latestError, 200))
            ->line('Run `php artisan webhooks:check-failures` on the server for the full list.');
    }
}
