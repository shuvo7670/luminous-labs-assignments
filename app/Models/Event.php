<?php

namespace App\Models;

use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'description', 'venue', 'starts_at', 'status'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_PUBLISHED = 'published';

    public const string STATUS_CANCELLED = 'cancelled';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
        ];
    }
}
