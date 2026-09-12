<?php

namespace App\Console\Commands;

use App\Models\FailedWebhook;
use App\Notifications\UnresolvedWebhookFailures;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

#[Signature('webhooks:check-failures')]
#[Description('List unresolved payment webhook failures, email operations, and exit non-zero when any exist')]
class CheckFailedWebhooks extends Command
{
    private const string ALERT_SENT_CACHE_KEY = 'payment-webhooks:failure-alert-sent';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $unresolvedFailures = FailedWebhook::query()
            ->whereNull('resolved_at')
            ->orderBy('id')
            ->get();

        if ($unresolvedFailures->isEmpty()) {
            $this->info('No unresolved payment webhook failures.');

            return self::SUCCESS;
        }

        $this->error("{$unresolvedFailures->count()} unresolved payment webhook failure(s).");

        $this->table(
            ['ID', 'Provider event', 'Attempts', 'Last failure', 'Error'],
            $unresolvedFailures->map(fn (FailedWebhook $failedWebhook): array => [
                $failedWebhook->id,
                $failedWebhook->provider_event_id ?? '(none)',
                $failedWebhook->attempts,
                $failedWebhook->last_failed_at->toDateTimeString(),
                Str::limit($failedWebhook->last_error, 120),
            ]),
        );

        $this->alertOperations($unresolvedFailures);

        return self::FAILURE;
    }

    /**
     * Email a summary to the operations address, at most once per alert interval.
     *
     * @param  Collection<int, FailedWebhook>  $unresolvedFailures
     */
    private function alertOperations(Collection $unresolvedFailures): void
    {
        $alertEmail = config('payment-provider.alert_email');

        if (! is_string($alertEmail) || $alertEmail === '') {
            $this->warn('No alert email is configured (PAYMENT_WEBHOOK_ALERT_EMAIL), so nobody was emailed.');

            return;
        }

        $alertInterval = now()->addMinutes((int) config('payment-provider.alert_interval_minutes'));

        if (! Cache::add(self::ALERT_SENT_CACHE_KEY, true, $alertInterval)) {
            $this->line('An alert email was already sent within the alert interval.');

            return;
        }

        try {
            Notification::route('mail', $alertEmail)->notify(new UnresolvedWebhookFailures(
                unresolvedCount: $unresolvedFailures->count(),
                oldestFailedAt: $unresolvedFailures->sortBy('last_failed_at')->first()->last_failed_at,
                latestError: $unresolvedFailures->sortByDesc('last_failed_at')->first()->last_error,
            ));
        } catch (Throwable $exception) {
            Cache::forget(self::ALERT_SENT_CACHE_KEY);

            throw $exception;
        }

        $this->info("Alert emailed to {$alertEmail}.");
    }
}
