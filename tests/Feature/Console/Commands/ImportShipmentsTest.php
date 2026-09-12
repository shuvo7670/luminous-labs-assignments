<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Shipment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImportShipmentsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string HEADER_ROW = 'external_id,regional_office,shipment_date,source_batch';

    public function test_imports_each_row_using_the_date_format_of_its_office(): void
    {
        $path = $this->csvFile(
            self::HEADER_ROW,
            'NG-UK-1,northgate-uk,07/03/2026,batch-uk-41',
            'NG-US-1,northgate-us,07/03/2026,batch-us-42',
            'NG-ISO-1,northgate-iso,2026-03-07,batch-iso-7',
        );

        $this->artisan('shipments:import', ['file' => $path])
            ->expectsOutputToContain('Imported 3 shipments.')
            ->assertSuccessful()
            ->run();

        $this->assertDatabaseCount('shipments', 3);
        $this->assertDatabaseHas('shipments', [
            'external_id' => 'NG-UK-1',
            'regional_office' => 'northgate-uk',
            'source_batch' => 'batch-uk-41',
            'shipment_date' => '2026-03-07',
            'shipment_date_raw' => '07/03/2026',
        ]);
        $this->assertDatabaseHas('shipments', [
            'external_id' => 'NG-US-1',
            'regional_office' => 'northgate-us',
            'source_batch' => 'batch-us-42',
            'shipment_date' => '2026-07-03',
            'shipment_date_raw' => '07/03/2026',
        ]);
        $this->assertDatabaseHas('shipments', [
            'external_id' => 'NG-ISO-1',
            'regional_office' => 'northgate-iso',
            'source_batch' => 'batch-iso-7',
            'shipment_date' => '2026-03-07',
            'shipment_date_raw' => '2026-03-07',
        ]);
    }

    public function test_updates_the_existing_shipment_with_the_same_external_id(): void
    {
        $shipment = Shipment::factory()->create(['external_id' => 'NG-US-1']);
        $path = $this->csvFile(self::HEADER_ROW, 'NG-US-1,northgate-us,07/03/2026,batch-us-42');

        $this->artisan('shipments:import', ['file' => $path])
            ->expectsOutputToContain('Imported 1 shipments.')
            ->assertSuccessful()
            ->run();

        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'regional_office' => 'northgate-us',
            'source_batch' => 'batch-us-42',
            'shipment_date' => '2026-07-03',
            'shipment_date_raw' => '07/03/2026',
        ]);
    }

    public function test_stores_null_source_batch_when_the_optional_column_is_absent(): void
    {
        $path = $this->csvFile('external_id,regional_office,shipment_date', 'NG-UK-1,northgate-uk,07/03/2026');

        $this->artisan('shipments:import', ['file' => $path])->assertSuccessful()->run();

        $this->assertDatabaseHas('shipments', [
            'external_id' => 'NG-UK-1',
            'source_batch' => null,
            'shipment_date' => '2026-03-07',
        ]);
    }

    public function test_accepts_a_header_row_with_a_utf8_byte_order_mark(): void
    {
        $path = $this->csvFile("\xEF\xBB\xBF".self::HEADER_ROW, 'NG-UK-1,northgate-uk,07/03/2026,batch-uk-41');

        $this->artisan('shipments:import', ['file' => $path])->assertSuccessful()->run();

        $this->assertDatabaseHas('shipments', ['external_id' => 'NG-UK-1']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidRows(): array
    {
        return [
            'impossible date' => [
                'NG-UK-2,northgate-uk,31/02/2026,batch-uk-41',
                'Row 3: Shipment date [31/02/2026] does not match the d/m/Y format required for [northgate-uk].',
            ],
            'unknown office' => [
                'NG-FR-1,northgate-fr,07/03/2026,batch-fr-1',
                'Row 3: Unknown regional office [northgate-fr].',
            ],
            'too few columns' => [
                'NG-UK-2,northgate-uk,07/03/2026',
                'Row 3: expected 4 columns but found 3.',
            ],
            'too many columns' => [
                'NG-UK-2,northgate-uk,07/03/2026,batch-uk-41,extra',
                'Row 3: expected 4 columns but found 5.',
            ],
            'missing external id' => [
                ',northgate-uk,07/03/2026,batch-uk-41',
                'Row 3: external_id is required.',
            ],
        ];
    }

    #[DataProvider('invalidRows')]
    public function test_fails_and_rolls_back_earlier_rows_when_any_row_is_invalid(string $invalidRow, string $expectedError): void
    {
        $path = $this->csvFile(self::HEADER_ROW, 'NG-UK-1,northgate-uk,07/03/2026,batch-uk-41', $invalidRow);

        $this->artisan('shipments:import', ['file' => $path])
            ->expectsOutputToContain($expectedError)
            ->expectsOutputToContain('No shipments were imported.')
            ->assertFailed()
            ->run();

        $this->assertDatabaseEmpty('shipments');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidHeaderRows(): array
    {
        return [
            'empty file' => ['', 'The CSV file has no header row.'],
            'required column missing' => ['external_id,regional_office,source_batch', 'Missing required column(s): shipment_date.'],
            'unknown column' => [self::HEADER_ROW.',shipped_on', 'Unknown column(s): shipped_on.'],
            'duplicate column' => [self::HEADER_ROW.',shipment_date', 'Duplicate column(s): shipment_date.'],
        ];
    }

    #[DataProvider('invalidHeaderRows')]
    public function test_fails_without_importing_when_the_header_row_is_invalid(string $headerRow, string $expectedError): void
    {
        $path = $this->csvFile($headerRow, 'NG-UK-1,northgate-uk,07/03/2026,batch-uk-41');

        $this->artisan('shipments:import', ['file' => $path])
            ->expectsOutputToContain($expectedError)
            ->assertFailed()
            ->run();

        $this->assertDatabaseEmpty('shipments');
    }

    public function test_fails_when_the_file_does_not_exist(): void
    {
        $this->artisan('shipments:import', ['file' => '/missing/shipments.csv'])
            ->expectsOutputToContain('File [/missing/shipments.csv] does not exist or is not readable.')
            ->assertFailed()
            ->run();
    }

    /**
     * Write the given lines to a temporary CSV file that is deleted after the test.
     */
    private function csvFile(string ...$lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'shipments-');
        file_put_contents($path, implode("\n", $lines)."\n");

        $this->beforeApplicationDestroyed(fn () => unlink($path));

        return $path;
    }
}
