<?php

namespace App\Console\Commands;

use App\Models\Shipment;
use App\Services\ShipmentDateParser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

#[Signature('shipments:audit-dates {--batch= : Only audit shipments from this source batch} {--apply : Correct mismatched dates instead of only reporting them}')]
#[Description('Re-parse retained raw shipment dates and report, or with --apply repair, stored dates that differ')]
class AuditShipmentDates extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ShipmentDateParser $dateParser): int
    {
        $shouldApply = (bool) $this->option('apply');
        $batch = $this->option('batch');

        $auditedCount = 0;
        $mismatchedCount = 0;
        $needsReviewCount = 0;

        $shipments = Shipment::query()
            ->when($batch !== null, fn (Builder $query) => $query->where('source_batch', $batch))
            ->lazyById(1000);

        foreach ($shipments as $shipment) {
            $auditedCount++;

            if ($shipment->shipment_date_raw === null || $shipment->shipment_date_raw === '') {
                $this->warnNeedsSourceFileReview($shipment, 'no raw shipment date is stored.');
                $needsReviewCount++;

                continue;
            }

            try {
                $parsedDate = $dateParser->parse($shipment->shipment_date_raw, $shipment->regional_office);
            } catch (InvalidArgumentException $exception) {
                $this->warnNeedsSourceFileReview($shipment, $exception->getMessage());
                $needsReviewCount++;

                continue;
            }

            $storedDate = $shipment->shipment_date->toDateString();

            if ($storedDate === $parsedDate->toDateString()) {
                continue;
            }

            $mismatchedCount++;

            if ($shouldApply) {
                $shipment->update(['shipment_date' => $parsedDate]);
            }

            $this->line(sprintf(
                '%s: shipment [%s] stored %s, but raw value [%s] from %s parses to %s.',
                $shouldApply ? 'Corrected' : 'Mismatch',
                $shipment->external_id,
                $storedDate,
                $shipment->shipment_date_raw,
                $shipment->regional_office,
                $parsedDate->toDateString(),
            ));
        }

        $this->info("Audited {$auditedCount} shipments: {$mismatchedCount} mismatched, {$needsReviewCount} need source-file review.");

        if (! $shouldApply && $mismatchedCount > 0) {
            $this->comment("Dry run: no dates were changed. Re-run with --apply to correct {$mismatchedCount} shipments.");
        }

        if ($needsReviewCount > 0) {
            $this->error("{$needsReviewCount} shipments need source-file review and were not changed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Report a shipment whose correct date cannot be proven from the stored data.
     */
    private function warnNeedsSourceFileReview(Shipment $shipment, string $reason): void
    {
        $this->warn("Needs source-file review: shipment [{$shipment->external_id}]: {$reason}");
    }
}
