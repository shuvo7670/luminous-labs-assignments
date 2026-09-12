<?php

namespace App\Console\Commands;

use App\Models\FailedWebhook;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('webhooks:check-failures')]
#[Description('List unresolved payment webhook failures and exit non-zero when any exist')]
class CheckFailedWebhooks extends Command
{
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

        return self::FAILURE;
    }
}
