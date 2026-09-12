<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Order;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

#[Signature('review:demo')]
#[Description('Demonstrate Tickets A, B and C end to end without a server; every change is rolled back')]
class ReviewDemo extends Command
{
    private const string SAMPLE_WEBHOOK_PATH = 'docs/sample-payment-succeeded.json';

    private const string SAMPLE_SHIPMENTS_PATH = 'docs/sample-shipments.csv';

    /**
     * @var list<string>
     */
    private const array PUBLIC_EVENT_FIELDS = ['id', 'name', 'starts_at', 'venue', 'description'];

    private int $failedCheckCount = 0;

    /**
     * Execute the console command.
     */
    public function handle(HttpKernel $httpKernel): int
    {
        $this->line('Every demo write runs inside a database transaction that is rolled back at the end.');

        DB::beginTransaction();

        try {
            $this->runSection('Ticket A: Fenwick payment webhook', fn () => $this->demonstratePaymentWebhook($httpKernel));
            $this->runSection('Ticket B: Northgate shipment dates', fn () => $this->demonstrateShipmentDates());
            $this->runSection('Ticket C: Marlow upcoming events', fn () => $this->demonstrateUpcomingEvents($httpKernel));
        } finally {
            DB::rollBack();
        }

        $this->newLine();

        if ($this->failedCheckCount > 0) {
            $this->error("{$this->failedCheckCount} check(s) failed.");

            return self::FAILURE;
        }

        $this->info('All checks passed');

        return self::SUCCESS;
    }

    /**
     * Send the signed sample webhook twice, then a forged one, through the HTTP kernel.
     */
    private function demonstratePaymentWebhook(HttpKernel $httpKernel): void
    {
        $payload = (string) file_get_contents(base_path(self::SAMPLE_WEBHOOK_PATH));
        $paymentId = json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['data']['object']['id'];
        $secret = (string) config('payment-provider.webhook_secret');

        $firstDelivery = $this->postWebhook($httpKernel, $payload, $this->signatureHeader($payload, $secret));
        $this->check(
            "First signed delivery of {$paymentId} returns 200 (got {$firstDelivery->getStatusCode()} {$firstDelivery->getContent()})",
            $firstDelivery->getStatusCode() === 200,
        );

        $retriedDelivery = $this->postWebhook($httpKernel, $payload, $this->signatureHeader($payload, $secret));
        $this->check(
            "Retried delivery returns 200 (got {$retriedDelivery->getStatusCode()} {$retriedDelivery->getContent()})",
            $retriedDelivery->getStatusCode() === 200,
        );

        $orderCount = Order::query()->where('provider_payment_id', $paymentId)->count();
        $this->check("Exactly 1 order exists for {$paymentId} (got {$orderCount})", $orderCount === 1);

        $forgedDelivery = $this->postWebhook($httpKernel, $payload, 't='.now()->getTimestamp().',v1='.str_repeat('0', 64));
        $this->check(
            "Delivery with a bad signature returns 401 (got {$forgedDelivery->getStatusCode()})",
            $forgedDelivery->getStatusCode() === 401,
        );

        $exitCode = $this->runArtisan('webhooks:check-failures')['exitCode'];
        $this->check("webhooks:check-failures exits 0 (got {$exitCode})", $exitCode === 0);
    }

    /**
     * Import the sample CSV, then show the dry-run audit catching a simulated historical misread.
     */
    private function demonstrateShipmentDates(): void
    {
        $exitCode = $this->runArtisan('shipments:import', ['file' => base_path(self::SAMPLE_SHIPMENTS_PATH)])['exitCode'];
        $this->check("shipments:import exits 0 (got {$exitCode})", $exitCode === 0);

        $ukShipment = $this->findShipment('northgate-uk', '07/03/2026');
        $usShipment = $this->findShipment('northgate-us', '07/03/2026');

        $ukDate = $ukShipment?->shipment_date?->toDateString() ?? 'not found';
        $this->check("UK 07/03/2026 is saved as 2026-03-07 (got {$ukDate})", $ukDate === '2026-03-07');

        $usDate = $usShipment?->shipment_date?->toDateString() ?? 'not found';
        $this->check("US 07/03/2026 is saved as 2026-07-03 (got {$usDate})", $usDate === '2026-07-03');

        $usShipment->update(['shipment_date' => CarbonImmutable::parse('2026-03-07', 'UTC')]);
        $this->line("  Simulating the historical bug: shipment [{$usShipment->external_id}] stored day-first as 2026-03-07.");

        ['exitCode' => $exitCode, 'output' => $output] = $this->runArtisan('shipments:audit-dates', ['--batch' => $usShipment->source_batch]);
        $this->check("Dry-run shipments:audit-dates exits 0 (got {$exitCode})", $exitCode === 0);
        $this->check(
            "Dry-run audit reports shipment [{$usShipment->external_id}] should be 2026-07-03",
            str_contains($output, "Mismatch: shipment [{$usShipment->external_id}]") && str_contains($output, 'parses to 2026-07-03'),
        );

        $dateAfterDryRun = $usShipment->fresh()->shipment_date->toDateString();
        $this->check("Dry run leaves the stored date unchanged (got {$dateAfterDryRun})", $dateAfterDryRun === '2026-03-07');
    }

