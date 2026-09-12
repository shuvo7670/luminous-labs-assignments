<?php

namespace Tests\Unit\Services;

use App\Services\ShipmentDateParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class ShipmentDateParserTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function officeSpecificDates(): array
    {
        return [
            'UK reads 07/03/2026 as 7 March' => ['northgate-uk', '07/03/2026', '2026-03-07'],
            'US reads 07/03/2026 as 3 July' => ['northgate-us', '07/03/2026', '2026-07-03'],
            'ISO reads year first' => ['northgate-iso', '2026-03-07', '2026-03-07'],
        ];
    }

    #[DataProvider('officeSpecificDates')]
    public function test_parses_date_as_utc_midnight_using_the_office_format(string $regionalOffice, string $rawDate, string $expectedDate): void
    {
        $parser = new ShipmentDateParser;

        $date = $parser->parse($rawDate, $regionalOffice);

        $this->assertSame($expectedDate.' 00:00:00 UTC', $date->format('Y-m-d H:i:s e'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function datesNotMatchingTheOfficeFormat(): array
    {
        return [
            'day that does not exist' => ['northgate-uk', '31/02/2026'],
            'UK-ordered value from US office' => ['northgate-us', '13/07/2026'],
            'unpadded ISO month and day' => ['northgate-iso', '2026-3-7'],
            'ISO value from UK office' => ['northgate-uk', '2026-03-07'],
            'two-digit year' => ['northgate-uk', '07/03/26'],
            'time component' => ['northgate-iso', '2026-03-07 10:30:00'],
            'surrounding whitespace' => ['northgate-us', ' 07/03/2026 '],
            'empty value' => ['northgate-uk', ''],
        ];
    }

    #[DataProvider('datesNotMatchingTheOfficeFormat')]
    public function test_rejects_date_that_does_not_exactly_match_the_office_format(string $regionalOffice, string $rawDate): void
    {
        $parser = new ShipmentDateParser;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Shipment date [{$rawDate}] does not match");

        $parser->parse($rawDate, $regionalOffice);
    }

    #[TestWith(['northgate-fr'])]
    #[TestWith(['NORTHGATE-UK'])]
    #[TestWith([''])]
    public function test_throws_for_unknown_regional_office(string $regionalOffice): void
    {
        $parser = new ShipmentDateParser;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown regional office [{$regionalOffice}].");

        $parser->parse('07/03/2026', $regionalOffice);
    }
}
