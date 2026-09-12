<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditShipmentDatesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dry_run_reports_a_mismatched_date_without_changing_it(): void
    {
        $mismatchedShipment = $this->createUsShipmentStoredWithDayFirstDate();
        Shipment::factory()->create(['external_id' => 'NG-ISO-1']);

        $this->artisan('shipments:audit-dates')
            ->expectsOutputToContain('Mismatch: shipment [NG-US-1] stored 2026-03-07, but raw value [07/03/2026] from northgate-us parses to 2026-07-03.')
            ->expectsOutputToContain('Audited 2 shipments: 1 mismatched, 0 need source-file review.')
            ->expectsOutputToContain('Dry run: no dates were changed. Re-run with --apply to correct 1 shipments.')
            ->doesntExpectOutputToContain('NG-ISO-1')
            ->assertSuccessful()
            ->run();

        $this->assertDatabaseHas('shipments', [
            'id' => $mismatchedShipment->id,
            'shipment_date' => '2026-03-07',
        ]);
    }

    public function test_apply_corrects_a_mismatched_date_from_its_raw_value(): void
    {
        $shipment = $this->createUsShipmentStoredWithDayFirstDate();

        $this->artisan('shipments:audit-dates', ['--apply' => true])
            ->expectsOutputToContain('Corrected: shipment [NG-US-1] stored 2026-03-07, but raw value [07/03/2026] from northgate-us parses to 2026-07-03.')
            ->doesntExpectOutputToContain('Dry run')
            ->assertSuccessful()
            ->run();

        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'shipment_date' => '2026-07-03',
            'shipment_date_raw' => '07/03/2026',
        ]);
    }

    public function test_audits_only_shipments_from_the_given_batch(): void
    {
        $shipmentInBatch = $this->createUsShipmentStoredWithDayFirstDate([
            'external_id' => 'NG-US-1',
            'source_batch' => 'batch-us-42',
        ]);
        $shipmentInOtherBatch = $this->createUsShipmentStoredWithDayFirstDate([
            'external_id' => 'NG-US-2',
            'source_batch' => 'batch-us-43',
        ]);

        $this->artisan('shipments:audit-dates', ['--batch' => 'batch-us-42', '--apply' => true])
            ->expectsOutputToContain('Audited 1 shipments: 1 mismatched, 0 need source-file review.')
            ->doesntExpectOutputToContain('NG-US-2')
            ->assertSuccessful()
            ->run();

        $this->assertDatabaseHas('shipments', ['id' => $shipmentInBatch->id, 'shipment_date' => '2026-07-03']);
        $this->assertDatabaseHas('shipments', ['id' => $shipmentInOtherBatch->id, 'shipment_date' => '2026-03-07']);
    }

    /**
     * @return array<string, array{string|null, string}>
     */
    public static function shipmentsNeedingSourceFileReview(): array
    {
        return [
            'no raw value' => [
                null,
                'Needs source-file review: shipment [NG-UK-1]: no raw shipment date is stored.',
            ],
            'raw value that cannot be parsed' => [
                '31/02/2026',
                'Needs source-file review: shipment [NG-UK-1]: Shipment date [31/02/2026] does not match the d/m/Y format required for [northgate-uk].',
            ],
        ];
    }

    #[DataProvider('shipmentsNeedingSourceFileReview')]
    public function test_fails_and_leaves_the_shipment_unchanged_when_it_needs_source_file_review(?string $rawDate, string $expectedWarning): void
    {
        $shipment = Shipment::factory()->create([
            'external_id' => 'NG-UK-1',
            'regional_office' => 'northgate-uk',
            'shipment_date' => CarbonImmutable::parse('2026-03-03', 'UTC'),
            'shipment_date_raw' => $rawDate,
        ]);

        $this->artisan('shipments:audit-dates', ['--apply' => true])
            ->expectsOutputToContain($expectedWarning)
            ->expectsOutputToContain('1 shipments need source-file review and were not changed.')
            ->assertFailed()
            ->run();

        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'shipment_date' => '2026-03-03',
            'shipment_date_raw' => $rawDate,
        ]);
    }

    /**
     * Create a US shipment whose raw 07/03/2026 was previously misread day-first as 7 March.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createUsShipmentStoredWithDayFirstDate(array $attributes = []): Shipment
    {
        return Shipment::factory()->create([
            'external_id' => 'NG-US-1',
            'regional_office' => 'northgate-us',
            'source_batch' => 'batch-us-42',
            'shipment_date' => CarbonImmutable::parse('2026-03-07', 'UTC'),
            'shipment_date_raw' => '07/03/2026',
            ...$attributes,
        ]);
    }
}
