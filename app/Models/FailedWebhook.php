<?php

namespace App\Models;

use Database\Factories\FailedWebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['provider_event_id', 'attempts', 'last_error', 'last_failed_at', 'resolved_at', 'payload'])]
class FailedWebhook extends Model
{
    /** @use HasFactory<FailedWebhookFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_failed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
