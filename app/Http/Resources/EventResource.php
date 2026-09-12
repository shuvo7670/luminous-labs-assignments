<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /**
     * Transform the event into its public representation.
     *
     * Only explicitly public fields are listed; status and timestamps stay internal.
     *
     * @return array{id: int, name: string, starts_at: string, venue: string, description: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'starts_at' => $this->starts_at->toIso8601String(),
            'venue' => $this->venue,
            'description' => $this->description,
        ];
    }
}
