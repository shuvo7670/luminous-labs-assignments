<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use InvalidArgumentException;

class ShipmentDateParser
{
    /**
     * The single date format each Northgate regional office exports.
     *
     * @var array<string, string>
     */
    private const array OFFICE_DATE_FORMATS = [
        'northgate-uk' => 'd/m/Y',
        'northgate-us' => 'm/d/Y',
        'northgate-iso' => 'Y-m-d',
    ];

    /**
     * Parse a raw shipment date strictly in the format of the office that exported it.
     *
     * The parsed date must format back to the exact raw value, which rejects
     * overflowing dates such as 31/02/2026 and unpadded values such as 2026-3-7.
     *
     * @throws InvalidArgumentException
     */
    public function parse(string $rawDate, string $regionalOffice): CarbonImmutable
    {
        $format = self::OFFICE_DATE_FORMATS[$regionalOffice]
            ?? throw new InvalidArgumentException("Unknown regional office [{$regionalOffice}].");

        try {
            $date = CarbonImmutable::createFromFormat('!'.$format, $rawDate, 'UTC');
        } catch (InvalidFormatException) {
            $date = null;
        }

        if (! $date instanceof CarbonImmutable || $date->format($format) !== $rawDate) {
            throw new InvalidArgumentException(
                "Shipment date [{$rawDate}] does not match the {$format} format required for [{$regionalOffice}]."
            );
        }

        return $date;
    }
}