    /**
     * Call the upcoming events endpoint through the HTTP kernel and verify its contract.
     */
    private function demonstrateUpcomingEvents(HttpKernel $httpKernel): void
    {
        $response = $httpKernel->handle(Request::create('/api/events/upcoming', server: ['HTTP_ACCEPT' => 'application/json']));
        $this->check("GET /api/events/upcoming returns 200 (got {$response->getStatusCode()})", $response->getStatusCode() === 200);

        /** @var list<array<string, mixed>> $events */
        $events = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        $eventCount = count($events);

        $this->check(
            "Returns between 1 and 10 events (got {$eventCount})".($eventCount === 0 ? '; run php artisan migrate:fresh --seed first' : ''),
            $eventCount >= 1 && $eventCount <= 10,
        );

        foreach ($events as $position => $event) {
            $this->line(sprintf('    %2d. %s  #%d  %s', $position + 1, $event['starts_at'], $event['id'], OutputFormatter::escape((string) $event['name'])));
        }

        $expectedOrder = collect($events)
            ->sortBy([['starts_at', 'asc'], ['id', 'asc']])
            ->pluck('id')
            ->all();
        $this->check('Events are ordered by starts_at, then id', array_column($events, 'id') === $expectedOrder);

        $this->check(
            'Each event exposes only '.implode(', ', self::PUBLIC_EVENT_FIELDS),
            collect($events)->every(fn (array $event): bool => array_keys($event) === self::PUBLIC_EVENT_FIELDS),
        );

        $publishedUpcomingCount = Event::query()
            ->whereKey(array_column($events, 'id'))
            ->where('status', Event::STATUS_PUBLISHED)
            ->where('starts_at', '>', now())
            ->count();
        $this->check('Every returned event is published and starts after now', $publishedUpcomingCount === $eventCount);
    }

    /**
     * Run one ticket's checks, turning an unexpected exception into a failed check.
     */
    private function runSection(string $title, Closure $checks): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");

        try {
            $checks();
        } catch (Throwable $exception) {
            $this->check('Unexpected '.$exception::class.': '.$exception->getMessage(), false);
        }
    }

    /**
     * Print a PASS or FAIL line and count failures.
     */
    private function check(string $description, bool $passed): void
    {
        if (! $passed) {
            $this->failedCheckCount++;
        }

        $this->line(sprintf('  %s  %s', $passed ? '<info>PASS</info>' : '<error>FAIL</error>', OutputFormatter::escape($description)));
    }

    /**
     * Run an Artisan command in-process and print its indented output.
     *
     * The command gets its own buffered output style, so its output is captured
     * even when the container binds a different OutputStyle (as console tests do).
     *
     * @param  array<string, mixed>  $parameters
     * @return array{exitCode: int, output: string}
     */
    private function runArtisan(string $command, array $parameters = []): array
    {
        $buffer = new BufferedOutput;

        $exitCode = Artisan::call($command, $parameters, new OutputStyle(new ArrayInput([]), $buffer));
        $output = $buffer->fetch();

        $displayedParameters = collect($parameters)
            ->map(fn (mixed $value, string $name): string => str_starts_with($name, '--') ? "{$name}={$value}" : (string) $value)
            ->implode(' ');

        $this->line('  <comment>$ php artisan '.OutputFormatter::escape(trim("{$command} {$displayedParameters}")).'</comment>');

        foreach (preg_split('/\R/', rtrim($output)) as $outputLine) {
            $this->line('    '.OutputFormatter::escape($outputLine));
        }

        return ['exitCode' => $exitCode, 'output' => $output];
    }

    /**
     * Send a JSON webhook request through the HTTP kernel without starting a server.
     */
    private function postWebhook(HttpKernel $httpKernel, string $payload, string $signatureHeader): Response
    {
        return $httpKernel->handle(Request::create('/webhooks/payment-provider', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMENT_SIGNATURE' => $signatureHeader,
        ], content: $payload));
    }

    /**
     * Sign the payload the way the payment provider does.
     */
    private function signatureHeader(string $payload, string $secret): string
    {
        $timestamp = now()->getTimestamp();

        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    }

    /**
     * Find the imported shipment for an office and raw date value.
     */
    private function findShipment(string $regionalOffice, string $rawDate): ?Shipment
    {
        return Shipment::query()
            ->where('regional_office', $regionalOffice)
            ->where('shipment_date_raw', $rawDate)
            ->first();
    }
}
