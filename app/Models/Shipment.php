<?php

namespace App\Models;

use Database\Factories\ShipmentFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['external_id', 'regional_office', 'source_batch', 'shipment_date', 'shipment_date_raw'])]
class Shipment extends Model
{
    /** @use HasFactory<ShipmentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shipment_date' => 'immutable_date',
        ];
    }

    /**
     * Store the shipment date as a calendar date without a time component.
     */
    protected function shipmentDate(): Attribute
    {
        return Attribute::set(fn (DateTimeInterface $value): string => $value->format('Y-m-d'));
    }
}
