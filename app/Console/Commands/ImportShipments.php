<?php

namespace App\Console\Commands;

use App\Models\Shipment;
use App\Services\ShipmentDateParser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

#[Signature('shipments:import {file : Path to the Northgate shipments CSV file}')]
#[Description('Import Northgate shipments using the strict date format of each regional office')]
class ImportShipments extends Command
{
    /**
     * @var list<string>
     */
    private const array REQUIRED_COLUMNS = ['external_id', 'regional_office', 'shipment_date'];

    /**
     * @var list<string>
     */
    private const array OPTIONAL_COLUMNS = ['source_batch'];

    /**
     * Execute the console command.
     */
    public function handle(ShipmentDateParser $dateParser): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("File [{$path}] does not exist or is not readable.");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');

        try {
            $columns = $this->readColumns($handle);

            $importedCount = DB::transaction(fn (): int => $this->importRows($handle, $columns, $dateParser));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            $this->error('No shipments were imported.');

            return self::FAILURE;
        } finally {
            fclose($handle);
        }

        $this->info("Imported {$importedCount} shipments.");

        return self::SUCCESS;
    }

    /**
     * Read the header row and ensure it contains exactly the supported columns.
     *
     * @param  resource  $handle
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private function readColumns($handle): array
    {
        $headerRow = $this->readRecord($handle);

        if ($headerRow === false || $headerRow === [null]) {
            throw new InvalidArgumentException('The CSV file has no header row.');
        }

        $columns = array_map(trim(...), $headerRow);
        $columns[0] = preg_replace('/^\xEF\xBB\xBF/', '', $columns[0]);

        $missingColumns = array_diff(self::REQUIRED_COLUMNS, $columns);

        if ($missingColumns !== []) {
            throw new InvalidArgumentException('Missing required column(s): '.implode(', ', $missingColumns).'.');
        }

        $unknownColumns = array_diff($columns, self::REQUIRED_COLUMNS, self::OPTIONAL_COLUMNS);

        if ($unknownColumns !== []) {
            throw new InvalidArgumentException('Unknown column(s): '.implode(', ', $unknownColumns).'.');
        }

        $duplicateColumns = array_unique(array_diff_assoc($columns, array_unique($columns)));

        if ($duplicateColumns !== []) {
            throw new InvalidArgumentException('Duplicate column(s): '.implode(', ', $duplicateColumns).'.');
        }

        return $columns;
    }

    /**
     * Create or update a shipment for every data row, stopping at the first invalid row.
     *
     * @param  resource  $handle
     * @param  list<string>  $columns
     *
     * @throws InvalidArgumentException
     */
    private function importRows($handle, array $columns, ShipmentDateParser $dateParser): int
    {
        $rowNumber = 1;
        $importedCount = 0;

        while (($record = $this->readRecord($handle)) !== false) {
            $rowNumber++;

            if ($record === [null]) {
                continue;
            }

            if (count($record) !== count($columns)) {
                throw new InvalidArgumentException(
                    "Row {$rowNumber}: expected ".count($columns).' columns but found '.count($record).'.'
                );
            }

            $row = array_combine($columns, $record);

            if ($row['external_id'] === '') {
                throw new InvalidArgumentException("Row {$rowNumber}: external_id is required.");
            }

            try {
                $shipmentDate = $dateParser->parse($row['shipment_date'], $row['regional_office']);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException("Row {$rowNumber}: {$exception->getMessage()}", previous: $exception);
            }

            Shipment::updateOrCreate(['external_id' => $row['external_id']], [
                'regional_office' => $row['regional_office'],
                'source_batch' => ($row['source_batch'] ?? '') !== '' ? $row['source_batch'] : null,
                'shipment_date' => $shipmentDate,
                'shipment_date_raw' => $row['shipment_date'],
            ]);

            $importedCount++;
        }

        return $importedCount;
    }

    /**
     * Read the next CSV record.
     *
     * @param  resource  $handle
     * @return array<int, string|null>|false
     */
    private function readRecord($handle): array|false
    {
        return fgetcsv($handle, null, ',', '"', '');
    }
}
